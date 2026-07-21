import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import EventPlanningBoard from '../../resources/js/admin/expedition-board/EventPlanningBoard';

const mockEvents = [
  {
    id: 12,
    title: 'Hill Practice 1',
    event_code: 'H-SP1',
    type: 'practice',
    level: 'silver',
    start_date: '2026-06-28',
    end_date: '2026-06-30',
    available_count: 2,
    allocated_count: 1
  }
];

const mockAvailability = [
  {
    scout_id: 4001,
    first_name: 'Alice',
    last_name: 'MacLeod',
    unit_name: 'SMESU',
    allocated_event_code: null,
    allocated_team_code: null,
    team_preferences: 'Prefer Team A'
  },
  {
    scout_id: 4002,
    first_name: 'Bob',
    last_name: 'Smith',
    unit_name: 'Kelso',
    allocated_event_code: 'H-SP2',
    allocated_team_code: 'H-SP2-1',
    team_preferences: ''
  }
];

describe('EventPlanningBoard', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    global.fetch = vi.fn().mockImplementation((url) => {
      const urlStr = String(url);
      if (urlStr.includes('/planning-board/availability')) {
        return Promise.resolve({
          ok: true,
          json: async () => ({ explorers: mockAvailability, teams: [] })
        });
      }
      if (urlStr.includes('/planning-board/allocate')) {
        return Promise.resolve({
          ok: true,
          json: async () => ({ success: true })
        });
      }
      if (urlStr.includes('/planning-board')) {
        return Promise.resolve({
          ok: true,
          json: async () => mockEvents
        });
      }
      if (urlStr.includes('/teams')) {
        return Promise.resolve({
          ok: true,
          json: async () => [
            { ID: 201, ems_team_code: 'H-SP2-1' }
          ]
        });
      }
      return Promise.reject(new Error('Unknown url: ' + urlStr));
    });
  });

  it('renders event selector and fetches planning board events', async () => {
    render(<EventPlanningBoard />);

    expect(screen.getByText(/Select Event/i)).toBeInTheDocument();
    
    await waitFor(() => {
      expect(screen.getByText(/Hill Practice 1/)).toBeInTheDocument();
      expect(screen.getByText(/Available/)).toBeInTheDocument();
    });
  });

  it('clicking an event fetches availability and lists explorers', async () => {
    render(<EventPlanningBoard />);

    await waitFor(() => {
      expect(screen.getByText(/Hill Practice 1/)).toBeInTheDocument();
    });

    // Select event from dropdown
    const select = screen.getByRole('combobox', { name: /Select Event/i });
    fireEvent.change(select, { target: { value: 'H-SP1' } });

    await waitFor(() => {
      expect(screen.getAllByText(/Alice/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/MacLeod/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/Bob/).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/Smith/).length).toBeGreaterThan(0);
      expect(screen.getByText('Prefer Team A')).toBeInTheDocument();
    });
  });

  it('allows bulk allocation action execution', async () => {
    // Mock window.confirm
    const confirmSpy = vi.spyOn(window, 'confirm').mockImplementation(() => true);

    render(<EventPlanningBoard />);

    await waitFor(() => {
      expect(screen.getByText(/Hill Practice 1/)).toBeInTheDocument();
    });

    const select = screen.getByRole('combobox', { name: /Select Event/i });
    fireEvent.change(select, { target: { value: 'H-SP1' } });

    await waitFor(() => {
      expect(screen.getAllByText(/Alice/).length).toBeGreaterThan(0);
    });

    // Check Alice checkbox
    const checkbox = screen.getAllByRole('checkbox')[1]; // first is select-all, second is Alice
    fireEvent.click(checkbox);

    // Select Add to Unallocated
    const applyBtn = screen.getByRole('button', { name: /Apply Action/i });
    fireEvent.click(applyBtn);

    await waitFor(() => {
      // Find the fetch call to /allocate
      const allocateCall = (global.fetch as any).mock.calls.find((call: any) => 
        call[0].includes('/planning-board/allocate')
      );
      expect(allocateCall).toBeDefined();
      
      const options = allocateCall[1];
      expect(options.method).toBe('POST');
      
      const payload = JSON.parse(options.body);
      expect(payload).toEqual({
        scout_ids: [4001],
        event_code: 'H-SP1',
        mode: 'unallocated'
      });
    });

    confirmSpy.mockRestore();
  });

  it('renders without forbidden inline structural styles', async () => {
    const { container } = render(<EventPlanningBoard />);
    await waitFor(() => expect(screen.getByText(/Hill Practice 1/)).toBeInTheDocument());

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
