import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { SeasonDashboard } from '../../resources/js/admin/expedition-board/SeasonDashboard';
import { BoardData, Expedition, Team } from '../../resources/js/admin/expedition-board/types';

beforeEach(() => {
    (global as any).window.emsExpeditionBoard = { root_url: 'http://test/wp-json/ems/v1', nonce: 'test-nonce' };
});

const mockBoard: BoardData = {
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
                    season_id: 1,
                    ems_event_code: 'H-SP1',
                    ems_type: 'practice',
                    ems_transport: 'hillwalking',
                    ems_level: 'silver',
                    ems_start_date: '2027-06-01',
                    ems_end_date: '2027-06-03',
                    teams: [
                        { ID: 20, post_title: 'Team 1', ems_team_code: 'H-SP1-1', ems_team_number: 1, event_id: 10, member_count: 4, size_warning: false, members: [{ user_id: 1, scout_id: 30001, first_name: 'Alice', last_name: 'MacLeod' }] },
                    ],
                    member_count: 4,
                },
            ],
        },
    ],
};

describe('SeasonDashboard', () => {
    it('renders seasons and events', () => {
        render(<SeasonDashboard data={mockBoard} />);
        expect(screen.getByText('2026-27 Season')).toBeInTheDocument();
        expect(screen.getByText(/Hill Practice 1/)).toBeInTheDocument();
        expect(screen.getByText(/H-SP1/)).toBeInTheDocument();
    });

    it('expands event to show teams', () => {
        render(<SeasonDashboard data={mockBoard} />);
        fireEvent.click(screen.getByTestId('event-header-10'));
        expect(screen.getByText('H-SP1-1')).toBeInTheDocument();
        expect(screen.getByText('Alice MacLeod')).toBeInTheDocument();
    });

    it('shows empty state when no seasons', () => {
        render(<SeasonDashboard data={{ seasons: [] }} />);
        expect(screen.getByText(/Create your first season/)).toBeInTheDocument();
    });

    it('falls back to the year when a season has no title', () => {
        const data: BoardData = {
            seasons: [{ ...mockBoard.seasons[0], post_title: '', ems_season_year: '2027', events: [] }],
        };
        render(<SeasonDashboard data={data} />);
        expect(screen.getByText('2027 Season')).toBeInTheDocument();
    });

    it('opens the event form and posts a new event', async () => {
        const newEvent: Expedition = {
            ID: 99,
            post_title: 'New Event',
            season_id: 1,
            ems_event_code: 'H-SP2',
            ems_type: 'practice',
            ems_transport: 'hillwalking',
            ems_level: 'silver',
            ems_start_date: '2027-07-01',
            ems_end_date: '2027-07-03',
            teams: [],
            member_count: 0,
        };
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => newEvent });

        render(<SeasonDashboard data={mockBoard} />);

        fireEvent.click(screen.getAllByRole('button', { name: /Create Event/ })[0]);
        const codeInput = document.querySelector('input[name="ems_event_code"]') as HTMLInputElement;
        fireEvent.change(codeInput, { target: { value: 'H-SP2' } });
        const startInput = document.querySelector('input[name="ems_start_date"]') as HTMLInputElement;
        fireEvent.change(startInput, { target: { value: '2027-07-01' } });
        const endInput = document.querySelector('input[name="ems_end_date"]') as HTMLInputElement;
        fireEvent.change(endInput, { target: { value: '2027-07-03' } });

        fireEvent.click(screen.getByRole('button', { name: /Create Event/ }));

        await waitFor(() => {
            const [url, opts] = (global.fetch as any).mock.calls[0];
            expect(url).toBe('http://test/wp-json/ems/v1/events');
            expect(opts.method).toBe('POST');
        });
        await waitFor(() => expect(screen.getByText(/H-SP2/)).toBeInTheDocument());
    });

    it('adds a team to an event inline without collapsing', async () => {
        const newTeam = { ID: 21, post_title: 'Team 2', ems_team_code: 'H-SP1-2', ems_team_number: 2, event_id: 10, member_count: 0, size_warning: false, members: [] };
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => newTeam });

        render(<SeasonDashboard data={mockBoard} />);

        fireEvent.click(screen.getByTestId('event-header-10'));
        fireEvent.click(screen.getByRole('button', { name: /Add Team/ }));

        await waitFor(() => expect(screen.getByText('H-SP1-2')).toBeInTheDocument());
        expect(screen.getByRole('button', { name: /Add Team/ })).toBeInTheDocument();
    });

    it('adds an explorer to a team inline', async () => {
        const updatedMembers = [
            { user_id: 1, scout_id: 30001, first_name: 'Alice', last_name: 'MacLeod' },
            { user_id: 2, scout_id: 555, first_name: 'Bob', last_name: 'Roy' },
        ];
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => updatedMembers });

        const data: BoardData = { ...mockBoard, explorers: [{ scout_id: 555, first_name: 'Bob', last_name: 'Roy' }] };
        render(<SeasonDashboard data={data} />);

        fireEvent.click(screen.getByTestId('event-header-10'));
        fireEvent.change(screen.getByLabelText('Add explorer to H-SP1-1'), { target: { value: '555' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add' }));

        await waitFor(() => expect(screen.getByText('Bob Roy')).toBeInTheDocument());
        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/teams/20/members');
        expect(JSON.parse(opts.body)).toMatchObject({ scout_id: 555 });
    });

    it('removes a member from a team inline', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ team_deleted: false }) });
        const data: BoardData = {
            ...mockBoard,
            seasons: [{
                ...mockBoard.seasons[0],
                events: [{
                    ...mockBoard.seasons[0].events[0],
                    teams: [{ ...mockBoard.seasons[0].events[0].teams[0], members: [{ user_id: 0, scout_id: 777, first_name: 'Cara', last_name: 'Bell' }] }],
                }],
            }],
        };
        render(<SeasonDashboard data={data} />);

        fireEvent.click(screen.getByTestId('event-header-10'));
        fireEvent.click(screen.getByRole('button', { name: 'Remove Cara Bell' }));

        await waitFor(() => expect(screen.queryByText('Cara Bell')).not.toBeInTheDocument());
        expect((global.fetch as any).mock.calls[0][0]).toBe('http://test/wp-json/ems/v1/teams/20/members/777');
    });

    it('opens the edit event form', async () => {
        render(<SeasonDashboard data={mockBoard} />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
        expect(screen.getByRole('button', { name: /Update Event/ })).toBeInTheDocument();
        const codeInput = document.querySelector('input[name="ems_event_code"]') as HTMLInputElement;
        expect(codeInput?.value).toBe('H-SP1');
    });

    it('updates an event inline without collapsing', async () => {
        const updatedEvent: Expedition = {
            ...mockBoard.seasons[0].events[0],
            ems_start_date: '2027-08-01',
            ems_end_date: '2027-08-03',
        };
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => updatedEvent });

        render(<SeasonDashboard data={mockBoard} />);

        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
        const startInput = document.querySelector('input[name="ems_start_date"]') as HTMLInputElement;
        fireEvent.change(startInput, { target: { value: '2027-08-01' } });
        const endInput = document.querySelector('input[name="ems_end_date"]') as HTMLInputElement;
        fireEvent.change(endInput, { target: { value: '2027-08-03' } });

        fireEvent.click(screen.getByRole('button', { name: /Update Event/ }));

        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/events/10');
        expect(opts.method).toBe('PATCH');
    });

    it('shows size warning for oversized teams', () => {
        const data: BoardData = {
            seasons: [{
                ...mockBoard.seasons[0],
                events: [{
                    ...mockBoard.seasons[0].events[0],
                    teams: [{ ...mockBoard.seasons[0].events[0].teams[0], member_count: 8, size_warning: true }],
                }],
            }],
        };
        render(<SeasonDashboard data={data} />);
        fireEvent.click(screen.getByTestId('event-header-10'));
        expect(screen.getByTitle('Team size outside 4–7')).toBeInTheDocument();
    });

    it('deletes an empty season', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ deleted: true }) });
        window.confirm = vi.fn(() => true);
        const data: BoardData = { ...mockBoard, seasons: [{ ...mockBoard.seasons[0], events: [] }] };
        render(<SeasonDashboard data={data} />);

        fireEvent.click(screen.getByRole('button', { name: /Delete season/ }));

        await waitFor(() => expect(screen.queryByText('2026-27 Season')).not.toBeInTheDocument());
        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/seasons/1');
        expect(opts.method).toBe('DELETE');
    });

    it('does not show delete season button when season has events', () => {
        render(<SeasonDashboard data={mockBoard} />);
        expect(screen.queryByRole('button', { name: /Delete season/ })).not.toBeInTheDocument();
    });

    it('deletes an empty expedition', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ deleted: true }) });
        window.confirm = vi.fn(() => true);
        const data: BoardData = {
            ...mockBoard,
            seasons: [{
                ...mockBoard.seasons[0],
                events: [{ ...mockBoard.seasons[0].events[0], teams: [], member_count: 0 }],
            }],
        };
        render(<SeasonDashboard data={data} />);

        fireEvent.click(screen.getByRole('button', { name: /Delete expedition Hill Practice 1/ }));

        await waitFor(() => expect(screen.queryByText(/Hill Practice 1/)).not.toBeInTheDocument());
        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/events/10');
        expect(opts.method).toBe('DELETE');
    });

    it('filters expeditions by type', () => {
        const data: BoardData = {
            seasons: [{
                ...mockBoard.seasons[0],
                events: [
                    mockBoard.seasons[0].events[0],
                    { ...mockBoard.seasons[0].events[0], ID: 11, post_title: 'Training Day', ems_type: 'training' },
                ],
            }],
        };
        render(<SeasonDashboard data={data} />);
        fireEvent.change(screen.getByLabelText('Filter by type'), { target: { value: 'training' } });
        expect(screen.queryByText('Hill Practice 1')).not.toBeInTheDocument();
        expect(screen.getByText('Training Day')).toBeInTheDocument();
    });

    it('sorts team members alphabetically by last name', () => {
        const data: BoardData = {
            ...mockBoard,
            seasons: [{
                ...mockBoard.seasons[0],
                events: [{
                    ...mockBoard.seasons[0].events[0],
                    teams: [{
                        ...mockBoard.seasons[0].events[0].teams[0],
                        members: [
                            { user_id: 1, scout_id: 30001, first_name: 'Alice', last_name: 'MacLeod' },
                            { user_id: 2, scout_id: 30002, first_name: 'Bob', last_name: 'Andrews' },
                        ],
                    }],
                }],
            }],
        };
        render(<SeasonDashboard data={data} />);
        fireEvent.click(screen.getByTestId('event-header-10'));
        const names = screen.getAllByLabelText(/Remove /).map((el) => el.getAttribute('aria-label'));
        expect(names).toEqual(['Remove Bob Andrews', 'Remove Alice MacLeod']);
    });

    it('displays expedition metadata', () => {
        const data: BoardData = {
            ...mockBoard,
            seasons: [{
                ...mockBoard.seasons[0],
                events: [{
                    ...mockBoard.seasons[0].events[0],
                    ems_start_location: 'Glen Nevis',
                    ems_end_location: 'Fort William',
                    ems_start_time: '08:00',
                    ems_end_time: '16:00',
                    ems_status: 'planned',
                    ems_route_deadline: '2027-05-01',
                }],
            }],
        };
        render(<SeasonDashboard data={data} />);
        expect(screen.getByText('Start: Glen Nevis')).toBeInTheDocument();
        expect(screen.getByText('End: Fort William')).toBeInTheDocument();
        expect(screen.getByText('Time: 08:00 — 16:00')).toBeInTheDocument();
        expect(screen.getByText('Status: planned')).toBeInTheDocument();
        expect(screen.getByText(/Route deadline:/)).toBeInTheDocument();
    });

    it('shows explorer patrol in member list', () => {
        const data: BoardData = {
            ...mockBoard,
            seasons: [{
                ...mockBoard.seasons[0],
                events: [{
                    ...mockBoard.seasons[0].events[0],
                    teams: [{
                        ...mockBoard.seasons[0].events[0].teams[0],
                        members: [{ user_id: 1, scout_id: 30001, first_name: 'Alice', last_name: 'MacLeod', patrol: 'Eagles' }],
                    }],
                }],
            }],
        };
        render(<SeasonDashboard data={data} />);
        fireEvent.click(screen.getByTestId('event-header-10'));
        expect(screen.getByText('(Eagles)')).toBeInTheDocument();
    });

    it('displays leader name and phone in metadata', () => {
        const data: BoardData = {
            ...mockBoard,
            seasons: [{
                ...mockBoard.seasons[0],
                events: [{
                    ...mockBoard.seasons[0].events[0],
                    ems_lic_name: 'Sarah Connor',
                    ems_lic_phone: '07700 900123',
                }],
            }],
        };
        render(<SeasonDashboard data={data} />);
        expect(screen.getByText('Leader: Sarah Connor (07700 900123)')).toBeInTheDocument();
    });

    it('prevents assigning an explorer already in another team of the same expedition', () => {
        const data: BoardData = {
            ...mockBoard,
            seasons: [{
                ...mockBoard.seasons[0],
                events: [{
                    ...mockBoard.seasons[0].events[0],
                    teams: [
                        {
                            ...mockBoard.seasons[0].events[0].teams[0],
                            members: [{ user_id: 1, scout_id: 30001, first_name: 'Alice', last_name: 'MacLeod' }],
                        },
                        {
                            ...mockBoard.seasons[0].events[0].teams[0],
                            ID: 101,
                            ems_team_code: 'H-SP1-2',
                            members: [],
                            member_count: 0,
                        },
                    ],
                    member_count: 1,
                }],
            }],
        };
        render(<SeasonDashboard data={data} />);
        fireEvent.click(screen.getByTestId('event-header-10'));
        const secondTeamSelect = screen.getByLabelText('Add explorer to H-SP1-2');
        expect(screen.queryByRole('option', { name: /Alice MacLeod/ })).not.toBeInTheDocument();
    });

    it('shows an expand/collapse indicator on the event header', () => {
        render(<SeasonDashboard data={mockBoard} />);
        const header = screen.getByTestId('event-header-10');
        expect(header).toHaveAttribute('aria-expanded', 'false');
        expect(header.textContent).toContain('▸');
        fireEvent.click(header);
        expect(header).toHaveAttribute('aria-expanded', 'true');
        expect(header.textContent).toContain('▾');
    });

    it('deduplicates explorers in the add-explorer dropdown', () => {
        const data: BoardData = {
            ...mockBoard,
            explorers: [
                ...(mockBoard.explorers ?? []),
                { scout_id: 30002, first_name: 'Bob', last_name: 'Andrews', patrol: 'Hawks' },
                { scout_id: 30002, first_name: 'Bob', last_name: 'Andrews', patrol: 'Hawks' },
            ],
            seasons: [{
                ...mockBoard.seasons[0],
                events: [{
                    ...mockBoard.seasons[0].events[0],
                    teams: [{
                        ...mockBoard.seasons[0].events[0].teams[0],
                        members: [],
                        member_count: 0,
                    }],
                    member_count: 0,
                }],
            }],
        };
        render(<SeasonDashboard data={data} />);
        fireEvent.click(screen.getByTestId('event-header-10'));
        const options = screen.getAllByRole('option', { name: /Bob Andrews/ });
        expect(options).toHaveLength(1);
    });

    it('opens the move team dialog and calls the API', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ moved: true }) });
        const secondEvent: Expedition = {
            ...mockBoard.seasons[0].events[0],
            ID: 11,
            post_title: 'Hill Practice 2',
            ems_event_code: 'H-SP2',
            teams: [],
            member_count: 0,
        };
        const data: BoardData = {
            ...mockBoard,
            seasons: [{
                ...mockBoard.seasons[0],
                events: [mockBoard.seasons[0].events[0], secondEvent],
            }],
        };
        render(<SeasonDashboard data={data} />);
        fireEvent.click(screen.getByTestId('event-header-10'));
        fireEvent.click(screen.getByRole('button', { name: /Move team H-SP1-1 to another event/ }));
        fireEvent.change(screen.getByDisplayValue('— Select event —'), { target: { value: '11' } });
        fireEvent.click(screen.getByRole('button', { name: 'Move' }));

        await waitFor(() => expect(screen.queryByText('Will be re-coded to')).not.toBeInTheDocument());
        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/teams/20/move');
        expect(JSON.parse(opts.body)).toEqual({ target_event_id: 11 });
    });

    it('opens the duplicate team dialog and calls the API', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ duplicated: true }) });
        const secondEvent: Expedition = {
            ...mockBoard.seasons[0].events[0],
            ID: 11,
            post_title: 'Hill Practice 2',
            ems_event_code: 'H-SP2',
            teams: [],
            member_count: 0,
        };
        const data: BoardData = {
            ...mockBoard,
            seasons: [{
                ...mockBoard.seasons[0],
                events: [mockBoard.seasons[0].events[0], secondEvent],
            }],
        };
        render(<SeasonDashboard data={data} />);
        fireEvent.click(screen.getByTestId('event-header-10'));
        fireEvent.click(screen.getByRole('button', { name: /Duplicate team H-SP1-1 to another event/ }));
        fireEvent.change(screen.getByDisplayValue('— Select event —'), { target: { value: '11' } });
        fireEvent.click(screen.getByRole('button', { name: 'Duplicate' }));

        await waitFor(() => expect(screen.queryByText('New team will be coded')).not.toBeInTheDocument());
        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/teams/20/duplicate');
        expect(JSON.parse(opts.body)).toEqual({ target_event_id: 11 });
    });

    it('opens the move explorer dialog and calls the API', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ moved: true }) });
        const secondTeam: Team = {
            ...mockBoard.seasons[0].events[0].teams[0],
            ID: 101,
            ems_team_code: 'H-SP1-2',
            members: [],
            member_count: 0,
        };
        const data: BoardData = {
            ...mockBoard,
            seasons: [{
                ...mockBoard.seasons[0],
                events: [{
                    ...mockBoard.seasons[0].events[0],
                    teams: [mockBoard.seasons[0].events[0].teams[0], secondTeam],
                }],
            }],
        };
        render(<SeasonDashboard data={data} />);
        fireEvent.click(screen.getByTestId('event-header-10'));
        fireEvent.click(screen.getByRole('button', { name: /Move Alice MacLeod to another team/ }));
        fireEvent.change(screen.getByDisplayValue('— Select team —'), { target: { value: '101' } });
        fireEvent.click(screen.getByRole('button', { name: 'Move' }));

        await waitFor(() => expect(screen.queryByText('Move Alice MacLeod to team')).not.toBeInTheDocument());
        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/explorers/30001/move-team');
        expect(JSON.parse(opts.body)).toEqual({ target_team_id: 101 });
    });
});
