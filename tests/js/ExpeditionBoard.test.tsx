import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import ExpeditionBoard from '../../resources/js/admin/expedition-board/ExpeditionBoard';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
};

(global as any).window.emsExpeditionBoard = mockConfig;

const mockBoardData = {
    seasons: [
        {
            ID: 1,
            post_title: '2026-27 Season',
            ems_season_year: '2026-27',
            ems_season_status: 'active',
            events: [
                {
                    ID: 10,
                    post_title: 'Hill Practice 1',
                    ems_event_code: 'H-SP1',
                    ems_type: 'practice',
                    ems_level: 'silver',
                    ems_start_date: '2027-06-01',
                    ems_end_date: '2027-06-03',
                    member_count: 4,
                    teams: [
                        {
                            ID: 100,
                            post_title: 'Team 1',
                            ems_team_code: 'H-SP1-1',
                            ems_team_number: 1,
                            event_id: 10,
                            member_count: 4,
                            size_warning: false,
                            members: [{ user_id: 1, scout_id: 101, first_name: 'Alice', last_name: 'MacLeod' }],
                        },
                    ],
                },
            ],
        },
    ],
    last_sync: '2026-06-13T20:00:00Z',
};

describe('ExpeditionBoard', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('shows loading state initially', () => {
        (global.fetch as any).mockReturnValueOnce(new Promise(() => {}));
        render(<ExpeditionBoard />);
        expect(screen.getByText('Loading board...')).toBeInTheDocument();
    });

    it('renders the season dashboard by default', async () => {
        (global.fetch as any).mockResolvedValueOnce({ ok: true, json: async () => mockBoardData });
        render(<ExpeditionBoard />);
        await waitFor(() => {
            expect(screen.getByText('2026-27 Season')).toBeInTheDocument();
            expect(screen.getByText(/Hill Practice 1/)).toBeInTheDocument();
        });
    });

    it('shows error state on fetch failure', async () => {
        (global.fetch as any).mockRejectedValueOnce(new Error('network error'));
        render(<ExpeditionBoard />);
        await waitFor(() => {
            expect(screen.getByText('network error')).toBeInTheDocument();
        });
    });

    it('shows never synced when last_sync is null', async () => {
        (global.fetch as any).mockResolvedValueOnce({ ok: true, json: async () => ({ ...mockBoardData, last_sync: null }) });
        render(<ExpeditionBoard />);
        await waitFor(() => {
            expect(screen.getByText(/Never/)).toBeInTheDocument();
        });
    });

    it('switches to the Move / Duplicate Teams tab', async () => {
        (global.fetch as any).mockResolvedValueOnce({ ok: true, json: async () => mockBoardData });
        render(<ExpeditionBoard />);
        await waitFor(() => screen.getByText('2026-27 Season'));

        fireEvent.click(screen.getByText('Move / Duplicate Teams'));
        expect(screen.getByTestId('control-H-SP1-1')).toBeInTheDocument();
    });

    it('switches to the Cross-Event View tab', async () => {
        (global.fetch as any).mockResolvedValueOnce({ ok: true, json: async () => mockBoardData });
        render(<ExpeditionBoard />);
        await waitFor(() => screen.getByText('2026-27 Season'));

        fireEvent.click(screen.getByText('Cross-Event View'));
        expect(screen.getByText('Select a team to see cross-event assignments')).toBeInTheDocument();
    });
});
