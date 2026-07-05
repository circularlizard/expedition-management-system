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
            {icon && <span style={{ marginRight: '4px' }}>{icon}</span>}
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
            style={{ cursor: 'pointer' }}
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

    // Selected explorer ID for the right-hand Profile Inspector
    const [selectedScoutId, setSelectedScoutId] = useState<number | null>(null);

    // Profile details fetched on demand
    const [profileData, setProfileData] = useState<any | null>(null);
    const [profileLoading, setProfileLoading] = useState<boolean>(false);
    const [profileError, setProfileError] = useState<string | null>(null);

    // Confidential Leaders' notes editor state
    const [editedNotes, setEditedNotes] = useState<string>('');
    const [notesSaving, setNotesSaving] = useState<boolean>(false);
    const [notesError, setNotesError] = useState<string | null>(null);

    const config = window.emsExpeditionBoard || { root_url: '', nonce: '' };

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

    // Fetch Profile details when selected explorer changes
    useEffect(() => {
        if (!selectedScoutId) {
            setProfileData(null);
            return;
        }

        const fetchProfile = async () => {
            setProfileLoading(true);
            setProfileError(null);
            try {
                const response = await fetch(`${config.root_url}/explorers/${selectedScoutId}/profile`, {
                    headers: { 'X-WP-Nonce': config.nonce }
                });
                if (!response.ok) {
                    throw new Error(`Failed to load profile (HTTP ${response.status})`);
                }
                const resData = await response.json();
                setProfileData(resData);
                setEditedNotes(resData.organiser_notes || '');
            } catch (err: any) {
                setProfileError(err.message || 'Error fetching profile data');
            } finally {
                setProfileLoading(false);
            }
        };

        fetchProfile();
    }, [selectedScoutId, config.root_url, config.nonce]);

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

    const handleSaveNotes = async () => {
        if (!selectedScoutId) return;
        setNotesSaving(true);
        setNotesError(null);
        try {
            const response = await fetch(`${config.root_url}/explorers/${selectedScoutId}/asn`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({ organiser_notes: editedNotes })
            });
            if (!response.ok) throw new Error(`Failed to save notes (HTTP ${response.status})`);
            
            setProfileData((prev: any) => prev ? { ...prev, organiser_notes: editedNotes } : null);
            onChanged?.();
        } catch (err: any) {
            setNotesError(err.message || 'Failed to save notes');
        } finally {
            setNotesSaving(false);
        }
    };

    // Navigation pagination helper
    const selectedIndex = sorted.findIndex(row => row.explorer.scout_id === selectedScoutId);
    const hasPrev = selectedIndex > 0;
    const hasNext = selectedIndex !== -1 && selectedIndex < sorted.length - 1;

    const handlePrev = () => {
        if (hasPrev) {
            setSelectedScoutId(sorted[selectedIndex - 1].explorer.scout_id);
        }
    };

    const handleNext = () => {
        if (hasNext) {
            setSelectedScoutId(sorted[selectedIndex + 1].explorer.scout_id);
        }
    };

    const hasFilters = filterEvent || filterFa;

    return (
        <div className="ems-signups-container">
            <div className="ems-signups-main ems-osm-reference ems-osm-ref-container">
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
                            <table className="widefat striped ems-table">
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
                                    {sorted.map(({ explorer, byType }) => {
                                        const isSelected = explorer.scout_id === selectedScoutId;
                                        return (
                                            <tr 
                                                key={explorer.scout_id} 
                                                onClick={() => setSelectedScoutId(explorer.scout_id)}
                                                className={`ems-row-hoverable ${isSelected ? 'ems-row-selected' : ''}`}
                                                style={{ cursor: 'pointer' }}
                                            >
                                                <td>
                                                    <span className="ems-osm-ref-name">
                                                        <FaIcon level={levels[explorer.scout_id] ?? 'none'} />
                                                        {explorer.first_name} {explorer.last_name}
                                                    </span>
                                                </td>
                                                <td>{explorer.patrol || '—'}</td>
                                                <td>
                                                    <FirstAidPill level={levels[explorer.scout_id]} />
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
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}
                    </>
                )}
            </div>

            {/* Profile Inspector slide-out panel */}
            {selectedScoutId && (
                <div className="ems-signups-inspector">
                    <div className="ems-signups-inspector__header">
                        <h3 className="ems-signups-inspector__title">Explorer Profile</h3>
                        <div className="ems-flex-center ems-gap-6">
                            <button
                                type="button"
                                onClick={handlePrev}
                                disabled={!hasPrev}
                                className="button button-small"
                                aria-label="<"
                            >
                                &lt;
                            </button>
                            <button
                                type="button"
                                onClick={handleNext}
                                disabled={!hasNext}
                                className="button button-small"
                                aria-label=">"
                            >
                                &gt;
                            </button>
                            <button
                                type="button"
                                onClick={() => setSelectedScoutId(null)}
                                className="button-link"
                                aria-label="&times;"
                                style={{ fontSize: '20px', marginLeft: '10px', textDecoration: 'none', border: 'none', background: 'none', cursor: 'pointer' }}
                            >
                                &times;
                            </button>
                        </div>
                    </div>

                    <div className="ems-signups-inspector__body">
                        {profileLoading && <p className="ems-p-16">Loading profile...</p>}
                        {profileError && (
                            <div className="notice notice-error ems-m-12">
                                <p>{profileError}</p>
                            </div>
                        )}

                        {!profileLoading && !profileError && profileData && (
                            <div className="ems-flex-col ems-gap-12">
                                {/* Name */}
                                <div>
                                    <span className="ems-signups-inspector__label">Name</span>
                                    <div className="ems-signups-inspector__value--large">
                                        {profileData.first_name} {profileData.last_name}
                                    </div>
                                </div>

                                {/* Scout ID */}
                                <div>
                                    <span className="ems-signups-inspector__label">Scout ID</span>
                                    <div className="ems-signups-inspector__value ems-monospace ems-font-semibold">
                                        {profileData.scout_id}
                                    </div>
                                </div>

                                {/* Email */}
                                <div>
                                    <span className="ems-signups-inspector__label">Email Address</span>
                                    <div className="ems-signups-inspector__value">{profileData.email || '—'}</div>
                                </div>

                                {/* Unit info */}
                                <div className="ems-grid-2 ems-gap-12">
                                    <div>
                                        <span className="ems-signups-inspector__label">Unit</span>
                                        <div className="ems-signups-inspector__value">{profileData.unit || '—'}</div>
                                    </div>
                                    <div>
                                        <span className="ems-signups-inspector__label">Unit Leader Email</span>
                                        <div className="ems-signups-inspector__value">{profileData.leader_email || '—'}</div>
                                    </div>
                                </div>

                                {/* First aid level selector */}
                                <div>
                                    <label htmlFor="inspector-fa-level" className="ems-signups-inspector__label">First Aid Level</label>
                                    <div className="ems-mt-4">
                                        <select
                                            id="inspector-fa-level"
                                            aria-label="First Aid Level"
                                            value={levels[selectedScoutId] ?? 'none'}
                                            onChange={(e) => updateLevel(sorted.find(r => r.explorer.scout_id === selectedScoutId)!.explorer, e.target.value as FirstAidLevel)}
                                            disabled={saving[selectedScoutId]}
                                            className="ems-select"
                                            style={{ width: '100%', maxWidth: '240px' }}
                                        >
                                            {(Object.keys(FA_LABELS) as FirstAidLevel[]).map((level) => (
                                                <option key={level} value={level}>{FA_LABELS[level]}</option>
                                            ))}
                                        </select>
                                        {errors[selectedScoutId] && (
                                            <div className="ems-osm-ref-fa-error ems-mt-4">{errors[selectedScoutId]}</div>
                                        )}
                                    </div>
                                </div>

                                <hr style={{ borderTop: '1px solid #ccd0d4', margin: '8px 0' }} />

                                {/* Event status matrix */}
                                <div>
                                    <span className="ems-signups-inspector__label">Training Events</span>
                                    <div className="ems-mt-4">
                                        {profileData.training_events && profileData.training_events.length > 0 ? (
                                            <ul style={{ margin: '4px 0 0 0', paddingLeft: '20px' }}>
                                                {profileData.training_events.map((ev: any, idx: number) => (
                                                    <li key={idx}>
                                                        <strong>{ev.event_title} ({ev.team_code})</strong> — Status: <i>{ev.osm_status}</i>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : <div className="ems-signups-inspector__value">—</div>}
                                    </div>
                                </div>

                                <div>
                                    <span className="ems-signups-inspector__label">Practice Events</span>
                                    <div className="ems-mt-4">
                                        {profileData.practice_events && profileData.practice_events.length > 0 ? (
                                            <ul style={{ margin: '4px 0 0 0', paddingLeft: '20px' }}>
                                                {profileData.practice_events.map((ev: any, idx: number) => (
                                                    <li key={idx}>
                                                        <strong>{ev.event_title} ({ev.team_code})</strong> — Status: <i>{ev.osm_status}</i>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : <div className="ems-signups-inspector__value">—</div>}
                                    </div>
                                </div>

                                <div>
                                    <span className="ems-signups-inspector__label">Qualifiers Events</span>
                                    <div className="ems-mt-4">
                                        {profileData.qualifiers_events && profileData.qualifiers_events.length > 0 ? (
                                            <ul style={{ margin: '4px 0 0 0', paddingLeft: '20px' }}>
                                                {profileData.qualifiers_events.map((ev: any, idx: number) => (
                                                    <li key={idx}>
                                                        <strong>{ev.event_title} ({ev.team_code})</strong> — Status: <i>{ev.osm_status}</i>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : <div className="ems-signups-inspector__value">—</div>}
                                    </div>
                                </div>

                                <hr style={{ borderTop: '1px solid #ccd0d4', margin: '8px 0' }} />

                                {/* Additional support needs (ASN) */}
                                <div>
                                    <span className="ems-signups-inspector__label">Parent Additional Support Needs</span>
                                    <div className="ems-mt-4 ems-signups-inspector__support-box" style={{ background: '#f6f7f7', padding: '8px', borderRadius: '4px' }}>
                                        {profileData.parent_asn || 'No support needs declared by parent.'}
                                    </div>
                                </div>

                                <div>
                                    <label htmlFor="confidential-notes" className="ems-signups-inspector__label">Confidential Leaders' Notes</label>
                                    <textarea
                                        id="confidential-notes"
                                        className="ems-signups-inspector__input ems-mt-4"
                                        style={{ width: '100%' }}
                                        rows={4}
                                        value={editedNotes}
                                        onChange={(e) => setEditedNotes(e.target.value)}
                                        placeholder="Add private leader notes here..."
                                    />
                                    {notesError && <div className="ems-osm-ref-fa-error ems-mt-4">{notesError}</div>}
                                    <button
                                        type="button"
                                        className="button button-primary ems-mt-4"
                                        onClick={handleSaveNotes}
                                        disabled={notesSaving}
                                    >
                                        {notesSaving ? 'Saving...' : 'Save Confidential Notes'}
                                    </button>
                                </div>

                                <hr style={{ borderTop: '1px solid #ccd0d4', margin: '8px 0' }} />

                                {/* Expedition Preferences */}
                                <div>
                                    <span className="ems-signups-inspector__label">Expedition Signup Preferences</span>
                                    {profileData.preferences ? (
                                        <div className="ems-mt-4 ems-flex-col ems-gap-6">
                                            <div>
                                                <strong>Practice dates:</strong> {profileData.preferences.exped_practice_dates || '—'}
                                            </div>
                                            <div>
                                                <strong>Qualifier dates:</strong> {profileData.preferences.exped_qualifier_dates || '—'}
                                            </div>
                                            <div>
                                                <strong>Buddy preferences:</strong> {profileData.preferences.exped_team_names || '—'}
                                            </div>
                                            <div>
                                                <strong>Type:</strong> {profileData.preferences.exped_type || '—'}
                                            </div>
                                        </div>
                                    ) : <div className="ems-signups-inspector__value">—</div>}
                                </div>

                                <hr style={{ borderTop: '1px solid #ccd0d4', margin: '8px 0' }} />

                                {/* Tutor LMS training records */}
                                <div>
                                    <span className="ems-signups-inspector__label">Tutor LMS Training Records</span>
                                    {profileData.training_records && profileData.training_records.length > 0 ? (
                                        <table className="widefat striped ems-table ems-mt-4" style={{ border: '1px solid #ccd0d4' }}>
                                            <thead>
                                                <tr>
                                                    <th>Course Title</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {profileData.training_records.map((rec: any) => (
                                                    <tr key={rec.id}>
                                                        <td>{rec.title}</td>
                                                        <td>
                                                            <span className={`ems-status-badge ems-status-badge--${rec.status}`}>
                                                                {rec.status}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    ) : <div className="ems-signups-inspector__value">—</div>}
                                </div>

                                <hr style={{ borderTop: '1px solid #ccd0d4', margin: '8px 0' }} />

                                {/* Participant place signups */}
                                <div>
                                    <span className="ems-signups-inspector__label">Participant Place Signups</span>
                                    {profileData.participant_signups && profileData.participant_signups.length > 0 ? (
                                        <table className="widefat striped ems-table ems-mt-4" style={{ border: '1px solid #ccd0d4' }}>
                                            <thead>
                                                <tr>
                                                    <th>Level</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th>Link</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {profileData.participant_signups.map((sup: any) => (
                                                    <tr key={sup.id}>
                                                        <td>{sup.dofe_level}</td>
                                                        <td>{formatTimestamp(sup.created_at)}</td>
                                                        <td>
                                                            <span className={`ems-status-badge ems-status-badge--${sup.signup_status}`}>
                                                                {sup.signup_status}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <a href={`admin.php?page=ems-participant-signups&id=${sup.id}&status=${sup.signup_status}`} target="_blank" rel="noreferrer">
                                                                View
                                                            </a>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    ) : <div className="ems-signups-inspector__value">—</div>}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
};

export default OSMReference;
