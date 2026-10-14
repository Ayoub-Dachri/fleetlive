import L from 'leaflet';
import { useEffect, useImperativeHandle, useRef } from 'react';
import { glideMarkerTo, rotationTowards, stopGlide } from '../lib/animateMarker';
import { escapeHtml, formatNumber, relativeTime } from '../lib/format';
import { TruckIcon } from './Icons';

const CASABLANCA = [33.5731, -7.5898];

const ARROW_SVG =
    '<svg class="vehicle-marker__arrow" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2.5 19.5 21 12 16.8 4.5 21z"/></svg>';

const markerIcon = L.divIcon({
    className: '', // drop Leaflet's default white box
    html: `<div class="vehicle-marker"><span class="vehicle-marker__halo"></span><span class="vehicle-marker__body">${ARROW_SVG}</span></div>`,
    iconSize: [36, 36],
    iconAnchor: [18, 18],
    popupAnchor: [0, -16],
});

/**
 * Leaflet is driven imperatively: React hands over the vehicle list and this component
 * creates, glides, restyles and removes markers to match it.
 */
export default function LiveMap({ ref, vehicles, statuses, now, selectedId, loaded, connection, onSelect, onReconnect }) {
    const containerRef = useRef(null);
    const mapRef = useRef(null);
    const markersRef = useRef(new Map()); // vehicle id => { marker, vehicle, rotation }
    const hasFittedRef = useRef(false);
    const onSelectRef = useRef(onSelect);
    const nowRef = useRef(now);

    onSelectRef.current = onSelect;
    nowRef.current = now;

    useEffect(() => {
        const map = L.map(containerRef.current, { zoomControl: false }).setView(CASABLANCA, 12);

        L.control.zoom({ position: 'bottomright' }).addTo(map);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);

        // Keep tiles correct when the layout around the map changes size.
        const resizeObserver = new ResizeObserver(() => map.invalidateSize());
        resizeObserver.observe(containerRef.current);

        mapRef.current = map;
        const markers = markersRef.current;

        return () => {
            resizeObserver.disconnect();
            markers.forEach(({ marker }) => stopGlide(marker));
            markers.clear();
            map.remove();
        };
    }, []);

    useImperativeHandle(ref, () => ({
        focus(vehicle) {
            const entry = markersRef.current.get(vehicle.id);

            if (!entry) {
                return;
            }

            mapRef.current.flyTo([vehicle.latitude, vehicle.longitude], Math.max(mapRef.current.getZoom(), 16), {
                duration: 1.2,
            });
            mapRef.current.once('moveend', () => entry.marker.openPopup());
        },
    }));

    // Sync markers with the latest vehicle data.
    useEffect(() => {
        const map = mapRef.current;
        const markers = markersRef.current;
        const visible = new Set();

        for (const vehicle of vehicles) {
            if (vehicle.latitude === null) {
                continue; // no GPS fix received yet
            }

            visible.add(vehicle.id);
            let entry = markers.get(vehicle.id);

            if (!entry) {
                entry = createMarker(map, vehicle, () => onSelectRef.current(vehicle.id));
                entry.marker.bindPopup(() => popupHtml(entry.vehicle, nowRef.current), {
                    className: 'vehicle-popup',
                    closeButton: false,
                    maxWidth: 260,
                });
                markers.set(vehicle.id, entry);
            } else if (entry.vehicle.updated_at !== vehicle.updated_at) {
                glideMarkerTo(entry.marker, [vehicle.latitude, vehicle.longitude]);
            }

            entry.vehicle = vehicle;
        }

        for (const [id, entry] of markers) {
            if (!visible.has(id)) {
                stopGlide(entry.marker);
                entry.marker.remove();
                markers.delete(id);
            }
        }

        if (!hasFittedRef.current && markers.size > 0) {
            const bounds = L.latLngBounds([...markers.values()].map(({ marker }) => marker.getLatLng()));
            map.fitBounds(bounds, { padding: [60, 60], maxZoom: 15, animate: false });
            hasFittedRef.current = true;
        }
    }, [vehicles]);

    // Status colour, heading and popup text change with time as well as with data.
    useEffect(() => {
        for (const [id, entry] of markersRef.current) {
            const element = entry.marker.getElement()?.firstElementChild;

            if (!element) {
                continue;
            }

            element.dataset.status = statuses.get(id) ?? 'offline';
            element.dataset.selected = String(id === selectedId);

            if (entry.vehicle.direction !== null) {
                entry.rotation = rotationTowards(entry.rotation, entry.vehicle.direction);
                element.querySelector('.vehicle-marker__arrow').style.transform = `rotate(${entry.rotation}deg)`;
            }

            entry.marker.setZIndexOffset(id === selectedId ? 1000 : 0);

            if (entry.marker.isPopupOpen()) {
                entry.marker.getPopup().update();
            }
        }
    }, [vehicles, statuses, selectedId, now]);

    return (
        <div className="relative h-full overflow-hidden rounded-[inherit]">
            {/* `isolate` keeps Leaflet's high z-index panes below the overlays. */}
            <div ref={containerRef} className="fleet-map absolute inset-0 isolate" />

            <Legend />

            {!loaded && connection !== 'offline' && (
                <Overlay>
                    <span className="size-5 animate-spin rounded-full border-2 border-emerald-400/30 border-t-emerald-400" />
                    <p className="text-sm text-slate-300">
                        {connection === 'reconnecting' ? 'Server not responding, retrying…' : 'Connecting to the fleet…'}
                    </p>
                </Overlay>
            )}

            {!loaded && connection === 'offline' && (
                <Overlay>
                    <div className="text-center">
                        <p className="font-medium text-white">Can’t reach the server</p>
                        <p className="mt-1 max-w-64 text-sm text-slate-400">
                            The API is not responding. Check that the app, MySQL and Redis are running, then reconnect.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onReconnect}
                        className="rounded-full bg-white px-4 py-1.5 text-sm font-semibold text-ink-950 transition hover:bg-emerald-300 active:scale-95"
                    >
                        Reconnect
                    </button>
                </Overlay>
            )}

            {loaded && vehicles.length === 0 && (
                <Overlay>
                    <div className="grid size-12 place-items-center rounded-2xl bg-white/5 ring-1 ring-white/10">
                        <TruckIcon className="size-6 text-slate-300" />
                    </div>
                    <div className="text-center">
                        <p className="font-medium text-white">No vehicles yet</p>
                        <p className="mt-1 max-w-64 text-sm text-slate-400">
                            Vehicles appear here as soon as their first GPS message arrives.
                        </p>
                    </div>
                    <code className="rounded-lg bg-black/40 px-3 py-1.5 font-mono text-xs text-emerald-300 ring-1 ring-white/10">
                        php artisan gps:simulate
                    </code>
                </Overlay>
            )}
        </div>
    );
}

function createMarker(map, vehicle, onClick) {
    const marker = L.marker([vehicle.latitude, vehicle.longitude], {
        icon: markerIcon,
        title: vehicle.name,
        riseOnHover: true,
    })
        .on('click', onClick)
        .addTo(map);

    return { marker, vehicle, rotation: vehicle.direction ?? 0 };
}

function popupHtml(vehicle, now) {
    const rows = [
        ['Speed', formatNumber(vehicle.speed, { unit: 'km/h' })],
        ['Battery', formatNumber(vehicle.battery, { digits: 2, unit: 'V' })],
        ['Satellites', formatNumber(vehicle.satellites)],
        ['Updated', relativeTime(vehicle.updated_at * 1000, now)],
    ];

    return `
        <div style="min-width:190px">
            <div style="font-weight:600;color:#fff;font-size:14px">${escapeHtml(vehicle.name)}</div>
            <div style="font-family:var(--font-mono);font-size:11px;color:#64748b;margin-top:2px">
                #${escapeHtml(vehicle.device_id ?? vehicle.id)} · ${escapeHtml(vehicle.ident)}
            </div>
            <dl style="display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:10px 0 0;font-size:12px">
                ${rows
                    .map(
                        ([label, value]) =>
                            `<dt style="color:#64748b">${label}</dt><dd style="margin:0;text-align:right;color:#e2e8f0;font-family:var(--font-mono)">${escapeHtml(value)}</dd>`,
                    )
                    .join('')}
            </dl>
        </div>`;
}

function Legend() {
    const items = [
        ['Moving', '#34d399'],
        ['Idle', '#94a3b8'],
        ['GPS invalid / stale', '#f43f5e'],
    ];

    return (
        <div className="pointer-events-none absolute bottom-3 left-3 z-10 rounded-xl border border-white/8 bg-ink-900/80 px-3 py-2.5 shadow-lg backdrop-blur-md">
            <p className="mb-1.5 text-[10px] font-semibold tracking-wider text-slate-500 uppercase">Legend</p>
            <ul className="space-y-1">
                {items.map(([label, color]) => (
                    <li key={label} className="flex items-center gap-2 text-xs text-slate-300">
                        <span className="size-2.5 rounded-full ring-2 ring-white/80" style={{ background: color }} />
                        {label}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Overlay({ children }) {
    return (
        <div className="absolute inset-0 z-10 grid place-items-center bg-ink-950/50 backdrop-blur-[2px]">
            <div className="flex animate-fade-up flex-col items-center gap-3 rounded-2xl border border-white/8 bg-ink-900/90 px-8 py-6 shadow-2xl">
                {children}
            </div>
        </div>
    );
}
