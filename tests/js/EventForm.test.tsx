import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import EventForm from '../../resources/js/admin/expedition-board/EventForm';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
};

(global as any).window.emsExpeditionBoard = mockConfig;

describe('EventForm', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('renders form fields and submits new event', async () => {
        const onSaved = vi.fn();
        (global.fetch as any).mockResolvedValueOnce({ ok: true, json: async () => ({ ID: 10 }) });

        render(<EventForm seasonId={1} onSaved={onSaved} />);

        fireEvent.change(screen.getByLabelText(/Event Code/), { target: { value: 'H-SP1' } });
        fireEvent.change(screen.getByLabelText(/Start Date/), { target: { value: '2027-06-01' } });
        fireEvent.change(screen.getByLabelText(/End Date/), { target: { value: '2027-06-03' } });

        fireEvent.click(screen.getByRole('button', { name: 'Create Event' }));

        await waitFor(() => expect(onSaved).toHaveBeenCalled());
        expect(global.fetch).toHaveBeenCalledWith(
            'https://example.com/wp-json/ems/v1/events',
            expect.objectContaining({ method: 'POST' })
        );
    });

    it('shows validation errors for missing required fields', () => {
        render(<EventForm seasonId={1} />);
        fireEvent.click(screen.getByRole('button', { name: 'Create Event' }));
        expect(screen.getByText('Event code is required')).toBeInTheDocument();
    });

    it('shows error on duplicate event code', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            ok: false,
            status: 409,
            json: async () => ({ code: 'ems_event_code_exists' }),
        });

        render(<EventForm seasonId={1} />);
        fireEvent.change(screen.getByLabelText(/Event Code/), { target: { value: 'H-SP1' } });
        fireEvent.change(screen.getByLabelText(/Start Date/), { target: { value: '2027-06-01' } });
        fireEvent.change(screen.getByLabelText(/End Date/), { target: { value: '2027-06-03' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create Event' }));

        await waitFor(() => {
            expect(screen.getByText('Event code already exists in this season')).toBeInTheDocument();
        });
    });
});
