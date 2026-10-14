// Small inline icons (24px grid, stroke-based) so no icon library is needed.

function Icon({ children, className = 'size-4', ...props }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            className={className}
            aria-hidden="true"
            {...props}
        >
            {children}
        </svg>
    );
}

export const LogoMark = (props) => (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...props}>
        <path d="M12 3.5 18.5 20 12 16.5 5.5 20z" fill="currentColor" />
    </svg>
);

export const CrosshairIcon = (props) => (
    <Icon {...props}>
        <circle cx="12" cy="12" r="7" />
        <path d="M12 2v3M12 19v3M2 12h3M19 12h3" />
        <circle cx="12" cy="12" r="1.5" fill="currentColor" />
    </Icon>
);

export const RefreshIcon = (props) => (
    <Icon {...props}>
        <path d="M20 11a8 8 0 1 0-2.3 5.7" />
        <path d="M20 4v7h-7" />
    </Icon>
);

export const TruckIcon = (props) => (
    <Icon {...props}>
        <path d="M3 6h11v10H3zM14 9h4l3 3v4h-7" />
        <circle cx="7" cy="17.5" r="1.8" />
        <circle cx="17" cy="17.5" r="1.8" />
    </Icon>
);

export const ActivityIcon = (props) => (
    <Icon {...props}>
        <path d="M3 12h4l3-8 4 16 3-8h4" />
    </Icon>
);
