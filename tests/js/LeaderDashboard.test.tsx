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
            expect(screen.getByText('Zack Adams')).toBeDefined();
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

        // 3. OSM Sync icon: Zack Adams (scout_id 30003) shows assets/images/osm_icon.png, Alice Zimmerman (scout_id 0) shows nothing
        const zackRow = screen.getByText('Zack Adams').closest('tr')!;
        const zackImg = within(zackRow).getByRole('img', { name: /Synced with OSM/i }) as HTMLImageElement;
        expect(zackImg.src).toContain('assets/images/osm_icon.png');

        const aliceRow = screen.getByText('Alice Zimmerman').closest('tr')!;
        expect(within(aliceRow).queryByRole('img')).toBeNull();

        // 4. Coloured level pills
        const bronzePill = within(zackRow).getByText('bronze');
        expect(bronzePill.className).toContain('ems-pill--bronze');

        const silverPill = within(aliceRow).getByText('silver');
        expect(silverPill.className).toContain('ems-pill--silver');

        const bobRow = screen.getByText('Bob Smith').closest('tr')!;
        const goldPill = within(bobRow).getByText('gold');
        expect(goldPill.className).toContain('ems-pill--gold');

        // 5. Checkmarks in Participant Place and Expedition Preference, plus Unit and OSM Sync (last) columns
        const headers = screen.getAllByRole('columnheader');
        expect(headers[5].textContent).toContain('Unit');
        expect(headers[6].textContent).toContain('OSM Sync');

        const zackCells = within(zackRow).getAllByRole('cell');
        // Columns: 0: Explorer Name, 1: Level, 2: Participant Place, 3: Expedition Preference, 4: Expedition Type, 5: Unit, 6: OSM Sync
        expect(zackCells[2].textContent?.trim()).toBe('✓');
        expect(zackCells[3].textContent?.trim()).toBe('✓');
        expect(zackCells[4].textContent?.trim()).toBe('Hillwalking');
        expect(zackCells[5].textContent?.trim()).toBe('BO-Kelso');
        expect(within(zackCells[6]).getByRole('img', { name: /Synced with OSM/i })).toBeDefined();

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
});
