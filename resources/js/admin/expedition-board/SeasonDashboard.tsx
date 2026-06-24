import React, { useState } from 'react';
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
    onChanged?: () => void;
}

export const SeasonDashboard: React.FC<SeasonDashboardProps> = ({ data, onChanged }) => {
    const [expandedEvents, setExpandedEvents] = useState<Set<number>>(new Set());

    if (!data.seasons || data.seasons.length === 0) {
        return <div className="notice notice-info">Create your first season to begin planning expeditions.</div>;
    }

    return (
        <div className="ems-season-dashboard">
            {data.seasons.map((season) => (
                <SeasonCard
                    key={season.ID}
                    season={season}
                    explorers={data.explorers ?? []}
                    expandedEvents={expandedEvents}
                    setExpandedEvents={setExpandedEvents}
                    onChanged={onChanged}
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
    onChanged?: () => void;
}> = ({ season, explorers, expandedEvents, setExpandedEvents, onChanged }) => {
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
                        onSaved={() => { setShowEventForm(false); onChanged?.(); }}
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
                                onChanged={onChanged}
                            />
                        ))}
                    </div>
                ))
            )}
        </div>
    );
};

const EventCard: React.FC<{ event: Expedition; explorers: Explorer[]; expanded: boolean; onToggle: () => void; onChanged?: () => void }> = ({ event, explorers, expanded, onToggle, onChanged }) => {
    const [busy, setBusy] = useState(false);

    const addTeam = async () => {
        setBusy(true);
        try {
            await postJson(`/events/${event.ID}/teams`);
            onChanged?.();
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="ems-event-card" style={{ marginBottom: '12px', border: '1px solid #eee', padding: '12px' }}>
            <div
                className="ems-event-header"
                onClick={onToggle}
                style={{ cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}
                data-testid={`event-header-${event.ID}`}
            >
                <div>
                    <strong>{event.post_title}</strong> ({event.ems_event_code})
                </div>
                <div>
                    {event.teams.length} teams · {event.member_count ?? 0} members
                </div>
            </div>
            {expanded && (
                <div className="ems-event-teams" style={{ marginTop: '12px' }}>
                    <div style={{ marginBottom: '8px' }}>
                        <button type="button" className="button" onClick={addTeam} disabled={busy}>+ Add Team</button>
                    </div>
                    {event.teams.length === 0 ? (
                        <p>No teams in this event.</p>
                    ) : (
                        event.teams.map((team) => (
                            <TeamRow key={team.ID} team={team} explorers={explorers} onChanged={onChanged} />
                        ))
                    )}
                </div>
            )}
        </div>
    );
};

const TeamRow: React.FC<{ team: Team; explorers: Explorer[]; onChanged?: () => void }> = ({ team, explorers, onChanged }) => {
    const [selected, setSelected] = useState('');
    const [busy, setBusy] = useState(false);

    const members: Member[] = team.members ?? [];
    const assigned = new Set(members.map((m) => m.scout_id));
    const available = explorers.filter((e) => !assigned.has(e.scout_id));

    const addMember = async () => {
        if (!selected) return;
        setBusy(true);
        try {
            await postJson(`/teams/${team.ID}/members`, { scout_id: Number(selected) });
            setSelected('');
            onChanged?.();
        } finally {
            setBusy(false);
        }
    };

    const removeMember = async (scoutId: number) => {
        setBusy(true);
        try {
            await del(`/teams/${team.ID}/members/${scoutId}`);
            onChanged?.();
        } finally {
            setBusy(false);
        }
    };

    const deleteTeam = async () => {
        setBusy(true);
        try {
            await del(`/teams/${team.ID}`);
            onChanged?.();
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
                            ⚠ Size warning
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

export default SeasonDashboard;
