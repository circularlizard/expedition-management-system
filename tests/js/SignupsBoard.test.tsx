import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SignupsBoard from '../../resources/js/admin/signups-board/SignupsBoard';

const mockConfig = {
    root_url: 'https://example.com/wp-json/ems/v1',
    nonce: 'test-nonce',
};

(global as any).window.emsSignupsBoard = mockConfig;

const mockExplorers = [
    {
        scout_id: 30001,
        first_name: 'Alice',
        last_name: 'Smith',
        email: 'alice@example.com',
        parent_email: 'parent@example.com',
        section_id: 101,
        patrol: 'Eagles'
    },
    {
        scout_id: 30002,
        first_name: 'Bob',
        last_name: 'Smith',
        email: 'bob@example.com',
        parent_email: 'parent@example.com',
        section_id: 101,
        patrol: 'Eagles'
    },
    {
        scout_id: 30003,
        first_name: 'Mary',
        last_name: 'Jones',
        email: 'mary@example.com',
        parent_email: 'parent2@example.com',
        section_id: 102,
        patrol: 'Otters'
    }
];

const mockCombinedSignups = [
    {
        id: 10,
        type: 'participant',
        scout_id: 30003,
        parent_user_id: 4,
        unit_name: 'Kelso',
        explorer_first_name: 'Mary',
        explorer_last_name: 'Jones',
        explorer_email: 'mary@example.com',
        parent_email: 'parent2@example.com',
        leader_email: 'leader@example.com',
        dofe_level: 'bronze',
        dob: '2010-05-15',
        dofe_registered: 'n',
        dofe_number: null,
        bronze_completion: null,
        silver_completion: null,
        signup_status: 'submitted',
        payment_status: 'paid',
        form_submission_id: 1234,
        is_synced_osm: 1,
        created_at: '2026-06-13 20:00:00',
    },
    {
        id: 11,
        type: 'participant',
        scout_id: 0, // Unmatched guest
        parent_user_id: 4,
        unit_name: 'Selkirk',
        explorer_first_name: 'Alice',
        explorer_last_name: 'Smith',
        explorer_email: '',
        parent_email: 'parent@example.com',
        leader_email: 'leader@example.com',
        dofe_level: 'bronze',
        dob: '2010-05-15',
        dofe_registered: 'y-other',
        dofe_number: null,
        dofe_org: 'Borders Scout Region',
        bronze_completion: null,
        silver_completion: null,
        signup_status: 'submitted',
        payment_status: 'paid',
        form_submission_id: 1235,
        is_synced_osm: 0,
        created_at: '2026-06-13 20:01:00',
    },
    {
        id: 12,
        type: 'participant',
        scout_id: 0, // Unmatched guest with sibling collision risk (same parent email, different first name)
        parent_user_id: 4,
        unit_name: 'Selkirk',
        explorer_first_name: 'Charlie',
        explorer_last_name: 'Smith',
        explorer_email: '',
        parent_email: 'parent@example.com',
        leader_email: 'leader@example.com',
        dofe_level: 'silver',
        dob: '2010-05-15',
        dofe_registered: 'n',
        dofe_number: null,
        bronze_completion: null,
        silver_completion: null,
        signup_status: 'submitted',
        payment_status: 'paid',
        form_submission_id: 1236,
        is_synced_osm: 0,
        created_at: '2026-06-13 20:02:00',
    },
    {
        id: 20,
        type: 'expedition',
        scout_id: 30003,
        parent_user_id: 5,
        unit_name: 'Kelso',
        explorer_first_name: 'Mary',
        explorer_last_name: 'Jones',
        explorer_email: 'mary@example.com',
        parent_email: 'parent2@example.com',
        leader_email: 'leader@example.com',
        dofe_level: 'bronze',
        dofe_number: 'D-991234',
        expedition_preferences: { exped_type: 'Hillwalking' },
        additional_support_needs: '',
        first_aid_status: 'first_response',
        first_aid_expiry: '2028-06-13',
        signup_status: 'submitted',
        form_submission_id: 5678,
        is_synced_osm: 1,
        created_at: '2026-06-13 20:03:00',
    }
];

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

    it('renders the consolidated list, allows filtering and grouping', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockCombinedSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getAllByText('Mary Jones').length).toBeGreaterThan(0);
            expect(screen.getByText('Alice Smith')).toBeInTheDocument();
            expect(screen.getByText('Charlie Smith')).toBeInTheDocument();
        });

        // Group by Unit ESU
        const groupingSelect = screen.getByLabelText(/Group By/i);
        fireEvent.change(groupingSelect, { target: { value: 'unit' } });

        await waitFor(() => {
            expect(screen.getByText('Unit: Kelso')).toBeInTheDocument();
            expect(screen.getByText('Unit: Selkirk')).toBeInTheDocument();
        });

        // Group by Level
        fireEvent.change(groupingSelect, { target: { value: 'level' } });
        await waitFor(() => {
            expect(screen.getByText('Level: bronze')).toBeInTheDocument();
            expect(screen.getByText('Level: silver')).toBeInTheDocument();
        });

        // Test Level Filter
        const levelSelect = screen.getByLabelText(/Filter Level/i);
        fireEvent.change(levelSelect, { target: { value: 'silver' } });

        await waitFor(() => {
            expect(screen.queryAllByText('Mary Jones').length).toBe(0);
            expect(screen.getByText('Charlie Smith')).toBeInTheDocument();
        });
    });

    it('renders suggestions for unmatched guest matching emails/names and checks first name similarity', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockCombinedSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getByText('Alice Smith')).toBeInTheDocument();
        });

        // Open Inspector for Alice Smith (unmatched, parent_email 'parent@example.com', first name 'Alice')
        fireEvent.click(screen.getByText('Alice Smith'));

        await waitFor(() => {
            expect(screen.getByText('Explorer Details')).toBeInTheDocument();
            // Should suggest Alice Smith (scout_id 30001) as match due to same parent email and name similarity
            expect(screen.getAllByText(/Alice Smith/i).length).toBeGreaterThan(0);
            expect(screen.getByRole('button', { name: /Confirm Match/i })).toBeInTheDocument();
        });

        // Open Inspector for Charlie Smith (parent_email 'parent@example.com', different name)
        fireEvent.click(screen.getByText('Charlie Smith'));

        await waitFor(() => {
            // Sibling Collision Guard: Should NOT suggest Alice Smith or Bob Smith because Charlie does not share first name similarity.
            expect(screen.queryByText(/Suggested matches:/i)).not.toBeInTheDocument();
        });
    });

    it('reconciles guest signup on confirm match', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockCombinedSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ reconciled: true }) });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getByText('Alice Smith')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('Alice Smith'));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Confirm Match/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Confirm Match/i }));

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(
                expect.stringContaining('/signups/reconcile'),
                expect.objectContaining({
                    method: 'POST',
                    body: expect.stringContaining('"scout_id":30001')
                })
            );
        });
    });

    it('unlinks matched explorer and displays conflict error if assigned to team', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockCombinedSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers })
            .mockResolvedValueOnce({
                ok: false,
                status: 409,
                json: async () => ({ message: 'Cannot unlink explorer who is currently assigned to a team.' })
            });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getAllByText('Mary Jones').length).toBeGreaterThan(0);
        });

        // Open matched signup Mary Jones
        fireEvent.click(screen.getAllByText('Mary Jones')[0]);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Unlink OSM Profile/i })).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: /Unlink OSM Profile/i }));

        await waitFor(() => {
            expect(screen.getByText(/Cannot unlink explorer who is currently assigned to a team/i)).toBeInTheDocument();
        });
    });

    it('closes inspector when close button is clicked', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockCombinedSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getAllByText('Mary Jones').length).toBeGreaterThan(0);
        });

        fireEvent.click(screen.getAllByText('Mary Jones')[0]);

        await waitFor(() => {
            expect(screen.getByText('Explorer Details')).toBeInTheDocument();
        });

        // Find the close button (the '×' text button)
        const closeBtn = screen.getByRole('button', { name: '×' });
        fireEvent.click(closeBtn);

        await waitFor(() => {
            expect(screen.queryByText('Explorer Details')).not.toBeInTheDocument();
        });
    });

    it('renders without forbidden inline structural styles', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockCombinedSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers });

        const { container } = render(<SignupsBoard />);
        await waitFor(() => expect(screen.getAllByText('Mary Jones').length).toBeGreaterThan(0));

        const elementsWithStyle = container.querySelectorAll('[style]');
        elementsWithStyle.forEach((el) => {
            const styleAttr = el.getAttribute('style') || '';
            const forbiddenStyles = ['display', 'margin', 'padding', 'flex', 'grid', 'position', 'left', 'top', 'right', 'bottom', 'gap', 'border-radius', 'border:'];
            forbiddenStyles.forEach((prop) => {
                expect(styleAttr).not.toContain(prop);
            });
        });
    });
});
