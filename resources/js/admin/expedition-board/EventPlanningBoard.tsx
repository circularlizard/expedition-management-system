import React, { useEffect, useState, useCallback } from 'react';

interface PlanningEvent {
  id:              number;
  event_code:      string;
  title:           string;
  level:           string;
  type:            string;
  status:          string;
  start_date:      string;
  end_date:        string;
  available_count: number;
  allocated_count: number;
}

interface PlanningExplorer {
  scout_id:              number;
  first_name:            string;
  last_name:             string;
  unit_name:             string;
  dofe_level:            string;
  allocated_team_code?:  string;
  allocated_event_code?: string;
  team_preferences?:     string;
}

interface EventTeam {
  ID:            number;
  ems_team_code: string;
}

type AllocationMode = 'unallocated' | 'new_team' | 'existing_team';

function Spinner() {
  return <span className="ems-spinner" aria-label="Loading…" />;
}

interface EventPlanningBoardProps {
  event?: PlanningEvent | null;
  onTeamChanged?: () => void;
  onViewAsn?: (scoutId: number) => void;
}

export default function EventPlanningBoard({ event = null, onTeamChanged, onViewAsn }: EventPlanningBoardProps = {}) {
  const config  = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '' };
  const rootUrl = config.root_url;
  const nonce   = config.nonce;

  const [events,      setEvents]      = useState<PlanningEvent[]>([]);
  const [loading,     setLoading]     = useState(false);
  const [error,       setError]       = useState<string | null>(null);

  const [selectedEvent,    setSelectedEvent]    = useState<PlanningEvent | null>(event);
  const [explorers,        setExplorers]        = useState<PlanningExplorer[]>([]);
  const [explorersLoading, setExplorersLoading] = useState(false);
  const [selectedScoutIds, setSelectedScoutIds] = useState<number[]>([]);
  const [sortKey,          setSortKey]          = useState<'name' | 'unit'>('name');
  const [sortOrder,        setSortOrder]        = useState<'asc' | 'desc'>('asc');
  const [filterUnit,       setFilterUnit]       = useState<string>('all');
  const [allocationMode,   setAllocationMode]   = useState<AllocationMode>('unallocated');
  const [targetTeamId,     setTargetTeamId]     = useState<number>(0);
  const [eventTeams,       setEventTeams]       = useState<EventTeam[]>([]);
  const [feedback,         setFeedback]         = useState<{ ok: boolean; msg: string } | null>(null);
  const [dragOverZone,     setDragOverZone]     = useState<string | null>(null);
  const [collapsedTeams,   setCollapsedTeams]   = useState<Record<string, boolean>>({});
  const [hideAssigned,     setHideAssigned]     = useState(false);
  const [rosterCollapsed,  setRosterCollapsed]  = useState(!!event);

  // ── Load planning board events list ─────────────────────────────────────────────
  useEffect(() => {
    if (event) {
      setSelectedEvent(event);
      return;
    }
    setLoading(true);
    setError(null);
    setSelectedEvent(null);
    setExplorers([]);
    setSelectedScoutIds([]);

    fetch(`${rootUrl}/planning-board`, {
      headers: { 'X-WP-Nonce': nonce },
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.statusText))
      .then(data => setEvents(Array.isArray(data) ? data : []))
      .catch(e => setError(`Failed to load planning board: ${e}`))
      .finally(() => setLoading(false));
  }, [rootUrl, nonce, event]);

  // ── Load explorers availability reactively ─────────────────────────────────
  useEffect(() => {
    if (!selectedEvent) return;

    setExplorers([]);
    setSelectedScoutIds([]);
    setEventTeams([]);
    setAllocationMode('unallocated');
    setFilterUnit('all');
    setFeedback(null);
    setError(null);
    setExplorersLoading(true);

    fetch(`${rootUrl}/planning-board/availability/${selectedEvent.event_code}`, {
      headers: { 'X-WP-Nonce': nonce },
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.statusText))
      .then(data => {
        setExplorers((data && data.explorers) || []);
        setEventTeams((data && data.teams) || []);
      })
      .catch(e => setError(`Failed to load availability: ${e}`))
      .finally(() => setExplorersLoading(false));
  }, [selectedEvent, rootUrl, nonce]);

  // ── Refetch helper ─────────────────────────────────────────────────────────
  const refetchAvailability = useCallback(async () => {
    if (!selectedEvent) return;
    try {
      const resp = await fetch(`${rootUrl}/planning-board/availability/${selectedEvent.event_code}`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      if (resp.ok) {
        const resData = await resp.json();
        setExplorers(resData.explorers || []);
        setEventTeams(resData.teams || []);
        setSelectedScoutIds([]);
        if (onTeamChanged) onTeamChanged();
      }
    } catch (e) {
      console.error('Failed to refetch availability', e);
    }
  }, [selectedEvent, rootUrl, nonce, onTeamChanged]);

  const handleSelectEvent = useCallback((ev: PlanningEvent) => {
    setSelectedEvent(ev);
  }, []);

  // ── Checkbox helpers ───────────────────────────────────────────────────────
  const handleSelectExplorer = (scout_id: number) =>
    setSelectedScoutIds(prev =>
      prev.includes(scout_id) ? prev.filter(id => id !== scout_id) : [...prev, scout_id]
    );

  const handleToggleSelectAll = () => {
    const visibleIds = sortedExplorers.filter(e => !e.allocated_event_code).map(e => e.scout_id);
    const allVisibleSelected = visibleIds.every(id => selectedScoutIds.includes(id));
    if (allVisibleSelected) {
      setSelectedScoutIds(prev => prev.filter(id => !visibleIds.includes(id)));
    } else {
      setSelectedScoutIds(prev => Array.from(new Set([...prev, ...visibleIds])));
    }
  };

  // ── Apply allocation action (reusable for drag-drop and manual select) ───
  const allocateExplorers = async (scoutIds: number[], mode: AllocationMode | 'remove', teamId?: number) => {
    if (!selectedEvent || scoutIds.length === 0) return;
    setLoading(true);
    setFeedback(null);

    const body: Record<string, unknown> = {
      scout_ids:  scoutIds,
      event_code: selectedEvent.event_code,
      mode:       mode,
    };
    if (mode === 'existing_team' && teamId) body.team_id = teamId;

    try {
      const res  = await fetch(`${rootUrl}/planning-board/allocate`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body:    JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data?.message || res.statusText);
      const actionMsg = mode === 'remove' ? 'removed from this event.' : 'allocated.';
      setFeedback({ ok: true, msg: `${data.allocated ?? scoutIds.length} explorer(s) ${actionMsg}` });
      setSelectedScoutIds([]);
       await refetchAvailability();
    } catch (e: unknown) {
      setFeedback({ ok: false, msg: e instanceof Error ? e.message : String(e) });
    } finally {
      setLoading(false);
    }
  };

  const handleApplyAction = () => {
    allocateExplorers(selectedScoutIds, allocationMode, targetTeamId);
  };

  // ── Drag & Drop Event Handlers ───────────────────────────────────────────
  const handleDragStart = (e: React.DragEvent, scoutId: number) => {
    const dragIds = selectedScoutIds.includes(scoutId) ? selectedScoutIds : [scoutId];
    e.dataTransfer.setData('application/json', JSON.stringify({ scoutIds: dragIds }));
    e.dataTransfer.effectAllowed = 'move';
  };

  const handleDragOver = (e: React.DragEvent, zoneId: string) => {
    e.preventDefault();
    setDragOverZone(zoneId);
  };

  const handleDragLeave = () => {
    setDragOverZone(null);
  };

  const handleDrop = (e: React.DragEvent, mode: AllocationMode, teamId?: number) => {
    e.preventDefault();
    setDragOverZone(null);
    try {
      const json = e.dataTransfer.getData('application/json');
      if (!json) return;
      const { scoutIds } = JSON.parse(json);
      if (Array.isArray(scoutIds) && scoutIds.length > 0) {
        void allocateExplorers(scoutIds, mode, teamId);
      }
    } catch (err) {
      console.error('Failed to parse dropped explorer data', err);
    }
  };

  // ── Sorting & Filtering ───────────────────────────────────────────────────
  const availableUnits = Array.from(new Set(explorers.map(e => e.unit_name).filter(Boolean))).sort();

  const handleHeaderSort = (key: 'name' | 'unit') => {
    if (sortKey === key) {
      setSortOrder(prev => prev === 'asc' ? 'desc' : 'asc');
    } else {
      setSortKey(key);
      setSortOrder('asc');
    }
  };

  const renderSortIndicator = (key: 'name' | 'unit') => {
    if (sortKey !== key) return <span className="ems-sort-indicator">⇅</span>;
    return sortOrder === 'asc' ? <span className="ems-sort-indicator">▲</span> : <span className="ems-sort-indicator">▼</span>;
  };

  const filteredExplorers = explorers.filter(exp => {
    if (filterUnit !== 'all' && exp.unit_name !== filterUnit) return false;
    if (hideAssigned && exp.allocated_event_code) return false;
    return true;
  });

  const sortedExplorers = [...filteredExplorers].sort((a, b) => {
    let valA = '';
    let valB = '';
    if (sortKey === 'unit') {
      valA = a.unit_name || '';
      valB = b.unit_name || '';
    } else {
      valA = `${a.first_name} ${a.last_name}`;
      valB = `${b.first_name} ${b.last_name}`;
    }
    const cmp = valA.localeCompare(valB);
    return sortOrder === 'asc' ? cmp : -cmp;
  });

  const unassignedVisible = sortedExplorers.filter(e => !e.allocated_event_code);
  const allSelected  = unassignedVisible.length > 0 && unassignedVisible.every(e => selectedScoutIds.includes(e.scout_id));
  const someSelected = unassignedVisible.length > 0 && unassignedVisible.some(e => selectedScoutIds.includes(e.scout_id)) && !allSelected;

  // ── Collapse helpers ──
  const toggleCollapse = (teamKey: string) => {
    setCollapsedTeams(prev => ({ ...prev, [teamKey]: !prev[teamKey] }));
  };

  // ── Render members lists in drop zones ──
  const renderTeamMembersList = (teamCode: string) => {
    const list = explorers
      .filter(exp => exp.allocated_team_code === teamCode)
      .sort((a, b) => a.first_name.localeCompare(b.first_name));
    if (list.length === 0) {
      return <div className="ems-meta-text ems-italic">No members allocated yet</div>;
    }
    return (
      <div className="ems-planning-member-list">
        {list.map(m => (
          <div
            key={m.scout_id}
            className="ems-planning-member-item"
            draggable={true}
            onDragStart={e => handleDragStart(e, m.scout_id)}
          >
            <span className="ems-flex-center ems-gap-4">
              {m.has_asn && (
                <span
                  className="ems-member-asn"
                  title="Additional Support Needs (ASN) - Click to view"
                  style={{ cursor: onViewAsn ? 'pointer' : 'default' }}
                  onClick={e => {
                    if (onViewAsn) {
                      e.stopPropagation();
                      onViewAsn(m.scout_id);
                    }
                  }}
                >
                  ⚠️
                </span>
              )}
              {m.first_aid_level === 'full_first_aid' && <span className="ems-fa-full" title="Full First Aid">⊕</span>}
              {m.first_aid_level === 'first_response' && <span className="ems-fa-response" title="First Response">✚</span>}
              <span>{m.first_name} {m.last_name}</span>
              <span className="ems-meta-text">({m.unit_name})</span>
            </span>
            <button
              type="button"
              className="ems-remove-member-btn"
              onClick={e => {
                e.stopPropagation();
                void allocateExplorers([m.scout_id], 'remove');
              }}
              title="Remove from event entirely"
            >
              &times;
            </button>
          </div>
        ))}
      </div>
    );
  };

  const renderPoolMembersList = () => {
    const list = explorers
      .filter(exp => exp.allocated_team_code === 'UNALLOCATED')
      .sort((a, b) => a.first_name.localeCompare(b.first_name));
    if (list.length === 0) {
      return <div className="ems-meta-text ems-italic">No pool members</div>;
    }
    return (
      <div className="ems-planning-member-list">
        {list.map(m => (
          <div
            key={m.scout_id}
            className="ems-planning-member-item"
            draggable={true}
            onDragStart={e => handleDragStart(e, m.scout_id)}
          >
            <span className="ems-flex-center ems-gap-4">
              {m.has_asn && (
                <span
                  className="ems-member-asn"
                  title="Additional Support Needs (ASN) - Click to view"
                  style={{ cursor: onViewAsn ? 'pointer' : 'default' }}
                  onClick={e => {
                    if (onViewAsn) {
                      e.stopPropagation();
                      onViewAsn(m.scout_id);
                    }
                  }}
                >
                  ⚠️
                </span>
              )}
              {m.first_aid_level === 'full_first_aid' && <span className="ems-fa-full" title="Full First Aid">⊕</span>}
              {m.first_aid_level === 'first_response' && <span className="ems-fa-response" title="First Response">✚</span>}
              <span>{m.first_name} {m.last_name}</span>
              <span className="ems-meta-text">({m.unit_name})</span>
            </span>
            <button
              type="button"
              className="ems-remove-member-btn"
              onClick={e => {
                e.stopPropagation();
                void allocateExplorers([m.scout_id], 'remove');
              }}
              title="Remove from event entirely"
            >
              &times;
            </button>
          </div>
        ))}
      </div>
    );
  };

  return (
    <div className="ems-panel ems-planning-panel">

      {/* ── Error banner ── */}
      {error && (
        <div className="notice notice-error is-dismissible ems-mb-16">
          <p>{error}</p>
          <button type="button" className="notice-dismiss" onClick={() => setError(null)}>
            <span className="screen-reader-text">Dismiss</span>
          </button>
        </div>
      )}

      {/* ── Action feedback ── */}
      {feedback && (
        <div className={`notice ${feedback.ok ? 'notice-success' : 'notice-error'} is-dismissible ems-mb-16`}>
          <p>{feedback.msg}</p>
          <button type="button" className="notice-dismiss" onClick={() => setFeedback(null)}>
            <span className="screen-reader-text">Dismiss</span>
          </button>
        </div>
      )}

      {/* ── Toolbar / Event Selector at the Top ── */}
      {!event && (
        <div className="ems-toolbar">
          <div className="ems-toolbar__group">
            <label className="ems-toolbar__label ems-planning-select-label" htmlFor="epb-event-select">Select Event</label>
            <select
              id="epb-event-select"
              className="ems-select ems-planning-select"
              aria-label="Select Event"
              value={selectedEvent?.event_code || ''}
              onChange={e => {
                const ev = events.find(x => x.event_code === e.target.value);
                if (ev) handleSelectEvent(ev);
              }}
            >
              <option value="">-- Choose an Event --</option>
              {events.map(ev => (
                <option key={ev.id} value={ev.event_code}>
                  {ev.title} ({ev.event_code}) - {ev.available_count} Available
                </option>
              ))}
            </select>
          </div>

          {loading && <Spinner />}
        </div>
      )}

      {/* ── Two-column split ── */}
      <div className={`ems-split ems-planning-split ${rosterCollapsed ? 'ems-planning-split--collapsed-roster' : ''}`}>

        {/* Left Column — Availability Roster */}
        <div className="ems-split__left ems-planning-split__left">
          <h3 className="ems-section-heading ems-mb-16">Available Explorers</h3>
          {selectedEvent ? (
            <>
              <div className="ems-toolbar ems-planning-toolbar ems-mb-12">
                <div className="ems-toolbar__group">
                  <div className="ems-flex-center ems-gap-4">
                    <label className="ems-toolbar__label" htmlFor="epb-filter-unit">Unit Filter</label>
                    <select
                      id="epb-filter-unit"
                      className="ems-select-sm"
                      value={filterUnit}
                      onChange={e => setFilterUnit(e.target.value)}
                    >
                      <option value="all">All Units</option>
                      {availableUnits.map(unit => (
                        <option key={unit} value={unit}>{unit}</option>
                      ))}
                    </select>
                  </div>
                </div>
                <div className="ems-toolbar__group">
                  <label className="ems-flex-center ems-gap-8 ems-pointer">
                    <input
                      type="checkbox"
                      className="ems-checkbox"
                      checked={hideAssigned}
                      onChange={e => setHideAssigned(e.target.checked)}
                    />
                    <span className="ems-toolbar__label">Hide Assigned</span>
                  </label>
                </div>
              </div>

              {explorersLoading ? (
                <div className="ems-planning-spinner"><Spinner /></div>
              ) : explorers.length === 0 ? (
                <div className="notice notice-warning ems-m-0">
                  <p>No explorers declared interest in this event.</p>
                </div>
              ) : (
                <div className="ems-table-wrap ems-table-wrap--unrestricted">
                  <table className="ems-table">
                    <thead>
                      <tr>
                        <th className="ems-planning-checkbox-col">
                          <input
                            type="checkbox"
                            className="ems-checkbox"
                            checked={allSelected}
                            ref={el => { if (el) el.indeterminate = someSelected; }}
                            onChange={handleToggleSelectAll}
                            aria-label="Select all explorers"
                          />
                        </th>
                        <th className="ems-sortable-header" onClick={() => handleHeaderSort('name')}>
                          Name {renderSortIndicator('name')}
                        </th>
                        <th className="ems-sortable-header" onClick={() => handleHeaderSort('unit')}>
                          Unit {renderSortIndicator('unit')}
                        </th>
                        <th>Team Preferences</th>
                      </tr>
                    </thead>
                    <tbody>
                      {sortedExplorers.map(exp => {
                        const checked = selectedScoutIds.includes(exp.scout_id);
                        const isAssignedToThisEvent = exp.allocated_event_code === selectedEvent.event_code;
                        const isAssignedToOtherEvent = exp.allocated_event_code && exp.allocated_event_code !== selectedEvent.event_code;
                        const isGreyedOut = !!isAssignedToThisEvent || !!isAssignedToOtherEvent;

                        return (
                          <tr
                            key={exp.scout_id}
                            className={`ems-draggable-row ${checked ? 'ems-table-row--selected' : ''} ${isGreyedOut ? 'ems-roster-row--assigned' : ''}`}
                            draggable={!isGreyedOut}
                            onDragStart={e => {
                              if (isGreyedOut) {
                                e.preventDefault();
                                return;
                              }
                              handleDragStart(e, exp.scout_id);
                            }}
                          >
                            <td className="ems-table-cell--center">
                              <input
                                type="checkbox"
                                className="ems-checkbox"
                                checked={checked}
                                disabled={isGreyedOut}
                                onChange={() => handleSelectExplorer(exp.scout_id)}
                                onClick={e => e.stopPropagation()}
                                aria-label={`Select ${exp.first_name} ${exp.last_name}`}
                              />
                            </td>
                            <td>
                              <div className="ems-table__name">{exp.first_name} {exp.last_name}</div>
                              {isAssignedToThisEvent && (
                                <span className="ems-badge--assigned-this">
                                  Assigned: {exp.allocated_team_code === 'UNALLOCATED' ? 'Event Pool' : exp.allocated_team_code}
                                </span>
                              )}
                              {isAssignedToOtherEvent && (
                                <span className="ems-badge--assigned-other">
                                  Assigned: {exp.allocated_team_code === 'UNALLOCATED' ? 'Event Pool' : exp.allocated_team_code} ({exp.allocated_event_code})
                                </span>
                              )}
                            </td>
                            <td>
                              {exp.unit_name}
                            </td>
                            <td className="ems-table-cell--meta">
                              {exp.team_preferences && <div>{exp.team_preferences}</div>}
                              {exp.other_events && exp.other_events.length > 0 && (
                                <div className="ems-table__other-choices">
                                  {exp.other_events.join(', ')}
                                </div>
                              )}
                              {!exp.team_preferences && (!exp.other_events || exp.other_events.length === 0) && '—'}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          ) : (
            <div className="ems-empty">
              Select an event from the dropdown to view availability roster.
            </div>
          )}
        </div>

        {/* Right Column — Drop Zones for Teams */}
        <div className="ems-split__right ems-planning-split__right">
          <div className="ems-flex-between ems-mb-16" style={{ alignItems: 'center' }}>
            <h3 className="ems-section-heading ems-m-0">Expedition Teams</h3>
            {selectedEvent && (
              <button
                type="button"
                className="button button-secondary"
                onClick={() => setRosterCollapsed(prev => !prev)}
              >
                {rosterCollapsed ? 'Show Availability Roster' : 'Hide Availability Roster'}
              </button>
            )}
          </div>

          {selectedEvent ? (
            <div className="ems-planning-grid">
              
              {/* Event Pool Zone */}
              <div
                className={`ems-planning-card ems-planning-card--pool ${collapsedTeams['pool'] ? 'ems-planning-card--collapsed' : ''} ${dragOverZone === 'unallocated' ? 'ems-planning-card--active-drag' : ''}`}
                onDragOver={e => handleDragOver(e, 'unallocated')}
                onDragLeave={handleDragLeave}
                onDrop={e => handleDrop(e, 'unallocated')}
              >
                <div className="ems-planning-card__header" onClick={() => toggleCollapse('pool')}>
                  <h4 className="ems-planning-card__title">
                    {collapsedTeams['pool'] ? '▶' : '▼'} Event Pool (No Team)
                  </h4>
                  <span className="ems-badge">
                    {explorers.filter(exp => !exp.allocated_team_code || exp.allocated_team_code === 'UNALLOCATED').length}
                  </span>
                </div>
                {!collapsedTeams['pool'] && renderPoolMembersList()}
              </div>

              {/* Existing Teams Zones */}
              {eventTeams.map(team => {
                const zoneId = `team-${team.ID}`;
                const isCollapsed = !!collapsedTeams[zoneId];
                const members = explorers.filter(exp => exp.allocated_team_code === team.ems_team_code);
                const size = members.length;

                // Warnings
                const sizeWarning = size < 4 || size > 7;

                // First Aid Warning:
                const faReq = selectedEvent?.first_aid_level;
                const faCount = members.filter(m => {
                    const lvl = m.first_aid_level ?? 'none';
                    if (faReq === 'full_first_aid') return lvl === 'full_first_aid';
                    return lvl === 'first_response' || lvl === 'full_first_aid';
                }).length;
                const hasFaCover = !faReq || faReq === 'none' || faCount >= 2;
                const faWarning = !hasFaCover;

                // Branded Border colors (Gold, Silver, Bronze):
                const eventLevel = selectedEvent?.level?.toLowerCase() || '';
                let levelClass = '';
                if (eventLevel === 'gold') levelClass = 'ems-team-card--gold';
                if (eventLevel === 'silver') levelClass = 'ems-team-card--silver';
                if (eventLevel === 'bronze') levelClass = 'ems-team-card--bronze';

                return (
                  <div
                    key={team.ID}
                    className={`ems-planning-card ${levelClass} ${isCollapsed ? 'ems-planning-card--collapsed' : ''} ${sizeWarning || faWarning ? 'ems-team-card--warning' : ''} ${dragOverZone === zoneId ? 'ems-planning-card--active-drag' : ''}`}
                    onDragOver={e => handleDragOver(e, zoneId)}
                    onDragLeave={handleDragLeave}
                    onDrop={e => handleDrop(e, 'existing_team', team.ID)}
                  >
                    <div className="ems-planning-card__header" onClick={() => toggleCollapse(zoneId)}>
                      <h4 className="ems-planning-card__title">
                        {isCollapsed ? '▶' : '▼'} Team {team.ems_team_code}
                      </h4>
                      <span className="ems-badge">
                        {size}
                      </span>
                    </div>

                    {!isCollapsed && (
                      <>
                        {sizeWarning && (
                          <div className="ems-alert ems-alert--warning" style={{ fontSize: '11px', padding: '6px 8px', marginBottom: '8px' }}>
                            ⚠️ Team size must be 4–7 members (currently {size})
                          </div>
                        )}
                        {faWarning && (
                          <div className="ems-alert ems-alert--danger" style={{ fontSize: '11px', padding: '6px 8px', marginBottom: '8px' }}>
                            ⚕️ Requires at least 2 qualified First Aiders
                          </div>
                        )}
                        {renderTeamMembersList(team.ems_team_code)}
                      </>
                    )}
                  </div>
                );
              })}

              {/* New Team Zone */}
              <div
                className={`ems-planning-card ems-planning-card--dashed ${dragOverZone === 'new_team' ? 'ems-planning-card--active-drag' : ''}`}
                onDragOver={e => handleDragOver(e, 'new_team')}
                onDragLeave={handleDragLeave}
                onDrop={e => handleDrop(e, 'new_team')}
              >
                <div className="ems-planning-new-team-inner">
                  <div className="ems-planning-new-team-icon">＋</div>
                  <div>Drop here to create a new team</div>
                </div>
              </div>

            </div>
          ) : (
            <div className="ems-empty">
              Select an event to view and manage team allocations.
            </div>
          )}
        </div>
      </div>

      {/* ── Floating Action bar for manual moves ── */}
      {selectedEvent && selectedScoutIds.length > 0 && (
        <div className="ems-action-bar ems-action-bar--fixed">
          <div>
            <strong>With Selected ({selectedScoutIds.length}):</strong>
          </div>
          <div className="ems-flex-center ems-gap-12">
            <select
              className="ems-select ems-m-0"
              value={allocationMode}
              onChange={e => setAllocationMode(e.target.value as AllocationMode)}
              aria-label="Allocation action"
            >
              <option value="unallocated">Add to Event Pool</option>
              <option value="new_team">Add to New Team</option>
              {eventTeams.length > 0 && (
                <option value="existing_team">Add to Existing Team…</option>
              )}
            </select>

            {allocationMode === 'existing_team' && eventTeams.length > 0 && (
              <select
                className="ems-select ems-m-0"
                value={targetTeamId}
                onChange={e => setTargetTeamId(parseInt(e.target.value))}
                aria-label="Target team"
              >
                <option value="0">-- Select Team --</option>
                {eventTeams.map(t => (
                  <option key={t.ID} value={t.ID}>Team {t.ems_team_code}</option>
                ))}
              </select>
            )}

            <button
              type="button"
              className="button button-primary"
              onClick={handleApplyAction}
              disabled={loading}
            >
              {loading ? 'Applying…' : 'Apply Action'}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
