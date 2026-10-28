#!/usr/bin/env bash
set -e

# Wechsel ins Projektverzeichnis
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
cd "$DIR"

# Prüfe deploy.config.json
if [ ! -f "deploy.config.json" ]; then
    echo -e "\033[93m⚠ Hinweis: 'deploy.config.json' existiert noch nicht!\033[0m"
    echo "Erstelle eine Konfigurationsdatei aus der Vorlage:"
    echo "  cp deploy.config.example.json deploy.config.json"
    echo "Passe dort die FTP-Server und GitHub-Daten an."
    echo ""
    read -p "Möchtest du die Vorlage jetzt als 'deploy.config.json' anlegen? (j/N): " choice
    case "$choice" in
        j|J|ja|Ja|y|Y )
            cp deploy.config.example.json deploy.config.json
            chmod 600 deploy.config.json
            echo -e "\033[92m✔ 'deploy.config.json' wurde angelegt (Berechtigung 600).\033[0m"
            echo "Bitte editiere die Datei mit deinen Serverdaten (z. B. nano deploy.config.json)."
            exit 0
            ;;
        * )
            exit 1
            ;;
    esac
fi

python3 tools/deploy.py "$@"
