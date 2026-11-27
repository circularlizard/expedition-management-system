import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { AutoProposeTeamsModal } from './AutoProposeTeamsModal';
import { TeamScopeBar } from './TeamScopeBar';
import type { PreferenceQueueResponseDTO, SolverProposalDTO } from '@seee/contracts';

const mockNeedsReviewQueue: PreferenceQueueResponseDTO = {
  items: [
    {
      source_signup_id: 101,
      explorer_name: 'Alice MacLeod',
      unit_name: 'Lauderdale ESU',
      dofe_level: 'silver',
      transport_mode: 'foot',
      wishlist_raw: 'John Smith, not Bob Brown, Gold Friend',
      wishlist_hash: 'hash101',
      tokens: [
        {
          id: 1,
          source_signup_id: 101,
          target_signup_id: null,
          wishlist_hash: 'hash101',
          raw_token: 'John Smith',
          unit_hint: null,
          preference_type: 'prefer',
          match_status: 'ambiguous',
          confidence_score: 70,
          flag_negation: false,
          candidates: [
            {
              signup_id: 201,
              explorer_name: 'John Smith',
              unit_name: 'Lauderdale ESU',
              dofe_level: 'silver',
              transport_mode: 'foot',
              shared_code_count: 3,
            },
            {
              signup_id: 202,
              explorer_name: 'John Smith',
              unit_name: 'Pentland ESU',
              dofe_level: 'silver',
              transport_mode: 'foot',
              shared_code_count: 1,
            },
          ],
        },
        {
          id: 2,
          source_signup_id: 101,
          target_signup_id: null,
          wishlist_hash: 'hash101',
          raw_token: 'Bob Brown',
          unit_hint: null,
          preference_type: 'prefer',
          match_status: 'not_found',
          confidence_score: 0,
          flag_negation: true,
          candidates: [],
        },
        {
          id: 3,
          source_signup_id: 101,
          target_signup_id: null,
          wishlist_hash: 'hash101',
          raw_token: 'Gold Friend',
          unit_hint: null,
          preference_type: 'prefer',
          match_status: 'cross_bucket',
          confidence_score: 90,
          flag_negation: false,
          candidates: [
            {
              signup_id: 301,
              explorer_name: 'Gavin Reid',
              unit_name: 'Cramond ESU',
              dofe_level: 'gold',
              transport_mode: 'foot',
              shared_code_count: 0,
            },
          ],
        },
      ],
    },
  ],
  summary: {
    total: 3,
    needs_review: 1,
    auto_matched: 2,
    confirmed: 0,
    ignored: 0,
  },
};

const mockAllQueue: PreferenceQueueResponseDTO = {
  ...mockNeedsReviewQueue,
  items: [
    ...mockNeedsReviewQueue.items,
    {
      source_signup_id: 102,
      explorer_name: 'Clara Fraser',
      unit_name: 'Pentland ESU',
      dofe_level: 'silver',
      transport_mode: 'foot',
      wishlist_raw: 'Lucy Hann',
      wishlist_hash: 'hash102',
      tokens: [
        {
          id: 4,
          source_signup_id: 102,
          target_signup_id: 205,
          wishlist_hash: 'hash102',
          raw_token: 'Lucy Hann',
          unit_hint: null,
          preference_type: 'prefer',
          match_status: 'auto_matched',
          confidence_score: 98,
          flag_negation: false,
          candidates: [],
          resolved_target_name: 'Lucy Hann',
          resolved_target_unit: 'Lauderdale ESU',
        },
      ],
    },
  ],
};

const mockProposal: SolverProposalDTO = {
  scope: 'bucket',
  buckets_solved: [{ dofe_level: 'silver', transport_mode: 'foot' }],
  proposed_teams: [
    {
      temp_id: 'tmp-1',
      team_name: 'H-SP1-1',
      dofe_level: 'silver',
      transport_mode: 'foot',
      practice_expedition_id: 10,
      practice_code: 'H-SP1',
      qualifier_expedition_id: 20,
      qualifier_code: 'H-SQ1',
      friend_match_count: 2,
      has_first_aid: true,
      score: 215,
      members: [
        {
          expedition_signup_id: 101,
          scout_id: 1001,
          name: 'Alice MacLeod',
          unit_name: 'Lauderdale ESU',
          has_first_aid: true,
          practice_available: true,
          qualifier_available: true,
          resolution_status: 'ok',
        },
        {
          expedition_signup_id: 201,
          scout_id: 1002,
          name: 'John Smith',
          unit_name: 'Lauderdale ESU',
          has_first_aid: false,
          practice_available: true,
          qualifier_available: true,
          resolution_status: 'ok',
        },
      ],
    },
  ],
  expedition_capacity: [
    {
      expedition_id: 10,
      event_code: 'H-SP1',
      title: 'Silver Practice 1',
      stage: 'practice',
      locked_teams: 2,
      proposed_teams: 1,
      total_teams: 3,
      max_teams: 4,
    },
  ],
  compromises: {
    date_conflict_pending: [
      {
        expedition_signup_id: 202,
        explorer_name: 'Ben Fraser',
        team_name: 'H-SP1-2',
        stage: 'qualifier',
        event_code: 'H-SQ1',
      },
    ],
    split_date: [
      {
        expedition_signup_id: 203,
        explorer_name: 'Cara Watt',
        practice_team: 'H-SP1-2',
        qualifier_team: 'H-SQ2-1',
      },
    ],
    unfulfilled_mutual_pairs: [
      {
        source_signup_id: 101,
        source_name: 'Alice MacLeod',
        target_signup_id: 205,
        target_name: 'Lucy Hann',
      },
    ],
  },
  warnings: ['Code H-SP9 is not linked to an active expedition.'],
  stats: {
    total_explorers: 2,
    total_teams: 1,
    mutual_pairs_fulfilled: 1,
    mutual_pairs_total: 2,
    first_aid_teams_covered: 1,
  },
};

describe('AutoProposeTeamsModal', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    (window as any).emsExpeditionBoard = {
      root_url: 'https://example.com/wp-json/ems/v1',
      nonce: 'test-nonce',
    };
  });

  it('runs parse + needs_review queue, disambiguates radio candidates, overrides via search, adds teammate, and saves via PUT', async () => {
    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes('/preferences/parse')) {
        return { ok: true, json: async () => ({ parsed: 3 }) };
      }
      if (url.includes('/preferences/queue') && url.includes('filter=all')) {
        return { ok: true, json: async () => mockAllQueue };
      }
      if (url.includes('/preferences/queue')) {
        return { ok: true, json: async () => mockNeedsReviewQueue };
      }
      if (url.includes('/preferences/candidates')) {
        return {
          ok: true,
          json: async () => ({
            candidates: [
              {
                signup_id: 204,
                explorer_name: 'Robert Brown',
                unit_name: 'Lauderdale ESU',
                dofe_level: 'silver',
                transport_mode: 'foot',
                shared_code_count: 2,
              },
            ],
          }),
        };
      }
      if (url.includes('/preferences/101') && init?.method === 'PUT') {
        return { ok: true, json: async () => ({ saved: true }) };
      }
      return { ok: false, status: 404, json: async () => ({}) };
    });

    global.fetch = fetchMock as unknown as typeof fetch;

    render(
      <AutoProposeTeamsModal
        isOpen={true}
        onClose={vi.fn()}
        level="silver"
        transport="hillwalking"
        onRefreshAll={vi.fn()}
      />
    );

    // Step 1 loads parse + queue
    expect(await screen.findByText('Alice MacLeod')).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledWith(
      'https://example.com/wp-json/ems/v1/preferences/parse',
      expect.objectContaining({ method: 'POST' })
    );

    // Ambiguous radio candidates show name, unit, and shared code count
    expect(screen.getByText(/Lauderdale ESU · 3 shared expedition code\(s\)/i)).toBeInTheDocument();
    expect(screen.getByText(/Pentland ESU · 1 shared expedition code\(s\)/i)).toBeInTheDocument();

    // Cross-bucket & not_found notices
    expect(screen.getByText(/Cross-bucket match: Gavin Reid is signed up for Gold Hillwalking/i)).toBeInTheDocument();
    expect(screen.getByText(/No matching sign-up found in this bucket/i)).toBeInTheDocument();

    // Select first ambiguous radio candidate
    const radios = screen.getAllByRole('radio');
    fireEvent.click(radios[0]);

    // Toggle Prefer / Avoid on the negation-flagged token (Bob Brown)
    const avoidButtons = screen.getAllByRole('button', { name: 'Avoid' });
    fireEvent.click(avoidButtons[1]);

    // Search & override for Bob Brown
    const searchOverrideBtns = screen.getAllByRole('button', { name: /Search & override/i });
    fireEvent.click(searchOverrideBtns[1]);
    const overrideInput = await screen.findByLabelText(/Search override for Bob Brown/i);
    fireEvent.change(overrideInput, { target: { value: 'Robert' } });
    const candBtn = await screen.findByRole('button', { name: /Robert Brown \(Lauderdale ESU\)/i });
    fireEvent.click(candBtn);

    // + Add teammate for prose-only note
    fireEvent.click(screen.getByRole('button', { name: /\+ Add teammate/i }));
    const addInput = await screen.findByLabelText(/Add teammate search for Alice MacLeod/i);
    fireEvent.change(addInput, { target: { value: 'Robert' } });
    const addCandBtn = await screen.findByRole('button', { name: /Robert Brown \(Lauderdale ESU\)/i });
    fireEvent.click(addCandBtn);

    // Toggle Show All Explorers
    fireEvent.click(screen.getByRole('checkbox', { name: /Show All Explorers/i }));
    expect(await screen.findByText('Clara Fraser')).toBeInTheDocument();

    // Save & Proceed to Step 2
    fireEvent.click(screen.getByRole('button', { name: /Save & Proceed to Step 2/i }));

    await waitFor(() => {
      const putCall = fetchMock.mock.calls.find(
        ([u, i]) => typeof u === 'string' && u.includes('/preferences/101') && i?.method === 'PUT'
      );
      expect(putCall).toBeDefined();
      const body = JSON.parse(String(putCall?.[1]?.body));
      expect(body.decisions).toEqual(
        expect.arrayContaining([
          expect.objectContaining({ id: 1, target_signup_id: 201, match_status: 'confirmed', preference_type: 'prefer' }),
          expect.objectContaining({ id: 2, target_signup_id: 204, match_status: 'confirmed', preference_type: 'avoid' }),
          expect.objectContaining({ target_signup_id: 204, match_status: 'confirmed', raw_token: 'Robert Brown' }),
        ])
      );
    });

    expect(await screen.findByText(/Step 2 of 2: Propose & Load Draft Teams/i)).toBeInTheDocument();
  });

  it('supports jumping straight to Step 2 when zero flagged items remain, generating proposal, and loading draft teams', async () => {
    const emptyQueue: PreferenceQueueResponseDTO = {
      items: [],
      summary: { total: 2, needs_review: 0, auto_matched: 2, confirmed: 0, ignored: 0 },
    };

    const onRefreshAll = vi.fn(async () => {});
    const onFeedback = vi.fn();
    const onClose = vi.fn();

    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes('/preferences/parse')) {
        return { ok: true, json: async () => ({ parsed: 2 }) };
      }
      if (url.includes('/preferences/queue')) {
        return { ok: true, json: async () => emptyQueue };
      }
      if (url.includes('/teams/solver/propose')) {
        return { ok: true, json: async () => mockProposal };
      }
      if (url.includes('/teams/solver/apply')) {
        return {
          ok: true,
          json: async () => ({
            created_teams: 1,
            created_members: 2,
            cleared_draft_teams: 1,
            preserved_notified_teams: 0,
          }),
        };
      }
      return { ok: false, status: 404, json: async () => ({}) };
    });

    global.fetch = fetchMock as unknown as typeof fetch;

    render(
      <AutoProposeTeamsModal
        isOpen={true}
        onClose={onClose}
        level="silver"
        transport="hillwalking"
        onRefreshAll={onRefreshAll}
        onFeedback={onFeedback}
      />
    );

    // Zero flagged items -> Skip to Step 2 available
    const skipBtn = await screen.findByRole('button', { name: /Skip to Step 2/i });
    fireEvent.click(skipBtn);

    // Step 2: Scope selector & default weights
    expect(screen.getByLabelText(/Avoid exclusion/i)).toHaveValue(-500);
    expect(screen.getByLabelText(/Cross-stage continuity/i)).toHaveValue(100);
    expect(screen.getByLabelText(/First Aid presence/i)).toHaveValue(5);

    fireEvent.click(screen.getByRole('radio', { name: /Full Season \(All Buckets\)/i }));
    fireEvent.change(screen.getByLabelText(/Mutual wishlist pair/i), { target: { value: '40' } });

    fireEvent.click(screen.getByRole('button', { name: /Generate Proposal/i }));

    // Preview renders team name, capacity, friend matches, and compromises
    expect(await screen.findByText('H-SP1-1')).toBeInTheDocument();
    expect(screen.getByText('3/4 teams on H-SP1')).toBeInTheDocument();
    expect(screen.getByText(/2 friend matches/i)).toBeInTheDocument();
    expect(screen.getByText(/Ben Fraser \(H-SP1-2 · qualifier H-SQ1\)/i)).toBeInTheDocument();
    expect(screen.getByText(/Cara Watt \(H-SP1-2 \/ H-SQ2-1\)/i)).toBeInTheDocument();
    expect(screen.getByText(/Alice MacLeod ↔ Lucy Hann/i)).toBeInTheDocument();

    // Check replace existing drafts & load
    fireEvent.click(screen.getByRole('checkbox', { name: /Replace existing draft teams in scope/i }));
    fireEvent.click(screen.getByRole('button', { name: /Load as Draft Teams/i }));

    await waitFor(() => {
      const applyCall = fetchMock.mock.calls.find(
        ([u, i]) => typeof u === 'string' && u.includes('/teams/solver/apply') && i?.method === 'POST'
      );
      expect(applyCall).toBeDefined();
      const body = JSON.parse(String(applyCall?.[1]?.body));
      expect(body.replace_existing_drafts).toBe(true);
      expect(body.proposed_teams).toHaveLength(1);
    });

    expect(onRefreshAll).toHaveBeenCalledTimes(1);
    expect(onFeedback).toHaveBeenCalledWith(
      expect.objectContaining({
        ok: true,
        msg: expect.stringContaining('Loaded 1 draft team(s)'),
      })
    );
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('renders unit_preference badge, updates explorer gender via PUT /preferences/genders, and shows weight explanations & gender balance in Step 2', async () => {
    const unitPrefQueue: PreferenceQueueResponseDTO = {
      items: [
        {
          source_signup_id: 501,
          explorer_name: 'Sophie Kerr',
          unit_name: 'Lauderdale ESU',
          dofe_level: 'bronze',
          transport_mode: 'foot',
          gender: 'female',
          wishlist_raw: 'Anyone from Lauderdale ESU',
          wishlist_hash: 'hash501',
          tokens: [
            {
              id: 51,
              source_signup_id: 501,
              target_signup_id: null,
              wishlist_hash: 'hash501',
              raw_token: 'Lauderdale ESU',
              unit_hint: 'Lauderdale ESU',
              preference_type: 'prefer',
              match_status: 'unit_preference',
              confidence_score: 95,
              flag_negation: false,
              candidates: [],
            },
          ],
        },
      ],
      summary: { total: 1, needs_review: 0, auto_matched: 1, confirmed: 0, ignored: 0 },
    };

    const genderProposal: SolverProposalDTO = {
      ...mockProposal,
      scope: 'full_season',
      proposed_teams: [
        {
          ...mockProposal.proposed_teams[0],
          members: [
            { ...mockProposal.proposed_teams[0].members[0], gender: 'female' },
            { ...mockProposal.proposed_teams[0].members[1], gender: 'male' },
          ],
        },
      ],
      compromises: {
        date_conflict_pending: [],
        split_date: [],
        unfulfilled_mutual_pairs: [],
        solo_gender_teams: [
          {
            team_name: 'H-BP1-2',
            stage: 'practice',
            solo_gender: 'female',
            explorer_name: 'Sophie Kerr',
          },
        ],
      },
    };

    const fetchMock = vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes('/preferences/parse')) {
        return { ok: true, json: async () => ({ parsed: 1 }) };
      }
      if (url.includes('/preferences/queue')) {
        return { ok: true, json: async () => unitPrefQueue };
      }
      if (url.includes('/preferences/genders') && init?.method === 'PUT') {
        return { ok: true, json: async () => ({ updated: 1 }) };
      }
      if (url.includes('/teams/solver/propose')) {
        return { ok: true, json: async () => genderProposal };
      }
      return { ok: false, status: 404, json: async () => ({}) };
    });

    global.fetch = fetchMock as unknown as typeof fetch;

    render(
      <AutoProposeTeamsModal
        isOpen={true}
        onClose={vi.fn()}
        level="bronze"
        levels={['bronze', 'silver']}
        transport="hillwalking"
        onRefreshAll={vi.fn()}
      />
    );

    // Step 1: unit_preference badge & Prefer/Avoid/No Match controls
    expect(await screen.findByText('Unit preference: Lauderdale ESU')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Prefer' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Avoid' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'No Match' })).toBeInTheDocument();

    // Gender selector defaults to Female ('female') and updates via PUT /preferences/genders
    const genderSelect = screen.getByLabelText('Gender for Sophie Kerr') as HTMLSelectElement;
    expect(genderSelect.value).toBe('female');
    fireEvent.change(genderSelect, { target: { value: 'male' } });

    await waitFor(() => {
      const genderCall = fetchMock.mock.calls.find(
        ([u, i]) => typeof u === 'string' && u.includes('/preferences/genders') && i?.method === 'PUT'
      );
      expect(genderCall).toBeDefined();
      expect(JSON.parse(String(genderCall?.[1]?.body))).toEqual({
        genders: [{ signup_id: 501, gender: 'male' }],
      });
    });

    // Proceed to Step 2
    fireEvent.click(screen.getByRole('button', { name: /Skip to Step 2/i }));

    // Multi-level defaults to Full Season, first radio shows Current filter (Bronze, Silver / Hillwalking)
    expect(screen.getByRole('radio', { name: /Full Season \(All Buckets/i })).toBeChecked();
    expect(screen.getByRole('radio', { name: /Current filter \(Bronze, Silver \/ Hillwalking/i })).toBeInTheDocument();

    // Weighting explanation callout & solo_gender_penalty default -40
    expect(screen.getByText(/How Solver Weights Work:/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Solo gender penalty/i)).toHaveValue(-40);
    expect(screen.getByText(/Penalizes leaving a single male or female alone on a team\./i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Generate Proposal/i }));

    // Team card shows gender counts and Exceptions & Compromises surfaces solo_gender_teams
    expect(await screen.findByText(/1M \/ 1F/i)).toBeInTheDocument();
    expect(screen.getByText(/Solo Gender Teams:/i)).toBeInTheDocument();
    expect(screen.getByText(/H-BP1-2 \(practice: Sophie Kerr — solo female\)/i)).toBeInTheDocument();
  });
});

describe('TeamScopeBar multi-level selection', () => {
  it('toggles levels while keeping at least one selected and supports All', () => {
    const onChangeLevels = vi.fn();
    render(
      <TeamScopeBar
        levels={['silver']}
        transport="hillwalking"
        pendingNotifyCount={0}
        onChangeLevels={onChangeLevels}
        onChangeTransport={vi.fn()}
        onOpenNotify={vi.fn()}
      />
    );

    // Clicking the only selected level does not deselect it
    fireEvent.click(screen.getByRole('button', { name: 'Silver' }));
    expect(onChangeLevels).not.toHaveBeenCalled();

    // Clicking Bronze adds it in canonical order
    fireEvent.click(screen.getByRole('button', { name: 'Bronze' }));
    expect(onChangeLevels).toHaveBeenCalledWith(['bronze', 'silver']);

    // Clicking All selects all 3 levels
    fireEvent.click(screen.getByRole('button', { name: 'All' }));
    expect(onChangeLevels).toHaveBeenCalledWith(['bronze', 'silver', 'gold']);
  });
});
