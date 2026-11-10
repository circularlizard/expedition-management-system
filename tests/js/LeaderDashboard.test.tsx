// @vitest-environment jsdom
import React from 'react';
import { render, screen, waitFor, within, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { LeaderDashboard } from '../../resources/js/leader-dashboard/LeaderDashboard';

const mockSignupsPayload = [
    {
        id: 10,
        type: 'participant',
        scout_id: 30003,
        explorer_first_name: 'Zack',
        explorer_last_name: 'Adams',
        dofe_level: 'bronze',
        unit_id: 10,
        unit_name: 'BO-Kelso',
        expedition_preferences: null,
        signup_status: 'submitted',
        created_at: '2026-08-03 10:00:00',
    },
    {
        id: 20,
        type: 'expedition',
        scout_id: 30003,
        explorer_first_name: 'Zack',
        explorer_last_name: 'Adams',
        dofe_level: 'bronze',
        unit_id: 10,
        unit_name: 'BO-Kelso',
        expedition_preferences: { exped_type: 'Hillwalking' },
        signup_status: 'submitted',
        created_at: '2026-08-18 10:00:00',
    },
    {
        id: 30,
        type: 'participant',
        scout_id: 30003,
        explorer_first_name: 'Zack',
        explorer_last_name: 'Adams',
        dofe_level: 'silver',
        unit_id: 10,
        unit_name: 'BO-Kelso',
        expedition_preferences: null,
        signup_status: 'submitted',
        created_at: '2026-08-19 10:00:00',
    },
    {
        id: 11,
        type: 'participant',
        scout_id: 0,
        explorer_first_name: 'Alice',
        explorer_last_name: 'Zimmerman',
        dofe_level: 'silver',
        unit_id: 10,
        unit_name: 'BO-Kelso',
        expedition_preferences: null,
        signup_status: 'submitted',
        created_at: '2026-08-10 10:00:00',
    },
    {
        id: 12,
        type: 'expedition',
        scout_id: 30002,
        explorer_first_name: 'Bob',
        explorer_last_name: 'Smith',
        dofe_level: 'gold',
        unit_id: 20,
        unit_name: 'TW-Peebles',
        expedition_preferences: { exped_type: 'Biking' },
        signup_status: 'submitted',
        created_at: '2026-08-12 10:00:00',
    },
];

describe('LeaderDashboard Component', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        (window as any).emsLeaderDashboard = {
            root_url: 'http://localhost/wp-json/ems/v1',
            nonce: 'test-nonce',
            plugin_url: 'http://localhost/wp-content/plugins/ems-plugin/',
            login_url: 'http://localhost/wp-login.php',
            logout_url: 'http://localhost/wp-login.php?action=logout',
            user_data: {
                logged_in: true,
                display_name: 'Leader Jane',
                can_access: true,
            },
        };

        global.fetch = vi.fn().mockImplementation((url: string) => {
            if (url.includes('/leader-dashboard/signups')) {
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve(mockSignupsPayload),
                });
            }
            return Promise.resolve({ ok: true, json: () => Promise.resolve([]) });
        });
    });

    it('renders explorers grouped by unit and sorted by first name with OSM sync icon, coloured level pills, and no inspector panel', async () => {
        render(<LeaderDashboard />);

        await waitFor(() => {
            expect(screen.getAllByText('Zack Adams').length).toBeGreaterThan(0);
        });

        // 1. No Form Type filter in toolbar
        expect(screen.queryByLabelText(/Form Type/i)).toBeNull();

        // 2. Grouped by Unit with BO-Kelso before TW-Peebles, and Alice Zimmerman before Zack Adams (sorted by first name)
        const rows = screen.getAllByRole('row');
        const rowTexts = rows.map(r => r.textContent || '');

        const kelsoHeaderIdx = rowTexts.findIndex(t => t.includes('BO-Kelso') && !t.includes('Alice') && !t.includes('Zack'));
        const peeblesHeaderIdx = rowTexts.findIndex(t => t.includes('TW-Peebles') && !t.includes('Bob'));
        const aliceRowIdx = rowTexts.findIndex(t => t.includes('Alice Zimmerman'));
        const zackRowIdx = rowTexts.findIndex(t => t.includes('Zack Adams'));
        const bobRowIdx = rowTexts.findIndex(t => t.includes('Bob Smith'));

        expect(kelsoHeaderIdx).toBeGreaterThan(0);
        expect(peeblesHeaderIdx).toBeGreaterThan(kelsoHeaderIdx);
        expect(aliceRowIdx).toBeGreaterThan(kelsoHeaderIdx);
        expect(zackRowIdx).toBeGreaterThan(aliceRowIdx);
        expect(bobRowIdx).toBeGreaterThan(peeblesHeaderIdx);

        // Group header rows have ems-table-group-header class and explorer count badges
        const kelsoRow = screen.getByText('BO-Kelso', { selector: '.ems-table-group-header__title' }).closest('tr')!;
        expect(kelsoRow.className).toContain('ems-table-group-header');
        expect(within(kelsoRow).getByText(/2 explorers/i)).toBeDefined();

        const peeblesRow = screen.getByText('TW-Peebles', { selector: '.ems-table-group-header__title' }).closest('tr')!;
        expect(peeblesRow.className).toContain('ems-table-group-header');
        expect(within(peeblesRow).getByText(/1 explorer/i)).toBeDefined();

        // 3. Zack Adams has 2 rows in BO-Kelso: one bronze, one silver
        const zackRows = screen.getAllByText('Zack Adams').map(el => el.closest('tr')!);
        expect(zackRows).toHaveLength(2);
        const zackBronzeRow = zackRows.find(r => r.textContent?.includes('bronze'))!;
        const zackSilverRow = zackRows.find(r => r.textContent?.includes('silver'))!;
        expect(zackBronzeRow).toBeDefined();
        expect(zackSilverRow).toBeDefined();

        // OSM Sync icon: Zack Adams (scout_id 30003) shows assets/images/osm_icon.png, Alice Zimmerman (scout_id 0) shows nothing
        const zackImg = within(zackBronzeRow).getByRole('img', { name: /Synced with OSM/i }) as HTMLImageElement;
        expect(zackImg.src).toContain('assets/images/osm_icon.png');

        const aliceRow = screen.getByText('Alice Zimmerman').closest('tr')!;
        expect(within(aliceRow).queryByRole('img')).toBeNull();

        // 4. Coloured level pills
        const bronzePill = within(zackBronzeRow).getByText('bronze');
        expect(bronzePill.className).toContain('ems-pill--bronze');

        const silverPill = within(aliceRow).getByText('silver');
        expect(silverPill.className).toContain('ems-pill--silver');

        const bobRow = screen.getByText('Bob Smith').closest('tr')!;
        const goldPill = within(bobRow).getByText('gold');
        expect(goldPill.className).toContain('ems-pill--gold');

        // 5. Checkmarks in Participant Place and Expedition Signup, plus Unit and OSM Sync columns
        const headers = screen.getAllByRole('columnheader');
        expect(headers[3].textContent).toContain('Expedition Signup');
        expect(headers[5].textContent).toContain('Unit');
        expect(headers[6].textContent).toContain('OSM Sync');

        // Zack Bronze row
        const zackBronzeCells = within(zackBronzeRow).getAllByRole('cell');
        // Columns: 0: Explorer Name, 1: Level, 2: Participant Place, 3: Expedition Signup, 4: Expedition Type, 5: Unit, 6: OSM Sync
        expect(zackBronzeCells[2].textContent?.trim()).toBe('✓');
        expect(zackBronzeCells[3].textContent?.trim()).toBe('✓');
        expect(zackBronzeCells[4].textContent?.trim()).toBe('Hillwalking');
        expect(zackBronzeCells[5].textContent?.trim()).toBe('BO-Kelso');
        expect(within(zackBronzeCells[6]).getByRole('img', { name: /Synced with OSM/i })).toBeDefined();

        // Zack Silver row: Participant Place submitted, Expedition Signup not submitted
        const zackSilverCells = within(zackSilverRow).getAllByRole('cell');
        expect(zackSilverCells[2].textContent?.trim()).toBe('✓');
        expect(zackSilverCells[3].textContent?.trim()).toBe('—');

        // 6. Pagination controls exist
        expect(screen.getByLabelText(/Items per page/i)).toBeDefined();
        expect(screen.getByText(/Page 1 of 1/i)).toBeDefined();
        expect(screen.getByRole('button', { name: /Prev/i })).toBeDefined();
        expect(screen.getByRole('button', { name: /Next/i })).toBeDefined();

        // 7. No inspector panel exists
        expect(document.querySelector('.ems-signups-inspector')).toBeNull();
    });

    it('paginates explorer rows across unit groups when items per page is changed and Next/Prev are clicked', async () => {
        const twelveExplorersPayload = Array.from({ length: 12 }, (_, idx) => ({
            id: 100 + idx,
            type: 'participant',
            scout_id: 40000 + idx,
            explorer_first_name: `Explorer${String(idx + 1).padStart(2, '0')}`,
            explorer_last_name: 'Test',
            dofe_level: 'bronze',
            unit_id: idx < 6 ? 10 : 20,
            unit_name: idx < 6 ? 'BO-Kelso' : 'TW-Peebles',
            expedition_preferences: null,
            signup_status: 'submitted',
            created_at: '2026-08-03 10:00:00',
        }));

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(twelveExplorersPayload),
        });

        render(<LeaderDashboard />);

        await waitFor(() => {
            expect(screen.getByText('Explorer01 Test')).toBeDefined();
        });

        // Change Items per page to 10 -> should paginate 12 explorers across 2 pages even when grouped by Unit
        const perPageSelect = screen.getByLabelText(/Items per page/i);
        fireEvent.change(perPageSelect, { target: { value: '10' } });

        expect(screen.getByText(/Showing 1–10 of 12 explorers/i)).toBeDefined();
        expect(screen.getByText(/Page 1 of 2/i)).toBeDefined();
        expect(screen.getByText('Explorer01 Test')).toBeDefined();
        expect(screen.getByText('Explorer10 Test')).toBeDefined();
        expect(screen.queryByText('Explorer11 Test')).toBeNull();

        // Click Next -> Page 2 shows Explorer11 and Explorer12
        const nextBtn = screen.getByRole('button', { name: /Next/i });
        fireEvent.click(nextBtn);

        expect(screen.getByText(/Showing 11–12 of 12 explorers/i)).toBeDefined();
        expect(screen.getByText(/Page 2 of 2/i)).toBeDefined();
        expect(screen.queryByText('Explorer01 Test')).toBeNull();
        expect(screen.getByText('Explorer11 Test')).toBeDefined();
        expect(screen.getByText('Explorer12 Test')).toBeDefined();
    });

    it('filters leader dashboard table by explorer search query and district', async () => {
        (global as any).window.emsLeaderDashboard.units = [
            { name: 'BO-Kelso', district: 'Borders' },
            { name: 'TW-Peebles', district: 'Tweeddale' },
        ];

        render(<LeaderDashboard />);

        await waitFor(() => {
            expect(screen.getAllByText('Zack Adams').length).toBeGreaterThan(0);
            expect(screen.getByText('Alice Zimmerman')).toBeDefined();
            expect(screen.getByText('Bob Smith')).toBeDefined();
        });

        // Search for Zack
        const searchInput = screen.getByLabelText(/Search Explorer:/i);
        fireEvent.change(searchInput, { target: { value: 'Zack' } });

        await waitFor(() => {
            expect(screen.getAllByText('Zack Adams').length).toBeGreaterThan(0);
            expect(screen.queryByText('Alice Zimmerman')).toBeNull();
            expect(screen.queryByText('Bob Smith')).toBeNull();
        });

        // Clear search
        fireEvent.change(searchInput, { target: { value: '' } });
        await waitFor(() => {
            expect(screen.getByText('Alice Zimmerman')).toBeDefined();
        });

        // Filter by Tweeddale district
        const districtSelect = screen.getByLabelText(/Filter District:/i);
        fireEvent.change(districtSelect, { target: { value: 'Tweeddale' } });

        await waitFor(() => {
            expect(screen.getByText('Bob Smith')).toBeDefined();
            expect(screen.queryByText('Zack Adams')).toBeNull();
            expect(screen.queryByText('Alice Zimmerman')).toBeNull();
        });
    });

    it('renders unit summary matrix table tab and navigates to filtered explorer list on cell click', async () => {
        (global as any).window.emsLeaderDashboard.units = [
            { unit_id: 1, name: 'Kelso ESU', district: 'Borders' },
            { unit_id: 2, name: 'Jedburgh ESU', district: 'Borders' },
            { unit_id: 3, name: 'Peebles ESU', district: 'Tweeddale' },
        ];

        const leaderSignups = [
            {
                id: 10,
                type: 'participant',
                scout_id: 30003,
                explorer_first_name: 'Zack',
                explorer_last_name: 'Adams',
                dofe_level: 'bronze',
                unit_name: 'Kelso ESU',
                district: 'Borders',
                expedition_preferences: null,
                signup_status: 'submitted',
                created_at: '2026-08-01 10:00:00',
            },
            {
                id: 20,
                type: 'expedition',
                scout_id: 30003,
                explorer_first_name: 'Zack',
                explorer_last_name: 'Adams',
                dofe_level: 'bronze',
                unit_name: 'Kelso ESU',
                district: 'Borders',
                expedition_preferences: { exped_type: 'Hillwalking' },
                signup_status: 'submitted',
                created_at: '2026-08-02 10:00:00',
            },
            {
                id: 21,
                type: 'expedition',
                scout_id: 30004,
                explorer_first_name: 'Sam',
                explorer_last_name: 'Taylor',
                dofe_level: 'bronze',
                unit_name: 'Kelso ESU',
                district: 'Borders',
                expedition_preferences: { exped_type: 'Paddling' },
                signup_status: 'submitted',
                created_at: '2026-08-03 10:00:00',
            },
            {
                id: 22,
                type: 'expedition',
                scout_id: 30005,
                explorer_first_name: 'Chris',
                explorer_last_name: 'Evans',
                dofe_level: 'silver',
                unit_name: 'Kelso ESU',
                district: 'Borders',
                expedition_preferences: { exped_type: 'Biking' },
                signup_status: 'submitted',
                created_at: '2026-08-04 10:00:00',
            },
            {
                id: 23,
                type: 'expedition',
                scout_id: 30006,
                explorer_first_name: 'Dana',
                explorer_last_name: 'White',
                dofe_level: 'silver',
                unit_name: 'Jedburgh ESU',
                district: 'Borders',
                expedition_preferences: { exped_type: 'Hillwalking' },
                signup_status: 'submitted',
                created_at: '2026-08-05 10:00:00',
            },
            {
                id: 24,
                type: 'expedition',
                scout_id: 30007,
                explorer_first_name: 'Alex',
                explorer_last_name: 'Morgan',
                dofe_level: 'gold',
                unit_name: 'Peebles ESU',
                district: 'Tweeddale',
                expedition_preferences: { exped_type: 'Hillwalking' },
                signup_status: 'submitted',
                created_at: '2026-08-06 10:00:00',
            },
            {
                id: 25,
                type: 'expedition',
                scout_id: 30008,
                explorer_first_name: 'Jordan',
                explorer_last_name: 'Lee',
                dofe_level: 'gold',
                unit_name: 'Peebles ESU',
                district: 'Tweeddale',
                expedition_preferences: { exped_type: 'Biking' },
                signup_status: 'submitted',
                created_at: '2026-08-07 10:00:00',
            },
        ];

        global.fetch = vi.fn().mockImplementation((url: string) => {
            if (url.includes('/leader-dashboard/signups')) {
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve(leaderSignups),
                });
            }
            return Promise.resolve({ ok: true, json: () => Promise.resolve([]) });
        });

        render(<LeaderDashboard />);

        // Wait for data load and check tabs
        const summaryTabBtn = await screen.findByRole('button', { name: /Unit Summary/i });
        expect(summaryTabBtn).toBeInTheDocument();
        const explorersTabBtn = screen.getByRole('button', { name: /Explorer Signups/i });
        expect(explorersTabBtn).toBeInTheDocument();

        // Switch to summary tab
        fireEvent.click(summaryTabBtn);

        // Check matrix table
        const matrixTable = screen.getByTestId('leader-expedition-matrix');
        expect(matrixTable).toBeInTheDocument();

        // Check rows
        const kelsoRow = screen.getByTestId('matrix-row-Kelso ESU');
        expect(within(kelsoRow).getByTestId('cell-bronze-hillwalking')).toHaveTextContent('1');
        expect(within(kelsoRow).getByTestId('cell-bronze-paddling')).toHaveTextContent('1');
        expect(within(kelsoRow).getByTestId('cell-silver-biking')).toHaveTextContent('1');
        expect(within(kelsoRow).getByTestId('cell-total')).toHaveTextContent('3');

        const jedburghRow = screen.getByTestId('matrix-row-Jedburgh ESU');
        expect(within(jedburghRow).getByTestId('cell-silver-hillwalking')).toHaveTextContent('1');
        expect(within(jedburghRow).getByTestId('cell-total')).toHaveTextContent('1');

        const bordersSubtotal = screen.getByTestId('matrix-subtotal-Borders');
        expect(bordersSubtotal.classList.contains('ems-table-group-header')).toBe(true);
        expect(within(bordersSubtotal).getByText('Borders Subtotal')).toHaveClass('ems-table-group-header__title');
        expect(within(bordersSubtotal).getByText('2 units')).toHaveClass('ems-table-group-header__badge');
        expect(within(bordersSubtotal).getByTestId('subtotal-total')).toHaveTextContent('4');

        const peeblesRow = screen.getByTestId('matrix-row-Peebles ESU');
        expect(within(peeblesRow).getByTestId('cell-gold-hillwalking')).toHaveTextContent('1');
        expect(within(peeblesRow).getByTestId('cell-gold-biking')).toHaveTextContent('1');
        expect(within(peeblesRow).getByTestId('cell-total')).toHaveTextContent('2');

        const grandTotalRow = screen.getByTestId('matrix-grand-total');
        expect(within(grandTotalRow).getByTestId('grand-total-total')).toHaveTextContent('6');

        // Click count cell for Kelso ESU Bronze Hillwalking
        const kelsoHwCell = within(kelsoRow).getByTestId('cell-bronze-hillwalking');
        const clickTarget = kelsoHwCell.querySelector('button') || kelsoHwCell;
        fireEvent.click(clickTarget);

        // Should switch to Explorer Signups tab with filtered values
        await waitFor(() => {
            const levelSelect = screen.getByLabelText(/Filter Level:/i) as HTMLSelectElement;
            expect(levelSelect.value).toBe('bronze');
            const expedTypeSelect = screen.getByLabelText(/Expedition Type:/i) as HTMLSelectElement;
            expect(expedTypeSelect.value).toBe('hillwalking');
            const unitSelect = screen.getByLabelText(/Unit:/i) as HTMLSelectElement;
            expect(unitSelect.value).toBe('Kelso ESU');
        });

        // Click Clear Filters
        const clearBtn = screen.getByRole('button', { name: /Clear Filters/i });
        fireEvent.click(clearBtn);

        await waitFor(() => {
            const levelSelect = screen.getByLabelText(/Filter Level:/i) as HTMLSelectElement;
            expect(levelSelect.value).toBe('all');
            const expedTypeSelect = screen.getByLabelText(/Expedition Type:/i) as HTMLSelectElement;
            expect(expedTypeSelect.value).toBe('all');
            const unitSelect = screen.getByLabelText(/Unit:/i) as HTMLSelectElement;
            expect(unitSelect.value).toBe('all');
            const districtSelect = screen.getByLabelText(/Filter District:/i) as HTMLSelectElement;
            expect(districtSelect.value).toBe('all');
        });
    });
});
