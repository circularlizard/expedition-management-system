import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { CrossEventTeamView } from '../../resources/js/admin/expedition-board/CrossEventTeamView';
import { Season } from '../../resources/js/admin/expedition-board/types';

const alice = { user_id: 1, scout_id: 101, first_name: 'Alice', last_name: 'MacLeod' };
const bob = { user_id: 2, scout_id: 102, first_name: 'Bob', last_name: 'Stewart' };

function baseSeason(): Season {
    return {
        ID: 1,
        post_title: '2026-27',
        ems_season_year: '2026-27',
        ems_season_status: 'active',
        events: [
            {
                ID: 10, post_title: 'H-SP1', ems_event_code: 'H-SP1', ems_type: 'practice', ems_level: 'silver',
                ems_start_date: '', ems_end_date: '',
                teams: [{ ID: 100, post_title: 'T1', ems_team_code: 'H-SP1-1', ems_team_number: 1, event_id: 10, members: [alice, bob] }],
            },
            {
                ID: 20, post_title: 'H-SP2', ems_event_code: 'H-SP2', ems_type: 'practice', ems_level: 'silver',
                ems_start_date: '', ems_end_date: '',
                teams: [{ ID: 200, post_title: 'T1', ems_team_code: 'H-SP2-1', ems_team_number: 1, event_id: 20, members: [alice] }],
            },
        ],
    };
}

describe('CrossEventTeamView', () => {
    it('shows prompt when no team selected', () => {
        render(<CrossEventTeamView season={baseSeason()} />);
        expect(screen.getByText('Select a team to see cross-event assignments')).toBeInTheDocument();
    });

    it('shows member assignments in other same-type events', () => {
        render(<CrossEventTeamView season={baseSeason()} selectedTeamId={100} />);
        const aliceCell = screen.getByTestId('assignment-101-H-SP2');
        expect((within(aliceCell).getByRole('combobox') as HTMLSelectElement).value).toBe('200');
        const bobCell = screen.getByTestId('assignment-102-H-SP2');
        expect((within(bobCell).getByRole('combobox') as HTMLSelectElement).value).toBe('');
        expect(bobCell.querySelector('.ems-unassigned')).toBeInTheDocument();
    });

    it('shows columns for all other practice events', () => {
        const season = baseSeason();
        season.events.push({
            ID: 30, post_title: 'H-SP3', ems_event_code: 'H-SP3', ems_type: 'practice', ems_level: 'silver',
            ems_start_date: '', ems_end_date: '', teams: [],
        });
        render(<CrossEventTeamView season={season} selectedTeamId={100} />);
        expect(screen.getByTestId('column-H-SP2')).toBeInTheDocument();
        expect(screen.getByTestId('column-H-SP3')).toBeInTheDocument();
    });

    it('does not show qualifying events', () => {
        const season = baseSeason();
        season.events.push({
            ID: 40, post_title: 'H-SQ1', ems_event_code: 'H-SQ1', ems_type: 'qualifying', ems_level: 'silver',
            ems_start_date: '', ems_end_date: '', teams: [{ ID: 400, post_title: 'Q', ems_team_code: 'H-SQ1-1', ems_team_number: 1, event_id: 40, members: [] }],
        });
        render(<CrossEventTeamView season={season} selectedTeamId={100} />);
        expect(screen.queryByTestId('column-H-SQ1')).not.toBeInTheDocument();
    });

    it('shows empty state when no other same-type events', () => {
        const season = baseSeason();
        season.events = [season.events[0]];
        render(<CrossEventTeamView season={season} selectedTeamId={100} />);
        expect(screen.getByText('No other practice events in this season')).toBeInTheDocument();
    });

    it('fires reassign callback when assignment changed', () => {
        let captured: any = null;
        render(
            <CrossEventTeamView
                season={baseSeason()}
                selectedTeamId={100}
                onReassign={(id, teamId, eventId) => { captured = { id, teamId, eventId }; }}
            />
        );
        const bobCell = screen.getByTestId('assignment-102-H-SP2');
        fireEvent.change(within(bobCell).getByRole('combobox'), { target: { value: '200' } });
        expect(captured).toEqual({ id: '102', teamId: 200, eventId: 20 });
    });
});
