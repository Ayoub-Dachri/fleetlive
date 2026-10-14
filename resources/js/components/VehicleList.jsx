import { formatDirection, formatNumber, relativeTime } from '../lib/format';
import { CrosshairIcon, TruckIcon } from './Icons';
import StatusBadge from './StatusBadge';

const LOW_BATTERY_V = 3.7;
const LOW_EXTERNAL_POWER_V = 11.8;

export default function VehicleList({ vehicles, statuses, now, loaded, selectedId, onSelect, onFocus }) {
    return (
        <div className="flex h-full flex-col">
            <div className="flex items-center justify-between border-b border-white/5 px-5 py-4">
                <div className="flex items-center gap-2">
                    <TruckIcon className="size-4 text-slate-500" />
                    <h2 className="text-sm font-semibold text-white">Vehicles</h2>
                </div>
                <span className="rounded-full bg-white/5 px-2 py-0.5 font-mono text-xs text-slate-400">{vehicles.length}</span>
            </div>

            <div className="scrollbar-thin flex-1 space-y-2.5 overflow-y-auto p-3">
                {!loaded && [0, 1, 2, 3].map((index) => <CardSkeleton key={index} />)}

                {loaded && vehicles.length === 0 && (
                    <p className="px-4 py-10 text-center text-sm text-slate-500">
                        No vehicles have reported yet.
                    </p>
                )}

                {vehicles.map((vehicle, index) => (
                    <VehicleCard
                        key={vehicle.id}
                        index={index}
                        vehicle={vehicle}
                        status={statuses.get(vehicle.id)}
                        now={now}
                        selected={vehicle.id === selectedId}
                        onSelect={() => onSelect(vehicle.id)}
                        onFocus={() => onFocus(vehicle)}
                    />
                ))}
            </div>
        </div>
    );
}

function VehicleCard({ vehicle, status, now, selected, index, onSelect, onFocus }) {
    const hasPosition = vehicle.latitude !== null;

    return (
        <article
            onClick={onSelect}
            style={{ animationDelay: `${Math.min(index, 8) * 50}ms` }}
            className={`group animate-fade-up cursor-pointer rounded-2xl border p-4 transition-[background-color,border-color,opacity,transform] duration-500 hover:-translate-y-0.5 ${
                selected
                    ? 'border-emerald-400/30 bg-emerald-400/[0.04]'
                    : 'border-white/5 bg-white/[0.015] hover:border-white/10 hover:bg-white/[0.03]'
            } ${status === 'offline' ? 'opacity-70' : ''}`}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="truncate text-sm font-semibold text-white">{vehicle.name}</h3>
                    <p className="mt-0.5 truncate font-mono text-[11px] text-slate-500">
                        #{vehicle.device_id ?? vehicle.id} · {vehicle.ident}
                    </p>
                </div>
                <StatusBadge status={status} />
            </div>

            <dl className="mt-3.5 grid grid-cols-3 gap-x-3 gap-y-2.5">
                <Metric label="Speed" value={formatNumber(vehicle.speed, { unit: 'km/h' })} />
                <Metric label="Heading" value={formatDirection(vehicle.direction)} />
                <Metric label="Mileage" value={formatNumber(vehicle.mileage, { unit: 'km' })} />
                <Metric
                    label="Battery"
                    value={formatNumber(vehicle.battery, { digits: 2, unit: 'V' })}
                    warn={vehicle.battery !== null && vehicle.battery < LOW_BATTERY_V}
                />
                <Metric
                    label="Ext. power"
                    value={formatNumber(vehicle.external_power, { digits: 2, unit: 'V' })}
                    warn={vehicle.external_power !== null && vehicle.external_power < LOW_EXTERNAL_POWER_V}
                />
                <Metric label="Satellites" value={formatNumber(vehicle.satellites)} />
            </dl>

            <div className="mt-3.5 flex items-center justify-between border-t border-white/5 pt-3">
                <p className="text-xs text-slate-500">
                    Last message{' '}
                    <span className="font-medium text-slate-300" title={vehicle.last_message_at ?? ''}>
                        {relativeTime(vehicle.updated_at * 1000, now)}
                    </span>
                </p>
                <button
                    type="button"
                    disabled={!hasPosition}
                    onClick={(event) => {
                        event.stopPropagation();
                        onFocus();
                    }}
                    className="flex items-center gap-1.5 rounded-lg border border-white/10 px-2.5 py-1 text-xs font-medium text-slate-300 transition hover:border-emerald-400/40 hover:bg-emerald-400/10 hover:text-emerald-200 focus-visible:ring-2 focus-visible:ring-emerald-400/60 focus-visible:outline-none active:scale-95 disabled:pointer-events-none disabled:opacity-40"
                    aria-label={`Focus map on ${vehicle.name}`}
                >
                    <CrosshairIcon className="size-3.5" />
                    Focus
                </button>
            </div>
        </article>
    );
}

function Metric({ label, value, warn = false }) {
    return (
        <div className="min-w-0">
            <dt className="text-[10px] font-medium tracking-wider text-slate-500 uppercase">{label}</dt>
            <dd className={`truncate font-mono text-[13px] tabular-nums transition-colors duration-500 ${warn ? 'text-amber-300' : 'text-slate-200'}`}>
                {value}
            </dd>
        </div>
    );
}

function CardSkeleton() {
    return (
        <div className="rounded-2xl border border-white/5 p-4">
            <div className="flex justify-between">
                <div className="space-y-2">
                    <div className="skeleton h-3.5 w-32" />
                    <div className="skeleton h-2.5 w-44" />
                </div>
                <div className="skeleton h-6 w-16 rounded-full" />
            </div>
            <div className="mt-4 grid grid-cols-3 gap-3">
                {[0, 1, 2, 3, 4, 5].map((index) => (
                    <div key={index} className="skeleton h-7" />
                ))}
            </div>
        </div>
    );
}
