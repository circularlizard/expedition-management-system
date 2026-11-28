import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faChevronDown,
  faChevronRight,
  faCircleCheck,
  faCircleExclamation,
  faMagnifyingGlass,
  faPlus,
  faSpinner,
  faTriangleExclamation,
  faWandMagicSparkles,
  faXmark,
} from '@fortawesome/free-solid-svg-icons';
import type {
  ExplorerGender,
  PreferenceCandidateDTO,
  PreferenceDecisionItemDTO,
  PreferenceQueueItemDTO,
  PreferenceQueueResponseDTO,
  PreferenceTokenDTO,
  SelectableExpeditionDTO,
  SolverApplyResponseDTO,
  SolverProposalDTO,
  SolverWeightsDTO,
  TeammateMatchStatus,
  TeammatePreferenceType,
} from '@seee/contracts';

export interface AutoProposeTeamsModalProps {
  isOpen: boolean;
  onClose: () => void;
  level: 'bronze' | 'silver' | 'gold';
  levels?: Array<'bronze' | 'silver' | 'gold'>;
  transport: string;
  rootUrl?: string;
  nonce?: string;
  onRefreshAll: () => Promise<void> | void;
  onFeedback?: (msg: { ok: boolean; msg: string } | null) => void;
}

const DEFAULT_WEIGHTS: SolverWeightsDTO = {
  avoid_exclusion: -500,
  cross_stage_continuity: 100,
  code_availability: 80,
  mutual_wishlist: 25,
  one_way_wishlist: 10,
  solo_gender_penalty: -40,
  solo_unit_district_penalty: -30,
  same_unit: 5,
  same_district: 3,
  first_aid_presence: 5,
};

const WEIGHT_TIERS: Array<{
  title: string;
  fields: Array<{ key: keyof SolverWeightsDTO; label: string; description: string }>;
}> = [
  {
    title: 'Strong Rules (Hard to Override)',
    fields: [
      {
        key: 'avoid_exclusion',
        label: 'Avoid exclusions',
        description: 'Strong penalty to keep "Avoid" pairs on separate teams.',
      },
      {
        key: 'cross_stage_continuity',
        label: 'Keep teams together across Practice & Qualifier',
        description: 'Keeps the same explorers together across practice & qualifier.',
      },
      {
        key: 'code_availability',
        label: "Only use expedition codes they're available for",
        description: 'Rewards assigning explorers to expedition codes they can attend.',
      },
    ],
  },
  {
    title: 'Avoid Splitting People Up',
    fields: [
      {
        key: 'solo_gender_penalty',
        label: "Don't leave 1 boy or girl alone on a team",
        description: 'Penalizes leaving a single male or female alone on a team.',
      },
      {
        key: 'solo_unit_district_penalty',
        label: "Don't leave 1 explorer from a unit/district alone",
        description: 'Presumption against placing 1 explorer from a unit/district into a team with others from another district.',
      },
    ],
  },
  {
    title: 'Friend & Unit Tie-Breakers',
    fields: [
      {
        key: 'mutual_wishlist',
        label: 'Mutual friend requests',
        description: 'Rewards pairing two explorers who both listed each other.',
      },
      {
        key: 'one_way_wishlist',
        label: 'One-way friend requests',
        description: 'Rewards honoring a one-sided teammate preference.',
      },
      {
        key: 'same_unit',
        label: 'Same unit',
        description: 'Gentle tie-breaker to group explorers from the same unit.',
      },
      {
        key: 'same_district',
        label: 'Same district',
        description: 'Gentle tie-breaker to group explorers from the same district (weaker than same unit).',
      },
      {
        key: 'first_aid_presence',
        label: 'Has First Aid qualification',
        description: 'Rewards ensuring at least one First Aid-qualified explorer per team.',
      },
    ],
  },
];

interface TokenDraft {
  id: number;
  target_signup_id: number | null;
  preference_type: TeammatePreferenceType;
  match_status: TeammateMatchStatus;
  resolved_target_name?: string | null;
  resolved_target_unit?: string | null;
  dirty: boolean;
}

interface AddedTeammateDraft {
  id?: number;
  tempKey: string;
  target_signup_id: number;
  explorer_name: string;
  unit_name: string;
  preference_type: TeammatePreferenceType;
}

const ALL_LEVELS: Array<'bronze' | 'silver' | 'gold'> = ['bronze', 'silver', 'gold'];

function formatShortDate(dateStr?: string): string {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

function buildApiUrl(rootUrl: string, path: string): string {
  const base = (rootUrl || '/wp-json/ems/v1').replace(/\/+$/, '');
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  if (base.endsWith('/ems/v1')) {
    return `${base}${normalizedPath}`;
  }
  return `${base}/ems/v1${normalizedPath}`;
}

function toCanonicalTransportMode(raw: string): string {
  const n = (raw || '').trim().toLowerCase();
  if (n === 'hillwalking' || n === 'walking' || n === 'foot') return 'foot';
  if (n === 'biking' || n === 'cycling' || n === 'bike') return 'cycle';
  if (n === 'paddling' || n === 'canoe' || n === 'paddle') return 'paddle';
  return n || 'foot';
}

function formatTransportLabel(raw: string): string {
  const n = (raw || '').trim().toLowerCase();
  if (n === 'foot' || n === 'hillwalking') return 'Hillwalking';
  if (n === 'cycle' || n === 'biking') return 'Biking';
  if (n === 'paddle' || n === 'paddling') return 'Paddling';
  return raw;
}

function formatLevelLabel(raw: string): string {
  return raw ? raw.charAt(0).toUpperCase() + raw.slice(1) : '';
}

function getStatusBadgeStyle(
  status: TeammateMatchStatus,
  unitHint?: string | null
): { label: string; bg: string; color: string; border: string } {
  switch (status) {
    case 'confirmed':
      return { label: 'Confirmed', bg: '#dcfce7', color: '#166534', border: '#86efac' };
    case 'auto_matched':
      return { label: 'Auto-matched', bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' };
    case 'ambiguous':
      return { label: 'Ambiguous', bg: '#fef3c7', color: '#92400e', border: '#f59e0b' };
    case 'cross_bucket':
      return { label: 'Cross-bucket', bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' };
    case 'unit_preference':
      return {
        label: unitHint ? `Unit preference: ${unitHint}` : 'Unit preference',
        bg: '#f3e8ff',
        color: '#6b21a8',
        border: '#d8b4fe',
      };
    case 'ignored':
      return { label: 'No Match', bg: '#f1f5f9', color: '#475569', border: '#cbd5e1' };
    case 'not_found':
    default:
      return { label: 'Not found', bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' };
  }
}

export const AutoProposeTeamsModal: React.FC<AutoProposeTeamsModalProps> = ({
  isOpen,
  onClose,
  level,
  levels,
  transport,
  rootUrl: propRootUrl,
  nonce: propNonce,
  onRefreshAll,
  onFeedback,
}) => {
  const config = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '' };
  const rootUrl = propRootUrl || config.root_url;
  const nonce = propNonce !== undefined ? propNonce : config.nonce;
  const initialCanonicalTransport = useMemo(() => toCanonicalTransportMode(transport), [transport]);
  const initialSelectedLevels = useMemo(
    () => (levels && levels.length > 0 ? levels : [level]),
    [levels, level]
  );

  const [activeLevels, setActiveLevels] = useState<Array<'bronze' | 'silver' | 'gold'>>(initialSelectedLevels);
  const [activeTransport, setActiveTransport] = useState<string>(initialCanonicalTransport);
  const activeLevelsKey = activeLevels.join(',');
  const isMultiLevel = activeLevels.length > 1 || activeTransport === 'all';

  const [step, setStep] = useState<1 | 2>(1);

  // Step 1 state
  const [filterMode, setFilterMode] = useState<'needs_review' | 'all'>('needs_review');
  const [loadingQueue, setLoadingQueue] = useState(false);
  const [queueError, setQueueError] = useState<string | null>(null);
  const [queueData, setQueueData] = useState<PreferenceQueueResponseDTO | null>(null);
  const [tokenDrafts, setTokenDrafts] = useState<Record<number, TokenDraft>>({});
  const [addedBySource, setAddedBySource] = useState<Record<number, AddedTeammateDraft[]>>({});
  const [genderDrafts, setGenderDrafts] = useState<Record<number, ExplorerGender>>({});
  const [cardSaveState, setCardSaveState] = useState<Record<number, 'saving' | 'saved' | 'error'>>({});
  const [collapsedUnits, setCollapsedUnits] = useState<Record<string, boolean>>({});
  const inFlightSavesRef = useRef<Map<number, Promise<void>>>(new Map());

  // Bucket-scoped search picker state (keyed by `token-${tokenId}` or `add-${sourceSignupId}`)
  const [searchOpenKey, setSearchOpenKey] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<PreferenceCandidateDTO[]>([]);
  const [searchLoading, setSearchLoading] = useState(false);
  const [savingStep1, setSavingStep1] = useState(false);

  // Step 2 state
  const [solverScope, setSolverScope] = useState<'bucket' | 'full_season'>(
    isMultiLevel ? 'full_season' : 'bucket'
  );
  const [selectableExpeditions, setSelectableExpeditions] = useState<SelectableExpeditionDTO[]>([]);
  const [loadingExpeditions, setLoadingExpeditions] = useState(false);
  const [includedCodes, setIncludedCodes] = useState<string[]>([]);
  const [weights, setWeights] = useState<SolverWeightsDTO>(DEFAULT_WEIGHTS);
  const [savingWeights, setSavingWeights] = useState(false);
  const [weightsSavedMsg, setWeightsSavedMsg] = useState<string | null>(null);
  const [proposing, setProposing] = useState(false);
  const [proposalError, setProposalError] = useState<string | null>(null);
  const [proposal, setProposal] = useState<SolverProposalDTO | null>(null);
  const [warningsDismissed, setWarningsDismissed] = useState(false);
  const [replaceExistingDrafts, setReplaceExistingDrafts] = useState(false);
  const [applying, setApplying] = useState(false);
  const [applyError, setApplyError] = useState<string | null>(null);

  const hydrateDrafts = useCallback((items: PreferenceQueueItemDTO[]) => {
    setTokenDrafts((prev) => {
      const next: Record<number, TokenDraft> = { ...prev };
      for (const item of items) {
        for (const tok of item.tokens) {
          if (!next[tok.id] || !next[tok.id].dirty) {
            next[tok.id] = {
              id: tok.id,
              target_signup_id: tok.target_signup_id,
              preference_type: tok.preference_type,
              match_status: tok.match_status,
              resolved_target_name: tok.resolved_target_name,
              resolved_target_unit: tok.resolved_target_unit,
              dirty: false,
            };
          }
        }
      }
      return next;
    });
  }, []);

  const fetchQueue = useCallback(
    async (
      filter: 'needs_review' | 'all',
      levelsToFetch: Array<'bronze' | 'silver' | 'gold'> = activeLevels,
      transportToFetch: string = activeTransport
    ) => {
      const results = await Promise.all(
        levelsToFetch.map(async (lvl) => {
          const params = new URLSearchParams({
            dofe_level: lvl,
            filter,
          });
          if (transportToFetch && transportToFetch !== 'all') {
            params.set('transport_mode', transportToFetch);
          }
          const res = await fetch(`${buildApiUrl(rootUrl, '/preferences/queue')}?${params.toString()}`, {
            headers: { 'X-WP-Nonce': nonce },
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) {
            throw new Error(data.message || `Failed to load preference queue (HTTP ${res.status})`);
          }
          return {
            items: Array.isArray(data.items) ? (data.items as PreferenceQueueItemDTO[]) : [],
            summary: data.summary || {
              total: 0,
              needs_review: 0,
              auto_matched: 0,
              confirmed: 0,
              ignored: 0,
            },
          };
        })
      );

      const seenItems = new Map<number, PreferenceQueueItemDTO>();
      const mergedSummary = {
        total: 0,
        needs_review: 0,
        auto_matched: 0,
        confirmed: 0,
        ignored: 0,
        bucket_signups_count: 0,
        full_season_signups_count: 0,
      };
      for (const r of results) {
        for (const item of r.items) {
          if (!seenItems.has(item.source_signup_id)) {
            seenItems.set(item.source_signup_id, item);
          }
        }
        mergedSummary.total += Number(r.summary.total ?? 0);
        mergedSummary.needs_review += Number(r.summary.needs_review ?? 0);
        mergedSummary.auto_matched += Number(r.summary.auto_matched ?? 0);
        mergedSummary.confirmed += Number(r.summary.confirmed ?? 0);
        mergedSummary.ignored += Number(r.summary.ignored ?? 0);
        mergedSummary.bucket_signups_count += Number(r.summary.bucket_signups_count ?? 0);
        mergedSummary.full_season_signups_count = Math.max(
          mergedSummary.full_season_signups_count,
          Number(r.summary.full_season_signups_count ?? 0)
        );
      }

      const normalized: PreferenceQueueResponseDTO = {
        items: Array.from(seenItems.values()),
        summary: mergedSummary,
      };
      setQueueData(normalized);
      hydrateDrafts(normalized.items);
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [rootUrl, nonce, activeLevelsKey, activeTransport, hydrateDrafts]
  );

  const parseAndLoadScope = useCallback(
    async (
      filter: 'needs_review' | 'all',
      levelsToLoad: Array<'bronze' | 'silver' | 'gold'>,
      transportToLoad: string
    ) => {
      await Promise.all(
        levelsToLoad.map(async (lvl) => {
          const payload: Record<string, string> = { dofe_level: lvl };
          if (transportToLoad && transportToLoad !== 'all') {
            payload.transport_mode = transportToLoad;
          }
          const parseRes = await fetch(buildApiUrl(rootUrl, '/preferences/parse'), {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': nonce,
            },
            body: JSON.stringify(payload),
          });
          if (!parseRes.ok) {
            const errData = await parseRes.json().catch(() => ({}));
            throw new Error(errData.message || `Failed to parse wishlists (HTTP ${parseRes.status})`);
          }
        })
      );
      await fetchQueue(filter, levelsToLoad, transportToLoad);
    },
    [rootUrl, nonce, fetchQueue]
  );

  // Run parse + initial queue load on open
  useEffect(() => {
    if (!isOpen) return;
    let cancelled = false;
    setActiveLevels(initialSelectedLevels);
    setActiveTransport(initialCanonicalTransport);
    setStep(1);
    setFilterMode('needs_review');
    setSolverScope(initialSelectedLevels.length > 1 ? 'full_season' : 'bucket');
    setQueueError(null);
    setTokenDrafts({});
    setAddedBySource({});
    setGenderDrafts({});
    setCardSaveState({});
    setCollapsedUnits({});
    setSearchOpenKey(null);
    setProposal(null);
    setProposalError(null);
    setWarningsDismissed(false);
    setWeightsSavedMsg(null);
    setApplyError(null);
    setSelectableExpeditions([]);
    setIncludedCodes([]);

    // Load saved solver weights in the background (gracefully keep DEFAULT_WEIGHTS if unavailable)
    fetch(buildApiUrl(rootUrl, '/teams/solver/weights'), {
      headers: { 'X-WP-Nonce': nonce },
    })
      .then((res) => (res && res.ok ? res.json() : null))
      .then((data) => {
        if (!cancelled && data?.weights) {
          setWeights({ ...DEFAULT_WEIGHTS, ...data.weights });
        }
      })
      .catch(() => {
        // Ignore if endpoint unavailable in tests
      });

    const init = async () => {
      setLoadingQueue(true);
      try {
        await parseAndLoadScope('needs_review', initialSelectedLevels, initialCanonicalTransport);
      } catch (err) {
        if (!cancelled) {
          setQueueError(err instanceof Error ? err.message : 'Failed to parse teammate wishlists.');
        }
      } finally {
        if (!cancelled) {
          setLoadingQueue(false);
        }
      }
    };

    init();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, rootUrl, nonce, initialSelectedLevels, initialCanonicalTransport]);

  // Fetch selectable expeditions when entering Step 2 or changing solver scope
  useEffect(() => {
    if (!isOpen || step !== 2) return;
    let cancelled = false;
    setLoadingExpeditions(true);

    const params = new URLSearchParams({
      scope: solverScope,
      dofe_level: activeLevels.join(','),
    });
    if (activeTransport && activeTransport !== 'all') {
      params.set('transport_mode', activeTransport);
    }

    fetch(`${buildApiUrl(rootUrl, '/teams/solver/expeditions')}?${params.toString()}`, {
      headers: { 'X-WP-Nonce': nonce },
    })
      .then((res) => (res && res.ok ? res.json() : null))
      .then((data) => {
        if (cancelled) return;
        const list = Array.isArray(data?.expeditions) ? (data.expeditions as SelectableExpeditionDTO[]) : [];
        setSelectableExpeditions(list);
        setIncludedCodes(list.map((e) => e.event_code).filter(Boolean));
      })
      .catch(() => {
        if (!cancelled) {
          setSelectableExpeditions([]);
          setIncludedCodes([]);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoadingExpeditions(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [isOpen, step, solverScope, activeLevelsKey, activeTransport, rootUrl, nonce, activeLevels]);

  const buildDecisionsForItem = (
    item: PreferenceQueueItemDTO,
    draftsMap: Record<number, TokenDraft>,
    addedList: AddedTeammateDraft[]
  ): PreferenceDecisionItemDTO[] => {
    const decisions: PreferenceDecisionItemDTO[] = item.tokens.map((tok) => {
      const draft = draftsMap[tok.id];
      const targetId = draft ? draft.target_signup_id : tok.target_signup_id;
      const prefType = draft ? draft.preference_type : tok.preference_type;
      const rawStatus = draft ? draft.match_status : tok.match_status;
      const matchStatus: 'confirmed' | 'ignored' =
        rawStatus === 'ignored' || targetId === null ? 'ignored' : 'confirmed';

      return {
        id: tok.id,
        target_signup_id: matchStatus === 'ignored' ? null : targetId,
        preference_type: prefType,
        match_status: matchStatus,
        raw_token: tok.raw_token,
      };
    });

    for (const extra of addedList) {
      decisions.push({
        ...(extra.id ? { id: extra.id } : {}),
        target_signup_id: extra.target_signup_id,
        preference_type: extra.preference_type,
        match_status: 'confirmed',
        raw_token: extra.explorer_name,
      });
    }
    return decisions;
  };

  const persistCard = (
    item: PreferenceQueueItemDTO,
    nextDrafts: Record<number, TokenDraft>,
    nextAddedList: AddedTeammateDraft[],
    nextGender: ExplorerGender,
    removedSavedIds: number[] = []
  ): Promise<void> => {
    const sourceId = item.source_signup_id;
    const decisions = buildDecisionsForItem(item, nextDrafts, nextAddedList);
    for (const removedId of removedSavedIds) {
      decisions.push({
        id: removedId,
        target_signup_id: null,
        preference_type: 'prefer',
        match_status: 'ignored',
      });
    }

    setCardSaveState((prev) => ({ ...prev, [sourceId]: 'saving' }));

    const prevPromise = inFlightSavesRef.current.get(sourceId) || Promise.resolve();
    const savePromise = prevPromise
      .catch(() => {})
      .then(async () => {
        const res = await fetch(buildApiUrl(rootUrl, `/preferences/${sourceId}`), {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce,
          },
          body: JSON.stringify({ decisions, gender: nextGender }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          throw new Error(
            data.message || `Failed to save preferences for ${item.explorer_name} (HTTP ${res.status})`
          );
        }

        // Attach server-assigned IDs to any newly added teammates so subsequent edits update instead of duplicating
        if (Array.isArray(data.items)) {
          const serverItems = data.items as Array<{
            id?: number;
            target_signup_id?: number | null;
            preference_type?: string;
            match_status?: string;
          }>;
          setAddedBySource((prev) => {
            const currentList = prev[sourceId];
            if (!currentList || currentList.length === 0) return prev;
            let changed = false;
            const updatedList = currentList.map((extra) => {
              if (extra.id) return extra;
              const match = serverItems.find(
                (si) =>
                  si.id &&
                  si.target_signup_id === extra.target_signup_id &&
                  si.preference_type === extra.preference_type &&
                  si.match_status === 'confirmed'
              );
              if (match?.id) {
                changed = true;
                return { ...extra, id: match.id };
              }
              return extra;
            });
            return changed ? { ...prev, [sourceId]: updatedList } : prev;
          });
        }

        setTokenDrafts((prev) => {
          const next = { ...prev };
          let changed = false;
          for (const tok of item.tokens) {
            if (next[tok.id]?.dirty) {
              next[tok.id] = { ...next[tok.id], dirty: false };
              changed = true;
            }
          }
          return changed ? next : prev;
        });

        setCardSaveState((prev) => ({ ...prev, [sourceId]: 'saved' }));
      })
      .catch((err) => {
        setCardSaveState((prev) => ({ ...prev, [sourceId]: 'error' }));
        setQueueError(err instanceof Error ? err.message : 'Failed to save preference decisions.');
      });

    inFlightSavesRef.current.set(sourceId, savePromise);
    void savePromise.finally(() => {
      if (inFlightSavesRef.current.get(sourceId) === savePromise) {
        inFlightSavesRef.current.delete(sourceId);
      }
    });
    return savePromise;
  };

  const handleGenderChange = (item: PreferenceQueueItemDTO, gender: ExplorerGender) => {
    const signupId = item.source_signup_id;
    setGenderDrafts((prev) => ({ ...prev, [signupId]: gender }));
    const currentAdded = addedBySource[signupId] || [];
    void persistCard(item, tokenDrafts, currentAdded, gender);
  };

  const handleScopeSwitch = async (
    nextLevels: Array<'bronze' | 'silver' | 'gold'>,
    nextTransport: string
  ) => {
    setActiveLevels(nextLevels);
    setActiveTransport(nextTransport);
    setSolverScope(nextLevels.length > 1 || nextTransport === 'all' ? 'full_season' : 'bucket');
    setLoadingQueue(true);
    setQueueError(null);
    setProposal(null);
    try {
      await parseAndLoadScope(filterMode, nextLevels, nextTransport);
    } catch (err) {
      setQueueError(err instanceof Error ? err.message : 'Failed to load preference queue.');
    } finally {
      setLoadingQueue(false);
    }
  };

  const handleToggleFilter = async (nextFilter: 'needs_review' | 'all') => {
    setFilterMode(nextFilter);
    setLoadingQueue(true);
    setQueueError(null);
    try {
      await fetchQueue(nextFilter, activeLevels, activeTransport);
    } catch (err) {
      setQueueError(err instanceof Error ? err.message : 'Failed to load preference queue.');
    } finally {
      setLoadingQueue(false);
    }
  };

  const runCandidateSearch = async (sourceSignupId: number, q: string) => {
    setSearchLoading(true);
    try {
      const params = new URLSearchParams({
        source_signup_id: String(sourceSignupId),
        q,
      });
      const res = await fetch(`${buildApiUrl(rootUrl, '/preferences/candidates')}?${params.toString()}`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok) {
        const list = Array.isArray(data) ? data : Array.isArray(data.candidates) ? data.candidates : [];
        setSearchResults(list);
      } else {
        setSearchResults([]);
      }
    } catch {
      setSearchResults([]);
    } finally {
      setSearchLoading(false);
    }
  };

  const openSearchPicker = (key: string, sourceSignupId: number) => {
    if (searchOpenKey === key) {
      setSearchOpenKey(null);
      return;
    }
    setSearchOpenKey(key);
    setSearchQuery('');
    setSearchResults([]);
    void runCandidateSearch(sourceSignupId, '');
  };

  const updateTokenDraftAndSave = (
    item: PreferenceQueueItemDTO,
    token: PreferenceTokenDTO,
    patch: Partial<TokenDraft>
  ) => {
    const current: TokenDraft = tokenDrafts[token.id] || {
      id: token.id,
      target_signup_id: token.target_signup_id,
      preference_type: token.preference_type,
      match_status: token.match_status,
      resolved_target_name: token.resolved_target_name,
      resolved_target_unit: token.resolved_target_unit,
      dirty: false,
    };
    const nextDrafts: Record<number, TokenDraft> = {
      ...tokenDrafts,
      [token.id]: {
        ...current,
        ...patch,
        dirty: true,
      },
    };
    setTokenDrafts(nextDrafts);
    const currentAdded = addedBySource[item.source_signup_id] || [];
    const currentGender: ExplorerGender =
      genderDrafts[item.source_signup_id] !== undefined
        ? genderDrafts[item.source_signup_id]
        : item.gender ?? '';
    void persistCard(item, nextDrafts, currentAdded, currentGender);
  };

  const handleProceedToStep2 = async () => {
    setSavingStep1(true);
    setQueueError(null);
    try {
      const pending = Array.from(inFlightSavesRef.current.values());
      if (pending.length > 0) {
        await Promise.allSettled(pending);
      }
      setStep(2);
    } catch (err) {
      setQueueError(err instanceof Error ? err.message : 'Failed to save preference decisions.');
    } finally {
      setSavingStep1(false);
    }
  };

  const handleSaveWeights = async () => {
    setSavingWeights(true);
    setWeightsSavedMsg(null);
    try {
      const res = await fetch(buildApiUrl(rootUrl, '/teams/solver/weights'), {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({ weights }),
      });
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      const data = await res.json().catch(() => ({}));
      if (data?.weights) {
        setWeights({ ...DEFAULT_WEIGHTS, ...data.weights });
      }
      setWeightsSavedMsg('Saved');
      setTimeout(() => setWeightsSavedMsg(null), 2500);
    } catch {
      setWeightsSavedMsg('Failed to save');
    } finally {
      setSavingWeights(false);
    }
  };

  const handleGenerateProposal = async () => {
    setProposing(true);
    setProposalError(null);
    setWarningsDismissed(false);
    try {
      const payload: Record<string, unknown> = {
        scope: solverScope,
        dofe_level: activeLevels.length === 1 ? activeLevels[0] : activeLevels.join(','),
        dofe_levels: activeLevels,
        transport_mode: activeTransport === 'all' ? '' : activeTransport,
        weights,
      };
      if (selectableExpeditions.length > 0) {
        payload.included_expedition_codes = includedCodes;
      }

      const res = await fetch(buildApiUrl(rootUrl, '/teams/solver/propose'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify(payload),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.message || `Solver proposal failed (HTTP ${res.status})`);
      }
      setProposal(data as SolverProposalDTO);
    } catch (err) {
      setProposalError(err instanceof Error ? err.message : 'Failed to generate team proposal.');
      setProposal(null);
    } finally {
      setProposing(false);
    }
  };

  const handleLoadDraftTeams = async () => {
    if (!proposal || proposal.proposed_teams.length === 0) return;
    setApplying(true);
    setApplyError(null);
    try {
      const res = await fetch(buildApiUrl(rootUrl, '/teams/solver/apply'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({
          proposed_teams: proposal.proposed_teams,
          replace_existing_drafts: replaceExistingDrafts,
          buckets: proposal.buckets_solved,
        }),
      });
      const data: SolverApplyResponseDTO & { message?: string } = await res.json().catch(() => ({} as any));
      if (!res.ok) {
        throw new Error(data.message || `Failed to load draft teams (HTTP ${res.status})`);
      }

      await onRefreshAll();
      const clearedText =
        data.cleared_draft_teams > 0 ? ` (${data.cleared_draft_teams} existing draft team(s) replaced)` : '';
      onFeedback?.({
        ok: true,
        msg: `Loaded ${data.created_teams} draft team(s) with ${data.created_members} member assignment(s)${clearedText}.`,
      });
      onClose();
    } catch (err) {
      setApplyError(err instanceof Error ? err.message : 'Failed to load draft teams.');
    } finally {
      setApplying(false);
    }
  };

  if (!isOpen) return null;

  const summary = queueData?.summary;
  const zeroFlaggedRemaining = (summary?.needs_review ?? 0) === 0;
  const items = queueData?.items || [];

  const groupedUnits = useMemo(() => {
    const byUnit = new Map<string, PreferenceQueueItemDTO[]>();
    for (const item of items) {
      const unitLabel = (item.unit_name || '').trim() || 'Unassigned Unit';
      const arr = byUnit.get(unitLabel) || [];
      arr.push(item);
      byUnit.set(unitLabel, arr);
    }
    const sortedUnitNames = Array.from(byUnit.keys()).sort((a, b) => a.localeCompare(b));
    let seqCounter = 0;
    return sortedUnitNames.map((unitName) => {
      const unitItems = (byUnit.get(unitName) || [])
        .slice()
        .sort((a, b) => a.explorer_name.localeCompare(b.explorer_name))
        .map((item) => {
          seqCounter += 1;
          return { item, seq: seqCounter };
        });
      return { unitName, items: unitItems };
    });
  }, [items]);

  const practiceExpeditions = useMemo(
    () => selectableExpeditions.filter((e) => e.stage === 'practice'),
    [selectableExpeditions]
  );
  const qualifierExpeditions = useMemo(
    () => selectableExpeditions.filter((e) => e.stage === 'qualifier'),
    [selectableExpeditions]
  );

  const expeditionValidationError = useMemo(() => {
    if (selectableExpeditions.length === 0) return null;
    const includedSet = new Set(includedCodes.map((c) => c.toUpperCase()));
    const buckets = new Map<
      string,
      { level: string; mode: string; hasPractice: boolean; hasQualifier: boolean; checkedPractice: number; checkedQualifier: number }
    >();
    for (const exp of selectableExpeditions) {
      const key = `${exp.dofe_level}|${exp.transport_mode}`;
      const entry = buckets.get(key) || {
        level: exp.dofe_level,
        mode: exp.transport_mode,
        hasPractice: false,
        hasQualifier: false,
        checkedPractice: 0,
        checkedQualifier: 0,
      };
      const isChecked = includedSet.has(exp.event_code.toUpperCase());
      if (exp.stage === 'practice') {
        entry.hasPractice = true;
        if (isChecked) entry.checkedPractice += 1;
      } else {
        entry.hasQualifier = true;
        if (isChecked) entry.checkedQualifier += 1;
      }
      buckets.set(key, entry);
    }
    for (const entry of buckets.values()) {
      if (entry.hasPractice && entry.checkedPractice === 0) {
        return `Select at least 1 Practice expedition for ${formatLevelLabel(entry.level)} ${formatTransportLabel(entry.mode)}.`;
      }
      if (entry.hasQualifier && entry.checkedQualifier === 0) {
        return `Select at least 1 Qualifier expedition for ${formatLevelLabel(entry.level)} ${formatTransportLabel(entry.mode)}.`;
      }
    }
    return null;
  }, [selectableExpeditions, includedCodes]);

  const subtitleLevelText = activeLevels.map(formatLevelLabel).join(', ');
  const subtitleModeText = activeTransport === 'all' ? 'All Modes' : formatTransportLabel(activeTransport);

  return (
    <div
      className="ems-modal-backdrop"
      role="dialog"
      aria-modal="true"
      aria-label="Auto-Propose Teams"
    >
      <div
        className="ems-modal-card ems-overview-card ems-dispatch-modal"
        style={{ maxWidth: '980px', width: '95vw', maxHeight: '90vh', display: 'flex', flexDirection: 'column' }}
      >
        {/* Header */}
        <div className="ems-dispatch-modal__header">
          <div>
            <h2 className="ems-dispatch-modal__title">
              <FontAwesomeIcon icon={faWandMagicSparkles} style={{ marginRight: '8px', color: '#2563eb' }} />
              Auto-Propose Teams
            </h2>
            <div className="ems-dispatch-modal__subtitle">
              {subtitleLevelText} · {subtitleModeText} ·{' '}
              {step === 1 ? 'Step 1 of 2: Confirm Teammate Links' : 'Step 2 of 2: Propose & Load Draft Teams'}
            </div>
          </div>
          <button
            type="button"
            className="button button-link ems-dispatch-modal__close"
            onClick={onClose}
            aria-label="Close modal"
            disabled={savingStep1 || proposing || applying}
          >
            <FontAwesomeIcon icon={faXmark} size="lg" />
          </button>
        </div>

        {/* Stepper Bar */}
        <div
          style={{
            display: 'flex',
            gap: '8px',
            padding: '8px 16px',
            background: '#f8fafc',
            borderBottom: '1px solid #e2e8f0',
          }}
        >
          <button
            type="button"
            className={`button ${step === 1 ? 'button-primary' : 'button-secondary'}`}
            onClick={() => setStep(1)}
            disabled={proposing || applying}
          >
            1. Confirm Teammate Links
            {summary && summary.needs_review > 0 ? ` (${summary.needs_review} flagged)` : ''}
          </button>
          <button
            type="button"
            className={`button ${step === 2 ? 'button-primary' : 'button-secondary'}`}
            onClick={() => setStep(2)}
            disabled={loadingQueue || savingStep1 || proposing || applying}
          >
            2. Propose &amp; Load Draft Teams
          </button>
        </div>

        {/* Scrollable Body */}
        <div style={{ padding: '16px', overflowY: 'auto', flex: 1 }}>
          {step === 1 && (
            <div>
              {queueError && (
                <div className="ems-error-notice ems-mb-12" role="alert">
                  <FontAwesomeIcon icon={faCircleExclamation} /> {queueError}
                </div>
              )}

              {/* In-Modal Level & Mode Scope Switcher */}
              <div className="ems-propose-scope-bar">
                <div className="ems-propose-scope-group">
                  <span className="ems-propose-scope-group__label">Level:</span>
                  <div className="ems-btn-group" role="group" aria-label="Filter by DofE level">
                    {ALL_LEVELS.map((lvl) => {
                      const isSelected = activeLevels.includes(lvl);
                      return (
                        <button
                          key={lvl}
                          type="button"
                          className={`button button-small ${isSelected ? 'button-primary' : 'button-secondary'}`}
                          disabled={loadingQueue}
                          onClick={() => {
                            let next: Array<'bronze' | 'silver' | 'gold'>;
                            if (isSelected) {
                              if (activeLevels.length === 1) return;
                              next = activeLevels.filter((l) => l !== lvl);
                            } else {
                              next = ALL_LEVELS.filter((l) => activeLevels.includes(l) || l === lvl);
                            }
                            void handleScopeSwitch(next, activeTransport);
                          }}
                        >
                          {formatLevelLabel(lvl)}
                        </button>
                      );
                    })}
                    <button
                      type="button"
                      className={`button button-small ${activeLevels.length === 3 ? 'button-primary' : 'button-secondary'}`}
                      disabled={loadingQueue}
                      onClick={() => void handleScopeSwitch([...ALL_LEVELS], activeTransport)}
                    >
                      All
                    </button>
                  </div>
                </div>

                <div className="ems-propose-scope-group">
                  <span className="ems-propose-scope-group__label">Mode:</span>
                  <div className="ems-btn-group" role="group" aria-label="Filter by expedition mode">
                    {[
                      { value: 'foot', label: 'Hillwalking' },
                      { value: 'cycle', label: 'Biking' },
                      { value: 'paddle', label: 'Paddling' },
                      { value: 'all', label: 'All' },
                    ].map((m) => (
                      <button
                        key={m.value}
                        type="button"
                        className={`button button-small ${activeTransport === m.value ? 'button-primary' : 'button-secondary'}`}
                        disabled={loadingQueue}
                        onClick={() => void handleScopeSwitch(activeLevels, m.value)}
                      >
                        {m.label}
                      </button>
                    ))}
                  </div>
                </div>
              </div>

              {/* Summary & Filter Controls */}
              <div
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  flexWrap: 'wrap',
                  gap: '10px',
                  marginBottom: '12px',
                  padding: '10px 12px',
                  background: '#f6f7f7',
                  border: '1px solid #dcdcde',
                  borderRadius: '4px',
                }}
              >
                <div style={{ fontSize: '13px', display: 'flex', gap: '12px', flexWrap: 'wrap' }}>
                  <span>
                    Total: <strong>{summary?.total ?? 0}</strong>
                  </span>
                  <span>
                    Needs Review: <strong>{summary?.needs_review ?? 0}</strong>
                  </span>
                  <span>
                    Auto-matched: <strong>{summary?.auto_matched ?? 0}</strong>
                  </span>
                  <span>
                    Confirmed: <strong>{summary?.confirmed ?? 0}</strong>
                  </span>
                  <span>
                    No Match: <strong>{summary?.ignored ?? 0}</strong>
                  </span>
                </div>

                <label style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '13px', cursor: 'pointer' }}>
                  <input
                    type="checkbox"
                    checked={filterMode === 'all'}
                    onChange={(e) => void handleToggleFilter(e.target.checked ? 'all' : 'needs_review')}
                    disabled={loadingQueue}
                  />
                  <span>Show All Explorers</span>
                </label>
              </div>

              {loadingQueue ? (
                <div style={{ padding: '32px', textAlign: 'center', color: '#64748b' }}>
                  <FontAwesomeIcon icon={faSpinner} spin size="lg" />
                  <div style={{ marginTop: '8px', fontSize: '13px' }}>
                    Parsing teammate wishlists and checking for duplicates…
                  </div>
                </div>
              ) : items.length === 0 ? (
                <div className="ems-dispatch-alert ems-dispatch-alert--ok">
                  <FontAwesomeIcon icon={faCircleCheck} />
                  <span>
                    {zeroFlaggedRemaining
                      ? 'All teammate wishlists are resolved. You can proceed straight to Step 2.'
                      : 'No wishlists found for the current filter.'}
                  </span>
                </div>
              ) : (
                <div>
                  {groupedUnits.map((group) => {
                    const isCollapsed = Boolean(collapsedUnits[group.unitName]);
                    return (
                    <div key={group.unitName} className="ems-unit-group">
                      <button
                        type="button"
                        className="ems-unit-group__header"
                        aria-expanded={!isCollapsed}
                        onClick={() =>
                          setCollapsedUnits((prev) => ({
                            ...prev,
                            [group.unitName]: !prev[group.unitName],
                          }))
                        }
                      >
                        <FontAwesomeIcon
                          icon={isCollapsed ? faChevronRight : faChevronDown}
                          style={{ fontSize: '11px', color: '#475569', width: '12px' }}
                        />
                        <span>{group.unitName}</span>
                        <span className="ems-unit-group__count">
                          ({group.items.length} {group.items.length === 1 ? 'explorer' : 'explorers'})
                        </span>
                      </button>
                      {!isCollapsed && (
                      <div className="ems-unit-group__cards">
                        {group.items.map(({ item, seq }) => {
                          const addedList = addedBySource[item.source_signup_id] || [];
                          const addPickerKey = `add-${item.source_signup_id}`;
                          const currentGender: ExplorerGender =
                            genderDrafts[item.source_signup_id] !== undefined
                              ? genderDrafts[item.source_signup_id]
                              : item.gender ?? '';
                          const saveStatus = cardSaveState[item.source_signup_id];

                          return (
                            <div
                              key={item.source_signup_id}
                              style={{
                                border: '1px solid #cbd5e1',
                                borderRadius: '6px',
                                padding: '12px',
                                background: '#fff',
                              }}
                            >
                              <div
                                style={{
                                  display: 'flex',
                                  justifyContent: 'space-between',
                                  alignItems: 'center',
                                  flexWrap: 'wrap',
                                  gap: '8px',
                                  marginBottom: '6px',
                                }}
                              >
                                <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '4px' }}>
                                  <span className="ems-explorer-seq">
                                    #{seq} of {items.length}
                                  </span>
                                  <strong style={{ fontSize: '14px' }}>{item.explorer_name}</strong>{' '}
                                  <span style={{ fontSize: '12px', color: '#475569' }}>
                                    ({item.unit_name}) · {formatLevelLabel(item.dofe_level)} ·{' '}
                                    {formatTransportLabel(item.transport_mode)}
                                  </span>
                                  {saveStatus === 'saving' && (
                                    <span className="ems-save-pill ems-save-pill--saving">Saving…</span>
                                  )}
                                  {saveStatus === 'saved' && (
                                    <span className="ems-save-pill ems-save-pill--saved">Saved ✓</span>
                                  )}
                                  {saveStatus === 'error' && (
                                    <span className="ems-save-pill ems-save-pill--error">Save failed</span>
                                  )}
                                </div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                  <label
                                    style={{
                                      display: 'inline-flex',
                                      alignItems: 'center',
                                      gap: '4px',
                                      fontSize: '12px',
                                      color: '#334155',
                                    }}
                                  >
                                    <span>Gender:</span>
                                    <select
                                      aria-label={`Gender for ${item.explorer_name}`}
                                      value={currentGender}
                                      onChange={(e) =>
                                        handleGenderChange(item, e.target.value as ExplorerGender)
                                      }
                                      disabled={savingStep1}
                                      style={{ fontSize: '12px', padding: '2px 28px 2px 8px', minWidth: '105px', minHeight: '28px', height: 'auto' }}
                                    >
                                      <option value="">Unknown</option>
                                      <option value="male">Male</option>
                                      <option value="female">Female</option>
                                      <option value="other">Other</option>
                                    </select>
                                  </label>
                                  <button
                                    type="button"
                                    className="button button-secondary"
                                    onClick={() => openSearchPicker(addPickerKey, item.source_signup_id)}
                                  >
                                    <FontAwesomeIcon icon={faPlus} /> + Add teammate
                                  </button>
                                </div>
                              </div>

                              <div style={{ fontSize: '12px', color: '#334155', marginBottom: '8px' }}>
                                Raw wishlist: <em>{item.wishlist_raw || '—'}</em>
                              </div>

                              {/* + Add teammate inline bucket-scoped search */}
                              {searchOpenKey === addPickerKey && (
                                <div
                                  style={{
                                    marginBottom: '10px',
                                    padding: '8px',
                                    background: '#f8fafc',
                                    border: '1px solid #cbd5e1',
                                    borderRadius: '4px',
                                  }}
                                >
                                  <div style={{ display: 'flex', gap: '6px', marginBottom: '6px' }}>
                                    <input
                                      type="text"
                                      className="regular-text"
                                      placeholder={`Search ${item.explorer_name}'s bucket by name or unit…`}
                                      aria-label={`Add teammate search for ${item.explorer_name}`}
                                      value={searchQuery}
                                      onChange={(e) => {
                                        const q = e.target.value;
                                        setSearchQuery(q);
                                        void runCandidateSearch(item.source_signup_id, q);
                                      }}
                                      style={{ flex: 1 }}
                                    />
                                  </div>
                                  {searchLoading ? (
                                    <div style={{ fontSize: '12px', color: '#64748b' }}>Searching…</div>
                                  ) : searchResults.length === 0 ? (
                                    <div style={{ fontSize: '12px', color: '#64748b' }}>No matching explorers in this bucket.</div>
                                  ) : (
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', maxHeight: '140px', overflowY: 'auto' }}>
                                      {searchResults.map((cand) => (
                                        <button
                                          key={cand.signup_id}
                                          type="button"
                                          className="button button-link"
                                          style={{ textAlign: 'left', padding: '2px 4px', fontSize: '12px' }}
                                          onClick={() => {
                                            const nextAdded: AddedTeammateDraft[] = [
                                              ...addedList,
                                              {
                                                tempKey: `${item.source_signup_id}-${cand.signup_id}-${Date.now()}`,
                                                target_signup_id: cand.signup_id,
                                                explorer_name: cand.explorer_name,
                                                unit_name: cand.unit_name,
                                                preference_type: 'prefer',
                                              },
                                            ];
                                            setAddedBySource((prev) => ({
                                              ...prev,
                                              [item.source_signup_id]: nextAdded,
                                            }));
                                            setSearchOpenKey(null);
                                            void persistCard(item, tokenDrafts, nextAdded, currentGender);
                                          }}
                                        >
                                          {cand.explorer_name} ({cand.unit_name}) · {cand.shared_code_count} shared code(s)
                                        </button>
                                      ))}
                                    </div>
                                  )}
                                </div>
                              )}

                              {/* Manually added teammates */}
                              {addedList.length > 0 && (
                                <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', marginBottom: '8px' }}>
                                  {addedList.map((extra) => (
                                    <div
                                      key={extra.tempKey}
                                      style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'space-between',
                                        gap: '8px',
                                        padding: '6px 8px',
                                        background: '#f0fdf4',
                                        border: '1px solid #86efac',
                                        borderRadius: '4px',
                                        fontSize: '12px',
                                      }}
                                    >
                                      <span>
                                        Added: <strong>{extra.explorer_name}</strong> ({extra.unit_name})
                                      </span>
                                      <div style={{ display: 'flex', gap: '6px', alignItems: 'center' }}>
                                        <button
                                          type="button"
                                          className={`button ${extra.preference_type === 'prefer' ? 'button-primary' : 'button-secondary'}`}
                                          onClick={() => {
                                            const nextAdded = addedList.map((a) =>
                                              a.tempKey === extra.tempKey ? { ...a, preference_type: 'prefer' as const } : a
                                            );
                                            setAddedBySource((prev) => ({
                                              ...prev,
                                              [item.source_signup_id]: nextAdded,
                                            }));
                                            void persistCard(item, tokenDrafts, nextAdded, currentGender);
                                          }}
                                        >
                                          Prefer
                                        </button>
                                        <button
                                          type="button"
                                          className={`button ${extra.preference_type === 'avoid' ? 'button-primary' : 'button-secondary'}`}
                                          onClick={() => {
                                            const nextAdded = addedList.map((a) =>
                                              a.tempKey === extra.tempKey ? { ...a, preference_type: 'avoid' as const } : a
                                            );
                                            setAddedBySource((prev) => ({
                                              ...prev,
                                              [item.source_signup_id]: nextAdded,
                                            }));
                                            void persistCard(item, tokenDrafts, nextAdded, currentGender);
                                          }}
                                        >
                                          Avoid
                                        </button>
                                        <button
                                          type="button"
                                          className="button button-link"
                                          onClick={() => {
                                            const nextAdded = addedList.filter((a) => a.tempKey !== extra.tempKey);
                                            setAddedBySource((prev) => ({
                                              ...prev,
                                              [item.source_signup_id]: nextAdded,
                                            }));
                                            void persistCard(
                                              item,
                                              tokenDrafts,
                                              nextAdded,
                                              currentGender,
                                              extra.id ? [extra.id] : []
                                            );
                                          }}
                                        >
                                          Remove
                                        </button>
                                      </div>
                                    </div>
                                  ))}
                                </div>
                              )}

                              {/* Parsed tokens */}
                              <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                                {item.tokens.map((tok) => {
                                  const draft = tokenDrafts[tok.id];
                                  const effectiveStatus = draft ? draft.match_status : tok.match_status;
                                  const effectivePrefType = draft ? draft.preference_type : tok.preference_type;
                                  const effectiveTargetId = draft ? draft.target_signup_id : tok.target_signup_id;
                                  const effectiveTargetName = draft ? draft.resolved_target_name : tok.resolved_target_name;
                                  const effectiveTargetUnit = draft ? draft.resolved_target_unit : tok.resolved_target_unit;
                                  const badge = getStatusBadgeStyle(effectiveStatus, tok.unit_hint);
                                  const tokenPickerKey = `token-${tok.id}`;

                                  return (
                                    <div
                                      key={tok.id}
                                      style={{
                                        padding: '8px 10px',
                                        border: '1px solid #e2e8f0',
                                        borderRadius: '4px',
                                        background: '#f8fafc',
                                      }}
                                    >
                                      <div
                                        style={{
                                          display: 'flex',
                                          justifyContent: 'space-between',
                                          alignItems: 'center',
                                          flexWrap: 'wrap',
                                          gap: '8px',
                                        }}
                                      >
                                        <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '6px' }}>
                                          <span style={{ fontWeight: 600, fontSize: '13px' }}>"{tok.raw_token}"</span>
                                          {tok.unit_hint && (
                                            <span style={{ fontSize: '11px', color: '#475569' }}>
                                              (hint: {tok.unit_hint})
                                            </span>
                                          )}
                                          <span
                                            className="ems-badge"
                                            style={{
                                              background: badge.bg,
                                              color: badge.color,
                                              border: `1px solid ${badge.border}`,
                                              fontSize: '11px',
                                            }}
                                          >
                                            {badge.label}
                                          </span>
                                          {tok.flag_negation && (
                                            <span
                                              className="ems-badge"
                                              style={{
                                                background: '#fef3c7',
                                                color: '#92400e',
                                                border: '1px solid #f59e0b',
                                                fontSize: '11px',
                                              }}
                                            >
                                              <FontAwesomeIcon icon={faTriangleExclamation} /> Negation detected
                                            </span>
                                          )}
                                          {effectiveTargetId && effectiveTargetName && (
                                            <span style={{ fontSize: '12px', color: '#166534' }}>
                                              → <strong>{effectiveTargetName}</strong>
                                              {effectiveTargetUnit ? ` (${effectiveTargetUnit})` : ''}
                                            </span>
                                          )}
                                        </div>

                                        <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }}>
                                          <div className="ems-btn-group" role="group" aria-label={`Preference type for ${tok.raw_token}`}>
                                            <button
                                              type="button"
                                              className={`button ${effectivePrefType === 'prefer' ? 'button-primary' : 'button-secondary'}`}
                                              onClick={() => updateTokenDraftAndSave(item, tok, { preference_type: 'prefer' })}
                                            >
                                              Prefer
                                            </button>
                                            <button
                                              type="button"
                                              className={`button ${effectivePrefType === 'avoid' ? 'button-primary' : 'button-secondary'}`}
                                              onClick={() => updateTokenDraftAndSave(item, tok, { preference_type: 'avoid' })}
                                            >
                                              Avoid
                                            </button>
                                          </div>

                                          <button
                                            type="button"
                                            className="button button-secondary"
                                            onClick={() => openSearchPicker(tokenPickerKey, item.source_signup_id)}
                                          >
                                            <FontAwesomeIcon icon={faMagnifyingGlass} /> Search &amp; override
                                          </button>

                                          <button
                                            type="button"
                                            className="button button-secondary"
                                            onClick={() =>
                                              updateTokenDraftAndSave(item, tok, {
                                                target_signup_id: null,
                                                match_status: 'ignored',
                                                resolved_target_name: null,
                                                resolved_target_unit: null,
                                              })
                                            }
                                          >
                                            No Match
                                          </button>
                                        </div>
                                      </div>

                                      {/* Ambiguous radio candidates */}
                                      {tok.match_status === 'ambiguous' && tok.candidates.length > 0 && (
                                        <div style={{ marginTop: '8px', paddingLeft: '6px' }}>
                                          <div style={{ fontSize: '12px', fontWeight: 600, marginBottom: '4px' }}>
                                            Multiple matches in this bucket — select the right explorer:
                                          </div>
                                          <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
                                            {tok.candidates.map((cand) => (
                                              <label
                                                key={cand.signup_id}
                                                style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px', cursor: 'pointer' }}
                                              >
                                                <input
                                                  type="radio"
                                                  name={`ambiguous-token-${tok.id}`}
                                                  checked={effectiveTargetId === cand.signup_id && effectiveStatus === 'confirmed'}
                                                  onChange={() =>
                                                    updateTokenDraftAndSave(item, tok, {
                                                      target_signup_id: cand.signup_id,
                                                      match_status: 'confirmed',
                                                      resolved_target_name: cand.explorer_name,
                                                      resolved_target_unit: cand.unit_name,
                                                    })
                                                  }
                                                />
                                                <span>
                                                  <strong>{cand.explorer_name}</strong> — {cand.unit_name} ·{' '}
                                                  {cand.shared_code_count} shared expedition code(s)
                                                </span>
                                              </label>
                                            ))}
                                          </div>
                                        </div>
                                      )}

                                      {/* Cross-bucket notice */}
                                      {tok.match_status === 'cross_bucket' && (
                                        <div style={{ marginTop: '6px', fontSize: '12px', color: '#991b1b' }}>
                                          <FontAwesomeIcon icon={faTriangleExclamation} />{' '}
                                          {tok.candidates[0]
                                            ? `Cross-bucket match: ${tok.candidates[0].explorer_name} is signed up for ${formatLevelLabel(
                                                tok.candidates[0].dofe_level
                                              )} ${formatTransportLabel(tok.candidates[0].transport_mode)} — ineligible for same-bucket pairing.`
                                            : 'Cross-bucket match — signed up for a different DofE level or transport mode (ineligible).'}
                                        </div>
                                      )}

                                      {/* Not found notice */}
                                      {tok.match_status === 'not_found' && effectiveStatus === 'not_found' && (
                                        <div style={{ marginTop: '6px', fontSize: '12px', color: '#991b1b' }}>
                                          <FontAwesomeIcon icon={faCircleExclamation} /> No matching sign-up found in this bucket. Use{' '}
                                          <strong>Search &amp; override</strong> to link manually, or mark <strong>No Match</strong>.
                                        </div>
                                      )}

                                      {/* Bucket-scoped Search & override picker */}
                                      {searchOpenKey === tokenPickerKey && (
                                        <div
                                          style={{
                                            marginTop: '8px',
                                            padding: '8px',
                                            background: '#fff',
                                            border: '1px solid #cbd5e1',
                                            borderRadius: '4px',
                                          }}
                                        >
                                          <input
                                            type="text"
                                            className="regular-text"
                                            placeholder={`Search eligible ${formatLevelLabel(item.dofe_level)} ${formatTransportLabel(
                                              item.transport_mode
                                            )} explorers…`}
                                            aria-label={`Search override for ${tok.raw_token}`}
                                            value={searchQuery}
                                            onChange={(e) => {
                                              const q = e.target.value;
                                              setSearchQuery(q);
                                              void runCandidateSearch(item.source_signup_id, q);
                                            }}
                                            style={{ width: '100%', marginBottom: '6px' }}
                                          />
                                          {searchLoading ? (
                                            <div style={{ fontSize: '12px', color: '#64748b' }}>Searching…</div>
                                          ) : searchResults.length === 0 ? (
                                            <div style={{ fontSize: '12px', color: '#64748b' }}>No matching explorers in this bucket.</div>
                                          ) : (
                                            <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', maxHeight: '140px', overflowY: 'auto' }}>
                                              {searchResults.map((cand) => (
                                                <button
                                                  key={cand.signup_id}
                                                  type="button"
                                                  className="button button-link"
                                                  style={{ textAlign: 'left', padding: '2px 4px', fontSize: '12px' }}
                                                  onClick={() => {
                                                    updateTokenDraftAndSave(item, tok, {
                                                      target_signup_id: cand.signup_id,
                                                      match_status: 'confirmed',
                                                      resolved_target_name: cand.explorer_name,
                                                      resolved_target_unit: cand.unit_name,
                                                    });
                                                    setSearchOpenKey(null);
                                                  }}
                                                >
                                                  {cand.explorer_name} ({cand.unit_name}) · {cand.shared_code_count} shared code(s)
                                                </button>
                                              ))}
                                            </div>
                                          )}
                                        </div>
                                      )}
                                    </div>
                                  );
                                })}
                              </div>
                            </div>
                          );
                        })}
                      </div>
                      )}
                    </div>
                    );
                  })}
                </div>
              )}
            </div>
          )}

          {step === 2 && (
            <div>
              {proposalError && (
                <div className="ems-error-notice ems-mb-12" role="alert">
                  <FontAwesomeIcon icon={faCircleExclamation} /> {proposalError}
                </div>
              )}
              {applyError && (
                <div className="ems-error-notice ems-mb-12" role="alert">
                  <FontAwesomeIcon icon={faCircleExclamation} /> {applyError}
                </div>
              )}

              {/* Scope & Weights Controls */}
              <div
                style={{
                  background: '#f6f7f7',
                  border: '1px solid #dcdcde',
                  borderRadius: '4px',
                  padding: '12px',
                  marginBottom: '14px',
                }}
              >
                <div style={{ fontWeight: 600, fontSize: '13px', marginBottom: '8px' }}>Solver Scope</div>
                <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap', marginBottom: '12px' }}>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', cursor: 'pointer' }}>
                    <input
                      type="radio"
                      name="ems-solver-scope"
                      value="bucket"
                      checked={solverScope === 'bucket'}
                      onChange={() => setSolverScope('bucket')}
                      disabled={proposing || applying}
                    />
                    <span>
                      Current filter ({subtitleLevelText} / {subtitleModeText}
                      {typeof queueData?.summary?.bucket_signups_count === 'number'
                        ? ` — ${queueData.summary.bucket_signups_count} explorer${queueData.summary.bucket_signups_count === 1 ? '' : 's'}`
                        : ''}
                      )
                    </span>
                  </label>
                  <label style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', cursor: 'pointer' }}>
                    <input
                      type="radio"
                      name="ems-solver-scope"
                      value="full_season"
                      checked={solverScope === 'full_season'}
                      onChange={() => setSolverScope('full_season')}
                      disabled={proposing || applying}
                    />
                    <span>
                      Full Season (All Buckets
                      {typeof queueData?.summary?.full_season_signups_count === 'number' && queueData.summary.full_season_signups_count > 0
                        ? ` — ${queueData.summary.full_season_signups_count} explorer${queueData.summary.full_season_signups_count === 1 ? '' : 's'}`
                        : ''}
                      )
                    </span>
                  </label>
                </div>

                {/* Target Expeditions to Fill */}
                {selectableExpeditions.length > 0 && (
                  <div className="ems-target-expeditions">
                    <div className="ems-target-expeditions__title">Target Expeditions to Fill</div>
                    {loadingExpeditions ? (
                      <div style={{ fontSize: '12px', color: '#64748b' }}>Loading expeditions…</div>
                    ) : (
                      <div className="ems-target-expeditions__groups">
                        <div>
                          <div className="ems-target-expeditions__stage">Practice</div>
                          <div className="ems-target-expeditions__list">
                            {practiceExpeditions.map((exp) => {
                              const dateStr = formatShortDate(exp.start_date);
                              const checked = includedCodes.includes(exp.event_code);
                              return (
                                <label key={exp.expedition_id} className="ems-target-expeditions__item">
                                  <input
                                    type="checkbox"
                                    checked={checked}
                                    disabled={proposing || applying}
                                    onChange={(e) => {
                                      setIncludedCodes((prev) =>
                                        e.target.checked
                                          ? Array.from(new Set([...prev, exp.event_code]))
                                          : prev.filter((c) => c !== exp.event_code)
                                      );
                                    }}
                                  />
                                  <span>
                                    <strong>{exp.event_code}</strong> ({dateStr ? `${dateStr} · ` : ''}
                                    {exp.available_count} avail)
                                  </span>
                                </label>
                              );
                            })}
                          </div>
                        </div>
                        <div>
                          <div className="ems-target-expeditions__stage">Qualifier</div>
                          <div className="ems-target-expeditions__list">
                            {qualifierExpeditions.map((exp) => {
                              const dateStr = formatShortDate(exp.start_date);
                              const checked = includedCodes.includes(exp.event_code);
                              return (
                                <label key={exp.expedition_id} className="ems-target-expeditions__item">
                                  <input
                                    type="checkbox"
                                    checked={checked}
                                    disabled={proposing || applying}
                                    onChange={(e) => {
                                      setIncludedCodes((prev) =>
                                        e.target.checked
                                          ? Array.from(new Set([...prev, exp.event_code]))
                                          : prev.filter((c) => c !== exp.event_code)
                                      );
                                    }}
                                  />
                                  <span>
                                    <strong>{exp.event_code}</strong> ({dateStr ? `${dateStr} · ` : ''}
                                    {exp.available_count} avail)
                                  </span>
                                </label>
                              );
                            })}
                          </div>
                        </div>
                      </div>
                    )}
                    {expeditionValidationError && (
                      <div className="ems-error-notice" style={{ marginTop: '8px', marginBottom: 0 }} role="alert">
                        <FontAwesomeIcon icon={faTriangleExclamation} /> {expeditionValidationError}
                      </div>
                    )}
                  </div>
                )}

                <div
                  style={{
                    background: '#eff6ff',
                    border: '1px solid #bfdbfe',
                    borderRadius: '4px',
                    padding: '8px 10px',
                    marginBottom: '8px',
                    fontSize: '12px',
                    color: '#1e3a8a',
                  }}
                >
                  <strong>How weights work:</strong> Higher numbers win when goals compete.{' '}
                  <strong>Positive (+)</strong> groups explorers together; <strong>negative (-)</strong> keeps them
                  apart. Weights have no fixed cap—only their relative size matters.
                </div>

                <details className="ems-solver-scale-details">
                  <summary>View relative weighting scale (0 to ±500)</summary>
                  <ul className="ems-solver-scale-details__list">
                    <li>
                      <strong>0</strong> = Rule disabled
                    </li>
                    <li>
                      <strong>±1–10</strong> = Gentle tie-breaker (e.g., <em>Same district</em>: <code>+3</code>,{' '}
                      <em>Same unit</em>: <code>+5</code>, <em>First Aid presence</em>: <code>+5</code>)
                    </li>
                    <li>
                      <strong>±25–50</strong> = Strong preference / presumption (e.g., <em>Mutual wishlist</em>:{' '}
                      <code>+25</code>, <em>Solo unit/district penalty</em>: <code>-30</code>,{' '}
                      <em>Solo gender penalty</em>: <code>-40</code>)
                    </li>
                    <li>
                      <strong>±80–100</strong> = High priority (e.g., <em>Code availability</em>: <code>+80</code>,{' '}
                      <em>Cross-stage continuity</em>: <code>+100</code>)
                    </li>
                    <li>
                      <strong>-500</strong> = Near-hard exclusion (<em>Avoid exclusion</em>: <code>-500</code>)
                    </li>
                  </ul>
                </details>

                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px', flexWrap: 'wrap', gap: '8px' }}>
                  <div style={{ fontWeight: 600, fontSize: '13px' }}>Objective Weights</div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    {weightsSavedMsg && (
                      <span style={{ fontSize: '12px', color: weightsSavedMsg === 'Saved' ? '#166534' : '#991b1b', fontWeight: 600 }}>
                        {weightsSavedMsg}
                      </span>
                    )}
                    <button
                      type="button"
                      className="button button-small button-secondary"
                      onClick={() => void handleSaveWeights()}
                      disabled={savingWeights || proposing || applying}
                    >
                      {savingWeights ? 'Saving…' : 'Save Weights'}
                    </button>
                    <button
                      type="button"
                      className="button button-small"
                      onClick={() => setWeights(DEFAULT_WEIGHTS)}
                      disabled={savingWeights || proposing || applying}
                    >
                      Reset Defaults
                    </button>
                  </div>
                </div>

                {WEIGHT_TIERS.map((tier) => (
                  <div key={tier.title} className="ems-solver-tier">
                    <h4 className="ems-solver-tier__title">{tier.title}</h4>
                    <div className="ems-solver-tier__grid">
                      {tier.fields.map(({ key, label: fieldLabel, description }) => {
                        const inputId = `ems-solver-weight-${key}`;
                        return (
                          <div key={key}>
                            <label htmlFor={inputId} style={{ display: 'block', fontSize: '11px', fontWeight: 600, marginBottom: '2px' }}>
                              {fieldLabel}
                            </label>
                            <input
                              id={inputId}
                              type="number"
                              value={weights[key]}
                              onChange={(e) => {
                                const val = parseInt(e.target.value, 10);
                                setWeights((prev) => ({
                                  ...prev,
                                  [key]: Number.isNaN(val) ? 0 : val,
                                }));
                              }}
                              disabled={proposing || applying}
                              style={{ width: '100%' }}
                            />
                            <div style={{ fontSize: '11px', color: '#475569', marginTop: '2px', lineHeight: 1.3 }}>
                              {description}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                ))}

                <button
                  type="button"
                  className="button button-primary"
                  onClick={handleGenerateProposal}
                  disabled={proposing || applying || expeditionValidationError !== null}
                >
                  {proposing ? (
                    <>
                      <FontAwesomeIcon icon={faSpinner} spin /> Generating Proposal…
                    </>
                  ) : (
                    <>
                      <FontAwesomeIcon icon={faWandMagicSparkles} /> Generate Proposal
                    </>
                  )}
                </button>
              </div>

              {/* Proposal Preview */}
              {proposal && (
                <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                  {/* Stats */}
                  <div
                    style={{
                      display: 'flex',
                      gap: '16px',
                      flexWrap: 'wrap',
                      padding: '10px 12px',
                      background: '#eff6ff',
                      border: '1px solid #bfdbfe',
                      borderRadius: '4px',
                      fontSize: '13px',
                    }}
                  >
                    <span>
                      Explorers: <strong>{proposal.stats.total_explorers}</strong>
                    </span>
                    <span>
                      Proposed Teams: <strong>{proposal.stats.total_teams}</strong>
                    </span>
                    <span>
                      Mutual Pairs Fulfilled:{' '}
                      <strong>
                        {proposal.stats.mutual_pairs_fulfilled}/{proposal.stats.mutual_pairs_total}
                      </strong>
                    </span>
                    <span>
                      First Aid Covered: <strong>{proposal.stats.first_aid_teams_covered}</strong>
                    </span>
                  </div>

                  {/* Warnings */}
                  {proposal.warnings.length > 0 && !warningsDismissed && (
                    <div
                      className="ems-dispatch-alert ems-dispatch-alert--warn"
                      style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '8px' }}
                    >
                      <div style={{ display: 'flex', gap: '8px', alignItems: 'flex-start' }}>
                        <FontAwesomeIcon icon={faTriangleExclamation} style={{ marginTop: '2px' }} />
                        <div>
                          {proposal.warnings.map((w, idx) => (
                            <div key={idx}>{w}</div>
                          ))}
                        </div>
                      </div>
                      <button
                        type="button"
                        className="button button-link"
                        aria-label="Dismiss warnings"
                        onClick={() => setWarningsDismissed(true)}
                        style={{ color: '#92400e', textDecoration: 'none', padding: '0 4px', minHeight: 'auto' }}
                      >
                        <FontAwesomeIcon icon={faXmark} />
                      </button>
                    </div>
                  )}

                  {/* Expedition Capacity */}
                  {proposal.expedition_capacity.length > 0 && (
                    <div>
                      <h3 style={{ fontSize: '13px', margin: '0 0 6px' }}>Expedition Capacity</h3>
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px' }}>
                        {proposal.expedition_capacity.map((cap) => (
                          <div
                            key={cap.expedition_id}
                            style={{
                              padding: '6px 10px',
                              border: '1px solid #cbd5e1',
                              borderRadius: '4px',
                              background: '#fff',
                              fontSize: '12px',
                            }}
                          >
                            <strong>
                              {cap.max_teams !== null
                                ? `${cap.total_teams}/${cap.max_teams} teams on ${cap.event_code}`
                                : `${cap.total_teams} teams on ${cap.event_code}`}
                            </strong>{' '}
                            <span style={{ color: '#64748b' }}>
                              ({cap.stage} · {cap.locked_teams} locked, {cap.proposed_teams} proposed)
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  {/* Proposed Teams */}
                  <div>
                    <h3 style={{ fontSize: '13px', margin: '0 0 6px' }}>
                      Proposed Draft Teams ({proposal.proposed_teams.length})
                    </h3>
                    {proposal.proposed_teams.length === 0 ? (
                      <div style={{ fontSize: '13px', color: '#64748b' }}>
                        No proposed teams were generated for the selected scope.
                      </div>
                    ) : (
                      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: '10px' }}>
                        {proposal.proposed_teams.map((team) => {
                          const maleCount = team.members.filter((m) => m.gender === 'male').length;
                          const femaleCount = team.members.filter((m) => m.gender === 'female').length;
                          const otherCount = team.members.filter((m) => m.gender === 'other').length;
                          const hasGenderData = maleCount > 0 || femaleCount > 0 || otherCount > 0;
                          const genderSummary = hasGenderData
                            ? `${maleCount}M / ${femaleCount}F${otherCount > 0 ? ` / ${otherCount}O` : ''}`
                            : null;

                          return (
                            <div
                              key={team.temp_id}
                              style={{
                                border: '1px solid #cbd5e1',
                                borderRadius: '6px',
                                padding: '10px',
                                background: '#fff',
                              }}
                            >
                              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '4px' }}>
                                <strong style={{ fontSize: '14px' }}>{team.team_name}</strong>
                                <span style={{ fontSize: '11px', color: '#475569' }}>
                                  {team.members.length} members
                                  {genderSummary ? ` · ${genderSummary}` : ''} · {team.friend_match_count} friend match
                                  {team.friend_match_count === 1 ? '' : 'es'}
                                </span>
                              </div>
                            <div style={{ fontSize: '12px', color: '#334155', marginBottom: '6px' }}>
                              Practice: <strong>{team.practice_code || '—'}</strong> · Qualifier:{' '}
                              <strong>{team.qualifier_code || '—'}</strong>
                              {team.has_first_aid && (
                                <span className="ems-matrix-tag ems-matrix-tag--fa" style={{ marginLeft: '6px' }}>
                                  FA
                                </span>
                              )}
                            </div>
                            <ul style={{ margin: 0, paddingLeft: '16px', fontSize: '12px' }}>
                              {team.members.map((m) => (
                                <li key={m.expedition_signup_id}>
                                  {m.name} <span style={{ color: '#64748b' }}>({m.unit_name})</span>
                                  {m.has_first_aid && ' [FA]'}
                                  {m.resolution_status !== 'ok' && (
                                    <span
                                      title="This explorer did not list the assigned expedition code(s) as available. You can resolve or split them after loading draft teams."
                                      style={{
                                        display: 'inline-block',
                                        marginLeft: '6px',
                                        padding: '0 6px',
                                        borderRadius: '999px',
                                        fontSize: '10px',
                                        fontWeight: 600,
                                        background: '#fef3c7',
                                        color: '#92400e',
                                        border: '1px solid #fcd34d',
                                      }}
                                    >
                                      {!m.practice_available && !m.qualifier_available
                                        ? 'Unavailable: Practice & Qualifier'
                                        : !m.practice_available
                                        ? 'Unavailable: Practice'
                                        : !m.qualifier_available
                                        ? 'Unavailable: Qualifier'
                                        : 'Split date'}
                                    </span>
                                  )}
                                </li>
                              ))}
                            </ul>
                          </div>
                          );
                        })}
                      </div>
                    )}
                  </div>

                  {/* Exceptions & Compromises */}
                  {(proposal.compromises.date_conflict_pending.length > 0 ||
                    proposal.compromises.split_date.length > 0 ||
                    proposal.compromises.unfulfilled_mutual_pairs.length > 0 ||
                    (proposal.compromises.solo_gender_teams?.length ?? 0) > 0) && (
                    <div
                      style={{
                        border: '1px solid #fcd34d',
                        background: '#fffbeb',
                        borderRadius: '6px',
                        padding: '10px 12px',
                        fontSize: '12px',
                      }}
                    >
                      <div style={{ fontWeight: 600, marginBottom: '6px', color: '#92400e' }}>
                        <FontAwesomeIcon icon={faTriangleExclamation} /> Exceptions &amp; Compromises
                      </div>
                      {proposal.compromises.date_conflict_pending.length > 0 && (
                        <div style={{ marginBottom: '4px' }}>
                          <strong>Date Conflict Pending:</strong>{' '}
                          {proposal.compromises.date_conflict_pending
                            .map((c) => `${c.explorer_name} (${c.team_name} · ${c.stage} ${c.event_code})`)
                            .join('; ')}
                        </div>
                      )}
                      {proposal.compromises.split_date.length > 0 && (
                        <div style={{ marginBottom: '4px' }}>
                          <strong>Split Date:</strong>{' '}
                          {proposal.compromises.split_date
                            .map((s) => `${s.explorer_name} (${s.practice_team} / ${s.qualifier_team})`)
                            .join('; ')}
                        </div>
                      )}
                      {proposal.compromises.unfulfilled_mutual_pairs.length > 0 && (
                        <div style={{ marginBottom: '4px' }}>
                          <strong>Unfulfilled Mutual Pairs:</strong>{' '}
                          {proposal.compromises.unfulfilled_mutual_pairs
                            .map((p) => `${p.source_name} ↔ ${p.target_name}`)
                            .join('; ')}
                        </div>
                      )}
                      {(proposal.compromises.solo_gender_teams?.length ?? 0) > 0 && (
                        <div>
                          <strong>Solo Gender Teams:</strong>{' '}
                          {proposal.compromises.solo_gender_teams!
                            .map(
                              (t) =>
                                `${t.team_name} (${t.stage}: ${t.explorer_name} — solo ${
                                  t.solo_gender === 'male' ? 'male' : 'female'
                                })`
                            )
                            .join('; ')}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>

        {/* Footer */}
        <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            flexWrap: 'wrap',
            gap: '10px',
            padding: '12px 16px',
            borderTop: '1px solid #e2e8f0',
            background: '#f8fafc',
          }}
        >
          {step === 1 ? (
            <>
              <button type="button" className="button button-secondary" onClick={onClose} disabled={savingStep1}>
                Cancel
              </button>
              <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                <button
                  type="button"
                  className="button button-primary"
                  onClick={handleProceedToStep2}
                  disabled={loadingQueue || savingStep1}
                >
                  {savingStep1 ? (
                    <>
                      <FontAwesomeIcon icon={faSpinner} spin /> Saving…
                    </>
                  ) : (
                    'Proceed to Step 2 →'
                  )}
                </button>
              </div>
            </>
          ) : (
            <>
              <button
                type="button"
                className="button button-secondary"
                onClick={() => setStep(1)}
                disabled={proposing || applying}
              >
                ← Back to Step 1
              </button>
              <div style={{ display: 'flex', gap: '12px', alignItems: 'center', flexWrap: 'wrap' }}>
                <label style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '12px', cursor: 'pointer' }}>
                  <input
                    type="checkbox"
                    checked={replaceExistingDrafts}
                    onChange={(e) => setReplaceExistingDrafts(e.target.checked)}
                    disabled={applying}
                  />
                  <span>Replace existing draft teams in scope</span>
                </label>
                <button
                  type="button"
                  className="button button-primary"
                  onClick={handleLoadDraftTeams}
                  disabled={!proposal || proposal.proposed_teams.length === 0 || proposing || applying}
                >
                  {applying ? (
                    <>
                      <FontAwesomeIcon icon={faSpinner} spin /> Loading Drafts…
                    </>
                  ) : (
                    'Load as Draft Teams'
                  )}
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default AutoProposeTeamsModal;
