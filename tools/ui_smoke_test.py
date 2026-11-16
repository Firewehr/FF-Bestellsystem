#!/usr/bin/env python3
"""
UI-Smoke-Test für Kellner-App, Station und Pilot-Admin (Playwright, Chromium headless).

Prüft die Bedienoberflächen im echten Browser gegen eine laufende Instanz, OHNE etwas zu
speichern: Alle POST-Anfragen außer dem Login werden abgefangen und mit {"ok":true}
beantwortet. Wo das Ergebnis von Live-Daten abhinge (Zahlen-Seite, Historie), liefert der
Test feste Beispieldaten an den Browser – die Datenbank bleibt unberührt.

Voraussetzungen (nur auf dem Prüfrechner, nicht im Web-Backend):
    pip install playwright && python -m playwright install chromium

Aufruf:
    FF_UI_USER=... FF_UI_PASS=... python3 tools/ui_smoke_test.py [--base https://host] [--shots DIR]

Zugangsdaten nie in diese Datei schreiben. Exit-Code 0 = alle Prüfungen bestanden.
"""
import argparse
import json
import os
import sys

try:
    from playwright.sync_api import sync_playwright
except ImportError:
    sys.exit("Playwright fehlt: pip install playwright && python -m playwright install chromium")

RESULTS = []


def check(name, ok, detail=""):
    RESULTS.append((name, bool(ok), detail))
    print(f"[{'OK  ' if ok else 'FAIL'}] {name}" + (f" – {detail}" if detail else ""))


# ---------------------------------------------------------------------------
# Feste Beispieldaten (nur im Browser, nie in der Datenbank)
# ---------------------------------------------------------------------------
def fake_table_orders(table_no, table_name):
    groups, total, count, rid = [], 0.0, 0, 900000
    for i, (name, price, qty, note) in enumerate([
        ("Schnitzel", 13.0, 3, "Ohne Zwiebel"), ("Bratwurst", 6.0, 2, ""), ("Kotelett", 13.0, 1, ""),
        ("Bier 0.5L", 4.8, 6, ""), ("Radler 0.5L", 4.8, 3, ""), ("Pommes", 3.8, 2, ""), ("Mineral", 3.0, 2, ""),
    ]):
        ids = list(range(rid, rid + qty)); rid += qty
        groups.append({"group_key": f"t{i}", "position_id": 1000 + i, "name": name, "short_name": name,
                       "single_price": price, "quantity": qty, "total_price": round(price * qty, 2),
                       "zusatzinfo": note, "rowids": ids, "payable_rowids": ids})
        total += price * qty; count += qty
    return {"ok": True, "table": {"tischnummer": table_no, "tischname": table_name, "is_sammelrechnung": False,
                                  "is_ehrengast": False, "color": "#000000"},
            "payment_mode": "after", "wait_times": {"drink": 0, "food": 0}, "pay_cash_modal": True,
            "sumup": {"enabled": True}, "print_targets": [], "total_amount": round(total, 2), "open_count": count,
            "pending_rechnung_count": 0, "items": [], "grouped": groups}


def fake_table_history(table_no, table_name):
    items = [{"rowid": 800000 + i, "position_id": 26, "name": "Kotelett", "short_name": "Kotelett", "price": 13.0,
              "zusatzinfo": "", "is_paid": False, "is_ready": False, "is_delivered": True, "is_cancelled": False,
              "can_cancel": True, "can_move": True} for i in range(5)]
    items.append({**items[0], "rowid": 800010, "name": "Bier", "position_id": 9, "price": 4.8})
    rnd = {"batch_key": "smoke1", "order_nr": 1, "timestamp": "2026-01-01 12:00:00", "kellner": "smoke",
           "items": items, "live_count": len(items), "total_amount": 5 * 13.0 + 4.8, "status_payment": "open",
           "status_delivery": "delivered", "is_cancelled": False, "can_cancel": True, "can_move": True}
    return {"ok": True, "table": {"tischnummer": table_no, "tischname": table_name}, "rounds": [rnd]}


def make_context(browser, base, user, pw, width, height, posts):
    ctx = browser.new_context(viewport={"width": width, "height": height}, locale="de-AT")

    def guard(route):
        req = route.request
        if req.method != "GET" and "auth.php" not in req.url:
            posts.append(req.url.split("/")[-1][:40])
            return route.fulfill(status=200, content_type="application/json", body='{"ok":true}')
        return route.continue_()

    ctx.route("**/*", guard)
    r = ctx.request.post(base + "/api/v1/auth.php", data=json.dumps({"action": "login", "username": user, "password": pw}),
                         headers={"Content-Type": "application/json"})
    if not r.ok:
        sys.exit(f"Login fehlgeschlagen (HTTP {r.status}) – FF_UI_USER/FF_UI_PASS prüfen.")
    return ctx


def page_overflow(pg):
    return pg.evaluate("document.documentElement.scrollWidth") > pg.viewport_size["width"]


# ---------------------------------------------------------------------------
# Kellner-App
# ---------------------------------------------------------------------------
def test_waiter(browser, base, user, pw, shots):
    posts, errors = [], []
    ctx = make_context(browser, base, user, pw, 412, 860, posts)
    pg = ctx.new_page()
    pg.on("pageerror", lambda e: errors.append(str(e)))
    dialogs = []
    pg.on("dialog", lambda d: (dialogs.append(d.message), d.dismiss()))
    pg.goto(base + "/pilot/index.html", wait_until="networkidle"); pg.wait_for_timeout(1200)
    # Selbstprüfung: Ein absichtlicher POST muss abgefangen werden, sonst sofort abbrechen
    before = len(posts)
    pg.evaluate("fetch('../api/v1/admin.php', {method: 'POST', body: '{\"action\":\"smoke_guard\"}'}).catch(() => {})")
    pg.wait_for_timeout(500)
    if len(posts) != before + 1:
        sys.exit("ABBRUCH: Schreibschutz greift nicht – es wurde nichts weiter getestet.")
    check("Schreibschutz aktiv (Test-POST abgefangen)", True)
    check("Kellner-App lädt Speisekarte", pg.locator(".menu-card").count() > 0)

    # Portionsdialog: Artikel ohne verknüpfte Artikel suchen (die öffnen erst einen Auswahldialog)
    pg.locator(".main-tab-btn").nth(1).click(); pg.wait_for_timeout(500)
    card = None
    for i in range(min(pg.locator(".menu-card").count(), 12)):
        c = pg.locator(".menu-card").nth(i)
        c.click(); pg.wait_for_timeout(300)
        if pg.locator(".modal-overlay").count():
            pg.locator(".modal-overlay .sheet-close-btn").first.click(); pg.wait_for_timeout(300)
            continue
        card = c
        break
    if card is None:
        check("Portionsdialog", False, "kein geeigneter Artikel gefunden")
    else:
        card.click(); card.click(); pg.wait_for_timeout(300)
        card.locator(".card-mini-btn.opt").click(); pg.wait_for_timeout(500)
        sh = pg.locator(".options-sheet")
        sh.locator(".options-mode-switch .p-view-btn").nth(1).click(); pg.wait_for_timeout(300)
        tabs = sh.locator(".portion-tab")
        check("Portionsdialog: 3 Reiter bei Menge 3", tabs.count() == 3, f"{tabs.count()} Reiter")
        tabs.nth(1).click(); pg.wait_for_timeout(200)
        sh.locator("textarea.note-textarea").fill("Smoke-Test")
        check("Portionsdialog: farbiger Kasten der aktiven Portion", sh.locator(".portion-panel.is-colored").count() == 1)
        sh.locator(".options-mode-switch .p-view-btn").nth(0).click(); pg.wait_for_timeout(300)
        mode = sh.locator(".options-mode-switch .p-view-btn.active").inner_text().strip()
        check("„Alle gleich“ fragt bei unterschiedlichen Portionen nach", len(dialogs) == 1 and mode == "Einzeln",
              f"Dialoge={len(dialogs)}, Modus={mode}")
        sh.locator(".sheet-footer .btn-primary-block").click(); pg.wait_for_timeout(500)
        if shots: pg.screenshot(path=os.path.join(shots, "waiter_menu.png"))
        pg.locator(".cart-sticky-info").click(); pg.wait_for_timeout(500)
        check("Warenkorb: Artikel mit Portionen als Kasten", pg.locator(".cart-article.is-split .portion-num").count() >= 2)
        if shots: pg.screenshot(path=os.path.join(shots, "waiter_cart.png"))
        pg.locator(".modal-overlay .sheet-close-btn").first.click(); pg.wait_for_timeout(300)

    # Zahlen-Seite und Historie mit festen Beispieldaten
    pg.locator(".nav-item-btn").nth(4).click(); pg.wait_for_timeout(1000)
    tile = pg.locator(".table-tile").first
    tname = tile.inner_text().split("\n")[0].strip()
    ctx.route("**/table_orders.php*", lambda r: r.fulfill(status=200, content_type="application/json",
                                                          body=json.dumps(fake_table_orders(1, tname))))
    ctx.route("**/table_history.php*", lambda r: r.fulfill(status=200, content_type="application/json",
                                                           body=json.dumps(fake_table_history(1, tname))))
    tile.click(); pg.wait_for_timeout(800)
    pg.locator(".nav-item-btn").nth(1).click(); pg.wait_for_timeout(1200)
    check("Zahlen: fixierte Leiste sichtbar", pg.locator(".pay-sticky-bar").is_visible())
    check("Zahlen: kein doppelter Tisch-Kopf", pg.locator(".pay-card-header").count() == 0)
    pg.locator(".pay-row").nth(1).click(); pg.wait_for_timeout(300)
    check("Zahlen: Auswahl färbt die Leiste", pg.locator(".pay-sticky-bar.has-selection").count() == 1)
    pg.evaluate("window.scrollTo(0, document.body.scrollHeight); document.body.scrollTop = 1e6"); pg.wait_for_timeout(300)
    gap = pg.evaluate("""(() => { const b = [...document.querySelectorAll('.pay-more-actions button')].pop();
        const bar = document.querySelector('.pay-sticky-bar'); return bar.getBoundingClientRect().top - b.getBoundingClientRect().bottom; })()""")
    check("Zahlen: Leiste verdeckt keinen Inhalt", gap >= 0, f"Abstand {gap:.0f}px")
    if shots: pg.screenshot(path=os.path.join(shots, "waiter_pay.png"))
    pg.locator(".nav-item-btn").nth(2).click(); pg.wait_for_timeout(1200)
    groups = pg.locator(".round-item-group").all_inner_texts()
    check("Historie: gleiche Positionen gebündelt", any("5×" in g and "Kotelett" in g for g in groups), f"{len(groups)} Zeilen")
    check("Historie: offene Runde orange markiert", pg.locator(".round-card.round-status-open").count() == 1)
    check("Kellner-App: keine Seitenbreite über Bildschirm", not page_overflow(pg))
    check("Kellner-App: keine JS-Fehler", not errors, "; ".join(errors)[:200])
    ctx.close()
    return posts


# ---------------------------------------------------------------------------
# Station und Admin
# ---------------------------------------------------------------------------
def test_station(browser, base, user, pw, shots):
    posts, errors = [], []
    ctx = make_context(browser, base, user, pw, 1280, 800, posts)
    pg = ctx.new_page(); pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(base + "/pilot/station.html", wait_until="networkidle"); pg.wait_for_timeout(2000)
    n = pg.locator(".order-card").count()
    colored = pg.locator(".order-card.has-table-color .table-color-chip").count()
    dv = pg.locator(".order-card.is-dv").count()
    check("Station lädt", True, f"{n} Karten")
    check("Station: Tischkarten tragen Tischfarbe", colored == n - dv, f"{colored} von {n - dv}")
    check("Station: keine JS-Fehler", not errors, "; ".join(errors)[:200])
    if shots: pg.screenshot(path=os.path.join(shots, "station.png"))
    ctx.close()
    return posts


def test_admin(browser, base, user, pw, shots):
    posts, errors = [], []
    for width, height, tag in [(1366, 768, "desktop"), (412, 860, "mobil")]:
        ctx = make_context(browser, base, user, pw, width, height, posts)
        pg = ctx.new_page(); pg.on("pageerror", lambda e: errors.append(str(e)))
        pg.on("dialog", lambda d: d.dismiss())
        pg.goto(base + "/pilot/admin.html", wait_until="networkidle"); pg.wait_for_timeout(1200)
        for tab, sub in [("Speisekarte", "Speisen"), ("Stammdaten", "Tische"), ("Personal", "Rechnungsjournal")]:
            pg.locator(".nav-tab-btn", has_text=tab).click(); pg.wait_for_timeout(400)
            pg.locator(".sub-nav-btn", has_text=sub).first.click(); pg.wait_for_timeout(1500)
            info = pg.evaluate("""(() => { const box = document.querySelector('.fill-view-card .table-responsive');
                if (!box) return null; const th = box.querySelector('thead th');
                const before = th.getBoundingClientRect().top; box.scrollTop = 600;
                return { page: document.documentElement.scrollHeight <= innerHeight + 1, moved: th.getBoundingClientRect().top - before }; })()""")
            ok = bool(info) and info["page"] and abs(info["moved"]) < 1
            check(f"Admin {tag}: {sub} – Kopf fix, nur Liste scrollt", ok, str(info))
            check(f"Admin {tag}: {sub} – keine seitliche Scrollleiste", not page_overflow(pg))
        pg.locator(".nav-tab-btn", has_text="Betrieb").click(); pg.wait_for_timeout(2500)
        check(f"Admin {tag}: Betrieb lädt", pg.locator(".kpi-tile").count() > 0)
        if shots: pg.screenshot(path=os.path.join(shots, f"admin_{tag}.png"))
        ctx.close()
    check("Admin: keine JS-Fehler", not errors, "; ".join(errors)[:200])
    return posts


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--base", default=os.environ.get("FF_UI_BASE", "https://pos.ballner.ipv64.de"))
    ap.add_argument("--shots", help="Ordner für Screenshots (optional)")
    args = ap.parse_args()
    user, pw = os.environ.get("FF_UI_USER"), os.environ.get("FF_UI_PASS")
    if not user or not pw:
        sys.exit("Bitte FF_UI_USER und FF_UI_PASS setzen (Zugangsdaten nie in den Code schreiben).")
    if args.shots:
        os.makedirs(args.shots, exist_ok=True)
    base = args.base.rstrip("/")
    with sync_playwright() as p:
        br = p.chromium.launch()
        blocked = test_waiter(br, base, user, pw, args.shots)
        blocked += test_station(br, base, user, pw, args.shots)
        blocked += test_admin(br, base, user, pw, args.shots)
        br.close()
    failed = [r for r in RESULTS if not r[1]]
    print(f"\n{len(RESULTS) - len(failed)}/{len(RESULTS)} Prüfungen bestanden · {len(blocked)} Schreibaufrufe abgefangen (inkl. 1 Test-POST, nichts gespeichert)")
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
