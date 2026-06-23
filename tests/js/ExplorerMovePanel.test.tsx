import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { ExplorerMovePanel } from '../../resources/js/admin/expedition-board/ExplorerMovePanel';
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
                teams: [
                    { ID: 100, post_title: 'T1', ems_team_code: 'H-SP1-1', ems_team_number: 1, event_id: 10, members: [m(1, 'Alice', 'MacLeod'), m(2, 'Bob', 'Stewart'), m(3, 'Charlie', 'Mackay')] },
                    { ID: 101, post_title: 'T2', ems_team_code: 'H-SP1-2', ems_team_number: 2, event_id: 10, members: [m(4, 'Diana', 'Fraser'), m(5, 'Ewan', 'Campbell')] },
                ],
            },
            {
                ID: 20, post_title: 'H-SP2', ems_event_code: 'H-SP2', ems_type: 'practice', ems_level: 'silver',
                ems_start_date: '', ems_end_date: '',
                teams: [{ ID: 200, post_title: 'T1', ems_team_code: 'H-SP2-1', ems_team_number: 1, event_id: 20, members: [m(6, 'Fiona', 'Grant')] }],
            },
        ],
    };
}

describe('ExplorerMovePanel', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
    });

    it('moves explorer within the same event and updates counts', () => {
        render(<ExplorerMovePanel season={baseSeason()} />);
        fireEvent.change(screen.getByLabelText('Select explorer'), { target: { value: '100:1' } });
        fireEvent.change(screen.getByLabelText('Select target team'), { target: { value: '101' } });
        fireEvent.click(screen.getByText('Move'));

        expect(within(screen.getByTestId('team-H-SP1-1')).getByText(/shows 2 members/)).toBeInTheDocument();
        expect(within(screen.getByTestId('team-H-SP1-2')).getByText(/shows 3 members/)).toBeInTheDocument();
        expect(screen.getByTestId('member-H-SP1-2-1')).toBeInTheDocument();
        expect(screen.queryByTestId('member-H-SP1-1-1')).not.toBeInTheDocument();
    });

    it('moves explorer across same-type events', () => {
        render(<ExplorerMovePanel season={baseSeason()} />);
        fireEvent.change(screen.getByLabelText('Select explorer'), { target: { value: '100:1' } });
        fireEvent.change(screen.getByLabelText('Select target team'), { target: { value: '200' } });
        fireEvent.click(screen.getByText('Move'));

        expect(screen.getByTestId('member-H-SP2-1-1')).toBeInTheDocument();
        expect(screen.queryByTestId('member-H-SP1-1-1')).not.toBeInTheDocument();
        expect(within(screen.getByTestId('team-H-SP2-1')).getByText(/shows 2 members/)).toBeInTheDocument();
    });

    it('target dropdown lists teams in same event and other same-type events', () => {
        render(<ExplorerMovePanel season={baseSeason()} />);
        fireEvent.change(screen.getByLabelText('Select explorer'), { target: { value: '100:1' } });
        const options = Array.from(screen.getByLabelText('Select target team').querySelectorAll('option')).map((o) => o.textContent);
        expect(options).toContain('H-SP1-2');
        expect(options).toContain('H-SP2-1');
    });

    it('removes a team from the view when its last member is moved out', () => {
        const season = baseSeason();
        season.events[0].teams.push({ ID: 102, post_title: 'T3', ems_team_code: 'H-SP1-3', ems_team_number: 3, event_id: 10, members: [m(7, 'Gillian', 'Ross')] });
        render(<ExplorerMovePanel season={season} />);
        fireEvent.change(screen.getByLabelText('Select explorer'), { target: { value: '102:7' } });
        fireEvent.change(screen.getByLabelText('Select target team'), { target: { value: '100' } });
        fireEvent.click(screen.getByText('Move'));

        expect(screen.queryByTestId('team-H-SP1-3')).not.toBeInTheDocument();
        expect(screen.getByTestId('member-H-SP1-1-7')).toBeInTheDocument();
    });

    it('does not list teams from a different event type', () => {
        const season = baseSeason();
        season.events.push({
            ID: 30, post_title: 'H-SQ1', ems_event_code: 'H-SQ1', ems_type: 'qualifying', ems_level: 'silver',
            ems_start_date: '', ems_end_date: '', teams: [{ ID: 300, post_title: 'Q', ems_team_code: 'H-SQ1-1', ems_team_number: 1, event_id: 30, members: [m(8, 'Hamish', 'Bell')] }],
        });
        render(<ExplorerMovePanel season={season} />);
        fireEvent.change(screen.getByLabelText('Select explorer'), { target: { value: '100:1' } });
        const options = Array.from(screen.getByLabelText('Select target team').querySelectorAll('option')).map((o) => o.textContent);
        expect(options).not.toContain('H-SQ1-1');
    });
});
