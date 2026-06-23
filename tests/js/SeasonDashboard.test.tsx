import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { SeasonDashboard } from '../../resources/js/admin/expedition-board/SeasonDashboard';
import { BoardData } from '../../resources/js/admin/expedition-board/types';

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
