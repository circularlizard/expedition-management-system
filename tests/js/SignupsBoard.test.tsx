import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SignupsBoard from '../../resources/js/admin/signups-board/SignupsBoard';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
};

(global as any).window.emsSignupsBoard = mockConfig;

const mockSignups = [
    {
        id: 1,
        scout_id: 30001,
        parent_user_id: 4,
        explorer_first_name: 'Jane',
        explorer_last_name: 'Doe',
        dofe_level: 'silver',
        dofe_number: 'D-991234',
        first_aid_status: 'none',
        signup_status: 'pending',
        payment_status: 'paid',
        unit_id: 99001,
        unit_name: 'ESU 1',
        linkage_status: 'linked',
        proposed_scout_id: null,
    },
    {
        id: 2,
        scout_id: null,
        parent_user_id: 4,
        explorer_first_name: 'John',
        explorer_last_name: 'Smith',
        dofe_level: 'bronze',
        dofe_number: 'D-112233',
        first_aid_status: 'none',
        signup_status: 'pending',
        payment_status: 'paid',
        unit_id: 99002,
        unit_name: 'ESU 2',
        linkage_status: 'proposed',
        proposed_scout_id: 30002,
    },
    {
        id: 3,
        scout_id: null,
        parent_user_id: 5,
        explorer_first_name: 'Mark',
        explorer_last_name: 'Davis',
        dofe_level: 'gold',
        dofe_number: null,
        first_aid_status: 'none',
        signup_status: 'pending',
        payment_status: 'pending',
        unit_id: null,
        unit_name: 'Unassigned',
        linkage_status: 'unlinked',
        proposed_scout_id: null,
    }
];

const mockBoardData = {
    seasons: [],
    explorers: [
        { scout_id: 30002, first_name: 'John', last_name: 'Smith', patrol: 'Bears' },
        { scout_id: 30003, first_name: 'Mark', last_name: 'Davis', patrol: 'Stags' },
    ],
};

describe('SignupsBoard', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('shows loading state initially', () => {
        (global.fetch as any).mockReturnValueOnce(new Promise(() => {}));
        render(<SignupsBoard />);
        expect(screen.getByText(/Loading/i)).toBeInTheDocument();
    });

    it('renders the signups list and resolved data', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockBoardData });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getByText('Jane Doe')).toBeInTheDocument();
            expect(screen.getByText('John Smith')).toBeInTheDocument();
            expect(screen.getByText('Mark Davis')).toBeInTheDocument();
            expect(screen.getByText('✅ Linked')).toBeInTheDocument();
            expect(screen.getByText(/Fuzzy Match/i)).toBeInTheDocument();
            expect(screen.getByText('❌ Unlinked')).toBeInTheDocument();
        });
    });

    it('filters the list by DofE level', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockBoardData });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getByText('Jane Doe')).toBeInTheDocument();
        });

        const filterSelect = screen.getByLabelText(/Filter Level/i);
        fireEvent.change(filterSelect, { target: { value: 'gold' } });

        expect(screen.queryByText('Jane Doe')).not.toBeInTheDocument();
        expect(screen.queryByText('John Smith')).not.toBeInTheDocument();
        expect(screen.getByText('Mark Davis')).toBeInTheDocument();
    });

    it('handles process action', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockBoardData })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ processed: true }) });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getByText('Jane Doe')).toBeInTheDocument();
        });

        const processButtons = screen.getAllByRole('button', { name: /Process/i });
        fireEvent.click(processButtons[0]);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(
                expect.stringContaining('/signups/1/process'),
                expect.objectContaining({ method: 'POST' })
            );
        });
    });

    it('handles confirm link fuzzy match quick action', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockBoardData })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ reconciled: true }) });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getByText('John Smith')).toBeInTheDocument();
        });

        const confirmButton = screen.getByRole('button', { name: /Confirm Link/i });
        fireEvent.click(confirmButton);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(
                expect.stringContaining('/signups/2/reconcile'),
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({ scout_id: 30002 })
                })
            );
        });
    });
});
