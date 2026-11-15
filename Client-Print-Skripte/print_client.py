#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
FeuerwehrBestellsystem - Print Client für Epson TM-T88 Thermodrucker

Dieses Skript:
1. Liest die Konfiguration aus config.ini
2. Pollt den Server nach neuen Bestellungen für das konfigurierte Print Target
3. Druckt die Bons auf dem konfigurierten Drucker
4. Registriert sich ohne Token selbst am Server (printer_register.php) und wartet,
   bis ein Admin den Druck-Client freischaltet (Kopplungscode in der Konsole).
5. Sendet regelmäßig einen Heartbeat an den Server (printer_heartbeat.php),
   damit der Dienst nicht als abgestürzt/aufgehängt gilt.
6. Unterstützt Windows (über Generic Text Only Drucker) und Linux (über /dev/usb/lpX)

Unterstützte Drucker: Epson TM-T88 Serie (TM-T88IV, TM-T88V, TM-T88VI)
"""

import argparse
import configparser
import datetime
import hashlib
import json
import os
import platform
import subprocess
import sys
import threading
import time
from pathlib import Path
from typing import Optional, Tuple

try:
    import requests
    from requests.adapters import HTTPAdapter
except ImportError:
    print("Fehler: 'requests' Modul nicht installiert.")
    print("Installiere mit: pip install requests")
    sys.exit(1)


# Eine wiederverwendete HTTP-Session spart pro Polling-Runde mehrere TCP/TLS-Handshakes
# (Keep-Alive). Bei HTTPS sind das ~100–200 ms pro vermiedenem Verbindungsaufbau.
_HTTP_SESSION: Optional["requests.Session"] = None


def get_http_session() -> "requests.Session":
    global _HTTP_SESSION
    if _HTTP_SESSION is None:
        s = requests.Session()
        adapter = HTTPAdapter(pool_connections=4, pool_maxsize=8, max_retries=0)
        s.mount("http://", adapter)
        s.mount("https://", adapter)
        _HTTP_SESSION = s
    return _HTTP_SESSION


# ESC/POS Befehle für Epson TM-T88
class ESC:
    INIT = b'\x1B\x40'                    # Drucker initialisieren
    CUT = b'\x1D\x56\x00\x0A'             # Papier schneiden
    FEED = b'\n\n\n\n\n'                  # Papiervorschub
    
    # Ausrichtung
    ALIGN_LEFT = b'\x1B\x61\x00'
    ALIGN_CENTER = b'\x1B\x61\x01'
    ALIGN_RIGHT = b'\x1B\x61\x02'
    
    # Textformatierung
    NORMAL = b'\x1B!\x00'
    BOLD = b'\x1B!\x08'
    EMPH_ON = b'\x1B\x45\x01'          # Hervorhebung (fett) an – z. B. für Zusatzinfos
    EMPH_OFF = b'\x1B\x45\x00'         # Hervorhebung aus
    DOUBLE_HEIGHT = b'\x1B!\x10'
    DOUBLE_WIDTH = b'\x1B!\x20'
    DOUBLE_SIZE = b'\x1B!\x30'            # Doppelte Höhe + Breite
    
    # Zeichensatz
    CHARSET_GERMANY = b'\x1B\x52\x02'     # Deutsche Umlaute
    CODEPAGE_LATIN1 = b'\x1B\x74\x00'     # CP437 (IBM)
    CODEPAGE_WPC1252 = b'\x1B\x74\x10'    # Windows-1252


def _config_int(config, section: str, key: str, default: int) -> int:
    raw = config.get(section, key, fallback='')
    raw = (raw or '').split('#')[0].split(';')[0].strip()
    try:
        return int(raw) if raw else default
    except ValueError:
        return default


def load_config(path: str = ''):
    """Lädt die Konfiguration aus config.ini (oder dem per --config angegebenen Pfad)"""
    config_path = Path(path) if path else Path(__file__).parent / 'config.ini'
    
    if not config_path.exists():
        print(f"Fehler: Konfigurationsdatei nicht gefunden: {config_path}")
        print("Bitte config.ini.example nach config.ini kopieren und anpassen.")
        sys.exit(1)
    
    config = configparser.ConfigParser()
    config.read(config_path, encoding='utf-8')

    return {
        'server_url': config.get('Server', 'url', fallback='').rstrip('/'),
        'token': config.get('Server', 'token', fallback=''),
        # Optional: 0/leer = Druckziel kommt zentral vom Server (Admin -> Druck-Clients).
        'print_target': _config_int(config, 'Server', 'print_target', 0),
        'poll_interval': config.getint('Server', 'poll_interval', fallback=3),
        # Pro Server-Runde: wie viele Einträge aus printer_jobs nacheinander holen (je Anfrage liefert der Server nur 1 Job).
        'max_printer_jobs_per_poll': config.getint('Server', 'max_printer_jobs_per_poll', fallback=25),
        'heartbeat_interval': config.getint('Server', 'heartbeat_interval', fallback=60),
        # Event-Modus (SSE): Server meldet "print-ready"; poll_interval gilt nur als Fallback, wenn der Event-Kanal nicht aktiv ist.
        'use_events': config.getboolean('Server', 'use_events', fallback=True),
        'event_fallback_interval': config.getint('Server', 'event_fallback_interval', fallback=30),
        'service_name': config.get('Server', 'service_name', fallback=''),  # leer = target_<id>
        # Name, unter dem der Client im Admin zur Freischaltung erscheint (leer = Rechnername).
        'device_name': config.get('Server', 'device_name', fallback=''),
        # Ablage des selbst registrierten Geräte-Tokens (relativ zum Skriptordner).
        'token_file': config.get('Server', 'token_file', fallback='device_token.txt'),

        'printer_name': config.get('Drucker', 'name', fallback=''),
        'printer_device': config.get('Drucker', 'device', fallback=''),
        'printer_devices': config.get('Drucker', 'devices', fallback=''),
        'temp_file': config.get('Drucker', 'temp_file', fallback=''),
        # Linux: automatische USB-Drucker-Suche (siehe find_linux_usb_printer()).
        'auto_detect_printer': config.getboolean('Drucker', 'auto_detect', fallback=True),
        'usb_vendor_ids': config.get('Drucker', 'usb_vendor_ids', fallback='04b8, 067b'),
        # Simulation: false | true | fail  (true = nichts drucken, Bon in Konsole + Datei;
        # fail = Druckfehler simulieren, um die Fehlerbehandlung zu testen)
        'simulate': (config.get('Drucker', 'simulate', fallback='false') or 'false').strip().lower(),
        'simulate_dir': config.get('Drucker', 'simulate_dir', fallback='simulated_prints'),

        # Name/Kopfzeile kommt zentral vom Server (Admin -> Druckziele -> Bon-Kopf & Fußzeile).
        # footer_text ist nur noch Fallback, falls im Admin keine Fußzeile gesetzt ist.
        'footer_text': config.get('Einstellungen', 'footer_text', fallback='Guten Appetit!'),

        'verify_ssl': config.getboolean('Server', 'verify_ssl', fallback=True),

        'verbose_timings': config.getboolean('Server', 'verbose_timings', fallback=False),
    }


def encode_text(text: str) -> bytes:
    """Konvertiert Text für den Drucker (Windows-1252 für Umlaute)"""
    try:
        return text.encode('cp1252', errors='replace')
    except:
        return text.encode('latin-1', errors='replace')


def format_bon_position_name(pos: dict) -> str:
    """
    Positionszeile: Name wie bisher, optional hinten „ - (Druckziel)“.
    Alte Formate ([Schank] … / (Schank) … vorne) werden normalisiert.
    """
    import re
    base = str(pos.get('kurz') or pos.get('name') or '').strip()
    station = str(pos.get('druckziel_suffix') or pos.get('druckziel_name') or '').strip()

    m = re.match(r'^\[([^\]]{1,40})\]\s+', base)
    if m:
        if not station:
            station = m.group(1).strip()
        base = base[m.end():].strip()
    m = re.match(r'^\(([^)]{1,40})\)\s+', base)
    if m:
        if not station:
            station = m.group(1).strip()
        base = base[m.end():].strip()
    m = re.search(r'\s+-\s+\(([^)]{1,40})\)$', base)
    if m:
        if not station:
            station = m.group(1).strip()
        base = base[:m.start()].strip()

    if station:
        return f'{base} - ({station})'
    return base


def wrap_bon_position_name(name: str, first_width: int, cont_width: int) -> list[str]:
    """
    Bricht den Positionsnamen um und versucht, den Suffix " - (Druckziel)"
    zusammen am Ende zu halten.
    """
    import re
    import textwrap

    s = str(name or '').strip()
    if not s:
        return ['']

    first_w = max(1, int(first_width))
    cont_w = max(1, int(cont_width))

    m = re.match(r'^(.*?)(\s-\s\([^)]{1,60}\))$', s)
    if not m:
        out = textwrap.wrap(
            s,
            width=first_w,
            initial_indent='',
            subsequent_indent='',
            break_long_words=True,
            break_on_hyphens=False,
        )
        return out if out else [s[:first_w]]

    base = m.group(1).strip()
    suffix = m.group(2)
    base_lines = textwrap.wrap(
        base,
        width=first_w,
        initial_indent='',
        subsequent_indent='',
        break_long_words=True,
        break_on_hyphens=False,
    )
    if not base_lines:
        base_lines = ['']

    for i in range(1, len(base_lines)):
        # Folgezeilen dürfen breiter sein als die erste.
        if len(base_lines[i]) > cont_w:
            base_lines[i:i + 1] = textwrap.wrap(
                base_lines[i],
                width=cont_w,
                initial_indent='',
                subsequent_indent='',
                break_long_words=True,
                break_on_hyphens=False,
            )

    last_idx = len(base_lines) - 1
    last_width = first_w if last_idx == 0 else cont_w
    if len(base_lines[last_idx]) + len(suffix) <= last_width:
        base_lines[last_idx] = base_lines[last_idx] + suffix
        return base_lines

    # Suffix als ganze Einheit in neue Zeile (nicht zerstückeln).
    suffix_line = suffix.strip()
    if len(suffix_line) <= cont_w:
        base_lines.append(suffix_line)
    else:
        base_lines.extend(
            textwrap.wrap(
                suffix_line,
                width=cont_w,
                initial_indent='',
                subsequent_indent='',
                break_long_words=True,
                break_on_hyphens=False,
            )
        )
    return base_lines


def bon_effective_header_footer(config: dict, server_data: Optional[dict] = None) -> Tuple[str, str]:
    """Kopf/Fuß aus dem Server-JSON (Admin). Der Name wird ausschließlich im Admin gepflegt;
    der Server liefert immer einen Wert (Bon-Kopfzeile, sonst Vereinsname)."""
    srv = server_data or {}
    h = str(srv.get('thermo_bon_header') or '').strip()
    f = str(srv.get('thermo_bon_footer') or '').strip()
    header = h
    footer = f if f else (config.get('footer_text') or '')
    return header, footer


def append_bon_header(data: bytearray, header_text: str) -> None:
    lines = [ln.strip() for ln in header_text.replace('\r\n', '\n').split('\n') if ln.strip()]
    if not lines and header_text.strip():
        lines = [header_text.strip()]
    if not lines:
        return
    data.extend(ESC.ALIGN_CENTER)
    for i, line in enumerate(lines):
        if i == 0:
            data.extend(ESC.DOUBLE_SIZE)
        else:
            data.extend(ESC.NORMAL)
        data.extend(encode_text(line + '\n'))
    data.extend(ESC.NORMAL)
    data.extend(b'\n')


def append_bon_footer(data: bytearray, footer_text: str) -> None:
    lines = [ln.strip() for ln in footer_text.replace('\r\n', '\n').split('\n') if ln.strip()]
    if not lines and footer_text.strip():
        lines = [footer_text.strip()]
    if not lines:
        return
    data.extend(b'\n')
    data.extend(ESC.ALIGN_CENTER)
    for line in lines:
        data.extend(encode_text(line + '\n'))
    data.extend(ESC.ALIGN_LEFT)


def format_time(timestamp_str: str) -> str:
    """Formatiert einen Zeitstempel als HH:MM"""
    if not timestamp_str or timestamp_str == '0000-00-00 00:00:00':
        return ''
    try:
        dt = datetime.datetime.strptime(timestamp_str, '%Y-%m-%d %H:%M:%S')
        return dt.strftime('%H:%M')
    except:
        return timestamp_str


def build_bon(tisch: dict, config: dict, server_data: Optional[dict] = None) -> bytes:
    """Erstellt die Druckdaten für einen Tisch-Bon"""
    data = bytearray()
    header_text, footer_text = bon_effective_header_footer(config, server_data)

    # Initialisierung
    data.extend(ESC.INIT)
    data.extend(ESC.CODEPAGE_WPC1252)
    data.extend(ESC.CHARSET_GERMANY)

    append_bon_header(data, header_text)
    
    tnr = int(tisch.get('tischnummer') or 0)
    abhol_id = str(tisch.get('abhol_bon_id') or '').strip()

    # Direktverkauf: gleiche Abholnummer wie auf dem Kunden-Bon (oben, groß)
    if tnr == 999999 and abhol_id:
        data.extend(ESC.ALIGN_CENTER)
        data.extend(ESC.NORMAL)
        data.extend(encode_text("ABHOLNUMMER\n"))
        data.extend(ESC.DOUBLE_SIZE)
        data.extend(encode_text(f"#{abhol_id}\n"))
        data.extend(ESC.NORMAL)
        data.extend(b'\n')
        data.extend(encode_text("Bitte an Küche/Schank mit\n"))
        data.extend(encode_text("dieser Nummer abholen!\n"))
        data.extend(b'\n')
        data.extend(ESC.ALIGN_LEFT)
        data.extend(encode_text(f"Direktverkauf\n"))
        data.extend(b'\n')
    else:
        # Tischname bzw. Direktverkauf ohne separate Abhol-ID (Legacy)
        data.extend(ESC.DOUBLE_SIZE)
        if tnr == 999999:
            data.extend(encode_text(f"{tisch.get('tischname') or 'Direktverkauf'}\n"))
        else:
            data.extend(encode_text(f"Tisch: {tisch.get('tischname') or ('Tisch ' + str(tnr))}\n"))
        data.extend(ESC.NORMAL)
        data.extend(b'\n')

    # Teillieferung (nur fertiger Teil einer noch offenen Bestellung)
    is_teil = bool(tisch.get('teillieferung')) or bool(str(tisch.get('teillieferung_label') or '').strip())
    if is_teil:
        label = str(tisch.get('teillieferung_label') or '').strip()
        if not label:
            order_nrs_tmp = tisch.get('order_nrs') or []
            order_txt_tmp = ', '.join(str(n) for n in order_nrs_tmp) if order_nrs_tmp else '-'
            label = f"Teillieferung zu Bestellung {order_txt_tmp}"
        data.extend(ESC.ALIGN_CENTER)
        data.extend(ESC.BOLD)
        data.extend(ESC.DOUBLE_WIDTH)
        data.extend(encode_text("TEILLIEFERUNG\n"))
        data.extend(ESC.NORMAL)
        data.extend(ESC.BOLD)
        data.extend(encode_text(f"{label}\n"))
        data.extend(ESC.NORMAL)
        data.extend(ESC.ALIGN_LEFT)
        data.extend(b'\n')
    
    # Bestell-Nr., ggf. Rechnungsnummer(n), Bon-Nr. (API: order_nrs, rechnungsnummern)
    data.extend(ESC.ALIGN_LEFT)
    data.extend(ESC.BOLD)
    order_nrs = tisch.get('order_nrs') or []
    order_txt = ', '.join(str(n) for n in order_nrs) if order_nrs else '-'
    data.extend(encode_text(f"Bestell-Nr.: {order_txt}\n"))
    rechnungsnummern = tisch.get('rechnungsnummern') or []
    if rechnungsnummern:
        data.extend(encode_text(f"Rechnung: {', '.join(str(x) for x in rechnungsnummern)}\n"))
    bon_nr = tisch.get('_bon_nr', 0)
    if bon_nr:
        if tnr == 999999 and abhol_id:
            data.extend(ESC.NORMAL)
            data.extend(encode_text(f"(Interne Bon-Nr. {bon_nr})\n"))
        else:
            data.extend(encode_text(f"Bon Nr. {bon_nr}\n"))
    data.extend(ESC.NORMAL)
    
    # Info
    data.extend(encode_text(f"Kellner: {tisch.get('kellner') or '-'}\n"))
    data.extend(encode_text(f"Bestellt: {format_time(tisch.get('bestellt_um') or '')}\n"))
    now = datetime.datetime.now()
    data.extend(encode_text(f"Datum (Beleg): {now.strftime('%d.%m.%Y')}\n"))
    data.extend(encode_text(f"Druckzeit: {now.strftime('%H:%M')} Uhr\n"))
    data.extend(b'\n')
    
    # Trennlinie
    data.extend(encode_text('-' * 42 + '\n'))
    
    # Positionen (ggf. bereits gruppiert: anzahl, einzelpreis, gesamtpreis)
    for pos in (tisch.get('positionen') or []):
        import textwrap
        name = format_bon_position_name(pos)
        anz = int(pos.get('anzahl') or 1)
        if anz < 1:
            anz = 1
        gesamt = float(pos.get('gesamtpreis', pos.get('betrag', 0)))
        einzel = pos.get('einzelpreis')
        if einzel is None:
            einzel = gesamt / anz if anz else gesamt
        else:
            einzel = float(einzel)

        # Name nicht hart abschneiden: auf mehrere Zeilen umbrechen.
        # Erste Zeile mit Menge + Preisen, Folgezeilen nur Name-Einrückung.
        first_prefix = f"{anz}x "
        cont_prefix = "   "
        first_width = max(1, 22 - len(first_prefix))
        wrapped = wrap_bon_position_name(name, first_width, 22)
        if not wrapped:
            wrapped = ['']
        first_label = f"{first_prefix}{wrapped[0]}"
        if len(first_label) > 22:
            # Fallback bei extrem langer 1. Silbe trotz Wrap
            first_label = first_label[:22]
        line = f"{first_label:<22} {einzel:>6.2f} {gesamt:>7.2f}\n"
        data.extend(encode_text(line))
        for part in wrapped[1:]:
            cont_label = f"{cont_prefix}{part}"
            if len(cont_label) > 22:
                cont_label = cont_label[:22]
            data.extend(encode_text(f"{cont_label}\n"))

        if pos.get('zusatzinfo'):
            zi = str(pos['zusatzinfo']).strip()
            if zi:
                # Lange Zusatzinfos umbrechen statt abschneiden (max. 38 Zeichen je Zeile,
                # erste Zeile mit "   -> ", Folgezeilen mit gleicher Einrückung).
                prefix = "   -> "
                cont = "      "
                wrapped = textwrap.wrap(
                    zi,
                    width=38,
                    initial_indent='',
                    subsequent_indent='',
                    break_long_words=True,
                    break_on_hyphens=False,
                )
                if not wrapped:
                    wrapped = [zi]
                # Fett, damit Sonderwünsche (z. B. „Radler mit Almdudler“) nicht übersehen werden
                data.extend(ESC.EMPH_ON)
                for i, part in enumerate(wrapped):
                    data.extend(encode_text(f"{prefix if i == 0 else cont}{part}\n"))
                data.extend(ESC.EMPH_OFF)
    
    # Trennlinie
    data.extend(encode_text('-' * 42 + '\n'))
    
    # Summe
    total = sum(
        float(p.get('gesamtpreis', p.get('betrag', 0)))
        for p in (tisch.get('positionen') or [])
    )
    data.extend(ESC.DOUBLE_WIDTH)
    if is_teil:
        data.extend(encode_text(f"TEILBETRAG: {total:.2f} EUR\n"))
    else:
        data.extend(encode_text(f"SUMME: {total:.2f} EUR\n"))
    data.extend(ESC.NORMAL)
    
    append_bon_footer(data, footer_text)
    
    # Papiervorschub und Schneiden
    data.extend(ESC.FEED)
    data.extend(ESC.CUT)
    
    return bytes(data)


def print_windows(data: bytes, config: dict) -> bool:
    """Druckt unter Windows über freigegebenen Drucker (Generic Text Only)."""
    printer_name = (config.get('printer_name') or '').strip() or 'Receipt Printer'
    temp_file = config['temp_file'] or r'C:\POS-Daten\printPOS.txt'
    temp_path = Path(temp_file)
    temp_path.parent.mkdir(parents=True, exist_ok=True)
    with open(temp_file, 'wb') as f:
        f.write(data)
    computer_name = os.environ.get('COMPUTERNAME', 'localhost')
    cmd = f'print /D:"\\\\{computer_name}\\{printer_name}" "{temp_file}"'
    try:
        result = subprocess.run(cmd, shell=True, capture_output=True, timeout=30)
        return result.returncode == 0
    except subprocess.TimeoutExpired:
        return False


def list_usb_lp_candidates() -> list:
    """Listet alle /dev/usb/lpX-Devices mit USB-Vendor/Product/Produktname (sofern über sysfs lesbar).

    /dev/usb/lpX wird vom Kernel-Treiber 'usblp' erzeugt, der NUR an USB-Geräte der
    Interface-Klasse "Printer" (0x07) bindet. Reine USB-Seriell-Adapter (FTDI, CH340,
    Prolific-Seriell, ...) landen dagegen als /dev/ttyUSB*/ttyACM* - sie tauchen hier
    also gar nicht erst auf. Ein USB<->Parallel-Adapter (z.B. Prolific PL2305, wie er
    an manchen Epson TM-T88 mit Parallel-Port hängt) meldet sich beim Kernel jedoch
    selbst als Printer-Klasse und erscheint deshalb hier - mit der Vendor-ID des
    Adapters (z.B. 067b), NICHT der von Epson (04b8). Deshalb reicht eine reine
    Vendor-ID-Prüfung nicht aus, um "das ist sicher ein Epson" zu erkennen - sie dient
    hier nur als Tie-Breaker, wenn mehrere Kandidaten gefunden werden.
    """
    candidates = []
    base = Path('/dev/usb')
    if not base.is_dir():
        return candidates
    for node in sorted(base.glob('lp*')):
        vendor = product = name = ''
        for sys_path in (Path(f'/sys/class/usbmisc/{node.name}/device'),
                         Path(f'/sys/class/usb/{node.name}/device')):
            try:
                d = sys_path.resolve()
            except OSError:
                continue
            if not d.exists():
                continue
            # bis zu 6 Verzeichnisebenen hoch zum eigentlichen usb-device mit idVendor/idProduct
            for _ in range(6):
                if (d / 'idVendor').exists():
                    try:
                        vendor = (d / 'idVendor').read_text().strip().lower()
                        product = (d / 'idProduct').read_text().strip().lower()
                        if (d / 'product').exists():
                            name = (d / 'product').read_text().strip()
                    except OSError:
                        pass
                    break
                if d.parent == d:
                    break
                d = d.parent
            break
        candidates.append({'device': str(node), 'vendor': vendor, 'product': product, 'name': name})
    return candidates


def find_linux_usb_printer(vendor_ids: list) -> Optional[str]:
    """Sucht automatisch nach einem angeschlossenen USB-Thermodrucker unter Linux.

    Gibt None zurück, wenn kein /dev/usb/lpX-Device gefunden wurde (dann wird auf die
    manuell konfigurierten Devices zurückgefallen).
    """
    candidates = list_usb_lp_candidates()
    if not candidates:
        return None
    if len(candidates) == 1:
        return candidates[0]['device']

    # Mehrere Kandidaten: zuerst Name (Epson/TM-...), dann bekannte Vendor-ID bevorzugen.
    def name_match(c):
        n = c['name'].lower()
        return 'epson' in n or 'tm-' in n

    for c in candidates:
        if name_match(c):
            print(f"[auto] Mehrere USB-Drucker-Devices gefunden, wähle nach Produktname: {c['device']} ({c['name']})")
            return c['device']
    for c in candidates:
        if c['vendor'] in vendor_ids:
            print(f"[auto] Mehrere USB-Drucker-Devices gefunden, wähle nach Vendor-ID: {c['device']} (vendor={c['vendor']})")
            return c['device']

    print(f"[auto] Mehrere USB-Drucker-Devices gefunden ({', '.join(c['device'] for c in candidates)}), "
          f"verwende das erste: {candidates[0]['device']}")
    return candidates[0]['device']


def resolve_linux_printer_device(config: dict) -> str:
    """Ermittelt das zu verwendende Linux-Drucker-Device (auto-detect oder manuell aus config.ini)."""
    manual_list = [d.strip() for d in (config.get('printer_devices') or '').split(',') if d.strip()]
    manual_single = (config.get('printer_device') or '').strip()

    if config.get('auto_detect_printer', True):
        vendor_ids = [(v.strip().lower()[2:] if v.strip().lower().startswith('0x') else v.strip().lower())
                      for v in (config.get('usb_vendor_ids') or '').split(',') if v.strip()]
        found = find_linux_usb_printer(vendor_ids)
        if found:
            return found
        print("[auto] Keine USB-Drucker automatisch gefunden, verwende manuell konfiguriertes Device.")

    for d in manual_list:
        if os.path.exists(d):
            return d
    return manual_single or '/dev/usb/lp0'


def print_linux(data: bytes, config: dict) -> bool:
    """Druckt unter Linux über USB-Device (mit optionaler automatischer Drucker-Suche)."""
    device = resolve_linux_printer_device(config)
    if not os.path.exists(device):
        print(f"Fehler: Drucker-Device nicht gefunden: {device}")
        return False
    try:
        with open(device, 'wb') as f:
            f.write(data)
        return True
    except PermissionError:
        print(f"Fehler: Keine Berechtigung für {device}")
        return False
    except OSError as e:
        print(f"Druckfehler ({device}): {e}")
        return False


def build_rechnung_thermo_text(text: str, config: dict) -> bytes:
    """ESC/POS: vorformatierter Rechnungstext (42-Zeichen-Zeilen vom Server)."""
    data = bytearray()
    data.extend(ESC.INIT)
    data.extend(ESC.CODEPAGE_WPC1252)
    data.extend(ESC.CHARSET_GERMANY)
    data.extend(ESC.ALIGN_LEFT)
    data.extend(ESC.NORMAL)
    for line in text.replace('\r\n', '\n').split('\n'):
        data.extend(encode_text(line + '\n'))
    data.extend(ESC.FEED)
    data.extend(ESC.CUT)
    return bytes(data)


# ---------------------------------------------------------------------------
# Druck-Simulation (ohne Drucker testen)
# ---------------------------------------------------------------------------
_SIM_COUNTER = 0


def simulate_mode(config: dict) -> str:
    """'' (aus), 'ok' (Druck simulieren) oder 'fail' (Druckfehler simulieren)."""
    v = str(config.get('simulate') or '').strip().lower()
    if v in ('1', 'true', 'yes', 'ja', 'on', 'ok'):
        return 'ok'
    if v in ('fail', 'error', 'fehler'):
        return 'fail'
    return ''


def escpos_to_text(data: bytes) -> str:
    """Entfernt ESC/POS-Steuerbefehle und liefert den lesbaren Bon-Text."""
    out = bytearray()
    i, n = 0, len(data)
    while i < n:
        b = data[i]
        if b == 0x1B and i + 1 < n:                       # ESC
            c = data[i + 1]
            if c == 0x40:                                  # ESC @  (Init)
                i += 2
            elif c == 0x45 and i + 2 < n:                  # ESC E n (Hervorhebung) → **…** in der Simulation
                if data[i + 2] and data[i + 2] != 0x30:       # an: Markierung vor dem Text
                    out.extend(b'**')
                elif out.endswith(b'\n'):                      # aus: Markierung vor den Zeilenumbruch
                    out[-1:] = b'**\n'
                else:
                    out.extend(b'**')
                i += 3
            elif c in b'a!RtdE-JMVG':                      # ESC x n
                i += 3
            else:
                i += 2
            continue
        if b == 0x1D and i + 1 < n:                       # GS
            c = data[i + 1]
            if c == 0x56:                                  # GS V m [n]  (Schnitt)
                m = data[i + 2] if i + 2 < n else 0
                i += 4 if m in (65, 66) else 3
                out.extend(b'\n' + b'-' * 15 + b' >8 Schnitt ' + b'-' * 15 + b'\n')
            else:
                i += 3
            continue
        if b in (0x0C, 0x0D, 0x00):
            i += 1
            continue
        out.append(b)
        i += 1
    try:
        text = out.decode('cp1252', errors='replace')
    except LookupError:
        text = out.decode('latin-1', errors='replace')
    # Mehrfache Leerzeilen (Papiervorschub) zusammenfassen
    while '\n\n\n' in text:
        text = text.replace('\n\n\n', '\n\n')
    return text.strip('\n')


def simulate_print(data: bytes, config: dict) -> bool:
    """Simuliert den Druck: Bon in der Konsole anzeigen und als .txt/.bin ablegen."""
    global _SIM_COUNTER
    mode = simulate_mode(config)
    _SIM_COUNTER += 1
    text = escpos_to_text(data)
    width = 48
    print("     +" + "-" * width + "+  [SIMULATION]")
    for line in text.split('\n'):
        print("     |" + line[:width].ljust(width) + "|")
    print("     +" + "-" * width + "+")

    target_dir = Path(config.get('simulate_dir') or 'simulated_prints')
    if not target_dir.is_absolute():
        target_dir = Path(__file__).parent / target_dir
    try:
        target_dir.mkdir(parents=True, exist_ok=True)
        stamp = datetime.datetime.now().strftime('%Y%m%d_%H%M%S')
        base = target_dir / f"bon_{stamp}_{_SIM_COUNTER:04d}"
        base.with_suffix('.bin').write_bytes(data)                  # Roh-ESC/POS (z. B. für späteren Echtdruck)
        base.with_suffix('.txt').write_text(text + '\n', encoding='utf-8')
        print(f"     [Simulation] gespeichert: {base.name}.txt / .bin")
    except OSError as e:
        print(f"     [Simulation] Datei konnte nicht gespeichert werden: {e}")

    if mode == 'fail':
        print("     [Simulation] Druckfehler simuliert (simulate = fail)")
        return False
    return True


def do_print(data: bytes, config: dict) -> bool:
    """Druckt die Daten je nach Betriebssystem (oder simuliert den Druck)."""
    if simulate_mode(config):
        return simulate_print(data, config)
    system = platform.system()
    
    if system == 'Windows':
        return print_windows(data, config)
    elif system == 'Linux':
        return print_linux(data, config)
    else:
        print(f"Betriebssystem nicht unterstützt: {system}")
        return False


# ---------------------------------------------------------------------------
# Geräte-Registrierung & Freischaltung
# ---------------------------------------------------------------------------
AUTH_CODES = ('printer_pending', 'printer_blocked', 'printer_unauthorized', 'printer_no_target')


def host_name() -> str:
    return os.environ.get('COMPUTERNAME', '') or os.environ.get('HOSTNAME', '') or platform.node() or 'localhost'


def pair_code(token: str) -> str:
    """Kopplungscode – identisch zur Anzeige im Admin (SHA-256, erste 6 Zeichen)."""
    return hashlib.sha256(token.encode('utf-8')).hexdigest()[:6].upper()


def token_file_path(config: dict) -> Path:
    p = Path(config.get('token_file') or 'device_token.txt')
    return p if p.is_absolute() else Path(__file__).parent / p


def auth_error_code(response) -> str:
    """Liefert printer_pending/printer_blocked/printer_unauthorized bei 401/403, sonst ''."""
    if response.status_code not in (401, 403, 409):
        return ''
    try:
        code = str((response.json() or {}).get('code') or '')
    except ValueError:
        code = ''
    return code if code in AUTH_CODES else 'printer_unauthorized'


def register_device(config: dict) -> Optional[str]:
    """Registriert diesen Druck-Client am Server und speichert den erhaltenen Token."""
    url = config['server_url'] + '/printer_register.php'
    payload = {
        'name': config.get('device_name') or host_name(),
        'host': host_name(),
        'service': config.get('service_name') or (f"target_{config['print_target']}" if config['print_target'] else ''),
        'print_target': config['print_target'] or '',
        'client': f"print_client.py ({platform.system()})",
    }
    try:
        r = get_http_session().post(url, data=payload, timeout=15, verify=config.get('verify_ssl', True))
        data = r.json()
    except (requests.exceptions.RequestException, ValueError) as e:
        print(f"  [Registrierung] Server nicht erreichbar: {e}")
        return None
    if not data.get('ok') or not data.get('token'):
        print(f"  [Registrierung] abgelehnt: {data.get('error') or r.status_code}")
        return None
    token = str(data['token'])
    path = token_file_path(config)
    try:
        path.write_text(token + '\n', encoding='utf-8')
        try:
            os.chmod(path, 0o600)
        except OSError:
            pass
    except OSError as e:
        print(f"  [Registrierung] WARNUNG: Token konnte nicht gespeichert werden ({path}): {e}")
    print(f"  [Registrierung] Neuer Druck-Client angelegt (Gerät #{data.get('device_id')}).")
    return token


def load_or_register_token(config: dict) -> Optional[str]:
    """Token aus config.ini, sonst aus token_file, sonst neu registrieren."""
    if config.get('token'):
        return config['token']
    path = token_file_path(config)
    if path.exists():
        t = path.read_text(encoding='utf-8').strip()
        if t:
            return t
    return register_device(config)


def wait_for_approval(config: dict) -> None:
    """
    Blockiert, bis der Server diesen Client akzeptiert. Registriert sich bei Bedarf
    (neu) und zeigt den Kopplungscode für den Abgleich im Admin an.
    """
    from_config = bool(config.get('_token_from_config'))
    last_msg = ''
    while True:
        token = config.get('token') or load_or_register_token(config)
        if not token:
            time.sleep(30)
            continue
        config['token'] = token
        try:
            r = get_http_session().get(
                config['server_url'] + '/printer_register.php',
                params={'token': token}, timeout=15, verify=config.get('verify_ssl', True),
            )
            st = r.json() or {}
            status = str(st.get('status') or '')
        except (requests.exceptions.RequestException, ValueError) as e:
            print(f"  [Freischaltung] Server nicht erreichbar ({e}) - neuer Versuch in 15s")
            time.sleep(15)
            continue

        if status == 'approved':
            apply_server_target(config, st.get('print_target'))
            if config.get('print_target', 0) > 0:
                if last_msg:
                    print("  [Freischaltung] Druck-Client ist freigeschaltet - starte Druckbetrieb.")
                return
            msg = ("  [Freischaltung] Freigeschaltet, aber noch KEIN Druckziel zugewiesen. "
                   "Bitte im Admin unter Druck-Clients auswählen.")
            wait_s = 10
        elif status == 'pending':
            msg = (f"  [Freischaltung] Wartet auf Freischaltung im Admin "
                   f"(Stammdaten -> Druckziele -> Druck-Clients). Kopplungscode: {pair_code(token)}")
            wait_s = 10
        elif status == 'blocked':
            msg = "  [Freischaltung] Dieser Druck-Client ist im Admin GESPERRT."
            wait_s = 60
        else:
            if from_config:
                msg = "  [Freischaltung] Token aus config.ini wird vom Server nicht akzeptiert. Bitte prüfen."
                wait_s = 60
            else:
                print("  [Freischaltung] Gerät dem Server unbekannt (im Admin entfernt?) - registriere neu.")
                try:
                    token_file_path(config).unlink()
                except OSError:
                    pass
                config['token'] = ''
                time.sleep(30)
                continue
        if msg != last_msg:
            print(msg)
            last_msg = msg
        time.sleep(wait_s)


def apply_server_target(config: dict, server_target) -> None:
    """Übernimmt das im Admin zugewiesene Druckziel (zentrale Konfiguration)."""
    try:
        target = int(server_target or 0)
    except (TypeError, ValueError):
        target = 0
    if target > 0 and target != config.get('print_target'):
        old = config.get('print_target') or '-'
        config['print_target'] = target
        print(f"  [Konfiguration] Druckziel vom Server: {target} (vorher {old})")


def send_heartbeat(config: dict) -> bool:
    """Sendet Heartbeat an den Server (Dienst läuft noch)."""
    base = config['server_url']
    url = base + '/printer_heartbeat.php'
    params = {
        'service': config.get('service_name') or f"target_{config['print_target']}",
        'host': host_name(),
    }
    if config.get('token'):
        params['token'] = config['token']
    try:
        r = get_http_session().get(url, params=params, timeout=10, verify=config.get('verify_ssl', True))
        return r.status_code == 200 and r.text.strip().lower() == 'ok'
    except Exception:
        return False


def heartbeat_loop(config: dict, stop_event: threading.Event):
    """Hintergrund-Thread: sendet in festem Abstand einen Heartbeat."""
    interval = max(15, config.get('heartbeat_interval', 60))
    while not stop_event.wait(interval):
        if send_heartbeat(config):
            pass  # optional: nur bei Fehler loggen
        else:
            print(f"  [Heartbeat] Server nicht erreichbar (nächster Versuch in {interval}s)")


def fetch_kellner_bon_job(config: dict) -> dict:
    """Holt den nächsten manuell eingereihten Kellner-Bon-Job (printer_jobs)."""
    url = config['server_url'] + '/print_target_job_next.php'
    params = {'print_target': config['print_target']}
    if config['token']:
        params['token'] = config['token']
    try:
        t0 = time.time()
        response = get_http_session().get(url, params=params, timeout=15, verify=config.get('verify_ssl', True))
        dt_ms = int((time.time() - t0) * 1000)
        code = auth_error_code(response)
        if code:
            return {'ok': False, 'error': code, 'auth_code': code}
        response.raise_for_status()
        data = response.json()
        data['_http_ms'] = dt_ms
        return data
    except requests.exceptions.RequestException as e:
        return {'ok': False, 'error': str(e)}
    except json.JSONDecodeError:
        return {'ok': False, 'error': 'Invalid JSON (job_next)'}


def kellner_bon_job_done(config: dict, job_id: int, ok: bool, err_msg: str = '') -> None:
    """Markiert Kellner-Bon-Job als erledigt oder fehlerhaft."""
    url = config['server_url'] + '/print_target_job_done.php'
    data = {
        'job_id': str(job_id),
        'status': 'done' if ok else 'error',
    }
    if not ok and err_msg:
        data['error'] = err_msg[:2000]
    if config['token']:
        data['token'] = config['token']
    try:
        get_http_session().post(url, data=data, timeout=15, verify=config.get('verify_ssl', True))
    except requests.exceptions.RequestException:
        pass


def process_one_printer_job_from_server(config: dict) -> bool:
    """
    Holt genau einen Job von print_target_job_next.php, druckt, meldet done.
    Rückgabe True, wenn ein Job verarbeitet wurde; False, wenn die Queue leer war oder ein Abruf-Fehler.
    """
    job = fetch_kellner_bon_job(config)
    if job.get('auth_code'):
        config['_auth_lost'] = job['auth_code']
        return False
    if job.get('error') and not job.get('ok'):
        print(f"  Job-Queue: {job.get('error', 'Fehler')}")
        return False
    if not (job.get('ok') and job.get('job_id')):
        if config.get('verbose_timings'):
            _http_ms = job.get('_http_ms')
            _srv = job.get('server_timings') or {}
            if _http_ms is not None and _http_ms > 250:
                srv_total = _srv.get('total_ms') if _srv else None
                srv_q = _srv.get('queue_query_ms') if _srv else None
                print(f"  [job-queue leer] http={_http_ms}ms server={srv_total}ms (queue_query={srv_q}ms)")
        return False

    jid = int(job['job_id'])
    jtype = job.get('job_type') or 'kellner_bon'
    if jtype == 'rechnung_thermo':
        if not job.get('text'):
            print(f"  Rechnung-Thermo-Job #{jid}: leerer Text")
            kellner_bon_job_done(config, jid, False, 'Leerer Thermotext')
            return True
        print(f"  Rechnung-Thermo-Job #{jid}")
        ok_one = do_print(build_rechnung_thermo_text(job['text'], config), config)
        if ok_one:
            print("     OK - gedruckt")
        else:
            print("     FEHLER beim Drucken!")
        kellner_bon_job_done(config, jid, ok_one, '' if ok_one else 'Druck fehlgeschlagen')
        return True

    if job.get('count', 0) > 0 and job.get('tische'):
        job_bon_nr = job.get('bon_nr', 0)
        print(f"  Kellner-Bon-Job #{jid} (Bon Nr. {job_bon_nr}, {job['count']} Position(en))")
        ok_all = True
        for tisch in job.get('tische', []):
            # Neuere Server liefern pro Tisch eine eigene Bon-Nr.
            tisch['_bon_nr'] = tisch.get('bon_nr') or job_bon_nr
            tname = tisch.get('tischname', '?')
            npos = len(tisch.get('positionen', []))
            print(f"  -> Drucke Tisch: {tname} ({npos} Pos., Bon Nr. {tisch['_bon_nr']})")
            try:
                bon_data = build_bon(tisch, config, job)
            except Exception as e:
                # Fehlerhafter Payload darf den Job nicht dauerhaft "reserved" hängen lassen
                ok_all = False
                print(f"     FEHLER beim Erzeugen des Bons: {e}")
                continue
            if do_print(bon_data, config):
                print("     OK - gedruckt")
            else:
                ok_all = False
                print("     FEHLER beim Drucken!")
            time.sleep(0.5)
        kellner_bon_job_done(config, jid, ok_all, '' if ok_all else 'Druck fehlgeschlagen')
        return True

    print(f"  Job-Queue: ungültiger Job-Payload (#{jid})")
    kellner_bon_job_done(config, jid, False, 'Ungültiger Job-Payload')
    return True


def print_events_loop(config: dict, wake: threading.Event, active: threading.Event, stop: threading.Event) -> None:
    """
    Hält eine SSE-Verbindung zu print_events.php offen. Bei "print-ready" wird wake gesetzt.
    active ist nur gesetzt, solange der Server den Event-Kanal anbietet (Admin: "Druck per Event").
    """
    url = config['server_url'] + '/print_events.php'
    last_id = ''
    session = requests.Session()
    while not stop.is_set():
        wait_s = 5
        conn_target = config['print_target']
        params = {'print_target': conn_target}
        if config['token']:
            params['token'] = config['token']
        try:
            headers = {'Accept': 'text/event-stream'}
            if last_id:
                headers['Last-Event-ID'] = last_id
            with session.get(url, params=params, headers=headers, stream=True, timeout=(10, 45),
                             verify=config.get('verify_ssl', True)) as resp:
                ctype = resp.headers.get('Content-Type', '')
                if resp.status_code not in (200, 401, 403):
                    raise RuntimeError(f"HTTP {resp.status_code}")
                if 'text/event-stream' not in ctype:
                    raise RuntimeError(f"unerwarteter Content-Type: {ctype}")
                event_name = 'message'
                for raw in resp.iter_lines(decode_unicode=True):
                    if stop.is_set():
                        break
                    if config['print_target'] != conn_target:
                        # Druckziel wurde im Admin umgestellt -> Event-Kanal neu verbinden
                        last_id = ''
                        wait_s = 0
                        break
                    if raw is None:
                        continue
                    line = raw.strip()
                    if line.startswith('retry:'):
                        try:
                            wait_s = max(1, int(line[6:].strip()) // 1000)
                        except ValueError:
                            pass
                    elif line.startswith('id:'):
                        last_id = line[3:].strip()
                    elif line.startswith('event:'):
                        event_name = line[6:].strip()
                    elif line == '':
                        if event_name == 'print-ready':
                            if not active.is_set():
                                print("  [Event] Event-Kanal aktiv")
                            active.set()
                            wake.set()
                        elif event_name in ('print-disabled', 'print-denied', 'print-pending'):
                            if active.is_set():
                                print(f"  [Event] {event_name}: Fallback auf Polling")
                            active.clear()
                        event_name = 'message'
                    elif line.startswith(':'):
                        if not active.is_set() and resp.status_code == 200:
                            print("  [Event] Event-Kanal aktiv")
                            active.set()
                if resp.status_code == 200 and not active.is_set():
                    # Verbindung stand, aber nur retry/disabled: Server-Retry abwarten.
                    pass
        except Exception as e:
            if active.is_set():
                print(f"  [Event] Verbindung unterbrochen ({e}) - Polling als Fallback")
            active.clear()
            wait_s = 5
        if wait_s > 0:
            stop.wait(wait_s)


def fetch_orders(config: dict) -> dict:
    """Ruft neue Bestellungen vom Server ab"""
    url = config['server_url'] + '/print_target.php'
    params = {
        'print_target': config['print_target']
    }
    if config.get('preview'):
        # Server markiert die Bestellungen dann NICHT als gedruckt (und vergibt keine Bon-Nr.)
        params['preview'] = '1'
    if config['token']:
        params['token'] = config['token']
    
    try:
        t0 = time.time()
        response = get_http_session().get(url, params=params, timeout=15, verify=config.get('verify_ssl', True))
        dt_ms = int((time.time() - t0) * 1000)
        code = auth_error_code(response)
        if code:
            return {'error': code, 'auth_code': code}
        response.raise_for_status()
        data = response.json()
        data['_http_ms'] = dt_ms
        return data
    except requests.exceptions.RequestException as e:
        print(f"Server-Fehler: {e}")
        return {'error': str(e)}
    except json.JSONDecodeError:
        print("Ungültige Server-Antwort")
        return {'error': 'Invalid JSON'}


def main():
    parser = argparse.ArgumentParser(description='FeuerwehrBestellsystem Print Client')
    parser.add_argument('--test', action='store_true', help='Testmodus (einmal abrufen und beenden)')
    parser.add_argument('--preview', action='store_true', help='Preview-Modus (nicht als gedruckt markieren)')
    parser.add_argument('--verbose', action='store_true', help='Zeitmessungen pro Poll-Runde ausgeben (Fehlersuche)')
    parser.add_argument('--simulate', action='store_true',
                        help='Nichts drucken: Bons in der Konsole anzeigen und in simulated_prints/ speichern '
                             '(Ablauf inkl. Rückmeldung an den Server wie im Echtbetrieb)')
    parser.add_argument('--simulate-fail', action='store_true',
                        help='Wie --simulate, meldet aber jeden Druck als fehlgeschlagen (Fehlerbehandlung testen)')
    parser.add_argument('--config', default='', help='Alternativer Pfad zur config.ini')
    args = parser.parse_args()
    
    print("=" * 50)
    print("FeuerwehrBestellsystem - Print Client")
    print("=" * 50)
    
    config = load_config(args.config)
    if args.simulate_fail:
        config['simulate'] = 'fail'
    elif args.simulate:
        config['simulate'] = 'true'
    config['preview'] = bool(args.preview)

    # Verbose-Timings: über CLI-Flag --verbose oder config.ini (Server.verbose_timings=true) aktivierbar.
    # Bleibt standardmäßig aus, damit das normale Log schlank bleibt.
    if args.verbose:
        config['verbose_timings'] = True
    verbose = bool(config.get('verbose_timings', False))
    if verbose:
        print("[verbose] Timing-Ausgabe aktiv (--verbose oder verbose_timings=true)")

    if not config.get('verify_ssl', True):
        try:
            import urllib3
            urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
        except ImportError:
            pass
        print("Hinweis: SSL-Zertifikatsprüfung ist AUS (verify_ssl=false in config.ini).")

    if not config['server_url']:
        print("Fehler: Server-URL nicht konfiguriert!")
        sys.exit(1)

    print(f"Server: {config['server_url']}")
    print(f"Print Target: {config['print_target'] or 'zentral vom Server (Admin)'}")
    print(f"Poll-Intervall: {config['poll_interval']}s")
    print(f"Jobs pro Runde (printer_jobs): bis {config.get('max_printer_jobs_per_poll', 25)}")
    hb = config.get('heartbeat_interval', 60)
    print(f"Heartbeat: {'alle ' + str(hb) + 's' if hb > 0 else 'aus'}")
    print(f"Betriebssystem: {platform.system()}")
    if simulate_mode(config):
        print("Drucker: SIMULATION – es wird nichts gedruckt"
              + (" (jeder Druck meldet FEHLER)" if simulate_mode(config) == 'fail' else ""))
        print(f"          Bons landen in der Konsole und in '{config.get('simulate_dir')}/'")
    elif platform.system() == 'Windows':
        pn = (config.get('printer_name') or '').strip() or 'Receipt Printer'
        print(f"Drucker: {pn}")
    elif platform.system() == 'Linux':
        if config.get('auto_detect_printer', True):
            print("Drucker-Suche: automatisch (auto_detect=true in config.ini)")
        print(f"Drucker-Device: {resolve_linux_printer_device(config)}")
    print("-" * 50)

    # Geräte-Token: config.ini > device_token.txt > Selbstregistrierung. Gedruckt wird erst nach Freischaltung.
    config['_token_from_config'] = bool(config.get('token'))
    wait_for_approval(config)

    # Heartbeat im Hintergrund (damit der Dienst auch bei langem Druck/Abruf als „lebendig“ gilt)
    heartbeat_stop = threading.Event()
    if config.get('heartbeat_interval', 60) > 0:
        heartbeat_thread = threading.Thread(target=heartbeat_loop, args=(config, heartbeat_stop), daemon=True)
        heartbeat_thread.start()
    else:
        heartbeat_stop = None

    wake_event = threading.Event()
    events_active = threading.Event()
    events_stop = threading.Event()
    use_events = bool(config.get('use_events', True)) and not args.test and not args.preview
    if use_events:
        threading.Thread(
            target=print_events_loop, args=(config, wake_event, events_active, events_stop), daemon=True
        ).start()
        print(f"Event-Modus: aktiv, sobald im Admin freigeschaltet (Fallback-Poll alle {config['event_fallback_interval']}s)")

    while True:
        try:
            now = datetime.datetime.now().strftime('%H:%M:%S')
            t_round = time.time()
            print(f"[{now}] Prüfe auf neue Bestellungen...")

            jobs_this_round = 0
            t_jobs_start = time.time()
            if not args.preview:
                # Der Server liefert pro HTTP-Anfrage nur einen printer_jobs-Eintrag (LIMIT 1).
                # Hier werden mehrere Jobs nacheinander abgeholt, bis die Queue leer ist oder das Limit erreicht ist.
                max_q = max(1, config.get('max_printer_jobs_per_poll', 25))
                while jobs_this_round < max_q:
                    if not process_one_printer_job_from_server(config):
                        break
                    jobs_this_round += 1
                # Hinweis: Im Test-Modus wird danach auch print_target.php abgefragt
                # (früher endete --test hier und hat Bestellungen nie abgerufen).
            jobs_dt_ms = int((time.time() - t_jobs_start) * 1000)
            if verbose and jobs_this_round > 0:
                print(f"  [queue] {jobs_this_round} Job(s) verarbeitet in {jobs_dt_ms}ms")

            data = {} if config.get('_auth_lost') else fetch_orders(config)
            if data.get('auth_code'):
                config['_auth_lost'] = data['auth_code']
            if data.get('print_target'):
                apply_server_target(config, data.get('print_target'))
            if config.get('_auth_lost'):
                print(f"  Zugriff verweigert ({config.pop('_auth_lost')}) - warte auf Freischaltung...")
                wait_for_approval(config)
                continue
            if verbose:
                _http_ms = data.get('_http_ms')
                _srv = data.get('server_timings') or {}
                if _http_ms is not None or _srv:
                    http_part = f"http={_http_ms}ms" if _http_ms is not None else ""
                    srv_part = ""
                    if _srv:
                        srv_total = _srv.get('total_ms')
                        srv_part = (
                            f" server={srv_total}ms "
                            f"(boot={_srv.get('bootstrap_ms', 0)}ms, "
                            f"schema={_srv.get('schema_ms', 0)}ms, "
                            f"query={_srv.get('main_query_ms', 0)}ms, "
                            f"filter={_srv.get('sibling_filter_ms', 0)}ms)"
                        )
                    print(f"  [timing] {http_part}{srv_part}")
            
            if data.get('error'):
                print(f"  Fehler: {data['error']}")
            elif data.get('count', 0) > 0:
                print(f"  {data['count']} Bestellung(en) gefunden")
                
                auto_bon_nr = data.get('bon_nr', 0)
                for tisch in data.get('tische', []):
                    # Neuere Server liefern pro Tisch eine eigene Bon-Nr.
                    tisch['_bon_nr'] = tisch.get('bon_nr') or auto_bon_nr
                    print(f"  -> Drucke Tisch: {tisch.get('tischname', '?')} ({len(tisch.get('positionen') or [])} Pos., Bon Nr. {tisch['_bon_nr']})")
                    
                    bon_data = build_bon(tisch, config, data)
                    
                    if do_print(bon_data, config):
                        print(f"     OK - gedruckt")
                    else:
                        print(f"     FEHLER beim Drucken!")
                    
                    time.sleep(0.5)  # Kurze Pause zwischen Bons
            else:
                print(f"  Keine neuen Bestellungen")

            if verbose:
                round_dt_ms = int((time.time() - t_round) * 1000)
                print(f"  [poll-runde] gesamt={round_dt_ms}ms (jobs={jobs_this_round}, jobs_zeit={jobs_dt_ms}ms)")

            if args.test:
                print("\n[Test-Modus] Beende.")
                break

            if use_events and events_active.is_set():
                wake_event.wait(max(1, config['event_fallback_interval']))
                wake_event.clear()
            else:
                time.sleep(config['poll_interval'])

        except KeyboardInterrupt:
            print("\n\nBeendet durch Benutzer.")
            events_stop.set()
            if heartbeat_stop:
                heartbeat_stop.set()
            break
        except Exception as e:
            print(f"Unerwarteter Fehler: {e}")
            time.sleep(10)


if __name__ == '__main__':
    main()
