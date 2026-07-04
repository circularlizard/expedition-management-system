import React, { useState, useEffect, useCallback } from 'react';
import { Expedition, Team, Member, Explorer, FirstAidLevel, OSMEvent } from './types';
import { EventForm } from './EventForm';

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
    const bg = s === 'archived' ? '#f3f4f6' : '#d1fae5';
    const color = s === 'archived' ? '#6b7280' : '#065f46';
    return (
        <span style={{ display: 'inline-block', padding: '3px 12px', borderRadius: '12px', fontSize: '12px', fontWeight: 600, background: bg, color, textTransform: 'capitalize' }}>{s}</span>
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
    <div>
        <div style={{ fontSize: '11px', fontWeight: 600, color: '#888', marginBottom: '4px', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{label}</div>
        <div style={{ fontSize: '14px', color: value ? '#1d2327' : '#bbb' }}>{value || '—'}</div>
    </div>
);

function grd(cols: number): React.CSSProperties {
    return { display: 'grid', gridTemplateColumns: `repeat(${cols}, minmax(0, 220px))`, gap: '16px 32px', marginBottom: '20px' };
}

const secHdr: React.CSSProperties = {
    fontSize: '11px', fontWeight: 700, color: '#888', textTransform: 'uppercase',
    letterSpacing: '0.06em', marginBottom: '14px', paddingBottom: '6px', borderBottom: '1px solid #f0f0f0',
};

/* Overview */
const OverviewTab: React.FC<{ event: Expedition; osmEvents?: OSMEvent[]; onUpdated?: (e: Expedition) => void }> = ({ event, osmEvents = [], onUpdated }) => {
    const [editing, setEditing] = useState(false);
    return (
        <div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: '16px' }}>
                <button className="button" onClick={() => setEditing((v) => !v)}>{editing ? 'Cancel' : 'Edit Event'}</button>
            </div>
            {editing ? (
                <EventForm seasonId={0} initialEvent={event} osmEvents={osmEvents} onSaved={(u) => { setEditing(false); onUpdated?.(u); }} onCancel={() => setEditing(false)} />
            ) : (
                <>
                    <div style={{ marginBottom: '24px' }}><div style={secHdr}>Event Details</div>
                        <div style={grd(4)}>
                            <FieldVal label="Type" value={capitalize(event.ems_type)} />
                            <FieldVal label="Transport" value={capitalize(event.ems_transport || '')} />
                            <FieldVal label="Level" value={capitalize(event.ems_level)} />
                            <FieldVal label="First Aid" value={FA_LABELS[event.ems_first_aid_level as FirstAidLevel] ?? event.ems_first_aid_level} />
                        </div>
                    </div>
                    <div style={{ marginBottom: '24px' }}><div style={secHdr}>Schedule & Locations</div>
                        <div style={grd(4)}>
                            <FieldVal label="Start Date" value={formatDate(event.ems_start_date)} />
                            <FieldVal label="Start Time" value={event.ems_start_time} />
                            <FieldVal label="End Date" value={formatDate(event.ems_end_date)} />
                            <FieldVal label="End Time" value={event.ems_end_time} />
                            <FieldVal label="Start Location" value={event.ems_start_location} />
                            <FieldVal label="End Location" value={event.ems_end_location} />
                        </div>
                    </div>
                    <div style={{ marginBottom: '24px' }}><div style={secHdr}>Leader in Charge</div>
                        <div style={grd(3)}>
                            <FieldVal label="Name" value={event.ems_lic_name} />
                            <FieldVal label="Email" value={event.ems_lic_email} />
                            <FieldVal label="Phone" value={event.ems_lic_phone} />
                            <FieldVal label="LIC ID" value={event.ems_lic_id} />
                        </div>
                    </div>
                    <div style={{ marginBottom: '24px' }}><div style={secHdr}>OSM & Route</div>
                        <div style={grd(3)}>
                            <FieldVal label="OSM Event ID" value={event.ems_osm_event_id} />
                            <FieldVal label="Route Deadline" value={formatDate(event.ems_route_deadline)} />
                            <FieldVal label="Route Status" value={capitalize(event.ems_route_status || 'draft')} />
                        </div>
                        {event.ems_route_info && <div><div style={{ ...secHdr, marginTop: '12px' }}>Notes</div><div style={{ fontSize: '14px', lineHeight: '1.6' }} dangerouslySetInnerHTML={{ __html: event.ems_route_info }} /></div>}
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
    const hasFaCover = !faReq || faReq === 'none' || members.some(m => {
        const lvl = m.first_aid_level;
        if (faReq === 'full_first_aid') return lvl === 'full_first_aid';
        return lvl === 'first_response' || lvl === 'full_first_aid';
    });
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
        <div style={{ border: `1px solid ${sizeWarning || faWarning ? '#f0b849' : '#dcdcde'}`, borderRadius: '4px', padding: '16px', background: isVirtual ? '#f8fafc' : '#fff', minWidth: '260px', flex: '1 1 260px', display: 'flex', flexDirection: 'column', gap: '8px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: '1px solid #e5e7eb', paddingBottom: '8px' }}>
                <div>
                    <strong style={{ fontSize: '15px', color: isVirtual ? '#1e293b' : '#0f172a' }}>{isVirtual ? 'Unallocated Pool' : team.ems_team_code}</strong>
                    <span style={{ marginLeft: '8px', fontSize: '11px', fontWeight: 600, padding: '2px 6px', borderRadius: '10px', background: isVirtual ? '#e2e8f0' : '#f0fdf4', color: isVirtual ? '#475569' : '#166534' }}>
                        {size}
                    </span>
                </div>
                {!isVirtual && (
                    <button type="button" className="button-link" style={{ color: '#d63638', fontSize: '12px' }} onClick={deleteTeam} disabled={deleting}>Delete</button>
                )}
            </div>

            {/* Warnings Alert Block */}
            {sizeWarning && (
                <div style={{ background: '#fffbeb', borderLeft: '3px solid #d97706', padding: '6px 10px', fontSize: '11px', color: '#b45309' }}>
                    ⚠️ Team size must be 4–7 members (currently {size})
                </div>
            )}
            {faWarning && (
                <div style={{ background: '#fef2f2', borderLeft: '3px solid #dc2626', padding: '6px 10px', fontSize: '11px', color: '#b91c1c' }}>
                    ⚕️ Requires at least 1 qualified First Aider
                </div>
            )}

            {/* Members List */}
            <ul style={{ listStyle: 'none', margin: '0', padding: '0', flexGrow: 1 }}>
                {sortByName(members).map((m) => (
                    <li key={m.scout_id ?? m.user_id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '6px 0', borderBottom: '1px solid #f3f4f6', fontSize: '13px' }}>
                        <span style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
                            {m.has_asn && (
                                <span
                                    title="Additional Support Needs (Click to view PII)"
                                    style={{ color: '#d97706', cursor: 'pointer', marginRight: '2px', fontWeight: 'bold' }}
                                    onClick={() => onViewAsn(m.scout_id ?? 0)}
                                >
                                    ⚠️
                                </span>
                            )}
                            {m.first_aid_level === 'full_first_aid' && <span title="Full First Aid" style={{ color: '#1b5e20', fontWeight: 'bold' }}>⊕</span>}
                            {m.first_aid_level === 'first_response' && <span title="First Response" style={{ color: '#2e7d32', fontWeight: 'bold' }}>✚</span>}
                            {m.first_name} {m.last_name}
                        </span>
                        
                        <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                            {/* Move member control */}
                            <select
                                aria-label="Move explorer to team"
                                value=""
                                onChange={(e) => {
                                    if (e.target.value) {
                                        onMoveMember(m, team.ID, Number(e.target.value));
                                    }
                                }}
                                style={{ fontSize: '11px', padding: '2px', width: '70px', height: '22px' }}
                            >
                                <option value="">Move…</option>
                                {event.teams.map(t => t.ID !== team.ID && (
                                    <option key={t.ID} value={t.ID}>{t.ems_team_code === 'UNALLOCATED' ? 'Unallocated' : t.ems_team_code}</option>
                                ))}
                            </select>
                            
                            <button type="button" className="button-link" style={{ color: '#d63638', fontSize: '14px', lineHeight: 1 }} onClick={() => removeMember(m.scout_id ?? 0)} disabled={removing === m.scout_id}>✕</button>
                        </div>
                    </li>
                ))}
                {members.length === 0 && <li style={{ color: '#aaa', fontSize: '13px', padding: '8px 0', textAlign: 'center' }}>No members</li>}
            </ul>

            {/* Actions for Team Move / Duplicate */}
            {!isVirtual && (
                <div style={{ display: 'flex', gap: '8px', marginTop: '8px', borderTop: '1px solid #f3f4f6', paddingTop: '8px' }}>
                    <button type="button" className="button button-small" style={{ flex: 1 }} onClick={() => { setShowMoveTeam(!showMoveTeam); setShowDuplicateTeam(false); }}>
                        ✈️ Move Team
                    </button>
                    <button type="button" className="button button-small" style={{ flex: 1 }} onClick={() => { setShowDuplicateTeam(!showDuplicateTeam); setShowMoveTeam(false); }}>
                        👯 Duplicate
                    </button>
                </div>
            )}

            {/* Move Team Dialog Box */}
            {showMoveTeam && (
                <div style={{ background: '#f8fafc', padding: '8px', border: '1px solid #e2e8f0', borderRadius: '4px', fontSize: '12px' }}>
                    <label style={{ display: 'block', marginBottom: '6px' }}>
                        Select Target Event:
                        <select value={targetEventId} onChange={(e) => setTargetEventId(e.target.value)} style={{ display: 'block', width: '100%', marginTop: '4px' }}>
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
                <div style={{ background: '#f8fafc', padding: '8px', border: '1px solid #e2e8f0', borderRadius: '4px', fontSize: '12px' }}>
                    <label style={{ display: 'block', marginBottom: '6px' }}>
                        Select Target Event:
                        <select value={targetEventId} onChange={(e) => setTargetEventId(e.target.value)} style={{ display: 'block', width: '100%', marginTop: '4px' }}>
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
            <div style={{ display: 'flex', gap: '6px', alignItems: 'center', marginTop: '8px', borderTop: '1px solid #f3f4f6', paddingTop: '8px' }}>
                <select value={selected} onChange={(e) => setSelected(e.target.value)} style={{ flex: 1, fontSize: '12px', height: '30px' }} aria-label={`Add member to ${team.ems_team_code}`}>
                    <option value="">Add member…</option>
                    {available.map((exp) => <option key={exp.scout_id} value={exp.scout_id}>{exp.first_name} {exp.last_name}</option>)}
                </select>
                <button type="button" className="button" style={{ padding: '4px 10px', fontSize: '12px' }} onClick={addMember} disabled={!selected || adding}>{adding ? '…' : 'Add'}</button>
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
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                <div style={{ fontSize: '13px', color: '#50575e' }}>
                    {teams.filter(t => t.ems_team_code !== 'UNALLOCATED').length} teams • {teams.reduce((s, t) => s + (t.member_count ?? (t.members?.length ?? 0)), 0)} members
                </div>
                <button id="ems-add-team-btn" type="button" className="button" onClick={createTeam} disabled={creating}>{creating ? 'Creating…' : '+ Add Team'}</button>
            </div>
            {visibleTeams.length === 0 && <div className="notice notice-info"><p>No teams yet. Click "Add Team" to create the first one.</p></div>}
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '16px' }}>
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
            <h3 style={{ marginTop: 0 }}>Tutor LMS Training Requirements</h3>
            <p style={{ color: '#646970' }}>Select the training courses required for participants in this expedition:</p>
            
            {/* Course Selector Checklist */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(250px, 1fr))', gap: '10px', background: '#f6f7f7', padding: '16px', border: '1px solid #dcdcde', borderRadius: '4px', marginBottom: '16px' }}>
                {courses.map(course => (
                    <label key={course.id} style={{ display: 'flex', alignItems: 'center', gap: '8px', cursor: 'pointer', fontSize: '13px' }}>
                        <input
                            type="checkbox"
                            checked={selectedIds.includes(course.id)}
                            onChange={() => handleToggleCourse(course.id)}
                        />
                        {course.title}
                    </label>
                ))}
                {courses.length === 0 && <p style={{ color: '#aaa', margin: 0 }}>No Tutor LMS courses found.</p>}
            </div>
            
            <button type="button" className="button button-primary" onClick={handleSave} disabled={saving || courses.length === 0}>
                {saving ? 'Saving…' : 'Save Training Requirements'}
            </button>

            {/* Participant Completion Matrix */}
            <h4 style={{ marginTop: '28px', marginBottom: '10px' }}>Explorer Completion Status</h4>
            {requiredCourses.length === 0 ? (
                <div style={{ background: '#f0f6fc', padding: '12px', borderLeft: '4px solid #2271b1', color: '#1d2327', fontSize: '13px' }}>
                    No training requirements selected. Select courses above to track participant completion.
                </div>
            ) : (
                <table className="widefat striped" style={{ marginTop: '10px' }}>
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
                                <td style={{ fontWeight: 600 }}>{row.first_name} {row.last_name}</td>
                                {requiredCourses.map(c => {
                                    const status = row.matrix[c.id];
                                    const isComplete = status === 'complete';
                                    return (
                                        <td key={c.id}>
                                            <span style={{
                                                display: 'inline-flex',
                                                alignItems: 'center',
                                                gap: '4px',
                                                color: isComplete ? '#1b5e20' : '#b71c1c',
                                                fontWeight: 600,
                                                fontSize: '12px',
                                            }}>
                                                {isComplete ? '✅ Complete' : '❌ Incomplete'}
                                            </span>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                        {completion.length === 0 && (
                            <tr>
                                <td colSpan={requiredCourses.length + 1} style={{ textAlign: 'center', color: '#aaa' }}>
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
                await Promise.all(allMembers.map(async (m) => {
                    if (m.scout_id) {
                        try {
                            const asnRes = await fetch(`${config.root_url}/explorers/${m.scout_id}/asn`, { headers: { 'X-WP-Nonce': config.nonce } });
                            if (asnRes.ok) {
                                const data = await asnRes.json();
                                notesMap[m.scout_id] = data.organiser_notes || '';
                            }
                        } catch (err) {
                            console.error(err);
                        }
                    }
                }));
                setNotes(notesMap);
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
            <h3 style={{ marginTop: 0 }}>Additional Support Needs (Medical / PII)</h3>
            <p style={{ color: '#646970' }}>Secure directory of registered medical or accessibility requirements for this expedition.</p>
            
            <table className="widefat striped">
                <thead>
                    <tr>
                        <th style={{ width: '200px' }}>Explorer / Team</th>
                        <th>Information Provided by Parent (Sign Up)</th>
                        <th>Organiser's Confidential Notes</th>
                        <th style={{ width: '120px', textAlign: 'right' }}>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {explorers.map(exp => (
                        <tr key={exp.scout_id}>
                            <td>
                                <strong>{exp.first_name} {exp.last_name}</strong>
                                <div style={{ fontSize: '11px', color: '#646970', marginTop: '4px' }}>
                                    Team: <code>{exp.teamName === 'UNALLOCATED' ? 'Unallocated' : exp.teamName}</code>
                                </div>
                            </td>
                            <td style={{ verticalAlign: 'top', color: exp.additional_support_needs ? '#b45309' : '#646970', fontWeight: exp.additional_support_needs ? 600 : 400 }}>
                                {exp.additional_support_needs || '— No support needs declared by parent —'}
                            </td>
                            <td>
                                <textarea
                                    aria-label={`Organiser notes for ${exp.first_name} ${exp.last_name}`}
                                    value={notes[exp.scout_id ?? 0] || ''}
                                    onChange={(e) => setNotes(prev => ({ ...prev, [exp.scout_id ?? 0]: e.target.value }))}
                                    style={{ width: '100%', height: '60px', boxSizing: 'border-box', fontSize: '13px' }}
                                    placeholder="Enter private organiser notes here (e.g. medication details, actions needed)…"
                                />
                            </td>
                            <td style={{ verticalAlign: 'middle', textAlign: 'right' }}>
                                <button
                                    type="button"
                                    className="button button-primary"
                                    onClick={() => handleSaveNotes(exp.scout_id ?? 0)}
                                    disabled={savingId === exp.scout_id}
                                >
                                    {savingId === exp.scout_id ? 'Saving…' : 'Save Notes'}
                                </button>
                                {savedStatus[exp.scout_id ?? 0] && (
                                    <div style={{ color: '#00a32a', fontSize: '11px', fontWeight: 600, marginTop: '4px' }}>✓ Saved</div>
                                )}
                            </td>
                        </tr>
                    ))}
                    {explorers.length === 0 && (
                        <tr>
                            <td colSpan={4} style={{ textAlign: 'center', color: '#aaa', padding: '16px' }}>
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
            <h3 style={{ marginTop: 0 }}>WhatsApp Group Links & QR Codes</h3>
            <form onSubmit={save} style={{ maxWidth: '600px', marginBottom: '32px' }}>
                <table className="form-table" style={{ marginBottom: '16px' }}>
                    <tbody>
                        <tr><th scope="row" style={{ width: '200px', paddingLeft: 0 }}>Explorers WhatsApp Link</th><td><input id="ems-whatsapp-explorers" type="url" className="large-text" value={explorerLink} onChange={(e) => setExplorerLink(e.target.value)} placeholder="https://chat.whatsapp.com/…" /></td></tr>
                        <tr><th scope="row" style={{ paddingLeft: 0 }}>Parents WhatsApp Link</th><td><input id="ems-whatsapp-parents" type="url" className="large-text" value={parentLink} onChange={(e) => setParentLink(e.target.value)} placeholder="https://chat.whatsapp.com/…" /></td></tr>
                    </tbody>
                </table>
                <button type="submit" className="button button-primary" disabled={saving}>{saving ? 'Saving…' : 'Save Links'}</button>
                {saved && <span style={{ marginLeft: '12px', color: '#00a32a', fontWeight: 600 }}>✓ Saved</span>}
            </form>
            <div style={{ display: 'flex', gap: '32px', flexWrap: 'wrap' }}>
                {explorerLink && (
                    <div style={{ textAlign: 'center' }}>
                        <div style={{ fontWeight: 600, marginBottom: '8px' }}>🧭 Explorers Group</div>
                        <a href={explorerLink} target="_blank" rel="noopener noreferrer" style={{ display: 'block', marginBottom: '8px', fontSize: '13px' }}>Open link ↗</a>
                        <img src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(explorerLink)}`} alt="Explorers WhatsApp QR Code" style={{ border: '1px solid #dcdcde', borderRadius: '4px' }} />
                    </div>
                )}
                {parentLink && (
                    <div style={{ textAlign: 'center' }}>
                        <div style={{ fontWeight: 600, marginBottom: '8px' }}>👨‍👩‍👧 Parents Group</div>
                        <a href={parentLink} target="_blank" rel="noopener noreferrer" style={{ display: 'block', marginBottom: '8px', fontSize: '13px' }}>Open link ↗</a>
                        <img src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(parentLink)}`} alt="Parents WhatsApp QR Code" style={{ border: '1px solid #dcdcde', borderRadius: '4px' }} />
                    </div>
                )}
                {!explorerLink && !parentLink && <p style={{ color: '#aaa' }}>No WhatsApp links set yet.</p>}
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
        <div style={{ position: 'fixed', top: 0, right: 0, bottom: 0, width: '400px', background: '#fff', boxShadow: '-2px 0 10px rgba(0,0,0,0.15)', zIndex: 100000, display: 'flex', flexDirection: 'column', boxSizing: 'border-box' }}>
            <div style={{ padding: '16px 20px', background: '#1d2327', color: '#fff', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ fontWeight: 600, fontSize: '15px' }}>🔒 Support Needs (Confidential)</span>
                <button type="button" onClick={onClose} style={{ background: 'none', border: 'none', color: '#fff', fontSize: '20px', cursor: 'pointer', lineHeight: 1 }}>×</button>
            </div>
            
            <div style={{ padding: '20px', flexGrow: 1, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '20px' }}>
                {loading ? (
                    <p>Loading medical support records…</p>
                ) : !data ? (
                    <p style={{ color: '#d63638' }}>Failed to retrieve data safely.</p>
                ) : (
                    <>
                        <div>
                            <h3 style={{ margin: '0 0 6px' }}>{data.first_name} {data.last_name}</h3>
                            <code style={{ fontSize: '11px', color: '#646970' }}>Scout ID: {data.scout_id}</code>
                        </div>

                        <div>
                            <strong style={{ display: 'block', fontSize: '12px', color: '#646970', textTransform: 'uppercase', marginBottom: '6px' }}>
                                Declared by Parent (Sign Up)
                            </strong>
                            <div style={{ padding: '10px', background: '#fffbeb', borderLeft: '4px solid #d97706', borderRadius: '4px', fontSize: '13px', color: '#78350f', minHeight: '60px', whiteSpace: 'pre-wrap' }}>
                                {data.parent_asn || 'No support needs declared by parent.'}
                            </div>
                        </div>

                        <div>
                            <strong style={{ display: 'block', fontSize: '12px', color: '#646970', textTransform: 'uppercase', marginBottom: '6px' }}>
                                Organiser notes
                            </strong>
                            <textarea
                                aria-label="Organiser notes"
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                style={{ width: '100%', height: '120px', boxSizing: 'border-box', fontSize: '13px', padding: '8px' }}
                                placeholder="Add confidential leader notes (e.g. details of inhalers, food allergies, action plans)…"
                            />
                        </div>
                    </>
                )}
            </div>

            <div style={{ padding: '16px 20px', borderTop: '1px solid #dcdcde', background: '#f6f7f7', display: 'flex', justifyContent: 'flex-end', gap: '10px', alignItems: 'center' }}>
                {success && <span style={{ color: '#00a32a', fontWeight: 600, fontSize: '13px' }}>✓ Saved</span>}
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
        <div className="ems-event-detail" style={{ position: 'relative' }}>
            <div style={{ marginBottom: '16px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <button id="ems-back-to-events" type="button" className="button-link" style={{ color: '#2271b1', fontSize: '13px' }} onClick={onBack}>← Back to Events</button>
                
                <button
                    type="button"
                    className="button"
                    style={{ color: event.ems_status === 'archived' ? '#2271b1' : '#d63638' }}
                    onClick={handleArchiveEventDetail}
                >
                    {event.ems_status === 'archived' ? 'Restore Event' : 'Archive Event'}
                </button>
            </div>
            
            <div style={{ padding: '20px 24px', background: '#fff', border: '1px solid #dcdcde', borderRadius: '4px', marginBottom: '20px', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                <div>
                    <h1 style={{ margin: '0 0 4px', fontSize: '22px', color: '#1d2327' }}>{event.post_title || event.ems_event_code}</h1>
                    <div style={{ display: 'flex', gap: '12px', alignItems: 'center', flexWrap: 'wrap' }}>
                        <code style={{ background: '#f0f0f0', padding: '3px 8px', borderRadius: '3px', fontSize: '13px' }}>{event.ems_event_code}</code>
                        {statusBadge(event.ems_status)}
                        <span style={{ fontSize: '13px', color: '#50575e' }}>{formatDate(event.ems_start_date)} – {formatDate(event.ems_end_date)}</span>
                    </div>
                </div>
                <div style={{ textAlign: 'right', fontSize: '13px', color: '#50575e' }}>
                    <div style={{ fontWeight: 600 }}>{visibleTeams.filter(t => t.ems_team_code !== 'UNALLOCATED').length} team{visibleTeams.filter(t => t.ems_team_code !== 'UNALLOCATED').length !== 1 ? 's' : ''}</div>
                    <div>{event.member_count ?? 0} members</div>
                </div>
            </div>
            
            <nav className="nav-tab-wrapper" style={{ marginBottom: 0 }}>
                {tabBtn('overview', 'Overview', 'ems-detail-tab-overview')}
                {tabBtn('teams', 'Teams', 'ems-detail-tab-teams')}
                {tabBtn('training', 'Training', 'ems-detail-tab-training')}
                {tabBtn('asn', 'Support Needs', 'ems-detail-tab-asn')}
                {tabBtn('qrcodes', 'QR Codes', 'ems-detail-tab-qrcodes')}
            </nav>
            
            <div style={{ padding: '24px', background: '#fff', border: '1px solid #dcdcde', borderTop: 'none' }}>
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
