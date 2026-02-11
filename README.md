# ImmoAdmin WordPress Plugin

Synchronisiert Immobilien-Daten von [ImmoAdmin](https://immoadmin.at) und stellt sie als Custom Post Types in WordPress bereit.

## Features

- Automatischer Sync via Webhook
- Custom Post Type `immoadmin_wohnung` mit deutschen Meta-Feld-Beschreibungen
- Token-basierte Authentifizierung mit Signaturprüfung
- Auto-Updates über GitHub Releases
- Saubere Deinstallation

## Installation

1. Plugin ZIP herunterladen
2. WordPress Admin → Plugins → Installieren → Plugin hochladen
3. Plugin aktivieren
4. Token von ImmoAdmin kopieren und einfügen

## Setup

1. In **ImmoAdmin**: Projekt öffnen → Sync-Einstellungen
2. **WordPress URL** eingeben und **Token** kopieren
3. In **WordPress**: ImmoAdmin → Token einfügen
4. In **ImmoAdmin**: "Jetzt synchronisieren" klicken

## Deinstallation

Bei Löschen des Plugins werden alle Daten entfernt (Posts, Options, Dateien).
Bei bloßem Deaktivieren bleiben die Daten erhalten.

## Lizenz

Proprietär - © WHY Agency
