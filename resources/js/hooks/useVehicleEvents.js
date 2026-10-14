import { useEffect, useState } from 'react';

/**
 * Latest events for one vehicle. Refetches whenever `version` changes, which the
 * dashboard ties to the vehicle's updated_at so the feed follows live updates.
 */
export function useVehicleEvents(vehicleId, version) {
    const [state, setState] = useState({ vehicleId: null, events: [] });

    useEffect(() => {
        if (!vehicleId) {
            return;
        }

        const controller = new AbortController();

        fetch(`/api/gps/vehicles/${vehicleId}/events`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(response)))
            .then((data) => setState({ vehicleId, events: data.events }))
            .catch(() => {}); // A failed refresh keeps the current feed; the next update retries.

        return () => controller.abort();
    }, [vehicleId, version]);

    const isCurrent = state.vehicleId === vehicleId;

    return { events: isCurrent ? state.events : [], loading: Boolean(vehicleId) && !isCurrent };
}
