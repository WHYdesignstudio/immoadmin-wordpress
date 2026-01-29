# ImmoAdmin WordPress Plugin

Synchronisiert Immobilien-Daten von [ImmoAdmin](https://immoadmin.at) und stellt sie als Custom Post Types in WordPress bereit.

## Features

- **Automatischer Sync** - Empfängt Daten direkt von ImmoAdmin via Webhook
- **Custom Post Type** - Erstellt `immoadmin_unit` Posts für jede Einheit
- **Sichere Verbindung** - Token-basierte Authentifizierung mit HMAC-Signatur
- **Auto-Updates** - Aktualisiert sich automatisch über GitHub Releases
- **Saubere Deinstallation** - Entfernt alle Daten beim Löschen

## Installation

1. Plugin ZIP herunterladen
2. WordPress Admin → Plugins → Installieren → Plugin hochladen
3. Plugin aktivieren
4. Token von ImmoAdmin kopieren und einfügen

## Setup

1. In **ImmoAdmin**: Projekt öffnen → Sync-Einstellungen
2. **WordPress URL** eingeben
3. **Token** kopieren
4. In **WordPress**: ImmoAdmin → Token einfügen
5. In **ImmoAdmin**: "Jetzt synchronisieren" klicken

## Datenstruktur

Das Plugin erstellt den Custom Post Type `immoadmin_unit` mit folgenden Meta-Feldern:

### Basis
- `_immoadmin_id` - Eindeutige ID aus ImmoAdmin
- `status` - available, reserved, sold
- `object_type` - flat, house, plot, etc.

### Lage
- `street`, `house_number`, `postal_code`, `city`
- `floor`, `door_number`, `staircase`
- `latitude`, `longitude`

### Flächen (m²)
- `living_area`, `usable_area`, `total_area`
- `balcony_area`, `terrace_area`, `garden_area`

### Preise (€)
- `purchase_price`, `rent_cold`, `rent_warm`
- `operating_costs`, `deposit`

### Räume
- `room_count`, `bedrooms`, `bathrooms`

### Energie
- `hwb`, `hwb_class`, `fgee`, `fgee_class`

## Webhook Endpoints

- `POST /wp-json/immoadmin/v1/sync` - Sync-Daten empfangen
- `POST /wp-json/immoadmin/v1/verify` - Verbindung prüfen
- `GET /wp-json/immoadmin/v1/status` - Status abfragen
- `GET /wp-json/immoadmin/v1/debug` - Debug-Infos

## Sicherheit

- Token wird als SHA-256 Hash gespeichert (nicht plain text)
- Requests werden mit HMAC-SHA256 signiert
- Timestamp-Validierung verhindert Replay-Attacken
- HTTPS erforderlich

## Updates

Das Plugin prüft automatisch auf Updates von GitHub. Bei neuen Releases erscheint die Update-Benachrichtigung im WordPress Admin.

## Deinstallation

Bei Löschen des Plugins werden **alle Daten entfernt**:
- Alle `immoadmin_unit` Posts
- Alle Plugin-Options
- JSON-Dateien und Media-Ordner

Bei bloßem Deaktivieren bleiben die Daten erhalten.

## Entwicklung

```bash
# Repo klonen
git clone https://github.com/WHYdesignstudio/immoadmin-wordpress.git

# In WordPress plugins Ordner verlinken
ln -s /path/to/immoadmin-wp-plugin /path/to/wordpress/wp-content/plugins/
```

## Support

Bei Fragen: [support@immoadmin.at](mailto:support@immoadmin.at)

## Lizenz

Proprietär - © WHY Agency
