# SymconShelly

Findet Shellys ab Gen2 (Gen2, Gen3, Gen4) im Netz und bindet sie in IP-Symcon ein. Gen1 und BLU werden nicht unterstützt.

## Installation

1. Ordner nach `modules/SymconShelly` kopieren, dort `git init` und einen Commit machen, Symcon-Dienst neu starten.
2. In Symcon muss ein aktiver **MQTT Server** mit offenem Server Socket existieren (ohne Benutzer/Passwort).
3. Instanz **Shelly Konfigurator** anlegen, Liste öffnen und bei den gewünschten Geräten **Erstellen** klicken.

Beim ersten Abruf stellt die Instanz den MQTT-Server am Shelly auf Symcon ein und startet ihn neu, falls nötig.
**War der Shelly schon mit einem anderen Broker verbunden, wird dieser ersetzt** (steht dann im Symcon-Log). Das verhindert das Häkchen „MQTT automatisch einstellen“ in der Instanz.

Antwortet unter der IP ein anderer Shelly als beim Erstellen, schaltet die Instanz nichts und meldet einen Fehler.

Tipps: Shellys mit fester IP oder DHCP-Reservierung betreiben, denn die IP steht in der Instanz. Findet mDNS nichts (anderes VLAN), die IPs im Konfigurator eintragen. Akkugeräte: Abfrage-Intervall auf 0 stellen.

## Variablen

Sie entstehen aus dem, was das Gerät meldet.

| Komponente | Variablen | Schaltbar |
|---|---|---|
| Schalter | Status, Leistung, Spannung, Strom, Frequenz, Energie, Temperatur | Status |
| Light / Dimmer | zusätzlich Helligkeit | Status, Helligkeit |
| RGB / RGBW | zusätzlich Farbe, bei RGBW Weiß | alle vier |
| CCT | zusätzlich Farbtemperatur | Status, Helligkeit, Farbtemperatur |
| Rollladen | Steuerung (Auf/Stopp/Zu), Zustand, Position (nur kalibriert; 0 % = offen, per Häkchen umkehrbar), Messwerte | Steuerung, Position |
| Eingang | Zustand, Analogwert | – |
| EM, EM1, PM1 | Leistung, Spannung, Strom je Phase, Energie | – |
| Sensoren | Temperatur, Luftfeuchte, Helligkeit, Spannung, Batterie | – |

## Funktionen

`SHY_Update(int $id): bool` fragt den Shelly sofort ab. Geschaltet wird mit `RequestAction($variablenID, $wert)`.

## Test

```bash
php -d zend.assertions=1 -d assert.exception=1 test/live.php
php -d zend.assertions=1 -d assert.exception=1 test/live.php 192.168.1.20
```

Ohne Argument läuft der Selbsttest gegen einen simulierten Shelly. Mit IP wird der echte Shelly nur gelesen, es wird nichts geschaltet und MQTT nicht verändert.
