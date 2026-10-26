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
            expect(screen.getByRole('button', { name: /Signups Table/i })).toBeInTheDocument();
        });
        fireEvent.click(screen.getByRole('button', { name: /Signups Table/i }));

        await waitFor(() => {
            expect(screen.getAllByText('Mary Jones').length).toBeGreaterThan(0);
            expect(screen.getByText('Alice Smith')).toBeInTheDocument();
            expect(screen.getByText('Charlie Smith')).toBeInTheDocument();
            // Form Type column assertions
            expect(screen.getAllByText('Place').length).toBeGreaterThan(0);
            expect(screen.getAllByText('Exped').length).toBeGreaterThan(0);
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

        // Group by Explorer
        fireEvent.change(groupingSelect, { target: { value: 'explorer' } });
        await waitFor(() => {
            expect(screen.getByText(/Mary Jones \(Scout ID: 30003\)/)).toBeInTheDocument();
            expect(screen.getByText(/Alice Smith \(Guest\)/)).toBeInTheDocument();
        });

        // Group by Parent
        fireEvent.change(groupingSelect, { target: { value: 'parent' } });
        await waitFor(() => {
            expect(screen.getByText(/Parent ID: 4/)).toBeInTheDocument();
        });

        // Reset grouping back to none for filtering test
        fireEvent.change(groupingSelect, { target: { value: 'none' } });

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
            expect(screen.getByRole('button', { name: /Signups Table/i })).toBeInTheDocument();
        });
        fireEvent.click(screen.getByRole('button', { name: /Signups Table/i }));

        await waitFor(() => {
            expect(screen.getByText('Alice Smith')).toBeInTheDocument();
        });

        // Open Inspector for Alice Smith (unmatched, parent_email 'parent@example.com', first name 'Alice')
        fireEvent.click(screen.getByText('Alice Smith'));

        await waitFor(() => {
            expect(screen.getByText(/Details/i)).toBeInTheDocument();
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
            expect(screen.getByRole('button', { name: /Signups Table/i })).toBeInTheDocument();
        });
        fireEvent.click(screen.getByRole('button', { name: /Signups Table/i }));

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
            expect(screen.getByRole('button', { name: /Signups Table/i })).toBeInTheDocument();
        });
        fireEvent.click(screen.getByRole('button', { name: /Signups Table/i }));

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
            expect(screen.getByRole('button', { name: /Signups Table/i })).toBeInTheDocument();
        });
        fireEvent.click(screen.getByRole('button', { name: /Signups Table/i }));

        await waitFor(() => {
            expect(screen.getAllByText('Mary Jones').length).toBeGreaterThan(0);
        });

        fireEvent.click(screen.getAllByText('Mary Jones')[0]);

        await waitFor(() => {
            expect(screen.getByText(/Details/i)).toBeInTheDocument();
        });

        // Find the close button (the '×' text button)
        const closeBtn = screen.getByRole('button', { name: '×' });
        fireEvent.click(closeBtn);

        await waitFor(() => {
            expect(screen.queryByText(/Details/i)).not.toBeInTheDocument();
        });
    });

    it('renders without forbidden inline structural styles across both Dashboard and Table tabs', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockCombinedSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers });

        const { container } = render(<SignupsBoard />);
        await waitFor(() => expect(screen.getByText(/Cumulative Signups by Week/i)).toBeInTheDocument());

        const checkNoForbiddenInlineStyles = () => {
            const elementsWithStyle = container.querySelectorAll('[style]');
            elementsWithStyle.forEach((el) => {
                const styleAttr = el.getAttribute('style') || '';
                const forbiddenStyles = ['display', 'margin', 'padding', 'flex', 'grid', 'position', 'left', 'top', 'right', 'bottom', 'gap', 'border-radius', 'border:'];
                forbiddenStyles.forEach((prop) => {
                    expect(styleAttr).not.toContain(prop);
                });
            });
        };

        // Check Dashboard tab
        checkNoForbiddenInlineStyles();

        // Switch to Signups Table tab and check again
        fireEvent.click(screen.getByRole('button', { name: /Signups Table/i }));
        await waitFor(() => expect(screen.getAllByText('Mary Jones').length).toBeGreaterThan(0));
        checkNoForbiddenInlineStyles();
    });

    it('does not render First Aid column in table but displays it in the inspector panel', async () => {
        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockCombinedSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Signups Table/i })).toBeInTheDocument();
        });
        fireEvent.click(screen.getByRole('button', { name: /Signups Table/i }));

        await waitFor(() => {
            expect(screen.getAllByText('Mary Jones').length).toBeGreaterThan(0);
        });

        // Ensure "First Aid" table header is not in the document
        expect(screen.queryByRole('columnheader', { name: /First Aid/i })).not.toBeInTheDocument();

        // Open Inspector for Mary Jones
        fireEvent.click(screen.getAllByText('Mary Jones')[0]);

        await waitFor(() => {
            // First Aid Status should be in the inspector
            expect(screen.getByText('First Aid Status')).toBeInTheDocument();
            expect(screen.getByText('first_response')).toBeInTheDocument();
        });
    });

    it('renders the Signups Dashboard with breakdowns, anomaly summaries, and weekly cumulative chart', async () => {
        const dashboardSignups = [
            ...mockCombinedSignups,
            // Duplicate bronze participant signup for Mary Jones (scout_id 30003)
            {
                ...mockCombinedSignups[0],
                id: 15,
                created_at: '2026-06-20 10:00:00',
            },
            // Biking expedition signup for Bob Smith
            {
                ...mockCombinedSignups[3],
                id: 21,
                scout_id: 30002,
                explorer_first_name: 'Bob',
                explorer_last_name: 'Smith',
                dofe_level: 'gold',
                expedition_preferences: { exped_type: 'Biking' },
                created_at: '2026-06-21 12:00:00',
            }
        ];

        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => dashboardSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getByText(/Signups by Type/i)).toBeInTheDocument();
            expect(screen.getByText(/Signups by DofE Level/i)).toBeInTheDocument();
            expect(screen.getByText(/Signups by Expedition Type/i)).toBeInTheDocument();
            expect(screen.getByText(/Participant Place Only/i)).toBeInTheDocument();
            expect(screen.getByText(/Duplicate Signups/i)).toBeInTheDocument();
            expect(screen.getByText(/No Synced OSM Record/i)).toBeInTheDocument();
            expect(screen.getByText(/Cumulative Signups by Week/i)).toBeInTheDocument();
        });

        // Verify breakdowns
        expect(screen.getByTestId('metric-type-participant')).toHaveTextContent('4');
        expect(screen.getByTestId('metric-type-expedition')).toHaveTextContent('2');
        expect(screen.getByTestId('metric-exped-hillwalking')).toHaveTextContent('1');
        expect(screen.getByTestId('metric-exped-biking')).toHaveTextContent('1');
        expect(screen.getByTestId('metric-exped-paddling')).toHaveTextContent('0');

        // Verify Participant Place Only (Alice Smith - bronze, Charlie Smith - silver; Mary has exped)
        expect(screen.getByTestId('metric-no-expedition-total')).toHaveTextContent('2');
        expect(screen.getByTestId('metric-no-expedition-bronze')).toHaveTextContent('1');
        expect(screen.getByTestId('metric-no-expedition-silver')).toHaveTextContent('1');
        expect(screen.getByTestId('metric-no-expedition-gold')).toHaveTextContent('0');

        // Verify Expedition Only (Bob Smith - gold expedition without participant place)
        expect(screen.getByTestId('metric-no-participant-total')).toHaveTextContent('1');
        expect(screen.getByTestId('metric-no-participant-bronze')).toHaveTextContent('0');
        expect(screen.getByTestId('metric-no-participant-silver')).toHaveTextContent('0');
        expect(screen.getByTestId('metric-no-participant-gold')).toHaveTextContent('1');

        // Verify Duplicate Signups (Mary Jones has 2 bronze participant signups)
        expect(screen.getByTestId('metric-duplicates-groups')).toHaveTextContent('1');
        expect(screen.getByTestId('metric-duplicates-rows')).toHaveTextContent('2');

        // Verify No Synced OSM Record (Alice Smith & Charlie Smith -> 2 signups)
        expect(screen.getByTestId('metric-unmatched-osm')).toHaveTextContent('2');

        // Verify cumulative chart renders 2 lines and a legend (Participant Place and Expedition Preference) without a Signup Type filter
        expect(screen.queryByLabelText(/Chart Signup Type/i)).not.toBeInTheDocument();
        expect(screen.getByTestId('chart-legend')).toBeInTheDocument();
        expect(screen.getByTestId('chart-legend-participant')).toHaveTextContent(/Participant Place/i);
        expect(screen.getByTestId('chart-legend-expedition')).toHaveTextContent(/Expedition Preference/i);
        expect(screen.getByTestId('chart-line-participant')).toBeInTheDocument();
        expect(screen.getByTestId('chart-line-expedition')).toBeInTheDocument();
        expect(screen.getByTestId('chart-cumulative-participant')).toHaveTextContent('4');
        expect(screen.getByTestId('chart-cumulative-expedition')).toHaveTextContent('2');

        // Filter cumulative chart by Level
        const chartLevelSelect = screen.getByLabelText(/Chart Level/i);
        fireEvent.change(chartLevelSelect, { target: { value: 'silver' } });
        expect(screen.getByTestId('chart-cumulative-participant')).toHaveTextContent('1');
        expect(screen.getByTestId('chart-cumulative-expedition')).toHaveTextContent('0');

        // Click "Participant Place Only" section to drill down to filtered Signups Table
        fireEvent.click(screen.getByTestId('card-no-expedition'));

        await waitFor(() => {
            expect(screen.getByText('Alice Smith')).toBeInTheDocument();
            expect(screen.getByText('Charlie Smith')).toBeInTheDocument();
            expect(screen.queryByText('Mary Jones')).not.toBeInTheDocument();
            expect(screen.queryByText('Bob Smith')).not.toBeInTheDocument();
        });

        // Switch back to Dashboard and click "Expedition Only" section
        fireEvent.click(screen.getByRole('button', { name: /Dashboard/i }));
        await waitFor(() => {
            expect(screen.getByTestId('card-no-participant')).toBeInTheDocument();
        });
        fireEvent.click(screen.getByTestId('card-no-participant'));

        await waitFor(() => {
            expect(screen.getByText('Bob Smith')).toBeInTheDocument();
            expect(screen.queryByText('Alice Smith')).not.toBeInTheDocument();
            expect(screen.queryByText('Mary Jones')).not.toBeInTheDocument();
        });
    });

    it('displays Expedition Type column in Signups Table and filters by expedition type', async () => {
        const signupsWithExpedTypes = [
            ...mockCombinedSignups,
            {
                ...mockCombinedSignups[3],
                id: 22,
                scout_id: 30002,
                explorer_first_name: 'Bob',
                explorer_last_name: 'Smith',
                dofe_level: 'gold',
                expedition_preferences: { exped_type: 'Biking' },
                created_at: '2026-06-14 10:00:00',
            }
        ];

        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => signupsWithExpedTypes })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Signups Table/i })).toBeInTheDocument();
        });
        fireEvent.click(screen.getByRole('button', { name: /Signups Table/i }));

        await waitFor(() => {
            expect(screen.getByRole('columnheader', { name: /Expedition Type/i })).toBeInTheDocument();
            expect(screen.getAllByText(/Hillwalking/i).length).toBeGreaterThan(0);
            expect(screen.getAllByText(/Biking/i).length).toBeGreaterThan(0);
        });

        // Filter by Expedition Type = Hillwalking
        const expedTypeSelect = screen.getByLabelText(/Expedition Type:/i);
        fireEvent.change(expedTypeSelect, { target: { value: 'hillwalking' } });

        await waitFor(() => {
            expect(screen.getByText('Mary Jones')).toBeInTheDocument();
            expect(screen.queryByText('Bob Smith')).not.toBeInTheDocument();
            expect(screen.queryByText('Alice Smith')).not.toBeInTheDocument();
        });
    });

    it('updates the selected unit on a signup entry and syncs paired signups for the same explorer', async () => {
        (global as any).window.emsSignupsBoard = {
            ...mockConfig,
            units: [
                { unit_id: 10, short_code: 'Kelso', name: 'Kelso ESU', leader_email: 'leader@kelso.scouts.org.uk' },
                { unit_id: 20, short_code: 'TW-Peebles', name: 'Peebles ESU', leader_email: 'leader@peebles.scouts.org' },
            ],
        };

        const updatedSignups = mockCombinedSignups.map((s) =>
            s.scout_id === 30003
                ? { ...s, unit_id: 20, unit_name: 'TW-Peebles', leader_email: 'leader@peebles.scouts.org' }
                : s
        );

        (global.fetch as any)
            .mockResolvedValueOnce({ ok: true, json: async () => mockCombinedSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    updated: true,
                    unit_id: 20,
                    unit_name: 'TW-Peebles',
                    leader_email: 'leader@peebles.scouts.org',
                }),
            })
            .mockResolvedValueOnce({ ok: true, json: async () => updatedSignups })
            .mockResolvedValueOnce({ ok: true, json: async () => mockExplorers });

        render(<SignupsBoard />);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /Signups Table/i })).toBeInTheDocument();
        });
        fireEvent.click(screen.getByRole('button', { name: /Signups Table/i }));

        await waitFor(() => {
            expect(screen.getAllByText('Mary Jones').length).toBeGreaterThan(0);
        });

        // Click Mary Jones's expedition signup row
        fireEvent.click(screen.getAllByText('Mary Jones')[0]);

        await waitFor(() => {
            expect(screen.getByLabelText(/Change Unit/i)).toBeInTheDocument();
        });

        fireEvent.change(screen.getByLabelText(/Change Unit/i), { target: { value: '20' } });
        fireEvent.click(screen.getByRole('button', { name: /Update Unit/i }));

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(
                expect.stringContaining('/signups/unit'),
                expect.objectContaining({
                    method: 'POST',
                    body: expect.stringContaining('"unit_id":20'),
                })
            );
        });

        await waitFor(() => {
            expect(screen.getAllByText('TW-Peebles').length).toBeGreaterThanOrEqual(2);
        });
    });
});

