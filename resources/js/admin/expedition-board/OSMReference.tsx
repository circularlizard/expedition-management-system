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

type EventType = 'training' | 'practice' | 'qualifying';
type SortKey = 'name' | 'patrol' | 'first_aid' | 'training' | 'practice' | 'qualifying';
type SortDir = 'asc' | 'desc';

function faOrder(level?: FirstAidLevel): number {
    if (level === 'full_first_aid') return 2;
    if (level === 'first_response') return 1;
    return 0;
}

const FA_PILL_CLASS: Record<string, string> = {
    none: 'ems-pill ems-pill--fa-none',
    first_response: 'ems-pill ems-pill--fa-first-response',
    full_first_aid: 'ems-pill ems-pill--fa-full-first-aid',
};

function FaIcon({ level }: { level?: FirstAidLevel }) {
    if (level === 'full_first_aid') return <span title="Full First Aid" className="ems-fa-full">⊕</span>;
    if (level === 'first_response') return <span title="First Response" className="ems-fa-response">✚</span>;
    return null;
}

function FirstAidPill({ level }: { level?: FirstAidLevel }) {
    const l = level ?? 'none';
    const icon = l === 'first_response' ? '✚' : l === 'full_first_aid' ? '⊕' : null;
    return (
        <span className={FA_PILL_CLASS[l] ?? FA_PILL_CLASS.none}>
            {icon && <span>{icon}</span>}
            {FA_LABELS[l]}
        </span>
    );
}

interface EventAssignment {
    team_code: string;
    start_date: string;
    end_date: string;
    event_id: number;
    event_type: EventType;
}

interface ExplorerRow {
    explorer: Explorer;
    byType: Record<EventType, EventAssignment[]>;
}

function normaliseEventType(raw: string | undefined | null): EventType | null {
    switch (raw) {
        case 'training':   return 'training';
        case 'practice':   return 'practice';
        case 'qualifying':
        case 'qualifier':  return 'qualifying';
        default:           return null;
    }
}

function buildExplorerRows(data: BoardData): ExplorerRow[] {
    const byScout: Record<number, Record<EventType, EventAssignment[]>> = {};
    for (const season of data.seasons ?? []) {
        for (const event of season.events) {
            const eventType = normaliseEventType(event.ems_type);
            if (!eventType) continue;
            for (const team of event.teams) {
                for (const member of team.members ?? []) {
                    if (member.scout_id == null) continue;
                    if (!byScout[member.scout_id]) byScout[member.scout_id] = { training: [], practice: [], qualifying: [] };
                    byScout[member.scout_id][eventType].push({
                        team_code: team.ems_team_code,
                        start_date: event.ems_start_date,
                        end_date: event.ems_end_date,
                        event_id: event.ID,
                        event_type: eventType,
                    });
                }
            }
        }
    }
    return (data.explorers ?? []).map((explorer) => ({
        explorer,
        byType: byScout[explorer.scout_id] ?? { training: [], practice: [], qualifying: [] },
    }));
}

function formatShortDate(d: string): string {
    if (!d) return '';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

function formatTimestamp(d: string | null | undefined): string {
    if (!d) return '';
    const parts = d.split(' ')[0];
    return new Date(parts + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

function formatFullTimestamp(d: string | null | undefined): string {
    if (!d) return '';
    const isoStr = d.includes(' ') ? d.replace(' ', 'T') : d;
    try {
        const date = new Date(isoStr);
        if (isNaN(date.getTime())) return d;
        return date.toLocaleString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    } catch {
        return d;
    }
}


function EventCell({ assignments }: { assignments: EventAssignment[] }) {
    if (assignments.length === 0) return <span className="ems-osm-ref-event-cell--empty">—</span>;
    return (
        <div className="ems-osm-ref-event-list">
            {assignments.map((ev, i) => (
                <span key={i} className="ems-osm-ref-event-item">
                    <strong>{ev.team_code}</strong>
                    {(ev.start_date || ev.end_date) && (
                        <span className="ems-osm-ref-event-date">
                            {ev.start_date === ev.end_date
                                ? formatShortDate(ev.start_date)
                                : `${formatShortDate(ev.start_date)}–${formatShortDate(ev.end_date)}`}
                        </span>
                    )}
                </span>
            ))}
        </div>
    );
}

function SortHeader({ label, sortKey, active, dir, onSort }: {
    label: string; sortKey: SortKey; active: SortKey; dir: SortDir;
    onSort: (k: SortKey) => void;
}) {
    const isActive = active === sortKey;
    return (
        <th
            className="ems-osm-ref-col-header"
            onClick={() => onSort(sortKey)}
            aria-sort={isActive ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}
        >
            {label}{' '}
            <span className={`ems-osm-ref-col-sort ${isActive ? 'ems-osm-ref-col-sort--active' : 'ems-osm-ref-col-sort--inactive'}`}>
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
            const allAssignments = [
                ...row.byType.training,
                ...row.byType.practice,
                ...row.byType.qualifying,
            ];
            if (filterEvent === '__none__' && allAssignments.length > 0) return false;
            if (filterEvent === '__any__' && allAssignments.length === 0) return false;
            if (filterEvent && filterEvent !== '__none__' && filterEvent !== '__any__') {
                if (!allAssignments.some((a) => String(a.event_id) === filterEvent)) return false;
            }
            if (filterFa && (levels[row.explorer.scout_id] ?? 'none') !== filterFa) return false;
            return true;
        });
    }, [rows, filterEvent, filterFa, levels]);

    const sorted = useMemo(() => {
        return [...filtered].sort((a, b) => {
            let cmp = 0;
            if (sortKey === 'name') {
                cmp = `${a.explorer.first_name} ${a.explorer.last_name}`.localeCompare(
                    `${b.explorer.first_name} ${b.explorer.last_name}`,
                );
            } else if (sortKey === 'patrol') {
                cmp = (a.explorer.patrol ?? '').localeCompare(b.explorer.patrol ?? '');
            } else if (sortKey === 'first_aid') {
                cmp = faOrder(levels[a.explorer.scout_id]) - faOrder(levels[b.explorer.scout_id]);
            } else if (sortKey === 'training' || sortKey === 'practice' || sortKey === 'qualifying') {
                cmp = a.byType[sortKey].length - b.byType[sortKey].length;
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
        <div className="ems-osm-reference ems-osm-ref-container">
            <h2 className="ems-osm-ref-title">Explorer List</h2>

            {(data.explorers ?? []).length === 0 ? (
                <p>No explorers have been synced yet.</p>
            ) : (
                <>
                    <div className="ems-osm-ref-filter-bar">
                        <label className="ems-osm-ref-filter-label">Filter:</label>

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

                        <span className="ems-osm-ref-filter-count">
                            {sorted.length} of {rows.length} explorers
                        </span>
                    </div>

                    {sorted.length === 0 ? (
                        <p className="ems-osm-ref-empty">No explorers match the current filters.</p>
                    ) : (
                        <table className="widefat striped">
                            <thead>
                                <tr>
                                    <SortHeader label="Name" sortKey="name" active={sortKey} dir={sortDir} onSort={handleSort} />
                                    <SortHeader label="Patrol" sortKey="patrol" active={sortKey} dir={sortDir} onSort={handleSort} />
                                    <SortHeader label="First Aid" sortKey="first_aid" active={sortKey} dir={sortDir} onSort={handleSort} />
                                    <SortHeader label="Training" sortKey="training" active={sortKey} dir={sortDir} onSort={handleSort} />
                                    <SortHeader label="Practice" sortKey="practice" active={sortKey} dir={sortDir} onSort={handleSort} />
                                    <SortHeader label="Qualifying" sortKey="qualifying" active={sortKey} dir={sortDir} onSort={handleSort} />
                                    <th title="Last OSM sync" className="ems-osm-ref-col-header--small">Synced</th>
                                    <th title="Last local edit" className="ems-osm-ref-col-header--small">Edited</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sorted.map(({ explorer, byType }) => (
                                    <tr key={explorer.scout_id}>
                                        <td>
                                            <span className="ems-osm-ref-name">
                                                <FaIcon level={levels[explorer.scout_id] ?? 'none'} />
                                                {explorer.first_name} {explorer.last_name}
                                            </span>
                                        </td>
                                        <td>{explorer.patrol || '—'}</td>
                                        <td>
                                            <div className="ems-osm-ref-fa-cell">
                                                <select
                                                    aria-label={`First aid level for ${explorer.first_name} ${explorer.last_name}`}
                                                    value={levels[explorer.scout_id] ?? 'none'}
                                                    onChange={(e) => updateLevel(explorer, e.target.value as FirstAidLevel)}
                                                    disabled={saving[explorer.scout_id]}
                                                    className="ems-select-sm"
                                                >
                                                    {(Object.keys(FA_LABELS) as FirstAidLevel[]).map((level) => (
                                                        <option key={level} value={level}>{FA_LABELS[level]}</option>
                                                    ))}
                                                </select>
                                                {errors[explorer.scout_id] && (
                                                    <span className="ems-osm-ref-fa-error">{errors[explorer.scout_id]}</span>
                                                )}
                                            </div>
                                        </td>
                                        <td><EventCell assignments={byType.training} /></td>
                                        <td><EventCell assignments={byType.practice} /></td>
                                        <td><EventCell assignments={byType.qualifying} /></td>
                                        <td title={formatFullTimestamp(explorer.synced_at) || 'Not synced'}>
                                            <span className="ems-osm-ref-meta">
                                                {formatTimestamp(explorer.synced_at) || '—'}
                                            </span>
                                        </td>
                                        <td title={formatFullTimestamp(explorer.last_local_update_at) || 'No local edits'}>
                                            <span className="ems-osm-ref-meta">
                                                {formatTimestamp(explorer.last_local_update_at) || '—'}
                                            </span>
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
