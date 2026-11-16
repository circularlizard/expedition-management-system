import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import CohortsContinuityView from '../../resources/js/admin/expedition-board/CohortsContinuityView';
import { Cohort, MatrixEvent, MatrixExplorer } from '../../resources/js/admin/expedition-board/cohortTypes';

const mockPracticeEvents: MatrixEvent[] = [
  {
    id: 101,
    event_code: 'H-SP1',
    title: 'Silver Practice 1',
    level: 'silver',
    type: 'practice',
    transport: 'hillwalking',
    start_date: '2027-06-05',
    end_date: '2027-06-07',
    teams: [],
  },
];

const mockQualifierEvents: MatrixEvent[] = [
  {
    id: 201,
    event_code: 'H-SQ2',
    title: 'Silver Qualifier 2',
    level: 'silver',
    type: 'qualifying',
    transport: 'hillwalking',
    start_date: '2027-07-18',
    end_date: '2027-07-20',
    teams: [],
  },
];

const mockCohorts: Cohort[] = [
  {
    id: 11,
    name: 'Cohort Alpha',
    dofe_level: 'silver',
    transport_mode: 'foot',
    practice_expedition_id: null,
    qualifier_expedition_id: null,
    practice_team_post_id: null,
    qualifier_team_post_id: null,
    cohort_status: 'forming',
    notes: null,
    created_by: 1,
    created_at: '2026-11-16T00:00:00Z',
    updated_at: '2026-11-16T00:00:00Z',
    member_count: 2,
    members: [
      {
        id: 1,
        cohort_id: 11,
        expedition_signup_id: 501,
        scout_id: 1001,
        availability_override: null,
        member_status: 'active',
        notification_status: 'draft',
        notes: null,
        unit_id: 10,
        unit_name: 'Unit A',
        first_name: 'Alice',
        last_name: 'MacLeod',
        explorer_first_name: 'Alice',
        explorer_last_name: 'MacLeod',
        explorer_email: 'alice@example.com',
        parent_email: null,
        dofe_level: 'silver',
        expedition_preferences: null,
        additional_support_needs: null,
        asn_validated: null,
        first_aid_status: 'first_response',
        first_aid_expiry: '2028-01-01',
        first_aid_level: 'first_response',
        signup_status: 'pending',
      },
      {
        id: 2,
        cohort_id: 11,
        expedition_signup_id: 502,
        scout_id: 0,
        availability_override: null,
        member_status: 'active',
        notification_status: 'draft',
        notes: null,
        unit_id: 10,
        unit_name: 'Unit A',
        first_name: 'Ben',
        last_name: 'Fraser',
        explorer_first_name: 'Ben',
        explorer_last_name: 'Fraser',
        explorer_email: 'ben@example.com',
        parent_email: null,
        dofe_level: 'silver',
        expedition_preferences: null,
        additional_support_needs: null,
        asn_validated: null,
        first_aid_status: 'none',
        first_aid_expiry: null,
        first_aid_level: null,
        signup_status: 'pending',
      },
    ],
  },
];

const mockScheduleCandidates = [
  {
    expedition_id: 101,
    expedition_code: 'H-SP1',
    title: 'Silver Practice 1',
    expedition_date: '2027-06-05',
    stage: 'practice',
    available_count: 1,
    total_count: 2,
    unavailable_members: [
      {
        expedition_signup_id: 502,
        scout_id: 0,
        name: 'Ben Fraser',
      },
    ],
    first_aid_count: 1,
    asn_count: 0,
    size_valid: false,
  },
  {
    expedition_id: 201,
    expedition_code: 'H-SQ2',
    title: 'Silver Qualifier 2',
    expedition_date: '2027-07-18',
    stage: 'qualifier',
    available_count: 2,
    total_count: 2,
    unavailable_members: [],
    first_aid_count: 1,
    asn_count: 0,
    size_valid: false,
  },
];

describe('CohortsContinuityView — Partial-Availability Resolution', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('opens the inline Partial-Availability Resolution Drawer when a < 100% date is selected and submits chosen resolutions', async () => {
    const onRefreshAll = vi.fn();
    const onFeedback = vi.fn();

    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes('/cohorts/11/schedule') && (!init || !init.method || init.method === 'GET')) {
        return {
          ok: true,
          json: async () => mockScheduleCandidates,
        };
      }
      if (url.includes('/cohorts/11/schedule') && init?.method === 'POST') {
        return {
          ok: true,
          json: async () => ({ scheduled: true }),
        };
      }
      return { ok: true, json: async () => ({}) };
    });

    global.fetch = fetchMock as unknown as typeof fetch;

    const explorers: MatrixExplorer[] = [];

    render(
      <CohortsContinuityView
        level="silver"
        transport="hillwalking"
        rootUrl="https://example.com/wp-json/ems/v1"
        nonce="test-nonce"
        units={[]}
        cohorts={mockCohorts}
        practiceEvents={mockPracticeEvents}
        qualifierEvents={mockQualifierEvents}
        practiceExplorers={explorers}
        qualifierExplorers={explorers}
        onRefreshAll={onRefreshAll}
        onFeedback={onFeedback}
      />
    );

    // Wait for schedule candidates to load into the Practice dropdown
    const practiceSelect = await screen.findByLabelText(/Practice Expedition/i);
    await waitFor(() => {
      expect(screen.getByText(/H-SP1 \(5 Jun\) — 1\/2 Available/i)).toBeInTheDocument();
    });

    // Select the < 100% available Practice expedition
    fireEvent.change(practiceSelect, { target: { value: '101' } });

    // Inline Partial-Availability Resolution Drawer should expand automatically
    expect(await screen.findByText(/Partial-Availability Resolution/i)).toBeInTheDocument();
    expect(screen.getAllByText(/Ben Fraser/).length).toBeGreaterThanOrEqual(2);

    // Switch resolution radio to "Split to Alternate Date (Keep Team)"
    const splitRadio = screen.getByRole('radio', { name: /Split to Alternate Date \(Keep Team\)/i });
    fireEvent.click(splitRadio);

    // Click Confirm & Apply Schedule
    const applyBtn = screen.getByRole('button', { name: /Confirm & Apply Schedule/i });
    fireEvent.click(applyBtn);

    await waitFor(() => {
      const postCall = fetchMock.mock.calls.find(
        ([callUrl, callInit]) => callUrl.includes('/cohorts/11/schedule') && callInit?.method === 'POST'
      );
      expect(postCall).toBeTruthy();
      const body = JSON.parse(String(postCall![1]?.body));
      expect(body).toEqual({
        practice_expedition_id: 101,
        qualifier_expedition_id: null,
        member_resolutions: {
          502: 'split_date',
        },
      });
    });

    expect(onRefreshAll).toHaveBeenCalled();
  });
});
