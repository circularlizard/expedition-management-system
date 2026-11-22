import React, { useState, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faPlus,
  faLock,
  faUsers,
  faXmark,
  faCheck,
  faTriangleExclamation,
  faCircleQuestion,
  faMagnifyingGlass,
  faClipboardList,
} from '@fortawesome/free-solid-svg-icons';
import { getUnitDistrictMap, getUniqueDistricts, normalize } from '../signups-board/tableUtils';
import { Cohort, MatrixEvent, MatrixExplorer, formatDateShort } from './cohortTypes';

export interface StageMatrixViewProps {
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
  onOpenEvent?: (eventId: number) => void;
}

interface CombinedMatrixRow {
  key: string;
  expedition_signup_id: number;
  scout_id: number;
  first_name: string;
  last_name: string;
  unit_name: string;
  district: string;
  cohortName: string;
  team_preferences: string;
  has_asn: boolean;
  additional_support_needs: string;
  asn_validated: number | null;
  practiceAvailable: string[];
  practiceAllocatedEvent: string | null;
  practiceAllocatedTeam: string | null;
  qualifierAvailable: string[];
  qualifierAllocatedEvent: string | null;
  qualifierAllocatedTeam: string | null;
}

export default function StageMatrixView({
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
  onOpenEvent,
}: StageMatrixViewProps) {
  const unitDistrictMap = useMemo(() => getUnitDistrictMap(units), [units]);

  const [stageMode, setStageMode] = useState<'both' | 'practice' | 'qualifier'>('both');
  const [searchQuery, setSearchQuery] = useState('');
  const [districtFilter, setDistrictFilter] = useState('all');
  const [unitFilter, setUnitFilter] = useState('all');
  const [asnFilter, setAsnFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState<'all' | 'unallocated' | 'allocated'>('all');

  const [activePopover, setActivePopover] = useState<{
    row: CombinedMatrixRow;
    event: MatrixEvent;
    stage: 'practice' | 'qualifying';
  } | null>(null);
  const [allocating, setAllocating] = useState(false);

  const [asnDialogRow, setAsnDialogRow] = useState<CombinedMatrixRow | null>(null);
  const [asnUpdating, setAsnUpdating] = useState(false);

  const cohortBySignupId = useMemo(() => {
    const map = new Map<number, string>();
    for (const c of cohorts) {
      for (const m of c.members || []) {
        map.set(m.expedition_signup_id, c.name);
      }
    }
    return map;
  }, [cohorts]);

  const combinedRows = useMemo<CombinedMatrixRow[]>(() => {
    const map = new Map<string, CombinedMatrixRow>();

    const makeKey = (exp: MatrixExplorer) =>
      exp.expedition_signup_id > 0 ? `s_${exp.expedition_signup_id}` : `o_${exp.scout_id}_${exp.first_name}_${exp.last_name}`;

    for (const exp of practiceExplorers) {
      const k = makeKey(exp);
      map.set(k, {
        key: k,
        expedition_signup_id: exp.expedition_signup_id,
        scout_id: exp.scout_id,
        first_name: exp.first_name,
        last_name: exp.last_name,
        unit_name: exp.unit_name || 'Unassigned',
        district: exp.district || '',
        cohortName: cohortBySignupId.get(exp.expedition_signup_id) || '—',
        team_preferences: exp.team_preferences || '',
        has_asn: exp.has_asn,
        additional_support_needs: exp.additional_support_needs || '',
        asn_validated: exp.asn_validated ?? null,
        practiceAvailable: exp.available_events || [],
        practiceAllocatedEvent: exp.allocated_event_code,
        practiceAllocatedTeam: exp.allocated_team_code,
        qualifierAvailable: [],
        qualifierAllocatedEvent: null,
        qualifierAllocatedTeam: null,
      });
    }

    for (const exp of qualifierExplorers) {
      const k = makeKey(exp);
      const existing = map.get(k);
      if (existing) {
        existing.qualifierAvailable = exp.available_events || [];
        existing.qualifierAllocatedEvent = exp.allocated_event_code;
        existing.qualifierAllocatedTeam = exp.allocated_team_code;
      } else {
        map.set(k, {
          key: k,
          expedition_signup_id: exp.expedition_signup_id,
          scout_id: exp.scout_id,
          first_name: exp.first_name,
          last_name: exp.last_name,
          unit_name: exp.unit_name || 'Unassigned',
          district: exp.district || '',
          cohortName: cohortBySignupId.get(exp.expedition_signup_id) || '—',
          team_preferences: exp.team_preferences || '',
          has_asn: exp.has_asn,
          additional_support_needs: exp.additional_support_needs || '',
          asn_validated: exp.asn_validated ?? null,
          practiceAvailable: [],
          practiceAllocatedEvent: null,
          practiceAllocatedTeam: null,
          qualifierAvailable: exp.available_events || [],
          qualifierAllocatedEvent: exp.allocated_event_code,
          qualifierAllocatedTeam: exp.allocated_team_code,
        });
      }
    }

    return Array.from(map.values()).sort((a, b) =>
      `${a.first_name} ${a.last_name}`.localeCompare(`${b.first_name} ${b.last_name}`)
    );
  }, [practiceExplorers, qualifierExplorers, cohortBySignupId]);

  const uniqueDistricts = useMemo(
    () => getUniqueDistricts(units, combinedRows, unitDistrictMap),
    [units, combinedRows, unitDistrictMap]
  );

  const cohortUnits = useMemo(() => {
    const set = new Set<string>();
    combinedRows.forEach(r => {
      if (r.unit_name) set.add(r.unit_name);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [combinedRows]);

  const filteredRows = useMemo(() => {
    return combinedRows.filter(row => {
      if (districtFilter !== 'all') {
        const itemDistrict = (row.district || unitDistrictMap[row.unit_name] || '').trim() || 'Unassigned';
        if (normalize(itemDistrict) !== normalize(districtFilter)) return false;
      }
      if (unitFilter !== 'all' && row.unit_name !== unitFilter) return false;

      if (statusFilter !== 'all') {
        const isAlloc =
          stageMode === 'practice'
            ? Boolean(row.practiceAllocatedEvent)
            : stageMode === 'qualifier'
              ? Boolean(row.qualifierAllocatedEvent)
              : Boolean(row.practiceAllocatedEvent || row.qualifierAllocatedEvent);
        if (statusFilter === 'unallocated' && isAlloc) return false;
        if (statusFilter === 'allocated' && !isAlloc) return false;
      }

      if (asnFilter !== 'all') {
        const hasNeeds = Boolean(row.additional_support_needs && row.additional_support_needs.trim() !== '');
        if (asnFilter === 'has_asn' && !hasNeeds) return false;
        if (asnFilter === 'action_needed' && (!hasNeeds || row.asn_validated !== 1)) return false;
        if (asnFilter === 'no_action' && (!hasNeeds || row.asn_validated !== 0)) return false;
        if (asnFilter === 'unreviewed' && (!hasNeeds || (row.asn_validated !== null && row.asn_validated !== undefined))) {
          return false;
        }
      }

      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase().trim();
        const full = `${row.first_name} ${row.last_name}`.toLowerCase();
        const wish = row.team_preferences.toLowerCase();
        const unit = row.unit_name.toLowerCase();
        const cohort = row.cohortName.toLowerCase();
        if (!full.includes(q) && !wish.includes(q) && !unit.includes(q) && !cohort.includes(q)) return false;
      }

      return true;
    });
  }, [combinedRows, districtFilter, unitFilter, statusFilter, stageMode, asnFilter, searchQuery, unitDistrictMap]);

  const visiblePracticeEvents = stageMode === 'qualifier' ? [] : practiceEvents;
  const visibleQualifierEvents = stageMode === 'practice' ? [] : qualifierEvents;

  const executeAllocation = async (
    scoutId: number,
    eventCode: string,
    mode: 'unallocated' | 'new_team' | 'existing_team' | 'remove',
    teamId?: number
  ) => {
    if (!scoutId || !eventCode) return;
    setAllocating(true);
    onFeedback(null);

    const body: Record<string, unknown> = {
      scout_ids: [scoutId],
      event_code: eventCode,
      mode,
    };
    if (mode === 'existing_team' && teamId) {
      body.team_id = teamId;
    }

    try {
      const resp = await fetch(`${rootUrl}/planning-board/allocate`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify(body),
      });
      const resData = await resp.json();
      if (!resp.ok) {
        throw new Error(resData?.message || resp.statusText);
      }
      onFeedback({
        ok: true,
        msg: mode === 'remove' ? 'Explorer unallocated from event.' : 'Split-date guest placement updated.',
      });
      setActivePopover(null);
      await onRefreshAll();
    } catch (err) {
      onFeedback({ ok: false, msg: err instanceof Error ? err.message : String(err) });
    } finally {
      setAllocating(false);
    }
  };

  const handleUpdateAsnValidation = async (scoutId: number, val: number | null) => {
    setAsnUpdating(true);
    try {
      const resp = await fetch(`${rootUrl}/planning-board/explorers/${scoutId}/asn-validation`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({ asn_validated: val }),
      });
      const data = await resp.json();
      if (!resp.ok || (data.code && data.message)) {
        throw new Error(data.message || 'Failed to update ASN validation');
      }
      setAsnDialogRow(null);
      await onRefreshAll();
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to update ASN validation');
    } finally {
      setAsnUpdating(false);
    }
  };

  const stickyHeaderBase: React.CSSProperties = {
    position: 'sticky',
    zIndex: 3,
    background: '#f8fafc',
  };

  const stickyCellBase: React.CSSProperties = {
    position: 'sticky',
    zIndex: 2,
    background: '#ffffff',
  };

  const renderEventCell = (
    row: CombinedMatrixRow,
    ev: MatrixEvent,
    stage: 'practice' | 'qualifying',
    isGroupDivider: boolean
  ) => {
    const availableList = stage === 'practice' ? row.practiceAvailable : row.qualifierAvailable;
    const allocatedEvent = stage === 'practice' ? row.practiceAllocatedEvent : row.qualifierAllocatedEvent;
    const allocatedTeam = stage === 'practice' ? row.practiceAllocatedTeam : row.qualifierAllocatedTeam;

    const isAvailable = availableList.includes(ev.event_code);
    const isAssignedHere = allocatedEvent === ev.event_code;
    const isAssignedOther = Boolean(allocatedEvent && allocatedEvent !== ev.event_code);

    return (
      <td
        key={`${stage}-${ev.id}`}
        className="ems-matrix-cell-event"
        style={isGroupDivider ? { borderLeft: '3px solid #94a3b8' } : undefined}
      >
        {isAssignedHere ? (
          <button
            type="button"
            className="ems-matrix-badge ems-matrix-badge--assigned"
            onClick={() => setActivePopover({ row, event: ev, stage })}
            title="Assigned to this event - click to manage guest placement"
          >
            <FontAwesomeIcon icon={faLock} className="ems-matrix-badge-icon" />{' '}
            {allocatedTeam === 'UNALLOCATED' ? 'Pool' : allocatedTeam}
          </button>
        ) : !isAvailable ? (
          <span className="ems-matrix-dash" aria-label="Not available">—</span>
        ) : isAssignedOther ? (
          <button
            type="button"
            className="ems-matrix-badge ems-matrix-badge--other"
            onClick={() => setActivePopover({ row, event: ev, stage })}
            title={`Currently in ${allocatedEvent} - click to switch to ${ev.event_code}`}
          >
            In {allocatedEvent} ⇄
          </button>
        ) : (
          <button
            type="button"
            className="ems-matrix-badge ems-matrix-badge--available"
            onClick={() => setActivePopover({ row, event: ev, stage })}
            title="Available for this date - click for split-date guest placement"
          >
            Available +
          </button>
        )}
      </td>
    );
  };

  return (
    <div>
      {/* ── Stage Switcher + Refinement Bar ── */}
      <div className="ems-planning-refine-bar" role="search" aria-label="Stage Matrix Controls">
        <div className="ems-planning-scope-group">
          <span className="ems-planning-scope-label">STAGE:</span>
          <div className="ems-btn-group" role="group" aria-label="Stage Matrix Mode">
            {(
              [
                { key: 'both', label: 'Both Stages' },
                { key: 'practice', label: 'Practice' },
                { key: 'qualifier', label: 'Qualifier' },
              ] as const
            ).map(s => (
              <button
                key={s.key}
                type="button"
                className={`ems-scope-btn ${stageMode === s.key ? 'ems-scope-btn--active' : ''}`}
                onClick={() => setStageMode(s.key)}
              >
                {s.label}
              </button>
            ))}
          </div>
        </div>

        <div className="ems-search-box">
          <span className="ems-search-icon" aria-hidden="true">
            <FontAwesomeIcon icon={faMagnifyingGlass} />
          </span>
          <input
            type="text"
            className="ems-form-input ems-search-input"
            placeholder="Search explorer, team, or wishlist..."
            value={searchQuery}
            onChange={e => setSearchQuery(e.target.value)}
          />
          {searchQuery && (
            <button
              type="button"
              className="ems-clear-search-btn"
              aria-label="Clear search"
              onClick={() => setSearchQuery('')}
            >
              ×
            </button>
          )}
        </div>

        <div className="ems-refine-group">
          <label htmlFor="stage-matrix-district" className="ems-refine-label">District:</label>
          <select
            id="stage-matrix-district"
            className="ems-select-sm"
            value={districtFilter}
            onChange={e => setDistrictFilter(e.target.value)}
          >
            <option value="all">All Districts</option>
            {uniqueDistricts.map(d => (
              <option key={d} value={d}>{d}</option>
            ))}
            <option value="unassigned">Unassigned</option>
          </select>
        </div>

        <div className="ems-refine-group">
          <label htmlFor="stage-matrix-unit" className="ems-refine-label">Unit:</label>
          <select
            id="stage-matrix-unit"
            className="ems-select-sm"
            value={unitFilter}
            onChange={e => setUnitFilter(e.target.value)}
          >
            <option value="all">All Units ({cohortUnits.length})</option>
            {cohortUnits.map(u => (
              <option key={u} value={u}>{u}</option>
            ))}
          </select>
        </div>

        <div className="ems-refine-group">
          <label htmlFor="stage-matrix-asn" className="ems-refine-label">ASN:</label>
          <select
            id="stage-matrix-asn"
            className="ems-select-sm"
            value={asnFilter}
            onChange={e => setAsnFilter(e.target.value)}
          >
            <option value="all">All ASN</option>
            <option value="has_asn">Declared ASN</option>
            <option value="unreviewed">Unreviewed</option>
            <option value="action_needed">Action Needed</option>
            <option value="no_action">No Action Needed</option>
          </select>
        </div>

        <div className="ems-refine-group">
          <label htmlFor="stage-matrix-status" className="ems-refine-label">Status:</label>
          <select
            id="stage-matrix-status"
            className="ems-select-sm"
            value={statusFilter}
            onChange={e => setStatusFilter(e.target.value as 'all' | 'unallocated' | 'allocated')}
          >
            <option value="all">All ({combinedRows.length})</option>
            <option value="unallocated">Unassigned</option>
            <option value="allocated">Assigned</option>
          </select>
        </div>

        <div className="ems-cohort-count">
          Showing {filteredRows.length} of {combinedRows.length} explorers
        </div>
      </div>

      {/* ── Matrix Table ── */}
      {filteredRows.length === 0 ? (
        <div className="notice notice-warning ems-m-0">
          <p>No explorers match the current filters for {level.toUpperCase()} ({transport}).</p>
        </div>
      ) : (
        <div className="ems-matrix-table-wrap">
          <table className="ems-table ems-matrix-table">
            <thead>
              {/* Super-header row when Both Stages or stage groups exist */}
              <tr>
                <th colSpan={3} style={{ ...stickyHeaderBase, left: 0, textAlign: 'left' }}>
                  Explorer Identity &amp; Preferences
                </th>
                {visiblePracticeEvents.length > 0 && (
                  <th
                    colSpan={visiblePracticeEvents.length}
                    style={{
                      textAlign: 'center',
                      background: '#eff6ff',
                      color: '#1e40af',
                      borderLeft: '2px solid #cbd5e1',
                      fontWeight: 700,
                    }}
                  >
                    Practice Expeditions
                  </th>
                )}
                {visibleQualifierEvents.length > 0 && (
                  <th
                    colSpan={visibleQualifierEvents.length}
                    style={{
                      textAlign: 'center',
                      background: '#ecfdf5',
                      color: '#065f46',
                      borderLeft: stageMode === 'both' ? '3px solid #94a3b8' : '2px solid #cbd5e1',
                      fontWeight: 700,
                    }}
                  >
                    Qualifier Expeditions
                  </th>
                )}
                {visiblePracticeEvents.length === 0 && visibleQualifierEvents.length === 0 && (
                  <th style={{ textAlign: 'center' }}>No Planned Events</th>
                )}
              </tr>

              {/* Column headers row */}
              <tr>
                <th style={{ ...stickyHeaderBase, left: 0, minWidth: '170px', maxWidth: '170px' }}>Explorer</th>
                <th style={{ ...stickyHeaderBase, left: '170px', minWidth: '120px', maxWidth: '120px' }}>Unit</th>
                <th
                  style={{
                    ...stickyHeaderBase,
                    left: '290px',
                    minWidth: '170px',
                    maxWidth: '170px',
                    borderRight: '2px solid #cbd5e1',
                  }}
                >
                  Teammate Wishlist
                </th>

                {visiblePracticeEvents.map(ev => (
                  <th key={`p-${ev.id}`} className="ems-matrix-th-event">
                    <div>
                      {onOpenEvent && ev.id ? (
                        <button
                          type="button"
                          className="button-link"
                          style={{ fontWeight: 700, padding: 0 }}
                          onClick={() => onOpenEvent(ev.id)}
                          title={`Open ${ev.event_code} details`}
                        >
                          {ev.event_code}
                        </button>
                      ) : (
                        ev.event_code
                      )}
                    </div>
                    <span className="ems-matrix-th-date">{formatDateShort(ev.start_date)}</span>
                  </th>
                ))}

                {visibleQualifierEvents.map((ev, idx) => (
                  <th
                    key={`q-${ev.id}`}
                    className="ems-matrix-th-event"
                    style={stageMode === 'both' && idx === 0 ? { borderLeft: '3px solid #94a3b8' } : undefined}
                  >
                    <div>
                      {onOpenEvent && ev.id ? (
                        <button
                          type="button"
                          className="button-link"
                          style={{ fontWeight: 700, padding: 0 }}
                          onClick={() => onOpenEvent(ev.id)}
                          title={`Open ${ev.event_code} details`}
                        >
                          {ev.event_code}
                        </button>
                      ) : (
                        ev.event_code
                      )}
                    </div>
                    <span className="ems-matrix-th-date">{formatDateShort(ev.start_date)}</span>
                  </th>
                ))}

                {visiblePracticeEvents.length === 0 && visibleQualifierEvents.length === 0 && (
                  <th className="ems-matrix-th-pref">Availability</th>
                )}
              </tr>
            </thead>

            <tbody>
              {filteredRows.map(row => (
                <tr key={row.key}>
                  <td style={{ ...stickyCellBase, left: 0, minWidth: '170px', maxWidth: '170px' }}>
                    <div className="ems-table__name">
                      <span
                        className="ems-explorer-fullname ems-cursor-pointer"
                        onClick={() => setAsnDialogRow(row)}
                        title="Click to view/validate ASN"
                      >
                        {row.first_name} {row.last_name}
                      </span>
                      {row.additional_support_needs && row.additional_support_needs.trim() !== '' && (
                        row.asn_validated === 1 ? (
                          <span
                            className="ems-matrix-tag ems-matrix-tag--asn ems-matrix-tag--asn-action ems-cursor-pointer"
                            onClick={() => setAsnDialogRow(row)}
                          >
                            ! ASN
                          </span>
                        ) : row.asn_validated === 0 ? (
                          <span
                            className="ems-matrix-tag ems-matrix-tag--asn ems-matrix-tag--asn-no-action ems-cursor-pointer"
                            onClick={() => setAsnDialogRow(row)}
                          >
                            ✓ ASN
                          </span>
                        ) : (
                          <span
                            className="ems-matrix-tag ems-matrix-tag--asn ems-matrix-tag--asn-pending ems-cursor-pointer"
                            onClick={() => setAsnDialogRow(row)}
                          >
                            ? ASN
                          </span>
                        )
                      )}
                    </div>
                  </td>

                  <td style={{ ...stickyCellBase, left: '170px', minWidth: '120px', maxWidth: '120px' }}>
                    <span className="ems-matrix-unit-badge">{row.unit_name}</span>
                  </td>

                  <td
                    style={{
                      ...stickyCellBase,
                      left: '290px',
                      minWidth: '170px',
                      maxWidth: '170px',
                      borderRight: '2px solid #cbd5e1',
                    }}
                  >
                    <div className="ems-matrix-wishlist">{row.team_preferences || '—'}</div>
                  </td>

                  {visiblePracticeEvents.map(ev => renderEventCell(row, ev, 'practice', false))}

                  {visibleQualifierEvents.map((ev, idx) =>
                    renderEventCell(row, ev, 'qualifying', stageMode === 'both' && idx === 0)
                  )}

                  {visiblePracticeEvents.length === 0 && visibleQualifierEvents.length === 0 && (
                    <td style={{ fontSize: '12px', color: '#64748b' }}>
                      Prac: {row.practiceAvailable.join(', ') || '—'} | Qual:{' '}
                      {row.qualifierAvailable.join(', ') || '—'}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* ── Cell Popover: Split-Date Guest Placement ── */}
      {activePopover && (
        <>
          <div className="ems-popover-backdrop" onClick={() => setActivePopover(null)} />
          <div className="ems-assign-popover" role="dialog" aria-modal="true" aria-label="Assign Explorer">
            <h4 className="ems-assign-popover__title">
              {activePopover.row.first_name} {activePopover.row.last_name}
            </h4>
            <div className="ems-assign-popover__subtitle">
              Event: <strong>{activePopover.event.event_code}</strong> ({activePopover.event.title})
              <div style={{ fontSize: '11px', color: '#475569', marginTop: '2px' }}>
                Direct cell placement for split-date guest or individual team assignment.
              </div>
            </div>

            {activePopover.row.scout_id === 0 ? (
              <div className="notice notice-warning ems-mb-12">
                <p>Unmatched OSM explorer (`scout_id = 0`) cannot be placed via legacy scout allocation until matched.</p>
              </div>
            ) : (
              <div className="ems-assign-popover__list">
                <button
                  type="button"
                  className="ems-assign-popover__btn"
                  disabled={allocating}
                  onClick={() =>
                    executeAllocation(activePopover.row.scout_id, activePopover.event.event_code, 'new_team')
                  }
                >
                  <FontAwesomeIcon icon={faPlus} className="ems-popover-icon" /> Create New Team
                </button>

                <button
                  type="button"
                  className="ems-assign-popover__btn"
                  disabled={allocating}
                  onClick={() =>
                    executeAllocation(activePopover.row.scout_id, activePopover.event.event_code, 'unallocated')
                  }
                >
                  <FontAwesomeIcon icon={faClipboardList} className="ems-popover-icon" /> Event Pool (Unallocated)
                </button>

                {activePopover.event.teams
                  .filter(t => t.team_code !== 'UNALLOCATED')
                  .map(t => (
                    <button
                      key={t.id}
                      type="button"
                      className="ems-assign-popover__btn"
                      disabled={allocating}
                      onClick={() =>
                        executeAllocation(
                          activePopover.row.scout_id,
                          activePopover.event.event_code,
                          'existing_team',
                          t.id
                        )
                      }
                    >
                      <FontAwesomeIcon icon={faUsers} className="ems-popover-icon" /> Assign to {t.team_code} (
                      {t.member_count}/7)
                    </button>
                  ))}

                {((activePopover.stage === 'practice' &&
                  activePopover.row.practiceAllocatedEvent === activePopover.event.event_code) ||
                  (activePopover.stage === 'qualifying' &&
                    activePopover.row.qualifierAllocatedEvent === activePopover.event.event_code)) && (
                  <button
                    type="button"
                    className="button button-link-delete"
                    disabled={allocating}
                    onClick={() =>
                      executeAllocation(activePopover.row.scout_id, activePopover.event.event_code, 'remove')
                    }
                  >
                    <FontAwesomeIcon icon={faXmark} className="ems-popover-icon" /> Remove from this event
                  </button>
                )}
              </div>
            )}

            <button
              type="button"
              className="button button-secondary"
              onClick={() => setActivePopover(null)}
            >
              Cancel
            </button>
          </div>
        </>
      )}

      {/* ── ASN Leader Validation Dialogue ── */}
      {asnDialogRow && (
        <>
          <div
            className="ems-popover-backdrop"
            onClick={() => !asnUpdating && setAsnDialogRow(null)}
          />
          <div className="ems-asn-dialog" role="dialog" aria-modal="true" aria-label="ASN Leader Validation">
            <div className="ems-asn-dialog__header">
              <h3 className="ems-asn-dialog__title">
                ASN Review — {asnDialogRow.first_name} {asnDialogRow.last_name}
              </h3>
              <button
                type="button"
                className="ems-asn-dialog__close"
                onClick={() => setAsnDialogRow(null)}
                disabled={asnUpdating}
                aria-label="Close"
              >
                <FontAwesomeIcon icon={faXmark} />
              </button>
            </div>
            <div className="ems-asn-dialog__body">
              <div className="ems-mb-12">
                <span className="ems-signups-inspector__label">Declared Support Needs:</span>
                <div className="ems-signups-inspector__support-box ems-mt-4">
                  {asnDialogRow.additional_support_needs || 'None declared.'}
                </div>
              </div>
              <div className="ems-mb-16">
                <span className="ems-signups-inspector__label">Current Status:</span>
                <div className="ems-mt-4">
                  {asnDialogRow.asn_validated === 1 ? (
                    <span className="ems-pill ems-pill--asn-action">Action Needed</span>
                  ) : asnDialogRow.asn_validated === 0 ? (
                    <span className="ems-pill ems-pill--asn-no-action">No Action Needed</span>
                  ) : (
                    <span className="ems-pill ems-pill--asn-pending">Unreviewed</span>
                  )}
                </div>
              </div>
            </div>
            <div className="ems-flex-center ems-gap-8 ems-flex-wrap">
              <button
                type="button"
                className="button ems-btn--asn-action"
                disabled={asnUpdating || asnDialogRow.scout_id === 0}
                onClick={() => handleUpdateAsnValidation(asnDialogRow.scout_id, 1)}
              >
                <FontAwesomeIcon icon={faTriangleExclamation} /> Action Needed
              </button>
              <button
                type="button"
                className="button ems-btn--asn-no-action"
                disabled={asnUpdating || asnDialogRow.scout_id === 0}
                onClick={() => handleUpdateAsnValidation(asnDialogRow.scout_id, 0)}
              >
                <FontAwesomeIcon icon={faCheck} /> No Action Needed
              </button>
              <button
                type="button"
                className="button ems-btn--asn-unreview"
                disabled={asnUpdating || asnDialogRow.scout_id === 0}
                onClick={() => handleUpdateAsnValidation(asnDialogRow.scout_id, null)}
              >
                <FontAwesomeIcon icon={faCircleQuestion} /> Unreviewed
              </button>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
