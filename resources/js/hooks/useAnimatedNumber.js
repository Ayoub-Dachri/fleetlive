import { useEffect, useRef, useState } from 'react';

/** Tweens a displayed number towards `value`, for counters that roll instead of jumping. */
export function useAnimatedNumber(value, duration = 600) {
    const [display, setDisplay] = useState(value);
    const displayRef = useRef(value);

    useEffect(() => {
        const from = displayRef.current;
        const startedAt = performance.now();
        let frame;

        const step = (time) => {
            const progress = Math.min(1, (time - startedAt) / duration);
            const next = from + (value - from) * (1 - Math.pow(1 - progress, 3));

            displayRef.current = next;
            setDisplay(next);

            if (progress < 1) {
                frame = requestAnimationFrame(step);
            }
        };

        frame = requestAnimationFrame(step);

        return () => cancelAnimationFrame(frame);
    }, [value, duration]);

    return Math.round(display);
}
