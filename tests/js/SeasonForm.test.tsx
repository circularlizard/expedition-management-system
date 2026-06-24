import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { SeasonForm } from '../../resources/js/admin/expedition-board/SeasonForm';

const mockConfig = { root_url: 'http://test/wp-json/ems/v1', nonce: 'test-nonce' };
(global as any).window.emsExpeditionBoard = mockConfig;

describe('SeasonForm', () => {
    beforeEach(() => {
        global.fetch = vi.fn();
    });

    it('requires a year before submitting', async () => {
        render(<SeasonForm />);
        fireEvent.click(screen.getByRole('button', { name: /Create Season/ }));
        expect(await screen.findByText(/Season year is required/)).toBeInTheDocument();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('posts the year and calls onSaved on success', async () => {
        (global.fetch as any).mockResolvedValueOnce({ ok: true, json: async () => ({ ID: 5 }) });
        const onSaved = vi.fn();
        render(<SeasonForm onSaved={onSaved} />);

        fireEvent.change(screen.getByPlaceholderText(/2026-27/), { target: { value: '2026-27' } });
        fireEvent.click(screen.getByRole('button', { name: /Create Season/ }));

        await waitFor(() => expect(onSaved).toHaveBeenCalled());
        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/seasons');
        expect(opts.method).toBe('POST');
        expect(JSON.parse(opts.body)).toMatchObject({ year: '2026-27' });
    });

    it('shows an inline error when the year already exists', async () => {
        (global.fetch as any).mockResolvedValueOnce({ ok: false, status: 409, json: async () => ({ code: 'ems_season_year_exists' }) });
        render(<SeasonForm />);

        fireEvent.change(screen.getByPlaceholderText(/2026-27/), { target: { value: '2026-27' } });
        fireEvent.click(screen.getByRole('button', { name: /Create Season/ }));

        expect(await screen.findByText(/already exists/)).toBeInTheDocument();
    });
});
