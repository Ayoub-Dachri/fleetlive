import { useAnimatedNumber } from '../hooks/useAnimatedNumber';
import { relativeTime } from '../lib/format';
import { LogoMark, RefreshIcon } from './Icons';

const CONNECTION = {
    connecting: { label: 'Connecting', dot: 'bg-slate-400', ping: true, text: 'text-slate-300' },
    live: { label: 'Live', dot: 'bg-emerald-400', ping: true, text: 'text-emerald-300' },
    reconnecting: { label: 'Reconnecting…', dot: 'bg-amber-400', ping: true, text: 'text-amber-300' },
    offline: { label: 'Disconnected', dot: 'bg-rose-500', ping: false, text: 'text-rose-300' },
};

export default function Header({ counts, lastUpdateAt, now, connection, onReconnect }) {
    const attention = counts.offline + counts.invalid;

    return (
        <header className="mx-auto flex max-w-[1600px] flex-wrap items-center gap-x-6 gap-y-4 px-4 pt-5 pb-4 lg:px-6">
            <div className="flex items-center gap-3">
                <div className="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-emerald-400/20 to-emerald-400/5 ring-1 ring-emerald-400/25">
                    <LogoMark className="size-5 text-emerald-400" />
                </div>
                <div>
                    <h1 className="text-lg leading-tight font-semibold tracking-tight text-white">FleetLive</h1>
                    <p className="text-xs text-slate-500">Real-time vehicle tracking</p>
                </div>
            </div>

            <div className="order-3 grid w-full grid-cols-2 gap-2 sm:grid-cols-4 lg:order-2 lg:ml-auto lg:w-auto">
                <Stat label="Vehicles" value={counts.total} hint={attention > 0 ? `${attention} need attention` : 'All reporting'} />
                <Stat label="Moving" value={counts.moving} accent="bg-emerald-400" />
                <Stat label="Idle" value={counts.idle} accent="bg-amber-400" />
                <Stat label="Last update" text={lastUpdateAt ? relativeTime(lastUpdateAt, now) : '—'} />
            </div>

            <ConnectionPill connection={connection} onReconnect={onReconnect} />
        </header>
    );
}

function Stat({ label, value, text, hint, accent }) {
    const animated = useAnimatedNumber(value ?? 0);

    return (
        <div className="rounded-xl border border-white/5 bg-white/[0.02] px-3.5 py-2 lg:min-w-28">
            <div className="flex items-center gap-1.5 text-[11px] font-medium tracking-wide text-slate-500 uppercase">
                {accent && <span className={`size-1.5 rounded-full ${accent}`} />}
                {label}
            </div>
            <div className="mt-0.5 font-mono text-lg font-medium text-white tabular-nums">{text ?? animated}</div>
            {hint && <div className="-mt-0.5 truncate text-[11px] text-slate-500">{hint}</div>}
        </div>
    );
}

function ConnectionPill({ connection, onReconnect }) {
    const { label, dot, ping, text } = CONNECTION[connection];

    return (
        <div className="order-2 ml-auto flex items-center gap-2 lg:order-3 lg:ml-0">
            <div
                role="status"
                className="flex items-center gap-2 rounded-full border border-white/8 bg-white/[0.03] py-1.5 pr-3.5 pl-3 transition-colors duration-500"
            >
                <span className="relative flex size-2.5">
                    {ping && <span className={`absolute inline-flex size-full animate-ping rounded-full opacity-60 ${dot}`} />}
                    <span className={`relative inline-flex size-2.5 rounded-full ${dot}`} />
                </span>
                <span className={`text-sm font-medium ${text}`}>{label}</span>
            </div>

            {connection === 'offline' && (
                <button
                    type="button"
                    onClick={onReconnect}
                    className="flex items-center gap-1.5 rounded-full bg-white px-3.5 py-1.5 text-sm font-semibold text-ink-950 transition hover:bg-emerald-300 active:scale-95"
                >
                    <RefreshIcon className="size-3.5" />
                    Reconnect
                </button>
            )}
        </div>
    );
}
