#!/usr/bin/env python3
# Druckt offene Rechnungen (Firmenrechnung/Beleg) auf Epson TM-T88 ueber "Generic Text Only".
# Liest offene Jobs vom Server: rechnung_print.php
# Bestaetigt nach Druck: rechnung_print_done.php

import json
import os
import os.path
import sys
import time
import datetime

import requests

# === ANPASSEN ===
SAVE_PATH = r"C:\POS-Daten"
PRINT_FILE = os.path.join(SAVE_PATH, "printPOS.txt")
BAT_FILE = r"C:\POS-Daten\printi.bat"
SERVER_BASE = "http://.../"  # z.B. https://dein-webspace.tld/FeuerwehrBestellsystem-master/
PRINTER_TOKEN = ""           # leer = Selbstregistrierung; Freischaltung im Admin (Druckziele -> Druck-Clients)

import argparse

def _parse_args():
    ap = argparse.ArgumentParser()
    ap.add_argument('--server', dest='server', default=SERVER_BASE)
    ap.add_argument('--token', dest='token', default=PRINTER_TOKEN)
    return ap.parse_args()

_args = _parse_args()
SERVER_BASE = _args.server
PRINTER_TOKEN = _args.token


def esc(s: str) -> str:
    return s

def write_center(f, text):
    f.write("\x1B\x61\x01")
    f.write(text + "\n")
    f.write("\x1B\x61\x00")

def hr(f):
    f.write("------------------------------------------\n")

def cut(f):
    f.write("\n\n\n\n\n\x0D\x0c")
    f.write("\x1D\x56\x00\x0A")

def format_money(v):
    return ("%.2f" % float(v)).replace('.', ',')

def build_invoice_text(job):
    # If server already provides ready-to-print text, use it (cleaner columns).
    if job.get('text'):
        return str(job.get('text')) + "\n\n\n\x0D\x0c" + "\x1D\x56\x00\x0A"

    lines = []
    seller = job.get('seller', {})
    emp = job.get('empfaenger', {})

    # Center header
    lines.append("\x1B\x61\x01")
    lines.append("\x1B!\x10")  # double height
    lines.append("\x1B!\x20")  # double width
    if seller.get('name'):
        lines.append(seller['name'] + "\n")
    lines.append("\x1B!\x00")
    if seller.get('address'):
        for ln in str(seller['address']).splitlines():
            lines.append(ln + "\n")
    if seller.get('uid'):
        lines.append("UID: " + seller['uid'] + "\n")
    lines.append("\x1B\x61\x00")
    lines.append("\n")

    # Invoice meta
    lines.append("Rechnung: " + str(job.get('rechnungsnummer','')) + "\n")
    lines.append("Datum: " + str(job.get('created_at','')) + "\n")
    hr_text = "------------------------------------------\n"
    lines.append(hr_text)

    if int(job.get('is_firma',0)) == 1:
        lines.append("Empfaenger:\n")
        if emp.get('name'): lines.append(str(emp.get('name')) + "\n")
        if emp.get('strasse'): lines.append(str(emp.get('strasse')) + "\n")
        plz = (emp.get('plz') or '').strip(); ort = (emp.get('ort') or '').strip()
        if plz or ort:
            lines.append((plz + " " + ort).strip() + "\n")
        if emp.get('uid'): lines.append("UID: " + str(emp.get('uid')) + "\n")
        lines.append(hr_text)

    # Items
    lines.append("Anz  Artikel                      Summe\n")
    lines.append(hr_text)
    total = 0.0
    for it in job.get('items', []):
        cnt = int(it.get('cnt',1))
        name = str(it.get('name',''))
        betrag = float(it.get('betrag',0.0))
        line_sum = cnt * betrag
        total += line_sum

        # simple width handling
        name_short = (name[:26] + '…') if len(name) > 27 else name
        left = f"{cnt:>3}  {name_short:<27}"
        right = f"{format_money(line_sum):>8}"
        lines.append(left + right + "\n")

    lines.append(hr_text)
    lines.append("\x1B!\x20")  # double width
    lines.append("TOTAL: " + format_money(total) + " EUR\n")
    lines.append("\x1B!\x00")
    cut(lines)
    return ''.join(lines)

def cut(lines_list):
    lines_list.append("\n\n\n\n\n\x0D\x0c")
    lines_list.append("\x1D\x56\x00\x0A")

TOKEN_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'device_token_rechnung.txt')


def _pair_code(token):
    import hashlib
    return hashlib.sha256(token.encode('utf-8')).hexdigest()[:6].upper()


def ensure_approved():
    """Token laden/registrieren und warten, bis der Admin den Client freischaltet."""
    global PRINTER_TOKEN
    base = SERVER_BASE.rstrip('/')
    shown = ''
    while True:
        try:
            if not PRINTER_TOKEN and os.path.exists(TOKEN_FILE):
                with open(TOKEN_FILE, encoding='utf-8') as fh:
                    PRINTER_TOKEN = fh.read().strip()
            if not PRINTER_TOKEN:
                host = os.environ.get('COMPUTERNAME', 'Windows')
                r = requests.post(base + '/printer_register.php', data={
                    'name': host + ' (Rechnung)', 'host': host, 'service': 'rechnung',
                    'client': 'print_rechnung_win.py',
                }, timeout=15).json()
                if not r.get('ok'):
                    print("Registrierung abgelehnt:", r.get('error'))
                    time.sleep(60)
                    continue
                PRINTER_TOKEN = r['token']
                with open(TOKEN_FILE, 'w', encoding='utf-8') as fh:
                    fh.write(PRINTER_TOKEN)
            st = requests.get(base + '/printer_register.php', params={'token': PRINTER_TOKEN}, timeout=15).json()
            status = st.get('status')
            if status == 'approved':
                return
            if status == 'unknown' and os.path.exists(TOKEN_FILE):
                os.remove(TOKEN_FILE)
                PRINTER_TOKEN = ''
                continue
            msg = ("Wartet auf Freischaltung im Admin, Kopplungscode: " + _pair_code(PRINTER_TOKEN)) \
                if status == 'pending' else ("Druck-Client nicht akzeptiert (" + str(status) + ")")
            if msg != shown:
                print(msg)
                shown = msg
            time.sleep(10)
        except Exception as e:
            print("Freischaltung pruefen fehlgeschlagen:", e)
            time.sleep(15)


def main():
    ensure_approved()
    if not os.path.isdir(SAVE_PATH):
        print("Ordner fehlt:", SAVE_PATH)
        sys.exit(1)

    while True:
        try:
            print("Rechnungen abrufen", datetime.datetime.now().time())
            url = SERVER_BASE.rstrip('/') + '/rechnung_print.php'
            params = {}
            if PRINTER_TOKEN:
                params['token'] = PRINTER_TOKEN
            resp = requests.get(url, params=params, timeout=15)
            if resp.status_code in (401, 403):
                print("Zugriff verweigert - pruefe Freischaltung...")
                ensure_approved()
                continue
            jobs = resp.json()
            if isinstance(jobs, dict) and jobs.get('error'):
                print("Server-Fehler:", jobs.get('error'))
                time.sleep(10)
                continue

            if not jobs:
                time.sleep(2)
                continue

            for job in jobs:
                with open(PRINT_FILE, 'w', encoding='latin-1', errors='ignore') as f:
                    txt = build_invoice_text(job)
                    # Write raw ESC/POS bytes via latin-1
                    f.write(txt)

                rc = os.system(BAT_FILE)

                # Mark printed / retry
                done_url = SERVER_BASE.rstrip('/') + '/rechnung_print_done.php'
                data = {
                    'id': job.get('id', 0),
                    'token': PRINTER_TOKEN,
                    'reserved_by': job.get('reserved_by','')
                }
                if rc == 0:
                    data['status'] = 'done'
                else:
                    data['status'] = 'error'
                    data['error'] = 'printi.bat return code ' + str(rc)
                try:
                    requests.post(done_url, data=data, timeout=15)
                except Exception as e:
                    print("Konnte Druck-ACK nicht senden:", e)
                time.sleep(1)

            time.sleep(1)
        except Exception as e:
            print("Fehler:", e)
            time.sleep(10)

if __name__ == '__main__':
    main()
