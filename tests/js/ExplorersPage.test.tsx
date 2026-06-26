import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import ExplorersPage from '../../resources/js/admin/expedition-board/ExplorersPage';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
};

(global as any).window.emsExpeditionBoard = mockConfig;

const mockBoardData = {
    seasons: [],
    explorers: [
        { scout_id: 101, first_name: 'Alice', last_name: 'MacLeod', patrol: 'Hawks', first_aid_level: 'none' },
    ],
    last_sync: '2026-06-13T20:00:00Z',
};

describe('ExplorersPage', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('shows loading state initially', () => {
        (global.fetch as any).mockReturnValueOnce(new Promise(() => {}));
        render(<ExplorersPage />);
        expect(screen.getByText('Loading explorers...')).toBeInTheDocument();
    });

    it('renders the explorer list', async () => {
        (global.fetch as any).mockResolvedValueOnce({ ok: true, json: async () => mockBoardData });
        render(<ExplorersPage />);
        await waitFor(() => {
            expect(screen.getByRole('heading', { name: 'Explorer List' })).toBeInTheDocument();
            expect(screen.getByText('Alice MacLeod')).toBeInTheDocument();
        });
    });

    it('shows error state on fetch failure', async () => {
        (global.fetch as any).mockRejectedValueOnce(new Error('network error'));
        render(<ExplorersPage />);
        await waitFor(() => {
            expect(screen.getByText('network error')).toBeInTheDocument();
        });
    });
});
