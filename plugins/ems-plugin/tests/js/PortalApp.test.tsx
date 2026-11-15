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

declare global {
    interface Window {
        emsPortal?: any;
    }
}

global.window.emsPortal = mockConfig;

describe('PortalApp', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
        global.window.emsPortal = {
            root_url: 'https://example.com/wp-json/ems/v1',
            nonce: 'test-nonce',
            user_data: {
                logged_in: true,
                first_name: 'Sarah',
                last_name: 'Strachan',
                email: 'sarah@example.com',
                access_type: 'parent',
                is_admin: false
            },
            login_url: 'https://example.com/wp-login.php'
        };
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
            expect(screen.getByText('David Strachan')).toBeDefined();
            expect(screen.getByText('James Strachan')).toBeDefined();
        });
    });

    it('does NOT render admin preview bar for regular parent users', async () => {
        sessionStorage.clear();
        global.window.emsPortal = {
            ...mockConfig,
            user_data: {
                logged_in: true,
                first_name: 'Sarah',
                last_name: 'Strachan',
                email: 'sarah@example.com',
                access_type: 'parent',
                is_admin: false
            }
        };

        (global.fetch as any).mockImplementation((url: string) => {
            if (url.includes('/portal/me')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        logged_in: true,
                        is_admin: false,
                        access_type: 'parent',
                        display_name: 'Sarah Strachan',
                        profiles: [
                            { scout_id: 30001, first_name: 'David', last_name: 'Strachan', patrol: 'Falcons' },
                            { scout_id: 30002, first_name: 'James', last_name: 'Strachan', patrol: 'Kestrels' }
                        ]
                    })
                });
            }
            if (url.includes('/portal/explorer/')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        explorer: { scout_id: 30001, first_name: 'David', last_name: 'Strachan' },
                        signups: [],
                        events: { training: [], practice: [], qualifying: [] },
                        training_checklist: [],
                        team: null
                    })
                });
            }
            return Promise.reject(new Error(`Unexpected fetch: ${url}`));
        });

        render(<PortalApp />);

        await waitFor(() => {
            expect(screen.getByText('David Strachan')).toBeDefined();
        });

        // Ensure NO admin preview bar exists in DOM
        expect(screen.queryByText(/ADMIN PREVIEW/i)).toBeNull();
        expect(screen.queryByRole('region', { name: /Admin Portal Preview/i })).toBeNull();
    });

    it('does NOT render admin preview bar for regular explorer users', async () => {
        sessionStorage.clear();
        global.window.emsPortal = {
            ...mockConfig,
            user_data: {
                logged_in: true,
                first_name: 'David',
                last_name: 'Strachan',
                email: 'david@example.com',
                access_type: 'member',
                is_admin: false
            }
        };

        (global.fetch as any).mockImplementation((url: string) => {
            if (url.includes('/portal/me')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        logged_in: true,
                        is_admin: false,
                        access_type: 'member',
                        display_name: 'David Strachan',
                        profiles: [
                            { scout_id: 30001, first_name: 'David', last_name: 'Strachan', patrol: 'Falcons' }
                        ]
                    })
                });
            }
            if (url.includes('/portal/explorer/')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        explorer: { scout_id: 30001, first_name: 'David', last_name: 'Strachan' },
                        signups: [],
                        events: { training: [], practice: [], qualifying: [] },
                        training_checklist: [],
                        team: null
                    })
                });
            }
            return Promise.reject(new Error(`Unexpected fetch: ${url}`));
        });

        render(<PortalApp />);

        await waitFor(() => {
            expect(screen.getByText(/David\s+Strachan/i)).toBeDefined();
        });

        // Ensure NO admin preview bar exists in DOM
        expect(screen.queryByText(/ADMIN PREVIEW/i)).toBeNull();
        expect(screen.queryByRole('region', { name: /Admin Portal Preview/i })).toBeNull();
    });

    it('renders admin preview bar and prompt for administrator without target', async () => {
        sessionStorage.clear();
        global.window.emsPortal = {
            ...mockConfig,
            user_data: {
                logged_in: true,
                first_name: 'Admin',
                last_name: 'User',
                email: 'admin@example.com',
                access_type: 'local',
                is_admin: true
            }
        };

        (global.fetch as any).mockImplementation((url: string) => {
            if (url.includes('/portal/me')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        logged_in: true,
                        is_admin: true,
                        access_type: 'local',
                        display_name: 'Admin User',
                        profiles: []
                    })
                });
            }
            if (url.includes('/portal/admin/preview-targets')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        parents: [
                            { id: 50, name: 'Sarah Strachan', email: 'sarah@example.com', description: 'Children: David', scout_ids: [30001] }
                        ],
                        members: [],
                        explorers: [
                            { scout_id: 30001, first_name: 'David', last_name: 'Strachan', patrol: 'Falcons' }
                        ]
                    })
                });
            }
            return Promise.reject(new Error(`Unexpected fetch: ${url}`));
        });

        render(<PortalApp />);

        await waitFor(() => {
            expect(screen.getByText(/ADMIN PREVIEW/i)).toBeDefined();
            expect(screen.getByText('Administrator Portal Preview')).toBeDefined();
            expect(screen.getByRole('combobox', { name: /Select preview persona/i })).toBeDefined();
        });
    });

    it('allows administrator to select a parent and preview their portal experience', async () => {
        sessionStorage.clear();
        global.window.emsPortal = {
            ...mockConfig,
            user_data: {
                logged_in: true,
                first_name: 'Admin',
                last_name: 'User',
                email: 'admin@example.com',
                access_type: 'local',
                is_admin: true
            }
        };

        (global.fetch as any).mockImplementation((url: string) => {
            if (url.includes('/portal/admin/preview-targets')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        parents: [
                            { id: 50, name: 'Sarah Strachan', email: 'sarah@example.com', description: 'Children: David', scout_ids: [30001] }
                        ],
                        members: [],
                        explorers: []
                    })
                });
            }
            if (url.includes('preview_user_id=50')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        logged_in: true,
                        is_admin: true,
                        is_preview: true,
                        access_type: 'parent',
                        display_name: 'Sarah Strachan',
                        profiles: [
                            { scout_id: 30001, first_name: 'David', last_name: 'Strachan', patrol: 'Falcons' }
                        ]
                    })
                });
            }
            if (url.includes('/portal/me')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        logged_in: true,
                        is_admin: true,
                        access_type: 'local',
                        display_name: 'Admin User',
                        profiles: []
                    })
                });
            }
            if (url.includes('/portal/explorer/30001')) {
                return Promise.resolve({
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
            }
            return Promise.reject(new Error(`Unexpected fetch: ${url}`));
        });

        render(<PortalApp />);

        await waitFor(() => {
            expect(screen.getByRole('combobox', { name: /Select preview persona/i })).toBeDefined();
        });

        const select = screen.getByRole('combobox', { name: /Select preview persona/i });
        fireEvent.change(select, { target: { value: 'user:50:parent' } });

        await waitFor(() => {
            expect(screen.getByText(/David\s+Strachan/i)).toBeDefined();
            expect(screen.getByText('Exit Preview')).toBeDefined();
        });
    });

    it('allows administrator to select an explorer and exit preview', async () => {
        sessionStorage.clear();
        global.window.emsPortal = {
            ...mockConfig,
            user_data: {
                logged_in: true,
                first_name: 'Admin',
                last_name: 'User',
                email: 'admin@example.com',
                access_type: 'local',
                is_admin: true
            }
        };

        (global.fetch as any).mockImplementation((url: string) => {
            if (url.includes('/portal/admin/preview-targets')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        parents: [],
                        members: [],
                        explorers: [
                            { scout_id: 30002, first_name: 'James', last_name: 'Strachan', patrol: 'Kestrels' }
                        ]
                    })
                });
            }
            if (url.includes('preview_scout_id=30002')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        logged_in: true,
                        is_admin: true,
                        is_preview: true,
                        access_type: 'member',
                        display_name: 'James Strachan',
                        profiles: [
                            { scout_id: 30002, first_name: 'James', last_name: 'Strachan', patrol: 'Kestrels' }
                        ]
                    })
                });
            }
            if (url.includes('/portal/me')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        logged_in: true,
                        is_admin: true,
                        access_type: 'local',
                        display_name: 'Admin User',
                        profiles: []
                    })
                });
            }
            if (url.includes('/portal/explorer/30002')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        explorer: { scout_id: 30002, first_name: 'James', last_name: 'Strachan' },
                        signups: [],
                        events: { training: [], practice: [], qualifying: [] },
                        training_checklist: [],
                        team: null
                    })
                });
            }
            return Promise.reject(new Error(`Unexpected fetch: ${url}`));
        });

        render(<PortalApp />);

        await waitFor(() => {
            expect(screen.getByRole('combobox', { name: /Select preview persona/i })).toBeDefined();
        });

        const select = screen.getByRole('combobox', { name: /Select preview persona/i });
        fireEvent.change(select, { target: { value: 'explorer:30002:member' } });

        await waitFor(() => {
            expect(screen.getByText(/James\s+Strachan/i)).toBeDefined();
            expect(screen.getByText('Exit Preview')).toBeDefined();
        });

        // Click Exit Preview
        fireEvent.click(screen.getByText('Exit Preview'));

        await waitFor(() => {
            expect(screen.getByText('Administrator Portal Preview')).toBeDefined();
            expect(screen.queryByText('Exit Preview')).toBeNull();
        });
    });
});
