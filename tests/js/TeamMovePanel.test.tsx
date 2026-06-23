import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { TeamMovePanel } from '../../resources/js/admin/expedition-board/TeamMovePanel';
import { Season } from '../../resources/js/admin/expedition-board/types';

(global as any).window.emsExpeditionBoard = { root_url: 'https://example.com/wp-json/ems/v1', nonce: 'n' };

const m = (id: number, first: string, last: string) => ({ user_id: id, scout_id: id, first_name: first, last_name: last });

function baseSeason(): Season {
    return {
        ID: 1, post_title: '2026-27', ems_season_year: '2026-27', ems_season_status: 'active',
        events: [
            {
                ID: 10, post_title: 'H-SP1', ems_event_code: 'H-SP1', ems_type: 'practice', ems_level: 'silver',
                ems_start_date: '', ems_end_date: '',
                teams: [{ ID: 100, post_title: 'T1', ems_team_code: 'H-SP1-1', ems_team_number: 1, event_id: 10, members: [m(1, 'Alice', 'MacLeod'), m(2, 'Bob', 'Stewart')] }],
            },
            {
                ID: 20, post_title: 'H-SP2', ems_event_code: 'H-SP2', ems_type: 'practice', ems_level: 'silver',
                ems_start_date: '', ems_end_date: '', teams: [],
            },
        ],
    };
}

function withQualifying(season: Season): Season {
    season.events.push({
        ID: 30, post_title: 'H-SQ1', ems_event_code: 'H-SQ1', ems_type: 'qualifying', ems_level: 'silver',
        ems_start_date: '', ems_end_date: '', teams: [],
    });
    return season;
}

describe('TeamMovePanel', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
    });

    it('shows re-code preview with members when move target selected', () => {
        render(<TeamMovePanel season={baseSeason()} />);
        const row = screen.getByTestId('control-H-SP1-1');
        fireEvent.click(within(row).getByText('Move'));
        fireEvent.change(screen.getByLabelText('Select target event'), { target: { value: '20' } });

        const preview = screen.getByTestId('recode-preview');
        expect(within(preview).getByText(/H-SP2-1/)).toBeInTheDocument();
        expect(within(preview).getByText('Alice MacLeod')).toBeInTheDocument();
        expect(within(preview).getByText('Bob Stewart')).toBeInTheDocument();
    });

    it('confirming a move relocates the team with new code', () => {
        render(<TeamMovePanel season={baseSeason()} />);
        fireEvent.click(within(screen.getByTestId('control-H-SP1-1')).getByText('Move'));
        fireEvent.change(screen.getByLabelText('Select target event'), { target: { value: '20' } });
        fireEvent.click(screen.getByText('Confirm Move'));

        expect(screen.getByTestId('summary-team-H-SP2-1')).toBeInTheDocument();
        expect(screen.queryByTestId('summary-team-H-SP1-1')).not.toBeInTheDocument();
        expect(global.fetch).toHaveBeenCalledWith(
            'https://example.com/wp-json/ems/v1/teams/100/move',
            expect.objectContaining({ method: 'POST' })
        );
    });

    it('duplicating creates a new team and keeps the original', () => {
        render(<TeamMovePanel season={withQualifying(baseSeason())} />);
        fireEvent.click(within(screen.getByTestId('control-H-SP1-1')).getByText('Duplicate'));
        fireEvent.change(screen.getByLabelText('Select target event'), { target: { value: '30' } });
        expect(within(screen.getByTestId('recode-preview')).getByText(/H-SQ1-1/)).toBeInTheDocument();
        fireEvent.click(screen.getByText('Confirm Duplicate'));

        expect(screen.getByTestId('summary-team-H-SQ1-1')).toBeInTheDocument();
        expect(screen.getByTestId('summary-team-H-SP1-1')).toBeInTheDocument();
    });

    it('populate copies all practice teams into the qualifying event', () => {
        const season = withQualifying(baseSeason());
        season.events[0].teams.push({ ID: 101, post_title: 'T2', ems_team_code: 'H-SP1-2', ems_team_number: 2, event_id: 10, members: [m(3, 'Charlie', 'Mackay'), m(4, 'Diana', 'Fraser')] });
        render(<TeamMovePanel season={season} />);

        fireEvent.click(screen.getByText('Populate from H-SP1'));

        const summary = screen.getByTestId('event-summary-H-SQ1');
        expect(within(summary).getByText(/2 teams/)).toBeInTheDocument();
        expect(screen.getByTestId('summary-team-H-SQ1-1')).toHaveTextContent('Alice MacLeod, Bob Stewart');
        expect(screen.getByTestId('summary-team-H-SQ1-2')).toHaveTextContent('Charlie Mackay, Diana Fraser');
    });

    it('warns before overwriting a target event that already has teams', () => {
        const season = withQualifying(baseSeason());
        season.events[2].teams.push({ ID: 300, post_title: 'Q', ems_team_code: 'H-SQ1-1', ems_team_number: 1, event_id: 30, members: [] });
        render(<TeamMovePanel season={season} />);

        fireEvent.click(screen.getByText('Populate from H-SP1'));
        expect(screen.getByText('Target event already has teams')).toBeInTheDocument();
    });

    it('does not offer incompatible event type as a move target', () => {
        render(<TeamMovePanel season={withQualifying(baseSeason())} />);
        fireEvent.click(within(screen.getByTestId('control-H-SP1-1')).getByText('Move'));
        const options = Array.from(screen.getByLabelText('Select target event').querySelectorAll('option')).map((o) => o.textContent);
        expect(options).not.toContain('H-SQ1');
    });

    it('does not offer the current event as a move target', () => {
        render(<TeamMovePanel season={baseSeason()} />);
        fireEvent.click(within(screen.getByTestId('control-H-SP1-1')).getByText('Move'));
        const options = Array.from(screen.getByLabelText('Select target event').querySelectorAll('option')).map((o) => o.textContent);
        expect(options).not.toContain('H-SP1');
    });
});
