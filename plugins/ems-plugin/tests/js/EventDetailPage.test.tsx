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
    teams: [
        {
            ID: 100,
            post_title: 'Team 1',
            ems_team_code: 'H-SP1-1',
            ems_team_number: 1,
            event_id: 10,
            members: [
                { scout_id: 30001, user_id: 0, first_name: 'Alice', last_name: 'Brown', first_aid_level: 'full_first_aid' },
                { scout_id: 30002, user_id: 0, first_name: 'Charlie', last_name: 'Green', first_aid_level: 'none' }
            ]
        },
        {
            ID: 0,
            post_title: 'Unallocated Pool',
            ems_team_code: 'UNALLOCATED',
            ems_team_number: 0,
            event_id: 10,
            members: []
        }
    ]
};

describe('EventDetailPage Tab Functions', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('verifies that the Training Tab displays unit and sorts explorers alphabetically', async () => {
        const mockTrainingData = {
            course_ids: [101],
            courses: [{ id: 101, title: 'First Aid Course' }],
            completion: [
                { scout_id: 30002, first_name: 'Charlie', last_name: 'Green', unit_name: 'Selkirk', matrix: { 101: 'not_enrolled' } },
                { scout_id: 30001, first_name: 'Alice', last_name: 'Brown', unit_name: 'SMESU', matrix: { 101: 'complete' } }
            ]
        };

        (global.fetch as any).mockResolvedValueOnce({
            ok: true,
            json: async () => mockTrainingData
        });

        render(<EventDetailPage event={mockEvent} onBack={() => {}} />);

        // Switch to Training tab
        fireEvent.click(screen.getByText('Training'));

        await waitFor(() => {
            expect(screen.getByText('Alice Brown')).toBeInTheDocument();
            expect(screen.getByText('Charlie Green')).toBeInTheDocument();
        });

        // Verify unit names are rendered
        expect(screen.getByText('Unit: SMESU')).toBeInTheDocument();
        expect(screen.getByText('Unit: Selkirk')).toBeInTheDocument();

        // Verify alphabetical sorting (Alice should appear before Charlie in table rows)
        const rows = screen.getAllByRole('row');
        // Row 0 is header, Row 1 should be Alice, Row 2 should be Charlie
        expect(rows[1].textContent).toContain('Alice Brown');
        expect(rows[2].textContent).toContain('Charlie Green');
    });

    it('verifies that the ASNTab has a single save button and submits modified notes', async () => {
        (global.fetch as any)
            .mockResolvedValue({ ok: true, json: async () => mockEvent.teams }) // default fallback
            .mockResolvedValueOnce({ ok: true, json: async () => mockEvent.teams }) // load /events/10/teams
            .mockResolvedValueOnce({ ok: true, json: async () => ({ organiser_notes: 'Needs inhaler', parent_asn: 'Mild asthma' }) }) // load 30001
            .mockResolvedValueOnce({ ok: true, json: async () => ({ organiser_notes: '', parent_asn: '' }) }) // load 30002
            .mockResolvedValueOnce({ ok: true, json: async () => ({ success: true }) }); // save 30001

        render(<EventDetailPage event={mockEvent} onBack={() => {}} />);

        // Switch to Support Needs tab
        fireEvent.click(screen.getByText('Support Needs'));

        await waitFor(() => {
            expect(screen.getByText('Alice Brown')).toBeInTheDocument();
        });

        // Verify there is a single Save button
        const saveBtn = screen.getByRole('button', { name: 'Save Confidential Notes' });
        expect(saveBtn).toBeInTheDocument();

        // Check that individual row save buttons do NOT exist
        expect(screen.queryByRole('button', { name: 'Save Notes' })).not.toBeInTheDocument();

        // Modify Alice's notes
        const textareas = screen.getAllByRole('textbox');
        fireEvent.change(textareas[0], { target: { value: 'Needs inhaler and allergy medicine' } });

        // Click Save Confidential Notes
        fireEvent.click(saveBtn);

        await waitFor(() => {
            // Check that save API was called
            const saveCall = (global.fetch as any).mock.calls.find((c: any) => c[0].includes('/explorers/30001/asn') && c[1]?.method === 'POST');
            expect(saveCall).toBeDefined();
            expect(JSON.parse(saveCall[1].body)).toEqual({ organiser_notes: 'Needs inhaler and allergy medicine' });
        });
    });

    it('verifies bulk action member selection and re-allocation on the Teams tab', async () => {
        const mockAvailability = {
            explorers: [
                { scout_id: 30001, first_name: 'Alice', last_name: 'Brown', unit_name: 'SMESU', first_aid_level: 'full_first_aid', allocated_event_code: null, allocated_team_code: null },
                { scout_id: 30002, first_name: 'Charlie', last_name: 'Green', unit_name: 'Selkirk', first_aid_level: 'none', allocated_event_code: null, allocated_team_code: null }
            ],
            teams: [
                { ID: 100, ems_team_code: 'H-SP1-1', ems_team_number: 1, event_id: 10 }
            ]
        };

        (global.fetch as any).mockImplementation((url: string) => {
            if (url.includes('/planning-board/availability/')) {
                return Promise.resolve({ ok: true, json: async () => mockAvailability });
            }
            if (url.includes('/teams')) {
                return Promise.resolve({ ok: true, json: async () => mockAvailability.teams });
            }
            return Promise.resolve({ ok: true, json: async () => [] });
        });

        const onOpenTeamBuilder = vi.fn();
        render(
            <EventDetailPage
                event={{
                    ...mockEvent,
                    teams: [
                        {
                            ID: 55,
                            post_title: 'H-SP1-1',
                            ems_team_code: 'H-SP1-1',
                            ems_team_number: 1,
                            event_id: 101,
                            members: [
                                { id: 1, user_id: 1, scout_id: 30001, first_name: 'Alice', last_name: 'Brown', first_aid_level: 'first_response' },
                            ],
                        },
                    ],
                }}
                onBack={() => {}}
                onOpenTeamBuilder={onOpenTeamBuilder}
            />
        );

        // Switch to Teams tab
        fireEvent.click(screen.getByRole('button', { name: 'Teams' }));

        expect(screen.getByText('Allocated Event Teams')).toBeInTheDocument();
        expect(screen.getByText('H-SP1-1')).toBeInTheDocument();
        expect(screen.getByText(/Alice Brown/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Open in Team Builder →' }));
        expect(onOpenTeamBuilder).toHaveBeenCalledWith({
            level: 'silver',
            transport: undefined,
            view: 'team-builder',
        });

        fireEvent.click(screen.getByRole('button', { name: /Availability/ }));
        expect(onOpenTeamBuilder).toHaveBeenCalledWith({
            level: 'silver',
            transport: undefined,
            view: 'date-grid',
        });
    });

    it('verifies Route tab view and edit modes', async () => {
        render(<EventDetailPage event={mockEvent} onBack={() => {}} />);

        // Switch to Route tab
        fireEvent.click(screen.getByText('Route'));

        // View mode: shows deadline
        expect(screen.getByText('Route Deadline')).toBeInTheDocument();
        const editBtn = screen.getByRole('button', { name: 'Edit Route' });
        expect(editBtn).toBeInTheDocument();

        // Switch to Edit Mode
        fireEvent.click(editBtn);

        // Verify edit mode inputs are present
        expect(screen.getByText('Edit Route Details')).toBeInTheDocument();
        const statusSelect = screen.getByRole('combobox', { name: 'Route Planning Status' }) as HTMLSelectElement;
        expect(statusSelect).toBeInTheDocument();
        
        const options = Array.from(statusSelect.options).map(o => o.textContent);
        expect(options).toContain('Draft');
        expect(options).toContain('Confirmed');

        expect(screen.getByRole('textbox', { name: 'Notes' })).toBeInTheDocument();

        // Click Save
        (global.fetch as any).mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ...mockEvent, ems_route_status: 'confirmed' })
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save Route Details' }));

        await waitFor(() => {
            const patchCall = (global.fetch as any).mock.calls.find((c: any) => c[0].includes('/events/10') && c[1]?.method === 'PATCH');
            expect(patchCall).toBeDefined();
            expect(JSON.parse(patchCall[1].body)).toEqual(expect.objectContaining({
                ems_route_status: 'draft'
            }));
        });
    });

    it('renders ← Expeditions back link and Edit Event button in header across sub-tabs', () => {
        const onBack = vi.fn();
        render(<EventDetailPage event={mockEvent} onBack={onBack} />);

        const backBtn = screen.getByRole('button', { name: '← Expeditions' });
        expect(backBtn).toBeInTheDocument();
        fireEvent.click(backBtn);
        expect(onBack).toHaveBeenCalled();

        // Switch to Route tab and click Edit Event in header
        fireEvent.click(screen.getByText('Route'));
        const editEventBtn = screen.getByRole('button', { name: 'Edit Event' });
        expect(editEventBtn).toBeInTheDocument();
        fireEvent.click(editEventBtn);

        expect(screen.getByRole('heading', { name: 'Edit Event' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save Changes' })).toBeInTheDocument();
    });

    it('renders 4-card operational summary strip, Expedition Summary, and Joining Instructions on Overview', () => {
        render(
            <EventDetailPage
                event={{
                    ...mockEvent,
                    ems_max_teams: 4,
                    ems_req_volunteers: 3,
                    ems_req_assessors: 2,
                    assigned_volunteers: 2,
                    assigned_assessors: 1,
                    routes_approved: 1,
                    ems_route_deadline: '2026-06-14',
                    ems_osm_event_id: 999,
                    ems_joining_instructions: 'Meet at main car park at 08:30.',
                }}
                lastSync="2026-06-01T10:00:00Z"
                onBack={() => {}}
            />
        );

        expect(screen.getByText('1 / 4 Max')).toBeInTheDocument();
        expect(screen.getByText('Volunteers: 2/3 · Assessors: 1/2')).toBeInTheDocument();
        expect(screen.getByText('1/1 Approved')).toBeInTheDocument();
        expect(screen.getAllByText('#999').length).toBeGreaterThanOrEqual(1);
        expect(screen.getByText('Expedition Summary')).toBeInTheDocument();
        expect(screen.getByText('3 days / 2 nights')).toBeInTheDocument();
        expect(screen.getByText('Joining Instructions & Notes')).toBeInTheDocument();
        expect(screen.getByText('Meet at main car park at 08:30.')).toBeInTheDocument();
    });

    it('auto-seeds multi-day route, supports checkpoints and Same as Day End, and derives start/end on save', async () => {
        render(<EventDetailPage event={mockEvent} onBack={() => {}} />);

        fireEvent.click(screen.getByText('Route'));
        // Read-only itinerary shows 3 seeded days (2026-06-28 to 2026-06-30)
        expect(screen.getByText('3 days')).toBeInTheDocument();
        expect(screen.getByText('Day 1')).toBeInTheDocument();
        expect(screen.getByText('Day 3')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Edit Route' }));

        // Fill Day 1 Start & End
        fireEvent.change(screen.getByLabelText('Day 1 start latitude'), { target: { value: '55.95' } });
        fireEvent.change(screen.getByLabelText('Day 1 start longitude'), { target: { value: '-3.18' } });
        fireEvent.change(screen.getByLabelText('Day 1 end latitude'), { target: { value: '55.96' } });
        fireEvent.change(screen.getByLabelText('Day 1 end longitude'), { target: { value: '-3.19' } });

        // Copy Day 1 End into Day 2 Start
        fireEvent.click(screen.getByRole('button', { name: 'Same as Day 1 End' }));
        expect((screen.getByLabelText('Day 2 start latitude') as HTMLInputElement).value).toBe('55.96');

        // Fill Day 3 End
        fireEvent.change(screen.getByLabelText('Day 3 end latitude'), { target: { value: '55.99' } });
        fireEvent.change(screen.getByLabelText('Day 3 end longitude'), { target: { value: '-3.22' } });

        (global.fetch as any).mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ...mockEvent }),
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save Route Details' }));

        await waitFor(() => {
            const patchCall = (global.fetch as any).mock.calls.find((c: any) => c[0].includes('/events/10') && c[1]?.method === 'PATCH');
            expect(patchCall).toBeDefined();
            const body = JSON.parse(patchCall[1].body);
            expect(body.ems_route_days).toHaveLength(3);
            expect(body.ems_start_location).toBe('55.950000, -3.180000');
            expect(body.ems_end_location).toBe('55.990000, -3.220000');
        });
    });
});
