import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SignupsBoard from '../../resources/js/admin/signups-board/SignupsBoard';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
};

(global as any).window.emsSignupsBoard = mockConfig;

const mockParticipantSignups = [
    {
        id: 10,
        scout_id: 30001,
        parent_user_id: 4,
        unit_name: 'Kelso',
        explorer_first_name: 'Mary',
        explorer_last_name: 'Smith',
        explorer_email: 'mary@example.com',
        parent_email: 'parent@example.com',
        leader_email: 'leader@example.com',
        dofe_level: 'bronze',
        dob: '2010-05-15',
        dofe_registered: 'n',
        dofe_number: null,
        bronze_completion: null,
        silver_completion: null,
        signup_status: 'received',
        payment_status: 'paid',
        form_submission_id: 1234,
        created_at: '2026-06-13 20:00:00',
    },
    {
        id: 11,
        scout_id: 30003,
        parent_user_id: 4,
        unit_name: 'Selkirk',
        explorer_first_name: 'Bob',
        explorer_last_name: 'Jones',
        explorer_email: 'bob@example.com',
        parent_email: 'parent@example.com',
        leader_email: 'leader@example.com',
        dofe_level: 'bronze',
        dob: '2010-05-15',
        dofe_registered: 'y-other',
        dofe_number: 'D-998877',
        dofe_org: 'Borders Scout Region',
        bronze_completion: null,
        silver_completion: null,
        signup_status: 'received',
        payment_status: 'paid',
        form_submission_id: 1235,
        created_at: '2026-06-13 20:00:00',
    }
];

const mockExpeditionSignups = [
    {
        id: 20,
        scout_id: 30002,
        parent_user_id: 5,
        unit_name: 'SMESU',
        explorer_first_name: 'John',
        explorer_last_name: 'Doe',
        explorer_email: 'john@example.com',
        parent_email: 'parent2@example.com',
        leader_email: 'leader@example.com',
        dofe_level: 'silver',
        dofe_number: 'D-991234',
        expedition_preferences: { exped_type: 'Hillwalking' },
        additional_support_needs: '',
        first_aid_status: 'first_response',
        first_aid_expiry: '2028-06-13',
        signup_status: 'pending',
        form_submission_id: 5678,
        created_at: '2026-06-13 20:00:00',
    }
];

describe('SignupsBoard', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('shows loading state initially', () => {
        (global.fetch as any).mockReturnValueOnce(new Promise(() => {}));
        render(<SignupsBoard type="participant" />);
        expect(screen.getByText(/Loading/i)).toBeInTheDocument();
    });

    it('renders the participant places list and allows processing', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockParticipantSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ processed: true }) });

        render(<SignupsBoard type="participant" />);

        await waitFor(() => {
            expect(screen.getByText('Mary Smith')).toBeInTheDocument();
            expect(screen.getByText('Kelso')).toBeInTheDocument();
        });

        // Click row to open Inspector Panel
        fireEvent.click(screen.getByText('Mary Smith'));

        await waitFor(() => {
            expect(screen.getByText('Explorer Details')).toBeInTheDocument();
            expect(screen.getByText('leader@example.com')).toBeInTheDocument();
        });

        // Click Allocate Slot
        const allocateBtn = screen.getByRole('button', { name: /Allocate Slot/i });
        fireEvent.click(allocateBtn);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(
                expect.stringContaining('/signups/participants/10/process'),
                expect.objectContaining({ method: 'POST' })
            );
        });
    });

    it('renders the expedition signups list and inspector details without process buttons', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockExpeditionSignups });

        render(<SignupsBoard type="expedition" />);

        await waitFor(() => {
            expect(screen.getByText('John Doe')).toBeInTheDocument();
            expect(screen.getByText('SMESU')).toBeInTheDocument();
        });

        // Click row to open Inspector Panel
        fireEvent.click(screen.getByText('John Doe'));

        await waitFor(() => {
            expect(screen.getByText('Explorer Details')).toBeInTheDocument();
            expect(screen.getByText('leader@example.com')).toBeInTheDocument();
        });

        // Verify Process/Allocate buttons are NOT visible
        expect(screen.queryByRole('button', { name: /Process Entry/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Allocate Slot/i })).not.toBeInTheDocument();

        // Verify Archive button is visible
        expect(screen.getByRole('button', { name: /Archive/i })).toBeInTheDocument();
    });

    it('flags and displays transfer required when dofe_registered is y-other', async () => {
        (global.fetch as any).mockResolvedValueOnce({ ok: true, json: async () => mockParticipantSignups });

        render(<SignupsBoard type="participant" />);

        await waitFor(() => {
            expect(screen.getByText('Bob Jones')).toBeInTheDocument();
            expect(screen.getByText('⚠️ Transfer Req.')).toBeInTheDocument();
        });

        // Open inspector
        fireEvent.click(screen.getByText('Bob Jones'));

        await waitFor(() => {
            expect(screen.getByText('Registered (Other)')).toBeInTheDocument();
            expect(screen.getByText('Transfer Required')).toBeInTheDocument();
            expect(screen.getByText('Borders Scout Region')).toBeInTheDocument();
        });
    });
});
