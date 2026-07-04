import React, { useState, useEffect, useCallback } from 'react';
import { Expedition, Team, Member, Explorer, FirstAidLevel, OSMEvent } from './types';
import { EventForm } from './EventForm';

interface EventDetailPageProps {
    event: Expedition;
    onBack: () => void;
    explorers?: Explorer[];
    osmEvents?: OSMEvent[];
    onEventUpdated?: (updated: Expedition) => void;
}

type DetailTab = 'overview' | 'teams' | 'training' | 'qrcodes';

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

const STATUS_COLORS: Record<string, { bg: string; color: string }> = {
    active: { bg: '#d1fae5', color: '#065f46' },
    archived: { bg: '#f3f4f6', color: '#6b7280' },
    planning: { bg: '#e0f2fe', color: '#0369a1' },
    open: { bg: '#d1fae5', color: '#065f46' },
    confirmed: { bg: '#c7d2fe', color: '#3730a3' },
    completed: { bg: '#f3f4f6', color: '#6b7280' },
    draft: { bg: '#fef9c3', color: '#854d0e' },
};

function statusBadge(status?: string): React.ReactNode {
    const s = status || 'active';
    const c = STATUS_COLORS[s] || { bg: '#eee', color: '#555' };
    return (
        <span style={{ display: 'inline-block', padding: '3px 12px', borderRadius: '12px', fontSize: '12px', fontWeight: 600, background: c.bg, color: c.color, textTransform: 'capitalize' }}>{s}</span>
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
                            <FieldVal label="First Aid Requirements" value={event.ems_first_aid_requirements} />
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

const TeamCard: React.FC<{ team: Team; explorers: Explorer[]; assignedScoutIds: Set<number>; onTeamChanged: () => void }> = ({ team, explorers, assignedScoutIds, onTeamChanged }) => {
    const [selected, setSelected] = useState('');
    const [deleting, setDeleting] = useState(false);
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<number | null>(null);
    const [members, setMembers] = useState<Member[]>(team.members ?? []);

    const available = explorers.filter((e) => !assignedScoutIds.has(e.scout_id) || members.some((m) => m.scout_id === e.scout_id));
    const size = members.length;
    const warning = size < 4 || size > 7;

    const addMember = async () => {
        if (!selected) return;
        setAdding(true);
        try {
            const res = await apiFetch(`/teams/${team.ID}/members`, { method: 'POST', body: JSON.stringify({ scout_id: Number(selected) }) });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            setMembers(await res.json()); setSelected(''); onTeamChanged();
        } catch (e) { console.error(e); } finally { setAdding(false); }
    };

    const removeMember = async (scoutId: number) => {
        setRemoving(scoutId);
        try {
            const res = await apiFetch(`/teams/${team.ID}/members/${scoutId}`, { method: 'DELETE' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();
            if (json.team_deleted) { onTeamChanged(); return; }
            setMembers(json); onTeamChanged();
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

    return (
        <div style={{ border: `1px solid ${warning ? '#f0b849' : '#dcdcde'}`, borderRadius: '4px', padding: '16px', background: '#fff', minWidth: '240px', flex: '1 1 240px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
                <div>
                    <strong style={{ fontSize: '15px' }}>{team.ems_team_code}</strong>
                    <span style={{ marginLeft: '8px', fontSize: '12px', fontWeight: 600, color: warning ? '#d63638' : '#2e7d32' }}>{size} member{size !== 1 ? 's' : ''}{warning && ' ⚠'}</span>
                </div>
                <button type="button" className="button-link" style={{ color: '#d63638', fontSize: '12px' }} onClick={deleteTeam} disabled={deleting}>Delete</button>
            </div>
            <ul style={{ listStyle: 'none', margin: '0 0 12px', padding: 0 }}>
                {sortByName(members).map((m) => (
                    <li key={m.scout_id ?? m.user_id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '4px 0', borderBottom: '1px solid #f6f7f7', fontSize: '13px' }}>
                        <span>
                            {m.first_aid_level === 'full_first_aid' && <span title="Full First Aid" style={{ color: '#1b5e20', fontWeight: 'bold', marginRight: '4px' }}>⊕</span>}
                            {m.first_aid_level === 'first_response' && <span title="First Response" style={{ color: '#2e7d32', fontWeight: 'bold', marginRight: '4px' }}>✚</span>}
                            {m.first_name} {m.last_name}
                        </span>
                        <button type="button" className="button-link" style={{ color: '#d63638', fontSize: '11px' }} onClick={() => removeMember(m.scout_id ?? 0)} disabled={removing === m.scout_id}>✕</button>
                    </li>
                ))}
                {members.length === 0 && <li style={{ color: '#aaa', fontSize: '13px' }}>No members</li>}
            </ul>
            <div style={{ display: 'flex', gap: '6px', alignItems: 'center' }}>
                <select value={selected} onChange={(e) => setSelected(e.target.value)} style={{ flex: 1, fontSize: '12px' }} aria-label={`Add member to ${team.ems_team_code}`}>
                    <option value="">Add member…</option>
                    {available.map((exp) => <option key={exp.scout_id} value={exp.scout_id}>{exp.first_name} {exp.last_name}</option>)}
                </select>
                <button type="button" className="button" style={{ padding: '4px 10px', fontSize: '12px' }} onClick={addMember} disabled={!selected || adding}>{adding ? '…' : 'Add'}</button>
            </div>
        </div>
    );
};

const TeamsTab: React.FC<{ event: Expedition; explorers?: Explorer[] }> = ({ event, explorers = [] }) => {
    const config = window.emsExpeditionBoard;
    const [teams, setTeams] = useState<Team[]>((event.teams ?? []).filter(t => t.ems_team_code !== 'UNALLOCATED'));
    const [creating, setCreating] = useState(false);

    const assignedScoutIds = new Set<number>(teams.flatMap((t) => (t.members ?? []).map((m) => m.scout_id).filter((id): id is number => id !== undefined)));

    const loadTeams = useCallback(async () => {
        try {
            const res = await fetch(`${config.root_url}/events/${event.ID}/teams`, { headers: { 'X-WP-Nonce': config.nonce } });
            if (!res.ok) return;
            const data: Team[] = await res.json();
            setTeams(data.filter(t => t.ems_team_code !== 'UNALLOCATED'));
        } catch (e) { console.error(e); }
    }, [config.nonce, config.root_url, event.ID]);

    const createTeam = async () => {
        setCreating(true);
        try {
            const res = await apiFetch(`/events/${event.ID}/teams`, { method: 'POST', body: '{}' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            await loadTeams();
        } catch (e) { console.error(e); } finally { setCreating(false); }
    };

    return (
        <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                <div style={{ fontSize: '13px', color: '#50575e' }}>{teams.length} team{teams.length !== 1 ? 's' : ''} • {teams.reduce((s, t) => s + (t.member_count ?? (t.members?.length ?? 0)), 0)} members</div>
                <button id="ems-add-team-btn" type="button" className="button" onClick={createTeam} disabled={creating}>{creating ? 'Creating…' : '+ Add Team'}</button>
            </div>
            {teams.length === 0 && <div className="notice notice-info"><p>No teams yet. Click "Add Team" to create the first one.</p></div>}
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '16px' }}>
                {teams.map((team) => <TeamCard key={team.ID} team={team} explorers={explorers} assignedScoutIds={assignedScoutIds} onTeamChanged={loadTeams} />)}
            </div>
        </div>
    );
};

/* Training */
const TrainingTab: React.FC<{ eventId: number }> = ({ eventId }) => {
    const config = window.emsExpeditionBoard;
    const [reqs, setReqs] = useState<Array<{ id?: number; first_name?: string; last_name?: string; requirement?: string; status?: string }>>([]);
    const [loading, setLoading] = useState(true);
    const [newReq, setNewReq] = useState('');
    const [saving, setSaving] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(`${config.root_url}/events/${eventId}/training-requirements`, { headers: { 'X-WP-Nonce': config.nonce } });
            if (res.ok) { const d = await res.json(); setReqs(Array.isArray(d) ? d : (d.requirements ?? [])); }
        } catch { /* silent */ } finally { setLoading(false); }
    }, [config.nonce, config.root_url, eventId]);

    useEffect(() => { load(); }, [load]);

    const addReq = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!newReq.trim()) return;
        setSaving(true);
        try {
            const res = await fetch(`${config.root_url}/events/${eventId}/training-requirements`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce }, body: JSON.stringify({ requirement: newReq }) });
            if (res.ok) { setNewReq(''); await load(); }
        } catch { /* silent */ } finally { setSaving(false); }
    };

    if (loading) return <p>Loading training requirements…</p>;

    return (
        <div>
            <h3 style={{ marginTop: 0 }}>Training Requirements</h3>
            {reqs.length === 0 && <p style={{ color: '#aaa' }}>No requirements recorded yet.</p>}
            {reqs.length > 0 && (
                <table className="widefat striped" style={{ marginBottom: '20px' }}>
                    <thead><tr><th>Explorer</th><th>Requirement</th><th>Status</th></tr></thead>
                    <tbody>{reqs.map((r, i) => <tr key={r.id ?? i}><td>{r.first_name} {r.last_name}</td><td>{r.requirement}</td><td>{r.status}</td></tr>)}</tbody>
                </table>
            )}
            <form onSubmit={addReq} style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                <input type="text" className="regular-text" value={newReq} onChange={(e) => setNewReq(e.target.value)} placeholder="Add training requirement…" />
                <button type="submit" className="button button-primary" disabled={saving || !newReq.trim()}>{saving ? 'Saving…' : 'Add'}</button>
            </form>
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

/* Main */
export const EventDetailPage: React.FC<EventDetailPageProps> = ({ event: initialEvent, onBack, explorers = [], osmEvents = [], onEventUpdated }) => {
    const [activeTab, setActiveTab] = useState<DetailTab>('overview');
    const [event, setEvent] = useState<Expedition>(initialEvent);

    useEffect(() => { setEvent(initialEvent); }, [initialEvent]);

    const handleUpdated = (updated: Expedition) => { setEvent(updated); onEventUpdated?.(updated); };
    const tabBtn = (tab: DetailTab, label: string, id: string) => (
        <button id={id} type="button" className={`nav-tab ${activeTab === tab ? 'nav-tab-active' : ''}`} onClick={() => setActiveTab(tab)}>{label}</button>
    );

    const visibleTeams = (event.teams ?? []).filter(t => t.ems_team_code !== 'UNALLOCATED');

    return (
        <div className="ems-event-detail">
            <div style={{ marginBottom: '16px' }}>
                <button id="ems-back-to-events" type="button" className="button-link" style={{ color: '#2271b1', fontSize: '13px' }} onClick={onBack}>← Back to Events</button>
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
                    <div style={{ fontWeight: 600 }}>{visibleTeams.length} team{visibleTeams.length !== 1 ? 's' : ''}</div>
                    <div>{event.member_count ?? 0} members</div>
                </div>
            </div>
            <nav className="nav-tab-wrapper" style={{ marginBottom: 0 }}>
                {tabBtn('overview', 'Overview', 'ems-detail-tab-overview')}
                {tabBtn('teams', 'Teams', 'ems-detail-tab-teams')}
                {tabBtn('training', 'Training', 'ems-detail-tab-training')}
                {tabBtn('qrcodes', 'QR Codes', 'ems-detail-tab-qrcodes')}
            </nav>
            <div style={{ padding: '24px', background: '#fff', border: '1px solid #dcdcde', borderTop: 'none' }}>
                {activeTab === 'overview' && <OverviewTab event={event} osmEvents={osmEvents} onUpdated={handleUpdated} />}
                {activeTab === 'teams' && <TeamsTab event={event} explorers={explorers} />}
                {activeTab === 'training' && <TrainingTab eventId={event.ID} />}
                {activeTab === 'qrcodes' && <QRCodesTab event={event} />}
            </div>
        </div>
    );
};

export default EventDetailPage;
