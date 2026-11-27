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
        fireEvent.change(screen.getByLabelText(/First Aid Required/i), { target: { value: 'first_response' } });
        fireEvent.change(screen.getByLabelText(/Max Teams/i), { target: { value: '4' } });

        fireEvent.click(screen.getByRole('button', { name: 'Create Event' }));

        await waitFor(() => expect(onSaved).toHaveBeenCalled());
        expect(global.fetch).toHaveBeenCalledWith(
            'https://example.com/wp-json/ems/v1/events',
            expect.objectContaining({ method: 'POST' })
        );
        const body = JSON.parse((global.fetch as any).mock.calls[0][1].body);
        expect(body.ems_first_aid_level).toBe('first_response');
        expect(body.ems_max_teams).toBe('4');
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
            expect(screen.getByText('Event code already exists')).toBeInTheDocument();
        });
    });

    it('shows email validation error for invalid leader email', () => {
        render(<EventForm seasonId={1} />);
        fireEvent.change(screen.getByLabelText(/Event Code/), { target: { value: 'H-SP1' } });
        fireEvent.change(screen.getByLabelText(/Start Date/), { target: { value: '2027-06-01' } });
        fireEvent.change(screen.getByLabelText(/End Date/), { target: { value: '2027-06-03' } });
        fireEvent.change(screen.getByLabelText(/Leader Public Email/), { target: { value: 'not-an-email' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create Event' }));
        expect(screen.getByText('Enter a valid email address')).toBeInTheDocument();
    });



    it('renders without forbidden inline structural styles', () => {
        const { container } = render(<EventForm seasonId={1} />);
        const elementsWithStyle = container.querySelectorAll('[style]');
        elementsWithStyle.forEach((el) => {
            const styleAttr = el.getAttribute('style') || '';
            const forbiddenStyles = ['display', 'margin', 'padding', 'flex', 'grid', 'position', 'left', 'top', 'right', 'bottom', 'gap', 'border-radius', 'border:'];
            forbiddenStyles.forEach((prop) => {
                expect(styleAttr).not.toContain(prop);
            });
        });
    });

    it('shows top Save Changes + Cancel in edit mode and sticky dirty bar on change', () => {
        const initialEvent = {
            ID: 10,
            post_title: 'Hill Practice 1',
            ems_event_code: 'H-SP1',
            ems_type: 'practice' as const,
            ems_transport: 'hillwalking' as const,
            ems_level: 'silver' as const,
            ems_start_date: '2027-06-01',
            ems_end_date: '2027-06-03',
            teams: [],
        };
        const onCancel = vi.fn();
        render(<EventForm seasonId={0} initialEvent={initialEvent} onCancel={onCancel} />);

        expect(screen.getByRole('button', { name: 'Save Changes' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
        expect(screen.queryByRole('region', { name: 'Unsaved changes' })).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText(/Max Teams/i), { target: { value: '5' } });
        expect(screen.getByRole('region', { name: 'Unsaved changes' })).toBeInTheDocument();
    });
});
