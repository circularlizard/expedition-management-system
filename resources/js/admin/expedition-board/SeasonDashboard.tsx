import React, { useState, useCallback } from 'react';
import { BoardData, Season, Expedition, Team, Member, Explorer } from './types';
import { EventForm } from './EventForm';
import { sameTypeEvents, findEventOfTeam, previewTeamCode, nextTeamNumber, memberKey } from './boardUtils';

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
    const [filterType, setFilterType] = useState<string>('');
    const [filterTransport, setFilterTransport] = useState<string>('');
    const [filterLevel, setFilterLevel] = useState<string>('');

    const updateBoard = useCallback((updater: (b: BoardData) => void) => {
        setBoard((prev) => {
            const next = JSON.parse(JSON.stringify(prev)) as BoardData;
            updater(next);
            return next;
        });
    }, []);

    const clearFilters = () => {
        setFilterType('');
        setFilterTransport('');
        setFilterLevel('');
    };

    const hasFilters = filterType || filterTransport || filterLevel;

    if (!board.seasons || board.seasons.length === 0) {
        return <div className="notice notice-info">Create your first season to begin planning expeditions.</div>;
    }

    return (
        <div className="ems-season-dashboard">
            <div className="ems-board-filters" style={{ marginBottom: '16px', padding: '12px', background: '#fff', border: '1px solid #ddd', display: 'flex', gap: '12px', alignItems: 'center', flexWrap: 'wrap' }}>
                <label style={{ fontWeight: 600 }}>Filter expeditions:</label>
                <select aria-label="Filter by type" value={filterType} onChange={(e) => setFilterType(e.target.value)}>
                    <option value="">All types</option>
                    <option value="training">Training</option>
                    <option value="practice">Practice</option>
                    <option value="qualifying">Qualifying</option>
                </select>
                <select aria-label="Filter by transport" value={filterTransport} onChange={(e) => setFilterTransport(e.target.value)}>
                    <option value="">All transport</option>
                    <option value="hillwalking">Hillwalking</option>
                    <option value="biking">Biking</option>
                    <option value="paddling">Paddling</option>
                </select>
                <select aria-label="Filter by level" value={filterLevel} onChange={(e) => setFilterLevel(e.target.value)}>
                    <option value="">All levels</option>
                    <option value="bronze">Bronze</option>
                    <option value="silver">Silver</option>
                    <option value="gold">Gold</option>
                </select>
                {hasFilters && (
                    <button type="button" className="button-link" onClick={clearFilters}>
                        Clear filters
                    </button>
                )}
            </div>
            {board.seasons.map((season) => (
                <SeasonCard
                    key={season.ID}
                    season={season}
                    explorers={board.explorers ?? []}
                    expandedEvents={expandedEvents}
                    setExpandedEvents={setExpandedEvents}
                    updateBoard={updateBoard}
                    filters={{ type: filterType, transport: filterTransport, level: filterLevel }}
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

interface EventFilters {
    type: string;
    transport: string;
    level: string;
}

const SeasonCard: React.FC<{
    season: Season;
    explorers: Explorer[];
    expandedEvents: Set<number>;
    setExpandedEvents: React.Dispatch<React.SetStateAction<Set<number>>>;
    updateBoard: (updater: (b: BoardData) => void) => void;
    filters: EventFilters;
}> = ({ season, explorers, expandedEvents, setExpandedEvents, updateBoard, filters }) => {
    const [showEventForm, setShowEventForm] = useState(false);
    const [deleting, setDeleting] = useState(false);
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

    const deleteSeason = async () => {
        if (!confirm(`Delete season "${seasonTitle(season)}"? This cannot be undone.`)) return;
        setDeleting(true);
        try {
            const response = await del(`/seasons/${season.ID}`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            updateBoard((b) => {
                b.seasons = (b.seasons ?? []).filter((s) => s.ID !== season.ID);
            });
        } catch (e) {
            console.error('Failed to delete season:', e);
        } finally {
            setDeleting(false);
        }
    };

    const filteredEvents = season.events.filter((event) => {
        if (filters.type && event.ems_type !== filters.type) return false;
        if (filters.transport && event.ems_transport !== filters.transport) return false;
        if (filters.level && event.ems_level !== filters.level) return false;
        return true;
    });
    const canDeleteSeason = season.events.length === 0;

    return (
        <div className="ems-season-card" style={{ marginBottom: '24px', border: '1px solid #ddd', padding: '16px', background: '#fff' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
                <h2 style={{ margin: 0 }}>{seasonTitle(season)}</h2>
                <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                    {canDeleteSeason && (
                        <button
                            type="button"
                            className="button-link"
                            style={{ color: '#d63638' }}
                            onClick={deleteSeason}
                            disabled={deleting}
                            aria-label={`Delete season ${seasonTitle(season)}`}
                        >
                            Delete season
                        </button>
                    )}
                    <button
                        type="button"
                        className="button"
                        onClick={() => setShowEventForm((v) => !v)}
                    >
                        {showEventForm ? 'Close' : 'Create Event'}
                    </button>
                </div>
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
            ) : filteredEvents.length === 0 ? (
                <p>No expeditions match the current filters.</p>
            ) : (
                filteredEvents.map((event) => (
                    <EventCard
                        key={event.ID}
                        season={season}
                        event={event}
                        explorers={explorers}
                        expanded={expandedEvents.has(event.ID)}
                        onToggle={() => toggleEvent(event.ID)}
                        updateBoard={updateBoard}
                    />
                ))
            )}
        </div>
    );
};

const EventCard: React.FC<{ season: Season; event: Expedition; explorers: Explorer[]; expanded: boolean; onToggle: () => void; updateBoard: (updater: (b: BoardData) => void) => void }> = ({ season, event, explorers, expanded, onToggle, updateBoard }) => {
    const [busy, setBusy] = useState(false);
    const [isEditing, setIsEditing] = useState(false);

    const addTeam = async () => {
        setBusy(true);
        try {
            const response = await postJson(`/events/${event.ID}/teams`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const newTeam = await response.json() as Team;
            updateBoard((b) => {
                const e = findEvent(b, event.ID);
                if (e) {
                    e.teams.push({ ...newTeam, members: [] });
                    e.member_count = (e.member_count ?? 0) + (newTeam.member_count ?? 0);
                }
            });
        } catch (e) {
            console.error('Failed to add team:', e);
        } finally {
            setBusy(false);
        }
    };

    const deleteEvent = async () => {
        if (!confirm(`Delete expedition "${event.post_title}"? This cannot be undone.`)) return;
        setBusy(true);
        try {
            const response = await del(`/events/${event.ID}`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            updateBoard((b) => {
                for (const s of b.seasons ?? []) {
                    s.events = s.events.filter((e) => e.ID !== event.ID);
                }
            });
        } catch (e) {
            console.error('Failed to delete event:', e);
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

    const dateRange = formatDateRange();
    const canDeleteEvent = event.teams.length === 0 && (event.member_count ?? 0) === 0;

    const metaItems = [
        event.ems_start_location ? `Start: ${event.ems_start_location}` : '',
        event.ems_end_location ? `End: ${event.ems_end_location}` : '',
        event.ems_start_time ? `Time: ${event.ems_start_time}${event.ems_end_time ? ` — ${event.ems_end_time}` : ''}` : '',
        event.ems_lic_name ? `Leader: ${event.ems_lic_name}${event.ems_lic_phone ? ` (${event.ems_lic_phone})` : ''}` : '',
        event.ems_status ? `Status: ${event.ems_status}` : '',
        event.ems_route_deadline ? `Route deadline: ${formatShortDate(event.ems_route_deadline)}` : '',
    ].filter(Boolean);

    return (
        <div className="ems-event-card" style={{ marginBottom: '12px', border: '1px solid #eee', padding: '12px', background: '#fff' }}>
            <div
                className="ems-event-header"
                onClick={onToggle}
                style={{ cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '12px', padding: '10px 12px', margin: '-12px -12px 12px -12px', background: '#f7f7f7', borderBottom: '1px solid #eee' }}
                data-testid={`event-header-${event.ID}`}
                aria-expanded={expanded}
            >
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: '8px', flex: '1', minWidth: 0 }}>
                    <span style={{ fontSize: '14px', color: '#666', marginTop: '3px', flexShrink: 0 }} aria-hidden="true">
                        {expanded ? '▾' : '▸'}
                    </span>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', flex: '1', minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'baseline', gap: '10px', flexWrap: 'wrap' }}>
                            <strong style={{ fontSize: '17px' }}>{event.ems_event_code}</strong>
                            {dateRange && (
                                <span style={{ fontSize: '15px', color: '#333', fontWeight: 500 }}>
                                    {dateRange}
                                </span>
                            )}
                        </div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap', fontSize: '13px', color: '#666' }}>
                            {event.post_title && <span>{event.post_title}</span>}
                            <span>{typeIcon(event.ems_type)} · {transportIcon(event.ems_transport)} · {levelIcon(event.ems_level)}</span>
                            <span>{event.teams.length} team{event.teams.length !== 1 ? 's' : ''}, {event.member_count ?? 0} member{(event.member_count ?? 0) !== 1 ? 's' : ''}</span>
                        </div>
                    </div>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexShrink: 0 }}>
                    {canDeleteEvent && (
                        <button
                            type="button"
                            className="button-link"
                            onClick={deleteEvent}
                            disabled={busy}
                            style={{ color: '#d63638', fontSize: '12px' }}
                            aria-label={`Delete expedition ${event.post_title}`}
                        >
                            Delete
                        </button>
                    )}
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
            {metaItems.length > 0 && (
                <div className="ems-event-meta" style={{ marginBottom: '12px', fontSize: '12px', color: '#666', display: 'flex', gap: '12px', flexWrap: 'wrap' }}>
                    {metaItems.map((item, index) => (
                        <span key={index}>{item}</span>
                    ))}
                </div>
            )}
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
                <div className="ems-event-teams" style={{ marginTop: '16px' }}>
                    <div style={{ marginBottom: '12px' }}>
                        <button type="button" className="button" onClick={addTeam} disabled={busy}>+ Add Team</button>
                    </div>
                    {event.teams.length === 0 ? (
                        <p>No teams in this event.</p>
                    ) : (
                        <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap', alignItems: 'flex-start' }}>
                            {event.teams.map((team) => (
                                <TeamColumn key={team.ID} team={team} event={event} season={season} explorers={explorers} updateBoard={updateBoard} />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

const TeamColumn: React.FC<{ team: Team; event: Expedition; season: Season; explorers: Explorer[]; updateBoard: (updater: (b: BoardData) => void) => void }> = ({ team, event, season, explorers, updateBoard }) => {
    const [selected, setSelected] = useState('');
    const [busy, setBusy] = useState(false);
    const [dialog, setDialog] = useState<'moveTeam' | 'duplicateTeam' | 'moveExplorer' | null>(null);
    const [targetEventId, setTargetEventId] = useState('');
    const [targetTeamId, setTargetTeamId] = useState('');
    const [selectedMember, setSelectedMember] = useState<Member | null>(null);

    const members: Member[] = team.members ?? [];
    const assigned = new Set(
        event.teams.flatMap((t) => t.members?.map((m) => m.scout_id).filter((id): id is number => id !== undefined) ?? [])
    );
    const uniqueExplorers = explorers.filter((e, index, self) =>
        self.findIndex((ex) => ex.scout_id === e.scout_id) === index
    );
    const available = uniqueExplorers.filter((e) => !assigned.has(e.scout_id));
    const sortedMembers = [...members].sort((a, b) => {
        const aName = `${a.last_name ?? ''}, ${a.first_name ?? ''}`;
        const bName = `${b.last_name ?? ''}, ${b.first_name ?? ''}`;
        return aName.localeCompare(bName);
    });

    const closeDialog = () => {
        setDialog(null);
        setTargetEventId('');
        setTargetTeamId('');
        setSelectedMember(null);
    };

    const addMember = async () => {
        if (!selected) return;
        setBusy(true);
        try {
            const response = await postJson(`/teams/${team.ID}/members`, { scout_id: Number(selected) });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
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
        } catch (e) {
            console.error('Failed to add member:', e);
        } finally {
            setBusy(false);
        }
    };

    const removeMember = async (scoutId: number) => {
        setBusy(true);
        try {
            const response = await del(`/teams/${team.ID}/members/${scoutId}`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
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
        } catch (e) {
            console.error('Failed to remove member:', e);
        } finally {
            setBusy(false);
        }
    };

    const deleteTeam = async () => {
        setBusy(true);
        try {
            const response = await del(`/teams/${team.ID}`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            updateBoard((b) => {
                const ev = findParentEvent(b, team.ID);
                if (ev) {
                    ev.teams = ev.teams.filter((tm) => tm.ID !== team.ID);
                    ev.member_count = ev.teams.reduce((sum, tm) => sum + (tm.member_count ?? 0), 0);
                }
            });
        } catch (e) {
            console.error('Failed to delete team:', e);
        } finally {
            setBusy(false);
        }
    };

    const moveTargets = sameTypeEvents(season, event);
    const duplicateTargets = season.events.filter((e) => e.ID !== event.ID);
    const targetEvents = dialog === 'moveTeam' ? moveTargets : dialog === 'duplicateTeam' ? duplicateTargets : [];
    const targetEvent = targetEvents.find((e) => e.ID === Number(targetEventId));
    const preview = targetEvent ? previewTeamCode(targetEvent) : null;

    const explorerTargetTeams = (() => {
        if (dialog !== 'moveExplorer' || !selectedMember) return [];
        const sameEventTeams = event.teams.filter((t) => t.ID !== team.ID);
        const otherEventTeams = sameTypeEvents(season, event).flatMap((e) => e.teams);
        return [...sameEventTeams, ...otherEventTeams];
    })();

    const moveTeam = async () => {
        if (!targetEvent) return;
        setBusy(true);
        try {
            const response = await postJson(`/teams/${team.ID}/move`, { target_event_id: targetEvent.ID });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const newCode = previewTeamCode(targetEvent);
            const newNumber = nextTeamNumber(targetEvent);
            updateBoard((b) => {
                const srcEv = findEvent(b, event.ID);
                const tgtEv = findEvent(b, targetEvent.ID);
                if (srcEv && tgtEv) {
                    const moving = srcEv.teams.find((t) => t.ID === team.ID);
                    if (moving) {
                        srcEv.teams = srcEv.teams.filter((t) => t.ID !== team.ID);
                        moving.ems_team_code = newCode;
                        moving.ems_team_number = newNumber;
                        moving.event_id = targetEvent.ID;
                        tgtEv.teams.push(moving);
                        srcEv.member_count = srcEv.teams.reduce((sum, tm) => sum + (tm.member_count ?? 0), 0);
                        tgtEv.member_count = tgtEv.teams.reduce((sum, tm) => sum + (tm.member_count ?? 0), 0);
                    }
                }
            });
            closeDialog();
        } catch (e) {
            console.error('Failed to move team:', e);
        } finally {
            setBusy(false);
        }
    };

    const duplicateTeam = async () => {
        if (!targetEvent) return;
        setBusy(true);
        try {
            const response = await postJson(`/teams/${team.ID}/duplicate`, { target_event_id: targetEvent.ID });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const newCode = previewTeamCode(targetEvent);
            const newNumber = nextTeamNumber(targetEvent);
            const newId = Date.now();
            updateBoard((b) => {
                const tgtEv = findEvent(b, targetEvent.ID);
                if (tgtEv) {
                    const copy: Team = {
                        ...team,
                        ID: newId,
                        ems_team_code: newCode,
                        ems_team_number: newNumber,
                        event_id: targetEvent.ID,
                        members: (team.members ?? []).map((m) => ({ ...m })),
                        member_count: (team.members ?? []).length,
                    };
                    tgtEv.teams.push(copy);
                    tgtEv.member_count = (tgtEv.member_count ?? 0) + (copy.member_count ?? 0);
                }
            });
            closeDialog();
        } catch (e) {
            console.error('Failed to duplicate team:', e);
        } finally {
            setBusy(false);
        }
    };

    const moveExplorer = async () => {
        if (!selectedMember || !targetTeamId) return;
        const memberId = memberKey(selectedMember);
        const destTeamId = Number(targetTeamId);
        setBusy(true);
        try {
            const response = await postJson(`/explorers/${memberId}/move-team`, { target_team_id: destTeamId });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            updateBoard((b) => {
                const srcTeam = findTeam(b, team.ID);
                if (srcTeam) {
                    const moved = srcTeam.members?.find((m) => memberKey(m) === memberId);
                    srcTeam.members = (srcTeam.members ?? []).filter((m) => memberKey(m) !== memberId);
                    srcTeam.member_count = srcTeam.members.length;
                    srcTeam.size_warning = srcTeam.member_count < 4 || srcTeam.member_count > 7;
                    const srcEv = findParentEvent(b, team.ID);
                    if (srcEv) {
                        if (srcTeam.member_count === 0) {
                            srcEv.teams = srcEv.teams.filter((t) => t.ID !== team.ID);
                        }
                        srcEv.member_count = srcEv.teams.reduce((sum, tm) => sum + (tm.member_count ?? 0), 0);
                    }
                    const destTeam = findTeam(b, destTeamId);
                    if (destTeam && moved) {
                        destTeam.members = [...(destTeam.members ?? []), moved];
                        destTeam.member_count = destTeam.members.length;
                        destTeam.size_warning = destTeam.member_count < 4 || destTeam.member_count > 7;
                        const destEv = findParentEvent(b, destTeamId);
                        if (destEv) {
                            destEv.member_count = destEv.teams.reduce((sum, tm) => sum + (tm.member_count ?? 0), 0);
                        }
                    }
                }
            });
            closeDialog();
        } catch (e) {
            console.error('Failed to move explorer:', e);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="ems-team-column" style={{ flex: '1 1 200px', minWidth: '180px', maxWidth: '260px', border: '1px solid #eee', background: '#fafafa' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 10px', background: '#f0f0f0', borderBottom: '1px solid #eee', fontWeight: 600 }}>
                <span>{team.ems_team_code}</span>
                <span style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
                    {members.length}
                    {team.size_warning && (
                        <span className="ems-size-warning" title="Team size outside 4–7" style={{ color: '#d63638', fontWeight: 'bold' }}>
                            !
                        </span>
                    )}
                    <button
                        type="button"
                        className="button-link"
                        style={{ fontSize: '14px', lineHeight: 1, padding: '0 3px' }}
                        title="Move team to another event"
                        aria-label={`Move team ${team.ems_team_code} to another event`}
                        onClick={() => { setDialog('moveTeam'); setTargetEventId(''); }}
                        disabled={busy}
                    >
                        ↗
                    </button>
                    <button
                        type="button"
                        className="button-link"
                        style={{ fontSize: '14px', lineHeight: 1, padding: '0 3px' }}
                        title="Duplicate team to another event"
                        aria-label={`Duplicate team ${team.ems_team_code} to another event`}
                        onClick={() => { setDialog('duplicateTeam'); setTargetEventId(''); }}
                        disabled={busy}
                    >
                        ⧺
                    </button>
                    {members.length === 0 && (
                        <button type="button" className="button-link" style={{ color: '#d63638', fontSize: '12px' }} onClick={deleteTeam} disabled={busy} aria-label={`Delete team ${team.ems_team_code}`}>
                            ×
                        </button>
                    )}
                </span>
            </div>
            <div style={{ padding: '10px' }}>
                <ul style={{ margin: '0 0 12px 0', padding: 0, listStyle: 'none' }}>
                    {sortedMembers.map((member) => (
                        <li key={member.scout_id ?? member.user_id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '3px 0' }}>
                            <span>
                                {member.first_name} {member.last_name}
                                {member.patrol && <span style={{ fontSize: '11px', color: '#888', marginLeft: '4px' }}>({member.patrol})</span>}
                            </span>
                            <span style={{ display: 'flex', alignItems: 'center', gap: '2px' }}>
                                <button
                                    type="button"
                                    className="button-link"
                                    style={{ color: '#666', fontSize: '14px', lineHeight: 1, padding: '0 3px' }}
                                    title="Move explorer to another team"
                                    aria-label={`Move ${member.first_name} ${member.last_name} to another team`}
                                    onClick={() => { setSelectedMember(member); setDialog('moveExplorer'); setTargetTeamId(''); }}
                                    disabled={busy}
                                >
                                    ↗
                                </button>
                                <button
                                    type="button"
                                    className="button-link"
                                    style={{ color: '#d63638', fontSize: '16px', lineHeight: 1, padding: '0 4px' }}
                                    aria-label={`Remove ${member.first_name} ${member.last_name}`}
                                    onClick={() => removeMember(member.scout_id ?? 0)}
                                    disabled={busy}
                                >
                                    ×
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
                <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                    <select
                        aria-label={`Add explorer to ${team.ems_team_code}`}
                        value={selected}
                        onChange={(e) => setSelected(e.target.value)}
                        style={{ maxWidth: '100%', flex: '1 1 auto' }}
                    >
                        <option value="">Add…</option>
                        {available.map((e) => (
                            <option key={e.scout_id} value={e.scout_id}>
                                {e.first_name} {e.last_name}{e.patrol ? ` (${e.patrol})` : ''}
                            </option>
                        ))}
                    </select>
                    <button type="button" className="button" onClick={addMember} disabled={busy || !selected}>Add</button>
                </div>

                {dialog && (
                    <div className="ems-team-dialog" style={{ marginTop: '12px', padding: '10px', border: '1px solid #ddd', background: '#fff' }}>
                        {dialog === 'moveTeam' && (
                            <>
                                <label style={{ display: 'block', marginBottom: '8px', fontSize: '13px' }}>
                                    Move {team.ems_team_code} to event
                                    <select value={targetEventId} onChange={(e) => setTargetEventId(e.target.value)} style={{ display: 'block', marginTop: '4px', maxWidth: '100%' }}>
                                        <option value="">— Select event —</option>
                                        {targetEvents.map((e) => (<option key={e.ID} value={e.ID}>{e.ems_event_code}</option>))}
                                    </select>
                                </label>
                                {preview && <p style={{ fontSize: '12px', color: '#666', marginBottom: '8px' }}>Will be re-coded to {preview}</p>}
                                <div style={{ display: 'flex', gap: '8px' }}>
                                    <button type="button" className="button button-primary" onClick={moveTeam} disabled={busy || !targetEvent}>Move</button>
                                    <button type="button" className="button" onClick={closeDialog} disabled={busy}>Cancel</button>
                                </div>
                            </>
                        )}
                        {dialog === 'duplicateTeam' && (
                            <>
                                <label style={{ display: 'block', marginBottom: '8px', fontSize: '13px' }}>
                                    Duplicate {team.ems_team_code} to event
                                    <select value={targetEventId} onChange={(e) => setTargetEventId(e.target.value)} style={{ display: 'block', marginTop: '4px', maxWidth: '100%' }}>
                                        <option value="">— Select event —</option>
                                        {targetEvents.map((e) => (<option key={e.ID} value={e.ID}>{e.ems_event_code}</option>))}
                                    </select>
                                </label>
                                {preview && <p style={{ fontSize: '12px', color: '#666', marginBottom: '8px' }}>New team will be coded {preview}</p>}
                                <div style={{ display: 'flex', gap: '8px' }}>
                                    <button type="button" className="button button-primary" onClick={duplicateTeam} disabled={busy || !targetEvent}>Duplicate</button>
                                    <button type="button" className="button" onClick={closeDialog} disabled={busy}>Cancel</button>
                                </div>
                            </>
                        )}
                        {dialog === 'moveExplorer' && selectedMember && (
                            <>
                                <label style={{ display: 'block', marginBottom: '8px', fontSize: '13px' }}>
                                    Move {selectedMember.first_name} {selectedMember.last_name} to team
                                    <select value={targetTeamId} onChange={(e) => setTargetTeamId(e.target.value)} style={{ display: 'block', marginTop: '4px', maxWidth: '100%' }}>
                                        <option value="">— Select team —</option>
                                        {explorerTargetTeams.map((t) => (<option key={t.ID} value={t.ID}>{t.ems_team_code}</option>))}
                                    </select>
                                </label>
                                <div style={{ display: 'flex', gap: '8px' }}>
                                    <button type="button" className="button button-primary" onClick={moveExplorer} disabled={busy || !targetTeamId}>Move</button>
                                    <button type="button" className="button" onClick={closeDialog} disabled={busy}>Cancel</button>
                                </div>
                            </>
                        )}
                    </div>
                )}
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
        default: return type ? capitalize(type) : '';
    }
}

function transportIcon(transport?: string): string {
    switch (transport) {
        case 'hillwalking': return 'Hillwalking';
        case 'biking': return 'Biking';
        case 'paddling': return 'Paddling';
        default: return transport ? capitalize(transport) : '';
    }
}

function levelIcon(level: string): string {
    switch (level) {
        case 'bronze': return 'Bronze';
        case 'silver': return 'Silver';
        case 'gold': return 'Gold';
        default: return level ? capitalize(level) : 'Unknown';
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
