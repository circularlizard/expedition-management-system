import React, { useState } from 'react';
import { BoardData, Season, Expedition, Team, Member } from './types';

interface SeasonDashboardProps {
    data: BoardData;
}

export const SeasonDashboard: React.FC<SeasonDashboardProps> = ({ data }) => {
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
                    expandedEvents={expandedEvents}
                    setExpandedEvents={setExpandedEvents}
                />
            ))}
        </div>
    );
};

const SeasonCard: React.FC<{
    season: Season;
    expandedEvents: Set<number>;
    setExpandedEvents: React.Dispatch<React.SetStateAction<Set<number>>>;
}> = ({ season, expandedEvents, setExpandedEvents }) => {
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
            <h2>{season.post_title}</h2>
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
                                expanded={expandedEvents.has(event.ID)}
                                onToggle={() => toggleEvent(event.ID)}
                            />
                        ))}
                    </div>
                ))
            )}
        </div>
    );
};

const EventCard: React.FC<{ event: Expedition; expanded: boolean; onToggle: () => void }> = ({ event, expanded, onToggle }) => {
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
                    {event.teams.length === 0 ? (
                        <p>No teams in this event.</p>
                    ) : (
                        event.teams.map((team) => <TeamRow key={team.ID} team={team} />)
                    )}
                </div>
            )}
        </div>
    );
};

const TeamRow: React.FC<{ team: Team }> = ({ team }) => {
    return (
        <div className="ems-team-row" style={{ marginBottom: '8px', padding: '8px', border: '1px solid #f0f0f0' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <span>{team.ems_team_code}</span>
                <span>
                    {team.member_count ?? 0} members
                    {team.size_warning && (
                        <span className="ems-size-warning" style={{ marginLeft: '8px', color: '#d63638', fontWeight: 'bold' }}>
                            ⚠ Size warning
                        </span>
                    )}
                </span>
            </div>
            {team.members && team.members.length > 0 && (
                <ul style={{ margin: '8px 0 0 0', paddingLeft: '20px' }}>
                    {team.members.map((member) => (
                        <li key={member.user_id}>
                            {member.first_name} {member.last_name}
                        </li>
                    ))}
                </ul>
            )}
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
