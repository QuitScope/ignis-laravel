# Third-party notices

## Situation map tiles (FireTab Lagekarte)

The map styles Satellit, Hybrid, Strassen and Gelaende (`public/assets/map/{satellite,hybrid,roadmap,terrain}`) are adapted from the GTA V map tiles of gtadb.org by rolux (<https://map.gtadb.org>, source <https://github.com/rolux/gtadb.org>), licensed under CC BY 4.0 (<https://creativecommons.org/licenses/by/4.0/>), copyright gtadb.org and contributors.

Changes: the level 5 tiles were resampled into the frame of the atlas map (`App\Support\MapCoordinates`), cut into Leaflet tiles of zoom 0 to 5 and saved as JPEG; areas without a source tile are filled with the sea colour. `scripts/map-tiles/build_map_tiles.py` reproduces this.

Required attribution (shown in the map corner while one of these styles is active): "Karten: gtadb.org (rolux), CC BY 4.0" (linked to gtadb.org and the licence text; same string as `ATTRIBUTION` in `resources/js/lib/leafletMap.ts`).

The imagery depicts the world of Grand Theft Auto V by Rockstar Games.

## Icons (Font Awesome Free)

The interface icons come from Font Awesome Free 7.3.1 (`@fortawesome/free-solid-svg-icons`, `@fortawesome/free-brands-svg-icons`), copyright Fonticons, Inc. (<https://fontawesome.com>). The icons are licensed under CC BY 4.0 (<https://creativecommons.org/licenses/by/4.0/>), the code under the MIT licence. Only the icons named in `resources/js/lib/faIcons.ts` are bundled, inline as SVG; they are used unchanged.
