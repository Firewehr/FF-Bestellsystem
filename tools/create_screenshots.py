#!/usr/bin/env python3
"""
tools/create_screenshots.py
Erstellt aktuelle, hochauflösende Screenshots des FeuerwehrBestellsystems
für Dokumentation und GitHub README.
"""

import os
import sys
import time
from playwright.sync_api import sync_playwright

BASE_URL = "https://pos.ballner.ipv64.de"
DOC_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "documentation"))
SCREENSHOTS_DIR = os.path.join(DOC_DIR, "screenshots")

os.makedirs(SCREENSHOTS_DIR, exist_ok=True)

def main():
    print("Starte Playwright Screenshot-Generierung...")
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        
        # 1. Mobil-Kontext (z. B. Smartphone für Kellner-Ansichten)
        mobile_ctx = browser.new_context(
            viewport={"width": 412, "height": 890},
            device_scale_factor=2,
            is_mobile=True,
            has_touch=True
        )
        
        # 2. Desktop-Kontext (z. B. für Admin und Stationsmonitor)
        desktop_ctx = browser.new_context(
            viewport={"width": 1366, "height": 850},
            device_scale_factor=1.5
        )
        
        # Mobil einloggen
        page_m = mobile_ctx.new_page()
        page_m.goto(f"{BASE_URL}/login.php")
        page_m.fill('input[name="f[username]"]', "ki")
        page_m.fill('input[name="f[password]"]', "ki12345")
        page_m.click('button[type="submit"]')
        page_m.wait_for_timeout(2000)
        
        # Desktop einloggen
        page_d = desktop_ctx.new_page()
        page_d.goto(f"{BASE_URL}/login.php")
        page_d.fill('input[name="f[username]"]', "ki")
        page_d.fill('input[name="f[password]"]', "ki12345")
        page_d.click('button[type="submit"]')
        page_d.wait_for_timeout(2000)
        
        # ---------------------------------------------------------
        # 1. Menue.png: Hauptmenü
        # ---------------------------------------------------------
        print("Erstelle Menue.png...")
        page_m.goto(f"{BASE_URL}/index.php#indexPage")
        page_m.wait_for_timeout(1500)
        menue_path = os.path.join(SCREENSHOTS_DIR, "Menue.png")
        page_m.screenshot(path=menue_path)
        print(f" -> {menue_path}")
        
        # ---------------------------------------------------------
        # 2. Uebersicht_Tische.png & Bestellsytem04.png: Tischübersicht
        # ---------------------------------------------------------
        print("Erstelle Uebersicht_Tische.png...")
        page_m.click("#TischeButton")
        page_m.wait_for_timeout(1000)
        # Auf 'Alle Tische' klicken, um das Raster mit allen Bereichen zu zeigen
        alle_tische_btn = page_m.locator('button:has-text("Alle Tische"), a:has-text("Alle Tische"), .btn:has-text("Alle Tische")').first
        if alle_tische_btn:
            alle_tische_btn.click()
            page_m.wait_for_timeout(1500)
        tische_path = os.path.join(SCREENSHOTS_DIR, "Uebersicht_Tische.png")
        bestell04_path = os.path.join(DOC_DIR, "Bestellsytem04.png")
        page_m.screenshot(path=tische_path)
        page_m.screenshot(path=bestell04_path)
        print(f" -> {tische_path}")
        print(f" -> {bestell04_path}")
        
        # ---------------------------------------------------------
        # 3. Tisch_Speisen.png: Tischansicht Speisen
        # ---------------------------------------------------------
        print("Erstelle Tisch_Speisen.png...")
        # Tisch H-A1 anklicken
        ha1_btn = page_m.locator('button:has-text("H-A1"), a:has-text("H-A1"), .btn:has-text("H-A1")').first
        if ha1_btn:
            ha1_btn.click()
            page_m.wait_for_timeout(1500)
        
        # Speisen-Tab aktivieren
        speisen_tab = page_m.locator('text=Speisen').first
        if speisen_tab:
            speisen_tab.click()
            page_m.wait_for_timeout(1500)
        speisen_path = os.path.join(SCREENSHOTS_DIR, "Tisch_Speisen.png")
        page_m.screenshot(path=speisen_path)
        print(f" -> {speisen_path}")
        
        # ---------------------------------------------------------
        # 4. Tisch_Getraenke.png & Bestellsytem03.png: Tischansicht Getränke
        # ---------------------------------------------------------
        print("Erstelle Tisch_Getraenke.png & Bestellsytem03.png...")
        getraenke_tab = page_m.locator('text=Getränke').first
        if getraenke_tab:
            getraenke_tab.click()
            page_m.wait_for_timeout(1500)
        getraenke_path = os.path.join(SCREENSHOTS_DIR, "Tisch_Getraenke.png")
        bestell03_path = os.path.join(DOC_DIR, "Bestellsytem03.png")
        page_m.screenshot(path=getraenke_path)
        page_m.screenshot(path=bestell03_path)
        print(f" -> {getraenke_path}")
        print(f" -> {bestell03_path}")
        
        # ---------------------------------------------------------
        # 5. Bestellsytem02.png: Bezahlansicht / Abrechnung
        # ---------------------------------------------------------
        print("Erstelle Bestellsytem02.png (Bezahlansicht Tisch H-B2)...")
        # Zurück zur Tischliste und Tisch H-B2 mit aktiven Positionen aufrufen
        page_m.goto(f"{BASE_URL}/index.php#listTische")
        page_m.wait_for_timeout(1000)
        alle_btn = page_m.locator('button:has-text("Alle Tische"), a:has-text("Alle Tische")').first
        if alle_btn:
            alle_btn.click()
            page_m.wait_for_timeout(1000)
        hb2_btn = page_m.locator('button:has-text("H-B2"), a:has-text("H-B2")').first
        if hb2_btn:
            hb2_btn.click()
            page_m.wait_for_timeout(1500)
            zahlen_btn = page_m.locator('text=Zahlen').first
            if zahlen_btn:
                zahlen_btn.click()
                page_m.wait_for_timeout(1500)
        bestell02_path = os.path.join(DOC_DIR, "Bestellsytem02.png")
        page_m.screenshot(path=bestell02_path)
        print(f" -> {bestell02_path}")
        
        # ---------------------------------------------------------
        # 6. Ansicht_Kueche.png: Stationsansicht Küche
        # ---------------------------------------------------------
        print("Erstelle Ansicht_Kueche.png...")
        page_d.goto(f"{BASE_URL}/list_kueche.php")
        page_d.wait_for_timeout(2500)
        kueche_path = os.path.join(SCREENSHOTS_DIR, "Ansicht_Kueche.png")
        page_d.screenshot(path=kueche_path)
        print(f" -> {kueche_path}")
        
        # ---------------------------------------------------------
        # 7. Bestellsytem01.png: Admin-Dashboard
        # ---------------------------------------------------------
        print("Erstelle Bestellsytem01.png (Admin-Dashboard)...")
        page_d.goto(f"{BASE_URL}/admin_page.php")
        page_d.wait_for_timeout(2500)
        bestell01_path = os.path.join(DOC_DIR, "Bestellsytem01.png")
        page_d.screenshot(path=bestell01_path)
        print(f" -> {bestell01_path}")
        
        browser.close()
        print("Alle Screenshots erfolgreich erstellt!")

if __name__ == "__main__":
    main()
