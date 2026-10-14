import { useEffect, useState } from 'react';

/** Current time in ms, refreshed every `interval` so relative times and staleness stay current. */
export function useNow(interval = 1000) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), interval);
        return () => clearInterval(timer);
    }, [interval]);

    return now;
}
