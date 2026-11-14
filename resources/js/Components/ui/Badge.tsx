import { ReactNode } from 'react';
import { StatusKepatuhan, STATUS_KEPATUHAN_CONFIG } from '@/types';

interface BadgeProps {
    status: StatusKepatuhan;
    className?: string;
}

export function StatusBadge({ status, className = '' }: BadgeProps) {
    const config = STATUS_KEPATUHAN_CONFIG[status] || STATUS_KEPATUHAN_CONFIG['Belum Ada Jadwal'];

    return (
        <span
            className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border ${config.bgColor} ${config.color} ${config.borderColor} ${className}`}
        >
            {config.icon && <span className="text-[10px]">{config.icon}</span>}
            {status}
        </span>
    );
}
