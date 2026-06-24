import React, { useState, useCallback } from 'react';
import { BoardData, Season, Expedition, Team, Member, Explorer } from './types';
import { EventForm } from './EventForm';

async function postJson(path: string, body?: unknown): Promise<Response> {
    const config = window.emsExpeditionBoard;
    return fetch(`${config.root_url}${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
        body: body ? JSON.stringify(body) : undefined,
    });
}

async function del(path: string): Promise<Response> {
    const config = window.emsExpeditionBoard;
    return fetch(`${config.root_url}${path}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': config.nonce },
    });
}

interface SeasonDashboardProps {
    data: BoardData;
}

export const SeasonDashboard: React.FC<SeasonDashboardProps> = ({ data }) => {
    const [board, setBoard] = useState<BoardData>(data);
    const [expandedEvents, setExpandedEvents] = useState<Set<number>>(new Set());

    const updateBoard = useCallback((updater: (b: BoardData) => void) => {
        setBoard((prev) => {
            const next = JSON.parse(JSON.stringify(prev)) as BoardData;
            updater(next);
            return next;
        });
    }, []);

    if (!board.seasons || board.seasons.length === 0) {
        return <div className="notice notice-info">Create your first season to begin planning expeditions.</div>;
    }

    return (
        <div className="ems-season-dashboard">
            {board.seasons.map((season) => (
                <SeasonCard
                    key={season.ID}
                    season={season}
                    explorers={board.explorers ?? []}
                    expandedEvents={expandedEvents}
                    setExpandedEvents={setExpandedEvents}
                    updateBoard={updateBoard}
                />
            ))}
        </div>
    );
};

function seasonTitle(season: Season): string {
    if (season.post_title && season.post_title.trim()) return season.post_title;
    if (season.ems_season_year) return `${season.ems_season_year} Season`;
    return `Season #${season.ID}`;
}

const SeasonCard: React.FC<{
    season: Season;
    explorers: Explorer[];
    expandedEvents: Set<number>;
    setExpandedEvents: React.Dispatch<React.SetStateAction<Set<number>>>;
    updateBoard: (updater: (b: BoardData) => void) => void;
}> = ({ season, explorers, expandedEvents, setExpandedEvents, updateBoard }) => {
    const [showEventForm, setShowEventForm] = useState(false);
    const toggleEvent = (eventId: number) => {
        setExpandedEvents((prev) => {
            const next = new Set(prev);
            if (next.has(eventId)) {
                next.delete(eventId);
            } else {
                next.add(eventId);
            }
            return next;
        });
    };

    const eventsByLevel = groupByLevel(season.events);

    return (
        <div className="ems-season-card" style={{ marginBottom: '24px', border: '1px solid #ddd', padding: '16px', background: '#fff' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <h2 style={{ margin: 0 }}>{seasonTitle(season)}</h2>
                <button
                    type="button"
                    className="button"
                    onClick={() => setShowEventForm((v) => !v)}
                >
                    {showEventForm ? 'Close' : 'Create Event'}
                </button>
            </div>

            {showEventForm && (
                <div style={{ margin: '12px 0' }}>
                    <EventForm
                        seasonId={season.ID}
                        onSaved={(savedEvent) => {
                            setShowEventForm(false);
                            updateBoard((b) => {
                                const s = b.seasons?.find((s) => s.ID === season.ID);
                                if (s) {
                                    const newEvent = {
                                        ...savedEvent,
                                        teams: savedEvent.teams ?? [],
                                        member_count: savedEvent.member_count ?? 0,
                                    };
                                    s.events.push(newEvent);
                                }
                            });
                        }}
                        onCancel={() => setShowEventForm(false)}
                    />
                </div>
            )}

            {season.events.length === 0 ? (
                <p>Create your first event for this season.</p>
            ) : (
                Object.entries(eventsByLevel).map(([level, events]) => (
                    <div key={level} className="ems-level-group" style={{ marginBottom: '16px' }}>
                        <h3>{capitalize(level)}</h3>
                        {events.map((event) => (
                            <EventCard
                                key={event.ID}
                                event={event}
                                explorers={explorers}
                                expanded={expandedEvents.has(event.ID)}
                                onToggle={() => toggleEvent(event.ID)}
                                updateBoard={updateBoard}
                            />
                        ))}
                    </div>
                ))
            )}
        </div>
    );
};

const EventCard: React.FC<{ event: Expedition; explorers: Explorer[]; expanded: boolean; onToggle: () => void; updateBoard: (updater: (b: BoardData) => void) => void }> = ({ event, explorers, expanded, onToggle, updateBoard }) => {
    const [busy, setBusy] = useState(false);
    const [isEditing, setIsEditing] = useState(false);

    const addTeam = async () => {
        setBusy(true);
        try {
            const response = await postJson(`/events/${event.ID}/teams`);
            const newTeam = await response.json() as Team;
            updateBoard((b) => {
                const e = findEvent(b, event.ID);
                if (e) {
                    e.teams.push({ ...newTeam, members: [] });
                    e.member_count = (e.member_count ?? 0) + (newTeam.member_count ?? 0);
                }
            });
        } finally {
            setBusy(false);
        }
    };

    const handleEventSaved = (updatedEvent: Expedition) => {
        setIsEditing(false);
        updateBoard((b) => {
            const e = findEvent(b, event.ID);
            if (e) Object.assign(e, updatedEvent);
        });
    };

    const formatDateRange = () => {
        const s = event.ems_start_date;
        const e = event.ems_end_date;
        if (!s) return '';
        if (s === e) return formatShortDate(s);
        if (e) return `${formatShortDate(s)} — ${formatShortDate(e)}`;
        return formatShortDate(s);
    };

    const handleEdit = (e: React.MouseEvent) => {
        e.stopPropagation();
        setIsEditing(true);
    };

    return (
        <div className="ems-event-card" style={{ marginBottom: '12px', border: '1px solid #eee', padding: '12px' }}>
            <div
                className="ems-event-header"
                onClick={onToggle}
                style={{ cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '12px' }}
                data-testid={`event-header-${event.ID}`}
            >
                <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', flex: '1', minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                        <strong style={{ whiteSpace: 'nowrap' }}>{event.post_title}</strong>
                        <span style={{ color: '#888', fontSize: '13px', fontFamily: 'monospace', whiteSpace: 'nowrap' }}>{event.ems_event_code}</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '3px', fontSize: '12px', color: '#666' }}>
                            {typeIcon(event.ems_type)}
                        </span>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '3px', fontSize: '12px', color: '#666' }}>
                            {transportIcon(event.ems_transport)}
                        </span>
                        <span style={levelBadgeStyle(event.ems_level)}>
                            {levelIcon(event.ems_level)}
                        </span>
                        {formatDateRange() && (
                            <span style={{ fontSize: '12px', color: '#666' }}>
                                {formatDateRange()}
                            </span>
                        )}
                    </div>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flexShrink: 0 }}>
                    <div style={{ textAlign: 'right' }}>
                        <div>{event.teams.length} team{event.teams.length !== 1 ? 's' : ''}</div>
                        <div style={{ color: '#666', fontSize: '13px' }}>{event.member_count ?? 0} member{((event.member_count ?? 0) !== 1) ? 's' : ''}</div>
                    </div>
                    <button
                        type="button"
                        className="button"
                        onClick={handleEdit}
                        style={{ fontSize: '12px' }}
                    >
                        Edit
                    </button>
                </div>
            </div>
            {isEditing && event.season_id && (
                <div className="ems-event-edit" style={{ marginTop: '12px' }}>
                    <EventForm
                        seasonId={event.season_id}
                        initialEvent={event}
                        onSaved={(savedEvent) => {
                            setIsEditing(false);
                            updateBoard((b) => {
                                const e = findEvent(b, event.ID);
                                if (e) {
                                    Object.assign(e, {
                                        ...savedEvent,
                                        teams: event.teams,
                                        member_count: event.member_count,
                                    });
                                }
                            });
                        }}
                        onCancel={() => setIsEditing(false)}
                    />
                </div>
            )}
            {expanded && !isEditing && (
                <div className="ems-event-teams" style={{ marginTop: '12px' }}>
                    <div style={{ marginBottom: '8px' }}>
                        <button type="button" className="button" onClick={addTeam} disabled={busy}>+ Add Team</button>
                    </div>
                    {event.teams.length === 0 ? (
                        <p>No teams in this event.</p>
                    ) : (
                        event.teams.map((team) => (
                            <TeamRow key={team.ID} team={team} explorers={explorers} updateBoard={updateBoard} />
                        ))
                    )}
                </div>
            )}
        </div>
    );
};

const TeamRow: React.FC<{ team: Team; explorers: Explorer[]; updateBoard: (updater: (b: BoardData) => void) => void }> = ({ team, explorers, updateBoard }) => {
    const [selected, setSelected] = useState('');
    const [busy, setBusy] = useState(false);

    const members: Member[] = team.members ?? [];
    const assigned = new Set(members.map((m) => m.scout_id));
    const available = explorers.filter((e) => !assigned.has(e.scout_id));

    const addMember = async () => {
        if (!selected) return;
        setBusy(true);
        try {
            const response = await postJson(`/teams/${team.ID}/members`, { scout_id: Number(selected) });
            const updatedMembers = await response.json() as Member[];
            const addedExplorer = explorers.find((e) => e.scout_id === Number(selected));
            updateBoard((b) => {
                const t = findTeam(b, team.ID);
                if (t) {
                    t.members = updatedMembers.map((m) => {
                        const ex = explorers.find((e) => e.scout_id === m.scout_id);
                        return {
                            ...m,
                            first_name: ex?.first_name ?? m.first_name ?? '',
                            last_name: ex?.last_name ?? m.last_name ?? '',
                            patrol: ex?.patrol ?? m.patrol ?? '',
                        };
                    });
                    t.member_count = updatedMembers.length;
                    t.size_warning = updatedMembers.length < 4 || updatedMembers.length > 7;
                    const ev = findParentEvent(b, team.ID);
                    if (ev) {
                        ev.member_count = ev.teams.reduce((sum, tm) => sum + (tm.member_count ?? 0), 0);
                    }
                }
                if (addedExplorer && b.explorers) {
                    b.explorers = b.explorers.filter((e) => e.scout_id !== addedExplorer.scout_id);
                }
            });
            setSelected('');
        } finally {
            setBusy(false);
        }
    };

    const removeMember = async (scoutId: number) => {
        setBusy(true);
        try {
            const response = await del(`/teams/${team.ID}/members/${scoutId}`);
            const data = await response.json();
            const removedExplorer = explorers.find((e) => e.scout_id === scoutId);
            updateBoard((b) => {
                const t = findTeam(b, team.ID);
                if (t) {
                    if (data.team_deleted) {
                        const ev = findParentEvent(b, team.ID);
                        if (ev) {
                            ev.teams = ev.teams.filter((tm) => tm.ID !== team.ID);
                            ev.member_count = ev.teams.reduce((sum, tm) => sum + (tm.member_count ?? 0), 0);
                        }
                    } else {
                        t.members = (t.members ?? []).filter((m) => m.scout_id !== scoutId);
                        t.member_count = t.members.length;
                        t.size_warning = t.members.length < 4 || t.members.length > 7;
                        const ev = findParentEvent(b, team.ID);
                        if (ev) {
                            ev.member_count = ev.teams.reduce((sum, tm) => sum + (tm.member_count ?? 0), 0);
                        }
                    }
                }
                if (removedExplorer && b.explorers) {
                    b.explorers = [...b.explorers, removedExplorer];
                }
            });
        } finally {
            setBusy(false);
        }
    };

    const deleteTeam = async () => {
        setBusy(true);
        try {
            await del(`/teams/${team.ID}`);
            updateBoard((b) => {
                const ev = findParentEvent(b, team.ID);
                if (ev) {
                    ev.teams = ev.teams.filter((tm) => tm.ID !== team.ID);
                    ev.member_count = ev.teams.reduce((sum, tm) => sum + (tm.member_count ?? 0), 0);
                }
            });
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="ems-team-row" style={{ marginBottom: '8px', padding: '8px', border: '1px solid #f0f0f0' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span>{team.ems_team_code}</span>
                <span>
                    {members.length} members
                    {team.size_warning && (
                        <span className="ems-size-warning" style={{ marginLeft: '8px', color: '#d63638', fontWeight: 'bold' }}>
                            Size warning
                        </span>
                    )}
                    {members.length === 0 && (
                        <button type="button" className="button-link" style={{ marginLeft: '8px', color: '#d63638' }} onClick={deleteTeam} disabled={busy}>
                            Delete team
                        </button>
                    )}
                </span>
            </div>
            {members.length > 0 && (
                <ul style={{ margin: '8px 0 0 0', paddingLeft: '20px', listStyle: 'none' }}>
                    {members.map((member) => (
                        <li key={member.scout_id ?? member.user_id} style={{ display: 'flex', justifyContent: 'space-between', maxWidth: '360px', padding: '2px 0' }}>
                            <span>{member.first_name} {member.last_name}</span>
                            <button
                                type="button"
                                className="button-link"
                                style={{ color: '#d63638' }}
                                aria-label={`Remove ${member.first_name} ${member.last_name}`}
                                onClick={() => removeMember(member.scout_id ?? 0)}
                                disabled={busy}
                            >
                                Remove
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <div style={{ marginTop: '8px', display: 'flex', gap: '8px' }}>
                <select
                    aria-label={`Add explorer to ${team.ems_team_code}`}
                    value={selected}
                    onChange={(e) => setSelected(e.target.value)}
                >
                    <option value="">— Add explorer —</option>
                    {available.map((e) => (
                        <option key={e.scout_id} value={e.scout_id}>
                            {e.first_name} {e.last_name}{e.patrol ? ` (${e.patrol})` : ''}
                        </option>
                    ))}
                </select>
                <button type="button" className="button" onClick={addMember} disabled={busy || !selected}>Add</button>
            </div>
        </div>
    );
};

function findEvent(b: BoardData, eventId: number): Expedition | null {
    for (const season of b.seasons ?? []) {
        for (const event of season.events ?? []) {
            if (event.ID === eventId) return event;
        }
    }
    return null;
}

function findTeam(b: BoardData, teamId: number): Team | null {
    for (const season of b.seasons ?? []) {
        for (const event of season.events ?? []) {
            for (const team of event.teams ?? []) {
                if (team.ID === teamId) return team;
            }
        }
    }
    return null;
}

function findParentEvent(b: BoardData, teamId: number): Expedition | null {
    for (const season of b.seasons ?? []) {
        for (const event of season.events ?? []) {
            for (const team of event.teams ?? []) {
                if (team.ID === teamId) return event;
            }
        }
    }
    return null;
}

function groupByLevel(events: Expedition[]): Record<string, Expedition[]> {
    const groups: Record<string, Expedition[]> = {};
    events.forEach((event) => {
        const level = event.ems_level || 'Unknown';
        if (!groups[level]) groups[level] = [];
        groups[level].push(event);
    });
    return groups;
}

function capitalize(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

function formatShortDate(dateStr: string): string {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

function typeIcon(type: string): string {
    switch (type) {
        case 'training': return 'Training';
        case 'practice': return 'Practice';
        case 'qualifying': return 'Qualifying';
        default: return type;
    }
}

function transportIcon(transport?: string): string {
    switch (transport) {
        case 'hillwalking': return 'Hillwalking';
        case 'biking': return 'Biking';
        case 'paddling': return 'Paddling';
        default: return '';
    }
}

function levelIcon(level: string): string {
    switch (level) {
        case 'bronze': return '';
        case 'silver': return '';
        case 'gold': return '';
        default: return '';
    }
}

function levelBadgeStyle(level: string): React.CSSProperties {
    const colors: Record<string, { bg: string; color: string }> = {
        bronze: { bg: '#f0d4b8', color: '#7a4410' },
        silver: { bg: '#e0e0e0', color: '#444' },
        gold: { bg: '#fff3cd', color: '#7a5c10' },
    };
    const c = colors[level] || { bg: '#eee', color: '#666' };
    return {
        display: 'inline-flex',
        alignItems: 'center',
        gap: '2px',
        fontSize: '11px',
        fontWeight: '600',
        padding: '2px 6px',
        borderRadius: '4px',
        background: c.bg,
        color: c.color,
    };
}

export default SeasonDashboard;
