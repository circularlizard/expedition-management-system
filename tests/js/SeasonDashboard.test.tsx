import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { SeasonDashboard } from '../../resources/js/admin/expedition-board/SeasonDashboard';
import { BoardData } from '../../resources/js/admin/expedition-board/types';

(global as any).window.emsExpeditionBoard = { root_url: 'http://test/wp-json/ems/v1', nonce: 'test-nonce' };

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
                    ems_event_code: 'H-SP1',
                    ems_type: 'practice',
                    ems_transport: 'hillwalking',
                    ems_level: 'silver',
                    ems_start_date: '2027-06-01',
                    ems_end_date: '2027-06-03',
                    teams: [
                        { ID: 20, post_title: 'Team 1', ems_team_code: 'H-SP1-1', ems_team_number: 1, event_id: 10, member_count: 4, size_warning: false, members: [{ user_id: 1, first_name: 'Alice', last_name: 'MacLeod' }] },
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
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ ID: 99 }) });
        const onChanged = vi.fn();
        render(<SeasonDashboard data={mockBoard} onChanged={onChanged} />);

        fireEvent.click(screen.getAllByRole('button', { name: /Create Event/ })[0]);
        const codeInput = document.querySelector('input[name="ems_event_code"]') as HTMLInputElement;
        fireEvent.change(codeInput, { target: { value: 'H-SP2' } });
        const startInput = document.querySelector('input[name="ems_start_date"]') as HTMLInputElement;
        fireEvent.change(startInput, { target: { value: '2027-07-01' } });
        const endInput = document.querySelector('input[name="ems_end_date"]') as HTMLInputElement;
        fireEvent.change(endInput, { target: { value: '2027-07-03' } });

        fireEvent.click(screen.getByRole('button', { name: /Save Event/ }));

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/events');
        expect(opts.method).toBe('POST');
        expect(JSON.parse(opts.body)).toMatchObject({ season_id: 1, ems_event_code: 'H-SP2' });
    });

    it('adds a team to an event', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({}) });
        const onChanged = vi.fn();
        render(<SeasonDashboard data={mockBoard} onChanged={onChanged} />);

        fireEvent.click(screen.getByTestId('event-header-10'));
        fireEvent.click(screen.getByRole('button', { name: /Add Team/ }));

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        expect((global.fetch as any).mock.calls[0][0]).toBe('http://test/wp-json/ems/v1/events/10/teams');
    });

    it('adds an explorer to a team', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ([]) });
        const onChanged = vi.fn();
        const data: BoardData = { ...mockBoard, explorers: [{ scout_id: 555, first_name: 'Bob', last_name: 'Roy' }] };
        render(<SeasonDashboard data={data} onChanged={onChanged} />);

        fireEvent.click(screen.getByTestId('event-header-10'));
        fireEvent.change(screen.getByLabelText('Add explorer to H-SP1-1'), { target: { value: '555' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add' }));

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/teams/20/members');
        expect(JSON.parse(opts.body)).toMatchObject({ scout_id: 555 });
    });

    it('removes a member from a team', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ([]) });
        const onChanged = vi.fn();
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
        render(<SeasonDashboard data={data} onChanged={onChanged} />);

        fireEvent.click(screen.getByTestId('event-header-10'));
        fireEvent.click(screen.getByRole('button', { name: 'Remove Cara Bell' }));

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        expect((global.fetch as any).mock.calls[0][0]).toBe('http://test/wp-json/ems/v1/teams/20/members/777');
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
        expect(screen.getByText(/Size warning/)).toBeInTheDocument();
    });
});
