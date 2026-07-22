import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { EventDetailPage } from '../../resources/js/admin/expedition-board/EventDetailPage';
import { Expedition } from '../../resources/js/admin/expedition-board/types';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
};
(global as any).window.emsExpeditionBoard = mockConfig;

const mockEvent: Expedition = {
    ID: 10,
    post_title: 'Hill Practice 1',
    ems_event_code: 'H-SP1',
    ems_type: 'practice',
    ems_level: 'silver',
    ems_start_date: '2026-06-28',
    ems_end_date: '2026-06-30',
    teams: []
};

describe('EventDetailPage Volunteers Tab', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('renders Volunteers tab with staffing indicators and triggers confirmation', async () => {
        const mockVolunteers = [
            {
                id: 1,
                first_name: 'Jane',
                last_name: 'Doe',
                preferred_roles: ['supervisor'],
                qualifications: { first_aid: 'full_first_aid' },
                availability: [
                    { id: 100, expedition_post_id: 10, date: '2026-06-28', overnight: 0, confirmed: 0 }
                ]
            }
        ];

        (global.fetch as any).mockResolvedValueOnce({
            ok: true,
            json: async () => mockVolunteers
        });

        render(<EventDetailPage event={mockEvent} onBack={() => {}} />);

        // Switch to Volunteers tab
        fireEvent.click(screen.getByText('Volunteers'));

        await waitFor(() => {
            expect(screen.getAllByText(/Jane Doe/)[0]).toBeInTheDocument();
            expect(screen.getByText('Supervisors Check')).toBeInTheDocument();
        });

        // Trigger confirmation
        const assignBtn = screen.getByRole('button', { name: 'Assign' });
        expect(assignBtn).toBeInTheDocument();

        (global.fetch as any).mockResolvedValueOnce({
            ok: true,
            json: async () => ({ success: true })
        });

        fireEvent.click(assignBtn);

        await waitFor(() => {
            const assignCall = (global.fetch as any).mock.calls.find((c: any) => c[0].includes('/volunteers/assign') && c[1]?.method === 'POST');
            expect(assignCall).toBeDefined();
            expect(JSON.parse(assignCall[1].body)).toEqual({ volunteer_id: 1, expedition_post_id: 10, confirmed: 1 });
        });
    });
});
