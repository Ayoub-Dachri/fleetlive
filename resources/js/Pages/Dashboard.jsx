import { Head } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import ActivityFeed from '../components/ActivityFeed';
import Header from '../components/Header';
import LiveMap from '../components/LiveMap';
import VehicleList from '../components/VehicleList';
import { useNow } from '../hooks/useNow';
import { useVehiclePolling } from '../hooks/useVehiclePolling';
import { vehicleStatus } from '../lib/vehicleStatus';

export default function Dashboard() {
    const { vehicles, loaded, connection, clockOffset, reconnect } = useVehiclePolling();
    const now = useNow() + clockOffset; // aligned with the server clock
    const mapRef = useRef(null);
    const [selectedId, setSelectedId] = useState(null);

    const statuses = useMemo(() => new Map(vehicles.map((vehicle) => [vehicle.id, vehicleStatus(vehicle, now)])), [vehicles, now]);

    const counts = useMemo(() => {
        const result = { total: vehicles.length, moving: 0, idle: 0, invalid: 0, offline: 0 };
        statuses.forEach((status) => result[status]++);
        return result;
    }, [vehicles, statuses]);

    const lastUpdateAt = useMemo(() => Math.max(0, ...vehicles.map((vehicle) => vehicle.updated_at ?? 0)) * 1000 || null, [vehicles]);

    const selected = vehicles.find((vehicle) => vehicle.id === selectedId) ?? vehicles[0] ?? null;

    const focusVehicle = (vehicle) => {
        setSelectedId(vehicle.id);
        mapRef.current?.focus(vehicle);
    };

    return (
        <>
            <Head title="Live map" />

            <div className="min-h-dvh">
                <Header counts={counts} lastUpdateAt={lastUpdateAt} now={now} connection={connection} onReconnect={reconnect} />

                <main className="mx-auto grid max-w-[1600px] gap-4 px-4 pb-6 lg:grid-cols-[minmax(0,1fr)_400px] lg:px-6">
                    <section className="card h-[55vh] min-h-80 animate-fade-up overflow-hidden lg:h-[calc(100dvh-24rem)] lg:min-h-[480px]" aria-label="Live map">
                        <LiveMap
                            ref={mapRef}
                            vehicles={vehicles}
                            statuses={statuses}
                            now={now}
                            selectedId={selected?.id}
                            loaded={loaded}
                            connection={connection}
                            onSelect={setSelectedId}
                            onReconnect={reconnect}
                        />
                    </section>

                    <aside
                        className="card max-h-[70vh] animate-fade-up overflow-hidden [animation-delay:80ms] lg:h-[calc(100dvh-24rem)] lg:max-h-none lg:min-h-[480px]"
                        aria-label="Vehicle list"
                    >
                        <VehicleList
                            vehicles={vehicles}
                            statuses={statuses}
                            now={now}
                            loaded={loaded}
                            selectedId={selected?.id}
                            onSelect={setSelectedId}
                            onFocus={focusVehicle}
                        />
                    </aside>

                    <section className="card animate-fade-up overflow-hidden [animation-delay:160ms] lg:col-span-2" aria-label="Activity feed">
                        <ActivityFeed vehicle={selected} />
                    </section>
                </main>
            </div>
        </>
    );
}
