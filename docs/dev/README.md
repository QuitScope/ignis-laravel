# Entwicklerdokumentation

Installation und Einrichtung stehen in [INSTALL.md](../../INSTALL.md), der Überblick über das Projekt im
[README](../../README.md).

## Betrieb

- [operations.md](operations.md): Warteschlange, Scheduler, Aufräumen, Sicherung und Wiederherstellung, Überwachung, Logs, Drosselung
- [update-recovery.md](update-recovery.md): Update per Klick von Hand wiederherstellen, wenn der automatische Rollback nicht reicht
- [map-tiles.md](map-tiles.md): Kartenkacheln der FireTab-Lagekarte

## Entwicklung

- [modules.md](modules.md): Module anlegen (Aufbau, Hooks, Rechte, Einstellungen) und die Eigenheiten der Fachmodule
- [cef.md](cef.md): FiveM-Browser (Chromium 103): Probe, Cookies und Header, bekannte Grenzen
- [vehicle-registry.md](vehicle-registry.md): Annahme des Fahrzeugregisters aus dem EMD-Sync

## Migration vom Altsystem

- [import.md](import.md): `ignis:import-legacy`: Voraussetzungen, Ablauf, was je Modul übernommen wird, Fehlersuche
- [tests.md](tests.md): Testgruppen, MariaDB-Lauf, Altschema laden
