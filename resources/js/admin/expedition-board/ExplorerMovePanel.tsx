import React, { useState } from 'react';
import { Season, Expedition, Team, Member } from './types';
import { sameTypeEvents, memberKey } from './boardUtils';

interface ExplorerMovePanelProps {
    season: Season;
    onMoved?: (scoutOrUserId: string, targetTeamId: number) => void;
}

function cloneSeason(season: Season): Season {
    return JSON.parse(JSON.stringify(season));
}

export const ExplorerMovePanel: React.FC<ExplorerMovePanelProps> = ({ season, onMoved }) => {
    const config = window.emsExpeditionBoard;
    const [board, setBoard] = useState<Season>(() => cloneSeason(season));
    const [selection, setSelection] = useState<string>('');
    const [targetTeamId, setTargetTeamId] = useState<string>('');

    const parsedSelection = selection ? parseSelection(selection) : null;
    const sourceTeam = parsedSelection ? findTeam(board, parsedSelection.teamId) : null;
    const sourceEvent = parsedSelection ? findEvent(board, parsedSelection.teamId) : null;

    const targetTeams: { team: Team; event: Expedition }[] = [];
    if (sourceEvent && sourceTeam) {
        sourceEvent.teams
            .filter((t) => t.ID !== sourceTeam.ID)
            .forEach((t) => targetTeams.push({ team: t, event: sourceEvent }));
        sameTypeEvents(board, sourceEvent).forEach((event) => {
            event.teams.forEach((t) => targetTeams.push({ team: t, event }));
        });
    }

    const performMove = (memberId: string, sourceTeamId: number, destTeamId: number) => {
        setBoard((prev) => {
            const next = cloneSeason(prev);
            let moved: Member | undefined;
            next.events.forEach((event) => {
                event.teams.forEach((team) => {
                    if (team.ID === sourceTeamId) {
                        const idx = (team.members ?? []).findIndex((m) => memberKey(m) === memberId);
                        if (idx >= 0) {
                            moved = team.members![idx];
                            team.members!.splice(idx, 1);
                            team.member_count = team.members!.length;
                        }
                    }
                });
                event.teams = event.teams.filter((t) => (t.members ?? []).length > 0);
            });
            if (moved) {
                next.events.forEach((event) => {
                    event.teams.forEach((team) => {
                        if (team.ID === destTeamId) {
                            team.members = [...(team.members ?? []), moved!];
                            team.member_count = team.members.length;
                        }
                    });
                });
            }
            return next;
        });

        fetch(`${config.root_url}/explorers/${memberId}/move-team`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
            body: JSON.stringify({ target_team_id: destTeamId }),
        }).catch(() => {});

        onMoved?.(memberId, destTeamId);
        setSelection('');
        setTargetTeamId('');
    };

    const handleMove = () => {
        if (!parsedSelection || !targetTeamId) return;
        performMove(parsedSelection.memberKey, parsedSelection.teamId, Number(targetTeamId));
    };

    return (
        <div className="ems-explorer-move-panel">
            <label>
                Explorer
                <select aria-label="Select explorer" value={selection} onChange={(e) => setSelection(e.target.value)}>
                    <option value="">— Select explorer —</option>
                    {board.events.flatMap((event) =>
                        event.teams.flatMap((team) =>
                            (team.members ?? []).map((member) => (
                                <option key={`${team.ID}:${memberKey(member)}`} value={`${team.ID}:${memberKey(member)}`}>
                                    {member.first_name} {member.last_name} ({team.ems_team_code})
                                </option>
                            ))
                        )
                    )}
                </select>
            </label>

            <label>
                Target team
                <select aria-label="Select target team" value={targetTeamId} onChange={(e) => setTargetTeamId(e.target.value)}>
                    <option value="">— Select target team —</option>
                    {targetTeams.map(({ team }) => (
                        <option key={team.ID} value={team.ID}>{team.ems_team_code}</option>
                    ))}
                </select>
            </label>

            <button className="button button-primary" onClick={handleMove} disabled={!parsedSelection || !targetTeamId}>
                Move
            </button>

            <div className="ems-event-list">
                {board.events.map((event) => (
                    <div key={event.ID} className="ems-event-block">
                        <h4>{event.ems_event_code}</h4>
                        {event.teams.map((team) => (
                            <div key={team.ID} className="ems-team-block" data-testid={`team-${team.ems_team_code}`}>
                                <strong>{team.ems_team_code}</strong> shows {(team.members ?? []).length} members
                                <ul>
                                    {(team.members ?? []).map((member) => (
                                        <li key={memberKey(member)} data-testid={`member-${team.ems_team_code}-${memberKey(member)}`}>
                                            {member.first_name} {member.last_name}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
};

function parseSelection(value: string): { teamId: number; memberKey: string } {
    const [teamId, key] = value.split(':');
    return { teamId: Number(teamId), memberKey: key };
}

function findTeam(season: Season, teamId: number): Team | null {
    for (const event of season.events) {
        const team = event.teams.find((t) => t.ID === teamId);
        if (team) return team;
    }
    return null;
}

function findEvent(season: Season, teamId: number): Expedition | null {
    for (const event of season.events) {
        if (event.teams.some((t) => t.ID === teamId)) return event;
    }
    return null;
}

export default ExplorerMovePanel;
