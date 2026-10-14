const COMPASS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

export function formatNumber(value, { digits = 0, unit = '' } = {}) {
    if (value === null || value === undefined) {
        return '—';
    }

    const number = Number(value).toLocaleString('en-US', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });

    return unit ? `${number} ${unit}` : number;
}

export function formatDirection(degrees) {
    if (degrees === null || degrees === undefined) {
        return '—';
    }

    return `${Math.round(degrees)}° ${COMPASS[Math.round(degrees / 45) % 8]}`;
}

export function formatCoordinates(latitude, longitude) {
    if (latitude === null || latitude === undefined) {
        return '—';
    }

    return `${latitude.toFixed(5)}, ${longitude.toFixed(5)}`;
}

/** "12s ago", "4m ago". `timestamp` and `now` are in milliseconds. */
export function relativeTime(timestamp, now) {
    if (!timestamp) {
        return 'never';
    }

    const seconds = Math.max(0, Math.round((now - timestamp) / 1000));

    if (seconds < 5) return 'just now';
    if (seconds < 60) return `${seconds}s ago`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;

    return `${Math.floor(seconds / 86400)}d ago`;
}

export function formatClock(isoString) {
    return new Date(isoString).toLocaleTimeString('en-GB', { hour12: false });
}

export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`);
}
