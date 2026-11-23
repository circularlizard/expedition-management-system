import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import Explorer360Drawer from '../../resources/js/admin/shared/Explorer360Drawer';

describe('Explorer360Drawer', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('loads 360 data, renders contact discrepancy, and syncs all active signups from OSM', async () => {
        const mock360 = {
            explorer: {
                scout_id: 30001,
                is_synced_osm: true,
                first_name: 'Alex',
                last_name: 'Brown',
                patrol: 'Kelso ESU',
                unit_name: 'Kelso ESU',
                dofe_number: '12345',
                first_aid_level: 'first_response',
            },
            contacts: {
                osm_canonical: {
                    explorer_email: 'osm.alex@example.com',
                    email2: 'alt.alex@example.com',
                    parent_email: 'osm.parent@example.com',
                    p1_email2: 'p1.alt@example.com',
                    p2_email1: 'parent2@example.com',
                    p2_email2: 'p2.alt@example.com',
                },
                resolved: {
                    explorer_email: 'osm.alex@example.com',
                    parent_email: 'osm.parent@example.com',
                },
                discrepancies: [
                    {
                        signup_id: 2,
                        signup_type: 'expedition',
                        field: 'explorer_email',
                        osm_value: 'osm.alex@example.com',
                        form_value: 'form.alex@example.com',
                    },
                ],
            },
            signups: [
                {
                    id: 2,
                    type: 'expedition',
                    scout_id: 30001,
                    dofe_level: 'silver',
                    signup_status: 'submitted',
                    kanban_stage: 'new',
                    participant_place_allocated: true,
                    participant_signup_id: 1,
                    anomalies: [],
                },
                {
                    id: 1,
                    type: 'participant',
                    scout_id: 30001,
                    dofe_level: 'silver',
                    signup_status: 'archived',
                    kanban_stage: 'place_allocated',
                    anomalies: [],
                    created_at: '2026-01-01 10:00:00',
                },
            ],
            teams: [
                {
                    id: 9,
                    cohort_name: 'Silver Team A',
                    dofe_level: 'silver',
                    transport_mode: 'foot',
                    notification_status: 'notified',
                },
            ],
            training: {
                status: 'in_progress',
                completed_modules: 2,
                total_modules: 4,
            },
            history: [
                {
                    kind: 'chaser',
                    title: 'Chased Unit Leader (leader@kelso.org)',
                    detail: 'unmatched_osm',
                    timestamp: '2026-11-20 12:00:00',
                },
            ],
        };

        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.includes('/explorers/30001/sync-contacts-from-osm')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({ synced: true, updated_rows: 1 }),
                });
            }
            return Promise.resolve({
                ok: true,
                json: async () => mock360,
            });
        });
        vi.stubGlobal('fetch', fetchMock);

        render(
            <Explorer360Drawer
                isOpen={true}
                scoutId={30001}
                rootUrl="/wp-json/ems/v1"
                nonce="test-nonce"
                initialTab="overview"
                onClose={() => {}}
            />
        );

        await waitFor(() => expect(screen.getByText(/Contact Mismatch Detected/i)).toBeInTheDocument());
        expect(screen.getByText('alt.alex@example.com')).toBeInTheDocument();
        expect(screen.getByText('p1.alt@example.com')).toBeInTheDocument();
        expect(screen.getByText('parent2@example.com')).toBeInTheDocument();
        expect(screen.getByText('p2.alt@example.com')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Sync All Active Sign-ups from OSM/i }));
        await waitFor(() =>
            expect(fetchMock).toHaveBeenCalledWith(
                '/wp-json/ems/v1/explorers/30001/sync-contacts-from-osm',
                expect.objectContaining({ method: 'POST' })
            )
        );

        fireEvent.click(screen.getByRole('button', { name: /Sign-ups \(/i }));
        expect(screen.getByText(/Archived \/ Past Sign-ups \(1\)/i)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Teams & Training/i }));
        expect(screen.getByText(/Silver Team A/i)).toBeInTheDocument();
        expect(screen.getByText(/2\/4 modules/i)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /History/i }));
        expect(screen.getByText(/Chased Unit Leader/i)).toBeInTheDocument();
    });

    it('renders award card headers, semantic anomaly pills, and sticky dirty-state save bar', async () => {
        const mock360 = {
            explorer: {
                scout_id: 30002,
                is_synced_osm: true,
                first_name: 'Sam',
                last_name: 'Taylor',
                unit_name: 'Kelso',
                dofe_number: null,
                first_aid_level: 'none',
            },
            contacts: { osm_canonical: null, resolved: { explorer_email: '', parent_email: '' }, discrepancies: [] },
            signups: [
                {
                    id: 12,
                    type: 'expedition',
                    scout_id: 30002,
                    dofe_level: 'gold',
                    signup_status: 'submitted',
                    kanban_stage: 'new',
                    participant_place_allocated: false,
                    anomalies: ['missing_edofe', 'level_mismatch'],
                },
            ],
            teams: [],
            training: { status: 'unavailable' },
            history: [],
        };

        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => mock360 });
        vi.stubGlobal('fetch', fetchMock);
        const onLevelChanged = vi.fn().mockResolvedValue(undefined);
        const onOpenChaser = vi.fn();

        render(
            <Explorer360Drawer
                isOpen={true}
                scoutId={30002}
                rootUrl="/wp-json/ems/v1"
                nonce="test-nonce"
                initialTab="signups"
                onLevelChanged={onLevelChanged}
                onOpenChaser={onOpenChaser}
                onClose={() => {}}
            />
        );

        await waitFor(() => expect(screen.getByTestId('inspector-entry-expedition_12')).toBeInTheDocument());
        expect(screen.getByText('Missing eDofE')).toHaveClass('ems-anomaly-pill--danger');
        expect(screen.getByText('Level Mismatch')).toHaveClass('ems-anomaly-pill--warning');
        expect(screen.getAllByText('Not recorded')[0]).toHaveClass('ems-drawer-kv__empty');

        fireEvent.click(screen.getByRole('button', { name: /Send Chaser/i }));
        expect(onOpenChaser).toHaveBeenCalledTimes(1);

        // Modify Level -> sticky save bar appears, Discard reverts it
        fireEvent.change(screen.getByLabelText('Change Level'), { target: { value: 'silver' } });
        expect(screen.getByTestId('drawer-save-bar')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Discard' }));
        expect(screen.queryByTestId('drawer-save-bar')).not.toBeInTheDocument();

        // Modify Level -> click Save Changes persists it
        fireEvent.change(screen.getByLabelText('Change Level'), { target: { value: 'silver' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }));
        await waitFor(() => expect(onLevelChanged).toHaveBeenCalledWith(12, 'expedition', 'silver'));
    });
});
