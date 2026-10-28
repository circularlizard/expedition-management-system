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
  type: 'practice',
  level: 'silver',
  start_date: '2027-05-28',
  end_date: '2027-05-30',
  available_count: 2,
  allocated_count: 1
};

describe('EventPlanningBoard - Cohort Availability Matrix', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    global.fetch = vi.fn().mockImplementation((url) => {
      const urlStr = String(url);
      if (urlStr.includes('/planning-board/matrix')) {
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

  it('renders Layer 1 Scope Filters and Layer 2 Refinement Controls', async () => {
    render(<EventPlanningBoard />);

    // Layer 1 Scope Filters
    expect(screen.getByRole('group', { name: /Level Selector/i })).toBeInTheDocument();
    expect(screen.getByRole('group', { name: /Transport Mode Selector/i })).toBeInTheDocument();
    expect(screen.getByRole('group', { name: /Stage Selector/i })).toBeInTheDocument();

    // Layer 2 Refinement Controls
    expect(screen.getByRole('search', { name: /Cohort Refinement/i })).toBeInTheDocument();
    expect(screen.getByPlaceholderText(/Search explorer name or wishlist text/i)).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/Shawn Gray/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/Holly Parker/).length).toBeGreaterThan(0);
    });
  });

  it('renders Matrix columns for each event with date subheaders and wishlist text', async () => {
    render(<EventPlanningBoard />);

    await waitFor(() => {
      // Event column headers
      expect(screen.getAllByText('H-SP1').length).toBeGreaterThan(0);
      expect(screen.getAllByText('H-SP2').length).toBeGreaterThan(0);
      expect(screen.getByText('28 May')).toBeInTheDocument();
      expect(screen.getByText('4 Jun')).toBeInTheDocument();

      // Teammate Wishlists
      expect(screen.getByText('Shawn Gray, Holly Parker')).toBeInTheDocument();

      // Badges
      expect(screen.getByText(/Team 1/)).toBeInTheDocument();
      expect(screen.getAllByText(/Available \+/).length).toBeGreaterThan(0);
    });
  });

  it('filters explorers by free-text wishlist search', async () => {
    render(<EventPlanningBoard />);

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
    });

    const searchInput = screen.getByPlaceholderText(/Search explorer name or wishlist text/i);
    // Searching "Holly" should show Holly Parker AND Fraser Myles (who listed Holly Parker in his wishlist)
    fireEvent.change(searchInput, { target: { value: 'Holly' } });

    await waitFor(() => {
      expect(screen.getAllByText(/Holly Parker/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
      expect(screen.queryByText(/Asthma inhaler/)).not.toBeInTheDocument();
    });
  });

  it('filters explorers by unit', async () => {
    render(<EventPlanningBoard />);

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
    });

    const unitSelect = screen.getByLabelText(/Unit:/i);
    fireEvent.change(unitSelect, { target: { value: 'PE-Castle' } });

    await waitFor(() => {
      expect(screen.getByRole('checkbox', { name: /Select Holly Parker/i })).toBeInTheDocument();
      expect(screen.queryByRole('checkbox', { name: /Select Fraser Myles/i })).not.toBeInTheDocument();
      expect(screen.queryByRole('checkbox', { name: /Select Shawn Gray/i })).not.toBeInTheDocument();
    });
  });

  it('opens quick allocation popover when clicking Available cell badge and allocates', async () => {
    render(<EventPlanningBoard />);

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
    });

    // Find Available badge
    const availableBadges = screen.getAllByRole('button', { name: /Available \+/i });
    fireEvent.click(availableBadges[0]);

    // Popover opens
    await waitFor(() => {
      expect(screen.getByRole('dialog', { name: /Assign Explorer/i })).toBeInTheDocument();
      expect(screen.getByText(/Create New Team/i)).toBeInTheDocument();
    });

    // Click Create New Team
    const newTeamBtn = screen.getByText(/Create New Team/i);
    fireEvent.click(newTeamBtn);

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

  it('allows bulk allocation of selected explorers using sticky bottom bar', async () => {
    render(<EventPlanningBoard />);

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
    });

    // Check Shawn Gray
    const shawnCheckbox = screen.getByRole('checkbox', { name: /Select Shawn Gray/i });
    fireEvent.click(shawnCheckbox);

    // Bulk action bar appears
    await waitFor(() => {
      const bar = screen.getByRole('region', { name: /Bulk Allocation Bar/i });
      expect(bar).toBeInTheDocument();
      expect(bar).toHaveTextContent(/1 explorer selected/i);
    });

    const applyBtn = screen.getByRole('button', { name: /Apply Allocation/i });
    fireEvent.click(applyBtn);

    await waitFor(() => {
      const allocateCall = (global.fetch as any).mock.calls.find((call: any) =>
        call[0].includes('/planning-board/allocate')
      );
      expect(allocateCall).toBeDefined();
      const payload = JSON.parse(allocateCall[1].body);
      expect(payload.scout_ids).toContain(3002);
      expect(['H-SP1', 'H-SP2']).toContain(payload.event_code);
    });
  });

  it('leaves single event view functional when event prop is provided', async () => {
    render(<EventPlanningBoard event={mockSingleEvent as any} />);

    // Single event view displays the split panes with Available Explorers and Expedition Teams
    await waitFor(() => {
      expect(screen.getByText(/Available Explorers/i)).toBeInTheDocument();
      expect(screen.getByText(/Expedition Teams/i)).toBeInTheDocument();
    });
  });

  it('leaves columns blank for unavailable events and allows switching event via clickable badge in asserted columns', async () => {
    render(<EventPlanningBoard />);

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
    });

    // Fraser Myles is allocated to H-SP1, but also asserted availability for H-SP2.
    // In H-SP2 column, a switch button "In H-SP1 ⇄" should be rendered.
    const switchBtn = screen.getByRole('button', { name: /In H-SP1/i });
    expect(switchBtn).toBeInTheDocument();

    // Clicking switchBtn opens the popover for H-SP2 allowing direct switch
    fireEvent.click(switchBtn);
    const dialog = screen.getByRole('dialog', { name: /Assign Explorer/i });
    expect(dialog).toBeInTheDocument();
    expect(within(dialog).getByText(/H-SP2/i)).toBeInTheDocument();
    expect(dialog).toHaveTextContent(/Switching from H-SP1/i);
  });

  it('renders without forbidden inline structural styles', async () => {
    const { container } = render(<EventPlanningBoard />);

    await waitFor(() => {
      expect(screen.getAllByText(/Fraser Myles/).length).toBeGreaterThan(0);
    });

    const elementsWithStyle = container.querySelectorAll('[style]');
    elementsWithStyle.forEach((el) => {
      const styleAttr = el.getAttribute('style') || '';
      const forbiddenStyles = [
        'display', 'margin', 'padding', 'flex', 'grid', 'position',
        'left', 'top', 'right', 'bottom', 'gap', 'border-radius', 'border:'
      ];
      forbiddenStyles.forEach((prop) => {
        expect(styleAttr).not.toContain(prop);
      });
    });
  });
});
