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
        global.fetch = vi.fn(((url: string) => {
            if (url.includes('/expedition-board')) {
                return Promise.resolve({ ok: true, json: async () => mockBoardData });
            }
            if (url.includes('/osm-events')) {
                return Promise.resolve({ ok: true, json: async () => [] });
            }
            if (url.includes('/events')) {
                return Promise.resolve({ ok: true, json: async () => mockEventsData });
            }
            return Promise.reject(new Error('Unknown request: ' + url));
        }) as any);
    });

    it('shows loading state initially', () => {
        (global.fetch as any).mockImplementationOnce(() => new Promise(() => {}));
        render(<ExpeditionBoard />);
        expect(screen.getByText('Loading board…')).toBeInTheDocument();
    });

    it('renders the events dashboard by default', async () => {
        render(<ExpeditionBoard />);
        await waitFor(() => {
            expect(screen.getByText('Expeditions')).toBeInTheDocument();
        });
    });

    it('shows error state on fetch failure', async () => {
        (global.fetch as any).mockImplementationOnce(() => Promise.reject(new Error('network error')));
        render(<ExpeditionBoard />);
        await waitFor(() => {
            expect(screen.getByText('network error')).toBeInTheDocument();
        });
    });

    it('shows never synced when last_sync is null', async () => {
        (global.fetch as any).mockImplementationOnce((url: string) => {
            if (url.includes('/expedition-board')) {
                return Promise.resolve({ ok: true, json: async () => ({ ...mockBoardData, last_sync: null }) });
            }
            return Promise.resolve({ ok: true, json: async () => [] });
        });
        render(<ExpeditionBoard />);
        await waitFor(() => {
            expect(screen.getByText(/Never/)).toBeInTheDocument();
        });
    });

    it('switches to create view when Create Event is clicked', async () => {
        render(<ExpeditionBoard />);
        
        await waitFor(() => {
            expect(screen.getByText('Expeditions')).toBeInTheDocument();
        });

        const createBtn = screen.getByRole('button', { name: '+ Create Event' });
        fireEvent.click(createBtn);

        expect(screen.getByRole('heading', { name: 'Create Event' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    });

    it('merges Name/Code into Expedition column, edits Max Teams inline, and routes code click vs Edit', async () => {
        const eventsPayload = {
            events: [
                {
                    ...mockBoardData.seasons[0].events[0],
                    ems_max_teams: 3,
                },
            ],
        };
        (global.fetch as any).mockImplementation((url: string, init?: RequestInit) => {
            if (url.includes('/expedition-board')) {
                return Promise.resolve({ ok: true, json: async () => mockBoardData });
            }
            if (url.includes('/osm-events')) {
                return Promise.resolve({ ok: true, json: async () => [] });
            }
            if (url.includes('/events/10') && init?.method === 'PATCH') {
                return Promise.resolve({ ok: true, json: async () => ({ ...eventsPayload.events[0], ems_max_teams: '5' }) });
            }
            if (url.includes('/events')) {
                return Promise.resolve({ ok: true, json: async () => eventsPayload });
            }
            return Promise.reject(new Error('Unknown request: ' + url));
        });

        render(<ExpeditionBoard />);

        await waitFor(() => {
            expect(screen.getByText('H-SP1')).toBeInTheDocument();
        });

        // Merged Expedition column shows code and subtitle
        expect(screen.getByText('Expedition')).toBeInTheDocument();
        expect(screen.getByText('Hill Practice 1')).toBeInTheDocument();
        expect(screen.getByText('Teams / Max')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Manage' })).not.toBeInTheDocument();

        // Inline Max Teams edit saves on Enter/blur
        const maxInput = screen.getByLabelText('Max teams for H-SP1') as HTMLInputElement;
        expect(maxInput.value).toBe('3');
        fireEvent.change(maxInput, { target: { value: '5' } });
        fireEvent.blur(maxInput);

        await waitFor(() => {
            const patchCall = (global.fetch as any).mock.calls.find(
                (c: any) => c[0].includes('/events/10') && c[1]?.method === 'PATCH'
            );
            expect(patchCall).toBeDefined();
            expect(JSON.parse(patchCall[1].body)).toEqual({ ems_max_teams: '5' });
        });

        // Edit opens Edit Event directly; clicking expedition code opens Overview
        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
        expect(screen.getByRole('heading', { name: 'Edit Event' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save Changes' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: '← Expeditions' }));
        await waitFor(() => {
            expect(screen.getByRole('link', { name: 'H-SP1' })).toBeInTheDocument();
        });
        fireEvent.click(screen.getByRole('link', { name: 'H-SP1' }));
        expect(screen.getByRole('button', { name: 'Edit Event' })).toBeInTheDocument();
    });
});
