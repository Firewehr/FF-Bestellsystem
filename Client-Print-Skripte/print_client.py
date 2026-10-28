#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
FeuerwehrBestellsystem - Print Client für Epson TM-T88 Thermodrucker

Dieses Skript:
1. Liest die Konfiguration aus config.ini
2. Pollt den Server nach neuen Bestellungen für das konfigurierte Print Target
3. Druckt die Bons auf dem konfigurierten Drucker
4. Sendet regelmäßig einen Heartbeat an den Server (printer_heartbeat.php),
   damit der Dienst nicht als abgestürzt/aufgehängt gilt.
5. Unterstützt Windows (über Generic Text Only Drucker) und Linux (über /dev/usb/lpX)

Unterstützte Drucker: Epson TM-T88 Serie (TM-T88IV, TM-T88V, TM-T88VI)
"""

import argparse
import configparser
import datetime
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
    DOUBLE_HEIGHT = b'\x1B!\x10'
    DOUBLE_WIDTH = b'\x1B!\x20'
    DOUBLE_SIZE = b'\x1B!\x30'            # Doppelte Höhe + Breite
    
    # Zeichensatz
    CHARSET_GERMANY = b'\x1B\x52\x02'     # Deutsche Umlaute
    CODEPAGE_LATIN1 = b'\x1B\x74\x00'     # CP437 (IBM)
    CODEPAGE_WPC1252 = b'\x1B\x74\x10'    # Windows-1252


def load_config():
    """Lädt die Konfiguration aus config.ini"""
    config_path = Path(__file__).parent / 'config.ini'
    
    if not config_path.exists():
        print(f"Fehler: Konfigurationsdatei nicht gefunden: {config_path}")
        print("Bitte config.ini.example nach config.ini kopieren und anpassen.")
        sys.exit(1)
    
    config = configparser.ConfigParser()
    config.read(config_path, encoding='utf-8')

    return {
        'server_url': config.get('Server', 'url', fallback='').rstrip('/'),
        'token': config.get('Server', 'token', fallback=''),
        'print_target': config.getint('Server', 'print_target', fallback=11),
        'poll_interval': config.getint('Server', 'poll_interval', fallback=3),
        # Pro Server-Runde: wie viele Einträge aus printer_jobs nacheinander holen (je Anfrage liefert der Server nur 1 Job).
        'max_printer_jobs_per_poll': config.getint('Server', 'max_printer_jobs_per_poll', fallback=25),
        'heartbeat_interval': config.getint('Server', 'heartbeat_interval', fallback=60),
        # Event-Modus (SSE): Server meldet "print-ready"; poll_interval gilt nur als Fallback, wenn der Event-Kanal nicht aktiv ist.
        'use_events': config.getboolean('Server', 'use_events', fallback=True),
        'event_fallback_interval': config.getint('Server', 'event_fallback_interval', fallback=30),
        'service_name': config.get('Server', 'service_name', fallback=''),  # leer = target_<id>

        'printer_name': config.get('Drucker', 'name', fallback=''),
        'printer_device': config.get('Drucker', 'device', fallback=''),
        'printer_devices': config.get('Drucker', 'devices', fallback=''),
        'temp_file': config.get('Drucker', 'temp_file', fallback=''),
        # Linux: automatische USB-Drucker-Suche (siehe find_linux_usb_printer()).
        'auto_detect_printer': config.getboolean('Drucker', 'auto_detect', fallback=True),
        'usb_vendor_ids': config.get('Drucker', 'usb_vendor_ids', fallback='04b8, 067b'),

        'ff_name': config.get('Einstellungen', 'ff_name', fallback='Freiwillige Feuerwehr'),
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
    """Kopf/Fuß: aus Server-JSON (Admin), sonst Fallback config.ini ff_name / footer_text."""
    srv = server_data or {}
    h = str(srv.get('thermo_bon_header') or '').strip()
    f = str(srv.get('thermo_bon_footer') or '').strip()
    header = h if h else (config.get('ff_name') or '')
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
            data.extend(encode_text(f"Tisch: {tisch['tischname']}\n"))
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
    data.extend(encode_text(f"Kellner: {tisch['kellner']}\n"))
    data.extend(encode_text(f"Bestellt: {format_time(tisch['bestellt_um'])}\n"))
    now = datetime.datetime.now()
    data.extend(encode_text(f"Datum (Beleg): {now.strftime('%d.%m.%Y')}\n"))
    data.extend(encode_text(f"Druckzeit: {now.strftime('%H:%M')} Uhr\n"))
    data.extend(b'\n')
    
    # Trennlinie
    data.extend(encode_text('-' * 42 + '\n'))
    
    # Positionen (ggf. bereits gruppiert: anzahl, einzelpreis, gesamtpreis)
    for pos in tisch['positionen']:
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
                for i, part in enumerate(wrapped):
                    data.extend(encode_text(f"{prefix if i == 0 else cont}{part}\n"))
    
    # Trennlinie
    data.extend(encode_text('-' * 42 + '\n'))
    
    # Summe
    total = sum(
        float(p.get('gesamtpreis', p.get('betrag', 0)))
        for p in tisch['positionen']
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


def do_print(data: bytes, config: dict) -> bool:
    """Druckt die Daten je nach Betriebssystem"""
    system = platform.system()
    
    if system == 'Windows':
        return print_windows(data, config)
    elif system == 'Linux':
        return print_linux(data, config)
    else:
        print(f"Betriebssystem nicht unterstützt: {system}")
        return False


def send_heartbeat(config: dict) -> bool:
    """Sendet Heartbeat an den Server (Dienst läuft noch)."""
    base = config['server_url']
    url = base + '/printer_heartbeat.php'
    params = {
        'service': config.get('service_name') or f"target_{config['print_target']}",
        'host': os.environ.get('COMPUTERNAME', '') or os.environ.get('HOSTNAME', 'localhost'),
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
            tisch['_bon_nr'] = job_bon_nr
            tname = tisch.get('tischname', '?')
            npos = len(tisch.get('positionen', []))
            print(f"  -> Drucke Tisch: {tname} ({npos} Pos.)")
            bon_data = build_bon(tisch, config, job)
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
    params = {'print_target': config['print_target']}
    if config['token']:
        params['token'] = config['token']
    last_id = ''
    session = requests.Session()
    while not stop.is_set():
        wait_s = 5
        try:
            headers = {'Accept': 'text/event-stream'}
            if last_id:
                headers['Last-Event-ID'] = last_id
            with session.get(url, params=params, headers=headers, stream=True, timeout=(10, 45),
                             verify=config.get('verify_ssl', True)) as resp:
                ctype = resp.headers.get('Content-Type', '')
                if resp.status_code != 200 and resp.status_code != 401:
                    raise RuntimeError(f"HTTP {resp.status_code}")
                if 'text/event-stream' not in ctype:
                    raise RuntimeError(f"unerwarteter Content-Type: {ctype}")
                event_name = 'message'
                for raw in resp.iter_lines(decode_unicode=True):
                    if stop.is_set():
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
                        elif event_name in ('print-disabled', 'print-denied'):
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
        stop.wait(wait_s)


def fetch_orders(config: dict) -> dict:
    """Ruft neue Bestellungen vom Server ab"""
    url = config['server_url'] + '/print_target.php'
    params = {
        'print_target': config['print_target']
    }
    if config['token']:
        params['token'] = config['token']
    
    try:
        t0 = time.time()
        response = get_http_session().get(url, params=params, timeout=15, verify=config.get('verify_ssl', True))
        dt_ms = int((time.time() - t0) * 1000)
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
    args = parser.parse_args()
    
    print("=" * 50)
    print("FeuerwehrBestellsystem - Print Client")
    print("=" * 50)
    
    config = load_config()

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
    print(f"Print Target: {config['print_target']}")
    print(f"Poll-Intervall: {config['poll_interval']}s")
    print(f"Jobs pro Runde (printer_jobs): bis {config.get('max_printer_jobs_per_poll', 25)}")
    hb = config.get('heartbeat_interval', 60)
    print(f"Heartbeat: {'alle ' + str(hb) + 's' if hb > 0 else 'aus'}")
    print(f"Betriebssystem: {platform.system()}")
    if platform.system() == 'Windows':
        pn = (config.get('printer_name') or '').strip() or 'Receipt Printer'
        print(f"Drucker: {pn}")
    elif platform.system() == 'Linux':
        if config.get('auto_detect_printer', True):
            print("Drucker-Suche: automatisch (auto_detect=true in config.ini)")
        print(f"Drucker-Device: {resolve_linux_printer_device(config)}")
    print("-" * 50)

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
                    if args.test:
                        print("\n[Test-Modus] Beende.")
                        break
                if args.test:
                    break
            jobs_dt_ms = int((time.time() - t_jobs_start) * 1000)
            if verbose and jobs_this_round > 0:
                print(f"  [queue] {jobs_this_round} Job(s) verarbeitet in {jobs_dt_ms}ms")

            data = fetch_orders(config)
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
                    tisch['_bon_nr'] = auto_bon_nr
                    print(f"  -> Drucke Tisch: {tisch['tischname']} ({len(tisch['positionen'])} Pos., Bon Nr. {auto_bon_nr})")
                    
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
