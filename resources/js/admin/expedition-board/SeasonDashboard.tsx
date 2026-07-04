import React, { useState, useCallback } from 'react';
import { BoardData, Season, Expedition, Team, Member, Explorer, OSMEvent } from './types';
import { EventForm } from './EventForm';
import { sameTypeEvents, findEventOfTeam, previewTeamCode, nextTeamNumber, memberKey, sortByName, sortByFirstName } from './boardUtils';

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
    osmEvents?: OSMEvent[];
    osmEventsLoading?: boolean;
}

export const SeasonDashboard: React.FC<SeasonDashboardProps> = ({ data, osmEvents = [], osmEventsLoading = false }) => {
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
            <div className="ems-board-filters ems-season-filter-bar">
                <label className="ems-season-filter-label">Filter expeditions:</label>
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
                    osmEvents={osmEvents}
                    osmEventsLoading={osmEventsLoading}
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
    osmEvents?: OSMEvent[];
    osmEventsLoading?: boolean;
}> = ({ season, explorers, expandedEvents, setExpandedEvents, updateBoard, filters, osmEvents = [], osmEventsLoading = false }) => {
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
        <div className="ems-season-card">
            <div className="ems-season-header">
                <h2 className="ems-season-title">{seasonTitle(season)}</h2>
                <div className="ems-season-actions">
                    {canDeleteSeason && (
                        <button
                            type="button"
                            className="button-link ems-btn-danger"
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
                <div className="ems-event-form-container">
                    <EventForm
                        seasonId={season.ID}
                        osmEvents={osmEvents}
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
                        osmEvents={osmEvents}
                    />
                ))
            )}
        </div>
    );
};

const EventCard: React.FC<{ season: Season; event: Expedition; explorers: Explorer[]; expanded: boolean; onToggle: () => void; updateBoard: (updater: (b: BoardData) => void) => void; osmEvents?: OSMEvent[] }> = ({ season, event, explorers, expanded, onToggle, updateBoard, osmEvents = [] }) => {
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
        <div className="ems-event-card ems-event-card-wrapper">
            <div
                className={`ems-event-header ems-event-card-header ems-event-header--${event.ems_level || 'default'}`}
                onClick={onToggle}
                data-testid={`event-header-${event.ID}`}
                aria-expanded={expanded}
            >
                <div className="ems-event-header__content">
                    <span className="ems-event-header__toggle" aria-hidden="true">
                        {expanded ? '▾' : '▸'}
                    </span>
                    <div className="ems-event-header__info">
                        <div className="ems-event-header__title-row">
                            <strong className="ems-event-header__title">{event.post_title || 'Untitled expedition'} ({event.ems_event_code})</strong>
                            {dateRange && (
                                <span className="ems-event-header__date">
                                    {dateRange}
                                </span>
                            )}
                        </div>
                        <div className="ems-event-header__pills">
                            <span className={`ems-pill ems-pill--${event.ems_type}`}>{typeIcon(event.ems_type)}</span>
                            <span className={`ems-pill ems-pill--${event.ems_transport}`}>{transportIcon(event.ems_transport)}</span>
                            <span className={`ems-pill ems-pill--${event.ems_level}`}>{levelIcon(event.ems_level)}</span>
                            <span className={`ems-pill ems-pill--fa-${event.ems_first_aid_level || 'none'}`}>{firstAidIcon(event.ems_first_aid_level)}</span>
                            <span className="ems-event-header__counts">{event.teams.length} team{event.teams.length !== 1 ? 's' : ''}, {event.member_count ?? 0} member{(event.member_count ?? 0) !== 1 ? 's' : ''}</span>
                        </div>
                    </div>
                </div>
                <div className="ems-event-header__actions">
                    {canDeleteEvent && (
                        <button
                            type="button"
                            className="button-link ems-btn-danger"
                            onClick={deleteEvent}
                            disabled={busy}
                            aria-label={`Delete expedition ${event.post_title}`}
                        >
                            Delete
                        </button>
                    )}
                    <button
                        type="button"
                        className="button"
                        onClick={handleEdit}
                    >
                        Edit
                    </button>
                </div>
            </div>
            {metaItems.length > 0 && (
                <div className="ems-event-meta ems-event-meta-row">
                    {metaItems.map((item, index) => (
                        <span key={index}>{item}</span>
                    ))}
                </div>
            )}
            {isEditing && event.season_id && (
                <div className="ems-event-edit ems-event-edit-container">
                    <EventForm
                        seasonId={event.season_id}
                        initialEvent={event}
                        osmEvents={osmEvents}
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
                <div className="ems-event-teams ems-event-teams-section">
                    <div className="ems-event-teams__actions">
                        <button type="button" className="button" onClick={addTeam} disabled={busy}>+ Add Team</button>
                    </div>
                    {event.teams.length === 0 ? (
                        <p>No teams in this event.</p>
                    ) : (
                        <div className="ems-team-columns">
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
    const available = sortByFirstName(uniqueExplorers.filter((e) => !assigned.has(e.scout_id)));
    const sortedMembers = sortByFirstName(members);

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
                            first_aid_level: ex?.first_aid_level ?? (m.first_aid_level || 'none'),
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

    const firstAidWarning = (() => {
        const required = event.ems_first_aid_level ?? 'none';
        if (required === 'none') return false;
        const qualified = members.filter((m) => {
            const level = m.first_aid_level ?? 'none';
            if (required === 'full_first_aid') return level === 'full_first_aid';
            return level === 'first_response' || level === 'full_first_aid';
        }).length;
        return qualified < 2;
    })();

    return (
        <div className="ems-team-column">
            <div className="ems-team-column__header">
                <span>{team.ems_team_code}</span>
                <span className="ems-team-column__actions">
                    {members.length}
                    {team.size_warning && (
                        <span className="ems-size-warning" title="Team size outside 4–7">
                            !
                        </span>
                    )}
                    {firstAidWarning && (
                        <span className="ems-first-aid-warning" title="Fewer than 2 qualified first aiders">
                            ⚕
                        </span>
                    )}
                    <button
                        type="button"
                        className="button-link ems-team-icon-btn"
                        title="Move team to another event"
                        aria-label={`Move team ${team.ems_team_code} to another event`}
                        onClick={() => { setDialog('moveTeam'); setTargetEventId(''); }}
                        disabled={busy}
                    >
                        ↗
                    </button>
                    <button
                        type="button"
                        className="button-link ems-team-icon-btn"
                        title="Duplicate team to another event"
                        aria-label={`Duplicate team ${team.ems_team_code} to another event`}
                        onClick={() => { setDialog('duplicateTeam'); setTargetEventId(''); }}
                        disabled={busy}
                    >
                        ⧺
                    </button>
                    {members.length === 0 && (
                        <button type="button" className="button-link ems-team-icon-btn--danger" onClick={deleteTeam} disabled={busy} aria-label={`Delete team ${team.ems_team_code}`}>
                            ×
                        </button>
                    )}
                </span>
            </div>
            <div className="ems-team-column__body">
                {firstAidWarning && (
                    <div className="ems-first-aid-alert">
                        ⚠ First aid requirement not met
                    </div>
                )}
                {team.size_warning && (
                    <div className="ems-size-alert">
                        ⚠ Team size requirement not met
                    </div>
                )}
                <ul className="ems-team-member-list">
                    {sortedMembers.map((member) => (
                        <li key={member.scout_id ?? member.user_id} className="ems-team-member__item">
                            <span className="ems-team-member__name">
                                {member.first_aid_level === 'first_response' && <span className="ems-fa-icon-response" title="First Response">✚</span>}
                                {member.first_aid_level === 'full_first_aid' && <span className="ems-fa-icon-full" title="Full First Aid">⊕</span>}
                                {member.first_name} {member.last_name}
                                {member.patrol && <span className="ems-team-member__patrol">({member.patrol})</span>}
                            </span>
                            <span className="ems-team-member__actions">
                                <button
                                    type="button"
                                    className="button-link ems-member-move-btn"
                                    title="Move explorer to another team"
                                    aria-label={`Move ${member.first_name} ${member.last_name} to another team`}
                                    onClick={() => { setSelectedMember(member); setDialog('moveExplorer'); setTargetTeamId(''); }}
                                    disabled={busy}
                                >
                                    ↗
                                </button>
                                <button
                                    type="button"
                                    className="button-link ems-member-remove-btn"
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
                <div className="ems-explorer-add">
                    <select
                        aria-label={`Add explorer to ${team.ems_team_code}`}
                        className="ems-explorer-add__select"
                        value={selected}
                        onChange={(e) => setSelected(e.target.value)}
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
                    <div className="ems-team-dialog">
                        {dialog === 'moveTeam' && (
                            <>
                                <div className="ems-team-dialog__label">
                                    Move Team to Event:
                                </div>
                                <select
                                    className="ems-team-dialog__select"
                                    value={targetEventId}
                                    onChange={(e) => setTargetEventId(e.target.value)}
                                >
                                    <option value="">— Select event —</option>
                                    {targetEvents.map((e) => (<option key={e.ID} value={e.ID}>{e.ems_event_code}</option>))}
                                </select>
                                {preview && <p className="ems-team-dialog__preview">Re-coded to: {preview}</p>}
                                <div className="ems-team-dialog__actions">
                                    <button type="button" className="button button-small" onClick={closeDialog} disabled={busy}>Cancel</button>
                                    <button type="button" className="button button-small button-primary" onClick={moveTeam} disabled={busy || !targetEvent}>Move</button>
                                </div>
                            </>
                        )}
                        {dialog === 'duplicateTeam' && (
                            <>
                                <div className="ems-team-dialog__label">
                                    Duplicate Team to Event:
                                </div>
                                <select
                                    className="ems-team-dialog__select"
                                    value={targetEventId}
                                    onChange={(e) => setTargetEventId(e.target.value)}
                                >
                                    <option value="">— Select event —</option>
                                    {targetEvents.map((e) => (<option key={e.ID} value={e.ID}>{e.ems_event_code}</option>))}
                                </select>
                                {preview && <p className="ems-team-dialog__preview">New team code: {preview}</p>}
                                <div className="ems-team-dialog__actions">
                                    <button type="button" className="button button-small" onClick={closeDialog} disabled={busy}>Cancel</button>
                                    <button type="button" className="button button-small button-primary" onClick={duplicateTeam} disabled={busy || !targetEvent}>Duplicate</button>
                                </div>
                            </>
                        )}
                        {dialog === 'moveExplorer' && selectedMember && (
                            <>
                                <div className="ems-team-dialog__label ems-team-dialog__label--mt-2">
                                    Move explorer:
                                </div>
                                <div className="ems-team-dialog__explorer">
                                    {selectedMember.first_name} {selectedMember.last_name}
                                </div>
                                <select
                                    className="ems-team-dialog__select"
                                    value={targetTeamId}
                                    onChange={(e) => setTargetTeamId(e.target.value)}
                                >
                                    <option value="">— Select team —</option>
                                    {explorerTargetTeams.map((t) => (<option key={t.ID} value={t.ID}>{t.ems_team_code}</option>))}
                                </select>
                                <div className="ems-team-dialog__actions">
                                    <button type="button" className="button button-small" onClick={closeDialog} disabled={busy}>Cancel</button>
                                    <button type="button" className="button button-small button-primary" onClick={moveExplorer} disabled={busy || !targetTeamId}>Move</button>
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

function eventHeaderBackground(level: string): string {
    switch (level) {
        case 'bronze': return '#f9f0e8';
        case 'silver': return '#f2f2f2';
        case 'gold': return '#fff9e6';
        default: return '#f7f7f7';
    }
}

function typePillStyle(type: string): React.CSSProperties {
    const colors: Record<string, { bg: string; color: string }> = {
        training: { bg: '#e3f2fd', color: '#1565c0' },
        practice: { bg: '#e8f5e9', color: '#2e7d32' },
        qualifying: { bg: '#f3e5f5', color: '#7b1fa2' },
    };
    return pillStyle(colors[type] || { bg: '#eee', color: '#666' });
}

function transportPillStyle(transport?: string): React.CSSProperties {
    const colors: Record<string, { bg: string; color: string }> = {
        hillwalking: { bg: '#efebe9', color: '#5d4037' },
        biking: { bg: '#e0f2f1', color: '#00695c' },
        paddling: { bg: '#e1f5fe', color: '#0277bd' },
    };
    return pillStyle(colors[transport || ''] || { bg: '#eee', color: '#666' });
}

function levelPillStyle(level: string): React.CSSProperties {
    const colors: Record<string, { bg: string; color: string }> = {
        bronze: { bg: '#f0d4b8', color: '#7a4410' },
        silver: { bg: '#e0e0e0', color: '#444' },
        gold: { bg: '#fff3cd', color: '#7a5c10' },
    };
    return pillStyle(colors[level] || { bg: '#eee', color: '#666' });
}

function pillStyle(colors: { bg: string; color: string }): React.CSSProperties {
    return {
        display: 'inline-flex',
        alignItems: 'center',
        gap: '2px',
        fontSize: '11px',
        fontWeight: '600',
        padding: '3px 8px',
        borderRadius: '12px',
        background: colors.bg,
        color: colors.color,
    };
}

function firstAidIcon(level?: string): string {
    switch (level) {
        case 'first_response': return '✚ First Response';
        case 'full_first_aid': return '⊕ Full First Aid';
        default: return 'No first aid';
    }
}

function firstAidPillStyle(level?: string): React.CSSProperties {
    const colors: Record<string, { bg: string; color: string }> = {
        none: { bg: '#f5f5f5', color: '#666' },
        first_response: { bg: '#e8f5e9', color: '#2e7d32' },
        full_first_aid: { bg: '#c8e6c9', color: '#1b5e20' },
    };
    return pillStyle(colors[level ?? 'none'] || colors.none);
}

export default SeasonDashboard;
