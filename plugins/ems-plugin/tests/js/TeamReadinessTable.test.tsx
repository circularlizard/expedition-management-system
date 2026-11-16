import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TeamReadinessTable, TeamReadinessRecord } from '../../resources/js/shared/TeamReadinessTable';

const sampleRecords: TeamReadinessRecord[] = [
  {
    record_type: 'team',
    team_post_id: 301,
    team_code: 'H-SP1-1',
    team_title: 'Hill Practice Team 1',
    cohort_id: 11,
    cohort_name: 'Cohort Alpha',
    cohort_status: 'scheduled',
    dofe_level: 'silver',
    transport_mode: 'foot',
    stage: 'practice',
    expedition_id: 101,
    expedition_code: 'H-SP1',
    expedition_title: 'Silver Practice 1',
    expedition_date: '2027-06-05',
    member_count: 5,
    size_valid: true,
    offer_counts: {
      draft: 1,
      ready_to_notify: 0,
      notified: 2,
      re_notify_needed: 0,
      accepted: 2,
      queried: 0,
      declined: 0,
    },
    first_aid_count: 2,
    first_aid_valid: true,
    training_available: true,
    training_summary: {
      complete: 3,
      in_progress: 1,
      not_started: 0,
      unlinked_oidc: 1,
      unavailable: 0,
    },
    route_plan: {
      status: 'submitted',
      version: 2,
      cutoff_date: '2027-05-22',
      days_until_cutoff: 12,
      is_past_cutoff: false,
    },
    members: [
      {
        expedition_signup_id: 501,
        scout_id: 1001,
        wp_user_id: 42,
        name: 'Alice MacLeod',
        availability_override: 'matched',
        notification_status: 'accepted',
        first_aid_level: 'first_response',
        first_aid_expiry: '2028-01-15',
        first_aid_valid: true,
        training_status: 'complete',
        completed_modules: 6,
        total_modules: 6,
        last_completed_date: '2026-11-01',
      },
      {
        expedition_signup_id: 502,
        scout_id: 0,
        wp_user_id: null,
        name: 'Ben Fraser',
        availability_override: 'date_conflict_pending',
        notification_status: 'draft',
        first_aid_level: 'none',
        first_aid_expiry: null,
        first_aid_valid: false,
        training_status: 'unlinked_oidc',
        completed_modules: 0,
        total_modules: 6,
        last_completed_date: null,
      },
    ],
  },
];

describe('TeamReadinessTable', () => {
  it('renders readiness columns, expands member compliance drawer, and triggers Send Reminder in admin mode', () => {
    const onSendReminder = vi.fn();

    render(<TeamReadinessTable records={sampleRecords} onSendReminder={onSendReminder} />);

    // Columns & summary badges
    expect(screen.getByText('H-SP1-1')).toBeInTheDocument();
    expect(screen.getByText('H-SP1')).toBeInTheDocument();
    expect(screen.getByText(/5 members \(4–7\)/)).toBeInTheDocument();
    expect(screen.getByText('Rule of Two met')).toBeInTheDocument();
    expect(screen.getByText(/12d until 14-day cutoff/i)).toBeInTheDocument();

    // Send Reminder button visible in admin mode
    const reminderBtn = screen.getByRole('button', { name: /Send Reminder/i });
    fireEvent.click(reminderBtn);
    expect(onSendReminder).toHaveBeenCalledWith(sampleRecords[0]);

    // Expand member compliance drawer
    const expandBtn = screen.getByRole('button', { name: /Details/i });
    fireEvent.click(expandBtn);

    expect(screen.getByText('Alice MacLeod')).toBeInTheDocument();
    expect(screen.getByText('Ben Fraser')).toBeInTheDocument();
    expect(screen.getByText(/2028-01-15/)).toBeInTheDocument();
    expect(screen.getByText('6 / 6 modules')).toBeInTheDocument();
    expect(screen.getByText('Unlinked OIDC')).toBeInTheDocument();
  });

  it('hides Send Reminder and redacts admin-only labels when isLicScoped is true', () => {
    render(<TeamReadinessTable records={sampleRecords} isLicScoped={true} onSendReminder={vi.fn()} />);

    expect(screen.queryByRole('button', { name: /Send Reminder/i })).not.toBeInTheDocument();
    expect(screen.getByText(/Pending: 1/)).toBeInTheDocument();
    expect(screen.getByText(/Not Linked: 1/)).toBeInTheDocument();

    // Expand drawer and verify LIC-scoped redaction
    fireEvent.click(screen.getByRole('button', { name: /Details/i }));
    expect(screen.getByText('Not Linked')).toBeInTheDocument();
    expect(screen.queryByText('date_conflict_pending')).not.toBeInTheDocument();
  });
});
