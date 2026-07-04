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
            ID: 0,
            post_title: 'All Events',
            ems_season_year: '',
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
    explorers: [],
    last_sync: '2026-06-13T20:00:00Z',
};

const mockEventsData = { events: mockBoardData.seasons[0].events };

describe('ExpeditionBoard', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('shows loading state initially', () => {
        (global.fetch as any).mockReturnValueOnce(new Promise(() => {}));
        render(<ExpeditionBoard />);
        expect(screen.getByText('Loading board…')).toBeInTheDocument();
    });

    it('renders the events dashboard by default', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockBoardData })
            .mockResolvedValueOnce({ ok: true, json: async () => mockEventsData });
        render(<ExpeditionBoard />);
        await waitFor(() => {
            expect(screen.getByText('Events Dashboard')).toBeInTheDocument();
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
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => ({ ...mockBoardData, last_sync: null }) })
            .mockResolvedValueOnce({ ok: true, json: async () => mockEventsData });
        render(<ExpeditionBoard />);
        await waitFor(() => {
            expect(screen.getByText(/Never/)).toBeInTheDocument();
        });
    });

    it('switches to the Expedition View tab', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockBoardData })
            .mockResolvedValueOnce({ ok: true, json: async () => mockEventsData });
        render(<ExpeditionBoard />);
        await waitFor(() => screen.getByText('Events Dashboard'));

        fireEvent.click(screen.getByText('Expedition View'));
        expect(screen.getByLabelText('Select expedition')).toBeInTheDocument();
    });
});
