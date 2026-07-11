import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { PortalApp } from '../../resources/js/portal/index';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
    user_data: {
        logged_in: true,
        first_name: 'Sarah',
        last_name: 'Strachan',
        email: 'sarah@example.com',
        access_type: 'parent'
    },
    login_url: 'https://example.com/wp-login.php'
};

global.window.emsPortal = mockConfig;

describe('PortalApp', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('renders login prompt when not logged in', async () => {
        const loggedOutConfig = {
            ...mockConfig,
            user_data: { logged_in: false, first_name: '', last_name: '', email: '', access_type: '' }
        };
        global.window.emsPortal = loggedOutConfig;

        render(<PortalApp />);

        expect(screen.getByText('Log In via Online Scout Manager')).toBeDefined();
    });

    it('fetches portal data and renders child profiles for parents', async () => {
        global.window.emsPortal = mockConfig;

        (global.fetch as any).mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                logged_in: true,
                access_type: 'parent',
                display_name: 'Sarah Strachan',
                profiles: [
                    { scout_id: 30001, first_name: 'David', last_name: 'Strachan', patrol: 'Falcons' },
                    { scout_id: 30002, first_name: 'James', 'last_name': 'Strachan', patrol: 'Kestrels' }
                ]
            })
        });

        // Mock detail fetch for first child (David)
        (global.fetch as any).mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                explorer: { scout_id: 30001, first_name: 'David', last_name: 'Strachan' },
                signups: [
                    { id: 1, dofe_level: 'silver', signup_status: 'allocated', payment_status: 'reconciled', created_at: '2026-06-13T20:00:00Z', type: 'participant' }
                ],
                events: { training: [], practice: [], qualifying: [] },
                training_checklist: [],
                team: null
            })
        });

        render(<PortalApp />);

        await waitFor(() => {
            expect(screen.getByText('Sarah Strachan')).toBeDefined();
            expect(screen.getByText('David Strachan')).toBeDefined();
            expect(screen.getByText('James Strachan')).toBeDefined();
        });
    });
});
