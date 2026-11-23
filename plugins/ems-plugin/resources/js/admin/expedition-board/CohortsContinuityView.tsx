import React, { useMemo, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faCheck,
  faCopy,
  faLink,
  faPlus,
  faTrash,
  faTriangleExclamation,
  faXmark,
} from '@fortawesome/free-solid-svg-icons';
import { getUnitDistrictMap, getUniqueDistricts, normalize } from '../signups-board/tableUtils';
import {
  MatrixEvent,
  MatrixExplorer,
  NotifyModalOptions,
  formatDateShort,
  fuzzyMatchExplorer,
  hasValidFirstAid,
  isWishlistMatch,
  parseWishlistTokens,
} from './cohortTypes';

export interface CohortsContinuityViewProps {
  level: string;
  transport: string;
  rootUrl: string;
  nonce: string;
  units: any[];
  cohorts: any[];
  practiceEvents: MatrixEvent[];
  qualifierEvents: MatrixEvent[];
  practiceExplorers: MatrixExplorer[];
  qualifierExplorers: MatrixExplorer[];
  onRefreshAll: () => Promise<void>;
  onFeedback: (msg: { ok: boolean; msg: string } | null) => void;
  onViewAsn?: (scoutId: number) => void;
  onOpenNotifyModal?: (opts: NotifyModalOptions) => void;
  onOpenEvent?: (eventId: number) => void;
  onInspectExplorer?: (target: { scoutId?: number; signupId?: number }) => void;
}

interface HoveredExplorerInfo {
  expedition_signup_id: number;
  first_name: string;
  last_name: string;
  wishlist: string;
  practiceAvail: string[];
  qualifierAvail: string[];
}

function getOfferStatusBadge(member: any): { label: string; bg: string; color: string; border: string } {
  const offer = String(member.offer_status || 'pending').toLowerCase();
  const notif = String(member.notification_status || 'draft').toLowerCase();
  if (offer === 'accepted') return { label: 'Accepted', bg: '#dcfce7', color: '#166534', border: '#86efac' };
  if (offer === 'queried') return { label: 'Queried', bg: '#fef3c7', color: '#92400e', border: '#f59e0b' };
  if (offer === 'declined') return { label: 'Declined', bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' };
  if (notif === 'notified') return { label: 'Offer Sent', bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' };
  if (notif === 're_notify_needed') return { label: 'Re-notify', bg: '#fef3c7', color: '#92400e', border: '#f59e0b' };
  return { label: 'Draft', bg: '#f1f5f9', color: '#475569', border: '#cbd5e1' };
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

export default function CohortsContinuityView({
  level,
  transport,
  rootUrl,
  nonce,
  units,
  cohorts: teams,
  practiceEvents,
  qualifierEvents,
  practiceExplorers,
  qualifierExplorers,
  onRefreshAll,
  onFeedback,
  onViewAsn,
  onOpenNotifyModal,
  onOpenEvent,
  onInspectExplorer,
}: CohortsContinuityViewProps) {
  const unitDistrictMap = useMemo(() => getUnitDistrictMap(units), [units]);

  // ── Left Pool Filters ──────────────────────────────────────────────────────
  const [searchQuery, setSearchQuery] = useState('');
  const [districtFilter, setDistrictFilter] = useState('all');
  const [unitFilter, setUnitFilter] = useState('all');
  const [asnFilter, setAsnFilter] = useState('all');
  const [stageFilter, setStageFilter] = useState<'all' | 'practice' | 'qualifier'>('all');
  const [groupByUnit, setGroupByUnit] = useState(false);
  const [hideAllocated, setHideAllocated] = useState(true);

  // ── Selection & Team Actions ───────────────────────────────────────────────
  const [selectedSignupIds, setSelectedSignupIds] = useState<number[]>([]);
  const [hoveredExplorer, setHoveredExplorer] = useState<HoveredExplorerInfo | null>(null);
  const [newTeamExpId, setNewTeamExpId] = useState<string>('');
  const [newTeamCode, setNewTeamCode] = useState<string>('');
  const [copyingTeamId, setCopyingTeamId] = useState<number | null>(null);
  const [copyTargetExpId, setCopyTargetExpId] = useState<string>('');
  const [busy, setBusy] = useState<boolean>(false);

  const allEvents = useMemo(
    () => [
      ...practiceEvents.map((e) => ({ ...e, stage: 'practice' as const })),
      ...qualifierEvents.map((e) => ({ ...e, stage: 'qualifier' as const })),
    ],
    [practiceEvents, qualifierEvents]
  );

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

  const assignmentMaps = useMemo(() => {
    const prac = new Map<number, string>();
    const qual = new Map<number, string>();
    for (const t of teams) {
      for (const m of t.members || []) {
        const sid = Number(m.expedition_signup_id || 0);
        if (sid <= 0) continue;
        if (t.stage === 'practice') prac.set(sid, t.name);
        else qual.set(sid, t.name);
      }
    }
    return { prac, qual };
  }, [teams]);

  const uniqueDistricts = useMemo(
    () => getUniqueDistricts(units, mergedExplorers, unitDistrictMap),
    [units, mergedExplorers, unitDistrictMap]
  );

  const poolUnits = useMemo(() => {
    const set = new Set<string>();
    mergedExplorers.forEach((e) => {
      if (e.unit_name) set.add(e.unit_name);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [mergedExplorers]);

  const explorerLookupList = useMemo(
    () =>
      mergedExplorers.map((e) => ({
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
    setSelectedSignupIds((prev) => Array.from(new Set([...prev, signupId, ...matches])));
  };

  const filteredPool = useMemo(() => {
    return mergedExplorers
      .filter((exp) => {
        const inPrac = assignmentMaps.prac.has(exp.expedition_signup_id);
        const inQual = assignmentMaps.qual.has(exp.expedition_signup_id);
        if (hideAllocated) {
          if (stageFilter === 'practice' && inPrac) return false;
          if (stageFilter === 'qualifier' && inQual) return false;
          if (stageFilter === 'all' && inPrac && inQual) return false;
        }
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
      })
      .sort((a, b) => `${a.first_name} ${a.last_name}`.localeCompare(`${b.first_name} ${b.last_name}`));
  }, [mergedExplorers, assignmentMaps, hideAllocated, stageFilter, districtFilter, unitFilter, asnFilter, searchQuery, unitDistrictMap]);

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

  const checkCompatibility = (
    firstName: string,
    lastName: string,
    wishlist: string,
    pracAvail: string[],
    qualAvail: string[],
    signupId: number
  ) => {
    if (!hoveredExplorer || hoveredExplorer.expedition_signup_id === signupId) {
      return { isMatch: false, compatibleDates: false };
    }
    const match = isWishlistMatch(
      hoveredExplorer.first_name,
      hoveredExplorer.last_name,
      hoveredExplorer.wishlist,
      firstName,
      lastName,
      wishlist
    );
    if (!match) return { isMatch: false, compatibleDates: false };
    const sharedPrac = hoveredExplorer.practiceAvail.some((c) => pracAvail.includes(c));
    const sharedQual = hoveredExplorer.qualifierAvail.some((c) => qualAvail.includes(c));
    return { isMatch: true, compatibleDates: sharedPrac || sharedQual };
  };

  const visibleTeams = useMemo(() => {
    if (stageFilter === 'all') return teams;
    return teams.filter((t) => t.stage === stageFilter);
  }, [teams, stageFilter]);

  const selectedExplorers = useMemo(
    () => mergedExplorers.filter((ex) => selectedSignupIds.includes(ex.expedition_signup_id)),
    [mergedExplorers, selectedSignupIds]
  );

  const scoreEventForExplorers = (
    eventCode: string,
    stage: 'practice' | 'qualifier',
    explorersList: Array<{ first_name: string; last_name: string; practiceAvail: string[]; qualifierAvail: string[] }>
  ) => {
    const unavailableNames: string[] = [];
    let availableCount = 0;
    for (const ex of explorersList) {
      const avail = stage === 'practice' ? ex.practiceAvail : ex.qualifierAvail;
      if (avail.includes(eventCode)) {
        availableCount += 1;
      } else {
        unavailableNames.push(`${ex.first_name} ${ex.last_name}`.trim());
      }
    }
    return {
      availableCount,
      total: explorersList.length,
      unavailableNames,
    };
  };

  const otherTeamsByMemberKey = useMemo(() => {
    const map = new Map<string, Array<{ team_post_id: number; name: string; stage: string }>>();
    for (const t of teams) {
      const tid = Number(t.team_post_id || t.id);
      for (const m of t.members || []) {
        const key = m.expedition_signup_id ? `s:${m.expedition_signup_id}` : m.scout_id ? `sc:${m.scout_id}` : '';
        if (!key) continue;
        const list = map.get(key) || [];
        list.push({ team_post_id: tid, name: t.name, stage: t.stage });
        map.set(key, list);
      }
    }
    return map;
  }, [teams]);

  const handleCreateTeam = async () => {
    if (!newTeamExpId) return;
    setBusy(true);
    onFeedback(null);
    try {
      const res = await fetch(`${rootUrl}/teams`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify({ expedition_id: Number(newTeamExpId), team_code: newTeamCode.trim() }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data?.message || 'Failed to create team.');
      setNewTeamCode('');
      onFeedback({ ok: true, msg: `Created team "${data.name}".` });
      await onRefreshAll();
    } catch (err: any) {
      onFeedback({ ok: false, msg: err?.message || 'Failed to create team.' });
    } finally {
      setBusy(false);
    }
  };

  const handleAddSelected = async (teamPostId: number, teamName: string) => {
    if (selectedSignupIds.length === 0) return;
    setBusy(true);
    onFeedback(null);
    try {
      const res = await fetch(`${rootUrl}/teams/${teamPostId}/members`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify({ expedition_signup_ids: selectedSignupIds }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data?.message || 'Failed to add members.');
      setSelectedSignupIds([]);
      onFeedback({ ok: true, msg: `Added ${data.added ?? 0} explorer(s) to ${teamName}.` });
      await onRefreshAll();
    } catch (err: any) {
      onFeedback({ ok: false, msg: err?.message || 'Failed to add members.' });
    } finally {
      setBusy(false);
    }
  };

  const handleRemoveMember = async (teamPostId: number, signupOrScoutId: number, name: string) => {
    setBusy(true);
    onFeedback(null);
    try {
      const res = await fetch(`${rootUrl}/teams/${teamPostId}/members/${signupOrScoutId}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': nonce },
      });
      if (!res.ok) throw new Error('Failed to remove member.');
      onFeedback({ ok: true, msg: `Removed ${name} from team.` });
      await onRefreshAll();
    } catch (err: any) {
      onFeedback({ ok: false, msg: err?.message || 'Failed to remove member.' });
    } finally {
      setBusy(false);
    }
  };

  const handleDeleteTeam = async (teamPostId: number, name: string) => {
    if (!window.confirm(`Delete team "${name}"? Members will return to the unassigned pool.`)) return;
    setBusy(true);
    onFeedback(null);
    try {
      const res = await fetch(`${rootUrl}/teams/${teamPostId}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': nonce },
      });
      if (!res.ok) throw new Error('Failed to delete team.');
      onFeedback({ ok: true, msg: `Deleted team "${name}".` });
      await onRefreshAll();
    } catch (err: any) {
      onFeedback({ ok: false, msg: err?.message || 'Failed to delete team.' });
    } finally {
      setBusy(false);
    }
  };

  const handleCopyToQualifier = async (teamPostId: number) => {
    if (!copyTargetExpId) return;
    setBusy(true);
    onFeedback(null);
    try {
      const res = await fetch(`${rootUrl}/teams/${teamPostId}/copy-to-event`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify({ target_expedition_id: Number(copyTargetExpId) }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data?.message || 'Failed to copy team.');
      setCopyingTeamId(null);
      setCopyTargetExpId('');
      onFeedback({
        ok: true,
        msg: `Copied ${data.copied_count} member(s) into Qualifier team "${data.name}".`,
      });
      await onRefreshAll();
    } catch (err: any) {
      onFeedback({ ok: false, msg: err?.message || 'Failed to copy team.' });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'minmax(300px, 370px) 1fr', gap: '16px', alignItems: 'start' }}>
      {/* Left: Unassigned / Pool with Wishlist Matching & Availability */}
      <div className="ems-panel ems-p-16">
        <div className="ems-flex-between ems-mb-8">
          <h3 className="ems-m-0">Explorers Pool ({filteredPool.length})</h3>
          {selectedSignupIds.length > 0 && (
            <button type="button" className="button button-small" onClick={() => setSelectedSignupIds([])}>
              Clear ({selectedSignupIds.length})
            </button>
          )}
        </div>

        <input
          type="search"
          className="regular-text ems-w-full ems-mb-8"
          placeholder="Search name, unit, or wishlist…"
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
        />

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '6px', marginBottom: '8px' }}>
          <select className="ems-select ems-select-sm" value={districtFilter} onChange={(e) => setDistrictFilter(e.target.value)}>
            <option value="all">All Districts</option>
            {uniqueDistricts.map((d) => (
              <option key={d} value={d}>{d}</option>
            ))}
          </select>
          <select className="ems-select ems-select-sm" value={unitFilter} onChange={(e) => setUnitFilter(e.target.value)}>
            <option value="all">All Units</option>
            {poolUnits.map((u) => (
              <option key={u} value={u}>{u}</option>
            ))}
          </select>
          <select className="ems-select ems-select-sm" value={asnFilter} onChange={(e) => setAsnFilter(e.target.value)}>
            <option value="all">All ASN</option>
            <option value="has_asn">Has ASN</option>
            <option value="action_needed">Action Needed</option>
            <option value="unreviewed">Unreviewed</option>
          </select>
          <label style={{ display: 'flex', alignItems: 'center', gap: '4px', fontSize: '12px' }}>
            <input type="checkbox" checked={groupByUnit} onChange={(e) => setGroupByUnit(e.target.checked)} />
            Group by Unit
          </label>
        </div>

        <label style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px', marginBottom: '10px' }}>
          <input type="checkbox" checked={hideAllocated} onChange={(e) => setHideAllocated(e.target.checked)} />
          Hide already allocated
        </label>

        <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', maxHeight: '62vh', overflowY: 'auto' }}>
          {filteredPool.length === 0 ? (
            <p className="ems-meta-text">No explorers match the current pool filters.</p>
          ) : (
            groupedPool.map(([groupName, groupItems]) => (
              <div key={groupName || 'all'}>
                {groupName && (
                  <div style={{ fontSize: '11px', fontWeight: 700, textTransform: 'uppercase', color: '#475569', padding: '4px 2px' }}>
                    {groupName} ({groupItems.length})
                  </div>
                )}
                {groupItems.map((ex) => {
                  const sid = ex.expedition_signup_id;
                  const checked = selectedSignupIds.includes(sid);
                  const isFA = hasValidFirstAid(null, ex.first_aid_status, ex.first_aid_expiry);
                  const matches = matchedIdsBySignup.get(sid) || [];
                  const compat = checkCompatibility(
                    ex.first_name,
                    ex.last_name,
                    ex.team_preferences,
                    ex.practiceAvail,
                    ex.qualifierAvail,
                    sid
                  );
                  const pracTeam = assignmentMaps.prac.get(sid);
                  const qualTeam = assignmentMaps.qual.get(sid);

                  return (
                    <div
                      key={sid}
                      onMouseEnter={() =>
                        setHoveredExplorer({
                          expedition_signup_id: sid,
                          first_name: ex.first_name,
                          last_name: ex.last_name,
                          wishlist: ex.team_preferences,
                          practiceAvail: ex.practiceAvail,
                          qualifierAvail: ex.qualifierAvail,
                        })
                      }
                      onMouseLeave={() => setHoveredExplorer(null)}
                      style={{
                        display: 'flex',
                        alignItems: 'flex-start',
                        gap: '8px',
                        padding: '7px 8px',
                        marginBottom: '4px',
                        border: '1px solid #e2e8f0',
                        borderRadius: '4px',
                        background: compat.isMatch ? '#eff6ff' : checked ? '#f0fdf4' : '#fff',
                        boxShadow: compat.isMatch
                          ? `inset 3px 0 0 ${compat.compatibleDates ? '#2563eb' : '#f59e0b'}`
                          : undefined,
                      }}
                    >
                      <input
                        type="checkbox"
                        checked={checked}
                        onChange={(e) =>
                          setSelectedSignupIds((prev) =>
                            e.target.checked ? [...prev, sid] : prev.filter((id) => id !== sid)
                          )
                        }
                        style={{ marginTop: '3px' }}
                      />
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '4px', fontSize: '13px' }}>
                          {onInspectExplorer ? (
                            <button
                              type="button"
                              className="button-link"
                              style={{ fontWeight: 600, fontSize: '13px', padding: 0, textAlign: 'left' }}
                              title="Open Explorer 360° drawer"
                              onClick={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                onInspectExplorer({
                                  scoutId: ex.scout_id > 0 ? ex.scout_id : undefined,
                                  signupId: sid > 0 ? sid : undefined,
                                });
                              }}
                            >
                              {ex.first_name} {ex.last_name}
                            </button>
                          ) : (
                            <strong>{ex.first_name} {ex.last_name}</strong>
                          )}
                          {isFA && <span className="ems-matrix-tag ems-matrix-tag--fa">FA</span>}
                          {ex.has_asn && onViewAsn && (
                            <button
                              type="button"
                              className="button-link"
                              style={{ fontSize: '11px', color: '#b45309' }}
                              onClick={() => onViewAsn(ex.scout_id)}
                            >
                              ASN
                            </button>
                          )}
                          {matches.length > 0 && (
                            <button
                              type="button"
                              className="button-link"
                              style={{ fontSize: '11px' }}
                              title="Select explorer and wishlist matches"
                              onClick={() => selectExplorerAndMatches(sid)}
                            >
                              <FontAwesomeIcon icon={faLink} /> +{matches.length}
                            </button>
                          )}
                        </div>
                        <div className="ems-meta-text" style={{ fontSize: '11px' }}>
                          {ex.unit_name} &middot; {pracTeam ? `Prac: ${pracTeam}` : `Prac avail: ${ex.practiceAvail.join(', ') || 'None'}`} &middot;{' '}
                          {qualTeam ? `Qual: ${qualTeam}` : `Qual avail: ${ex.qualifierAvail.join(', ') || 'None'}`}
                        </div>
                        {ex.team_preferences && (
                          <div style={{ fontSize: '11px', color: '#475569', marginTop: '2px' }}>
                            Wishlist: <em>{ex.team_preferences}</em>
                          </div>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            ))
          )}
        </div>
      </div>

      {/* Right: Event Teams */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
        <div className="ems-panel ems-p-12 ems-flex-between ems-wrap ems-gap-8">
          <div className="ems-flex-center ems-gap-8 ems-wrap">
            <select
              aria-label="Select event for new team"
              className="ems-select"
              value={newTeamExpId}
              onChange={(e) => setNewTeamExpId(e.target.value)}
            >
              <option value="">Select Practice or Qualifier event…</option>
              {allEvents.map((ev) => {
                const score =
                  selectedExplorers.length > 0
                    ? scoreEventForExplorers(ev.event_code, ev.stage, selectedExplorers)
                    : null;
                return (
                  <option key={ev.id} value={String(ev.id)}>
                    [{ev.stage === 'practice' ? 'Practice' : 'Qualifier'}] {ev.event_code} — {ev.title} ({formatDateShort(ev.start_date)})
                    {score ? ` · ${score.availableCount}/${score.total} avail` : ''}
                  </option>
                );
              })}
            </select>
            <input
              type="text"
              placeholder="Optional team code…"
              value={newTeamCode}
              onChange={(e) => setNewTeamCode(e.target.value)}
              style={{ width: '160px' }}
            />
            <button
              type="button"
              className="button button-primary"
              disabled={busy || !newTeamExpId}
              onClick={handleCreateTeam}
            >
              <FontAwesomeIcon icon={faPlus} /> Create Team
            </button>
          </div>

          <div className="ems-btn-group">
            {(['all', 'practice', 'qualifier'] as const).map((st) => (
              <button
                key={st}
                type="button"
                className={`button button-small ${stageFilter === st ? 'button-primary' : ''}`}
                onClick={() => setStageFilter(st)}
              >
                {st === 'all' ? 'All Stages' : st === 'practice' ? 'Practice' : 'Qualifier'}
              </button>
            ))}
          </div>
        </div>

        {selectedExplorers.length > 0 && allEvents.length > 0 && (
          <div className="ems-panel ems-p-12" style={{ background: '#f8fafc' }}>
            <div style={{ fontSize: '12px', fontWeight: 600, color: '#334155', marginBottom: '6px' }}>
              Event Availability for {selectedExplorers.length} Selected:
            </div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px' }}>
              {allEvents.map((ev) => {
                const score = scoreEventForExplorers(ev.event_code, ev.stage, selectedExplorers);
                const allOk = score.availableCount === score.total;
                const noneOk = score.availableCount === 0;
                return (
                  <span
                    key={`fit_${ev.id}`}
                    className="ems-badge"
                    title={
                      score.unavailableNames.length > 0
                        ? `Unavailable: ${score.unavailableNames.join(', ')}`
                        : 'All selected explorers available'
                    }
                    style={{
                      background: allOk ? '#dcfce7' : noneOk ? '#fee2e2' : '#fef3c7',
                      color: allOk ? '#166534' : noneOk ? '#991b1b' : '#92400e',
                      border: `1px solid ${allOk ? '#86efac' : noneOk ? '#fca5a5' : '#f59e0b'}`,
                    }}
                  >
                    {ev.event_code} ({formatDateShort(ev.start_date)}): {score.availableCount}/{score.total}
                  </span>
                );
              })}
            </div>
          </div>
        )}

        {visibleTeams.length === 0 ? (
          <div className="ems-panel ems-p-16">
            <p className="ems-meta-text ems-m-0">No event teams found for this level/transport yet. Create one above or in Date Grid.</p>
          </div>
        ) : (
          visibleTeams.map((team) => {
            const members: any[] = team.members || [];
            const sizeOk = members.length >= 4 && members.length <= 7;
            const faCount = members.filter((m) =>
              hasValidFirstAid(m.first_aid_level, m.first_aid_status, m.first_aid_expiry)
            ).length;
            const faOk = faCount >= 2;
            const isPractice = team.stage === 'practice';

            return (
              <div key={team.team_post_id || team.id} className="ems-panel ems-p-16">
                <div className="ems-flex-between ems-wrap ems-gap-8 ems-mb-12">
                  <div className="ems-flex-center ems-wrap ems-gap-8">
                    <h3 className="ems-m-0">{team.name}</h3>
                    <span
                      className="ems-badge"
                      style={{
                        background: isPractice ? '#dbeafe' : '#ede9fe',
                        color: isPractice ? '#1e40af' : '#5b21b6',
                      }}
                    >
                      {isPractice ? 'Practice' : 'Qualifier'}
                    </span>
                    <span className="ems-meta-text">
                      {onOpenEvent && team.expedition_id ? (
                        <button
                          type="button"
                          className="button-link"
                          style={{ fontWeight: 600, padding: 0 }}
                          onClick={() => onOpenEvent(Number(team.expedition_id))}
                          title="Open event details"
                        >
                          {team.expedition_code}
                        </button>
                      ) : (
                        team.expedition_code
                      )}{' '}
                      ({formatDateShort(team.start_date)})
                    </span>
                    <span
                      className="ems-badge"
                      style={{
                        background: sizeOk ? '#ecfdf5' : '#fef3c7',
                        color: sizeOk ? '#065f46' : '#92400e',
                      }}
                    >
                      {members.length}/7
                    </span>
                    <span
                      className="ems-badge"
                      style={{
                        background: faOk ? '#ecfdf5' : '#fef3c7',
                        color: faOk ? '#065f46' : '#92400e',
                      }}
                    >
                      <FontAwesomeIcon icon={faOk ? faCheck : faTriangleExclamation} />{' '}
                      {faCount} FA
                    </span>
                  </div>

                  <div className="ems-flex-center ems-gap-6 ems-wrap">
                    {selectedExplorers.length > 0 && (() => {
                      const cardScore = scoreEventForExplorers(
                        team.expedition_code,
                        isPractice ? 'practice' : 'qualifier',
                        selectedExplorers
                      );
                      const allOk = cardScore.availableCount === cardScore.total;
                      return (
                        <span
                          className="ems-badge"
                          title={
                            cardScore.unavailableNames.length > 0
                              ? `Unavailable: ${cardScore.unavailableNames.join(', ')}`
                              : 'All selected explorers available'
                          }
                          style={{
                            background: allOk ? '#dcfce7' : '#fef3c7',
                            color: allOk ? '#166534' : '#92400e',
                            border: `1px solid ${allOk ? '#86efac' : '#f59e0b'}`,
                          }}
                        >
                          {cardScore.availableCount}/{cardScore.total} avail
                        </span>
                      );
                    })()}
                    {selectedSignupIds.length > 0 && (
                      <button
                        type="button"
                        className="button button-small button-primary"
                        disabled={busy}
                        onClick={() => handleAddSelected(team.team_post_id || team.id, team.name)}
                      >
                        <FontAwesomeIcon icon={faPlus} /> Add {selectedSignupIds.length} Selected
                      </button>
                    )}
                    {onOpenNotifyModal && (
                      <button
                        type="button"
                        className="button button-small"
                        disabled={busy}
                        onClick={() =>
                          onOpenNotifyModal({
                            scope: 'cohort',
                            cohortId: team.team_post_id || team.id,
                            cohortName: team.name,
                            level,
                            transport,
                          })
                        }
                      >
                        Send Offer Emails
                      </button>
                    )}
                    {isPractice && qualifierEvents.length > 0 && (
                      <button
                        type="button"
                        className="button button-small"
                        disabled={busy}
                        onClick={() => {
                          setCopyingTeamId(copyingTeamId === team.team_post_id ? null : team.team_post_id);
                          setCopyTargetExpId(String(qualifierEvents[0]?.id || ''));
                        }}
                      >
                        <FontAwesomeIcon icon={faCopy} /> Copy to Qualifier…
                      </button>
                    )}
                    <button
                      type="button"
                      className="button button-small button-link-delete"
                      disabled={busy}
                      onClick={() => handleDeleteTeam(team.team_post_id || team.id, team.name)}
                      title="Delete team"
                    >
                      <FontAwesomeIcon icon={faTrash} />
                    </button>
                  </div>
                </div>

                {copyingTeamId === team.team_post_id && (() => {
                  const teamPoolMembers = members
                    .map((m) => mergedExplorers.find((ex) => ex.expedition_signup_id === m.expedition_signup_id))
                    .filter((x): x is NonNullable<typeof x> => Boolean(x));
                  const selectedQual = qualifierEvents.find((ev) => String(ev.id) === copyTargetExpId);
                  const selectedScore =
                    selectedQual && teamPoolMembers.length > 0
                      ? scoreEventForExplorers(selectedQual.event_code, 'qualifier', teamPoolMembers)
                      : null;

                  return (
                  <div className="ems-mb-12" style={{ padding: '8px', background: '#f8fafc', borderRadius: '6px' }}>
                    <div className="ems-flex-center ems-gap-8 ems-wrap">
                      <span style={{ fontSize: '12px', fontWeight: 600 }}>Copy members to Qualifier event:</span>
                      <select
                        className="ems-select ems-select-sm"
                        value={copyTargetExpId}
                        onChange={(e) => setCopyTargetExpId(e.target.value)}
                      >
                        {qualifierEvents.map((ev) => {
                          const score =
                            teamPoolMembers.length > 0
                              ? scoreEventForExplorers(ev.event_code, 'qualifier', teamPoolMembers)
                              : null;
                          return (
                            <option key={ev.id} value={String(ev.id)}>
                              {ev.event_code} — {ev.title} ({formatDateShort(ev.start_date)})
                              {score ? ` · ${score.availableCount}/${score.total} avail` : ''}
                            </option>
                          );
                        })}
                      </select>
                      <button
                        type="button"
                        className="button button-small button-primary"
                        disabled={busy || !copyTargetExpId}
                        onClick={() => handleCopyToQualifier(team.team_post_id || team.id)}
                      >
                        Copy Team
                      </button>
                      <button type="button" className="button button-small" onClick={() => setCopyingTeamId(null)}>
                        Cancel
                      </button>
                    </div>
                    {selectedScore && selectedScore.unavailableNames.length > 0 && (
                      <div style={{ fontSize: '11.5px', color: '#92400e', marginTop: '6px' }}>
                        Unavailable for {selectedQual?.event_code}: {selectedScore.unavailableNames.join(', ')}
                      </div>
                    )}
                  </div>
                  );
                })()}

                {members.length === 0 ? (
                  <p className="ems-meta-text ems-m-0">No members in this team yet.</p>
                ) : (
                  <table className="ems-table" style={{ fontSize: '12.5px' }}>
                    <thead>
                      <tr>
                        <th>Explorer</th>
                        <th>Unit</th>
                        <th>Offer Status</th>
                        <th style={{ textAlign: 'right' }}>Remove</th>
                      </tr>
                    </thead>
                    <tbody>
                      {members.map((m) => {
                        const badge = getOfferStatusBadge(m);
                        const isFA = hasValidFirstAid(m.first_aid_level, m.first_aid_status, m.first_aid_expiry);
                        const wishlist = extractWishlistFromPrefs(m.expedition_preferences);
                        const poolMatch = mergedExplorers.find((ex) => ex.expedition_signup_id === m.expedition_signup_id);
                        const compat = checkCompatibility(
                          m.first_name,
                          m.last_name,
                          wishlist,
                          poolMatch?.practiceAvail || [],
                          poolMatch?.qualifierAvail || [],
                          m.expedition_signup_id
                        );
                        return (
                          <tr
                            key={`${team.team_post_id}_${m.expedition_signup_id || m.scout_id}`}
                            onMouseEnter={() =>
                              setHoveredExplorer({
                                expedition_signup_id: m.expedition_signup_id,
                                first_name: m.first_name,
                                last_name: m.last_name,
                                wishlist,
                                practiceAvail: poolMatch?.practiceAvail || [],
                                qualifierAvail: poolMatch?.qualifierAvail || [],
                              })
                            }
                            onMouseLeave={() => setHoveredExplorer(null)}
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
                              {onInspectExplorer ? (
                                <button
                                  type="button"
                                  className="button-link"
                                  style={{ fontWeight: 600, padding: 0, textAlign: 'left' }}
                                  title="Open Explorer 360° drawer"
                                  onClick={() =>
                                    onInspectExplorer({
                                      scoutId: m.scout_id > 0 ? m.scout_id : undefined,
                                      signupId: m.expedition_signup_id > 0 ? m.expedition_signup_id : undefined,
                                    })
                                  }
                                >
                                  {m.first_name} {m.last_name}
                                </button>
                              ) : (
                                <strong>{m.first_name} {m.last_name}</strong>
                              )}{' '}
                              {isFA && <span className="ems-matrix-tag ems-matrix-tag--fa">FA</span>}
                              {(() => {
                                const key = m.expedition_signup_id ? `s:${m.expedition_signup_id}` : m.scout_id ? `sc:${m.scout_id}` : '';
                                const others = (otherTeamsByMemberKey.get(key) || []).filter(
                                  (ot) => ot.team_post_id !== Number(team.team_post_id || team.id)
                                );
                                return others.map((ot) => (
                                  <span
                                    key={ot.team_post_id}
                                    className="ems-matrix-tag ems-matrix-tag--pref"
                                    style={{ marginLeft: '4px' }}
                                    title={`Also in ${ot.name} (${ot.stage})`}
                                  >
                                    {ot.name}
                                  </span>
                                ));
                              })()}
                            </td>
                            <td>{m.unit_name || 'Unassigned'}</td>
                            <td>
                              <span
                                className="ems-matrix-tag"
                                style={{ background: badge.bg, color: badge.color, border: `1px solid ${badge.border}` }}
                              >
                                {badge.label}
                              </span>
                            </td>
                            <td style={{ textAlign: 'right' }}>
                              <button
                                type="button"
                                className="button button-small"
                                disabled={busy}
                                onClick={() =>
                                  handleRemoveMember(
                                    team.team_post_id || team.id,
                                    m.expedition_signup_id || m.scout_id,
                                    `${m.first_name} ${m.last_name}`
                                  )
                                }
                                aria-label={`Remove ${m.first_name} ${m.last_name}`}
                              >
                                <FontAwesomeIcon icon={faXmark} />
                              </button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                )}
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
