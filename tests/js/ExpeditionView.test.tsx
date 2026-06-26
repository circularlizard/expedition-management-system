import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ExpeditionView } from '../../resources/js/admin/expedition-board/ExpeditionView';
import { BoardData, OSMEvent } from '../../resources/js/admin/expedition-board/types';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
};

(global as any).window.emsExpeditionBoard = mockConfig;

const mockBoardData: BoardData = {
    seasons: [
        {
            ID: 1,
            post_title: '2026 Season',
            ems_season_year: '2026',
            ems_season_status: 'active',
            events: [
                {
                    ID: 10,
                    post_title: 'Hill Walking 2026',
                    ems_event_code: 'HW-2026',
                    ems_type: 'practice',
                    ems_level: 'silver',
                    ems_start_date: '2026-07-01',
                    ems_end_date: '2026-07-03',
                    member_count: 4,
                    teams: [
                        {
                            ID: 100,
                            post_title: 'Team A',
                            ems_team_code: 'HW-2026-1',
                            ems_team_number: 1,
                            event_id: 10,
                            member_count: 4,
                            size_warning: false,
                            members: [{ user_id: 1, scout_id: 101, first_name: 'Alice', last_name: 'Smith' }],
                        },
                    ],
                },
            ],
        },
    ],
    last_sync: '2026-06-13T20:00:00Z',
};

const mockCoursesPayload = {
    course_ids: [101],
    courses: [
        { id: 101, title: 'First Aid Course' },
        { id: 102, title: 'Navigation Course' },
    ],
};

describe('ExpeditionView', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('renders the select dropdown and defaults to Overview tab', () => {
        render(<ExpeditionView data={mockBoardData} />);
        expect(screen.getByLabelText('Select expedition')).toBeInTheDocument();
        expect(screen.getByText('Hill Walking 2026')).toBeInTheDocument();
        expect(screen.getByText('Identification')).toBeInTheDocument();
        expect(screen.getByText('Schedule')).toBeInTheDocument();
        expect(screen.queryByText('Teams (1)')).not.toBeInTheDocument();
    });

    it('switches to the Teams tab and displays teams table', () => {
        render(<ExpeditionView data={mockBoardData} />);
        
        fireEvent.click(screen.getByText('Teams'));
        expect(screen.getByText('Teams (1)')).toBeInTheDocument();
        expect(screen.getByText('HW-2026-1')).toBeInTheDocument();
        expect(screen.queryByText('Identification')).not.toBeInTheDocument();
    });

    it('switches to Training Requirements tab, fetches and displays courses checklist', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            ok: true,
            json: async () => mockCoursesPayload,
        });

        render(<ExpeditionView data={mockBoardData} />);
        
        fireEvent.click(screen.getByText('Training Requirements'));
        
        expect(screen.getByText('Loading training requirements...')).toBeInTheDocument();
        
        await waitFor(() => {
            expect(screen.getByText('Required Tutor LMS Courses')).toBeInTheDocument();
            expect(screen.getByLabelText('First Aid Course')).toBeInTheDocument();
            expect(screen.getByLabelText('Navigation Course')).toBeInTheDocument();
        });

        const firstAidCheckbox = screen.getByLabelText('First Aid Course') as HTMLInputElement;
        const navigationCheckbox = screen.getByLabelText('Navigation Course') as HTMLInputElement;

        expect(firstAidCheckbox.checked).toBe(true);
        expect(navigationCheckbox.checked).toBe(false);
        expect(global.fetch).toHaveBeenCalledWith(
            'https://example.com/wp-json/ems/v1/events/10/training-requirements',
            expect.objectContaining({
                headers: { 'X-WP-Nonce': 'test-nonce' },
            })
        );
    });

    it('allows toggling requirements and saving them successfully', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({
                ok: true,
                json: async () => mockCoursesPayload,
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: true, course_ids: [101, 102] }),
            });

        render(<ExpeditionView data={mockBoardData} />);
        
        fireEvent.click(screen.getByText('Training Requirements'));
        
        await waitFor(() => screen.getByLabelText('Navigation Course'));

        const navigationCheckbox = screen.getByLabelText('Navigation Course');
        fireEvent.click(navigationCheckbox);

        const saveButton = screen.getByRole('button', { name: 'Save Requirements' });
        fireEvent.click(saveButton);

        expect(screen.getByText('Saving...')).toBeInTheDocument();

        await waitFor(() => {
            expect(screen.getByText('Training requirements saved successfully.')).toBeInTheDocument();
        });

        expect(global.fetch).toHaveBeenNthCalledWith(
            2,
            'https://example.com/wp-json/ems/v1/events/10/training-requirements',
            expect.objectContaining({
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': 'test-nonce',
                },
                body: JSON.stringify({ course_ids: [101, 102] }),
            })
        );
    });
});
