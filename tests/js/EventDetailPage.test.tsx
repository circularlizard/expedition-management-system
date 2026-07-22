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
                { scout_id: 30001, first_name: 'Alice', last_name: 'Brown', first_aid_level: 'full_first_aid' },
                { scout_id: 30002, first_name: 'Charlie', last_name: 'Green', first_aid_level: 'none' }
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

        render(<EventDetailPage event={mockEvent} onBack={() => {}} />);

        // Switch to Teams tab
        fireEvent.click(screen.getByText('Teams'));

        // Toggle roster open
        await waitFor(() => {
            expect(screen.getByRole('button', { name: 'Show Availability Roster' })).toBeInTheDocument();
        });
        fireEvent.click(screen.getByRole('button', { name: 'Show Availability Roster' }));

        await waitFor(() => {
            expect(screen.getByText('Alice Brown')).toBeInTheDocument();
        });

        // Bulk action bar should not be visible initially
        expect(screen.queryByText(/With Selected/)).not.toBeInTheDocument();

        // Check Alice checkbox
        const aliceCheckbox = screen.getByLabelText('Select Alice Brown');
        fireEvent.click(aliceCheckbox);

        // Bulk action bar should now be visible
        await waitFor(() => {
            expect(screen.getByText(/With Selected/)).toBeInTheDocument();
        });

        // Setup mock responses on the EXISTING mock
        (global.fetch as any).mockReset();
        (global.fetch as any).mockImplementation((url: string) => {
            if (url.includes('/planning-board/allocate')) {
                return Promise.resolve({ ok: true, json: async () => ({ success: true, allocated: 1 }) });
            }
            if (url.includes('/planning-board/availability/')) {
                return Promise.resolve({ ok: true, json: async () => mockAvailability });
            }
            if (url.includes('/teams')) {
                return Promise.resolve({ ok: true, json: async () => mockAvailability.teams });
            }
            return Promise.resolve({ ok: true, json: async () => [] });
        });

        // Select action
        const select = screen.getByRole('combobox', { name: 'Allocation action' });
        fireEvent.change(select, { target: { value: 'unallocated' } });

        const applyBtn = screen.getByRole('button', { name: 'Apply Action' });
        fireEvent.click(applyBtn);

        await waitFor(() => {
            const allocateCall = (global.fetch as any).mock.calls.find((c: any) => c[0].includes('/planning-board/allocate'));
            expect(allocateCall).toBeDefined();
            expect(allocateCall[1].method).toBe('POST');
            expect(JSON.parse(allocateCall[1].body)).toEqual({
                scout_ids: [30001],
                event_code: 'H-SP1',
                mode: 'unallocated'
            });
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
});
