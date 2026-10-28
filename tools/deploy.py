#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Deployment & Backup Pipeline für Feuerwehr-Bestellsystem
Unterstützt:
  - Vorab-Selbsttests der PHP-Applikation
  - Remote-Backup via FTP vor jedem Deployment
  - Differentieller Upload via LFTP (nur geänderte Dateien)
  - Dry-Run / Vorschau-Modus
  - Multi-Environment Support (2-3+ Zielserver)
  - Automatischer / optionaler GitHub Push
"""

from __future__ import annotations

import argparse
import datetime
import json
import os
import shutil
import subprocess
import sys
import tarfile
from pathlib import Path

# Basis-Pfade
BASE_DIR = Path(__file__).resolve().parent.parent
CONFIG_FILE = BASE_DIR / "deploy.config.json"
CONFIG_EXAMPLE = BASE_DIR / "deploy.config.example.json"
BACKUP_DIR = BASE_DIR / "backups"


class Colors:
    GREEN = "\033[92m"
    YELLOW = "\033[93m"
    RED = "\033[91m"
    BLUE = "\033[94m"
    CYAN = "\033[96m"
    BOLD = "\033[1m"
    RESET = "\033[0m"


def print_step(title: str):
    print(f"\n{Colors.CYAN}{Colors.BOLD}=== {title} ==={Colors.RESET}")


def print_ok(msg: str):
    print(f"{Colors.GREEN}✔ {msg}{Colors.RESET}")


def print_warn(msg: str):
    print(f"{Colors.YELLOW}⚠ {msg}{Colors.RESET}")


def print_err(msg: str):
    print(f"{Colors.RED}✖ {msg}{Colors.RESET}")


def load_config() -> dict:
    if not CONFIG_FILE.is_file():
        print_err(f"Konfigurationsdatei '{CONFIG_FILE.name}' nicht gefunden!")
        print(f"Bitte erstelle sie basierend auf der Vorlage:")
        print(f"  cp {CONFIG_EXAMPLE.name} {CONFIG_FILE.name}")
        print(f"und passe die FTP-Zugangsdaten an.\n")
        sys.exit(1)

    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    except Exception as e:
        print_err(f"Fehler beim Lesen der Konfigurationsdatei: {e}")
        sys.exit(1)


def run_selftests() -> bool:
    print_step("Führe System- & Schema-Selbsttests aus")
    db_file = (BASE_DIR / "include" / "db.php").resolve()
    selftests_file = (BASE_DIR / "include" / "ff_selftests.php").resolve()

    php_code = (
        f"require_once '{db_file}'; "
        f"require_once '{selftests_file}'; "
        "$res = ff_selftests_run($conn, 'unit'); "
        "$results = is_array($res['results'] ?? null) ? $res['results'] : []; "
        "$failed = array_values(array_filter($results, static fn($x) => empty($x['ok']))); "
        "if (!empty($failed) || (($res['failed'] ?? 0) > 0)) { "
        "    echo json_encode(['passed' => $res['passed'] ?? 0, 'failed' => count($failed), 'failed_tests' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); "
        "    exit(1); "
        "} "
        "echo (int)($res['passed'] ?? count($results)); "
        "exit(0);"
    )
    test_cmd = ["php", "-r", php_code]
    try:
        proc = subprocess.run(test_cmd, capture_output=True, text=True, cwd=str(BASE_DIR))
        if proc.returncode == 0:
            count = proc.stdout.strip()
            print_ok(f"Alle {count} Selbsttests erfolgreich bestanden!")
            return True
        else:
            print_err("Selbsttests FEHLGESCHLAGEN! Abbruch zum Schutz der Zielserver.")
            try:
                err_data = json.loads(proc.stdout)
                if isinstance(err_data, dict) and "failed_tests" in err_data:
                    for f in err_data["failed_tests"]:
                        print(f"  - [{f.get('group', 'Test')}] {f.get('name', 'Unbekannt')}: {f.get('detail', 'Fehlgeschlagen')}")
                elif proc.stdout.strip():
                    print(proc.stdout)
            except Exception:
                if proc.stdout.strip():
                    print(proc.stdout)
            if proc.stderr.strip():
                print(proc.stderr)
            return False
    except Exception as e:
        print_warn(f"Selbsttests konnten nicht ausgeführt werden: {e}")
        return True


def build_lftp_preamble(env: dict) -> str:
    user = env.get("user", "").replace("\\", "\\\\").replace('"', '\\"')
    password = env.get("password", "").replace("\\", "\\\\").replace('"', '\\"')
    host = env.get("host", "").replace("\\", "\\\\").replace('"', '\\"')
    port = int(env.get("port", 21))
    ssl_enabled = bool(env.get("ssl", True))
    proto = str(env.get("proto", "ftp")).lower()

    lines = [
        "set ssl:verify-certificate no;",
        # Wichtig bei Pure-FTPd Servern (u. a. World4You): Verhindert 451-Fehler bei TLS 1.3 Data-Channel
        "set ssl:priority NORMAL:-VERS-TLS1.3;",
        "set net:timeout 30;",
        "set net:max-retries 5;",
        "set ftp:passive-mode true;",
    ]

    if proto == "sftp":
        lines.append(f'open -u "{user}","{password}" -p {port} "sftp://{host}";')
    elif port == 990 or proto == "ftps":
        # Implizites FTPS
        lines.append(f'open -u "{user}","{password}" -p {port} "ftps://{host}";')
    else:
        # Explizites FTPS (Port 21 mit STARTTLS) oder unverschlüsseltes FTP
        if ssl_enabled:
            lines.append("set ftp:ssl-force yes;")
            lines.append("set ftp:ssl-protect-data yes;")
        else:
            lines.append("set ftp:ssl-allow no;")
        lines.append(f'open -u "{user}","{password}" -p {port} "ftp://{host}";')

    return "\n".join(lines)


def test_connection(env_key: str, env: dict) -> bool:
    print_step(f"Verbindungstest zu '{env.get('name', env_key)}'")
    preamble = build_lftp_preamble(env)
    remote_dir = env.get("remote_dir", "/")

    lftp_script = f"""
{preamble}
cd "{remote_dir}";
ls;
bye;
"""
    try:
        proc = subprocess.run(["lftp", "-c", lftp_script], capture_output=True, text=True)
        if proc.returncode == 0:
            print_ok(f"Verbindung zu '{env.get('host')}' ({remote_dir}) erfolgreich hergestellt!")
            return True
        else:
            print_err(f"Verbindungsfehler: {proc.stderr.strip() or proc.stdout.strip()}")
            return False
    except Exception as e:
        print_err(f"LFTP Fehler: {e}")
        return False


def backup_remote(env_key: str, env: dict) -> str | None:
    print_step(f"Erstelle Remote-Backup für '{env.get('name', env_key)}'")
    BACKUP_DIR.mkdir(parents=True, exist_ok=True)
    ts = datetime.datetime.now().strftime("%Y-%m-%d_%H-%M-%S")
    temp_dir = BACKUP_DIR / f"{env_key}_{ts}"
    tar_path = BACKUP_DIR / f"{env_key}_{ts}.tar.gz"

    temp_dir.mkdir(parents=True, exist_ok=True)
    preamble = build_lftp_preamble(env)
    remote_dir = env.get("remote_dir", "/")

    print(f"Lade aktuelle Dateien vom Zielserver herunter ({remote_dir})...")
    lftp_script = f"""
{preamble}
cd "{remote_dir}";
mirror --verbose --parallel=3 --exclude "(\\.cache|backups)(/|$)" --exclude-glob "backups*" . "{temp_dir}";
bye;
"""
    try:
        proc = subprocess.run(["lftp", "-c", lftp_script], capture_output=True, text=True)
        if proc.returncode != 0:
            print_warn(f"Backup-Warnung beim Spiegeln: {proc.stderr.strip() or proc.stdout.strip()}")

        # Packen als tar.gz
        print(f"Komprimiere Backup nach '{tar_path.name}'...")
        with tarfile.open(tar_path, "w:gz") as tar:
            tar.add(temp_dir, arcname=f"{env_key}_{ts}")

        # Temp-Ordner löschen
        shutil.rmtree(temp_dir, ignore_errors=True)
        size_mb = tar_path.stat().st_size / (1024 * 1024)
        print_ok(f"Backup erfolgreich erstellt: {tar_path} ({size_mb:.2f} MB)")
        return str(tar_path)
    except Exception as e:
        print_err(f"Fehler beim Erstellen des Backups: {e}")
        return None


def deploy_environment(env_key: str, env: dict, excludes: list[str], dry_run: bool = False, skip_backup: bool = False) -> bool:
    env_name = env.get("name", env_key)
    print_step(f"Deployment auf '{env_name}' ({'DRY-RUN / VORSCHAU' if dry_run else 'LIVE'})")

    if not dry_run and not skip_backup and env.get("backup_before_deploy", True):
        backup_file = backup_remote(env_key, env)
        if not backup_file:
            print_err("Backup fehlgeschlagen! Deployment wird aus Sicherheitsgründen gestoppt.")
            return False

    preamble = build_lftp_preamble(env)
    remote_dir = env.get("remote_dir", "/")

    # Exclude-Flags für lftp
    exclude_list = list(excludes)
    for essential in [".git*", ".git/*", ".vscode*", "*__pycache__*", "*.pyc", "deploy.config*.json", "backups*"]:
        if essential not in exclude_list:
            exclude_list.append(essential)

    # Regex-Excludes für rekursives Verhindern unerwünschter Ordner
    dir_excludes = [r"(\.git|\.vscode|\.idea|__pycache__|\.cache|backups)(/|$)"]
    exclude_args_list = [f'--exclude "{d}"' for d in dir_excludes]
    for ex in exclude_list:
        exclude_args_list.append(f'--exclude-glob "{ex}"')
    exclude_args = " ".join(exclude_args_list)

    dry_flag = "--dry-run" if dry_run else ""
    lftp_script = f"""
{preamble}
cd "{remote_dir}";
mirror --reverse --verbose --only-newer --no-perms {dry_flag} {exclude_args} "{BASE_DIR}" .;
bye;
"""
    print(f"Starte Upload (nur neue & geänderte Dateien)...")
    try:
        proc = subprocess.run(["lftp", "-c", lftp_script], text=True)
        if proc.returncode == 0:
            if dry_run:
                print_ok(f"Dry-Run für '{env_name}' abgeschlossen! Keine Dateien geändert.")
            else:
                print_ok(f"Deployment auf '{env_name}' erfolgreich abgeschlossen!")
            return True
        else:
            print_err(f"LFTP-Deployment fehlgeschlagen mit Exit-Code {proc.returncode}!")
            return False
    except Exception as e:
        print_err(f"Fehler beim Deployment: {e}")
        return False


def push_to_github(config: dict, commit_msg: str | None = None) -> bool:
    print_step("GitHub Push")
    gh_cfg = config.get("github", {})
    remote = gh_cfg.get("remote", "origin")
    branch = gh_cfg.get("branch", "main")

    # Prüfe ob Remote existiert
    remotes_proc = subprocess.run(["git", "remote"], capture_output=True, text=True, cwd=str(BASE_DIR))
    if remote not in remotes_proc.stdout.split():
        print_warn(f"Git-Remote '{remote}' ist noch nicht konfiguriert.")
        print(f"Du kannst es hinzufügen mit: git remote add {remote} <DEINE_REPO_URL>")
        return False

    # Git Status
    status_proc = subprocess.run(["git", "status", "--porcelain"], capture_output=True, text=True, cwd=str(BASE_DIR))
    dirty = bool(status_proc.stdout.strip())

    if dirty:
        print("Geänderte / neue Dateien gefunden:")
        print(status_proc.stdout)
        if not commit_msg:
            commit_msg = input("\nCommit-Nachricht eingeben (Enter für Abbruch): ").strip()
            if not commit_msg:
                print_warn("GitHub Push abgebrochen (keine Commit-Nachricht).")
                return False

        subprocess.run(["git", "add", "."], cwd=str(BASE_DIR), check=True)
        subprocess.run(["git", "commit", "-m", commit_msg], cwd=str(BASE_DIR), check=True)
        print_ok(f"Commit erstellt: '{commit_msg}'")

    print(f"Pushe zu {remote}/{branch}...")
    try:
        subprocess.run(["git", "push", remote, branch], cwd=str(BASE_DIR), check=True)
        print_ok("Erfolgreich zu GitHub gepusht!")
        return True
    except subprocess.CalledProcessError as e:
        print_err(f"Git Push fehlgeschlagen: {e}")
        return False


def interactive_menu(config: dict):
    envs = config.get("environments", {})
    excludes = config.get("exclude", [])

    while True:
        print("\n" + "=" * 50)
        print(f"{Colors.BOLD}Feuerwehr-Bestellsystem: Deployment & Pipeline{Colors.RESET}")
        print("=" * 50)
        print("[1] Deployment durchführen (mit automatischem Backup)")
        print("[2] Deployment Trockenlauf (Dry-Run / Vorschau)")
        print("[3] Nur Backup vom Zielserver herunterladen")
        print("[4] Verbindung zum Zielserver testen")
        print("[5] Code zu GitHub pushen")
        print("[6] Lokale Selbsttests ausführen")
        print("[0] Beenden")

        choice = input("\nAuswahl [1-6, 0]: ").strip()
        if choice == "0":
            break
        elif choice == "6":
            run_selftests()
        elif choice == "5":
            push_to_github(config)
        elif choice in ("1", "2", "3", "4"):
            if not envs:
                print_warn("Keine Umgebungen in 'deploy.config.json' konfiguriert!")
                continue

            print("\nVerfügbare Zielumgebungen:")
            env_keys = list(envs.keys())
            for idx, k in enumerate(env_keys, 1):
                name = envs[k].get("name", k)
                host = envs[k].get("host", "–")
                print(f"  [{idx}] {name} ({host})")
            if choice in ("1", "2"):
                print(f"  [{len(env_keys) + 1}] ALLE Server nacheinander")

            sub_choice = input(f"Server wählen [1-{len(env_keys) + (1 if choice in ('1', '2') else 0)}]: ").strip()
            if not sub_choice.isdigit():
                continue
            idx = int(sub_choice)

            targets = []
            if choice in ("1", "2") and idx == len(env_keys) + 1:
                targets = env_keys
            elif 1 <= idx <= len(env_keys):
                targets = [env_keys[idx - 1]]
            else:
                print_warn("Ungültige Auswahl.")
                continue

            for t in targets:
                env = envs[t]
                if choice == "4":
                    test_connection(t, env)
                elif choice == "3":
                    backup_remote(t, env)
                elif choice == "2":
                    deploy_environment(t, env, excludes, dry_run=True)
                elif choice == "1":
                    if not run_selftests():
                        confirm = input("Trotz fehlgeschlagener Tests fortfahren? (j/N): ").strip().lower()
                        if confirm not in ("j", "ja", "y", "yes"):
                            continue
                    deploy_environment(t, env, excludes, dry_run=False)

                    # Nach erfolgreichem Deployment optional zu GitHub
                    ask_git = input("\nMöchtest du diese Änderungen jetzt auch zu GitHub pushen? (j/N): ").strip().lower()
                    if ask_git in ("j", "ja", "y", "yes"):
                        push_to_github(config)


def main():
    parser = argparse.ArgumentParser(description="Bestellsystem Deployment & Backup Pipeline")
    parser.add_argument("--target", help="Name der Zielumgebung (aus deploy.config.json) oder 'all'")
    parser.add_argument("--dry-run", action="store_true", help="Vorschau ohne Dateien hochzuladen")
    parser.add_argument("--skip-backup", action="store_true", help="Remote-Backup vor Upload überspringen")
    parser.add_argument("--skip-tests", action="store_true", help="Selbsttests überspringen")
    parser.add_argument("--backup-only", help="Nur Backup von Zielserver erstellen")
    parser.add_argument("--test-conn", help="Nur Verbindung testen")
    parser.add_argument("--git-push", action="store_true", help="Änderungen zu GitHub pushen")
    parser.add_argument("-m", "--message", help="Commit-Nachricht für GitHub Push")

    args = parser.parse_args()
    config = load_config()

    if args.git_push and not args.target:
        push_to_github(config, args.message)
        return

    if args.test_conn:
        env = config.get("environments", {}).get(args.test_conn)
        if not env:
            print_err(f"Umgebung '{args.test_conn}' nicht in deploy.config.json gefunden.")
            sys.exit(1)
        test_connection(args.test_conn, env)
        return

    if args.backup_only:
        env = config.get("environments", {}).get(args.backup_only)
        if not env:
            print_err(f"Umgebung '{args.backup_only}' nicht in deploy.config.json gefunden.")
            sys.exit(1)
        backup_remote(args.backup_only, env)
        return

    if args.target:
        envs = config.get("environments", {})
        excludes = config.get("exclude", [])

        if not args.skip_tests:
            if not run_selftests():
                sys.exit(1)

        targets = list(envs.keys()) if args.target == "all" else [args.target]
        for t in targets:
            if t not in envs:
                print_err(f"Umgebung '{t}' nicht gefunden!")
                continue
            deploy_environment(t, envs[t], excludes, dry_run=args.dry_run, skip_backup=args.skip_backup)

        if args.git_push:
            push_to_github(config, args.message)
        return

    # Ohne Parameter -> Interaktives Menü
    interactive_menu(config)


if __name__ == "__main__":
    main()
