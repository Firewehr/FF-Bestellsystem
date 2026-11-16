#!/usr/bin/env python3
"""
tools/sse_capacity_test.py – Wie viele gleichzeitige Live-Verbindungen (Stationen) trägt ein Server?

Jede Stationsansicht hält eine Live-Verbindung (SSE) offen; sie belegt dabei einen PHP-Prozess und eine
DB-Verbindung. Auf Shared Hosting ist die Zahl der PHP-Prozesse pro Kunde begrenzt und nicht sichtbar.
Dieses Skript öffnet stufenweise immer mehr solcher Verbindungen (diagnose_sse_test.php?hold=1, verhält
sich wie eine Station) und misst nebenbei die Antwortzeit eines leichten Abrufs (api/v1/auth.php).
Steigt die Antwortzeit sprunghaft oder kommen Fehler/Verbindungen nicht mehr an, ist die Grenze erreicht.

- Schreibt nichts in die Datenbank (nur Login/Sitzung). Kein Druck, keine Bestellungen.
- Nicht während eines Fests ausführen: An der Grenze werden alle Seiten kurz langsam.
- Braucht nur die Python-Standardbibliothek (Python 3.7+). Benutzer mit Admin-Rechten.

Aufruf:
  FF_UI_USER=admin FF_UI_PASS=... python3 tools/sse_capacity_test.py --url https://example.at/pos/bestellsystem
  python3 tools/sse_capacity_test.py --url https://... --user admin --step 5 --max 60
"""

import argparse
import getpass
import http.client
import json
import os
import socket
import statistics
import sys
import threading
import time
import urllib.parse


class Server:
    def __init__(self, base_url: str):
        u = urllib.parse.urlparse(base_url.rstrip("/"))
        if u.scheme not in ("http", "https") or not u.hostname:
            sys.exit("--url muss mit http:// oder https:// beginnen.")
        self.https = u.scheme == "https"
        self.host = u.hostname
        self.port = u.port or (443 if self.https else 80)
        self.prefix = u.path.rstrip("/")
        self.cookie = ""

    def conn(self, timeout: float) -> http.client.HTTPConnection:
        cls = http.client.HTTPSConnection if self.https else http.client.HTTPConnection
        return cls(self.host, self.port, timeout=timeout)

    def headers(self, accept: str = "application/json") -> dict:
        h = {"Accept": accept, "User-Agent": "ff-sse-capacity-test"}
        if self.cookie:
            h["Cookie"] = self.cookie
        return h

    def login(self, user: str, password: str) -> None:
        c = self.conn(20)
        c.request("POST", self.prefix + "/api/v1/auth.php",
                  json.dumps({"action": "login", "username": user, "password": password}),
                  {**self.headers(), "Content-Type": "application/json"})
        r = c.getresponse()
        body = r.read()
        jar = {}
        for k, v in r.getheaders():
            if k.lower() == "set-cookie":
                name, _, val = v.split(";", 1)[0].partition("=")
                jar[name] = val  # letzter Wert gilt (Sitzungs-ID wird beim Login neu erzeugt)
        try:
            data = json.loads(body or b"{}")
        except ValueError:
            data = {}
        if r.status != 200 or not data.get("logged_in"):
            sys.exit(f"Login fehlgeschlagen (HTTP {r.status}): {data.get('message') or data.get('error') or body[:120]!r}")
        if int((data.get("user") or {}).get("admin", 0)) < 1:
            sys.exit("Der Benutzer braucht Admin-Rechte (diagnose_sse_test.php ist nur für Admins).")
        self.cookie = "; ".join(f"{k}={v}" for k, v in jar.items())


class Holder(threading.Thread):
    """Eine Live-Verbindung wie eine Station: halten, nach Ende (20 s) nach 1 s neu verbinden."""

    def __init__(self, srv: Server, stop: threading.Event):
        super().__init__(daemon=True)
        self.srv, self.stop = srv, stop
        self.holding = False
        self.failures = 0
        self.conn = None

    def close(self) -> None:
        """Blockierendes Lesen von außen beenden (sonst stürzt Python beim Beenden in offenen TLS-Verbindungen ab)."""
        c = self.conn
        if c is not None and c.sock is not None:
            try:
                c.sock.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass

    def run(self) -> None:
        while not self.stop.is_set():
            try:
                c = self.srv.conn(45)
                self.conn = c
                c.request("GET", self.srv.prefix + "/diagnose_sse_test.php?hold=1", headers=self.srv.headers("text/event-stream"))
                r = c.getresponse()
                if r.status != 200:
                    self.failures += 1
                    r.read()
                    c.close()
                    time.sleep(2)
                    continue
                while not self.stop.is_set():
                    line = r.readline()
                    if not line:
                        break
                    if line.startswith(b"event: holding"):
                        self.holding = True
                c.close()
            except Exception:
                if not self.stop.is_set():
                    self.failures += 1
                    time.sleep(1)
            self.conn = None
            self.holding = False
            if not self.stop.is_set():
                time.sleep(1.0)


# Art der Fehler (HTTP-Status bzw. Ausnahme) – zeigt, ob der Hoster abweist (z. B. 503/508) oder abbricht
ERROR_KINDS: dict = {}


def probe(srv: Server, samples: int) -> tuple:
    """Antwortzeiten (ms) eines leichten Abrufs; Fehler zählen."""
    times, errors = [], 0
    for _ in range(samples):
        t0 = time.time()
        try:
            c = srv.conn(30)
            c.request("GET", srv.prefix + "/api/v1/auth.php", headers=srv.headers())
            r = c.getresponse()
            r.read()
            c.close()
            if r.status == 200:
                times.append((time.time() - t0) * 1000)
            else:
                errors += 1
                ERROR_KINDS[f"HTTP {r.status}"] = ERROR_KINDS.get(f"HTTP {r.status}", 0) + 1
        except Exception as e:
            errors += 1
            ERROR_KINDS[type(e).__name__] = ERROR_KINDS.get(type(e).__name__, 0) + 1
        time.sleep(0.3)
    return times, errors


def main() -> None:
    ap = argparse.ArgumentParser(description="Misst, wie viele gleichzeitige Live-Verbindungen (Stationen) der Server trägt.")
    ap.add_argument("--url", required=True, help="Basis-URL der Installation, z. B. https://example.at/pos/bestellsystem")
    ap.add_argument("--user", default=os.environ.get("FF_UI_USER", ""), help="Admin-Benutzer (oder FF_UI_USER)")
    ap.add_argument("--step", type=int, default=5, help="Verbindungen pro Stufe (Standard 5)")
    ap.add_argument("--max", type=int, default=60, help="Höchstens so viele Verbindungen (Standard 60)")
    ap.add_argument("--samples", type=int, default=12, help="Messungen pro Stufe (Standard 12)")
    ap.add_argument("--yes", action="store_true", help="Ohne Rückfrage starten")
    args = ap.parse_args()

    user = args.user or input("Admin-Benutzer: ").strip()
    password = os.environ.get("FF_UI_PASS") or getpass.getpass("Kennwort: ")
    if not args.yes:
        print("Achtung: An der Grenze werden alle Seiten dieser Installation kurz langsam. Nicht während eines Fests ausführen.")
        if input("Starten? (j/N) ").strip().lower() not in ("j", "ja", "y", "yes"):
            sys.exit(0)

    srv = Server(args.url)
    srv.login(user, password)

    base, base_err = probe(srv, args.samples)
    if not base or base_err:
        sys.exit(f"Grundmessung fehlgeschlagen ({base_err} Fehler) – Server nicht erreichbar?")
    base_med = statistics.median(base)
    limit_ms = max(base_med * 4, base_med + 400)
    print(f"Grundmessung ohne Live-Verbindungen: Median {base_med:.0f} ms (Grenzwert {limit_ms:.0f} ms)\n")
    print(f"{'Verbindungen':>12} | {'gehalten':>8} | {'Median':>7} | {'90 %':>7} | {'Max':>7} | {'Fehler':>6}")

    stop = threading.Event()
    holders = []
    last_ok, result = 0, None
    try:
        level = 0
        while level < args.max:
            level = min(args.max, level + args.step)
            while len(holders) < level:
                h = Holder(srv, stop)
                h.start()
                holders.append(h)
                time.sleep(0.05)
            # Warten, bis die Verbindungen stehen (höchstens 15 s)
            deadline = time.time() + 15
            while time.time() < deadline and sum(h.holding for h in holders) < level:
                time.sleep(0.5)
            held = sum(h.holding for h in holders)
            times, errors = probe(srv, args.samples)
            held = max(held, sum(h.holding for h in holders))
            med = statistics.median(times) if times else float("inf")
            p90 = sorted(times)[int(0.9 * (len(times) - 1))] if times else float("inf")
            mx = max(times) if times else float("inf")
            print(f"{level:>12} | {held:>8} | {med:>5.0f}ms | {p90:>5.0f}ms | {mx:>5.0f}ms | {errors:>6}", flush=True)

            if errors:
                result = f"Fehler bei {level} Verbindungen."
            elif held < level * 0.8:
                result = f"Nur {held} von {level} Verbindungen kamen zustande – neue Verbindungen warten auf freie Prozesse."
            elif med > limit_ms or p90 > limit_ms * 2 or mx > 5000 or sum(t > max(2000, limit_ms * 3) for t in times) >= 2:
                result = (f"Antwortzeit bei {level} Verbindungen sprunghaft gestiegen (Median {med:.0f} ms, "
                          f"90 % {p90:.0f} ms, langsamster {mx:.0f} ms; vorher Median {base_med:.0f} ms) – Anfragen warten auf freie Prozesse.")
            if result:
                break
            last_ok = level
    except KeyboardInterrupt:
        print("\nAbgebrochen.")
    finally:
        stop.set()
        for h in holders:
            h.close()
        for h in holders:
            h.join(timeout=5)

    print()
    if result:
        print(result)
        if ERROR_KINDS:
            print("Fehlerarten: " + ", ".join(f"{k} ×{v}" for k, v in sorted(ERROR_KINDS.items())))
        print(f"Ergebnis: Grenze liegt vermutlich zwischen {last_ok} und {level} gleichzeitigen Live-Verbindungen.")
        print("Stationen + Druck-Clients (mit print_event_mode) sollten deutlich darunter bleiben – sonst im")
        print("Pilot-Admin unter Allgemeine Einstellungen „Stationen: Live-Verbindung“ ausschalten.")
    else:
        print(f"Ergebnis: Bis {last_ok} gleichzeitige Live-Verbindungen kein Engpass gemessen.")
    print("Die Server-Seite der Testverbindungen endet spätestens nach 20 Sekunden von selbst.")


if __name__ == "__main__":
    main()
