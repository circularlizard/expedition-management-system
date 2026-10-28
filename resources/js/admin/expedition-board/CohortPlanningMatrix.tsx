import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faPlus,
  faLock,
  faUsers,
  faXmark,
  faMagnifyingGlass,
  faClipboardList,
  faPersonHiking,
  faBicycle,
  faWater
} from '@fortawesome/free-solid-svg-icons';

export interface MatrixTeam {
  id: number;
  team_code: string;
  member_count: number;
  scout_ids: number[];
}

export interface MatrixEvent {
  id: number;
  event_code: string;
  title: string;
  level: string;
  type: string;
  transport: string;
  start_date: string;
  end_date: string;
  teams: MatrixTeam[];
}

export interface MatrixExplorer {
  scout_id: number;
  first_name: string;
  last_name: string;
  unit_name: string;
  team_preferences: string;
  available_events: string[];
  allocated_event_code: string | null;
  allocated_team_code: string | null;
  allocated_team_id: number | null;
  has_asn: boolean;
  additional_support_needs: string;
  first_aid_status: string;
}

export interface CohortPlanningMatrixProps {
  onViewAsn?: (scoutId: number) => void;
}

function formatDateShort(dateStr?: string): string {
  if (!dateStr) return '';
  try {
    const parts = dateStr.split('-');
    if (parts.length === 3) {
      const day = parseInt(parts[2], 10);
      const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      const month = months[parseInt(parts[1], 10) - 1] || '';
      return `${day} ${month}`;
    }
    return dateStr;
  } catch {
    return dateStr;
  }
}

export default function CohortPlanningMatrix({ onViewAsn }: CohortPlanningMatrixProps = {}) {
  const config = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '' };
  const rootUrl = config.root_url;
  const nonce = config.nonce;

  // ── Layer 1: Hard Filter / Cohort Scope ─────────────────────────────────────
  const [level, setLevel] = useState<'bronze' | 'silver' | 'gold'>('silver');
  const [transport, setTransport] = useState<string>('hillwalking');
  const [stage, setStage] = useState<'practice' | 'qualifying'>('practice');

  // Available transports per level
  const allowedTransports = useMemo(() => {
    if (level === 'bronze') {
      return [
        { key: 'hillwalking', label: 'Hillwalking', icon: faPersonHiking },
        { key: 'paddling', label: 'Paddling', icon: faWater },
      ];
    }
    return [
      { key: 'hillwalking', label: 'Hillwalking', icon: faPersonHiking },
      { key: 'biking', label: 'Biking', icon: faBicycle },
    ];
  }, [level]);

  // Adjust transport when switching level
  useEffect(() => {
    const valid = allowedTransports.some(t => t.key === transport);
    if (!valid) {
      setTransport('hillwalking');
    }
  }, [allowedTransports, transport]);

  // ── Matrix Data State ───────────────────────────────────────────────────────
  const [events, setEvents] = useState<MatrixEvent[]>([]);
  const [explorers, setExplorers] = useState<MatrixExplorer[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<{ ok: boolean; msg: string } | null>(null);

  // ── Layer 2: Refinement & Search ───────────────────────────────────────────
  const [searchQuery, setSearchQuery] = useState('');
  const [filterUnit, setFilterUnit] = useState('all');
  const [filterStatus, setFilterStatus] = useState<'all' | 'unallocated' | 'allocated'>('all');
  const [sortKey, setSortKey] = useState<'name' | 'unit'>('name');

  // ── Selection & Allocation State ───────────────────────────────────────────
  const [selectedScoutIds, setSelectedScoutIds] = useState<number[]>([]);
  const [activePopover, setActivePopover] = useState<{
    explorer: MatrixExplorer;
    event: MatrixEvent;
  } | null>(null);
  const [bulkEventCode, setBulkEventCode] = useState('');
  const [bulkTargetMode, setBulkTargetMode] = useState<string>('new_team');
  const [allocating, setAllocating] = useState(false);

  // ── Fetch Matrix Data ───────────────────────────────────────────────────────
  const fetchMatrix = useCallback(async () => {
    setLoading(true);
    setError(null);
    setSelectedScoutIds([]);
    setActivePopover(null);

    try {
      const params = new URLSearchParams({
        level,
        type: stage,
        transport,
      });
      const resp = await fetch(`${rootUrl}/planning-board/matrix?${params.toString()}`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      if (!resp.ok) {
        throw new Error(`HTTP ${resp.status}`);
      }
      const data = await resp.json();
      setEvents(Array.isArray(data.events) ? data.events : []);
      setExplorers(Array.isArray(data.explorers) ? data.explorers : []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load cohort matrix data');
    } finally {
      setLoading(false);
    }
  }, [level, stage, transport, rootUrl, nonce]);

  useEffect(() => {
    fetchMatrix();
  }, [fetchMatrix]);

  // Automatically select first event in bulk select bar if only one exists or empty
  useEffect(() => {
    if (events.length > 0) {
      if (!bulkEventCode || !events.some(e => e.event_code === bulkEventCode)) {
        setBulkEventCode(events[0].event_code);
      }
    } else {
      setBulkEventCode('');
    }
  }, [events, bulkEventCode]);

  // ── Cohort Units for Layer 2 Filter ────────────────────────────────────────
  const cohortUnits = useMemo(() => {
    const units = new Set<string>();
    explorers.forEach(e => {
      if (e.unit_name) units.add(e.unit_name);
    });
    return Array.from(units).sort();
  }, [explorers]);

  // ── Layer 2 Filtering Logic ────────────────────────────────────────────────
  const filteredExplorers = useMemo(() => {
    return explorers.filter(exp => {
      // Unit filter
      if (filterUnit !== 'all' && exp.unit_name !== filterUnit) {
        return false;
      }
      // Status filter
      if (filterStatus === 'unallocated' && exp.allocated_event_code) {
        return false;
      }
      if (filterStatus === 'allocated' && !exp.allocated_event_code) {
        return false;
      }
      // Free-text search: searches Explorer Name AND Teammate Wishlist!
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase().trim();
        const fullName = `${exp.first_name} ${exp.last_name}`.toLowerCase();
        const wishlist = (exp.team_preferences || '').toLowerCase();
        const unit = (exp.unit_name || '').toLowerCase();
        if (!fullName.includes(q) && !wishlist.includes(q) && !unit.includes(q)) {
          return false;
        }
      }
      return true;
    }).sort((a, b) => {
      if (sortKey === 'unit') {
        const cmp = a.unit_name.localeCompare(b.unit_name);
        if (cmp !== 0) return cmp;
      }
      const nameA = `${a.first_name} ${a.last_name}`;
      const nameB = `${b.first_name} ${b.last_name}`;
      return nameA.localeCompare(nameB);
    });
  }, [explorers, filterUnit, filterStatus, searchQuery, sortKey]);

  // ── Checkbox Selection ─────────────────────────────────────────────────────
  const visibleScoutIds = useMemo(() => filteredExplorers.map(e => e.scout_id), [filteredExplorers]);
  const allSelected = visibleScoutIds.length > 0 && visibleScoutIds.every(id => selectedScoutIds.includes(id));
  const someSelected = visibleScoutIds.some(id => selectedScoutIds.includes(id)) && !allSelected;

  const toggleSelectAll = () => {
    if (allSelected) {
      setSelectedScoutIds(prev => prev.filter(id => !visibleScoutIds.includes(id)));
    } else {
      setSelectedScoutIds(prev => Array.from(new Set([...prev, ...visibleScoutIds])));
    }
  };

  const toggleSelectExplorer = (scoutId: number) => {
    setSelectedScoutIds(prev =>
      prev.includes(scoutId) ? prev.filter(id => id !== scoutId) : [...prev, scoutId]
    );
  };

  // ── Allocation Execution ───────────────────────────────────────────────────
  const executeAllocation = async (
    scoutIds: number[],
    eventCode: string,
    mode: 'unallocated' | 'new_team' | 'existing_team' | 'remove',
    teamId?: number
  ) => {
    if (scoutIds.length === 0 || !eventCode) return;
    setAllocating(true);
    setFeedback(null);

    const body: Record<string, unknown> = {
      scout_ids: scoutIds,
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
      const count = scoutIds.length;
      const actionDesc = mode === 'remove' ? 'unallocated from event' : 'allocated successfully';
      setFeedback({ ok: true, msg: `${count} explorer(s) ${actionDesc}.` });
      setActivePopover(null);
      setSelectedScoutIds([]);
      await fetchMatrix();
    } catch (err) {
      setFeedback({ ok: false, msg: err instanceof Error ? err.message : String(err) });
    } finally {
      setAllocating(false);
    }
  };

  // Bulk Allocation handler
  const handleBulkAllocate = () => {
    if (!bulkEventCode || selectedScoutIds.length === 0) return;
    if (bulkTargetMode === 'remove') {
      executeAllocation(selectedScoutIds, bulkEventCode, 'remove');
    } else if (bulkTargetMode === 'unallocated') {
      executeAllocation(selectedScoutIds, bulkEventCode, 'unallocated');
    } else if (bulkTargetMode === 'new_team') {
      executeAllocation(selectedScoutIds, bulkEventCode, 'new_team');
    } else if (bulkTargetMode.startsWith('team_')) {
      const teamId = parseInt(bulkTargetMode.replace('team_', ''), 10);
      executeAllocation(selectedScoutIds, bulkEventCode, 'existing_team', teamId);
    }
  };

  const selectedBulkEvent = useMemo(() => {
    return events.find(e => e.event_code === bulkEventCode) || null;
  }, [events, bulkEventCode]);

  return (
    <div className="ems-planning-matrix">
      {/* ── Feedback Notice ── */}
      {feedback && (
        <div className={`notice ${feedback.ok ? 'notice-success' : 'notice-error'} is-dismissible ems-mb-16`}>
          <p>{feedback.msg}</p>
          <button type="button" className="notice-dismiss" onClick={() => setFeedback(null)}>
            <span className="screen-reader-text">Dismiss</span>
          </button>
        </div>
      )}

      {error && (
        <div className="notice notice-error ems-mb-16">
          <p>{error}</p>
        </div>
      )}

      {/* ── LAYER 1: Hard Filter / Cohort Scope ── */}
      <div className="ems-planning-scope-bar" role="region" aria-label="Cohort Scope Filters">
        <div className="ems-planning-scope-group">
          <span className="ems-planning-scope-label">Level:</span>
          <div className="ems-btn-group" role="group" aria-label="Level Selector">
            {(['bronze', 'silver', 'gold'] as const).map(l => (
              <button
                key={l}
                type="button"
                className={`ems-scope-btn ${level === l ? 'ems-scope-btn--active' : ''}`}
                onClick={() => setLevel(l)}
              >
                {l.charAt(0).toUpperCase() + l.slice(1)}
              </button>
            ))}
          </div>
        </div>

        <div className="ems-planning-scope-group">
          <span className="ems-planning-scope-label">Mode:</span>
          <div className="ems-btn-group" role="group" aria-label="Transport Mode Selector">
            {allowedTransports.map(t => (
              <button
                key={t.key}
                type="button"
                className={`ems-scope-btn ${transport === t.key ? 'ems-scope-btn--active' : ''}`}
                onClick={() => setTransport(t.key)}
              >
                <FontAwesomeIcon icon={t.icon} className="ems-scope-btn-icon" /> {t.label}
              </button>
            ))}
          </div>
        </div>

        <div className="ems-planning-scope-group">
          <span className="ems-planning-scope-label">Stage:</span>
          <div className="ems-btn-group" role="group" aria-label="Stage Selector">
            {(['practice', 'qualifying'] as const).map(s => (
              <button
                key={s}
                type="button"
                className={`ems-scope-btn ${stage === s ? 'ems-scope-btn--active' : ''}`}
                onClick={() => setStage(s)}
              >
                {s === 'qualifying' ? 'Qualifier' : 'Practice'}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* ── LAYER 2: Cohort Refinement & Search ── */}
      <div className="ems-planning-refine-bar" role="search" aria-label="Cohort Refinement">
        <div className="ems-search-box">
          <span className="ems-search-icon" aria-hidden="true">
            <FontAwesomeIcon icon={faMagnifyingGlass} />
          </span>
          <input
            type="text"
            className="ems-form-input ems-search-input"
            placeholder="Search explorer name or wishlist text..."
            aria-label="Search explorer or wishlist"
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
          <label htmlFor="matrix-unit-filter" className="ems-refine-label">Unit:</label>
          <select
            id="matrix-unit-filter"
            className="ems-select-sm"
            value={filterUnit}
            onChange={e => setFilterUnit(e.target.value)}
          >
            <option value="all">All Units ({cohortUnits.length})</option>
            {cohortUnits.map(u => (
              <option key={u} value={u}>{u}</option>
            ))}
          </select>
        </div>

        <div className="ems-refine-group">
          <label htmlFor="matrix-status-filter" className="ems-refine-label">Status:</label>
          <select
            id="matrix-status-filter"
            className="ems-select-sm"
            value={filterStatus}
            onChange={e => setFilterStatus(e.target.value as any)}
          >
            <option value="all">All ({explorers.length})</option>
            <option value="unallocated">Unassigned ({explorers.filter(e => !e.allocated_event_code).length})</option>
            <option value="allocated">Assigned ({explorers.filter(e => !!e.allocated_event_code).length})</option>
          </select>
        </div>

        <div className="ems-refine-group">
          <label htmlFor="matrix-sort-by" className="ems-refine-label">Sort:</label>
          <select
            id="matrix-sort-by"
            className="ems-select-sm"
            value={sortKey}
            onChange={e => setSortKey(e.target.value as 'name' | 'unit')}
          >
            <option value="name">Name (A–Z)</option>
            <option value="unit">Unit</option>
          </select>
        </div>

        <div className="ems-cohort-count">
          Showing {filteredExplorers.length} of {explorers.length} explorers
        </div>
      </div>

      {/* ── Table Content ── */}
      {loading ? (
        <div className="ems-planning-spinner">
          <span className="ems-spinner" aria-label="Loading cohort matrix…" />
        </div>
      ) : events.length === 0 ? (
        <div className="notice notice-info ems-m-0">
          <p>
            No events found for <strong>{level.toUpperCase()} {stage.toUpperCase()}</strong> ({transport}).
            Create events in the Events Dashboard to plan this cohort.
          </p>
        </div>
      ) : filteredExplorers.length === 0 ? (
        <div className="notice notice-warning ems-m-0">
          <p>
            No explorers match the current filters or search query in this cohort.
          </p>
        </div>
      ) : (
        <div className="ems-matrix-table-wrap">
          <table className="ems-table ems-matrix-table">
            <thead>
              <tr>
                <th className="ems-planning-checkbox-col">
                  <input
                    type="checkbox"
                    className="ems-checkbox"
                    checked={allSelected}
                    ref={el => { if (el) el.indeterminate = someSelected; }}
                    onChange={toggleSelectAll}
                    aria-label="Select all visible explorers"
                  />
                </th>
                <th>Explorer</th>
                <th>Unit</th>
                <th>Teammate Wishlist</th>
                {events.map(ev => {
                  const assignedCount = explorers.filter(e => e.allocated_event_code === ev.event_code).length;
                  const poolCount = explorers.filter(e => e.allocated_event_code === ev.event_code && e.allocated_team_code === 'UNALLOCATED').length;
                  return (
                    <th key={ev.id} className="ems-matrix-th-event">
                      <div>{ev.event_code}</div>
                      <span className="ems-matrix-th-date">{formatDateShort(ev.start_date)}</span>
                      <div className="ems-matrix-th-stats">
                        <span className="ems-matrix-stat-pill" title={`${assignedCount - poolCount} in teams, ${poolCount} in event pool`}>
                          {assignedCount} alloc{poolCount > 0 ? ` (${poolCount} pool)` : ''}
                        </span>
                      </div>
                    </th>
                  );
                })}
              </tr>
            </thead>
            <tbody>
              {filteredExplorers.map(exp => {
                const isSelected = selectedScoutIds.includes(exp.scout_id);
                return (
                  <tr key={exp.scout_id} className={isSelected ? 'ems-table-row--selected' : ''}>
                    <td className="ems-table-cell--center">
                      <input
                        type="checkbox"
                        className="ems-checkbox"
                        checked={isSelected}
                        onChange={() => toggleSelectExplorer(exp.scout_id)}
                        aria-label={`Select ${exp.first_name} ${exp.last_name}`}
                      />
                    </td>
                    <td>
                      <div className="ems-table__name">
                        <span className="ems-explorer-fullname">{`${exp.first_name} ${exp.last_name}`}</span>
                        {exp.first_aid_status && exp.first_aid_status !== 'none' && (
                          <span className="ems-matrix-tag ems-matrix-tag--fa" title={`First Aid: ${exp.first_aid_status}`}>
                            FA
                          </span>
                        )}
                        {exp.has_asn && (
                          <span
                            className="ems-matrix-tag ems-matrix-tag--asn"
                            title={`ASN: ${exp.additional_support_needs || 'Yes'}`}
                            onClick={() => onViewAsn && onViewAsn(exp.scout_id)}
                          >
                            ASN
                          </span>
                        )}
                      </div>
                    </td>
                    <td>
                      <span className="ems-matrix-unit-badge">{exp.unit_name}</span>
                    </td>
                    <td>
                      <div className="ems-matrix-wishlist">
                        {exp.team_preferences || '—'}
                      </div>
                    </td>
                    {events.map(ev => {
                      const isAvailable = exp.available_events.includes(ev.event_code);
                      const isAssignedHere = exp.allocated_event_code === ev.event_code;
                      const isAssignedOther = exp.allocated_event_code && exp.allocated_event_code !== ev.event_code;

                      return (
                        <td key={ev.id} className="ems-matrix-cell-event">
                          {isAssignedHere ? (
                            <button
                              type="button"
                              className="ems-matrix-badge ems-matrix-badge--assigned"
                              onClick={() => setActivePopover({ explorer: exp, event: ev })}
                              title="Assigned to this event - click to manage team"
                            >
                              <FontAwesomeIcon icon={faLock} className="ems-matrix-badge-icon" />{' '}
                              {exp.allocated_team_code === 'UNALLOCATED' ? 'Pool' : exp.allocated_team_code}
                            </button>
                          ) : !isAvailable ? (
                            <span className="ems-matrix-dash" aria-label="Not available">—</span>
                          ) : isAssignedOther ? (
                            <button
                              type="button"
                              className="ems-matrix-badge ems-matrix-badge--other"
                              onClick={() => setActivePopover({ explorer: exp, event: ev })}
                              title={`Currently in ${exp.allocated_event_code} - click to switch to ${ev.event_code}`}
                            >
                              In {exp.allocated_event_code} ⇄
                            </button>
                          ) : (
                            <button
                              type="button"
                              className="ems-matrix-badge ems-matrix-badge--available"
                              onClick={() => setActivePopover({ explorer: exp, event: ev })}
                              title="Available for this date - click to assign"
                            >
                              Available +
                            </button>
                          )}
                        </td>
                      );
                    })}
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={4} className="ems-matrix-footer-cell">
                  Cohort Totals: {filteredExplorers.length} visible ({explorers.filter(e => !e.allocated_event_code).length} unallocated)
                </td>
                {events.map(ev => {
                  const availableCount = explorers.filter(e => e.available_events.includes(ev.event_code)).length;
                  const assignedCount = explorers.filter(e => e.allocated_event_code === ev.event_code).length;
                  const poolCount = explorers.filter(e => e.allocated_event_code === ev.event_code && e.allocated_team_code === 'UNALLOCATED').length;
                  const teamCount = assignedCount - poolCount;

                  return (
                    <td key={ev.id} className="ems-matrix-footer-cell">
                      <div><strong>{assignedCount}</strong> / {availableCount}</div>
                      {poolCount > 0 && (
                        <div className="ems-meta-text ems-small-text">
                          ({teamCount} team{teamCount === 1 ? '' : 's'}, {poolCount} pool)
                        </div>
                      )}
                    </td>
                  );
                })}
              </tr>
            </tfoot>
          </table>
        </div>
      )}

      {/* ── Cell Popover: Quick Allocation ── */}
      {activePopover && (
        <>
          <div className="ems-popover-backdrop" onClick={() => setActivePopover(null)} />
          <div className="ems-assign-popover" role="dialog" aria-modal="true" aria-label="Assign Explorer">
            <h4 className="ems-assign-popover__title">
              {activePopover.explorer.first_name} {activePopover.explorer.last_name}
            </h4>
            <div className="ems-assign-popover__subtitle">
              Event: <strong>{activePopover.event.event_code}</strong> ({activePopover.event.title})
              {activePopover.explorer.allocated_event_code &&
                activePopover.explorer.allocated_event_code !== activePopover.event.event_code && (
                  <div className="ems-popover-switch-alert">
                    Switching from <strong>{activePopover.explorer.allocated_event_code}</strong>
                  </div>
                )}
            </div>

            <div className="ems-assign-popover__list">
              <button
                type="button"
                className="ems-assign-popover__btn"
                disabled={allocating}
                onClick={() =>
                  executeAllocation([activePopover.explorer.scout_id], activePopover.event.event_code, 'new_team')
                }
              >
                <FontAwesomeIcon icon={faPlus} className="ems-popover-icon" /> Create New Team
              </button>

              <button
                type="button"
                className="ems-assign-popover__btn"
                disabled={allocating}
                onClick={() =>
                  executeAllocation([activePopover.explorer.scout_id], activePopover.event.event_code, 'unallocated')
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
                        [activePopover.explorer.scout_id],
                        activePopover.event.event_code,
                        'existing_team',
                        t.id
                      )
                    }
                  >
                    <FontAwesomeIcon icon={faUsers} className="ems-popover-icon" /> Assign to {t.team_code} ({t.member_count}/7)
                  </button>
                ))}

              {activePopover.explorer.allocated_event_code === activePopover.event.event_code && (
                <button
                  type="button"
                  className="button button-link-delete"
                  disabled={allocating}
                  onClick={() =>
                    executeAllocation([activePopover.explorer.scout_id], activePopover.event.event_code, 'remove')
                  }
                >
                  <FontAwesomeIcon icon={faXmark} className="ems-popover-icon" /> Remove from this event
                </button>
              )}
            </div>

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

      {/* ── Sticky Bottom Multi-Select Action Bar ── */}
      {selectedScoutIds.length > 0 && (
        <div className="ems-planning-action-bar" role="region" aria-label="Bulk Allocation Bar">
          <div className="ems-action-bar-info">
            <strong>{selectedScoutIds.length}</strong> explorer{selectedScoutIds.length > 1 ? 's' : ''} selected
          </div>
          <div className="ems-action-bar-controls">
            <label htmlFor="bulk-event-select" className="ems-action-bar-label">Event:</label>
            <select
              id="bulk-event-select"
              className="ems-select-sm"
              value={bulkEventCode}
              onChange={e => setBulkEventCode(e.target.value)}
            >
              <option value="">-- Choose Event --</option>
              {events.map(ev => (
                <option key={ev.event_code} value={ev.event_code}>
                  {ev.event_code} ({formatDateShort(ev.start_date)})
                </option>
              ))}
            </select>

            <label htmlFor="bulk-team-select" className="ems-action-bar-label">Assign to:</label>
            <select
              id="bulk-team-select"
              className="ems-select-sm"
              value={bulkTargetMode}
              onChange={e => setBulkTargetMode(e.target.value)}
            >
              <option value="new_team">+ Create New Team</option>
              <option value="unallocated">Event Pool (Unallocated)</option>
              {selectedBulkEvent?.teams
                .filter(t => t.team_code !== 'UNALLOCATED')
                .map(t => (
                  <option key={t.id} value={`team_${t.id}`}>
                    {t.team_code} ({t.member_count}/7)
                  </option>
                ))}
              <option value="remove">Remove Allocation</option>
            </select>

            <button
              type="button"
              className="button button-primary"
              disabled={!bulkEventCode || allocating}
              onClick={handleBulkAllocate}
            >
              {allocating ? 'Allocating…' : 'Apply Allocation'}
            </button>
            <button
              type="button"
              className="button button-secondary"
              onClick={() => setSelectedScoutIds([])}
            >
              Clear Selection
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
