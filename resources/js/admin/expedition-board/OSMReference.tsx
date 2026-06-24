import React, { useState, useEffect, useMemo } from 'react';
import { BoardData, Expedition, Explorer, FirstAidLevel } from './types';

interface OSMReferenceProps {
    data: BoardData;
    onChanged?: () => void;
}

const FA_LABELS: Record<FirstAidLevel, string> = {
    none: 'None',
    first_response: 'First Response',
    full_first_aid: 'Full First Aid',
};

type SortKey = 'name' | 'patrol' | 'first_aid' | 'events';
type SortDir = 'asc' | 'desc';

function faOrder(level?: FirstAidLevel): number {
    if (level === 'full_first_aid') return 2;
    if (level === 'first_response') return 1;
    return 0;
}

function pillStyle(bg: string, color: string): React.CSSProperties {
    return {
        display: 'inline-flex', alignItems: 'center', gap: '2px',
        fontSize: '11px', fontWeight: 600, padding: '2px 8px',
        borderRadius: '12px', background: bg, color,
    };
}

const FA_PILL_COLORS: Record<string, { bg: string; color: string }> = {
    none: { bg: '#f5f5f5', color: '#666' },
    first_response: { bg: '#e8f5e9', color: '#2e7d32' },
    full_first_aid: { bg: '#c8e6c9', color: '#1b5e20' },
};

function FirstAidPill({ level }: { level?: FirstAidLevel }) {
    const l = level ?? 'none';
    const { bg, color } = FA_PILL_COLORS[l] ?? FA_PILL_COLORS.none;
    const icon = l === 'first_response' ? '✚' : l === 'full_first_aid' ? '⊕' : null;
    return (
        <span style={pillStyle(bg, color)}>
            {icon && <span>{icon}</span>}
            {FA_LABELS[l]}
        </span>
    );
}

interface ExplorerRow {
    explorer: Explorer;
    events: { team_code: string; start_date: string; end_date: string; event_id: number }[];
}

function buildExplorerRows(data: BoardData): ExplorerRow[] {
    const eventsByScout: Record<number, ExplorerRow['events']> = {};
    for (const season of data.seasons ?? []) {
        for (const event of season.events) {
            for (const team of event.teams) {
                for (const member of team.members ?? []) {
                    if (member.scout_id == null) continue;
                    if (!eventsByScout[member.scout_id]) eventsByScout[member.scout_id] = [];
                    eventsByScout[member.scout_id].push({
                        team_code: team.ems_team_code,
                        start_date: event.ems_start_date,
                        end_date: event.ems_end_date,
                        event_id: event.ID,
                    });
                }
            }
        }
    }
    return (data.explorers ?? []).map((explorer) => ({
        explorer,
        events: eventsByScout[explorer.scout_id] ?? [],
    }));
}

function formatShortDate(d: string): string {
    if (!d) return '';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

function SortHeader({ label, sortKey, active, dir, onSort }: {
    label: string; sortKey: SortKey; active: SortKey; dir: SortDir;
    onSort: (k: SortKey) => void;
}) {
    const isActive = active === sortKey;
    return (
        <th
            style={{ cursor: 'pointer', userSelect: 'none', whiteSpace: 'nowrap' }}
            onClick={() => onSort(sortKey)}
            aria-sort={isActive ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}
        >
            {label}{' '}
            <span style={{ fontSize: '10px', opacity: isActive ? 1 : 0.35 }}>
                {isActive ? (dir === 'asc' ? '▲' : '▼') : '▲'}
            </span>
        </th>
    );
}

export const OSMReference: React.FC<OSMReferenceProps> = ({ data, onChanged }) => {
    const [levels, setLevels] = useState<Record<number, FirstAidLevel>>({});
    const [saving, setSaving] = useState<Record<number, boolean>>({});
    const [errors, setErrors] = useState<Record<number, string>>({});

    const [filterEvent, setFilterEvent] = useState<string>('');
    const [filterFa, setFilterFa] = useState<string>('');
    const [sortKey, setSortKey] = useState<SortKey>('name');
    const [sortDir, setSortDir] = useState<SortDir>('asc');

    const config = window.emsExpeditionBoard;

    useEffect(() => {
        const next: Record<number, FirstAidLevel> = {};
        for (const explorer of data.explorers ?? []) {
            next[explorer.scout_id] = explorer.first_aid_level ?? 'none';
        }
        setLevels(next);
    }, [data.explorers]);

    const allEvents: Expedition[] = useMemo(
        () => (data.seasons ?? []).flatMap((s) => s.events),
        [data.seasons],
    );

    const rows = useMemo(() => buildExplorerRows(data), [data]);

    const filtered = useMemo(() => {
        return rows.filter((row) => {
            if (filterEvent === '__none__' && row.events.length > 0) return false;
            if (filterEvent === '__any__' && row.events.length === 0) return false;
            if (filterEvent && filterEvent !== '__none__' && filterEvent !== '__any__') {
                if (!row.events.some((e) => String(e.event_id) === filterEvent)) return false;
            }
            if (filterFa && (levels[row.explorer.scout_id] ?? 'none') !== filterFa) return false;
            return true;
        });
    }, [rows, filterEvent, filterFa, levels]);

    const sorted = useMemo(() => {
        return [...filtered].sort((a, b) => {
            let cmp = 0;
            if (sortKey === 'name') {
                cmp = `${a.explorer.last_name} ${a.explorer.first_name}`.localeCompare(
                    `${b.explorer.last_name} ${b.explorer.first_name}`,
                );
            } else if (sortKey === 'patrol') {
                cmp = (a.explorer.patrol ?? '').localeCompare(b.explorer.patrol ?? '');
            } else if (sortKey === 'first_aid') {
                cmp = faOrder(levels[a.explorer.scout_id]) - faOrder(levels[b.explorer.scout_id]);
            } else if (sortKey === 'events') {
                cmp = a.events.length - b.events.length;
            }
            return sortDir === 'asc' ? cmp : -cmp;
        });
    }, [filtered, sortKey, sortDir, levels]);

    const handleSort = (key: SortKey) => {
        if (sortKey === key) {
            setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSortKey(key);
            setSortDir('asc');
        }
    };

    const updateLevel = async (explorer: Explorer, level: FirstAidLevel) => {
        if (levels[explorer.scout_id] === level) return;
        setLevels((prev) => ({ ...prev, [explorer.scout_id]: level }));
        setSaving((prev) => ({ ...prev, [explorer.scout_id]: true }));
        setErrors((prev) => ({ ...prev, [explorer.scout_id]: '' }));
        try {
            const response = await fetch(`${config.root_url}/explorers/${explorer.scout_id}/first-aid`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: JSON.stringify({ first_aid_level: level }),
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            onChanged?.();
        } catch (e) {
            const message = e instanceof Error ? e.message : 'Failed to save';
            setErrors((prev) => ({ ...prev, [explorer.scout_id]: message }));
            setLevels((prev) => ({ ...prev, [explorer.scout_id]: explorer.first_aid_level ?? 'none' }));
        } finally {
            setSaving((prev) => ({ ...prev, [explorer.scout_id]: false }));
        }
    };

    const hasFilters = filterEvent || filterFa;

    return (
        <div className="ems-osm-reference" style={{ padding: '12px', background: '#fff' }}>
            <h2 style={{ marginTop: 0 }}>Explorer List</h2>

            {(data.explorers ?? []).length === 0 ? (
                <p>No explorers have been synced yet.</p>
            ) : (
                <>
                    <div style={{ display: 'flex', gap: '12px', flexWrap: 'wrap', alignItems: 'center', marginBottom: '16px', padding: '10px 12px', background: '#f9f9f9', border: '1px solid #ddd' }}>
                        <label style={{ fontWeight: 600, fontSize: '13px' }}>Filter:</label>

                        <select
                            aria-label="Filter by event"
                            value={filterEvent}
                            onChange={(e) => setFilterEvent(e.target.value)}
                        >
                            <option value="">All explorers</option>
                            <option value="__any__">In any event</option>
                            <option value="__none__">In no event</option>
                            {allEvents.map((ev) => (
                                <option key={ev.ID} value={String(ev.ID)}>
                                    {ev.ems_event_code} — {ev.post_title}
                                </option>
                            ))}
                        </select>

                        <select
                            aria-label="Filter by first aid"
                            value={filterFa}
                            onChange={(e) => setFilterFa(e.target.value)}
                        >
                            <option value="">All first aid levels</option>
                            <option value="none">None</option>
                            <option value="first_response">✚ First Response</option>
                            <option value="full_first_aid">⊕ Full First Aid</option>
                        </select>

                        {hasFilters && (
                            <button
                                type="button"
                                className="button-link"
                                onClick={() => { setFilterEvent(''); setFilterFa(''); }}
                            >
                                Clear filters
                            </button>
                        )}

                        <span style={{ marginLeft: 'auto', fontSize: '12px', color: '#666' }}>
                            {sorted.length} of {rows.length} explorers
                        </span>
                    </div>

                    {sorted.length === 0 ? (
                        <p style={{ color: '#666' }}>No explorers match the current filters.</p>
                    ) : (
                        <table className="widefat striped" style={{ fontSize: '13px' }}>
                            <thead>
                                <tr>
                                    <SortHeader label="Name" sortKey="name" active={sortKey} dir={sortDir} onSort={handleSort} />
                                    <SortHeader label="Patrol" sortKey="patrol" active={sortKey} dir={sortDir} onSort={handleSort} />
                                    <SortHeader label="First Aid" sortKey="first_aid" active={sortKey} dir={sortDir} onSort={handleSort} />
                                    <SortHeader label="Events" sortKey="events" active={sortKey} dir={sortDir} onSort={handleSort} />
                                </tr>
                            </thead>
                            <tbody>
                                {sorted.map(({ explorer, events }) => (
                                    <tr key={explorer.scout_id}>
                                        <td style={{ fontWeight: 500 }}>
                                            {explorer.first_name} {explorer.last_name}
                                        </td>
                                        <td>{explorer.patrol || '—'}</td>
                                        <td>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                                <FirstAidPill level={levels[explorer.scout_id] ?? 'none'} />
                                                <select
                                                    aria-label={`First aid level for ${explorer.first_name} ${explorer.last_name}`}
                                                    value={levels[explorer.scout_id] ?? 'none'}
                                                    onChange={(e) => updateLevel(explorer, e.target.value as FirstAidLevel)}
                                                    disabled={saving[explorer.scout_id]}
                                                    style={{ fontSize: '12px' }}
                                                >
                                                    {(Object.keys(FA_LABELS) as FirstAidLevel[]).map((level) => (
                                                        <option key={level} value={level}>{FA_LABELS[level]}</option>
                                                    ))}
                                                </select>
                                                {errors[explorer.scout_id] && (
                                                    <span style={{ color: '#d63638', fontSize: '11px' }}>{errors[explorer.scout_id]}</span>
                                                )}
                                            </div>
                                        </td>
                                        <td>
                                            {events.length === 0 ? (
                                                <span style={{ color: '#aaa', fontSize: '12px' }}>—</span>
                                            ) : (
                                                <div style={{ display: 'flex', flexDirection: 'column', gap: '2px' }}>
                                                    {events.map((ev, i) => (
                                                        <span key={i} style={{ fontSize: '12px', whiteSpace: 'nowrap' }}>
                                                            <strong>{ev.team_code}</strong>
                                                            {(ev.start_date || ev.end_date) && (
                                                                <span style={{ color: '#666', marginLeft: '4px' }}>
                                                                    {ev.start_date === ev.end_date
                                                                        ? formatShortDate(ev.start_date)
                                                                        : `${formatShortDate(ev.start_date)}–${formatShortDate(ev.end_date)}`}
                                                                </span>
                                                            )}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </>
            )}
        </div>
    );
};

export default OSMReference;
