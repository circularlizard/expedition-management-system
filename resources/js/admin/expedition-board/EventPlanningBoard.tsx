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

type LevelFilter    = 'silver' | 'gold';
type TypeFilter     = 'practice' | 'qualifier';
type AllocationMode = 'unallocated' | 'new_team' | 'existing_team';
type SortBy         = 'name' | 'unit' | 'allocation';

function Spinner() {
  return <span className="ems-spinner" aria-label="Loading…" />;
}

export default function EventPlanningBoard() {
  const config  = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '' };
  const rootUrl = config.root_url;
  const nonce   = config.nonce;

  const [levelFilter, setLevelFilter] = useState<LevelFilter>('silver');
  const [typeFilter,  setTypeFilter]  = useState<TypeFilter>('practice');
  const [events,      setEvents]      = useState<PlanningEvent[]>([]);
  const [loading,     setLoading]     = useState(false);
  const [error,       setError]       = useState<string | null>(null);

  const [selectedEvent,    setSelectedEvent]    = useState<PlanningEvent | null>(null);
  const [explorers,        setExplorers]        = useState<PlanningExplorer[]>([]);
  const [explorersLoading, setExplorersLoading] = useState(false);
  const [selectedScoutIds, setSelectedScoutIds] = useState<number[]>([]);
  const [sortBy,           setSortBy]           = useState<SortBy>('name');
  const [filterUnit,       setFilterUnit]       = useState<string>('all');
  const [filterAllocated,  setFilterAllocated]  = useState<'all' | 'allocated' | 'unallocated'>('all');
  const [allocationMode,   setAllocationMode]   = useState<AllocationMode>('unallocated');
  const [targetTeamId,     setTargetTeamId]     = useState<number>(0);
  const [eventTeams,       setEventTeams]       = useState<EventTeam[]>([]);
  const [feedback,         setFeedback]         = useState<{ ok: boolean; msg: string } | null>(null);
  const [dragOverZone,     setDragOverZone]     = useState<string | null>(null);

  // ── Load planning board events ─────────────────────────────────────────────
  useEffect(() => {
    setLoading(true);
    setError(null);
    setSelectedEvent(null);
    setExplorers([]);
    setSelectedScoutIds([]);

    fetch(`${rootUrl}/planning-board?level=${levelFilter}&type=${typeFilter}`, {
      headers: { 'X-WP-Nonce': nonce },
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.statusText))
      .then(data => setEvents(Array.isArray(data) ? data : []))
      .catch(e => setError(`Failed to load planning board: ${e}`))
      .finally(() => setLoading(false));
  }, [levelFilter, typeFilter, rootUrl, nonce]);

  // ── Load explorers for a selected event ────────────────────────────────────
  const handleSelectEvent = useCallback((ev: PlanningEvent) => {
    setSelectedEvent(ev);
    setExplorers([]);
    setSelectedScoutIds([]);
    setEventTeams([]);
    setAllocationMode('unallocated');
    setFilterUnit('all');
    setFilterAllocated('all');
    setFeedback(null);
    setExplorersLoading(true);

    fetch(`${rootUrl}/planning-board/availability/${ev.event_code}`, {
      headers: { 'X-WP-Nonce': nonce },
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.statusText))
      .then(data => {
        setExplorers(data.explorers || []);
        setEventTeams(data.teams    || []);
      })
      .catch(e => setError(`Failed to load availability: ${e}`))
      .finally(() => setExplorersLoading(false));
  }, [rootUrl, nonce]);

  // ── Checkbox helpers ───────────────────────────────────────────────────────
  const handleSelectExplorer = (scout_id: number) =>
    setSelectedScoutIds(prev =>
      prev.includes(scout_id) ? prev.filter(id => id !== scout_id) : [...prev, scout_id]
    );

  const handleToggleSelectAll = () => {
    const visibleIds = sortedExplorers.map(e => e.scout_id);
    const allVisibleSelected = visibleIds.every(id => selectedScoutIds.includes(id));
    if (allVisibleSelected) {
      setSelectedScoutIds(prev => prev.filter(id => !visibleIds.includes(id)));
    } else {
      setSelectedScoutIds(prev => Array.from(new Set([...prev, ...visibleIds])));
    }
  };

  // ── Apply allocation action (reusable for drag-drop and manual select) ───
  const allocateExplorers = async (scoutIds: number[], mode: AllocationMode, teamId?: number) => {
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
      setFeedback({ ok: true, msg: `${data.allocated ?? scoutIds.length} explorer(s) allocated.` });
      setSelectedScoutIds([]);
      handleSelectEvent(selectedEvent);
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
    // If the dragged row is checked, drag all checked items, otherwise drag just this item
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

  // ── Sort & Filter explorers ────────────────────────────────────────────────
  const availableUnits = Array.from(new Set(explorers.map(e => e.unit_name).filter(Boolean))).sort();

  const filteredExplorers = explorers.filter(exp => {
    if (filterUnit !== 'all' && exp.unit_name !== filterUnit) return false;
    if (filterAllocated === 'allocated' && !exp.allocated_event_code) return false;
    if (filterAllocated === 'unallocated' && exp.allocated_event_code) return false;
    return true;
  });

  const sortedExplorers = [...filteredExplorers].sort((a, b) => {
    if (sortBy === 'unit') return a.unit_name.localeCompare(b.unit_name);
    if (sortBy === 'allocation') {
      const aAlloc = !!a.allocated_event_code;
      const bAlloc = !!b.allocated_event_code;
      if (aAlloc !== bAlloc) return aAlloc ? 1 : -1; // Unallocated first
      return `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`);
    }
    return `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`);
  });

  const allSelected  = sortedExplorers.length > 0 && sortedExplorers.every(e => selectedScoutIds.includes(e.scout_id));
  const someSelected = sortedExplorers.length > 0 && sortedExplorers.some(e => selectedScoutIds.includes(e.scout_id)) && !allSelected;

  // ── Render members lists in drop zones ──
  const renderTeamMembersList = (teamCode: string) => {
    const list = explorers.filter(exp => exp.allocated_team_code === teamCode);
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
            <span>{m.first_name} {m.last_name}</span>
            <span className="ems-meta-text">({m.unit_name})</span>
          </div>
        ))}
      </div>
    );
  };

  const renderUnallocatedMembersList = () => {
    const list = explorers.filter(exp => !exp.allocated_team_code || exp.allocated_team_code === 'UNALLOCATED');
    if (list.length === 0) {
      return <div className="ems-meta-text ems-italic">No unallocated members</div>;
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
            <span>{m.first_name} {m.last_name}</span>
            <span className="ems-meta-text">({m.unit_name})</span>
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

      {/* ── Toolbar ── */}
      <div className="ems-toolbar">
        <div className="ems-toolbar__group">
          <label className="ems-toolbar__label" htmlFor="epb-level">Level</label>
          <select
            id="epb-level"
            className="ems-select-sm"
            value={levelFilter}
            onChange={e => setLevelFilter(e.target.value as LevelFilter)}
          >
            <option value="silver">Silver</option>
            <option value="gold">Gold</option>
          </select>
        </div>

        <div className="ems-toolbar__group">
          <span className="ems-toolbar__label">Type</span>
          <button
            type="button"
            className={`button${typeFilter === 'practice' ? ' button-primary' : ''}`}
            onClick={() => setTypeFilter('practice')}
          >
            Practice
          </button>
          <button
            type="button"
            className={`button${typeFilter === 'qualifier' ? ' button-primary' : ''}`}
            onClick={() => setTypeFilter('qualifier')}
          >
            Qualifier
          </button>
        </div>

        {loading && <Spinner />}
      </div>

      <div className="ems-split ems-planning-split">

        {/* Left Column — Selector & Roster */}
        <div className="ems-split__left ems-planning-split__left">
          <div className="ems-mb-16">
            <label className="ems-toolbar__label ems-planning-select-label" htmlFor="epb-event-select">Select Event</label>
            <select
              id="epb-event-select"
              className="ems-select"
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

          {selectedEvent && (
            <>
              <div className="ems-toolbar ems-planning-toolbar ems-mb-12">
                <div className="ems-toolbar__group">
                  <div className="ems-flex-center ems-gap-4">
                    <label className="ems-toolbar__label" htmlFor="epb-sort">Sort</label>
                    <select
                      id="epb-sort"
                      className="ems-select-sm"
                      value={sortBy}
                      onChange={e => setSortBy(e.target.value as SortBy)}
                    >
                      <option value="name">Name</option>
                      <option value="unit">Unit</option>
                      <option value="allocation">Allocation Status</option>
                    </select>
                  </div>

                  <div className="ems-flex-center ems-gap-4">
                    <label className="ems-toolbar__label" htmlFor="epb-filter-unit">Unit</label>
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
              </div>

              {explorersLoading ? (
                <div className="ems-planning-spinner"><Spinner /></div>
              ) : explorers.length === 0 ? (
                <p className="ems-planning-empty">No explorers declared interest in this event.</p>
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
                        <th>Name &amp; Unit</th>
                        <th>Team Preferences</th>
                        <th>Allocation Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {sortedExplorers.map(exp => {
                        const checked = selectedScoutIds.includes(exp.scout_id);
                        return (
                          <tr
                            key={exp.scout_id}
                            className={`ems-draggable-row ${checked ? 'ems-table-row--selected' : ''}`}
                            draggable={true}
                            onDragStart={e => handleDragStart(e, exp.scout_id)}
                          >
                            <td className="ems-table-cell--center">
                              <input
                                type="checkbox"
                                className="ems-checkbox"
                                checked={checked}
                                onChange={() => handleSelectExplorer(exp.scout_id)}
                                onClick={e => e.stopPropagation()}
                                aria-label={`Select ${exp.first_name} ${exp.last_name}`}
                              />
                            </td>
                            <td>
                              <div className="ems-table__name">{exp.first_name} {exp.last_name}</div>
                              <div className="ems-table__meta">Unit: {exp.unit_name}</div>
                            </td>
                            <td className="ems-table-cell--meta">
                              {exp.team_preferences || '—'}
                            </td>
                            <td>
                              {exp.allocated_event_code ? (
                                <span className="ems-badge ems-badge--allocated">
                                  {exp.allocated_team_code}
                                </span>
                              ) : (
                                <span className="ems-badge ems-badge--unallocated">Unallocated</span>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          )}

          {!selectedEvent && (
            <div className="ems-empty">
              Select an event from the dropdown to view availability roster.
            </div>
          )}
        </div>

        {/* Right Column — Drop Zones for Teams */}
        <div className="ems-split__right ems-planning-split__right">
          <h3 className="ems-section-heading ems-mb-16">Teams Drop Zones</h3>

          {selectedEvent ? (
            <div className="ems-planning-grid">
              
              {/* Unallocated Zone */}
              <div
                className={`ems-planning-card ${dragOverZone === 'unallocated' ? 'ems-planning-card--active-drag' : ''}`}
                onDragOver={e => handleDragOver(e, 'unallocated')}
                onDragLeave={handleDragLeave}
                onDrop={e => handleDrop(e, 'unallocated')}
              >
                <div className="ems-planning-card__header">
                  <h4 className="ems-planning-card__title">Unallocated</h4>
                  <span className="ems-badge">
                    {explorers.filter(exp => !exp.allocated_team_code || exp.allocated_team_code === 'UNALLOCATED').length}
                  </span>
                </div>
                {renderUnallocatedMembersList()}
              </div>

              {/* Existing Teams Zones */}
              {eventTeams.map(team => {
                const zoneId = `team-${team.ID}`;
                return (
                  <div
                    key={team.ID}
                    className={`ems-planning-card ${dragOverZone === zoneId ? 'ems-planning-card--active-drag' : ''}`}
                    onDragOver={e => handleDragOver(e, zoneId)}
                    onDragLeave={handleDragLeave}
                    onDrop={e => handleDrop(e, 'existing_team', team.ID)}
                  >
                    <div className="ems-planning-card__header">
                      <h4 className="ems-planning-card__title">Team {team.ems_team_code}</h4>
                      <span className="ems-badge">
                        {explorers.filter(exp => exp.allocated_team_code === team.ems_team_code).length}
                      </span>
                    </div>
                    {renderTeamMembersList(team.ems_team_code)}
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
              <option value="unallocated">Add to Unallocated</option>
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
