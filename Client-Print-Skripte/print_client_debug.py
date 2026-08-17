#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Print-Client mit Zeitmessungen (Fehlersuche).

Dasselbe Verhalten wie print_client.py, aber immer mit --verbose:
  - HTTP-Latenz zum Server (print_target.php, Job-Queue)
  - Server-Timings aus print_target.php (server_timings)
  - Dauer pro Poll-Runde

Drucken selbst: ein Drucker aus config.ini [Drucker] name = … (wie Normalbetrieb).

Normalbetrieb: start_print_client.bat  oder  python print_client.py
Debug:        start_print_client_debug.bat  oder  python print_client_debug.py

Weitere Optionen werden durchgereicht, z. B. --test für einen einzelnen Lauf.
"""
from __future__ import annotations

import sys
from pathlib import Path

_DIR = Path(__file__).resolve().parent
if str(_DIR) not in sys.path:
    sys.path.insert(0, str(_DIR))


def main() -> None:
    if '--verbose' not in sys.argv and '-v' not in sys.argv:
        sys.argv.append('--verbose')
    print("=" * 50)
    print("DEBUG-Modus: Server-/Poll-Zeitmessungen (--verbose)")
    print("Drucker: wie print_client.py → config.ini [Drucker] name = …")
    print("=" * 50)
    import print_client

    print_client.main()


if __name__ == '__main__':
    main()
