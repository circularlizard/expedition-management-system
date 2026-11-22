import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import EventPlanningBoard from '../../resources/js/admin/expedition-board/EventPlanningBoard';

const mockMatrixData = {
  events: [
    {
      id: 101,
      event_code: 'H-SP1',
      title: 'Silver Practice 1',
      level: 'silver',
      type: 'practice',
      transport: 'hillwalking',
      start_date: '2027-05-28',
      end_date: '2027-05-30',
      teams: [
        {
          id: 201,
          team_code: 'Team 1',
          member_count: 1,
          scout_ids: [3001]
        },
        {
          id: 202,
          team_code: 'UNALLOCATED',
          member_count: 0,
          scout_ids: []
        }
      ]
    },
    {
      id: 102,
      event_code: 'H-SP2',
      title: 'Silver Practice 2',
      level: 'silver',
      type: 'practice',
      transport: 'hillwalking',
      start_date: '2027-06-04',
      end_date: '2027-06-06',
      teams: [
        {
          id: 203,
          team_code: 'UNALLOCATED',
          member_count: 0,
          scout_ids: []
        }
      ]
    }
  ],
  explorers: [
    {
      expedition_signup_id: 5001,
      scout_id: 3001,
      first_name: 'Fraser',
      last_name: 'Myles',
      unit_name: 'BO-Kelso',
      team_preferences: 'Shawn Gray, Holly Parker',
      available_events: ['H-SP1', 'H-SP2'],
      allocated_event_code: 'H-SP1',
      allocated_team_code: 'Team 1',
      allocated_team_id: 201,
      has_asn: false,
      additional_support_needs: '',
      first_aid_status: 'first-aid-yes'
    },
    {
      expedition_signup_id: 5002,
      scout_id: 3002,
      first_name: 'Shawn',
      last_name: 'Gray',
      unit_name: 'BO-Kelso',
      team_preferences: 'Fraser Myles',
      available_events: ['H-SP1'],
      allocated_event_code: null,
      allocated_team_code: null,
      allocated_team_id: null,
      has_asn: true,
      additional_support_needs: 'Asthma inhaler',
      first_aid_status: 'none'
    },
    {
      expedition_signup_id: 5003,
      scout_id: 3003,
      first_name: 'Holly',
      last_name: 'Parker',
      unit_name: 'PE-Castle',
      team_preferences: 'Fraser Myles, Shawn Gray',
      available_events: ['H-SP2'],
      allocated_event_code: null,
      allocated_team_code: null,
      allocated_team_id: null,
      has_asn: false,
      additional_support_needs: '',
      first_aid_status: 'none'
    }
  ]
};

const mockSingleEvent = {
  id: 101,
  event_code: 'H-SP1',
  title: 'Silver Practice 1',
  level: 'silver',
  type: 'practice',
  transport: 'hillwalking',
  start_date: '2027-05-28',
  end_date: '2027-05-30',
  available_count: 2,
  allocated_count: 1
};

async function renderAndSwitchToStageMatrix() {
  render(<EventPlanningBoard />);
  const stageMatrixBtn = await screen.findByRole('button', { name: /2\. Date Grid/i });
  fireEvent.click(stageMatrixBtn);
  await waitFor(() => {
    expect(screen.getByRole('group', { name: /Stage Matrix Mode/i })).toBeInTheDocument();
  });
}

describe('EventPlanningBoard - Cohort & Stage Matrix Planning', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    (global as any).window.emsExpeditionBoard = {
      root_url: '/wp-json/ems/v1',
      nonce: 'dummy-nonce',
      units: [
        { unit_id: 1, name: 'BO-Kelso', district: 'Borders' },
        { unit_id: 2, name: 'PE-Castle', district: 'Pentland' }
      ]
    };

    global.fetch = vi.fn().mockImplementation((url) => {
      const urlStr = String(url);
      if (urlStr.includes('/cohorts')) {
        return Promise.resolve({
          ok: true,
          json: async () => []
        });
      }
      if (urlStr.includes('/planning-board/matrix')) {
        if (urlStr.includes('type=qualifying')) {
          return Promise.resolve({
            ok: true,
            json: async () => ({ events: [], explorers: [] })
          });
        }
        return Promise.resolve({
          ok: true,
          json: async () => mockMatrixData
        });
      }
      if (urlStr.includes('/planning-board/allocate')) {
        return Promise.resolve({
          ok: true,
          json: async () => ({ success: true, allocated: 1 })
        });
      }
      if (urlStr.includes('/planning-board/availability/')) {
        return Promise.resolve({
          ok: true,
          json: async () => ({
            explorers: mockMatrixData.explorers,
            teams: mockMatrixData.events[0].teams
          })
        });
      }
      if (urlStr.includes('/planning-board/synced-explorers')) {
        return Promise.resolve({
          ok: true,
          json: async () => []
        });
      }
      return Promise.reject(new Error('Unknown url: ' + urlStr));
    });
  });

  it('renders Layer 1 Scope Filters, VIEW switcher, and Notify Teams button', async () => {
    render(<EventPlanningBoard />);

    expect(screen.getByRole('group', { name: /Level Selector/i })).toBeInTheDocument();
    expect(screen.getByRole('group', { name: /Transport Mode Selector/i })).toBeInTheDocument();
    expect(screen.getByRole('group', { name: /Planning Sub-View/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Notify Teams/i })).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/Shawn Gray/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/Holly Parker/).length).toBeGreaterThan(0);
    });
  });

  it('renders Stage Matrix columns with Practice super-headers, date subheaders, and wishlist text', async () => {
    await renderAndSwitchToStageMatrix();

    await waitFor(() => {
      expect(screen.getByText('Practice Expeditions')).toBeInTheDocument();
      expect(screen.getAllByText('H-SP1').length).toBeGreaterThan(0);
      expect(screen.getAllByText('H-SP2').length).toBeGreaterThan(0);
      expect(screen.getByText('28 May')).toBeInTheDocument();
      expect(screen.getByText('4 Jun')).toBeInTheDocument();
      expect(screen.getByText('Shawn Gray, Holly Parker')).toBeInTheDocument();
      expect(screen.getByText(/Team 1/)).toBeInTheDocument();
      expect(screen.getAllByText(/Available \+/).length).toBeGreaterThan(0);
    });
  });

  it('filters explorers by free-text wishlist search in Stage Matrix', async () => {
    await renderAndSwitchToStageMatrix();

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
    });

    const searchInput = screen.getByPlaceholderText(/Search explorer, team, or wishlist/i);
    fireEvent.change(searchInput, { target: { value: 'Holly' } });

    await waitFor(() => {
      expect(screen.getAllByText(/Holly Parker/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
      expect(screen.queryByText(/Asthma inhaler/)).not.toBeInTheDocument();
    });
  });

  it('filters explorers by unit in Stage Matrix', async () => {
    await renderAndSwitchToStageMatrix();

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
    });

    const unitSelect = screen.getByLabelText(/Unit:/i);
    fireEvent.change(unitSelect, { target: { value: 'PE-Castle' } });

    await waitFor(() => {
      expect(screen.getByText('Holly Parker', { selector: '.ems-explorer-fullname' })).toBeInTheDocument();
      expect(screen.queryByText('Fraser Myles', { selector: '.ems-explorer-fullname' })).not.toBeInTheDocument();
      expect(screen.queryByText('Shawn Gray', { selector: '.ems-explorer-fullname' })).not.toBeInTheDocument();
    });
  });

  it('opens quick allocation popover when clicking Available cell badge and allocates', async () => {
    await renderAndSwitchToStageMatrix();

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
    });

    const availableBadges = screen.getAllByRole('button', { name: /Available \+/i });
    fireEvent.click(availableBadges[0]);

    await waitFor(() => {
      expect(screen.getByRole('dialog', { name: /Assign Explorer/i })).toBeInTheDocument();
      expect(screen.getByText(/Create New Team/i)).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText(/Create New Team/i));

    await waitFor(() => {
      const allocateCall = (global.fetch as any).mock.calls.find((call: any) =>
        call[0].includes('/planning-board/allocate')
      );
      expect(allocateCall).toBeDefined();
      const payload = JSON.parse(allocateCall[1].body);
      expect(payload.mode).toBe('new_team');
      expect(['H-SP1', 'H-SP2']).toContain(payload.event_code);
    });
  });

  it('leaves single event view functional when event prop is provided', async () => {
    render(<EventPlanningBoard event={mockSingleEvent as any} />);

    await waitFor(() => {
      expect(screen.getByText(/Available Explorers/i)).toBeInTheDocument();
      expect(screen.getByText(/Expedition Teams/i)).toBeInTheDocument();
    });
  });

  it('displays amber pill for unreviewed ASN, red pill for action needed, and green pill for no action needed', async () => {
    const customMatrix = {
      events: mockMatrixData.events,
      explorers: [
        {
          expedition_signup_id: 6001,
          scout_id: 4001,
          first_name: 'Alice',
          last_name: 'Smith',
          unit_name: 'Kelso',
          team_preferences: '',
          available_events: ['H-SP1'],
          allocated_event_code: null,
          allocated_team_code: null,
          allocated_team_id: null,
          has_asn: true,
          additional_support_needs: 'Requires inhaler',
          asn_validated: null,
          first_aid_status: 'none'
        },
        {
          expedition_signup_id: 6002,
          scout_id: 4002,
          first_name: 'Bob',
          last_name: 'Jones',
          unit_name: 'Selkirk',
          team_preferences: '',
          available_events: ['H-SP1'],
          allocated_event_code: null,
          allocated_team_code: null,
          allocated_team_id: null,
          has_asn: true,
          additional_support_needs: 'No',
          asn_validated: 0,
          first_aid_status: 'none'
        },
        {
          expedition_signup_id: 6003,
          scout_id: 4003,
          first_name: 'Charlie',
          last_name: 'Brown',
          unit_name: 'Peebles',
          team_preferences: '',
          available_events: ['H-SP1'],
          allocated_event_code: null,
          allocated_team_code: null,
          allocated_team_id: null,
          has_asn: true,
          additional_support_needs: 'Mild asthma',
          asn_validated: 1,
          first_aid_status: 'none'
        },
        {
          expedition_signup_id: 6004,
          scout_id: 4004,
          first_name: 'David',
          last_name: 'Clark',
          unit_name: 'Hawick',
          team_preferences: '',
          available_events: ['H-SP1'],
          allocated_event_code: null,
          allocated_team_code: null,
          allocated_team_id: null,
          has_asn: false,
          additional_support_needs: '',
          asn_validated: null,
          first_aid_status: 'none'
        }
      ]
    };

    (global.fetch as any).mockImplementation((url: string) => {
      if (url.includes('/cohorts')) {
        return Promise.resolve({ ok: true, json: async () => [] });
      }
      if (url.includes('/planning-board/matrix')) {
        if (url.includes('type=qualifying')) {
          return Promise.resolve({ ok: true, json: async () => ({ events: [], explorers: [] }) });
        }
        return Promise.resolve({ ok: true, json: async () => customMatrix });
      }
      return Promise.resolve({ ok: true, json: async () => ({}) });
    });

    await renderAndSwitchToStageMatrix();

    await waitFor(() => {
      expect(screen.getAllByText(/Alice Smith/).length).toBeGreaterThan(0);
    });

    const aliceRow = screen.getByText('Alice Smith').closest('tr')!;
    expect(within(aliceRow).getByText('? ASN')).toHaveClass('ems-matrix-tag--asn-pending');

    const charlieRow = screen.getByText('Charlie Brown').closest('tr')!;
    expect(within(charlieRow).getByText('! ASN')).toHaveClass('ems-matrix-tag--asn-action');

    const bobRow = screen.getByText('Bob Jones').closest('tr')!;
    expect(within(bobRow).getByText('✓ ASN')).toHaveClass('ems-matrix-tag--asn-no-action');

    const davidRow = screen.getByText('David Clark').closest('tr')!;
    expect(within(davidRow).queryByText(/ASN/i)).not.toBeInTheDocument();
  });

  it('clicking explorer name opens leader validation dialogue and updates ASN validation status', async () => {
    let currentAsnValidated: number | null = null;
    const explorerAlice = () => ({
      expedition_signup_id: 6001,
      scout_id: 4001,
      first_name: 'Alice',
      last_name: 'Smith',
      unit_name: 'Kelso',
      team_preferences: '',
      available_events: ['H-SP1'],
      allocated_event_code: null,
      allocated_team_code: null,
      allocated_team_id: null,
      has_asn: true,
      additional_support_needs: 'Requires inhaler',
      asn_validated: currentAsnValidated,
      first_aid_status: 'none'
    });

    (global.fetch as any).mockImplementation((url: string, opts?: any) => {
      if (url.includes('/cohorts')) {
        return Promise.resolve({ ok: true, json: async () => [] });
      }
      if (url.includes('/planning-board/matrix')) {
        if (url.includes('type=qualifying')) {
          return Promise.resolve({ ok: true, json: async () => ({ events: [], explorers: [] }) });
        }
        return Promise.resolve({
          ok: true,
          json: async () => ({ events: mockMatrixData.events, explorers: [explorerAlice()] })
        });
      }
      if (url.includes('/planning-board/explorers/4001/asn-validation') && opts?.method === 'POST') {
        currentAsnValidated = 1;
        return Promise.resolve({
          ok: true,
          json: async () => ({ scout_id: 4001, asn_validated: 1, updated: true })
        });
      }
      return Promise.resolve({ ok: true, json: async () => ({}) });
    });

    await renderAndSwitchToStageMatrix();

    await waitFor(() => {
      expect(screen.getByText('Alice Smith')).toBeInTheDocument();
    });

    const aliceRow = screen.getByText('Alice Smith').closest('tr')!;
    expect(within(aliceRow).getByText('? ASN')).toHaveClass('ems-matrix-tag--asn-pending');

    fireEvent.click(screen.getByText('Alice Smith'));

    const dialog = await screen.findByRole('dialog', { name: /ASN Leader Validation/i });
    expect(within(dialog).getByText('Requires inhaler')).toBeInTheDocument();

    fireEvent.click(within(dialog).getByRole('button', { name: /^action needed$/i }));

    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: /ASN Leader Validation/i })).not.toBeInTheDocument();
      const updatedRow = screen.getByText('Alice Smith').closest('tr')!;
      expect(within(updatedRow).getByText('! ASN')).toHaveClass('ems-matrix-tag--asn-action');
    });
  });

  it('filters explorers by district and ASN in Stage Matrix', async () => {
    await renderAndSwitchToStageMatrix();

    await waitFor(() => {
      expect(screen.getByText('Fraser Myles', { selector: '.ems-explorer-fullname' })).toBeInTheDocument();
      expect(screen.getByText('Holly Parker', { selector: '.ems-explorer-fullname' })).toBeInTheDocument();
    });

    const districtSelect = screen.getByLabelText(/District:/i);
    fireEvent.change(districtSelect, { target: { value: 'Borders' } });

    await waitFor(() => {
      expect(screen.getByText('Fraser Myles', { selector: '.ems-explorer-fullname' })).toBeInTheDocument();
      expect(screen.getByText('Shawn Gray', { selector: '.ems-explorer-fullname' })).toBeInTheDocument();
      expect(screen.queryByText('Holly Parker', { selector: '.ems-explorer-fullname' })).not.toBeInTheDocument();
    });

    const asnSelect = screen.getByLabelText(/ASN:/i);
    fireEvent.change(asnSelect, { target: { value: 'has_asn' } });

    await waitFor(() => {
      expect(screen.getByText('Shawn Gray', { selector: '.ems-explorer-fullname' })).toBeInTheDocument();
      expect(screen.queryByText('Fraser Myles', { selector: '.ems-explorer-fullname' })).not.toBeInTheDocument();
    });
  });
});
