import { STATUS } from '../lib/vehicleStatus';

export default function StatusBadge({ status }) {
    const { label, badge, dot } = STATUS[status];

    return (
        <span
            className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold tracking-wide ring-1 ring-inset transition-colors duration-500 ${badge}`}
        >
            <span className={`size-1.5 rounded-full transition-colors duration-500 ${dot}`} />
            {label}
        </span>
    );
}
