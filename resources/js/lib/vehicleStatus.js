// A vehicle that hasn't reported for this long is shown as offline.
export const STALE_AFTER_MS = 60_000;

export const STATUS = {
    moving: {
        label: 'Moving',
        dot: 'bg-emerald-400',
        badge: 'bg-emerald-400/10 text-emerald-300 ring-emerald-400/25',
    },
    idle: {
        label: 'Idle',
        dot: 'bg-amber-400',
        badge: 'bg-amber-400/10 text-amber-300 ring-amber-400/25',
    },
    invalid: {
        label: 'GPS Invalid',
        dot: 'bg-rose-500',
        badge: 'bg-rose-500/10 text-rose-300 ring-rose-500/30',
    },
    offline: {
        label: 'Offline',
        dot: 'bg-rose-500/70',
        badge: 'bg-rose-500/10 text-rose-300/80 ring-rose-500/20',
    },
};

/**
 * Status depends on the clock (staleness), so it is derived on the client.
 * `now` is a server-aligned timestamp in milliseconds.
 */
export function vehicleStatus(vehicle, now) {
    if (!vehicle.updated_at || now - vehicle.updated_at * 1000 > STALE_AFTER_MS) {
        return 'offline';
    }

    if (!vehicle.gnss_valid) {
        return 'invalid';
    }

    return (vehicle.moving ?? vehicle.speed > 0) ? 'moving' : 'idle';
}
