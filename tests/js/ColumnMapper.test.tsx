import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import ColumnMapper from '../../resources/js/admin/column-mapper/ColumnMapper';

// Mock window.emsColumnMapper
const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
    sections: {
        '43105': { name: 'Silver ESU', extraid: '73848' }
    }
};

global.window.emsColumnMapper = mockConfig;

describe('ColumnMapper', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('renders initial state and fetches map', async () => {
        (global.fetch as any).mockResolvedValue({
            json: async () => ({ map: { expedition_code: 'f_1' }, required_fields: ['expedition_code'] })
        });

        render(<ColumnMapper />);

        expect(screen.getByText('Please select a section to begin mapping.')).toBeInTheDocument();
        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(expect.stringContaining('flexi-column-map'), expect.any(Object));
        });
    });

    it('loads columns when section is selected', async () => {
        // First fetch for the map
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ map: {}, required_fields: ['expedition_code'] })
        });
        // Second fetch for the structure
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ columns: [{ id: 'f_1', name: 'Group' }] })
        });

        render(<ColumnMapper />);

        const select = screen.getByLabelText('Select Section to Load Columns From:');
        fireEvent.change(select, { target: { value: '43105' } });

        await waitFor(() => {
            expect(screen.getByText('expedition_code')).toBeInTheDocument();
            expect(screen.getByText('Group (f_1)')).toBeInTheDocument();
        });
    });

    it('saves mapping when button is clicked', async () => {
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ map: {}, required_fields: ['expedition_code'] })
        });
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ columns: [{ id: 'f_1', name: 'Group' }] })
        });
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ success: true })
        });

        render(<ColumnMapper />);

        // Select section to show fields
        fireEvent.change(screen.getByLabelText('Select Section to Load Columns From:'), { target: { value: '43105' } });
        await waitFor(() => screen.getByText('expedition_code'));

        // Select a column
        fireEvent.change(screen.getByLabelText(/Map expedition_code/i), { target: { value: 'f_1' } });

        // Save
        fireEvent.click(screen.getByText('Save Mapping'));

        await waitFor(() => {
            expect(screen.getByText('Mapping saved successfully!')).toBeInTheDocument();
        });
    });
});
