#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
FF Fest – Offline-Sicherung per API (Token wie Drucker-Token).

Ruft fest_offline_snapshot_api.php per POST mit offline_backup_token auf
und schreibt die HTML-Sicherung wiederholt auf die Festplatte.

Nur Standardbibliothek (kein pip-Zwang).

Beispiel:
  python fest_offline_backup.py --config fest_offline_backup.ini
  python fest_offline_backup.py --once --url https://server/pfad/ --token GEHEIM --out D:/backup.html
"""

from __future__ import annotations

import argparse
import configparser
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


def load_config(path: Path) -> dict:
    cp = configparser.ConfigParser()
    if not path.is_file():
        print(f"Konfigurationsdatei fehlt: {path}", file=sys.stderr)
        sys.exit(2)
    cp.read(path, encoding="utf-8")
    sec = cp["backup"]
    return {
        "base_url": sec.get("base_url", "").strip(),
        "token": sec.get("token", "").strip(),
        "output_file": sec.get("output_file", "").strip(),
        "interval_seconds": sec.getint("interval_seconds", fallback=30),
    }


def normalize_base_url(url: str) -> str:
    url = url.strip()
    if not url:
        return ""
    return url.rstrip("/") + "/"


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """Folgt keinen Redirects: bei 301/302 würde urllib aus POST ein GET ohne Body
    machen und das Token wäre weg. Wir wollen das stattdessen sehen und melden.
    """

    def http_error_301(self, req, fp, code, msg, headers):
        return None

    def http_error_302(self, req, fp, code, msg, headers):
        return None

    def http_error_303(self, req, fp, code, msg, headers):
        return None

    def http_error_307(self, req, fp, code, msg, headers):
        return None


def fetch_snapshot(base_url: str, token: str, timeout: int = 120) -> dict:
    api = normalize_base_url(base_url) + "fest_offline_snapshot_api.php"

    # Token doppelt mitgeben: in der URL (Query) UND im POST-Body. Damit übersteht
    # die Anfrage auch Server, die POST → GET umleiten (z. B. http→https oder
    # mod_rewrite mit Trailing-Slash-Normalisierung).
    qs = urllib.parse.urlencode({"token": token})
    api_with_token = api + ("&" if "?" in api else "?") + qs
    data = qs.encode("utf-8")
    req = urllib.request.Request(
        api_with_token,
        data=data,
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded; charset=utf-8"},
    )
    ctx = ssl.create_default_context()
    opener = urllib.request.build_opener(_NoRedirect)
    try:
        with opener.open(req, timeout=timeout) as resp:
            body = resp.read().decode("utf-8")
        return json.loads(body)
    except urllib.error.HTTPError as e:
        # 3xx ohne Auto-Redirect: gezielt anzeigen, wohin der Server umleiten will.
        if e.code in (301, 302, 303, 307, 308):
            new_location = e.headers.get("Location") if e.headers else None
            hint = f"Server leitet auf {new_location} um – bitte base_url in der INI auf genau diese URL setzen (mit /)."
            raise urllib.error.HTTPError(e.url, e.code, f"{e.reason} – {hint}", e.headers, None)
        # Server liefert auch im Fehlerfall JSON mit error/message.
        try:
            raw = e.read().decode("utf-8", errors="replace")
            payload = json.loads(raw)
        except Exception:
            raise
        msg = payload.get("message") or payload.get("error") or e.reason
        raise urllib.error.HTTPError(e.url, e.code, f"{e.reason} – {msg}", e.headers, None)


def write_html(path: str, html: str) -> None:
    p = Path(path)
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_suffix(p.suffix + ".tmp")
    tmp.write_text(html, encoding="utf-8")
    tmp.replace(p)


def run_loop(cfg: dict) -> None:
    base = cfg["base_url"]
    token = cfg["token"]
    out = cfg["output_file"]
    interval = max(5, int(cfg["interval_seconds"]))

    if not base or not token or not out:
        print("base_url, token und output_file müssen gesetzt sein.", file=sys.stderr)
        sys.exit(2)

    print(f"Offline-Backup: alle {interval}s → {out}")
    print(f"URL: {normalize_base_url(base)}fest_offline_snapshot_api.php")
    print(f"Token-Länge: {len(token)} Zeichen")
    print("Strg+C zum Beenden.")
    while True:
        try:
            j = fetch_snapshot(base, token)
            if not j.get("ok") or "html" not in j:
                print(f"[{time.strftime('%H:%M:%S')}] Fehler: API ok={j.get('ok')}", file=sys.stderr)
            else:
                write_html(out, j["html"])
                label = j.get("generated_label", "")
                print(f"[{time.strftime('%H:%M:%S')}] OK · Stand {label}")
        except urllib.error.HTTPError as e:
            print(f"[{time.strftime('%H:%M:%S')}] HTTP {e.code}: {e.reason}", file=sys.stderr)
        except urllib.error.URLError as e:
            print(f"[{time.strftime('%H:%M:%S')}] Netzwerk: {e}", file=sys.stderr)
        except (json.JSONDecodeError, OSError, ValueError) as e:
            print(f"[{time.strftime('%H:%M:%S')}] Fehler: {e}", file=sys.stderr)
        time.sleep(interval)


def run_once(base_url: str, token: str, out_path: str) -> int:
    try:
        j = fetch_snapshot(base_url, token)
        if not j.get("ok") or "html" not in j:
            print(json.dumps(j, ensure_ascii=False, indent=2))
            return 1
        write_html(out_path, j["html"])
        print(f"Geschrieben: {out_path} · {j.get('generated_label', '')}")
        return 0
    except Exception as e:
        print(str(e), file=sys.stderr)
        return 1


def main() -> None:
    parser = argparse.ArgumentParser(description="FF Fest Offline-Sicherung (API + Token)")
    parser.add_argument(
        "--config",
        "-c",
        default="fest_offline_backup.ini",
        help="INI-Datei neben diesem Skript (Standard: fest_offline_backup.ini)",
    )
    parser.add_argument("--once", action="store_true", help="Nur ein Abruf, dann Ende")
    parser.add_argument("--url", help="Basis-URL (überschreibt INI)")
    parser.add_argument("--token", help="Offline-Backup-Token (überschreibt INI)")
    parser.add_argument("--out", help="Ausgabedatei (überschreibt INI)")
    args = parser.parse_args()

    script_dir = Path(__file__).resolve().parent
    cfg_path = Path(args.config)
    if not cfg_path.is_absolute():
        cfg_path = script_dir / cfg_path

    cfg: dict = {}
    if cfg_path.is_file():
        cfg = load_config(cfg_path)
    if args.url:
        cfg["base_url"] = args.url.strip()
    if args.token:
        cfg["token"] = args.token.strip()
    if args.out:
        cfg["output_file"] = args.out.strip()

    def need_keys() -> list:
        return [k for k in ("base_url", "token", "output_file") if not (cfg.get(k) or "").strip()]

    if args.once:
        miss = need_keys()
        if miss:
            print(
                "Fehlende Angaben: " + ", ".join(miss) + " — INI anlegen oder --url / --token / --out setzen.",
                file=sys.stderr,
            )
            sys.exit(2)
        sys.exit(run_once(cfg["base_url"].strip(), cfg["token"].strip(), cfg["output_file"].strip()))

    miss = need_keys()
    if miss:
        print(
            "Fehlende Angaben: " + ", ".join(miss) + f" — bitte {cfg_path.name} anlegen (siehe .ini.example).",
            file=sys.stderr,
        )
        sys.exit(2)

    run_loop(cfg)


if __name__ == "__main__":
    main()
