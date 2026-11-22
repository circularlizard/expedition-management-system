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

    it('renders Scouts Scotland 4-tab navigation, unified offer card with collapsed query box, and deduplicated event details', async () => {
        sessionStorage.clear();
        global.window.emsPortal = {
            ...mockConfig,
            user_data: {
                logged_in: true,
                first_name: 'David',
                last_name: 'Strachan',
                email: 'david@example.com',
                access_type: 'member',
                is_admin: false,
            },
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
                        profiles: [{ scout_id: 30001, first_name: 'David', last_name: 'Strachan', patrol: 'Falcons' }],
                    }),
                });
            }
            if (url.includes('/portal/explorer/30001')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        explorer: { scout_id: 30001, first_name: 'David', last_name: 'Strachan', first_aid_level: 'none' },
                        signups: [],
                        events: {
                            training: [],
                            practice: [
                                {
                                    id: 101,
                                    name: 'H-SP2',
                                    event_code: 'H-SP2',
                                    start_date: '2027-06-04',
                                    end_date: '2027-06-06',
                                    location: '',
                                    required_first_aid_level: 'first_response',
                                    whatsapp_explorers: null,
                                    whatsapp_parents: null,
                                    level: 'silver',
                                    type: 'practice',
                                    osm_event_url: null,
                                    leader_in_charge: { name: 'Jane Doe', email: 'jane@example.com', phone: '' },
                                },
                            ],
                            qualifying: [],
                        },
                        training_checklist: [],
                        team: {
                            team_code: 'H-SP2-1',
                            route_status: 'draft',
                            whatsapp_link: null,
                            teammates: [{ first_name: 'Alice', last_initial: 'M', patrol: 'Eagles' }],
                        },
                        offers: [
                            {
                                cohort_id: 11,
                                expedition_signup_id: 501,
                                team_name: 'Team 1',
                                dofe_level: 'silver',
                                transport_mode: 'foot',
                                offer_status: 'pending',
                                deadline: {
                                    allowed: true,
                                    communicated_date: '2026-12-15',
                                    effective_hard_cutoff: '2026-12-31',
                                },
                                stages: [
                                    {
                                        expedition_id: 101,
                                        expedition_name: 'H-SP2',
                                        type: 'practice',
                                        start_date: '2027-06-04',
                                        end_date: '2027-06-06',
                                    },
                                ],
                            },
                        ],
                    }),
                });
            }
            return Promise.reject(new Error(`Unexpected fetch: ${url}`));
        });

        render(<PortalApp />);

        // 4-tab navigation & default My Expeditions view
        expect(await screen.findByRole('tab', { name: 'My Expeditions' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('tab', { name: 'Training' })).toBeDefined();
        expect(screen.getByRole('tab', { name: 'Route Planner' })).toBeDefined();
        expect(screen.getByRole('tab', { name: 'My Sign-ups' })).toBeDefined();

        // Unified Team & Place Offer Card with nested expedition sub-card
        expect(screen.getByText('Team 1 · Silver · Hillwalking')).toBeDefined();
        expect(screen.getByText(/Respond by 15th December 2026/)).toBeDefined();
        expect(screen.getByText('Eagles')).toBeDefined();
        expect(screen.getByText('Alice M')).toBeDefined();
        expect(screen.getByText('Practice · H-SP2')).toBeDefined();
        expect(screen.getByText('4th–6th June 2027')).toBeDefined();
        expect(screen.getByText(/Mode: Hillwalking · Leader in Charge:/)).toBeDefined();
        expect(screen.getByText('Jane Doe')).toBeDefined();
        expect(screen.queryByText(/WhatsApp Groups & QR Codes/i)).toBeNull();

        // Query textarea collapsed by default, expands on click
        expect(screen.queryByPlaceholderText(/Describe the team or date changes/i)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: /Request Team or Date Change/i }));
        expect(screen.getByText(/If you cannot make the proposed dates/i)).toBeDefined();
        expect(screen.getByText('expeditions@sesscouts.org.uk')).toBeDefined();
        expect(screen.getByPlaceholderText(/Describe the team or date changes/i)).toBeDefined();
        expect(screen.getByRole('button', { name: 'Submit Request' })).toBeDefined();
    });
});
