import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import VolunteersDashboard from '../../resources/js/admin/volunteers/index';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
};
(global as any).window.emsVolunteers = mockConfig;

describe('VolunteersDashboard Split-Pane Staffing View', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('renders event staffing list with coverage indicators and checks constraints', async () => {
        const mockVolunteers = [
            {
                id: 1,
                first_name: 'John',
                last_name: 'Doe',
                email: 'john@example.com',
                phone: '123',
                preferred_roles: ['supervisor'],
                qualifications: { first_aid: 'none' },
                constraints: { max_practices: 1, max_total: 1 },
                availability: [
                    { id: 100, volunteer_id: 1, expedition_post_id: 10, date: '2026-08-14', overnight: 0, confirmed: 0, signup_type: 'part' }
                ]
            }
        ];

        const mockSeasonsPayload = {
            seasons: [
                {
                    ID: 1,
                    post_title: 'Season 2026',
                    events: [
                        {
                            ID: 10,
                            post_title: 'Bronze Practice 1',
                            ems_event_code: 'B-P1',
                            ems_type: 'practice',
                            ems_start_date: '2026-08-14',
                            ems_end_date: '2026-08-16',
                        }
                    ]
                }
            ]
        };

        // Mock fetches
        (global.fetch as any).mockImplementation((url: string) => {
            if (url.includes('/volunteers')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => mockVolunteers
                });
            }
            if (url.includes('/expedition-board')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => mockSeasonsPayload
                });
            }
            return Promise.reject(new Error('Unknown url: ' + url));
        });

        const { container } = render(<VolunteersDashboard />);

        // Wait for page to load
        await waitFor(() => {
            expect(screen.getAllByText(/Bronze Practice 1/).length).toBeGreaterThan(0);
        });

        // Verify Coverage Health Badge: Since John is confirmed = 0 (Pending), it should say "Pending Availability"
        expect(screen.getAllByText('Pending Availability').length).toBeGreaterThan(0);

        // Switch to Volunteer Registry
        const registryTabBtn = screen.getByRole('button', { name: /Volunteer Registry/ });
        fireEvent.click(registryTabBtn);

        // Check if registry panel loaded and John Doe is selected
        await waitFor(() => {
            expect(screen.getAllByText(/John Doe/).length).toBeGreaterThan(0);
            expect(screen.getByText(/Max Practice/)).toBeInTheDocument();
        });
    });
});
