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
        { scout_id: 30002, first_name: 'Bob', last_name: 'Andrews', first_aid_level: 'first_response' },
    ],
};

describe('OSMReference', () => {
    it('renders explorers and their first aid levels', () => {
        render(<OSMReference data={mockData} />);
        expect(screen.getByText('Alice MacLeod')).toBeInTheDocument();
        expect(screen.getByText('Bob Andrews')).toBeInTheDocument();
        expect(screen.getByLabelText(/First aid level for Alice MacLeod/)).toHaveValue('none');
        expect(screen.getByLabelText(/First aid level for Bob Andrews/)).toHaveValue('first_response');
    });

    it('sorts explorers A-Z by first name by default', () => {
        const data: BoardData = {
            seasons: [],
            explorers: [
                { scout_id: 30001, first_name: 'Alice', last_name: 'MacLeod', patrol: 'Hawks', first_aid_level: 'none' },
                { scout_id: 30002, first_name: 'Bob', last_name: 'Andrews', first_aid_level: 'first_response' },
            ],
        };
        render(<OSMReference data={data} />);
        const rows = screen.getAllByRole('row');
        expect(rows[1].textContent).toContain('Alice MacLeod');
        expect(rows[2].textContent).toContain('Bob Andrews');
    });

    it('calls the API when a first aid level is changed', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({ scout_id: 30001, first_aid_level: 'full_first_aid' }) });
        const onChanged = vi.fn();

        render(<OSMReference data={mockData} onChanged={onChanged} />);
        fireEvent.change(screen.getByLabelText(/First aid level for Alice MacLeod/), { target: { value: 'full_first_aid' } });

        await waitFor(() => expect(global.fetch).toHaveBeenCalled());
        const [url, opts] = (global.fetch as any).mock.calls[0];
        expect(url).toBe('http://test/wp-json/ems/v1/explorers/30001/first-aid');
        expect(JSON.parse(opts.body)).toEqual({ first_aid_level: 'full_first_aid' });
        expect(onChanged).toHaveBeenCalled();
    });

    it('shows empty state when no explorers', () => {
        render(<OSMReference data={{ seasons: [] }} />);
        expect(screen.getByText(/No explorers have been synced yet/)).toBeInTheDocument();
    });
});
