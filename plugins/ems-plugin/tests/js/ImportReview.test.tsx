import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import ImportReview from '../../resources/js/admin/column-mapper/ImportReview';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
    sections: {
        '43105': { name: 'Silver ESU', extraid: '73848' }
    }
};

describe('ImportReview', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('renders initial state', () => {
        render(<ImportReview config={mockConfig} />);
        expect(screen.getByText('Step 2: Review & Commit Data')).toBeInTheDocument();
    });

    it('fetches review data when section is selected', async () => {
        const mockBuckets = {
            clean: [{ expedition_code: 'EXP1', team_code: 'T1', participant_scout_id: '1001' }],
            partial: [],
            unparseable: []
        };

        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ buckets: mockBuckets })
        });

        render(<ImportReview config={mockConfig} />);

        fireEvent.change(screen.getByLabelText('Select Section to Review:'), { target: { value: '43105' } });

        await waitFor(() => {
            expect(screen.getByText('Ready to Commit (1)')).toBeInTheDocument();
            expect(screen.getByText('EXP1')).toBeInTheDocument();
        });
    });

    it('commits data when button is clicked', async () => {
        const mockBuckets = {
            clean: [{ expedition_code: 'EXP1', team_code: 'T1', participant_scout_id: '1001' }],
            partial: [],
            unparseable: []
        };

        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ buckets: mockBuckets })
        });
        (global.fetch as any).mockResolvedValueOnce({
            json: async () => ({ count: 1 })
        });

        render(<ImportReview config={mockConfig} />);

        // Load data first
        fireEvent.change(screen.getByLabelText('Select Section to Review:'), { target: { value: '43105' } });
        await waitFor(() => screen.getByText('Commit 1 Records'));

        // Click commit
        fireEvent.click(screen.getByText('Commit 1 Records'));

        await waitFor(() => {
            expect(screen.getByText('Successfully processed 1 records!')).toBeInTheDocument();
        });
    });
});
