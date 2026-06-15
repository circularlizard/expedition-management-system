import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import ExpeditionBoard from '../../resources/js/admin/expedition-board/ExpeditionBoard';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
};

global.window.emsExpeditionBoard = mockConfig;

global.URL.createObjectURL = vi.fn(() => 'blob:mock');
global.URL.revokeObjectURL = vi.fn();

const mockBoardData = {
    expeditions: [
        { ID: 501, post_title: 'Bronze 2026', ems_expedition_code: 'B26', ems_level: 'Bronze', ems_start_date: '2026-05-01', ems_end_date: '2026-05-02', ems_status: 'draft' }
    ],
    teams: {
        '501': [ { ID: 601, post_title: 'Team 1', ems_team_code: 'B26-1', ems_expedition_id: '501' } ]
    },
    members: {
        '601': [
            { 
                id: '1', user_id: '123', first_name: 'Alice', last_name: 'Alpha', scout_id: '1001', unit: 'Bears',
                training: { total: 5, complete: 3, percent: 60 }
            }
        ]
    },
    explorers: [
        { 
            id: '1', user_id: '123', first_name: 'Alice', last_name: 'Alpha', scout_id: '1001', unit: 'Bears',
            training: { total: 5, complete: 3, percent: 60 }
        }
    ],
    last_sync: '2026-06-13T20:00:00Z'
};

describe('ExpeditionBoard', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('renders and loads data', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => mockBoardData
        });

        render(<ExpeditionBoard />);

        expect(screen.getByText('Loading board...')).toBeInTheDocument();

        await waitFor(() => {
            expect(screen.getByText('Bronze 2026 (B26)')).toBeInTheDocument();
            expect(screen.getByText('Team B26-1')).toBeInTheDocument();
            expect(screen.getByText('Alice Alpha')).toBeInTheDocument();
        });
    });

    it('switches tabs correctly', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => mockBoardData
        });

        render(<ExpeditionBoard />);
        await waitFor(() => screen.getByText('Bronze 2026 (B26)'));

        // Switch to Explorer tab
        fireEvent.click(screen.getByText('By Explorer'));
        expect(screen.getByText('Scout ID')).toBeInTheDocument();
        expect(screen.getByText('3/5 courses')).toBeInTheDocument();

        // Switch to Team tab
        fireEvent.click(screen.getByText('By Team'));
        expect(screen.getByText('Team Code')).toBeInTheDocument();
        expect(screen.getByText('1 members')).toBeInTheDocument();

        // Switch to Patrol tab
        fireEvent.click(screen.getByText('By Patrol'));
        expect(screen.getByText('Bears (1)')).toBeInTheDocument();
    });

    it('shows loading state initially', () => {
        (global.fetch as any).mockReturnValueOnce(new Promise(() => {}));
        render(<ExpeditionBoard />);
        expect(screen.getByText('Loading board...')).toBeInTheDocument();
    });

    it('shows error state on fetch failure', async () => {
        (global.fetch as any).mockRejectedValueOnce(new Error('network error'));
        render(<ExpeditionBoard />);
        await waitFor(() => {
            expect(screen.getByText('Failed to load dashboard data')).toBeInTheDocument();
        });
    });

    it('shows never synced when last_sync is null', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ ...mockBoardData, last_sync: null })
        });
        render(<ExpeditionBoard />);
        await waitFor(() => {
            expect(screen.getByText(/Never/)).toBeInTheDocument();
        });
    });

    it('shows empty state on explorer tab when no explorers', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ ...mockBoardData, explorers: [] })
        });
        render(<ExpeditionBoard />);
        await waitFor(() => screen.getByText('Bronze 2026 (B26)'));

        fireEvent.click(screen.getByText('By Explorer'));
        expect(screen.getByText(/No explorers synced yet/)).toBeInTheDocument();
    });

    it('shows empty state on team tab when no teams', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ ...mockBoardData, teams: {}, members: {} })
        });
        render(<ExpeditionBoard />);
        await waitFor(() => screen.getByText('Bronze 2026 (B26)'));

        fireEvent.click(screen.getByText('By Team'));
        expect(screen.getByText(/No teams created yet/)).toBeInTheDocument();
    });

    it('renders Download CSV button on explorer tab', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => mockBoardData
        });
        render(<ExpeditionBoard />);
        await waitFor(() => screen.getByText('Bronze 2026 (B26)'));

        fireEvent.click(screen.getByText('By Explorer'));
        expect(screen.getByText('Download CSV')).toBeInTheDocument();
    });

    it('renders Download CSV button on team tab', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => mockBoardData
        });
        render(<ExpeditionBoard />);
        await waitFor(() => screen.getByText('Bronze 2026 (B26)'));

        fireEvent.click(screen.getByText('By Team'));
        expect(screen.getByText('Download CSV')).toBeInTheDocument();
    });

    it('renders Download CSV button on patrol tab', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => mockBoardData
        });
        render(<ExpeditionBoard />);
        await waitFor(() => screen.getByText('Bronze 2026 (B26)'));

        fireEvent.click(screen.getByText('By Patrol'));
        expect(screen.getByText('Download CSV')).toBeInTheDocument();
    });
});
