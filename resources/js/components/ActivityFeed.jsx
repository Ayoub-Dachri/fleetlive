import { useVehicleEvents } from '../hooks/useVehicleEvents';
import { formatClock, formatCoordinates, formatNumber } from '../lib/format';
import { ActivityIcon } from './Icons';

const COLUMNS = 'sm:grid-cols-[6rem_minmax(0,1.3fr)_5.5rem_5rem_minmax(0,1.4fr)_5.5rem]';

export default function ActivityFeed({ vehicle }) {
    const { events, loading } = useVehicleEvents(vehicle?.id, vehicle?.updated_at);

    return (
        <div className="flex h-full flex-col">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-white/5 px-5 py-4">
                <div className="flex items-center gap-2">
                    <ActivityIcon className="size-4 text-slate-500" />
                    <h2 className="text-sm font-semibold text-white">Latest GPS messages</h2>
                    {vehicle && <span className="text-sm text-slate-500">· {vehicle.name}</span>}
                </div>
                <p className="text-xs text-slate-500">Last 20 messages · select a vehicle to switch</p>
            </div>

            <div className={`hidden gap-4 px-5 pt-3 pb-1 text-[10px] font-medium tracking-wider text-slate-500 uppercase sm:grid ${COLUMNS}`}>
                <span>Time</span>
                <span>Vehicle</span>
                <span>Speed</span>
                <span>Battery</span>
                <span>Coordinates</span>
                <span>Ignition</span>
            </div>

            <ol key={vehicle?.id} className="scrollbar-thin max-h-80 flex-1 overflow-y-auto px-2 pb-2">
                {loading && [0, 1, 2, 3].map((index) => <li key={index} className="skeleton mx-3 my-2 h-8" />)}

                {!loading && events.length === 0 && (
                    <li className="px-3 py-8 text-center text-sm text-slate-500">
                        {vehicle ? 'No messages for this vehicle yet.' : 'Messages will appear here once vehicles report in.'}
                    </li>
                )}

                {events.map((event, index) => (
                    <li
                        key={event.id}
                        style={{ animationDelay: `${Math.min(index, 10) * 30}ms` }}
                        className={`grid animate-slide-in grid-cols-2 items-center gap-x-4 gap-y-1 rounded-xl px-3 py-2.5 text-sm transition-colors hover:bg-white/[0.03] ${COLUMNS}`}
                    >
                        <span className="font-mono text-xs text-slate-300 tabular-nums" title={event.message_at}>
                            {formatClock(event.message_at)}
                        </span>
                        <span className="truncate text-right font-mono text-xs text-slate-500 sm:text-left">{event.ident}</span>
                        <span className="font-mono text-xs text-slate-200 tabular-nums">
                            {formatNumber(event.speed, { unit: 'km/h' })}
                        </span>
                        <span className="text-right font-mono text-xs text-slate-200 tabular-nums sm:text-left">
                            {formatNumber(event.battery, { digits: 2, unit: 'V' })}
                        </span>
                        <span className={`truncate font-mono text-xs tabular-nums ${event.gnss_valid ? 'text-slate-400' : 'text-rose-300'}`}>
                            {event.gnss_valid ? formatCoordinates(event.latitude, event.longitude) : 'No GPS fix'}
                        </span>
                        <span className="text-right sm:text-left">
                            <IgnitionTag on={event.ignition} />
                        </span>
                    </li>
                ))}
            </ol>
        </div>
    );
}

function IgnitionTag({ on }) {
    if (on === null) {
        return <span className="text-xs text-slate-600">—</span>;
    }

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-medium ${
                on ? 'bg-emerald-400/10 text-emerald-300' : 'bg-white/5 text-slate-400'
            }`}
        >
            <span className={`size-1.5 rounded-full ${on ? 'bg-emerald-400' : 'bg-slate-500'}`} />
            {on ? 'On' : 'Off'}
        </span>
    );
}
