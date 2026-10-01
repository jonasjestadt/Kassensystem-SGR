# SG Rönshausen – Theke

Internes Tool für den Thekendienst: Getränke und Speisen antippen, Gesamtpreis sehen, Rückgeld berechnen.
Der Vorstand pflegt Artikel und Preise, verwaltet die Zugänge und sieht eine Auswertung für den Einkauf.

> Kein Kassensystem im steuerlichen Sinne – nur eine Rechenhilfe für die Helfer am Stand.

## Funktionen

| Rolle        | Kann                                                                                   |
|--------------|----------------------------------------------------------------------------------------|
| **Kasse**    | Artikel antippen → Summe, „Kassieren“ mit Rückgeldrechner                              |
| **Vorstand** | alles von Kasse + Artikel/Preise/Symbole pflegen, Benutzer verwalten, Auswertung, CSV  |

- Optimiert für Smartphone und Tablet, Hell/Dunkel-Schalter oben rechts
- Eigene Artikel-Symbole (u. a. Apfelwein im Gerippten, Bembel, Getränkeflaschen) in `public/assets/icons/`
- Reihenfolge der Artikel per Drag & Drop
- Rückgeldrechner mit Vorschlägen (aufgerundet auf 5/10/20/50/100 €)
- Wenn das WLAN kurz weg ist, werden Bestellungen auf dem Gerät zwischengespeichert und später nachgesendet
- Angemeldete Geräte bleiben 30 Tage eingeloggt
- Als App installierbar (Android/Chrome, iOS „Zum Home-Bildschirm“) – benötigt HTTPS

## Technik

- PHP ≥ 8.1 mit `pdo_sqlite` (bei praktisch jedem Webhoster vorhanden)
- SQLite-Datenbank – keine MySQL-Einrichtung nötig
- Keine externen Bibliotheken, kein Build-Schritt

```
app/        Logik (Datenbank, Login, Layout) – nicht öffentlich
data/       Datenbank + Sessions – nicht öffentlich, muss beschreibbar sein
public/     Webroot der Subdomain
dev/        Lokaler Testserver (wird auf dem Server nicht gebraucht)
```

## Installation auf dem Server

1. Die Ordner `app/`, `data/` und `public/` hochladen, z. B. nach `/theke/` (`dev/`, `package*.json` und `node_modules/` werden nicht gebraucht).
2. Beim Hoster eine Subdomain anlegen, z. B. `theke.sg-roenshausen.de`, und als
   **Zielverzeichnis `/theke/public`** einstellen. Damit sind Datenbank und Code von außen nicht erreichbar.
3. SSL-Zertifikat (Let's Encrypt) für die Subdomain aktivieren.
4. Sicherstellen, dass `data/` vom Webserver beschreibbar ist (meist automatisch, sonst Rechte 750/770).
5. Subdomain im Browser öffnen → **Ersteinrichtung**: Passwörter für `vorstand`, `kasse1`, `kasse2` festlegen.
6. Unter **Artikel** die Preise prüfen (Startpreise sind Platzhalter).

Tipp für die Theke: Seite auf dem Tablet/Handy öffnen und „Zum Home-Bildschirm hinzufügen“ – dann startet sie wie eine App.

### Falls die Subdomain nicht auf `public/` zeigen kann

Unter Apache schützen die `.htaccess`-Dateien in `app/` und `data/` die Ordner.
Unter **nginx** (eigener Server) zusätzlich in der Server-Konfiguration ergänzen:

```nginx
location ~ ^/(app|data)/ { deny all; }
```

## Betrieb auf dem Strato-VPS

Server: Ubuntu 24.04, nginx, PHP 8.3-FPM, SQLite. App liegt unter `/var/www/theke-sgr`
(`app/`, `public/` = Webroot, `data/` = Datenbank, nur für `www-data`).

- **Update einspielen:** Änderungen committen, dann `./deploy.sh` – überträgt den
  committeten Stand von `app/` und `public/`, die Datenbank bleibt unberührt.
- **Sicherheit:** Firewall (ufw) nur SSH/HTTP/HTTPS, SSH nur per Schlüssel,
  fail2ban, automatische Sicherheitsupdates.
- **nginx-Konfiguration:** `/etc/nginx/sites-available/theke-sgr`
- **Backups:** täglich 3:30 Uhr nach `/var/backups/theke-sgr/` (30 Tage), Skript `/usr/local/bin/theke-sgr-backup`.
  Wiederherstellen: Datei entpacken und als `/var/www/theke-sgr/data/kasse.sqlite` (Besitzer `www-data`) zurückkopieren.

## Datensicherung

Alle Daten stecken in `data/kasse.sqlite`. Diese Datei regelmäßig herunterladen genügt als Backup.

## Lokal ausprobieren

Ohne installiertes PHP – PHP läuft als WebAssembly in Node.js:

```bash
npm install
npm start
```

Danach http://localhost:8123 öffnen. Im selben WLAN ist der Server auch unter der IP-Adresse des Rechners erreichbar (z. B. `http://192.168.178.21:8123`).
Mit installiertem PHP ≥ 8.1 geht alternativ `php -S localhost:8000 -t public`.
