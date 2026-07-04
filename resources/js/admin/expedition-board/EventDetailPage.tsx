import React, { useState, useEffect, useCallback } from 'react';
import { Expedition, Team, Member, Explorer, FirstAidLevel, OSMEvent } from './types';
import { EventForm } from './EventForm';
import { OSMReadOnlyMap } from './OSMReadOnlyMap';

interface EventDetailPageProps {
    event: Expedition;
    onBack: () => void;
    explorers?: Explorer[];
    osmEvents?: OSMEvent[];
    allEvents?: Expedition[];
    onEventUpdated?: (updated: Expedition) => void;
}

type DetailTab = 'overview' | 'teams' | 'training' | 'asn' | 'qrcodes';

const FA_LABELS: Record<FirstAidLevel, string> = {
    none: 'None',
    first_response: 'First Response',
    full_first_aid: 'Full First Aid',
};

function formatDate(d?: string): string {
    if (!d) return '—';
    try { return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); } catch { return d; }
}

function capitalize(s: string): string {
    return s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : '—';
}

function statusBadge(status?: string): React.ReactNode {
    const s = status || 'active';
    const modifier = s === 'archived' ? 'ems-status-badge--archived' : 'ems-status-badge--active';
    return (
        <span className={`ems-status-badge ${modifier}`}>{s}</span>
    );
}

async function apiFetch(path: string, options?: RequestInit): Promise<Response> {
    const config = window.emsExpeditionBoard;
    return fetch(`${config.root_url}${path}`, {
        ...options,
        headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json', ...(options?.headers ?? {}) },
    });
}

const FieldVal: React.FC<{ label: string; value?: React.ReactNode }> = ({ label, value }) => (
    <div className="ems-meta-field">
        <div className="ems-meta-field__label">{label}</div>
        <div className={value ? 'ems-meta-field__value' : 'ems-meta-field__value ems-meta-field__value--empty'}>{value || '—'}</div>
    </div>
);

const LocationDisplay: React.FC<{ value?: string }> = ({ value }) => {
    const [resolvedName, setResolvedName] = useState<string>('');
    const [loading, setLoading] = useState(false);

    const coordsMatch = value ? value.trim().match(/^(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)$/) : null;

    useEffect(() => {
        if (!coordsMatch) {
            setResolvedName('');
            return;
        }
        const lat = parseFloat(coordsMatch[1]);
        const lng = parseFloat(coordsMatch[2]);
        setLoading(true);
        fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`, {
            headers: { 'Accept-Language': 'en' }
        })
            .then(res => {
                if (res.ok) return res.json();
                throw new Error();
            })
            .then(data => {
                const addr = data.address;
                const name = addr.road || addr.suburb || addr.town || addr.city || addr.county || '';
                const county = addr.county || addr.state || '';
                setResolvedName(name ? (county ? `${name}, ${county}` : name) : '');
            })
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [value]);

    if (!value) return <span>—</span>;

    if (!coordsMatch) {
        return <span>{value}</span>;
    }

    const lat = coordsMatch[1];
    const lng = coordsMatch[2];
    const mapUrl = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=15/${lat}/${lng}`;

    return (
        <div>
            <a href={mapUrl} target="_blank" rel="noopener noreferrer" className="ems-detail-link">
                {lat}, {lng} ↗
            </a>
            {loading && <div className="ems-detail-link-loading">Resolving address…</div>}
            {!loading && resolvedName && (
                <div className="ems-detail-link-resolved">
                    {resolvedName}
                </div>
            )}
        </div>
    );
};

function grd(cols: number): string {
    return cols === 4 ? 'ems-form-grid-4' : cols === 3 ? 'ems-form-grid-3' : 'ems-form-grid-2';
}

/* Overview */
const OverviewTab: React.FC<{ event: Expedition; osmEvents?: OSMEvent[]; onUpdated?: (e: Expedition) => void }> = ({ event, osmEvents = [], onUpdated }) => {
    const [editing, setEditing] = useState(false);
    return (
        <div>
            <div className="ems-edit-bar">
                <button className="button" onClick={() => setEditing((v) => !v)}>{editing ? 'Cancel' : 'Edit Event'}</button>
            </div>
            {editing ? (
                <EventForm seasonId={0} initialEvent={event} osmEvents={osmEvents} onSaved={(u) => { setEditing(false); onUpdated?.(u); }} onCancel={() => setEditing(false)} />
            ) : (
                <>
                    <div className="ems-section"><div className="ems-section__header">Event Details</div>
                        <div className={grd(4)}>
                            <FieldVal label="Type" value={capitalize(event.ems_type)} />
                            <FieldVal label="Transport" value={capitalize(event.ems_transport || '')} />
                            <FieldVal label="Level" value={capitalize(event.ems_level)} />
                            <FieldVal label="First Aid" value={FA_LABELS[event.ems_first_aid_level as FirstAidLevel] ?? event.ems_first_aid_level} />
                        </div>
                    </div>
                    <div className="ems-section"><div className="ems-section__header">Schedule & Locations</div>
                        <div className={grd(4)}>
                            <FieldVal label="Start Date" value={formatDate(event.ems_start_date)} />
                            <FieldVal label="Start Time" value={event.ems_start_time} />
                            <FieldVal label="End Date" value={formatDate(event.ems_end_date)} />
                            <FieldVal label="End Time" value={event.ems_end_time} />
                            <FieldVal label="Start Location" value={<LocationDisplay value={event.ems_start_location} />} />
                            <FieldVal label="End Location" value={<LocationDisplay value={event.ems_end_location} />} />
                        </div>
                        <OSMReadOnlyMap startLocation={event.ems_start_location} endLocation={event.ems_end_location} />
                    </div>
                    <div className="ems-section"><div className="ems-section__header">Leader in Charge</div>
                        <div className={grd(3)}>
                            <FieldVal label="Name" value={event.ems_lic_name} />
                            <FieldVal label="Email" value={event.ems_lic_email} />
                            <FieldVal label="Phone" value={event.ems_lic_phone} />
                            <FieldVal label="LIC ID" value={event.ems_lic_id} />
                        </div>
                    </div>
                    <div className="ems-section"><div className="ems-section__header">OSM & Route</div>
                        <div className={grd(3)}>
                            <FieldVal label="OSM Event ID" value={event.ems_osm_event_id} />
                            <FieldVal label="Route Deadline" value={formatDate(event.ems_route_deadline)} />
                            <FieldVal label="Route Status" value={capitalize(event.ems_route_status || 'draft')} />
                        </div>
                       {event.ems_route_info && (
                             <div>
                                 <div className="ems-section__header ems-section__header--mt">Notes</div>
                                 <div
                                     className="ems-rte-readonly ems-rte-readonly__content"
                                     dangerouslySetInnerHTML={{ __html: event.ems_route_info }}
                                 />
                             </div>
                         )}
                    </div>
                </>
            )}
        </div>
    );
};

/* Teams */
function sortByName(members: Member[]): Member[] {
    return [...members].sort((a, b) => `${a.first_name} ${a.last_name}`.localeCompare(`${b.first_name} ${b.last_name}`));
}

interface TeamCardProps {
    team: Team;
    event: Expedition;
    explorers: Explorer[];
    assignedScoutIds: Set<number>;
    allEvents: Expedition[];
    onTeamChanged: () => void;
    onViewAsn: (scoutId: number) => void;
    onMoveMember: (member: Member, fromTeamId: number, toTeamId: number) => Promise<void>;
}

const TeamCard: React.FC<TeamCardProps> = ({
    team,
    event,
    explorers,
    assignedScoutIds,
    allEvents,
    onTeamChanged,
    onViewAsn,
    onMoveMember,
}) => {
    const [selected, setSelected] = useState('');
    const [deleting, setDeleting] = useState(false);
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<number | null>(null);
    const [showMoveTeam, setShowMoveTeam] = useState(false);
    const [showDuplicateTeam, setShowDuplicateTeam] = useState(false);
    const [targetEventId, setTargetEventId] = useState('');
    const [actionInProgress, setActionInProgress] = useState(false);

    const members = team.members ?? [];
    const size = members.length;
    const isVirtual = team.ems_team_code === 'UNALLOCATED';

    // Warnings
    const sizeWarning = !isVirtual && (size < 4 || size > 7);
    
    // First Aid Warning:
    const faReq = event.ems_first_aid_level;
    const faCount = members.filter(m => {
        const lvl = m.first_aid_level ?? 'none';
        if (faReq === 'full_first_aid') return lvl === 'full_first_aid';
        return lvl === 'first_response' || lvl === 'full_first_aid';
    }).length;
    const hasFaCover = !faReq || faReq === 'none' || faCount >= 2;
    const faWarning = !isVirtual && !hasFaCover;

    const available = explorers.filter((e) => !assignedScoutIds.has(e.scout_id) || members.some((m) => m.scout_id === e.scout_id));

    const addMember = async () => {
        if (!selected) return;
        setAdding(true);
        try {
            const res = await apiFetch(`/teams/${team.ID}/members`, { method: 'POST', body: JSON.stringify({ scout_id: Number(selected) }) });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            setSelected('');
            onTeamChanged();
        } catch (e) { console.error(e); } finally { setAdding(false); }
    };

    const removeMember = async (scoutId: number) => {
        setRemoving(scoutId);
        try {
            const res = await apiFetch(`/teams/${team.ID}/members/${scoutId}`, { method: 'DELETE' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            onTeamChanged();
        } catch (e) { console.error(e); } finally { setRemoving(null); }
    };

    const deleteTeam = async () => {
        if (!confirm(`Delete team "${team.ems_team_code}"?`)) return;
        setDeleting(true);
        try {
            const res = await apiFetch(`/teams/${team.ID}`, { method: 'DELETE' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            onTeamChanged();
        } catch (e) { console.error(e); } finally { setDeleting(false); }
    };

    const handleMoveTeam = async () => {
        if (!targetEventId) return;
        setActionInProgress(true);
        try {
            const res = await apiFetch(`/teams/${team.ID}/move`, {
                method: 'POST',
                body: JSON.stringify({ target_event_id: Number(targetEventId) })
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            setShowMoveTeam(false);
            onTeamChanged();
        } catch (e) { console.error(e); } finally { setActionInProgress(false); }
    };

    const handleDuplicateTeam = async () => {
        if (!targetEventId) return;
        setActionInProgress(true);
        try {
            const res = await apiFetch(`/teams/${team.ID}/duplicate`, {
                method: 'POST',
                body: JSON.stringify({ target_event_id: Number(targetEventId) })
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            setShowDuplicateTeam(false);
            onTeamChanged();
        } catch (e) { console.error(e); } finally { setActionInProgress(false); }
    };

    return (
        <div className={`ems-team-card ${isVirtual ? 'ems-team-card--virtual' : ''} ${sizeWarning || faWarning ? 'ems-team-card--warning' : ''}`}>
            <div className="ems-team-card__header">
                <div>
                    <strong className={`ems-team-card__title ${isVirtual ? 'ems-team-card__title--virtual' : ''}`}>{isVirtual ? 'Unallocated Pool' : team.ems_team_code}</strong>
                    <span className={`ems-team-card__count ${isVirtual ? 'ems-team-card__count--virtual' : ''}`}>
                        {size}
                    </span>
                </div>
                {!isVirtual && (
                    <button type="button" className="button-link ems-team-card__delete" onClick={deleteTeam} disabled={deleting}>Delete</button>
                )}
            </div>

            {/* Warnings Alert Block */}
            {sizeWarning && (
                <div className="ems-alert ems-alert--warning">
                    ⚠️ Team size must be 4–7 members (currently {size})
                </div>
            )}
            {faWarning && (
                <div className="ems-alert ems-alert--danger">
                    ⚕️ Requires at least 1 qualified First Aider
                </div>
            )}

            {/* Members List */}
            <ul className="ems-member-list">
                {sortByName(members).map((m) => (
                    <li key={m.scout_id ?? m.user_id} className="ems-member-item">
                        <span className="ems-member-name">
                            {m.has_asn && (
                                <span
                                    className="ems-member-asn"
                                    title="Additional Support Needs (Click to view PII)"
                                    onClick={() => onViewAsn(m.scout_id ?? 0)}
                                >
                                    ⚠️
                                </span>
                            )}
                            {m.first_aid_level === 'full_first_aid' && <span className="ems-member-fa-full" title="Full First Aid">⊕</span>}
                            {m.first_aid_level === 'first_response' && <span className="ems-member-fa-response" title="First Response">✚</span>}
                            {m.first_name} {m.last_name}
                        </span>

                        <div className="ems-member-actions">
                            <select
                                className="ems-member-move"
                                aria-label="Move explorer to team"
                                value=""
                                onChange={(e) => {
                                    if (e.target.value) {
                                        onMoveMember(m, team.ID, Number(e.target.value));
                                    }
                                }}
                            >
                                <option value="">Move…</option>
                                {event.teams.map(t => t.ID !== team.ID && (
                                    <option key={t.ID} value={t.ID}>{t.ems_team_code === 'UNALLOCATED' ? 'Unallocated' : t.ems_team_code}</option>
                                ))}
                            </select>

                            <button type="button" className="button-link ems-member-remove" onClick={() => removeMember(m.scout_id ?? 0)} disabled={removing === m.scout_id}>✕</button>
                        </div>
                    </li>
                ))}
                {members.length === 0 && <li className="ems-member-empty">No members</li>}
            </ul>

            {/* Actions for Team Move / Duplicate */}
            {!isVirtual && (
                <div className="ems-team-actions">
                    <button type="button" className="button button-small" onClick={() => { setShowMoveTeam(!showMoveTeam); setShowDuplicateTeam(false); }}>
                        Move Team
                    </button>
                    <button type="button" className="button button-small" onClick={() => { setShowDuplicateTeam(!showDuplicateTeam); setShowMoveTeam(false); }}>
                        Duplicate
                    </button>
                </div>
            )}

            {/* Move Team Dialog Box */}
            {showMoveTeam && (
                <div className="ems-dialog">
                    <label className="ems-dialog__label">
                        Select Target Event:
                        <select className="ems-dialog__select" value={targetEventId} onChange={(e) => setTargetEventId(e.target.value)}>
                            <option value="">— Choose Event —</option>
                            {allEvents.filter(e => e.ID !== event.ID).map(e => (
                                <option key={e.ID} value={e.ID}>{e.post_title || e.ems_event_code}</option>
                            ))}
                        </select>
                    </label>
                    <button type="button" className="button button-primary button-small" onClick={handleMoveTeam} disabled={actionInProgress || !targetEventId}>
                        {actionInProgress ? 'Moving…' : 'Confirm Move'}
                    </button>
                </div>
            )}

            {/* Duplicate Team Dialog Box */}
            {showDuplicateTeam && (
                <div className="ems-dialog">
                    <label className="ems-dialog__label">
                        Select Target Event:
                        <select className="ems-dialog__select" value={targetEventId} onChange={(e) => setTargetEventId(e.target.value)}>
                            <option value="">— Choose Event —</option>
                            {allEvents.filter(e => e.ID !== event.ID).map(e => (
                                <option key={e.ID} value={e.ID}>{e.post_title || e.ems_event_code}</option>
                            ))}
                        </select>
                    </label>
                    <button type="button" className="button button-primary button-small" onClick={handleDuplicateTeam} disabled={actionInProgress || !targetEventId}>
                        {actionInProgress ? 'Duplicating…' : 'Confirm Duplicate'}
                    </button>
                </div>
            )}

            {/* Add Member Pool */}
            <div className="ems-add-member">
                <select className="ems-add-member__select" value={selected} onChange={(e) => setSelected(e.target.value)} aria-label={`Add member to ${team.ems_team_code}`}>
                    <option value="">Add member…</option>
                    {available.map((exp) => <option key={exp.scout_id} value={exp.scout_id}>{exp.first_name} {exp.last_name}</option>)}
                </select>
                <button type="button" className="button ems-add-member__button" onClick={addMember} disabled={!selected || adding}>{adding ? '…' : 'Add'}</button>
            </div>
        </div>
    );
};

const TeamsTab: React.FC<{ event: Expedition; explorers?: Explorer[]; allEvents?: Expedition[]; onTeamChanged: () => void; onViewAsn: (scoutId: number) => void }> = ({
    event,
    explorers = [],
    allEvents = [],
    onTeamChanged,
    onViewAsn,
}) => {
    const config = window.emsExpeditionBoard;
    const [teams, setTeams] = useState<Team[]>(event.teams ?? []);
    const [creating, setCreating] = useState(false);

    const assignedScoutIds = new Set<number>(
        teams.flatMap((t) => (t.members ?? []).map((m) => m.scout_id).filter((id): id is number => id !== undefined))
    );

    const loadTeams = useCallback(async () => {
        try {
            const res = await fetch(`${config.root_url}/events/${event.ID}/teams`, { headers: { 'X-WP-Nonce': config.nonce } });
            if (!res.ok) return;
            const data: Team[] = await res.json();
            setTeams(data);
        } catch (e) { console.error(e); }
    }, [config.nonce, config.root_url, event.ID]);

    useEffect(() => {
        loadTeams();
    }, [loadTeams]);

    const handleTeamChangedLocal = () => {
        loadTeams();
        onTeamChanged();
    };

    const createTeam = async () => {
        setCreating(true);
        try {
            const res = await apiFetch(`/events/${event.ID}/teams`, { method: 'POST', body: '{}' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            await loadTeams();
        } catch (e) { console.error(e); } finally { setCreating(false); }
    };

    const handleMoveMember = async (member: Member, fromTeamId: number, toTeamId: number) => {
        try {
            // First remove from the current team
            const delRes = await apiFetch(`/teams/${fromTeamId}/members/${member.scout_id}`, { method: 'DELETE' });
            if (!delRes.ok) throw new Error(`Failed to remove member`);
            
            // Then assign to new team
            const addRes = await apiFetch(`/teams/${toTeamId}/members`, { method: 'POST', body: JSON.stringify({ scout_id: member.scout_id }) });
            if (!addRes.ok) throw new Error(`Failed to assign member`);

            handleTeamChangedLocal();
        } catch (e) {
            console.error(e);
        }
    };

    // Filter rules:
    // Regular teams are always shown. 
    // UNALLOCATED virtual team is only shown if it has members in it.
    const visibleTeams = teams.filter(t => t.ems_team_code !== 'UNALLOCATED' || (t.members ?? []).length > 0);

    return (
        <div>
            <div className="ems-teams-header">
                <div className="ems-teams-header__summary">
                    {teams.filter(t => t.ems_team_code !== 'UNALLOCATED').length} teams • {teams.reduce((s, t) => s + (t.member_count ?? (t.members?.length ?? 0)), 0)} members
                </div>
                <button id="ems-add-team-btn" type="button" className="button" onClick={createTeam} disabled={creating}>{creating ? 'Creating…' : '+ Add Team'}</button>
            </div>
            {visibleTeams.length === 0 && <div className="notice notice-info"><p>No teams yet. Click "Add Team" to create the first one.</p></div>}
            <div className="ems-teams-grid">
                {visibleTeams.map((team) => (
                    <TeamCard
                        key={team.ID}
                        team={team}
                        event={event}
                        explorers={explorers}
                        assignedScoutIds={assignedScoutIds}
                        allEvents={allEvents}
                        onTeamChanged={handleTeamChangedLocal}
                        onViewAsn={onViewAsn}
                        onMoveMember={handleMoveMember}
                    />
                ))}
            </div>
        </div>
    );
};

/* Training */
const TrainingTab: React.FC<{ eventId: number }> = ({ eventId }) => {
    const config = window.emsExpeditionBoard;
    const [courses, setCourses] = useState<Array<{ id: number; title: string }>>([]);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [completion, setCompletion] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(`${config.root_url}/events/${eventId}/training-requirements`, { headers: { 'X-WP-Nonce': config.nonce } });
            if (res.ok) {
                const data = await res.json();
                setCourses(data.courses ?? []);
                setSelectedIds(data.course_ids ?? []);
                setCompletion(data.completion ?? []);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setLoading(false);
        }
    }, [config.nonce, config.root_url, eventId]);

    useEffect(() => { load(); }, [load]);

    const handleToggleCourse = (id: number) => {
        setSelectedIds(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );
    };

    const handleSave = async () => {
        setSaving(true);
        try {
            const res = await fetch(`${config.root_url}/events/${eventId}/training-requirements`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: JSON.stringify({ course_ids: selectedIds }),
            });
            if (res.ok) {
                await load();
            }
        } catch (e) {
            console.error(e);
        } finally {
            setSaving(false);
        }
    };

    if (loading) return <p>Loading training requirements…</p>;

    const requiredCourses = courses.filter(c => selectedIds.includes(c.id));

    return (
        <div>
            <h3 className="ems-training-header">Tutor LMS Training Requirements</h3>
            <p className="ems-training-desc">Select the training courses required for participants in this expedition:</p>

            {/* Course Selector Checklist */}
            <div className="ems-course-grid">
                {courses.map(course => (
                    <label key={course.id} className="ems-course-item">
                        <input
                            type="checkbox"
                            checked={selectedIds.includes(course.id)}
                            onChange={() => handleToggleCourse(course.id)}
                        />
                        {course.title}
                    </label>
                ))}
                {courses.length === 0 && <p className="ems-course-empty">No Tutor LMS courses found.</p>}
            </div>

            <button type="button" className="button button-primary" onClick={handleSave} disabled={saving || courses.length === 0}>
                {saving ? 'Saving…' : 'Save Training Requirements'}
            </button>

            {/* Participant Completion Matrix */}
            <h4 className="ems-completion-header">Explorer Completion Status</h4>
            {requiredCourses.length === 0 ? (
                <div className="ems-info-box">
                    No training requirements selected. Select courses above to track participant completion.
                </div>
            ) : (
                <table className="widefat striped ems-completion-table">
                    <thead>
                        <tr>
                            <th>Explorer</th>
                            {requiredCourses.map(c => (
                                <th key={c.id}>{c.title}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {completion.map(row => (
                            <tr key={row.scout_id}>
                                <td className="ems-completion-name">{row.first_name} {row.last_name}</td>
                                {requiredCourses.map(c => {
                                    const status = row.matrix[c.id];
                                    const isComplete = status === 'complete';
                                    return (
                                        <td key={c.id}>
                                            <span className={`ems-status-badge ems-status-badge--${isComplete ? 'success' : 'danger'}`}>
                                                {isComplete ? '✅ Complete' : '❌ Incomplete'}
                                            </span>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                        {completion.length === 0 && (
                            <tr>
                                <td colSpan={requiredCourses.length + 1} className="ems-empty-cell">
                                    No participants assigned to this event yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            )}
        </div>
    );
};

/* Support Needs / ASN Tab */
const ASNTab: React.FC<{ eventId: number; onTeamChanged: () => void }> = ({ eventId, onTeamChanged }) => {
    const config = window.emsExpeditionBoard;
    const [explorers, setExplorers] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [savingId, setSavingId] = useState<number | null>(null);
    const [notes, setNotes] = useState<Record<number, string>>({});
    const [parentAsn, setParentAsn] = useState<Record<number, string>>({});
    const [savedStatus, setSavedStatus] = useState<Record<number, boolean>>({});

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(`${config.root_url}/events/${eventId}/teams`, { headers: { 'X-WP-Nonce': config.nonce } });
            if (res.ok) {
                const teams: Team[] = await res.json();
                // Extract all members
                const allMembers = teams.flatMap(t => (t.members ?? []).map(m => ({ ...m, teamName: t.ems_team_code })));
                // Filter only those with ASN or load all?
                // Let's load all and let the organizer view and edit notes!
                setExplorers(allMembers);
                
                // Fetch details/organiser notes for each
                const notesMap: Record<number, string> = {};
                const parentAsnMap: Record<number, string> = {};
                await Promise.all(allMembers.map(async (m) => {
                    if (m.scout_id) {
                        try {
                            const asnRes = await fetch(`${config.root_url}/explorers/${m.scout_id}/asn`, { headers: { 'X-WP-Nonce': config.nonce } });
                            if (asnRes.ok) {
                                const data = await asnRes.json();
                                notesMap[m.scout_id] = data.organiser_notes || '';
                                parentAsnMap[m.scout_id] = data.parent_asn || '';
                            }
                        } catch (err) {
                            console.error(err);
                        }
                    }
                }));
                setNotes(notesMap);
                setParentAsn(parentAsnMap);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setLoading(false);
        }
    }, [config.nonce, config.root_url, eventId]);

    useEffect(() => { load(); }, [load]);

    const handleSaveNotes = async (scoutId: number) => {
        setSavingId(scoutId);
        try {
            const res = await apiFetch(`/explorers/${scoutId}/asn`, {
                method: 'POST',
                body: JSON.stringify({ organiser_notes: notes[scoutId] || '' })
            });
            if (res.ok) {
                setSavedStatus(prev => ({ ...prev, [scoutId]: true }));
                setTimeout(() => {
                    setSavedStatus(prev => ({ ...prev, [scoutId]: false }));
                }, 3000);
                onTeamChanged(); // refresh parent board so triangle update mirrors instantly
            }
        } catch (e) {
            console.error(e);
        } finally {
            setSavingId(null);
        }
    };

    if (loading) return <p>Loading support needs…</p>;

    return (
        <div>
            <h3 className="ems-asn-header">Additional Support Needs (Medical / PII)</h3>
            <p className="ems-asn-desc">Secure directory of registered medical or accessibility requirements for this expedition.</p>

            <table className="widefat striped ems-asn-table">
                <thead>
                    <tr>
                        <th className="ems-asn-th-name">Explorer / Team</th>
                        <th>Information Provided by Parent (Sign Up)</th>
                        <th>Organiser's Confidential Notes</th>
                        <th className="ems-asn-th-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {explorers.map(exp => (
                        <tr key={exp.scout_id}>
                            <td>
                                <strong>{exp.first_name} {exp.last_name}</strong>
                                <div className="ems-asn-team">
                                    Team: <code>{exp.teamName === 'UNALLOCATED' ? 'Unallocated' : exp.teamName}</code>
                                </div>
                            </td>
                            <td className={`ems-asn-parent ${parentAsn[exp.scout_id] ? 'ems-asn-parent--has' : 'ems-asn-parent--none'}`}>
                                {parentAsn[exp.scout_id] || '— No support needs declared by parent —'}
                            </td>
                            <td>
                                <textarea
                                    className="ems-asn-notes"
                                    aria-label={`Organiser notes for ${exp.first_name} ${exp.last_name}`}
                                    value={notes[exp.scout_id ?? 0] || ''}
                                    onChange={(e) => setNotes(prev => ({ ...prev, [exp.scout_id ?? 0]: e.target.value }))}
                                    placeholder="Enter private organiser notes here (e.g. medication details, actions needed)…"
                                />
                            </td>
                            <td className="ems-asn-actions">
                                <button
                                    type="button"
                                    className="button button-primary"
                                    onClick={() => handleSaveNotes(exp.scout_id ?? 0)}
                                    disabled={savingId === exp.scout_id}
                                >
                                    {savingId === exp.scout_id ? 'Saving…' : 'Save Notes'}
                                </button>
                                {savedStatus[exp.scout_id ?? 0] && (
                                    <div className="ems-asn-saved">✓ Saved</div>
                                )}
                            </td>
                        </tr>
                    ))}
                    {explorers.length === 0 && (
                        <tr>
                            <td colSpan={4} className="ems-empty-cell">
                                No participants assigned to this event yet.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
};

/* QR Codes */
const QRCodesTab: React.FC<{ event: Expedition }> = ({ event }) => {
    const [explorerLink, setExplorerLink] = useState(event.ems_expedition_whatsapp_explorers ?? '');
    const [parentLink, setParentLink] = useState(event.ems_expedition_whatsapp_parents ?? '');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    const save = async (e: React.FormEvent) => {
        e.preventDefault(); setSaving(true); setSaved(false);
        try {
            const res = await apiFetch(`/events/${event.ID}/whatsapp`, { method: 'POST', body: JSON.stringify({ explorer_link: explorerLink, parent_link: parentLink }) });
            if (res.ok) { setSaved(true); setTimeout(() => setSaved(false), 3000); }
        } catch { /* silent */ } finally { setSaving(false); }
    };

    return (
        <div>
            <h3 className="ems-qr-header">WhatsApp Group Links & QR Codes</h3>
            <form onSubmit={save} className="ems-qr-form">
                <table className="form-table ems-qr-table">
                    <tbody>
                        <tr><th scope="row" className="ems-qr-th">Explorers WhatsApp Link</th><td><input id="ems-whatsapp-explorers" type="url" className="large-text" value={explorerLink} onChange={(e) => setExplorerLink(e.target.value)} placeholder="https://chat.whatsapp.com/…" /></td></tr>
                        <tr><th scope="row" className="ems-qr-th">Parents WhatsApp Link</th><td><input id="ems-whatsapp-parents" type="url" className="large-text" value={parentLink} onChange={(e) => setParentLink(e.target.value)} placeholder="https://chat.whatsapp.com/…" /></td></tr>
                    </tbody>
                </table>
                <button type="submit" className="button button-primary" disabled={saving}>{saving ? 'Saving…' : 'Save Links'}</button>
                {saved && <span className="ems-saved-indicator">✓ Saved</span>}
            </form>
            <div className="ems-qr-grid">
                {explorerLink && (
                    <div className="ems-qr-card">
                        <div className="ems-qr-card__title">🧭 Explorers Group</div>
                        <a href={explorerLink} target="_blank" rel="noopener noreferrer" className="ems-qr-card__link">Open link ↗</a>
                        <img src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(explorerLink)}`} alt="Explorers WhatsApp QR Code" className="ems-qr-card__image" />
                    </div>
                )}
                {parentLink && (
                    <div className="ems-qr-card">
                        <div className="ems-qr-card__title">👨‍👩‍👧 Parents Group</div>
                        <a href={parentLink} target="_blank" rel="noopener noreferrer" className="ems-qr-card__link">Open link ↗</a>
                        <img src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(parentLink)}`} alt="Parents WhatsApp QR Code" className="ems-qr-card__image" />
                    </div>
                )}
                {!explorerLink && !parentLink && <p className="ems-qr-empty">No WhatsApp links set yet.</p>}
            </div>
        </div>
    );
};

/* Secure ASN Drawer Overlay */
interface ASNDrawerProps {
    scoutId: number;
    onClose: () => void;
    onSaved: () => void;
}

const ASNDrawer: React.FC<ASNDrawerProps> = ({ scoutId, onClose, onSaved }) => {
    const config = window.emsExpeditionBoard;
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState<any>(null);
    const [notes, setNotes] = useState('');
    const [saving, setSaving] = useState(false);
    const [success, setSuccess] = useState(false);

    useEffect(() => {
        let active = true;
        setLoading(true);
        fetch(`${config.root_url}/explorers/${scoutId}/asn`, { headers: { 'X-WP-Nonce': config.nonce } })
            .then(res => res.json())
            .then(json => {
                if (active) {
                    setData(json);
                    setNotes(json.organiser_notes || '');
                    setLoading(false);
                }
            })
            .catch(err => {
                console.error(err);
                if (active) setLoading(false);
            });

        return () => { active = false; };
    }, [scoutId, config.nonce, config.root_url]);

    const handleSave = async () => {
        setSaving(true);
        setSuccess(false);
        try {
            const res = await apiFetch(`/explorers/${scoutId}/asn`, {
                method: 'POST',
                body: JSON.stringify({ organiser_notes: notes }),
            });
            if (res.ok) {
                setSuccess(true);
                onSaved();
                setTimeout(() => setSuccess(false), 2000);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="ems-asn-drawer">
            <div className="ems-asn-drawer__header">
                <span className="ems-asn-drawer__title">🔒 Support Needs (Confidential)</span>
                <button type="button" className="ems-asn-drawer__close" onClick={onClose}>×</button>
            </div>

            <div className="ems-asn-drawer__content">
                {loading ? (
                    <p>Loading medical support records…</p>
                ) : !data ? (
                    <p className="ems-asn-drawer__error">Failed to retrieve data safely.</p>
                ) : (
                    <>
                        <div>
                            <h3 className="ems-asn-drawer__name">{data.first_name} {data.last_name}</h3>
                            <code className="ems-asn-drawer__scout-id">Scout ID: {data.scout_id}</code>
                        </div>

                        <div>
                            <strong className="ems-asn-drawer__label">
                                Declared by Parent (Sign Up)
                            </strong>
                            <div className="ems-asn-drawer__parent-asn">
                                {data.parent_asn || 'No support needs declared by parent.'}
                            </div>
                        </div>

                        <div>
                            <strong className="ems-asn-drawer__label">
                                Organiser notes
                            </strong>
                            <textarea
                                className="ems-asn-drawer__notes"
                                aria-label="Organiser notes"
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Add confidential leader notes (e.g. details of inhalers, food allergies, action plans)…"
                            />
                        </div>
                    </>
                )}
            </div>

            <div className="ems-asn-drawer__footer">
                {success && <span className="ems-saved-indicator">✓ Saved</span>}
                <button type="button" className="button" onClick={onClose}>Close</button>
                <button type="button" className="button button-primary" onClick={handleSave} disabled={loading || saving}>
                    {saving ? 'Saving…' : 'Save Notes'}
                </button>
            </div>
        </div>
    );
};

/* Main */
export const EventDetailPage: React.FC<EventDetailPageProps> = ({
    event: initialEvent,
    onBack,
    explorers = [],
    osmEvents = [],
    allEvents = [],
    onEventUpdated,
}) => {
    const config = window.emsExpeditionBoard;
    const [activeTab, setActiveTab] = useState<DetailTab>('overview');
    const [event, setEvent] = useState<Expedition>(initialEvent);
    const [activeAsnScoutId, setActiveAsnScoutId] = useState<number | null>(null);

    useEffect(() => { setEvent(initialEvent); }, [initialEvent]);

    const handleUpdated = (updated: Expedition) => {
        setEvent(updated);
        onEventUpdated?.(updated);
    };

    const handleArchiveEventDetail = async () => {
        const isArchive = event.ems_status !== 'archived';
        const msg = isArchive 
            ? 'Are you sure you want to archive this event? It will be hidden from the dashboard unless "Show Archived Events" is toggled.'
            : 'Are you sure you want to restore this event?';
            
        if (!window.confirm(msg)) return;
        
        try {
            const res = await apiFetch(`/events/${event.ID}`, {
                method: 'PATCH',
                body: JSON.stringify({ ems_status: isArchive ? 'archived' : 'active' })
            });
            if (res.ok) {
                const u = await res.json();
                handleUpdated(u);
            }
        } catch (e) {
            console.error(e);
        }
    };

    const refreshTeams = async () => {
        try {
            const res = await fetch(`${config.root_url}/events/${event.ID}/teams`, { headers: { 'X-WP-Nonce': config.nonce } });
            if (res.ok) {
                const data = await res.json();
                handleUpdated({ ...event, teams: data });
            }
        } catch (e) {
            console.error(e);
        }
    };

    const tabBtn = (tab: DetailTab, label: string, id: string) => (
        <button id={id} type="button" className={`nav-tab ${activeTab === tab ? 'nav-tab-active' : ''}`} onClick={() => setActiveTab(tab)}>{label}</button>
    );

    const visibleTeams = (event.teams ?? []).filter(t => t.ems_team_code !== 'UNALLOCATED' || (t.members ?? []).length > 0);

    return (
        <div className="ems-event-detail">
            <div className="ems-event-detail__toolbar">
                <button id="ems-back-to-events" type="button" className="button-link ems-event-detail__back" onClick={onBack}>← Back to Events</button>

                <button
                    type="button"
                    className={`button ${event.ems_status === 'archived' ? 'ems-event-detail__archive--archived' : 'ems-event-detail__archive'}`}
                    onClick={handleArchiveEventDetail}
                >
                    {event.ems_status === 'archived' ? 'Restore Event' : 'Archive Event'}
                </button>
            </div>

            <div className="ems-event-detail__header">
                <div>
                    <h1 className="ems-event-detail__title">{event.post_title || event.ems_event_code}</h1>
                    <div className="ems-event-detail__meta">
                        <code className="ems-event-detail__code">{event.ems_event_code}</code>
                        {statusBadge(event.ems_status)}
                        <span className="ems-event-detail__date">{formatDate(event.ems_start_date)} – {formatDate(event.ems_end_date)}</span>
                    </div>
                </div>
                <div className="ems-event-detail__stats">
                    <div>{visibleTeams.filter(t => t.ems_team_code !== 'UNALLOCATED').length} team{visibleTeams.filter(t => t.ems_team_code !== 'UNALLOCATED').length !== 1 ? 's' : ''}</div>
                    <div>{event.member_count ?? 0} members</div>
                </div>
            </div>

            <nav className="nav-tab-wrapper ems-event-detail__tabs">
                {tabBtn('overview', 'Overview', 'ems-detail-tab-overview')}
                {tabBtn('teams', 'Teams', 'ems-detail-tab-teams')}
                {tabBtn('training', 'Training', 'ems-detail-tab-training')}
                {tabBtn('asn', 'Support Needs', 'ems-detail-tab-asn')}
                {tabBtn('qrcodes', 'QR Codes', 'ems-detail-tab-qrcodes')}
            </nav>

            <div className="ems-event-detail__content">
                {activeTab === 'overview' && <OverviewTab event={event} osmEvents={osmEvents} onUpdated={handleUpdated} />}
                {activeTab === 'teams' && (
                    <TeamsTab
                        event={event}
                        explorers={explorers}
                        allEvents={allEvents}
                        onTeamChanged={refreshTeams}
                        onViewAsn={setActiveAsnScoutId}
                    />
                )}
                {activeTab === 'training' && <TrainingTab eventId={event.ID} />}
                {activeTab === 'asn' && <ASNTab eventId={event.ID} onTeamChanged={refreshTeams} />}
                {activeTab === 'qrcodes' && <QRCodesTab event={event} />}
            </div>

            {/* Secure ASN Drawer Modal */}
            {activeAsnScoutId !== null && (
                <ASNDrawer
                    scoutId={activeAsnScoutId}
                    onClose={() => setActiveAsnScoutId(null)}
                    onSaved={refreshTeams}
                />
            )}
        </div>
    );
};

export default EventDetailPage;
