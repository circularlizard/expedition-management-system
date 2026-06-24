import { Season, Expedition, Team, Member } from './types';

export function sortByName<T extends { first_name?: string; last_name?: string }>(items: T[]): T[] {
    return [...items].sort((a, b) => {
        const aName = `${a.last_name ?? ''}, ${a.first_name ?? ''}`;
        const bName = `${b.last_name ?? ''}, ${b.first_name ?? ''}`;
        return aName.localeCompare(bName);
    });
}

export function sortByFirstName<T extends { first_name?: string; last_name?: string }>(items: T[]): T[] {
    return [...items].sort((a, b) => {
        const aName = `${a.first_name ?? ''} ${a.last_name ?? ''}`;
        const bName = `${b.first_name ?? ''} ${b.last_name ?? ''}`;
        return aName.localeCompare(bName);
    });
}

export function memberKey(member: Member): string {
    return String(member.scout_id ?? member.user_id);
}

export function findEventOfTeam(season: Season, teamId: number): Expedition | null {
    for (const event of season.events) {
        if (event.teams.some((t) => t.ID === teamId)) {
            return event;
        }
    }
    return null;
}

export function findTeam(season: Season, teamId: number): Team | null {
    for (const event of season.events) {
        const team = event.teams.find((t) => t.ID === teamId);
        if (team) return team;
    }
    return null;
}

export function sameTypeEvents(season: Season, event: Expedition): Expedition[] {
    return season.events.filter((e) => e.ID !== event.ID && e.ems_type === event.ems_type);
}

export function teamContainingMember(event: Expedition, member: Member): Team | null {
    const key = memberKey(member);
    for (const team of event.teams) {
        if ((team.members ?? []).some((m) => memberKey(m) === key)) {
            return team;
        }
    }
    return null;
}

export function nextTeamNumber(event: Expedition): number {
    if (!event.teams.length) return 1;
    return Math.max(...event.teams.map((t) => t.ems_team_number || 0)) + 1;
}

export function previewTeamCode(event: Expedition): string {
    return `${event.ems_event_code}-${nextTeamNumber(event)}`;
}
