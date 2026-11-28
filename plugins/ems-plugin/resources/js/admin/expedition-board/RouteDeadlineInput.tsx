import React, { useState, useEffect } from 'react';

const MS_PER_DAY = 24 * 60 * 60 * 1000;

function parseIsoDateUtc(dateStr?: string): number | null {
    if (!dateStr) return null;
    const match = dateStr.trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return null;
    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const ms = Date.UTC(year, month - 1, day);
    const check = new Date(ms);
    if (
        check.getUTCFullYear() !== year ||
        check.getUTCMonth() !== month - 1 ||
        check.getUTCDate() !== day
    ) {
        return null;
    }
    return ms;
}

/**
 * Computes the non-negative number of days between deadlineDate and startDate.
 * Returns '' if either date is invalid or deadlineDate is after startDate.
 */
export function computeOffsetDays(startDate?: string, deadlineDate?: string): string {
    const startMs = parseIsoDateUtc(startDate);
    const deadlineMs = parseIsoDateUtc(deadlineDate);
    if (startMs === null || deadlineMs === null) return '';
    const diff = Math.round((startMs - deadlineMs) / MS_PER_DAY);
    return diff >= 0 ? String(diff) : '';
}

/**
 * Subtracts offsetDays from startDate in UTC and returns YYYY-MM-DD (or '' if invalid).
 */
export function computeDeadlineFromOffset(startDate?: string, offsetDays?: string): string {
    const startMs = parseIsoDateUtc(startDate);
    if (startMs === null || offsetDays === undefined || offsetDays.trim() === '') return '';
    const parsed = Number(offsetDays);
    if (!Number.isFinite(parsed) || parsed < 0) return '';
    const days = Math.floor(parsed);
    const target = new Date(startMs - days * MS_PER_DAY);
    return target.toISOString().slice(0, 10);
}

/**
 * Formats a route deadline date with its days-prior offset when startDate is available.
 */
export function formatRouteDeadlineWithOffset(
    deadlineDate: string | undefined,
    startDate: string | undefined,
    formatDateFn: (d?: string) => string
): string {
    if (!deadlineDate) return '—';
    const formatted = formatDateFn(deadlineDate);
    const offset = computeOffsetDays(startDate, deadlineDate);
    if (offset === '') return formatted;
    const n = Number(offset);
    return `${formatted} (${n} day${n === 1 ? '' : 's'} prior)`;
}

interface RouteDeadlineInputProps {
    startDate?: string;
    deadline: string;
    onChange: (nextDeadline: string) => void;
    className?: string;
}

/**
 * Dual input allowing a route deadline to be configured by concrete date or days prior to start.
 */
export const RouteDeadlineInput: React.FC<RouteDeadlineInputProps> = ({
    startDate,
    deadline,
    onChange,
    className = '',
}) => {
    const [pendingOffset, setPendingOffset] = useState<string>('');

    useEffect(() => {
        if (startDate && pendingOffset !== '' && !deadline) {
            const computed = computeDeadlineFromOffset(startDate, pendingOffset);
            if (computed) {
                onChange(computed);
                setPendingOffset('');
            }
        }
    }, [startDate, pendingOffset, deadline, onChange]);

    const derivedOffset = startDate ? computeOffsetDays(startDate, deadline) : pendingOffset;

    const handleDateChange = (nextDate: string) => {
        setPendingOffset('');
        onChange(nextDate);
    };

    const handleOffsetChange = (rawOffset: string) => {
        if (rawOffset === '') {
            setPendingOffset('');
            onChange('');
            return;
        }
        const cleaned = rawOffset.replace(/[^\d]/g, '');
        if (!startDate) {
            setPendingOffset(cleaned);
            return;
        }
        const computed = computeDeadlineFromOffset(startDate, cleaned);
        onChange(computed);
    };

    return (
        <div className={`ems-form-group ${className}`.trim()}>
            <div className="ems-form-label">Route Deadline</div>
            <div style={{ display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap' }}>
                <input
                    type="date"
                    aria-label="Route Deadline Date"
                    value={deadline}
                    onChange={(e) => handleDateChange(e.target.value)}
                    className="ems-form-input"
                    style={{ flex: '1 1 150px' }}
                />
                <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flex: '0 1 170px' }}>
                    <input
                        type="number"
                        min={0}
                        step={1}
                        aria-label="Days Prior to Start"
                        placeholder="Days prior"
                        value={derivedOffset}
                        onChange={(e) => handleOffsetChange(e.target.value)}
                        className="ems-form-input"
                        style={{ width: '90px' }}
                    />
                    <span className="ems-meta-text" style={{ whiteSpace: 'nowrap' }}>
                        days prior
                    </span>
                </div>
            </div>
            {!startDate && (
                <span className="ems-meta-text">Set start date to calculate due date from offset.</span>
            )}
        </div>
    );
};

export default RouteDeadlineInput;
