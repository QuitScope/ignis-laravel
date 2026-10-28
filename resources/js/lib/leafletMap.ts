/**
 * Lagekarte on Leaflet (CRS.Simple, local tiles). Percent coordinates (0-100, origin top left) map to lng 0..256 / lat 0..-256,
 * the numbers of the legacy map (8192 px at native zoom 5). Marker and tooltip DOM is built here from text nodes and
 * <img> elements only: user text never becomes markup (guarded by tests/js/leafletMap.test.ts).
 */
import L from 'leaflet';
import { renderSymbol } from '@/lib/tacticalSymbol';

export const UNITS = 256;
export const NATIVE_ZOOM = 5;
export const MAX_ZOOM = 6;
export const TILE_VERSION = '1';
export const MAP_TYPES = ['satellite', 'hybrid', 'roadmap', 'terrain'] as const;
export type MapType = (typeof MAP_TYPES)[number];
export const SEA: Record<MapType, string> = { satellite: '#0c284d', hybrid: '#0a1e36', roadmap: '#1862ad', terrain: '#4cb0d0' };
export const ATTRIBUTION =
    'Karten: <a href="https://gtadb.org" target="_blank" rel="noopener">gtadb.org</a> (rolux), <a href="https://creativecommons.org/licenses/by/4.0/deed.de" target="_blank" rel="noopener">CC BY 4.0</a>';
export const TYPE_COLOR: Record<string, string> = {
    Einsatzort: '#d10000',
    Einsatzleiter: '#0d6efd',
    Bereitstellungsraum: '#198754',
    'Verletzte Person': '#fd7e14',
    'Vermisste Person': '#6610f2',
    custom: '#6c757d',
};

export type Pct = { x: number; y: number };
export interface MapMarker {
    id: number;
    marker_type: string;
    pos_x: number;
    pos_y: number;
    description: string | null;
    can_delete?: boolean;
    grundzeichen: string | null;
    organisation: string | null;
    fachaufgabe: string | null;
    einheit: string | null;
    symbol: string | null;
    typ: string | null;
    text: string | null;
    name: string | null;
}
export interface MapZone {
    id: number;
    name: string;
    description: string | null;
    points: Pct[];
    color: string;
    can_delete?: boolean;
}

const round2 = (n: number) => Math.round(n * 100) / 100;
export const percentToLatLng = (p: Pct): { lat: number; lng: number } => ({ lat: -(Number(p.y) / 100) * UNITS, lng: (Number(p.x) / 100) * UNITS });
export const latLngToPercent = (ll: { lat: number; lng: number }): Pct => ({ x: round2((ll.lng / UNITS) * 100), y: round2((-ll.lat / UNITS) * 100) });
export const inMap = (p: Pct): boolean => Number.isFinite(p.x) && Number.isFinite(p.y) && p.x >= 0 && p.x <= 100 && p.y >= 0 && p.y <= 100;

export function diffById<T extends { id: number }>(prev: Map<number, string>, next: T[], sig: (t: T) => string = (t) => JSON.stringify(t)) {
    const add: T[] = [];
    const update: T[] = [];
    const seen = new Set<number>();
    for (const t of next) {
        seen.add(t.id);
        const old = prev.get(t.id);
        if (old === undefined) add.push(t);
        else if (old !== sig(t)) update.push(t);
    }
    return { add, update, remove: [...prev.keys()].filter((id) => !seen.has(id)) };
}

export const markerLabel = (m: MapMarker): string => m.name || m.text || m.description || m.marker_type;
const key = (incident: number, type: string) => `lagekarte.view.${incident}.${type}`;
export interface View {
    lat: number;
    lng: number;
    zoom: number;
}

function safeStore(): Storage | null {
    try {
        return globalThis.localStorage;
    } catch {
        return null;
    }
}

export function loadView(incident: number, type: string, store: Pick<Storage, 'getItem'> | null = safeStore()): View | null {
    try {
        const v = JSON.parse(store?.getItem(key(incident, type)) ?? 'null');
        return v && [v.lat, v.lng, v.zoom].every(Number.isFinite) ? { lat: v.lat, lng: v.lng, zoom: Math.min(Math.round(v.zoom), MAX_ZOOM) } : null;
    } catch {
        return null;
    }
}
export function saveView(incident: number, type: string, v: View, store: Pick<Storage, 'setItem'> | null = safeStore()): void {
    try {
        store?.setItem(key(incident, type), JSON.stringify(v));
    } catch {
    }
}

function iconElement(m: MapMarker): HTMLElement {
    const el = document.createElement('div');
    el.className = 'lk-icon';
    el.style.background = TYPE_COLOR[m.marker_type] ?? TYPE_COLOR.custom;
    el.textContent = (m.marker_type.startsWith('vehicle_') ? 'F' : m.marker_type.charAt(0)).toUpperCase();
    if (m.grundzeichen || m.symbol) {
        renderSymbol(m, m.name)
            .then((url) => {
                if (!url) return;
                const img = document.createElement('img');
                img.src = url;
                img.alt = '';
                el.textContent = '';
                el.style.background = 'transparent';
                el.appendChild(img);
            })
            .catch(() => {});
    }
    return el;
}

const textEl = (text: string): HTMLElement => {
    const el = document.createElement('span');
    el.textContent = text;
    return el;
};

export interface LagekarteOptions {
    el: HTMLElement;
    incidentId: number;
    type: MapType;
    colors: string[];
    onClick: (p: Pct) => void;
    onMove: (id: number, p: Pct) => void;
    onSelect: (kind: 'marker' | 'zone', id: number) => void;
}

export function createLagekarte(o: LagekarteOptions) {
    const bounds = L.latLngBounds([-UNITS, 0], [0, UNITS]);
    const map = L.map(o.el, { crs: L.CRS.Simple, minZoom: 0, maxZoom: MAX_ZOOM, zoomSnap: 1, zoomDelta: 1, maxBounds: bounds.pad(0.1), maxBoundsViscosity: 0.8, attributionControl: false });
    L.control.attribution({ prefix: false }).addAttribution(ATTRIBUTION).addTo(map);
    const zonesLayer = L.layerGroup().addTo(map);
    const markersLayer = L.layerGroup().addTo(map);
    const draftLayer = L.layerGroup().addTo(map);
    let tiles: L.TileLayer | null = null;
    let type = o.type;
    const markers = new Map<number, { layer: L.Marker; sig: string }>();
    const zones = new Map<number, { layer: L.Polygon; sig: string }>();

    const setType = (t: MapType) => {
        type = t;
        tiles?.remove();
        o.el.style.background = SEA[t];
        tiles = L.tileLayer(`/assets/map/${t}/{z}/{x}/{y}.jpg?v=${TILE_VERSION}`, {
            tileSize: 256,
            minNativeZoom: 0,
            maxNativeZoom: NATIVE_ZOOM,
            maxZoom: MAX_ZOOM,
            bounds,
            noWrap: true,
        }).addTo(map);
        tiles.bringToBack();
        const v = loadView(o.incidentId, t);
        if (v) map.setView([v.lat, v.lng], v.zoom);
    };
    map.fitBounds(bounds);
    setType(type);
    map.on('moveend', () => {
        const c = map.getCenter();
        saveView(o.incidentId, type, { lat: c.lat, lng: c.lng, zoom: map.getZoom() });
    });
    map.on('click', (e: L.LeafletMouseEvent) => o.onClick(latLngToPercent(e.latlng)));

    function setMarkers(list: MapMarker[], draggable: boolean) {
        const sig = (m: MapMarker) => JSON.stringify([m, draggable]);
        const d = diffById(new Map([...markers].map(([id, v]) => [id, v.sig])), list, sig);
        const build = (m: MapMarker) => {
            const layer = L.marker(percentToLatLng({ x: m.pos_x, y: m.pos_y }), {
                icon: L.divIcon({ html: iconElement(m), className: 'lk-divicon', iconSize: [48, 48], iconAnchor: [24, 24] }),
                draggable,
                keyboard: true,
                title: markerLabel(m),
                alt: markerLabel(m),
            });
            layer.bindTooltip(textEl(markerLabel(m)), { direction: 'top', offset: [0, -20] });
            layer.on('click', () => o.onSelect('marker', m.id));
            layer.on('dragend', () => o.onMove(m.id, latLngToPercent(layer.getLatLng())));
            return layer;
        };
        for (const id of d.remove) {
            markers.get(id)?.layer.remove();
            markers.delete(id);
        }
        for (const m of [...d.update, ...d.add]) {
            markers.get(m.id)?.layer.remove();
            const layer = build(m);
            layer.addTo(markersLayer);
            markers.set(m.id, { layer, sig: sig(m) });
        }
    }

    function resetMarkers() {
        markersLayer.clearLayers();
        markers.clear();
    }

    function setZones(list: MapZone[]) {
        const d = diffById(new Map([...zones].map(([id, v]) => [id, v.sig])), list);
        for (const id of d.remove) {
            zones.get(id)?.layer.remove();
            zones.delete(id);
        }
        for (const z of [...d.update, ...d.add]) {
            zones.get(z.id)?.layer.remove();
            const color = o.colors.includes(z.color) ? z.color : '#6c757d';
            const latlngs = z.points.map(percentToLatLng).map((p) => L.latLng(p.lat, p.lng));
            const layer = L.polygon(latlngs, { color, weight: 2, fillColor: color, fillOpacity: 0.25 });
            layer.bindTooltip(textEl(z.name), { sticky: true });
            layer.on('click', () => o.onSelect('zone', z.id));
            layer.addTo(zonesLayer);
            zones.set(z.id, { layer, sig: JSON.stringify(z) });
        }
    }

    function setDraft(points: Pct[], color: string) {
        draftLayer.clearLayers();
        const ll = points.map(percentToLatLng).map((p) => L.latLng(p.lat, p.lng));
        if (ll.length >= 3) L.polygon(ll, { color, weight: 2, dashArray: '6 4', fillColor: color, fillOpacity: 0.15, interactive: false }).addTo(draftLayer);
        else if (ll.length === 2) L.polyline(ll, { color, weight: 2, dashArray: '6 4', interactive: false }).addTo(draftLayer);
        ll.forEach((p) => L.circleMarker(p, { radius: 6, color, fillColor: '#fff', fillOpacity: 1, interactive: false }).addTo(draftLayer));
    }

    return {
        map,
        setType,
        setMarkers,
        setZones,
        resetMarkers,
        setDraft,
        center: () => latLngToPercent(map.getCenter()),
        invalidate: () => map.invalidateSize(),
        destroy: () => map.remove(),
    };
}
