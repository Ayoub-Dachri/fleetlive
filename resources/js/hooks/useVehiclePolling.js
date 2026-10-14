import { useCallback, useEffect, useMemo, useState } from 'react';

const VISIBLE_INTERVAL_MS = 2_000;
const HIDDEN_INTERVAL_MS = 10_000;
const REQUEST_TIMEOUT_MS = 8_000;
const MAX_FAILURES = 5;

// Abort reason for a request replaced by a newer one; not counted as a failure.
const SUPERSEDED = 'superseded';

/**
 * Keeps the fleet in sync with the server.
 *
 * 1. GET /api/gps/vehicles loads everything and returns `server_time`.
 * 2. GET /api/gps/vehicles/updates?since=<server_time> then returns only what changed,
 *    every 2s while the tab is visible and every 10s while it is hidden.
 * 3. After MAX_FAILURES consecutive errors polling stops until reconnect() is called.
 *
 * connection: 'connecting' | 'live' | 'reconnecting' | 'offline'
 */
export function useVehiclePolling() {
    const [vehiclesById, setVehiclesById] = useState({});
    const [loaded, setLoaded] = useState(false);
    const [connection, setConnection] = useState('connecting');
    const [clockOffset, setClockOffset] = useState(0);
    const [session, setSession] = useState(0);

    useEffect(() => {
        let cursor = null;
        let failures = 0;
        let timer = null;
        let controller = null;
        let stopped = false;

        setConnection('connecting');

        const scheduleNext = () => {
            clearTimeout(timer);
            timer = setTimeout(poll, document.hidden ? HIDDEN_INTERVAL_MS : VISIBLE_INTERVAL_MS);
        };

        async function poll() {
            clearTimeout(timer);
            controller?.abort(SUPERSEDED);

            const request = new AbortController();
            const timeout = setTimeout(() => request.abort('timeout'), REQUEST_TIMEOUT_MS);
            controller = request;

            const url = cursor === null ? '/api/gps/vehicles' : `/api/gps/vehicles/updates?since=${cursor}`;

            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();
                const isFirstLoad = cursor === null;

                cursor = data.server_time;
                failures = 0;

                setClockOffset(data.server_time * 1000 - Date.now());
                setVehiclesById((current) => (isFirstLoad ? indexById(data.vehicles) : mergeUpdates(current, data.vehicles)));
                setLoaded(true);
                setConnection('live');
                scheduleNext();
            } catch (error) {
                if (stopped || request.signal.reason === SUPERSEDED) {
                    return;
                }

                failures += 1;
                setConnection(failures >= MAX_FAILURES ? 'offline' : 'reconnecting');

                if (failures < MAX_FAILURES) {
                    scheduleNext();
                }
            } finally {
                clearTimeout(timeout);
            }
        }

        // Coming back to the tab: refresh immediately instead of waiting out the 10s interval.
        const onVisibilityChange = () => {
            if (!document.hidden && failures < MAX_FAILURES) {
                poll();
            }
        };

        document.addEventListener('visibilitychange', onVisibilityChange);
        poll();

        return () => {
            stopped = true;
            clearTimeout(timer);
            controller?.abort(SUPERSEDED);
            document.removeEventListener('visibilitychange', onVisibilityChange);
        };
    }, [session]);

    const vehicles = useMemo(
        () => Object.values(vehiclesById).sort((a, b) => a.name.localeCompare(b.name) || a.id - b.id),
        [vehiclesById],
    );

    const reconnect = useCallback(() => setSession((value) => value + 1), []);

    return { vehicles, loaded, connection, clockOffset, reconnect };
}

function indexById(vehicles) {
    return Object.fromEntries(vehicles.map((vehicle) => [vehicle.id, vehicle]));
}

/**
 * The server re-sends a short overlap window on every poll, so skip anything we already
 * have. Returning the same object when nothing changed avoids a re-render.
 */
function mergeUpdates(current, updates) {
    let next = current;

    for (const vehicle of updates) {
        if (current[vehicle.id]?.updated_at >= vehicle.updated_at) {
            continue;
        }

        if (next === current) {
            next = { ...current };
        }

        next[vehicle.id] = vehicle;
    }

    return next;
}
