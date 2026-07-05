import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { OSMReference } from '../../resources/js/admin/expedition-board/OSMReference';
import { BoardData } from '../../resources/js/admin/expedition-board/types';

beforeEach(() => {
    (global as any).window.emsExpeditionBoard = { root_url: 'http://test/wp-json/ems/v1', nonce: 'test-nonce' };
});

const mockData: BoardData = {
    seasons: [],
    explorers: [
        { scout_id: 30001, first_name: 'Alice', last_name: 'MacLeod', patrol: 'Hawks', first_aid_level: 'none' },
        { scout_id: 30002, first_name: 'Bob', last_name: 'Andrews', patrol: 'Hawks', first_aid_level: 'first_response' },
    ],
};

const mockProfileResponse = {
    scout_id: 30001,
    first_name: 'Alice',
    last_name: 'MacLeod',
    email: 'alice@example.com',
    unit: 'Hawks',
    leader_email: 'hawk_leader@example.com',
    organiser_notes: 'Organizer notes content',
    parent_asn: 'Parent notes content',
    training_events: [
        { event_title: 'Bronze Training', team_code: 'T-HA1', osm_status: 'Yes' }
    ],
    practice_events: [],
    qualifiers_events: [],
    preferences: {
        exped_type: 'Hillwalking',
        exped_practice_dates: 'May 2026',
        exped_qualifier_dates: 'Jun 2026',
        exped_team_names: 'Bob, Charlie'
    },
    participant_signups: [
        { id: 1, dofe_level: 'gold', created_at: '2026-06-13 20:00:00', signup_status: 'received', form_submission_id: 999 }
    ],
    training_records: [
        { id: 101, title: 'Camp Prep', status: 'complete' }
    ]
};

describe('OSMReference', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('renders explorers names and units', () => {
        render(<OSMReference data={mockData} />);
        expect(screen.getByText('Alice MacLeod')).toBeInTheDocument();
        expect(screen.getByText('Bob Andrews')).toBeInTheDocument();
    });

    it('clicking an explorer opens inspector and fetches profile data', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            ok: true,
            json: async () => mockProfileResponse
        });

        render(<OSMReference data={mockData} />);
        fireEvent.click(screen.getByText('Alice MacLeod'));

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith('http://test/wp-json/ems/v1/explorers/30001/profile', expect.any(Object));
        });

        await waitFor(() => {
            expect(screen.getByText('Explorer Profile')).toBeInTheDocument();
            expect(screen.getByText('hawk_leader@example.com')).toBeInTheDocument();
            expect(screen.getByText('Organizer notes content')).toBeInTheDocument();
            expect(screen.getByText('Parent notes content')).toBeInTheDocument();
            expect(screen.getByText('Bronze Training (T-HA1)')).toBeInTheDocument();
            expect(screen.getByText('Camp Prep')).toBeInTheDocument();
        });
    });

    it('calls the first aid API when a first aid level is changed in the inspector', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({
                ok: true,
                json: async () => mockProfileResponse
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ scout_id: 30001, first_aid_level: 'full_first_aid' })
            });

        const onChanged = vi.fn();
        render(<OSMReference data={mockData} onChanged={onChanged} />);

        // Open Inspector
        fireEvent.click(screen.getByText('Alice MacLeod'));
        await waitFor(() => expect(screen.getByText('Explorer Profile')).toBeInTheDocument());

        // Change select value
        const select = screen.getByLabelText(/First Aid Level/i);
        fireEvent.change(select, { target: { value: 'full_first_aid' } });

        await waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));
        const [url, opts] = (global.fetch as any).mock.calls[1];
        expect(url).toBe('http://test/wp-json/ems/v1/explorers/30001/first-aid');
        expect(JSON.parse(opts.body)).toEqual({ first_aid_level: 'full_first_aid' });
        expect(onChanged).toHaveBeenCalled();
    });

    it('paginates through filtered list using < and > buttons', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({
                ok: true,
                json: async () => mockProfileResponse
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ ...mockProfileResponse, scout_id: 30002, first_name: 'Bob', last_name: 'Andrews' })
            });

        render(<OSMReference data={mockData} />);

        // Open Inspector for Alice
        fireEvent.click(screen.getByText('Alice MacLeod'));
        await waitFor(() => expect(screen.getByText('Explorer Profile')).toBeInTheDocument());

        // Click next button
        const nextBtn = screen.getByRole('button', { name: '>' });
        fireEvent.click(nextBtn);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledTimes(2);
            expect(global.fetch).toHaveBeenLastCalledWith('http://test/wp-json/ems/v1/explorers/30002/profile', expect.any(Object));
        });
    });

    it('allows saving organiser confidential notes', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({
                ok: true,
                json: async () => mockProfileResponse
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: true, organiser_notes: 'Updated note' })
            });

        render(<OSMReference data={mockData} />);

        // Open Inspector
        fireEvent.click(screen.getByText('Alice MacLeod'));
        await waitFor(() => expect(screen.getByText('Explorer Profile')).toBeInTheDocument());

        // Edit text area
        const textarea = screen.getByLabelText(/Confidential Leaders' Notes/i);
        fireEvent.change(textarea, { target: { value: 'Updated note' } });

        // Click save button
        const saveBtn = screen.getByRole('button', { name: 'Save Confidential Notes' });
        fireEvent.click(saveBtn);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledTimes(2);
            const [url, opts] = (global.fetch as any).mock.calls[1];
            expect(url).toBe('http://test/wp-json/ems/v1/explorers/30001/asn');
            expect(JSON.parse(opts.body)).toEqual({ organiser_notes: 'Updated note' });
        });
    });
});
