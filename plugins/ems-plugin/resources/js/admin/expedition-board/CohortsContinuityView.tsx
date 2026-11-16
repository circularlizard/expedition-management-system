import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faPlus,
  faCheck,
  faTriangleExclamation,
  faMagnifyingGlass,
  faUsers,
  faEnvelope,
  faTrash,
  faXmark,
  faFirstAid,
  faLink,
  faUserSlash,
} from '@fortawesome/free-solid-svg-icons';
import { getUnitDistrictMap, getUniqueDistricts, normalize } from '../signups-board/tableUtils';
import {
  Cohort,
  CohortMember,
  MatrixEvent,
  MatrixExplorer,
  MemberResolutionAction,
  NotifyModalOptions,
  ScheduleCandidate,
  formatDateShort,
  fuzzyMatchExplorer,
  hasValidFirstAid,
  isWishlistMatch,
  parseWishlistTokens,
  toCohortTransportMode,
} from './cohortTypes';

export interface CohortsContinuityViewProps {
  level: 'bronze' | 'silver' | 'gold';
  transport: string;
  rootUrl: string;
  nonce: string;
  units: any[];
  cohorts: Cohort[];
  practiceEvents: MatrixEvent[];
  qualifierEvents: MatrixEvent[];
  practiceExplorers: MatrixExplorer[];
  qualifierExplorers: MatrixExplorer[];
  onRefreshAll: () => Promise<void>;
  onFeedback: (fb: { ok: boolean; msg: string } | null) => void;
  onViewAsn?: (scoutId: number) => void;
  onOpenNotifyModal?: (opts: NotifyModalOptions) => void;
}

interface HoveredExplorerInfo {
  expedition_signup_id: number;
  first_name: string;
  last_name: string;
  wishlist: string;
  practiceAvail: string[];
  qualifierAvail: string[];
}

interface PendingScheduleDraft {
  practice_expedition_id: number | null;
  qualifier_expedition_id: number | null;
  resolutions: Record<number, MemberResolutionAction>;
}

function extractWishlistFromPrefs(prefsJson: string | null): string {
  if (!prefsJson) return '';
  try {
    const parsed = JSON.parse(prefsJson);
    return typeof parsed?.exped_team_names === 'string' ? parsed.exped_team_names : '';
  } catch {
    return '';
  }
}

function getNotificationBadge(status?: string): { label: string; bg: string; color: string; border: string } {
  const s = (status || 'draft').toLowerCase();
  if (s === 'notified') {
    return { label: 'Notified', bg: '#ecfdf5', color: '#065f46', border: '#a7f3d0' };
  }
  if (s === 're_notify_needed') {
    return { label: 'Re-Notify Needed', bg: '#fef2f2', color: '#991b1b', border: '#fecaca' };
  }
  if (s === 'ready_to_notify') {
    return { label: 'Ready', bg: '#eff6ff', color: '#1e40af', border: '#bfdbfe' };
  }
  return { label: 'Draft', bg: '#f1f5f9', color: '#475569', border: '#cbd5e1' };
}

export default function CohortsContinuityView({
  level,
  transport,
  rootUrl,
  nonce,
  units,
  cohorts,
  practiceEvents,
  qualifierEvents,
  practiceExplorers,
  qualifierExplorers,
  onRefreshAll,
  onFeedback,
  onViewAsn,
  onOpenNotifyModal,
}: CohortsContinuityViewProps) {
  const unitDistrictMap = useMemo(() => getUnitDistrictMap(units), [units]);

  // ── Left Pool Filters ──────────────────────────────────────────────────────
  const [searchQuery, setSearchQuery] = useState('');
  const [districtFilter, setDistrictFilter] = useState('all');
  const [unitFilter, setUnitFilter] = useState('all');
  const [asnFilter, setAsnFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState<'pool' | 'all' | 'split' | 'in_cohort'>('pool');
  const [groupByUnit, setGroupByUnit] = useState(false);
  const [hideAllocated, setHideAllocated] = useState(true);

  // ── Multi-select & Create Cohort ───────────────────────────────────────────
  const [selectedSignupIds, setSelectedSignupIds] = useState<number[]>([]);
  const [expandedSelectedIds, setExpandedSelectedIds] = useState<number[]>([]);
  const [newCohortName, setNewCohortName] = useState('');
  const [creatingCohort, setCreatingCohort] = useState(false);
  const [busyAction, setBusyAction] = useState(false);
  const [mergingCohortId, setMergingCohortId] = useState<number | null>(null);
  const [mergeTargetId, setMergeTargetId] = useState<number | ''>('');

  // ── Wishlist Hover Mutual-Highlighting ─────────────────────────────────────
  const [hoveredExplorer, setHoveredExplorer] = useState<HoveredExplorerInfo | null>(null);

  // ── Candidate Scores per Cohort ────────────────────────────────────────────
  const [candidatesByCohort, setCandidatesByCohort] = useState<Record<number, ScheduleCandidate[]>>({});
  const [loadingCandidates, setLoadingCandidates] = useState<Record<number, boolean>>({});
  const [pendingDrafts, setPendingDrafts] = useState<Record<number, PendingScheduleDraft>>({});

  // Merge practice & qualifier explorers keyed by expedition_signup_id
  const mergedExplorers = useMemo(() => {
    const map = new Map<
      number,
      {
        expedition_signup_id: number;
        scout_id: number;
        first_name: string;
        last_name: string;
        unit_name: string;
        district: string;
        team_preferences: string;
        practiceAvail: string[];
        qualifierAvail: string[];
        has_asn: boolean;
        additional_support_needs: string;
        asn_validated: number | null;
        first_aid_status: string;
        first_aid_expiry: string | null;
      }
    >();

    for (const exp of practiceExplorers) {
      const id = exp.expedition_signup_id;
      if (!id) continue;
      map.set(id, {
        expedition_signup_id: id,
        scout_id: exp.scout_id,
        first_name: exp.first_name,
        last_name: exp.last_name,
        unit_name: exp.unit_name || 'Unassigned',
        district: exp.district || '',
        team_preferences: exp.team_preferences || '',
        practiceAvail: exp.available_events || [],
        qualifierAvail: [],
        has_asn: exp.has_asn,
        additional_support_needs: exp.additional_support_needs || '',
        asn_validated: exp.asn_validated ?? null,
        first_aid_status: exp.first_aid_status || 'none',
        first_aid_expiry: exp.first_aid_expiry ?? null,
      });
    }

    for (const exp of qualifierExplorers) {
      const id = exp.expedition_signup_id;
      if (!id) continue;
      const existing = map.get(id);
      if (existing) {
        existing.qualifierAvail = exp.available_events || [];
      } else {
        map.set(id, {
          expedition_signup_id: id,
          scout_id: exp.scout_id,
          first_name: exp.first_name,
          last_name: exp.last_name,
          unit_name: exp.unit_name || 'Unassigned',
          district: exp.district || '',
          team_preferences: exp.team_preferences || '',
          practiceAvail: [],
          qualifierAvail: exp.available_events || [],
          has_asn: exp.has_asn,
          additional_support_needs: exp.additional_support_needs || '',
          asn_validated: exp.asn_validated ?? null,
          first_aid_status: exp.first_aid_status || 'none',
          first_aid_expiry: exp.first_aid_expiry ?? null,
        });
      }
    }

    return Array.from(map.values());
  }, [practiceExplorers, qualifierExplorers]);

  // Lookup cohort membership by expedition_signup_id
  const membershipBySignupId = useMemo(() => {
    const map = new Map<number, { cohort: Cohort; member: CohortMember }>();
    for (const c of cohorts) {
      for (const m of c.members || []) {
        map.set(m.expedition_signup_id, { cohort: c, member: m });
      }
    }
    return map;
  }, [cohorts]);

  const uniqueDistricts = useMemo(
    () => getUniqueDistricts(units, mergedExplorers, unitDistrictMap),
    [units, mergedExplorers, unitDistrictMap]
  );

  const poolUnits = useMemo(() => {
    const set = new Set<string>();
    mergedExplorers.forEach(e => {
      if (e.unit_name) set.add(e.unit_name);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [mergedExplorers]);

  // Fetch candidate scores for all visible cohorts
  const fetchCandidatesForCohort = useCallback(
    async (cohortId: number) => {
      setLoadingCandidates(prev => ({ ...prev, [cohortId]: true }));
      try {
        const resp = await fetch(`${rootUrl}/cohorts/${cohortId}/schedule`, {
          headers: { 'X-WP-Nonce': nonce },
        });
        if (resp.ok) {
          const data = await resp.json();
          if (Array.isArray(data)) {
            setCandidatesByCohort(prev => ({ ...prev, [cohortId]: data }));
          }
        }
      } catch {
        // Non-fatal: dropdown falls back to raw events
      } finally {
        setLoadingCandidates(prev => ({ ...prev, [cohortId]: false }));
      }
    },
    [rootUrl, nonce]
  );

  useEffect(() => {
    cohorts.forEach(c => {
      fetchCandidatesForCohort(c.id);
    });
  }, [cohorts, fetchCandidatesForCohort]);

  // Filter & sort left pool (pin split-date explorers at top)
  const filteredPool = useMemo(() => {
    const filtered = mergedExplorers.filter(exp => {
      const membership = membershipBySignupId.get(exp.expedition_signup_id);
      const memberStatus = membership?.member.member_status || '';
      const isSplit = memberStatus === 'split_practice' || memberStatus === 'split_qualifier';
      const isAllocated = Boolean(membership) && !isSplit;

      if (statusFilter === 'split' && !isSplit) return false;
      if (statusFilter === 'in_cohort' && !membership) return false;
      if (hideAllocated && isAllocated && statusFilter !== 'in_cohort') return false;

      if (districtFilter !== 'all') {
        const itemDistrict = (exp.district || unitDistrictMap[exp.unit_name] || '').trim() || 'Unassigned';
        if (normalize(itemDistrict) !== normalize(districtFilter)) return false;
      }

      if (unitFilter !== 'all' && exp.unit_name !== unitFilter) return false;

      if (asnFilter !== 'all') {
        const hasNeeds = Boolean(exp.additional_support_needs && exp.additional_support_needs.trim() !== '');
        if (asnFilter === 'has_asn' && !hasNeeds) return false;
        if (asnFilter === 'action_needed' && (!hasNeeds || exp.asn_validated !== 1)) return false;
        if (asnFilter === 'no_action' && (!hasNeeds || exp.asn_validated !== 0)) return false;
        if (asnFilter === 'unreviewed' && (!hasNeeds || (exp.asn_validated !== null && exp.asn_validated !== undefined))) {
          return false;
        }
      }

      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase().trim();
        const full = `${exp.first_name} ${exp.last_name}`.toLowerCase();
        const wish = exp.team_preferences.toLowerCase();
        const unit = exp.unit_name.toLowerCase();
        if (!full.includes(q) && !wish.includes(q) && !unit.includes(q)) return false;
      }

      return true;
    });

    return filtered.sort((a, b) => {
      const aMem = membershipBySignupId.get(a.expedition_signup_id)?.member.member_status || '';
      const bMem = membershipBySignupId.get(b.expedition_signup_id)?.member.member_status || '';
      const aSplit = aMem === 'split_practice' || aMem === 'split_qualifier' ? 0 : 1;
      const bSplit = bMem === 'split_practice' || bMem === 'split_qualifier' ? 0 : 1;
      if (aSplit !== bSplit) return aSplit - bSplit;
      return `${a.first_name} ${a.last_name}`.localeCompare(`${b.first_name} ${b.last_name}`);
    });
  }, [mergedExplorers, membershipBySignupId, statusFilter, hideAllocated, districtFilter, unitFilter, asnFilter, searchQuery, unitDistrictMap]);

  const explorerLookupList = useMemo(
    () =>
      mergedExplorers.map(e => ({
        id: e.expedition_signup_id,
        name: `${e.first_name} ${e.last_name}`.trim(),
      })),
    [mergedExplorers]
  );

  const matchedIdsBySignup = useMemo(() => {
    const map = new Map<number, number[]>();
    for (const exp of mergedExplorers) {
      const matched = new Set<number>();
      for (const token of parseWishlistTokens(exp.team_preferences)) {
        const hit = fuzzyMatchExplorer(token, explorerLookupList, exp.expedition_signup_id);
        if (hit) matched.add(hit.id);
      }
      for (const other of mergedExplorers) {
        if (other.expedition_signup_id === exp.expedition_signup_id) continue;
        if (
          isWishlistMatch(
            exp.first_name,
            exp.last_name,
            exp.team_preferences,
            other.first_name,
            other.last_name,
            other.team_preferences
          )
        ) {
          matched.add(other.expedition_signup_id);
        }
      }
      map.set(exp.expedition_signup_id, Array.from(matched));
    }
    return map;
  }, [mergedExplorers, explorerLookupList]);

  const selectExplorerAndMatches = (signupId: number) => {
    const matches = matchedIdsBySignup.get(signupId) || [];
    const toSelect = [signupId, ...matches];
    setSelectedSignupIds(prev => Array.from(new Set([...prev, ...toSelect])));
    setExpandedSelectedIds(prev => prev.filter(id => !toSelect.includes(id)));
  };

  const toggleExpandedSelected = (signupId: number) => {
    setExpandedSelectedIds(prev =>
      prev.includes(signupId) ? prev.filter(id => id !== signupId) : [...prev, signupId]
    );
  };

  const groupedPool = useMemo(() => {
    if (!groupByUnit) {
      return [['', filteredPool]] as Array<[string, typeof filteredPool]>;
    }
    const groups = new Map<string, typeof filteredPool>();
    for (const exp of filteredPool) {
      const key = exp.unit_name || 'Unassigned Unit';
      const list = groups.get(key) || [];
      list.push(exp);
      groups.set(key, list);
    }
    return Array.from(groups.entries()).sort((a, b) => a[0].localeCompare(b[0]));
  }, [filteredPool, groupByUnit]);

  const toggleSelectSignup = (signupId: number) => {
    setSelectedSignupIds(prev =>
      prev.includes(signupId) ? prev.filter(id => id !== signupId) : [...prev, signupId]
    );
  };

  const handleCreateCohort = async () => {
    if (selectedSignupIds.length === 0) return;
    const trimmedName = newCohortName.trim() || `Team ${cohorts.length + 1}`;
    setCreatingCohort(true);
    onFeedback(null);

    try {
      const resp = await fetch(`${rootUrl}/cohorts`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({
          name: trimmedName,
          dofe_level: level,
          transport_mode: toCohortTransportMode(transport),
          expedition_signup_ids: selectedSignupIds,
        }),
      });
      const data = await resp.json();
      if (!resp.ok) {
        throw new Error(data?.message || `HTTP ${resp.status}`);
      }
      setSelectedSignupIds([]);
      setNewCohortName('');
      onFeedback({ ok: true, msg: `Created team "${trimmedName}" with ${selectedSignupIds.length} explorer(s).` });
      await onRefreshAll();
    } catch (err) {
      onFeedback({ ok: false, msg: err instanceof Error ? err.message : 'Failed to create team' });
    } finally {
      setCreatingCohort(false);
    }
  };

  const handleAddSelectedToCohort = async (cohortId: number, cohortName: string) => {
    if (selectedSignupIds.length === 0) return;
    setBusyAction(true);
    onFeedback(null);
    try {
      const resp = await fetch(`${rootUrl}/cohorts/${cohortId}/members`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({ expedition_signup_ids: selectedSignupIds }),
      });
      const data = await resp.json();
      if (!resp.ok) {
        throw new Error(data?.message || `HTTP ${resp.status}`);
      }
      setSelectedSignupIds([]);
      onFeedback({ ok: true, msg: `Added selected explorer(s) to "${cohortName}".` });
      await onRefreshAll();
    } catch (err) {
      onFeedback({ ok: false, msg: err instanceof Error ? err.message : 'Failed to add members' });
    } finally {
      setBusyAction(false);
    }
  };

  const handleRemoveMember = async (cohortId: number, signupId: number, memberName: string) => {
    setBusyAction(true);
    onFeedback(null);
    try {
      const resp = await fetch(`${rootUrl}/cohorts/${cohortId}/members/${signupId}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': nonce },
      });
      const data = await resp.json();
      if (!resp.ok) {
        throw new Error(data?.message || `HTTP ${resp.status}`);
      }
      onFeedback({ ok: true, msg: `Removed ${memberName} from team.` });
      await onRefreshAll();
    } catch (err) {
      onFeedback({ ok: false, msg: err instanceof Error ? err.message : 'Failed to remove member' });
    } finally {
      setBusyAction(false);
    }
  };

  const handleDeleteCohort = async (cohort: Cohort) => {
    if (!window.confirm(`Delete team "${cohort.name}"? Members will return to the unassigned pool.`)) {
      return;
    }
    setBusyAction(true);
    onFeedback(null);
    try {
      const resp = await fetch(`${rootUrl}/cohorts/${cohort.id}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': nonce },
      });
      const data = await resp.json();
      if (!resp.ok) {
        throw new Error(data?.message || `HTTP ${resp.status}`);
      }
      onFeedback({ ok: true, msg: `Deleted team "${cohort.name}".` });
      await onRefreshAll();
    } catch (err) {
      onFeedback({ ok: false, msg: err instanceof Error ? err.message : 'Failed to delete team' });
    } finally {
      setBusyAction(false);
    }
  };

  const handleMergeCohort = async (sourceCohort: Cohort) => {
    if (!mergeTargetId || Number(mergeTargetId) === sourceCohort.id) return;
    const targetCohort = cohorts.find(c => c.id === Number(mergeTargetId));
    if (!targetCohort) return;
    if (
      !window.confirm(
        `Merge "${sourceCohort.name}" (${sourceCohort.members.length} members) into "${targetCohort.name}"? "${sourceCohort.name}" will be removed.`
      )
    ) {
      return;
    }
    setBusyAction(true);
    onFeedback(null);
    try {
      const resp = await fetch(`${rootUrl}/cohorts/${sourceCohort.id}/merge`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({ target_cohort_id: targetCohort.id }),
      });
      const data = await resp.json();
      if (!resp.ok) {
        throw new Error(data?.message || `HTTP ${resp.status}`);
      }
      setMergingCohortId(null);
      setMergeTargetId('');
      onFeedback({
        ok: true,
        msg: `Merged "${sourceCohort.name}" into "${targetCohort.name}" (${data?.moved_count ?? 0} member(s) moved).`,
      });
      await onRefreshAll();
    } catch (err) {
      onFeedback({ ok: false, msg: err instanceof Error ? err.message : 'Failed to merge teams' });
    } finally {
      setBusyAction(false);
    }
  };

  const executeScheduleCohort = async (
    cohortId: number,
    practiceId: number | null,
    qualifierId: number | null,
    resolutions: Record<number, MemberResolutionAction>
  ) => {
    setBusyAction(true);
    onFeedback(null);
    try {
      const resp = await fetch(`${rootUrl}/cohorts/${cohortId}/schedule`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({
          practice_expedition_id: practiceId,
          qualifier_expedition_id: qualifierId,
          member_resolutions: resolutions,
        }),
      });
      const data = await resp.json();
      if (!resp.ok) {
        throw new Error(data?.message || `HTTP ${resp.status}`);
      }
      setPendingDrafts(prev => {
        const next = { ...prev };
        delete next[cohortId];
        return next;
      });
      onFeedback({ ok: true, msg: 'Team schedule applied successfully.' });
      await onRefreshAll();
      await fetchCandidatesForCohort(cohortId);
    } catch (err) {
      onFeedback({ ok: false, msg: err instanceof Error ? err.message : 'Failed to apply schedule' });
    } finally {
      setBusyAction(false);
    }
  };

  const handleStageSelectChange = (
    cohort: Cohort,
    stage: 'practice' | 'qualifier',
    expeditionIdStr: string
  ) => {
    const newId = expeditionIdStr ? parseInt(expeditionIdStr, 10) : null;
    const currentDraft = pendingDrafts[cohort.id];
    const nextPracticeId =
      stage === 'practice'
        ? newId
        : currentDraft
          ? currentDraft.practice_expedition_id
          : cohort.practice_expedition_id;
    const nextQualifierId =
      stage === 'qualifier'
        ? newId
        : currentDraft
          ? currentDraft.qualifier_expedition_id
          : cohort.qualifier_expedition_id;

    const candidates = candidatesByCohort[cohort.id] || [];
    const pracCand = candidates.find(c => c.expedition_id === nextPracticeId);
    const qualCand = candidates.find(c => c.expedition_id === nextQualifierId);

    const unavailableIds = new Set<number>();
    (pracCand?.unavailable_members || []).forEach(u => unavailableIds.add(u.expedition_signup_id));
    (qualCand?.unavailable_members || []).forEach(u => unavailableIds.add(u.expedition_signup_id));

    if (unavailableIds.size === 0) {
      // 100% available: apply immediately
      executeScheduleCohort(cohort.id, nextPracticeId, nextQualifierId, {});
    } else {
      // < 100% available: open inline Partial-Availability Resolution Drawer
      const resolutions: Record<number, MemberResolutionAction> = {};
      unavailableIds.forEach(uid => {
        resolutions[uid] = currentDraft?.resolutions[uid] || 'assign_pending';
      });
      setPendingDrafts(prev => ({
        ...prev,
        [cohort.id]: {
          practice_expedition_id: nextPracticeId,
          qualifier_expedition_id: nextQualifierId,
          resolutions,
        },
      }));
    }
  };

  const getCompatibilityInfo = (
    targetSignupId: number,
    targetFirst: string,
    targetLast: string,
    targetWishlist: string,
    targetPracAvail: string[],
    targetQualAvail: string[]
  ): { isMatch: boolean; compatibleDates: boolean } => {
    if (!hoveredExplorer || hoveredExplorer.expedition_signup_id === targetSignupId) {
      return { isMatch: false, compatibleDates: false };
    }
    const isMatch = isWishlistMatch(
      hoveredExplorer.first_name,
      hoveredExplorer.last_name,
      hoveredExplorer.wishlist,
      targetFirst,
      targetLast,
      targetWishlist
    );
    if (!isMatch) return { isMatch: false, compatibleDates: false };

    const pracOk =
      hoveredExplorer.practiceAvail.length === 0 ||
      targetPracAvail.length === 0 ||
      hoveredExplorer.practiceAvail.some(d => targetPracAvail.includes(d));
    const qualOk =
      hoveredExplorer.qualifierAvail.length === 0 ||
      targetQualAvail.length === 0 ||
      hoveredExplorer.qualifierAvail.some(d => targetQualAvail.includes(d));

    return { isMatch: true, compatibleDates: pracOk && qualOk };
  };

  return (
    <div style={{ display: 'flex', gap: '16px', alignItems: 'flex-start' }}>
      {/* ── LEFT COLUMN (38%): Unassigned & Split Pool ── */}
      <div className="ems-panel" style={{ flex: '0 0 38%', minWidth: 0, padding: '14px' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' }}>
          <h3 style={{ margin: 0, fontSize: '14px', fontWeight: 600 }}>
            Unassigned &amp; Split Pool ({filteredPool.length})
          </h3>
          {selectedSignupIds.length > 0 && (
            <button
              type="button"
              className="button button-small"
              onClick={() => setSelectedSignupIds([])}
            >
              Clear ({selectedSignupIds.length})
            </button>
          )}
        </div>

        {/* Search & Filters */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginBottom: '12px' }}>
          <div className="ems-search-box" style={{ width: '100%', position: 'relative' }}>
            <span className="ems-search-icon" aria-hidden="true">
              <FontAwesomeIcon icon={faMagnifyingGlass} />
            </span>
            <input
              type="text"
              className="ems-form-input ems-search-input"
              style={{ width: '100%', paddingRight: searchQuery ? '28px' : undefined }}
              placeholder="Search name, wishlist, or unit..."
              value={searchQuery}
              onChange={e => setSearchQuery(e.target.value)}
            />
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                aria-label="Clear search"
                title="Clear search"
                style={{
                  position: 'absolute',
                  right: '6px',
                  top: '50%',
                  transform: 'translateY(-50%)',
                  background: 'transparent',
                  border: 'none',
                  color: '#64748b',
                  cursor: 'pointer',
                  padding: '2px 4px',
                }}
              >
                <FontAwesomeIcon icon={faXmark} />
              </button>
            )}
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '6px' }}>
            <select
              className="ems-select-sm"
              aria-label="Filter by District"
              value={districtFilter}
              onChange={e => setDistrictFilter(e.target.value)}
            >
              <option value="all">All Districts</option>
              {uniqueDistricts.map(d => (
                <option key={d} value={d}>{d}</option>
              ))}
              <option value="unassigned">Unassigned</option>
            </select>

            <select
              className="ems-select-sm"
              aria-label="Filter by Unit"
              value={unitFilter}
              onChange={e => setUnitFilter(e.target.value)}
            >
              <option value="all">All Units ({poolUnits.length})</option>
              {poolUnits.map(u => (
                <option key={u} value={u}>{u}</option>
              ))}
            </select>

            <select
              className="ems-select-sm"
              aria-label="Filter by ASN"
              value={asnFilter}
              onChange={e => setAsnFilter(e.target.value)}
            >
              <option value="all">All ASN</option>
              <option value="has_asn">Declared ASN</option>
              <option value="unreviewed">Unreviewed</option>
              <option value="action_needed">Action Needed</option>
              <option value="no_action">No Action Needed</option>
            </select>

            <select
              className="ems-select-sm"
              aria-label="Filter by Pool Status"
              value={statusFilter}
              onChange={e => setStatusFilter(e.target.value as 'pool' | 'all' | 'split' | 'in_cohort')}
            >
              <option value="pool">Unassigned &amp; Split</option>
              <option value="split">Split Date Only</option>
              <option value="in_cohort">In Team</option>
              <option value="all">All Explorers</option>
            </select>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: '12px', fontSize: '12px', color: '#475569' }}>
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', cursor: 'pointer' }}>
              <input
                type="checkbox"
                checked={groupByUnit}
                onChange={e => setGroupByUnit(e.target.checked)}
              />
              Group by Unit
            </label>
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', cursor: 'pointer' }}>
              <input
                type="checkbox"
                checked={hideAllocated}
                onChange={e => setHideAllocated(e.target.checked)}
              />
              Hide allocated
            </label>
          </div>
        </div>

        {/* Bulk Create Cohort Bar */}
        {selectedSignupIds.length > 0 && (
          <div
            style={{
              background: '#eff6ff',
              border: '1px solid #bfdbfe',
              borderRadius: '6px',
              padding: '10px',
              marginBottom: '12px',
              display: 'flex',
              flexDirection: 'column',
              gap: '8px',
            }}
          >
            <div style={{ fontSize: '12px', fontWeight: 600, color: '#1e40af' }}>
              {selectedSignupIds.length} explorer{selectedSignupIds.length > 1 ? 's' : ''} selected
            </div>
            <div style={{ display: 'flex', gap: '6px' }}>
              <input
                type="text"
                className="ems-form-input"
                style={{ flex: 1, minWidth: 0 }}
                placeholder={`Team ${cohorts.length + 1}`}
                value={newCohortName}
                onChange={e => setNewCohortName(e.target.value)}
              />
              <button
                type="button"
                className="button button-primary"
                disabled={creatingCohort}
                onClick={handleCreateCohort}
              >
                <FontAwesomeIcon icon={faPlus} />{' '}
                {creatingCohort ? 'Creating…' : 'Create Team from Selected'}
              </button>
            </div>
          </div>
        )}

        {/* Pool List */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', maxHeight: '72vh', overflowY: 'auto' }}>
          {filteredPool.length === 0 ? (
            <div className="notice notice-info ems-m-0">
              <p>No explorers match the current pool filters.</p>
            </div>
          ) : (
            groupedPool.map(([unitLabel, groupExplorers]) => (
              <React.Fragment key={unitLabel || '__all'}>
                {groupByUnit && unitLabel && (
                  <div
                    style={{
                      position: 'sticky',
                      top: 0,
                      zIndex: 1,
                      background: '#f1f5f9',
                      border: '1px solid #cbd5e1',
                      borderRadius: '4px',
                      padding: '4px 8px',
                      fontSize: '11px',
                      fontWeight: 700,
                      color: '#334155',
                      display: 'flex',
                      justifyContent: 'space-between',
                    }}
                  >
                    <span>{unitLabel}</span>
                    <span>{groupExplorers.length}</span>
                  </div>
                )}
                {groupExplorers.map(exp => {
                  const isSelected = selectedSignupIds.includes(exp.expedition_signup_id);
                  const isExpanded = expandedSelectedIds.includes(exp.expedition_signup_id);
                  const membership = membershipBySignupId.get(exp.expedition_signup_id);
                  const memberStatus = membership?.member.member_status || '';
                  const isSplitPrac = memberStatus === 'split_practice';
                  const isSplitQual = memberStatus === 'split_qualifier';
                  const isAllocated = Boolean(membership) && !isSplitPrac && !isSplitQual;
                  const isCollapsed = (isSelected || isAllocated) && !isExpanded;
                  const isFA = hasValidFirstAid(null, exp.first_aid_status, exp.first_aid_expiry, null);
                  const hasAsnText = Boolean(exp.additional_support_needs && exp.additional_support_needs.trim() !== '');
                  const wishlistTokens = parseWishlistTokens(exp.team_preferences);
                  const matchedIds = matchedIdsBySignup.get(exp.expedition_signup_id) || [];

                  const compat = getCompatibilityInfo(
                    exp.expedition_signup_id,
                    exp.first_name,
                    exp.last_name,
                    exp.team_preferences,
                    exp.practiceAvail,
                    exp.qualifierAvail
                  );

                  return (
                    <div
                      key={exp.expedition_signup_id}
                      onMouseEnter={() =>
                        setHoveredExplorer({
                          expedition_signup_id: exp.expedition_signup_id,
                          first_name: exp.first_name,
                          last_name: exp.last_name,
                          wishlist: exp.team_preferences,
                          practiceAvail: exp.practiceAvail,
                          qualifierAvail: exp.qualifierAvail,
                        })
                      }
                      onMouseLeave={() => setHoveredExplorer(null)}
                      title={
                        compat.isMatch
                          ? compat.compatibleDates
                            ? 'Wishlist match with compatible dates'
                            : 'Wishlist match (date conflict)'
                          : undefined
                      }
                      style={{
                        border: isSplitPrac || isSplitQual
                          ? '1px solid #f59e0b'
                          : isAllocated
                            ? '1px solid #bbf7d0'
                            : isSelected
                              ? '1px solid #93c5fd'
                              : '1px solid #cbd5e1',
                        boxShadow: compat.isMatch
                          ? `0 0 0 2px ${compat.compatibleDates ? '#2563eb' : '#f59e0b'}`
                          : undefined,
                        background: compat.isMatch
                          ? '#eff6ff'
                          : isAllocated
                            ? '#f0fdf4'
                            : isSelected
                              ? '#f0f9ff'
                              : '#ffffff',
                        borderRadius: '6px',
                        padding: isCollapsed ? '6px 8px' : '10px',
                        transition: 'background 0.15s, box-shadow 0.15s',
                      }}
                    >
                      <div style={{ display: 'flex', alignItems: 'flex-start', gap: '8px' }}>
                        {isAllocated ? (
                          <span
                            title={`Allocated to ${membership?.cohort.name}`}
                            aria-label={`${exp.first_name} ${exp.last_name} allocated to ${membership?.cohort.name}`}
                            style={{
                              color: '#15803d',
                              marginTop: '2px',
                              fontSize: '13px',
                              width: '16px',
                              textAlign: 'center',
                              flexShrink: 0,
                            }}
                          >
                            <FontAwesomeIcon icon={faCheck} />
                          </span>
                        ) : (
                          <input
                            type="checkbox"
                            className="ems-checkbox"
                            checked={isSelected}
                            onChange={() => toggleSelectSignup(exp.expedition_signup_id)}
                            aria-label={`Select ${exp.first_name} ${exp.last_name}`}
                            style={{ marginTop: '2px' }}
                          />
                        )}
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '6px' }}>
                            <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '4px', minWidth: 0 }}>
                              <strong style={{ fontSize: '13px', color: '#0f172a' }}>
                                {exp.first_name} {exp.last_name}
                              </strong>

                              {isSplitPrac && (
                                <span
                                  className="ems-matrix-tag"
                                  style={{ background: '#fef3c7', color: '#92400e', border: '1px solid #f59e0b' }}
                                >
                                  <FontAwesomeIcon icon={faTriangleExclamation} /> Split Practice
                                </span>
                              )}
                              {isSplitQual && (
                                <span
                                  className="ems-matrix-tag"
                                  style={{ background: '#fef3c7', color: '#92400e', border: '1px solid #f59e0b' }}
                                >
                                  <FontAwesomeIcon icon={faTriangleExclamation} /> Split Qualifier
                                </span>
                              )}

                              {hasAsnText && (
                                <span
                                  className={`ems-matrix-tag ${
                                    exp.asn_validated === 1
                                      ? 'ems-matrix-tag--asn-action'
                                      : exp.asn_validated === 0
                                        ? 'ems-matrix-tag--asn-no-action'
                                        : 'ems-matrix-tag--asn-pending'
                                  }`}
                                  style={{ cursor: onViewAsn ? 'pointer' : 'default' }}
                                  onClick={() => onViewAsn && exp.scout_id > 0 && onViewAsn(exp.scout_id)}
                                  title={exp.additional_support_needs}
                                >
                                  {exp.asn_validated === 1 ? '! ASN' : exp.asn_validated === 0 ? '✓ ASN' : '? ASN'}
                                </span>
                              )}

                              {isFA && (
                                <span className="ems-matrix-tag ems-matrix-tag--fa" title="First Aid">
                                  <FontAwesomeIcon icon={faFirstAid} /> FA
                                </span>
                              )}

                              {exp.scout_id === 0 && (
                                <span
                                  className="ems-matrix-tag"
                                  style={{ background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' }}
                                  title="Not matched in OSM"
                                >
                                  <FontAwesomeIcon icon={faUserSlash} /> Unmatched OSM
                                </span>
                              )}

                              {isAllocated && membership && (
                                <span
                                  className="ems-matrix-tag"
                                  style={{ background: '#dcfce7', color: '#166534', border: '1px solid #86efac' }}
                                >
                                  <FontAwesomeIcon icon={faCheck} /> {membership.cohort.name}
                                </span>
                              )}

                              {!isAllocated && isCollapsed && (
                                <span style={{ fontSize: '11px', color: '#1d4ed8', fontWeight: 500 }}>
                                  Selected
                                </span>
                              )}
                            </div>

                            <div style={{ display: 'flex', alignItems: 'center', gap: '4px', flexShrink: 0 }}>
                              <span className="ems-matrix-unit-badge">{exp.unit_name}</span>
                              {(isSelected || isAllocated) && (
                                <button
                                  type="button"
                                  className="button button-small"
                                  style={{ fontSize: '11px', lineHeight: 1.2, padding: '2px 6px', minHeight: 'auto' }}
                                  onClick={e => {
                                    e.stopPropagation();
                                    toggleExpandedSelected(exp.expedition_signup_id);
                                  }}
                                >
                                  {isCollapsed ? 'Details' : 'Collapse'}
                                </button>
                              )}
                            </div>
                          </div>

                          {!isCollapsed && (
                            <>
                              {exp.team_preferences && (
                                <div style={{ marginTop: '4px', fontSize: '11px', color: '#334155', display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '4px' }}>
                                  <strong>Wishlist:</strong>
                                  {wishlistTokens.length > 0 ? (
                                    wishlistTokens.map((token, idx) => {
                                      const match = fuzzyMatchExplorer(token, explorerLookupList, exp.expedition_signup_id);
                                      const searchTarget = match ? match.name : token;
                                      const pct = match ? Math.round(match.score * 100) : null;
                                      return (
                                        <button
                                          key={`${token}-${idx}`}
                                          type="button"
                                          onClick={e => {
                                            e.stopPropagation();
                                            setSearchQuery(searchTarget);
                                          }}
                                          title={
                                            match
                                              ? `Fuzzy match: ${match.name} (${pct}%). Click to search.`
                                              : `Click to search pool for "${token}"`
                                          }
                                          style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            gap: '3px',
                                            padding: '1px 6px',
                                            fontSize: '11px',
                                            borderRadius: '9999px',
                                            border: match ? '1px solid #93c5fd' : '1px solid #cbd5e1',
                                            background: match ? '#eff6ff' : '#f8fafc',
                                            color: match ? '#1d4ed8' : '#334155',
                                            cursor: 'pointer',
                                            textDecoration: 'underline',
                                          }}
                                        >
                                          <span>{token}</span>
                                          {match && match.name.toLowerCase() !== token.toLowerCase() && (
                                            <span style={{ fontSize: '10px', color: '#0369a1', textDecoration: 'none' }}>
                                              → {match.name} ({pct}%)
                                            </span>
                                          )}
                                        </button>
                                      );
                                    })
                                  ) : (
                                    <span>{exp.team_preferences}</span>
                                  )}
                                  {matchedIds.length > 0 && (
                                    <button
                                      type="button"
                                      className="button button-small"
                                      style={{ fontSize: '11px', lineHeight: 1.2, padding: '1px 6px', minHeight: 'auto' }}
                                      onClick={e => {
                                        e.stopPropagation();
                                        selectExplorerAndMatches(exp.expedition_signup_id);
                                      }}
                                      title={`Select ${exp.first_name} and all ${matchedIds.length} matched explorer(s)`}
                                    >
                                      <FontAwesomeIcon icon={faLink} /> Select Matches ({matchedIds.length})
                                    </button>
                                  )}
                                </div>
                              )}

                              <div style={{ marginTop: '4px', fontSize: '11px', color: '#64748b' }}>
                                <span>Prac: {exp.practiceAvail.length > 0 ? exp.practiceAvail.join(', ') : 'None'}</span>
                                {' · '}
                                <span>Qual: {exp.qualifierAvail.length > 0 ? exp.qualifierAvail.join(', ') : 'None'}</span>
                              </div>
                            </>
                          )}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </React.Fragment>
            ))
          )}
        </div>
      </div>

      {/* ── RIGHT COLUMN (62%): Formed Cohorts ── */}
      <div style={{ flex: '1 1 62%', minWidth: 0, display: 'flex', flexDirection: 'column', gap: '14px' }}>
        {cohorts.length === 0 ? (
          <div className="ems-panel" style={{ padding: '24px', textAlign: 'center', color: '#64748b' }}>
            <p style={{ margin: 0, fontSize: '13px' }}>
              No teams formed yet for <strong>{level.toUpperCase()}</strong> ({transport}). Select explorers on the left
              and click <strong>Create Team from Selected</strong> to begin.
            </p>
          </div>
        ) : (
          cohorts.map(cohort => {
            const members = cohort.members || [];
            const activeCount = members.length;
            const sizeOk = activeCount >= 4 && activeCount <= 7;

            const candidates = candidatesByCohort[cohort.id] || [];
            const practiceCandidates = candidates.filter(c => c.stage === 'practice');
            const qualifierCandidates = candidates.filter(c => c.stage === 'qualifier');

            const draft = pendingDrafts[cohort.id];
            const selectedPracticeId = draft ? draft.practice_expedition_id : cohort.practice_expedition_id;
            const selectedQualifierId = draft ? draft.qualifier_expedition_id : cohort.qualifier_expedition_id;

            const selectedPracticeExp = practiceEvents.find(e => e.id === selectedPracticeId);
            const selectedQualifierExp = qualifierEvents.find(e => e.id === selectedQualifierId);
            const refDate = selectedPracticeExp?.start_date || selectedQualifierExp?.start_date || null;

            const firstAidCount = members.filter(m =>
              hasValidFirstAid(m.first_aid_level, m.first_aid_status, m.first_aid_expiry, refDate)
            ).length;
            const faOk = firstAidCount >= 2;

            // Unavailable members for the pending draft (if drawer is open)
            const pracCand = candidates.find(c => c.expedition_id === selectedPracticeId);
            const qualCand = candidates.find(c => c.expedition_id === selectedQualifierId);
            const unavailableList = useMemoListUnavailable(pracCand, qualCand);

            return (
              <div key={cohort.id} className="ems-panel" style={{ padding: '14px' }}>
                {/* Cohort Card Header */}
                <div
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    flexWrap: 'wrap',
                    gap: '8px',
                    paddingBottom: '10px',
                    borderBottom: '1px solid #e2e8f0',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                    <h3 style={{ margin: 0, fontSize: '15px', fontWeight: 700, color: '#0f172a' }}>
                      <FontAwesomeIcon icon={faUsers} style={{ color: '#2563eb', marginRight: '6px' }} />
                      {cohort.name}
                    </h3>
                    <span
                      className="ems-badge"
                      style={{
                        background: sizeOk ? '#ecfdf5' : '#fef3c7',
                        color: sizeOk ? '#065f46' : '#92400e',
                        border: `1px solid ${sizeOk ? '#a7f3d0' : '#fde68a'}`,
                      }}
                    >
                      {activeCount}/7
                    </span>
                    <span
                      className="ems-badge"
                      style={{
                        background: faOk ? '#ecfdf5' : '#fef3c7',
                        color: faOk ? '#065f46' : '#92400e',
                        border: `1px solid ${faOk ? '#a7f3d0' : '#fde68a'}`,
                      }}
                    >
                      <FontAwesomeIcon icon={faOk ? faCheck : faTriangleExclamation} />{' '}
                      {faOk ? `${firstAidCount} First Aiders` : '<2 First Aiders'}
                    </span>
                  </div>

                  <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }}>
                    {selectedSignupIds.length > 0 && (
                      <button
                        type="button"
                        className="button button-small"
                        disabled={busyAction}
                        onClick={() => handleAddSelectedToCohort(cohort.id, cohort.name)}
                      >
                        <FontAwesomeIcon icon={faPlus} /> Add {selectedSignupIds.length} Selected
                      </button>
                    )}
                    {onOpenNotifyModal && (
                      <button
                        type="button"
                        className="button button-small"
                        onClick={() =>
                          onOpenNotifyModal({
                            scope: 'cohort',
                            cohortId: cohort.id,
                            cohortName: cohort.name,
                            level,
                            transport,
                          })
                        }
                      >
                        <FontAwesomeIcon icon={faEnvelope} /> Send Allocation Emails
                      </button>
                    )}
                    {cohorts.length > 1 && (
                      <button
                        type="button"
                        className="button button-small"
                        disabled={busyAction}
                        onClick={() => {
                          if (mergingCohortId === cohort.id) {
                            setMergingCohortId(null);
                            setMergeTargetId('');
                          } else {
                            const firstOther = cohorts.find(c => c.id !== cohort.id);
                            setMergingCohortId(cohort.id);
                            setMergeTargetId(firstOther ? firstOther.id : '');
                          }
                        }}
                        title="Merge this team into another team"
                      >
                        Merge…
                      </button>
                    )}
                    <button
                      type="button"
                      className="button button-small button-link-delete"
                      disabled={busyAction}
                      onClick={() => handleDeleteCohort(cohort)}
                      title="Delete team"
                    >
                      <FontAwesomeIcon icon={faTrash} />
                    </button>
                  </div>
                </div>

                {mergingCohortId === cohort.id && (
                  <div
                    style={{
                      marginTop: '10px',
                      padding: '8px 10px',
                      background: '#f8fafc',
                      border: '1px solid #cbd5e1',
                      borderRadius: '6px',
                      display: 'flex',
                      alignItems: 'center',
                      flexWrap: 'wrap',
                      gap: '8px',
                    }}
                  >
                    <span style={{ fontSize: '12px', fontWeight: 600, color: '#334155' }}>
                      Merge &ldquo;{cohort.name}&rdquo; into:
                    </span>
                    <select
                      className="ems-select-sm"
                      aria-label={`Select target team to merge ${cohort.name} into`}
                      value={mergeTargetId}
                      onChange={e => setMergeTargetId(e.target.value ? Number(e.target.value) : '')}
                    >
                      {cohorts
                        .filter(c => c.id !== cohort.id)
                        .map(other => (
                          <option key={other.id} value={other.id}>
                            {other.name} ({other.members.length}/7)
                          </option>
                        ))}
                    </select>
                    <button
                      type="button"
                      className="button button-primary button-small"
                      disabled={busyAction || !mergeTargetId}
                      onClick={() => handleMergeCohort(cohort)}
                    >
                      Confirm Merge
                    </button>
                    <button
                      type="button"
                      className="button button-small"
                      disabled={busyAction}
                      onClick={() => {
                        setMergingCohortId(null);
                        setMergeTargetId('');
                      }}
                    >
                      Cancel
                    </button>
                  </div>
                )}

                {/* Practice & Qualifier Date Selectors */}
                <div
                  style={{
                    display: 'grid',
                    gridTemplateColumns: '1fr 1fr',
                    gap: '12px',
                    marginTop: '12px',
                    marginBottom: '12px',
                  }}
                >
                  <div>
                    <label
                      htmlFor={`cohort-${cohort.id}-practice`}
                      style={{ display: 'block', fontSize: '11px', fontWeight: 600, color: '#475569', marginBottom: '4px' }}
                    >
                      Practice Expedition {loadingCandidates[cohort.id] ? '(scoring…)' : ''}
                    </label>
                    <select
                      id={`cohort-${cohort.id}-practice`}
                      className="ems-select-sm"
                      style={{ width: '100%' }}
                      value={selectedPracticeId ?? ''}
                      disabled={busyAction}
                      onChange={e => handleStageSelectChange(cohort, 'practice', e.target.value)}
                    >
                      <option value="">— Unscheduled —</option>
                      {practiceCandidates.length > 0
                        ? practiceCandidates.map(cand => {
                            const warn = cand.available_count < cand.total_count ? ' ⚠' : '';
                            return (
                              <option key={cand.expedition_id} value={cand.expedition_id}>
                                {cand.expedition_code} ({formatDateShort(cand.expedition_date)}) — {cand.available_count}/
                                {cand.total_count} Available{warn}
                              </option>
                            );
                          })
                        : practiceEvents.map(ev => (
                            <option key={ev.id} value={ev.id}>
                              {ev.event_code} ({formatDateShort(ev.start_date)})
                            </option>
                          ))}
                    </select>
                  </div>

                  <div>
                    <label
                      htmlFor={`cohort-${cohort.id}-qualifier`}
                      style={{ display: 'block', fontSize: '11px', fontWeight: 600, color: '#475569', marginBottom: '4px' }}
                    >
                      Qualifier Expedition {loadingCandidates[cohort.id] ? '(scoring…)' : ''}
                    </label>
                    <select
                      id={`cohort-${cohort.id}-qualifier`}
                      className="ems-select-sm"
                      style={{ width: '100%' }}
                      value={selectedQualifierId ?? ''}
                      disabled={busyAction}
                      onChange={e => handleStageSelectChange(cohort, 'qualifier', e.target.value)}
                    >
                      <option value="">— Unscheduled —</option>
                      {qualifierCandidates.length > 0
                        ? qualifierCandidates.map(cand => {
                            const warn = cand.available_count < cand.total_count ? ' ⚠' : '';
                            return (
                              <option key={cand.expedition_id} value={cand.expedition_id}>
                                {cand.expedition_code} ({formatDateShort(cand.expedition_date)}) — {cand.available_count}/
                                {cand.total_count} Available{warn}
                              </option>
                            );
                          })
                        : qualifierEvents.map(ev => (
                            <option key={ev.id} value={ev.id}>
                              {ev.event_code} ({formatDateShort(ev.start_date)})
                            </option>
                          ))}
                    </select>
                  </div>
                </div>

                {/* Inline Partial-Availability Resolution Drawer */}
                {draft && unavailableList.length > 0 && (
                  <div
                    style={{
                      background: '#fffbeb',
                      border: '1px solid #f59e0b',
                      borderRadius: '6px',
                      padding: '12px',
                      marginBottom: '12px',
                    }}
                  >
                    <div
                      style={{
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        marginBottom: '8px',
                      }}
                    >
                      <strong style={{ fontSize: '12px', color: '#92400e' }}>
                        <FontAwesomeIcon icon={faTriangleExclamation} /> Partial-Availability Resolution (
                        {unavailableList.length} unavailable)
                      </strong>
                      <button
                        type="button"
                        className="button button-small"
                        onClick={() =>
                          setPendingDrafts(prev => {
                            const next = { ...prev };
                            delete next[cohort.id];
                            return next;
                          })
                        }
                      >
                        Cancel
                      </button>
                    </div>

                    <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                      {unavailableList.map(u => {
                        const action = draft.resolutions[u.expedition_signup_id] || 'assign_pending';
                        return (
                          <div
                            key={u.expedition_signup_id}
                            style={{
                              background: '#ffffff',
                              border: '1px solid #fde68a',
                              borderRadius: '4px',
                              padding: '8px',
                              fontSize: '12px',
                            }}
                          >
                            <div style={{ fontWeight: 600, marginBottom: '4px' }}>
                              {u.name}{' '}
                              <span style={{ fontWeight: 400, color: '#92400e' }}>({u.stages.join(' & ')})</span>
                            </div>
                            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '12px' }}>
                              {(
                                [
                                  { val: 'assign_pending', label: 'Assign Pending Confirmation' },
                                  { val: 'split_date', label: 'Split to Alternate Date (Keep Team)' },
                                  { val: 'remove_from_cohort', label: 'Remove from Team' },
                                ] as const
                              ).map(opt => (
                                <label key={opt.val} style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', cursor: 'pointer' }}>
                                  <input
                                    type="radio"
                                    name={`res-${cohort.id}-${u.expedition_signup_id}`}
                                    value={opt.val}
                                    checked={action === opt.val}
                                    onChange={() =>
                                      setPendingDrafts(prev => ({
                                        ...prev,
                                        [cohort.id]: {
                                          ...draft,
                                          resolutions: {
                                            ...draft.resolutions,
                                            [u.expedition_signup_id]: opt.val,
                                          },
                                        },
                                      }))
                                    }
                                  />
                                  <span>{opt.label}</span>
                                </label>
                              ))}
                            </div>
                          </div>
                        );
                      })}
                    </div>

                    <div style={{ marginTop: '10px', display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
                      <button
                        type="button"
                        className="button button-primary"
                        disabled={busyAction}
                        onClick={() =>
                          executeScheduleCohort(
                            cohort.id,
                            draft.practice_expedition_id,
                            draft.qualifier_expedition_id,
                            draft.resolutions
                          )
                        }
                      >
                        {busyAction ? 'Applying…' : 'Confirm & Apply Schedule'}
                      </button>
                    </div>
                  </div>
                )}

                {/* Cohort Roster Table */}
                <table className="ems-table" style={{ width: '100%', fontSize: '12px' }}>
                  <thead>
                    <tr>
                      <th>Explorer</th>
                      <th>Unit</th>
                      <th>Stages</th>
                      <th>Notification</th>
                      <th style={{ width: '36px' }} />
                    </tr>
                  </thead>
                  <tbody>
                    {members.length === 0 ? (
                      <tr>
                        <td colSpan={5} style={{ textAlign: 'center', color: '#64748b' }}>
                          No members in this cohort.
                        </td>
                      </tr>
                    ) : (
                      members.map(member => {
                        const wishlist = extractWishlistFromPrefs(member.expedition_preferences);
                        const merged = mergedExplorers.find(
                          e => e.expedition_signup_id === member.expedition_signup_id
                        );
                        const pracAvail = merged?.practiceAvail || [];
                        const qualAvail = merged?.qualifierAvail || [];
                        const compat = getCompatibilityInfo(
                          member.expedition_signup_id,
                          member.first_name,
                          member.last_name,
                          wishlist,
                          pracAvail,
                          qualAvail
                        );

                        const isFA = hasValidFirstAid(
                          member.first_aid_level,
                          member.first_aid_status,
                          member.first_aid_expiry,
                          refDate
                        );
                        const isSplitPrac = member.member_status === 'split_practice';
                        const isSplitQual = member.member_status === 'split_qualifier';
                        const notif = getNotificationBadge(member.notification_status);

                        return (
                          <tr
                            key={member.expedition_signup_id}
                            onMouseEnter={() =>
                              setHoveredExplorer({
                                expedition_signup_id: member.expedition_signup_id,
                                first_name: member.first_name,
                                last_name: member.last_name,
                                wishlist,
                                practiceAvail: pracAvail,
                                qualifierAvail: qualAvail,
                              })
                            }
                            onMouseLeave={() => setHoveredExplorer(null)}
                            title={
                              compat.isMatch
                                ? compat.compatibleDates
                                  ? 'Wishlist match with compatible dates'
                                  : 'Wishlist match (date conflict)'
                                : undefined
                            }
                            style={
                              compat.isMatch
                                ? {
                                    background: '#eff6ff',
                                    boxShadow: `inset 3px 0 0 ${compat.compatibleDates ? '#2563eb' : '#f59e0b'}`,
                                  }
                                : undefined
                            }
                          >
                            <td>
                              <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '4px' }}>
                                <strong>
                                  {member.first_name} {member.last_name}
                                </strong>
                                {isFA && (
                                  <span className="ems-matrix-tag ems-matrix-tag--fa">FA</span>
                                )}
                                {member.scout_id === 0 && (
                                  <span
                                    className="ems-matrix-tag"
                                    style={{ background: '#fef2f2', color: '#991b1b', border: '1px solid #fecaca' }}
                                  >
                                    Unmatched OSM
                                  </span>
                                )}
                              </div>
                            </td>
                            <td>
                              <span className="ems-matrix-unit-badge">{member.unit_name || 'Unassigned'}</span>
                            </td>
                            <td>
                              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                                {isSplitPrac ? (
                                  <span
                                    className="ems-matrix-tag"
                                    style={{ background: '#fef3c7', color: '#92400e', border: '1px solid #f59e0b' }}
                                  >
                                    Split Prac <FontAwesomeIcon icon={faTriangleExclamation} />
                                  </span>
                                ) : selectedPracticeExp ? (
                                  <span className="ems-matrix-tag ems-matrix-tag--pref">
                                    Prac: {selectedPracticeExp.event_code}
                                  </span>
                                ) : (
                                  <span style={{ color: '#94a3b8' }}>Prac: —</span>
                                )}

                                {isSplitQual ? (
                                  <span
                                    className="ems-matrix-tag"
                                    style={{ background: '#fef3c7', color: '#92400e', border: '1px solid #f59e0b' }}
                                  >
                                    Split Qual <FontAwesomeIcon icon={faTriangleExclamation} />
                                  </span>
                                ) : selectedQualifierExp ? (
                                  <span className="ems-matrix-tag ems-matrix-tag--pref">
                                    Qual: {selectedQualifierExp.event_code}
                                  </span>
                                ) : (
                                  <span style={{ color: '#94a3b8' }}>Qual: —</span>
                                )}
                              </div>
                            </td>
                            <td>
                              <span
                                className="ems-matrix-tag"
                                style={{
                                  background: notif.bg,
                                  color: notif.color,
                                  border: `1px solid ${notif.border}`,
                                  marginLeft: 0,
                                }}
                              >
                                {notif.label}
                              </span>
                            </td>
                            <td style={{ textAlign: 'right' }}>
                              <button
                                type="button"
                                className="button button-small"
                                disabled={busyAction}
                                onClick={() =>
                                  handleRemoveMember(
                                    cohort.id,
                                    member.expedition_signup_id,
                                    `${member.first_name} ${member.last_name}`
                                  )
                                }
                                title="Remove from team"
                                aria-label={`Remove ${member.first_name} ${member.last_name} from team`}
                              >
                                <FontAwesomeIcon icon={faXmark} />
                              </button>
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}

function useMemoListUnavailable(
  pracCand?: ScheduleCandidate,
  qualCand?: ScheduleCandidate
): Array<{ expedition_signup_id: number; scout_id: number; name: string; stages: string[] }> {
  const map = new Map<number, { expedition_signup_id: number; scout_id: number; name: string; stages: string[] }>();

  if (pracCand) {
    for (const u of pracCand.unavailable_members) {
      map.set(u.expedition_signup_id, { ...u, stages: ['Practice'] });
    }
  }
  if (qualCand) {
    for (const u of qualCand.unavailable_members) {
      const existing = map.get(u.expedition_signup_id);
      if (existing) {
        existing.stages.push('Qualifier');
      } else {
        map.set(u.expedition_signup_id, { ...u, stages: ['Qualifier'] });
      }
    }
  }
  return Array.from(map.values());
}
