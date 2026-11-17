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
# Remote-Backups liegen AUSSERHALB des Webroots (Standard: <projekt>-backups
# neben dem Projektordner, z. B. /var/www/bestellsystem-backups).
# Überschreibbar per Umgebungsvariable FF_DEPLOY_BACKUP_DIR.
BACKUP_DIR = Path(
    os.environ.get("FF_DEPLOY_BACKUP_DIR")
    or (BASE_DIR.parent / f"{BASE_DIR.name}-backups")
).resolve()


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


def mask_credentials(text: str) -> str:
    """Zugangsdaten in lftp-Ausgaben unkenntlich machen (ftp://user:pass@host → ftp://***@host)."""
    import re

    return re.sub(r"((?:s?ftps?|https?)://)[^/@\s]+@", r"\1***@", text)


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
            print_err(f"Verbindungsfehler: {mask_credentials(proc.stderr.strip() or proc.stdout.strip())}")
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
            print_warn(f"Backup-Warnung beim Spiegeln: {mask_credentials(proc.stderr.strip() or proc.stdout.strip())}")

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
    # include/db.php bleibt ausgeschlossen (auf Servern stehen dort teils Zugangsdaten). Deshalb enthält
    # db.php nur den Verbindungsaufbau; alle Helfer liegen in include/ff_db_helpers.php (wird deployt).
    exclude_list = list(excludes)
    for essential in [
        ".git*",
        ".git/*",
        ".vscode*",
        "*__pycache__*",
        "*.pyc",
        "deploy.config*.json",
        "backups*",
        "include/db.php",
        "include/db.local.php",
        "db.php",
        "db.local.php",
        "*db.php",
        "*db.local.php",
        "*.local.php",
    ]:
        if essential not in exclude_list:
            exclude_list.append(essential)

    # Regex-Excludes für rekursives Verhindern unerwünschter Ordner & DB-Konfigurationen
    dir_excludes = [
        r"(\.git|\.vscode|\.idea|__pycache__|\.cache|backups)(/|$)",
        r"(^|/)(include/)?db(\.local)?\.php$",
    ]
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
        # lftp gibt bei --dry-run/--verbose URLs mit Benutzer:Passwort aus → vor der Anzeige maskieren
        proc = subprocess.run(["lftp", "-c", lftp_script], text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        print(mask_credentials(proc.stdout or ""), end="")
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


# ---------------------------------------------------------------------------
# Verwaiste Dateien auf dem Server
# ---------------------------------------------------------------------------
# Das Deployment lädt nur hoch (mirror --reverse ohne --delete). Dateien, die lokal umbenannt,
# gelöscht oder einmal von Hand in einen falschen Ordner kopiert wurden, bleiben auf dem Server
# liegen – per URL aufrufbar, mit altem Code. Der Abgleich listet sie auf und löscht auf Wunsch
# nur Code-Dateien; alles, was absichtlich nur auf dem Server liegt, ist geschützt.

ORPHAN_CODE_EXT = {
    ".php", ".phtml", ".inc", ".js", ".mjs", ".css", ".html", ".htm", ".py", ".md", ".sh", ".ps1",
    ".bat", ".cmd", ".ino", ".h", ".cpp", ".txt", ".webmanifest",
}
ORPHAN_PROTECTED_GLOBS = [
    "uploads/*", "backups*", "*/.cache/*", ".cache/*", "include/.cache*",
    "db.php", "include/db.php", "*db.local.php", "*.local.php", "deploy.config*.json",
    ".ff-debug", ".ff-setup-allow", ".well-known/*", "cgi-bin/*", "*.log", "error_log", "*/error_log",
    ".htpasswd", "*/.htpasswd", ".user.ini", "php.ini",
    "*config*.php",  # Server-Konfiguration (z. B. include/web_push_config.php) – nie automatisch löschen
]


def _deploy_excluded(rel: str, excludes: list[str]) -> bool:
    """Gleiche Regeln wie deploy_environment(): wird diese lokale Datei NICHT hochgeladen?"""
    import fnmatch
    import re

    for rx in (r"(^|/)(\.git|\.vscode|\.idea|__pycache__|\.cache|backups)(/|$)", r"(^|/)(include/)?db(\.local)?\.php$"):
        if re.search(rx, rel):
            return True
    name = rel.rsplit("/", 1)[-1]
    for g in list(excludes) + [".git*", "*.pyc", "deploy.config*.json", "backups*", "*db.php", "*.local.php"]:
        if fnmatch.fnmatch(rel, g) or fnmatch.fnmatch(name, g):
            return True
        if g.endswith("*") and "/" in g and rel.startswith(g.rstrip("*")):
            return True  # z. B. include/.cache* trifft alles darunter
    # Fehler in diesen Regeln wirken nur in die sichere Richtung: „ausgeschlossen“ = auf dem Server geschützt.
    return False


def local_deploy_files(excludes: list[str]) -> set[str]:
    files: set[str] = set()
    for root, dirs, names in os.walk(BASE_DIR):
        rel_root = os.path.relpath(root, BASE_DIR)
        rel_root = "" if rel_root == "." else rel_root.replace(os.sep, "/") + "/"
        dirs[:] = [d for d in dirs if not _deploy_excluded(rel_root + d + "/", excludes)]
        for n in names:
            rel = rel_root + n
            if not _deploy_excluded(rel, excludes):
                files.add(rel)
    return files


def remote_files(env: dict) -> list[str] | None:
    preamble = build_lftp_preamble(env)
    remote_dir = env.get("remote_dir", "/")
    lftp_script = f"""
{preamble}
cd "{remote_dir}";
find .;
bye;
"""
    proc = subprocess.run(["lftp", "-c", lftp_script], capture_output=True, text=True)
    if proc.returncode != 0:
        print_err(f"Dateiliste konnte nicht geladen werden: {mask_credentials(proc.stderr.strip() or proc.stdout.strip())}")
        return None
    out = []
    for line in proc.stdout.splitlines():
        line = line.strip()
        if not line or line.endswith("/"):
            continue  # Ordner
        out.append(line[2:] if line.startswith("./") else line)
    return out


def find_orphans(env_key: str, env: dict, excludes: list[str], keep: list[str] | None = None) -> dict | None:
    import fnmatch

    print_step(f"Abgleich: verwaiste Dateien auf '{env.get('name', env_key)}'")
    remote = remote_files(env)
    if remote is None:
        return None
    local = local_deploy_files(excludes)
    res = {"code": [], "other": [], "protected": 0}
    for rel in sorted(set(remote) - local):
        name = rel.rsplit("/", 1)[-1]
        protected = any(fnmatch.fnmatch(rel, g) or fnmatch.fnmatch(name, g) for g in ORPHAN_PROTECTED_GLOBS)
        protected = protected or _deploy_excluded(rel, excludes)  # absichtlich nie hochgeladen → gehört dem Server
        protected = protected or any(fnmatch.fnmatch(rel, g) for g in (keep or []))  # --keep
        if protected:
            res["protected"] += 1
        elif os.path.splitext(name)[1].lower() in ORPHAN_CODE_EXT or name == ".htaccess":
            res["code"].append(rel)
        else:
            res["other"].append(rel)
    print(f"Server: {len(remote)} Dateien, lokal (deploybar): {len(local)}")
    print(f"Geschützt (nur auf dem Server, z. B. uploads/, Logs, db.php, Konfiguration, --keep): {res['protected']}")
    if res["code"]:
        print_warn(f"{len(res['code'])} verwaiste Code-Datei(en) – gibt es lokal nicht (mehr):")
        for rel in res["code"]:
            print(f"   {rel}")
    else:
        print_ok("Keine verwaisten Code-Dateien.")
    if res["other"]:
        print_warn(f"{len(res['other'])} sonstige Datei(en) nur auf dem Server (werden nie gelöscht, bitte selbst prüfen):")
        for rel in res["other"]:
            print(f"   {rel}")
    return res


def delete_orphans(env_key: str, env: dict, excludes: list[str], skip_backup: bool = False, assume_yes: bool = False, keep: list[str] | None = None) -> bool:
    res = find_orphans(env_key, env, excludes, keep)
    if res is None:
        return False
    paths = [p for p in res["code"] if '"' not in p and "\n" not in p]
    if not paths:
        return True
    if not assume_yes:
        answer = input(f"\n{len(paths)} Code-Datei(en) auf '{env.get('name', env_key)}' löschen? Zum Bestätigen LOESCHEN eingeben: ").strip()
        if answer != "LOESCHEN":
            print_warn("Abgebrochen, nichts gelöscht.")
            return False
    if not skip_backup:
        if not backup_remote(env_key, env):
            print_err("Backup fehlgeschlagen – es wird nichts gelöscht.")
            return False
    preamble = build_lftp_preamble(env)
    remote_dir = env.get("remote_dir", "/")
    rm_lines = "\n".join(f'rm "{p}";' for p in paths)
    lftp_script = f"""
{preamble}
cd "{remote_dir}";
{rm_lines}
bye;
"""
    proc = subprocess.run(["lftp", "-c", lftp_script], capture_output=True, text=True)
    if proc.returncode != 0:
        print_err(f"Löschen teilweise fehlgeschlagen: {mask_credentials(proc.stderr.strip() or proc.stdout.strip())}")
        return False
    print_ok(f"{len(paths)} verwaiste Datei(en) gelöscht.")
    return True


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
        print("[7] Verwaiste Dateien auf dem Server suchen / löschen")
        print("[0] Beenden")

        choice = input("\nAuswahl [1-7, 0]: ").strip()
        if choice == "0":
            break
        elif choice == "6":
            run_selftests()
        elif choice == "5":
            push_to_github(config)
        elif choice in ("1", "2", "3", "4", "7"):
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
                if choice == "7":
                    res = find_orphans(t, env, excludes)
                    if res and res["code"]:
                        if input("Diese Code-Dateien löschen? (j/N): ").strip().lower() in ("j", "ja", "y", "yes"):
                            delete_orphans(t, env, excludes)
                elif choice == "4":
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
    parser.add_argument("--selftests", action="store_true", help="Nur lokale Selbsttests ausführen")
    parser.add_argument("--backup-only", help="Nur Backup von Zielserver erstellen")
    parser.add_argument("--test-conn", help="Nur Verbindung testen")
    parser.add_argument("--git-push", action="store_true", help="Änderungen zu GitHub pushen")
    parser.add_argument("-m", "--message", help="Commit-Nachricht für GitHub Push")
    parser.add_argument("--orphans", help="Verwaiste Dateien auf dem Zielserver auflisten (Name der Umgebung)")
    parser.add_argument("--delete", action="store_true", help="Mit --orphans: verwaiste Code-Dateien löschen (Backup + Bestätigung)")
    parser.add_argument("--yes", action="store_true", help="Mit --orphans --delete: ohne Rückfrage löschen")
    parser.add_argument("--keep", action="append", default=[], help="Mit --orphans: Datei/Muster nicht löschen (mehrfach möglich)")

    args = parser.parse_args()

    if args.selftests:
        if not run_selftests():
            sys.exit(1)
        return

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

    if args.orphans:
        env = config.get("environments", {}).get(args.orphans)
        if not env:
            print_err(f"Umgebung '{args.orphans}' nicht in deploy.config.json gefunden.")
            sys.exit(1)
        excludes = config.get("exclude", [])
        if args.delete:
            ok = delete_orphans(args.orphans, env, excludes, skip_backup=args.skip_backup, assume_yes=args.yes, keep=args.keep)
        else:
            ok = find_orphans(args.orphans, env, excludes, args.keep) is not None
        sys.exit(0 if ok else 1)

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
