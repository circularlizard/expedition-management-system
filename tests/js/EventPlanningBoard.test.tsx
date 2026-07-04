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
    allocated_team_code: null
  },
  {
    scout_id: 4002,
    first_name: 'Bob',
    last_name: 'Smith',
    unit_name: 'Kelso',
    allocated_event_code: 'H-SP2',
    allocated_team_code: 'H-SP2-1'
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
          json: async () => mockAvailability
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

  it('renders level toggles and fetches planning board events', async () => {
    render(<EventPlanningBoard />);

    expect(screen.getByText(/LEVEL:/i)).toBeInTheDocument();
    
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

    // Click event card
    fireEvent.click(screen.getByText(/Hill Practice 1/));

    await waitFor(() => {
      expect(screen.getByText(/Alice MacLeod/)).toBeInTheDocument();
      expect(screen.getByText(/Bob Smith/)).toBeInTheDocument();
      expect(screen.getByText(/Allocated: H-SP2-1/)).toBeInTheDocument();
    });
  });

  it('allows bulk allocation action execution', async () => {
    // Mock window.confirm
    const confirmSpy = vi.spyOn(window, 'confirm').mockImplementation(() => true);

    render(<EventPlanningBoard />);

    await waitFor(() => {
      expect(screen.getByText(/Hill Practice 1/)).toBeInTheDocument();
    });

    fireEvent.click(screen.getByText(/Hill Practice 1/));

    await waitFor(() => {
      expect(screen.getByText(/Alice MacLeod/)).toBeInTheDocument();
    });

    // Check Alice checkbox
    const checkbox = screen.getAllByRole('checkbox')[1]; // first is select-all, second is Alice
    fireEvent.click(checkbox);

    // Select Add to Unallocated
    const applyBtn = screen.getByRole('button', { name: /Apply Action/i });
    fireEvent.click(applyBtn);

    await waitFor(() => {
      expect(global.fetch).toHaveBeenCalledWith(
        expect.stringContaining('/planning-board/allocate'),
        expect.objectContaining({
          method: 'POST',
          body: JSON.stringify({
            scout_ids: [4001],
            event_id: 12,
            allocation_mode: 'unallocated'
          })
        })
      );
    });

    confirmSpy.mockRestore();
  });
});
