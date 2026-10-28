# Kartenkacheln (FireTab Lagekarte)

Statische Dateien: `public/assets/map/<type>/{z}/{x}/{y}.jpg`, Typen `satellite` (Standard), `hybrid`, `roadmap`, `terrain`; native Zoomstufe 5, maximal 6. Sie werden direkt vom Webserver ausgeliefert, es gibt keine Laravel-Route und keinen Controller. Quelle und Lizenz: `THIRD_PARTY_NOTICES.md` (gtadb.org, CC BY 4.0, die Namensnennung muss in der Karte angezeigt werden). Neu erzeugen mit `scripts/map-tiles/build_map_tiles.py` (der Kopf beschreibt den Download der Quelle).

- Größe: 5460 Dateien, etwa 28,5 MiB zusätzlich im Repository (reine Binärdateien, `-text binary` in `.gitattributes`, kein Git LFS). Die Quellbilder liegen nicht im Repo.
- Importer: Karten-Elemente speichern nur Prozentpositionen, unabhängig vom Kachel-Typ; nichts im Import hängt von den Kacheln ab.
- Atlas (Typ `tiles` im Altsystem) ist bewusst ausgeschlossen: Herkunft und Lizenz des Bildes sind ungeklärt (offene Frage an den Betreiber). Der Wert `tiles` aus dem Altsystem wird auf `satellite` abgebildet. Der Atlas-Generator wird nicht übernommen.
- Release und Klick-Update: `tools/release/build-archive.php` schließt nur eine feste Liste aus, `public/assets/map` ist Teil des Archivs und des Manifests (abgedeckt durch `ReleaseToolsTest`).
- Docker-Image: Das `Dockerfile` kopiert den Baum (`COPY . .`), das Image wächst also um etwa 28,5 MiB.
- **Eintragsgrenze des Klick-Updates:** `App\Update\ArchiveExtractor` lehnt Archive mit mehr als `maxEntries = 20_000` Einträgen ab. Gemessen an diesem Baum (Pfade von `FlatSwapper::CODE` mit den Auslassregeln von `tools/release/build-archive.php`, vendor ohne Dev-Pakete): etwa **14.430** Einträge, davon 5.460 Kacheln, also bleiben etwa 5.570 übrig. Ein weiterer Kachel-Typ mit denselben Zoomstufen (etwa 1.365 Dateien) passt noch; eine native Zoomstufe 6 für alle vier Typen (4 x 4.096 Dateien mehr) oder ein deutlich größerer vendor-Baum würde das Klick-Update brechen. Erhöhe die Grenze in `ArchiveExtractor` (und in dessen Test) zusammen mit einer solchen Änderung.
- Caching: Die Kacheln ändern sich unter einer URL nie (Cache-Busting über eine Version in der URL bzw. Query durch den Karten-Client), also liefere sie so aus:

```nginx
location /assets/map/ {
    add_header Cache-Control "public, max-age=31536000, immutable";
    try_files $uri =404;
}
```

  Caddy: `header /assets/map/* Cache-Control "public, max-age=31536000, immutable"`. Das Repo enthält außer `docker/nginx/legacy-php.conf` (nur Rewrite) keine Server-Beispiele, deshalb ist es hier dokumentiert.
