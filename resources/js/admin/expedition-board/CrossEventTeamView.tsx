import React, { useState } from 'react';
import { Season, Member } from './types';
import { findEventOfTeam, findTeam, sameTypeEvents, teamContainingMember, memberKey } from './boardUtils';

interface CrossEventTeamViewProps {
    season: Season;
    selectedTeamId?: number | null;
    onSelectTeam?: (teamId: number) => void;
    onReassign?: (scoutOrUserId: string, targetTeamId: number, eventId: number) => void;
}

export const CrossEventTeamView: React.FC<CrossEventTeamViewProps> = ({
    season,
    selectedTeamId,
    onSelectTeam,
    onReassign,
}) => {
    const [internalTeamId, setInternalTeamId] = useState<number | null>(selectedTeamId ?? null);
    const teamId = selectedTeamId ?? internalTeamId;

    const selectTeam = (id: number) => {
        setInternalTeamId(id);
        onSelectTeam?.(id);
    };

    if (!teamId) {
        return (
            <div className="ems-cross-event-view">
                <TeamSelector season={season} onSelect={selectTeam} />
                <p>Select a team to see cross-event assignments</p>
            </div>
        );
    }

    const sourceEvent = findEventOfTeam(season, teamId);
    const sourceTeam = findTeam(season, teamId);

    if (!sourceEvent || !sourceTeam) {
        return <p>Select a team to see cross-event assignments</p>;
    }

    const otherEvents = sameTypeEvents(season, sourceEvent);
    const members = sourceTeam.members ?? [];

    if (otherEvents.length === 0) {
        return (
            <div className="ems-cross-event-view">
                <TeamSelector season={season} selectedTeamId={teamId} onSelect={selectTeam} />
                <p>No other {sourceEvent.ems_type} events in this season</p>
            </div>
        );
    }

    return (
        <div className="ems-cross-event-view">
            <TeamSelector season={season} selectedTeamId={teamId} onSelect={selectTeam} />
            <table className="widefat striped">
                <thead>
                    <tr>
                        <th>Member</th>
                        {otherEvents.map((event) => (
                            <th key={event.ID} data-testid={`column-${event.ems_event_code}`}>
                                {event.ems_event_code}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {members.map((member) => (
                        <tr key={memberKey(member)}>
                            <td>{member.first_name} {member.last_name}</td>
                            {otherEvents.map((event) => {
                                const assignedTeam = teamContainingMember(event, member);
                                return (
                                    <td key={event.ID} data-testid={`assignment-${memberKey(member)}-${event.ems_event_code}`}>
                                        <select
                                            value={assignedTeam ? assignedTeam.ID : ''}
                                            aria-label={`${member.first_name} ${member.last_name} assignment in ${event.ems_event_code}`}
                                            onChange={(e) => onReassign?.(memberKey(member), Number(e.target.value), event.ID)}
                                        >
                                            <option value="">not yet assigned</option>
                                            {event.teams.map((team) => (
                                                <option key={team.ID} value={team.ID}>
                                                    {team.ems_team_code}
                                                </option>
                                            ))}
                                        </select>
                                        {!assignedTeam && (
                                            <span className="ems-unassigned"> not yet assigned</span>
                                        )}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
};

const TeamSelector: React.FC<{ season: Season; selectedTeamId?: number | null; onSelect: (id: number) => void }> = ({
    season,
    selectedTeamId,
    onSelect,
}) => {
    return (
        <select
            aria-label="Select team"
            value={selectedTeamId ?? ''}
            onChange={(e) => onSelect(Number(e.target.value))}
        >
            <option value="">— Select a team —</option>
            {season.events.flatMap((event) =>
                event.teams.map((team) => (
                    <option key={team.ID} value={team.ID}>
                        {team.ems_team_code}
                    </option>
                ))
            )}
        </select>
    );
};

export default CrossEventTeamView;
