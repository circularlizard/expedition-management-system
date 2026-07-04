import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
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

    it('moves explorer within the same event and triggers callback', async () => {
        const onMoved = vi.fn();
        render(<ExplorerMovePanel season={baseSeason()} onMoved={onMoved} />);
        
        fireEvent.change(screen.getByLabelText('Select explorer'), { target: { value: '100:1' } });
        fireEvent.change(screen.getByLabelText('Select target team'), { target: { value: '101' } });
        fireEvent.click(screen.getByText('Move'));

        expect(onMoved).toHaveBeenCalledWith('1', 101);
        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/explorers/1/move-team'),
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ target_team_id: 101 }),
            })
        );
    });

    it('moves explorer across same-type events', () => {
        const onMoved = vi.fn();
        render(<ExplorerMovePanel season={baseSeason()} onMoved={onMoved} />);
        
        fireEvent.change(screen.getByLabelText('Select explorer'), { target: { value: '100:1' } });
        fireEvent.change(screen.getByLabelText('Select target team'), { target: { value: '200' } });
        fireEvent.click(screen.getByText('Move'));

        expect(onMoved).toHaveBeenCalledWith('1', 200);
        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/explorers/1/move-team'),
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ target_team_id: 200 }),
            })
        );
    });

    it('target dropdown lists teams in same event and other same-type events', () => {
        render(<ExplorerMovePanel season={baseSeason()} />);
        fireEvent.change(screen.getByLabelText('Select explorer'), { target: { value: '100:1' } });
        const options = Array.from(screen.getByLabelText('Select target team').querySelectorAll('option')).map((o) => o.textContent);
        expect(options).toContain('H-SP1-2');
        expect(options).toContain('H-SP2-1');
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

    it('does not duplicate the full season event tree', () => {
        render(<ExplorerMovePanel season={baseSeason()} />);
        expect(screen.queryByTestId('team-H-SP1-1')).not.toBeInTheDocument();
        expect(screen.queryByText('Alice MacLeod')).not.toBeInTheDocument();
    });
});
