#!/usr/bin/env python3
"""
tools/stress_test.py
Simulierter Stresstest-Runner für das FeuerwehrBestellsystem.

Simuliert realistische Festzelt-Lastspitzen (z. B. 20 Kellner, 100 Tische, 500 Bestellungen
in 60 Sekunden), um Datenbank-Bottlenecks, Lock-Waits, Latenzen und Fehler vor dem
Festbeginn zu identifizieren.

Beispiel-Aufrufe:
  python3 tools/stress_test.py --waiters 20 --tables 100 --orders 500 --duration 60 --cleanup
  python3 tools/stress_test.py --orders 50 --duration 10 --cleanup
"""

import argparse
import concurrent.futures
import json
import math
import os
import random
import statistics
import sys
import time
import urllib.parse
import urllib.request
from typing import Any, Dict, List, Optional, Tuple

# ANSI Farbcodes
C_RESET = "\033[0m"
C_BOLD = "\033[1m"
C_RED = "\033[91m"
C_GREEN = "\033[92m"
C_YELLOW = "\033[93m"
C_BLUE = "\033[94m"
C_CYAN = "\033[96m"


class SessionClient:
    """HTTP Client mit automatischer Cookie-Verwaltung."""

    def __init__(self, base_url: str):
        self.base_url = base_url.rstrip("/")
        self.cookies: Dict[str, str] = {}

    def _update_cookies(self, headers: Any):
        for header, val in headers.items():
            if header.lower() == "set-cookie":
                for part in val.split(","):
                    cookie_part = part.split(";")[0].strip()
                    if "=" in cookie_part:
                        k, v = cookie_part.split("=", 1)
                        self.cookies[k.strip()] = v.strip()

    def _get_cookie_header(self) -> str:
        return "; ".join(f"{k}={v}" for k, v in self.cookies.items())

    def request(
        self,
        method: str,
        path: str,
        json_data: Optional[Dict[str, Any]] = None,
        timeout: float = 10.0,
    ) -> Tuple[int, Dict[str, Any], float]:
        url = f"{self.base_url}/{path.lstrip('/')}"
        headers = {"User-Agent": "FF-POS-StressTest/1.0"}
        body = None

        if json_data is not None:
            headers["Content-Type"] = "application/json"
            body = json.dumps(json_data).encode("utf-8")

        cookie_str = self._get_cookie_header()
        if cookie_str:
            headers["Cookie"] = cookie_str

        req = urllib.request.Request(url, data=body, headers=headers, method=method)
        t0 = time.perf_counter()

        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                status = resp.status
                self._update_cookies(resp.headers)
                raw_body = resp.read().decode("utf-8", errors="replace")
                elapsed_ms = (time.perf_counter() - t0) * 1000.0
                try:
                    parsed = json.loads(raw_body)
                except Exception:
                    parsed = {"raw": raw_body}
                return status, parsed, elapsed_ms
        except urllib.error.HTTPError as e:
            self._update_cookies(e.headers)
            raw_body = e.read().decode("utf-8", errors="replace")
            elapsed_ms = (time.perf_counter() - t0) * 1000.0
            try:
                parsed = json.loads(raw_body)
            except Exception:
                parsed = {"raw": raw_body}
            return e.code, parsed, elapsed_ms
        except Exception as e:
            elapsed_ms = (time.perf_counter() - t0) * 1000.0
            return 0, {"error": str(e)}, elapsed_ms


def run_stress_test(
    base_url: str,
    num_waiters: int,
    num_tables: int,
    total_orders: int,
    duration_sec: float,
    user: str,
    password: str,
    cleanup: bool,
):
    print(f"\n{C_BOLD}{C_CYAN}=== FEUERWEHR BESTELLSYSTEM – STRESSTEST RUNNER ==={C_RESET}")
    print(f"Ziel-URL:            {base_url}")
    print(f"Simulierte Kellner:  {C_BOLD}{num_waiters}{C_RESET}")
    print(f"Aktive Tische:       {C_BOLD}{num_tables}{C_RESET}")
    print(f"Bestellungen gesamt: {C_BOLD}{total_orders}{C_RESET}")
    print(f"Geplante Testdauer:  {C_BOLD}{duration_sec:.1f} s{C_RESET}")
    print(f"Ziel-Durchsatz:      ~{total_orders / max(1.0, duration_sec):.1f} Bestellungen/s")
    print(f"Auto-Bereinigung:    {'Ja (markierte Testzeilen werden gelöscht)' if cleanup else 'Nein'}")
    print("-" * 60)

    # 1. Master Login & Daten laden
    master = SessionClient(base_url)
    print("🔐 Authentifiziere Master-Session...", end=" ", flush=True)
    status, auth_res, lat = master.request(
        "POST", "api/v1/auth.php", {"username": user, "password": password}
    )

    if status != 200 or not auth_res.get("ok"):
        print(f"{C_RED}FEHLGESCHLAGEN{C_RESET} ({status}: {auth_res})")
        sys.exit(1)

    csrf_token = auth_res.get("csrf_token")
    print(f"{C_GREEN}OK{C_RESET} (Latenz: {lat:.1f} ms, CSRF empfangen)")

    # 2. Speisekarte laden
    print("📋 Lade Speisekarte & verfügbare Positionen...", end=" ", flush=True)
    _, menu_res, _ = master.request("GET", "api/v1/menu.php")
    positions = []

    for item in menu_res.get("items", []):
        pid = item.get("id")
        name = item.get("name")
        if pid and name and not item.get("is_sold_out") and not item.get("is_locked"):
            positions.append({"id": pid, "name": name, "price": item.get("price", 0)})

    if not positions:
        print(f"{C_RED}Keine Positionen gefunden!{C_RESET}")
        sys.exit(1)

    print(f"{C_GREEN}OK{C_RESET} ({len(positions)} Positionen verfügbar)")

    # 3. Tisch-IDs laden
    print("🍽️ Lade Tischübersicht...", end=" ", flush=True)
    _, tables_res, _ = master.request("GET", "list_tische_json.php")
    table_ids = []
    if isinstance(tables_res, list):
        for t in tables_res:
            tid = t.get("tischnummer") or t.get("id")
            if tid:
                table_ids.append(int(tid))

    # Fallback Tische falls JSON-Endpunkt anders formatiert
    if not table_ids:
        table_ids = [100138, 100139, 100140, 100141, 100142, 100143, 100144, 7191, 7192]

    # Auf die gewünschte Tischanzahl begrenzen
    table_pool = table_ids[:num_tables]
    print(f"{C_GREEN}OK{C_RESET} ({len(table_pool)} Tische im Test-Pool)")

    # Eindeutige Kennung für diesen Stresstest-Lauf
    run_id = f"STRESS_{int(time.time())}_{random.randint(1000, 9999)}"

    # 4. Kellner-Clients initialisieren
    print(f"👥 Initialisiere {num_waiters} Kellner-Sessions...", end=" ", flush=True)
    waiter_clients: List[Tuple[SessionClient, str]] = []
    for i in range(num_waiters):
        c = SessionClient(base_url)
        # Login
        st, a_res, _ = c.request(
            "POST", "api/v1/auth.php", {"username": user, "password": password}
        )
        csrf = a_res.get("csrf_token") if a_res else csrf_token
        waiter_clients.append((c, csrf))
    print(f"{C_GREEN}OK{C_RESET}")

    # 5. Stresstest ausführen
    print(f"\n{C_BOLD}🚀 Starte Lasttest ({total_orders} Bestellungen)...{C_RESET}")
    start_time = time.perf_counter()
    latencies: List[float] = []
    success_count = 0
    fail_count = 0
    errors: Dict[str, int] = {}

    # Berechne Soll-Intervall zwischen Bestelleingängen
    target_interval = (duration_sec / total_orders) if (duration_sec > 0 and total_orders > 0) else 0.0

    def submit_single_order(index: int) -> Tuple[bool, float, Optional[str]]:
        client, csrf = waiter_clients[index % len(waiter_clients)]
        table_id = random.choice(table_pool)

        # 1 bis 3 zufällige Positionen auswählen
        num_items = random.randint(1, 3)
        chosen = random.sample(positions, min(num_items, len(positions)))
        items = []
        for p in chosen:
            items.append({
                "position_id": p["id"],
                "quantity": random.randint(1, 2),
                "zusatzinfo": f"{run_id}_P{index}",
            })

        payload = {
            "csrf_token": csrf,
            "tischnummer": table_id,
            "items": items,
        }

        status, resp, elapsed_ms = client.request("POST", "api/v1/order.php", payload)
        if status == 200 and resp.get("ok"):
            return True, elapsed_ms, None

        err_msg = str(resp.get("message") or resp.get("error") or f"HTTP {status}")
        return False, elapsed_ms, err_msg

    with concurrent.futures.ThreadPoolExecutor(max_workers=num_waiters) as executor:
        futures = []
        for i in range(total_orders):
            # Optionales Pacing
            if target_interval > 0:
                expected_time = start_time + (i * target_interval)
                now = time.perf_counter()
                sleep_sec = expected_time - now
                if sleep_sec > 0.005:
                    time.sleep(sleep_sec)

            futures.append(executor.submit(submit_single_order, i))

            # Live Fortschrittsanzeige alle 25 Bestellungen
            if (i + 1) % 25 == 0 or (i + 1) == total_orders:
                elapsed_now = time.perf_counter() - start_time
                rps = (i + 1) / max(0.1, elapsed_now)
                sys.stdout.write(
                    f"\r  Fortschritt: {i + 1}/{total_orders} ({(i + 1) * 100 // total_orders}%) | "
                    f"Aktuell: {rps:.1f} Orders/s | Zeit: {elapsed_now:.1f}s"
                )
                sys.stdout.flush()

        for f in concurrent.futures.as_completed(futures):
            ok, lat, err = f.result()
            latencies.append(lat)
            if ok:
                success_count += 1
            else:
                fail_count += 1
                errors[err] = errors.get(err, 0) + 1

    total_time = time.perf_counter() - start_time
    print("\n\n" + "-" * 60)
    print(f"{C_BOLD}{C_CYAN}=== ERGEBNISSE DES STRESSTESTS ==={C_RESET}")
    print(f"Gesamtdauer:         {total_time:.2f} Sekunden")
    print(f"Erfolgreich:         {C_GREEN}{success_count} / {total_orders}{C_RESET} ({success_count * 100 / max(1, total_orders):.1f}%)")

    if fail_count > 0:
        print(f"Fehlgeschlagen:      {C_RED}{fail_count}{C_RESET}")
        for err, cnt in errors.items():
            print(f"   -> {err}: {cnt}x")
    else:
        print(f"Fehlgeschlagen:      {C_GREEN}0 (Keine Fehler){C_RESET}")

    actual_rps = total_orders / max(0.001, total_time)
    print(f"Tatsächlicher Durchsatz: {C_BOLD}{actual_rps:.1f} Bestellungen/s{C_RESET} (~{actual_rps * 60:.0f} Orders/min)")

    if latencies:
        latencies.sort()
        avg_lat = statistics.mean(latencies)
        med_lat = statistics.median(latencies)
        p95_lat = latencies[int(len(latencies) * 0.95)] if len(latencies) > 1 else latencies[0]
        p99_lat = latencies[int(len(latencies) * 0.99)] if len(latencies) > 1 else latencies[0]
        max_lat = max(latencies)
        min_lat = min(latencies)

        print("\nAntwortzeiten (Latenz):")
        print(f"  Minimal:           {min_lat:.1f} ms")
        print(f"  Mittelwert (Avg):  {avg_lat:.1f} ms")
        print(f"  Median (P50):      {med_lat:.1f} ms")
        print(f"  95. Perzentil:     {C_YELLOW if p95_lat > 500 else C_GREEN}{p95_lat:.1f} ms{C_RESET}")
        print(f"  99. Perzentil:     {C_YELLOW if p99_lat > 1000 else C_GREEN}{p99_lat:.1f} ms{C_RESET}")
        print(f"  Maximal:           {max_lat:.1f} ms")

        # Bewertung
        print("\nBewertung der Systemleistung:")
        if p95_lat < 250 and fail_count == 0:
            print(f"  {C_GREEN}✔ AUSGEZEICHNET{C_RESET}: Das System bewältigt Stoßzeiten ohne Verzögerungen.")
        elif p95_lat < 750 and fail_count == 0:
            print(f"  {C_GREEN}✔ GUT{C_RESET}: Solide Performance unter Last. Unbedenklich für Festbetrieb.")
        elif fail_count == 0:
            print(f"  {C_YELLOW}⚠ BEFRIEDIGEND{C_RESET}: Keine Fehler, aber P95 > 750 ms. Datenbank-Indizes prüfen.")
        else:
            print(f"  {C_RED}❌ KRITISCH{C_RESET}: Es traten Fehler auf. Datenbank-Lock-Waits oder Server-Limits prüfen.")

    # 6. Bereinigung der Testdaten
    if cleanup:
        print(f"\n🧹 Bereinige Test-Bestellungen (Tag: {run_id})...", end=" ", flush=True)
        # Über MySQL Befehl oder PHP Skript direkt löschen
        import subprocess
        clean_cmd = f"php -r \"require_once 'include/db.php'; mysqli_query(\\$conn, \\\"DELETE FROM bestellungen WHERE Zusatzinfo LIKE '%{run_id}%'\\\"); echo mysqli_affected_rows(\\$conn);\""
        try:
            out = subprocess.check_output(clean_cmd, shell=True).decode().strip()
            print(f"{C_GREEN}OK{C_RESET} ({out} Test-Positionen entfernt)")
        except Exception as e:
            print(f"{C_YELLOW}Manuelle Bereinigung erforderlich: {e}{C_RESET}")

    print("-" * 60)
    print(f"{C_BOLD}Stresstest abgeschlossen.{C_RESET}\n")


def main():
    parser = argparse.ArgumentParser(description="FeuerwehrBestellsystem – Stresstest Runner")
    parser.add_argument("--url", default="https://pos.ballner.ipv64.de", help="Basis-URL der Installation")
    parser.add_argument("--waiters", type=int, default=20, help="Anzahl gleichzeitiger Kellner (Threads)")
    parser.add_argument("--tables", type=int, default=100, help="Anzahl Tische im Pool")
    parser.add_argument("--orders", type=int, default=500, help="Anzahl der Gesamtbestellungen")
    parser.add_argument("--duration", type=float, default=60.0, help="Geplante Dauer in Sekunden (0 = Maximaler Burst)")
    parser.add_argument("--user", default="ki", help="Benutzername für Testkellner")
    parser.add_argument("--password", default="ki12345", help="Kennwort für Testkellner")
    parser.add_argument("--cleanup", action="store_true", default=True, help="Testdaten am Ende automatisch löschen")
    parser.add_argument("--no-cleanup", action="store_false", dest="cleanup", help="Testdaten in der Datenbank belassen")

    args = parser.parse_args()
    run_stress_test(
        base_url=args.url,
        num_waiters=args.waiters,
        num_tables=args.tables,
        total_orders=args.orders,
        duration_sec=args.duration,
        user=args.user,
        password=args.password,
        cleanup=args.cleanup,
    )


if __name__ == "__main__":
    main()
