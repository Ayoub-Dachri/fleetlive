const GLIDE_DURATION_MS = 1500;

const frames = new WeakMap();
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

const easeInOutSine = (t) => -(Math.cos(Math.PI * t) - 1) / 2;

/**
 * Glide a Leaflet marker to a new position with requestAnimationFrame.
 * A new glide cancels the running one and starts from wherever the marker is now,
 * so overlapping updates never make it jump.
 */
export function glideMarkerTo(marker, [latitude, longitude], duration = GLIDE_DURATION_MS) {
    cancelAnimationFrame(frames.get(marker));

    const from = marker.getLatLng();

    if (reducedMotion.matches || document.hidden) {
        marker.setLatLng([latitude, longitude]);
        return;
    }

    const startedAt = performance.now();

    const step = (time) => {
        const progress = Math.min(1, (time - startedAt) / duration);
        const eased = easeInOutSine(progress);

        marker.setLatLng([
            from.lat + (latitude - from.lat) * eased,
            from.lng + (longitude - from.lng) * eased,
        ]);

        if (progress < 1) {
            frames.set(marker, requestAnimationFrame(step));
        }
    };

    frames.set(marker, requestAnimationFrame(step));
}

export function stopGlide(marker) {
    cancelAnimationFrame(frames.get(marker));
}

/**
 * The rotation (in degrees, unbounded) that turns `current` towards `heading` the short
 * way round, so a CSS transition from 350° to 10° turns 20° instead of 340°.
 */
export function rotationTowards(current, heading) {
    const delta = ((((heading - current) % 360) + 540) % 360) - 180;

    return current + delta;
}
