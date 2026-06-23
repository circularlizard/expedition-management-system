import React, { useState } from 'react';
import { Season, Expedition, Team, Member } from './types';
import { findEventOfTeam, findTeam, sameTypeEvents, nextTeamNumber, previewTeamCode, memberKey } from './boardUtils';

interface TeamMovePanelProps {
    season: Season;
    onChanged?: () => void;
}

type Mode = 'move' | 'duplicate';

function cloneSeason(season: Season): Season {
    return JSON.parse(JSON.stringify(season));
}

export const TeamMovePanel: React.FC<TeamMovePanelProps> = ({ season, onChanged }) => {
    const config = window.emsExpeditionBoard;
    const [board, setBoard] = useState<Season>(() => cloneSeason(season));
    const [selectedTeamId, setSelectedTeamId] = useState<number | null>(null);
    const [mode, setMode] = useState<Mode | null>(null);
    const [targetEventId, setTargetEventId] = useState<string>('');
    const [populateConfirm, setPopulateConfirm] = useState<{ sourceId: number; targetId: number } | null>(null);
    const [warning, setWarning] = useState<string | null>(null);

    const selectedTeam = selectedTeamId ? findTeam(board, selectedTeamId) : null;
    const sourceEvent = selectedTeamId ? findEventOfTeam(board, selectedTeamId) : null;

    const moveTargets = sourceEvent ? sameTypeEvents(board, sourceEvent) : [];
    const duplicateTargets = sourceEvent ? board.events.filter((e) => e.ID !== sourceEvent.ID) : [];
    const targetEvents = mode === 'duplicate' ? duplicateTargets : moveTargets;

    const targetEvent = targetEventId ? board.events.find((e) => e.ID === Number(targetEventId)) ?? null : null;
    const preview = targetEvent ? previewTeamCode(targetEvent) : null;

    const startMode = (teamId: number, nextMode: Mode) => {
        setSelectedTeamId(teamId);
        setMode(nextMode);
        setTargetEventId('');
    };

    const confirmMove = () => {
        if (!selectedTeam || !targetEvent || !sourceEvent) return;
        const newCode = previewTeamCode(targetEvent);
        const newNumber = nextTeamNumber(targetEvent);

        setBoard((prev) => {
            const next = cloneSeason(prev);
            const src = next.events.find((e) => e.ID === sourceEvent.ID)!;
            const tgt = next.events.find((e) => e.ID === targetEvent.ID)!;
            const moving = src.teams.find((t) => t.ID === selectedTeam.ID)!;
            src.teams = src.teams.filter((t) => t.ID !== selectedTeam.ID);
            moving.ems_team_code = newCode;
            moving.ems_team_number = newNumber;
            moving.event_id = tgt.ID;
            tgt.teams.push(moving);
            return next;
        });

        fetch(`${config.root_url}/teams/${selectedTeam.ID}/move`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
            body: JSON.stringify({ target_event_id: targetEvent.ID }),
        }).catch(() => {});

        reset();
        onChanged?.();
    };

    const confirmDuplicate = () => {
        if (!selectedTeam || !targetEvent) return;
        const newCode = previewTeamCode(targetEvent);
        const newNumber = nextTeamNumber(targetEvent);
        const newId = Date.now();

        setBoard((prev) => {
            const next = cloneSeason(prev);
            const tgt = next.events.find((e) => e.ID === targetEvent.ID)!;
            const copy: Team = {
                ID: newId,
                post_title: selectedTeam.post_title,
                ems_team_code: newCode,
                ems_team_number: newNumber,
                event_id: tgt.ID,
                members: (selectedTeam.members ?? []).map((m) => ({ ...m })),
                member_count: (selectedTeam.members ?? []).length,
            };
            tgt.teams.push(copy);
            return next;
        });

        fetch(`${config.root_url}/teams/${selectedTeam.ID}/duplicate`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
            body: JSON.stringify({ target_event_id: targetEvent.ID }),
        }).catch(() => {});

        reset();
        onChanged?.();
    };

    const requestPopulate = (sourceId: number, targetId: number) => {
        const target = board.events.find((e) => e.ID === targetId);
        if (target && target.teams.length > 0) {
            setWarning('Target event already has teams');
            setPopulateConfirm({ sourceId, targetId });
            return;
        }
        runPopulate(sourceId, targetId);
    };

    const runPopulate = (sourceId: number, targetId: number) => {
        const source = board.events.find((e) => e.ID === sourceId);
        if (!source) return;

        setBoard((prev) => {
            const next = cloneSeason(prev);
            const tgt = next.events.find((e) => e.ID === targetId)!;
            let n = nextTeamNumber(tgt);
            source.teams.forEach((team) => {
                tgt.teams.push({
                    ID: Date.now() + n,
                    post_title: team.post_title,
                    ems_team_code: `${tgt.ems_event_code}-${n}`,
                    ems_team_number: n,
                    event_id: tgt.ID,
                    members: (team.members ?? []).map((m) => ({ ...m })),
                    member_count: (team.members ?? []).length,
                });
                n += 1;
            });
            return next;
        });

        fetch(`${config.root_url}/events/${sourceId}/populate/${targetId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
        }).catch(() => {});

        setWarning(null);
        setPopulateConfirm(null);
        onChanged?.();
    };

    const reset = () => {
        setSelectedTeamId(null);
        setMode(null);
        setTargetEventId('');
    };

    const qualifyingEvents = board.events.filter((e) => e.ems_type === 'qualifying');
    const practiceEvents = board.events.filter((e) => e.ems_type === 'practice');

    return (
        <div className="ems-team-move-panel">
            <div className="ems-team-controls">
                {board.events.flatMap((event) =>
                    event.teams.map((team) => (
                        <div key={team.ID} className="ems-team-control-row" data-testid={`control-${team.ems_team_code}`}>
                            <span>{team.ems_team_code}</span>
                            <button className="button" onClick={() => startMode(team.ID, 'move')}>Move</button>
                            <button className="button" onClick={() => startMode(team.ID, 'duplicate')}>Duplicate</button>
                        </div>
                    ))
                )}
            </div>

            {selectedTeam && mode && (
                <div className="ems-move-dialog">
                    <h4>{mode === 'move' ? 'Move' : 'Duplicate'} {selectedTeam.ems_team_code}</h4>
                    <label>
                        Target event
                        <select aria-label="Select target event" value={targetEventId} onChange={(e) => setTargetEventId(e.target.value)}>
                            <option value="">— Select target event —</option>
                            {targetEvents.map((event) => (
                                <option key={event.ID} value={event.ID}>{event.ems_event_code}</option>
                            ))}
                        </select>
                    </label>

                    {preview && (
                        <div className="ems-recode-preview" data-testid="recode-preview">
                            <p>{mode === 'move' ? 'Will be re-coded to' : 'New team will be coded'} {preview}</p>
                            <ul>
                                {(selectedTeam.members ?? []).map((m) => (
                                    <li key={memberKey(m)}>{m.first_name} {m.last_name}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <button
                        className="button button-primary"
                        disabled={!targetEvent}
                        onClick={mode === 'move' ? confirmMove : confirmDuplicate}
                    >
                        Confirm {mode === 'move' ? 'Move' : 'Duplicate'}
                    </button>
                    <button className="button" onClick={reset}>Cancel</button>
                </div>
            )}

            {qualifyingEvents.map((qe) =>
                practiceEvents.map((pe) => (
                    <div key={`${pe.ID}-${qe.ID}`} className="ems-populate-row">
                        <button className="button" onClick={() => requestPopulate(pe.ID, qe.ID)}>
                            Populate from {pe.ems_event_code}
                        </button>
                        <span> → {qe.ems_event_code}</span>
                    </div>
                ))
            )}

            {warning && (
                <div className="notice notice-warning ems-populate-warning">
                    <p>{warning}</p>
                    {populateConfirm && (
                        <button
                            className="button button-primary"
                            onClick={() => runPopulate(populateConfirm.sourceId, populateConfirm.targetId)}
                        >
                            Confirm Populate
                        </button>
                    )}
                </div>
            )}

            <div className="ems-event-summary">
                {board.events.map((event) => (
                    <div key={event.ID} data-testid={`event-summary-${event.ems_event_code}`}>
                        <h4>{event.ems_event_code} — {event.teams.length} teams</h4>
                        <ul>
                            {event.teams.map((team) => (
                                <li key={team.ID} data-testid={`summary-team-${team.ems_team_code}`}>
                                    {team.ems_team_code}: {(team.members ?? []).map((m) => `${m.first_name} ${m.last_name}`).join(', ')}
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </div>
    );
};

export default TeamMovePanel;
