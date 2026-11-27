import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import CohortsContinuityView from './CohortsContinuityView';
import { MatrixEvent, MatrixExplorer } from './cohortTypes';

const practiceEvents: MatrixEvent[] = [
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

const qualifierEvents: MatrixEvent[] = [
  {
    id: 201,
    event_code: 'H-SQ3',
    title: 'Silver Qualifier 3',
    level: 'silver',
    type: 'qualifying',
    transport: 'hillwalking',
    start_date: '2027-07-18',
    end_date: '2027-07-20',
    teams: [],
  },
];

function makeTeam(id: number, name: string, stage: 'practice' | 'qualifier', members: any[]) {
  return {
    id,
    team_post_id: id,
    name,
    team_code: name,
    stage,
    expedition_id: stage === 'practice' ? 101 : 201,
    expedition_code: stage === 'practice' ? 'H-SP1' : 'H-SQ3',
    start_date: stage === 'practice' ? '2027-06-05' : '2027-07-18',
    members,
  };
}

function makeMember(signupId: number, firstName: string, lastName: string, unitName = 'Unit A') {
  return {
    id: signupId,
    expedition_signup_id: signupId,
    scout_id: 1000 + signupId,
    first_name: firstName,
    last_name: lastName,
    unit_name: unitName,
    notification_status: 'draft',
    offer_status: 'pending',
    first_aid_status: 'none',
    first_aid_level: 'none',
    expedition_preferences: null,
  };
}

const poolExplorers: MatrixExplorer[] = [
  {
    expedition_signup_id: 1,
    scout_id: 1001,
    first_name: 'Alice',
    last_name: 'Smith',
    unit_id: 1,
    unit_name: 'Unit A',
    first_aid_status: 'none',
    first_aid_expiry: null,
    additional_support_needs: '',
    asn_validated: null,
    team_preferences: '',
    available_events: ['H-SP1', 'H-SQ3'],
    allocated_team_id: 10,
    allocated_team_code: 'H-SP1-1',
    allocated_event_code: 'H-SP1',
    has_asn: false,
  },
  {
    expedition_signup_id: 2,
    scout_id: 1002,
    first_name: 'Ben',
    last_name: 'Jones',
    unit_id: 1,
    unit_name: 'Unit A',
    first_aid_status: 'none',
    first_aid_expiry: null,
    additional_support_needs: '',
    asn_validated: null,
    team_preferences: '',
    available_events: ['H-SP1', 'H-SQ3'],
    allocated_team_id: null,
    allocated_team_code: null,
    allocated_event_code: null,
    has_asn: false,
  },
];

describe('Team Builder Compact Grid, Paired Stages, and Collapsible Pool', () => {
  beforeEach(() => {
    try {
      localStorage.removeItem('ems_team_builder_view_density');
    } catch {
      // Ignore if localStorage unavailable
    }
  });

  it('defaults to Compact when 6+ teams exist, toggles to Detailed, and persists density', () => {
    const sixTeams = [1, 2, 3, 4, 5, 6].map((n) =>
      makeTeam(10 + n, `H-SP1-${n}`, 'practice', [makeMember(n, `Exp${n}`, 'Test')])
    );

    const { container } = render(
      <CohortsContinuityView
        level="silver"
        transport="hillwalking"
        rootUrl="/wp-json/ems/v1"
        nonce="test-nonce"
        units={[]}
        cohorts={sixTeams}
        practiceEvents={practiceEvents}
        qualifierEvents={qualifierEvents}
        practiceExplorers={poolExplorers}
        qualifierExplorers={[]}
        onRefreshAll={vi.fn()}
        onFeedback={vi.fn()}
      />
    );

    expect(container.querySelector('.ems-teams-compact-grid')).toBeInTheDocument();
    expect(container.querySelector('.ems-compact-member-row')).toBeInTheDocument();
    expect(container.querySelector('table.ems-table')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Detailed' }));
    expect(container.querySelector('.ems-teams-compact-grid')).not.toBeInTheDocument();
    expect(container.querySelector('table.ems-table')).toBeInTheDocument();
  });

  it('pairs Practice & Qualifier teams side-by-side when Pair linked stages is checked', () => {
    const pTeam = makeTeam(10, 'H-SP1-1', 'practice', [makeMember(1, 'Alice', 'Smith'), makeMember(2, 'Ben', 'Jones')]);
    const qTeam = makeTeam(20, 'H-SQ3-1', 'qualifier', [makeMember(1, 'Alice', 'Smith')]);

    const { container } = render(
      <CohortsContinuityView
        level="silver"
        transport="hillwalking"
        rootUrl="/wp-json/ems/v1"
        nonce="test-nonce"
        units={[]}
        cohorts={[pTeam, qTeam]}
        practiceEvents={practiceEvents}
        qualifierEvents={qualifierEvents}
        practiceExplorers={poolExplorers}
        qualifierExplorers={poolExplorers}
        onRefreshAll={vi.fn()}
        onFeedback={vi.fn()}
      />
    );

    fireEvent.click(screen.getByRole('checkbox', { name: /Pair linked stages/i }));
    expect(container.querySelector('.ems-paired-card')).toBeInTheDocument();
    expect(screen.getByText(/1 split-date member/i)).toBeInTheDocument();
    expect(screen.getByText('Split date')).toBeInTheDocument();
  });

  it('collapses and expands the Explorers Pool drawer and auto-collapses when empty', () => {
    const { container } = render(
      <CohortsContinuityView
        level="silver"
        transport="hillwalking"
        rootUrl="/wp-json/ems/v1"
        nonce="test-nonce"
        units={[]}
        cohorts={[makeTeam(10, 'H-SP1-1', 'practice', [makeMember(1, 'Alice', 'Smith')])]}
        practiceEvents={practiceEvents}
        qualifierEvents={qualifierEvents}
        practiceExplorers={poolExplorers}
        qualifierExplorers={[]}
        onRefreshAll={vi.fn()}
        onFeedback={vi.fn()}
      />
    );

    fireEvent.click(screen.getByRole('button', { name: 'Collapse Pool' }));
    expect(container.querySelector('.ems-team-builder-layout--collapsed')).toBeInTheDocument();
    expect(screen.getByText(/Pool \(\d+\)/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Expand Pool' }));
    expect(container.querySelector('.ems-team-builder-layout--collapsed')).not.toBeInTheDocument();

    // Auto-collapse when unallocated count is 0 under Hide already allocated
    const bothMembers = [makeMember(1, 'Alice', 'Smith'), makeMember(2, 'Ben', 'Jones')];
    const { container: c2 } = render(
      <CohortsContinuityView
        level="silver"
        transport="hillwalking"
        rootUrl="/wp-json/ems/v1"
        nonce="test-nonce"
        units={[]}
        cohorts={[
          makeTeam(10, 'H-SP1-1', 'practice', bothMembers),
          makeTeam(20, 'H-SQ3-1', 'qualifier', bothMembers),
        ]}
        practiceEvents={practiceEvents}
        qualifierEvents={qualifierEvents}
        practiceExplorers={poolExplorers}
        qualifierExplorers={poolExplorers}
        onRefreshAll={vi.fn()}
        onFeedback={vi.fn()}
      />
    );
    expect(c2.querySelector('.ems-team-builder-layout--collapsed')).toBeInTheDocument();
    expect(screen.getByText('Pool (0)')).toBeInTheDocument();
  });
});
