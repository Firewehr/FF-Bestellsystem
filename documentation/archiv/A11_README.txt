A11 – Rechnungsdruck: Layout + Retry
-----------------------------------

Neu:
- rechnung_print.php reserviert jetzt Jobs (druck_status='reserved')
- rechnung_print.php liefert zusaetzlich ein Feld 'text' (fertiger Drucktext, 42 Zeichen Breite)
- rechnung_print_done.php akzeptiert status=done|error und reserved_by
  * done  -> druck_status='done', gedruckt=1
  * error -> druck_attempts++, druck_last_error setzen, und entweder pending (Retry) oder error (Max Attempts)
- Windows Script Client-Print-Skripte/Windows/print_rechnung_win.py nutzt job['text'] falls vorhanden
  und sendet status/error + reserved_by

DB Patch:
- documentation/patch_a11_rechnung_retry_layout.sql ausfuehren

Einstellung:
- settings PRINTER_MAX_ATTEMPTS (Default 5)

Hinweis:
- Falls ein Druckdienst abstuerzt, bleiben reservierte Jobs ggf. haengen.
  Du kannst in der DB druck_status auf 'pending' zuruecksetzen.
