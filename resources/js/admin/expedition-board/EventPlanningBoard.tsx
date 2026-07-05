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
}

interface EventTeam {
  ID:            number;
  ems_team_code: string;
}

type LevelFilter    = 'silver' | 'gold';
type TypeFilter     = 'practice' | 'qualifier';
type AllocationMode = 'unallocated' | 'new_team' | 'existing_team';
type SortBy         = 'name' | 'unit';

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
  const [allocationMode,   setAllocationMode]   = useState<AllocationMode>('unallocated');
  const [targetTeamId,     setTargetTeamId]     = useState<number>(0);
  const [eventTeams,       setEventTeams]       = useState<EventTeam[]>([]);
  const [feedback,         setFeedback]         = useState<{ ok: boolean; msg: string } | null>(null);

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

  const handleToggleSelectAll = () =>
    setSelectedScoutIds(prev =>
      prev.length === explorers.length ? [] : explorers.map(e => e.scout_id)
    );

  // ── Apply allocation action ────────────────────────────────────────────────
  const handleApplyAction = async () => {
    if (!selectedEvent || selectedScoutIds.length === 0) return;
    setLoading(true);
    setFeedback(null);

    const body: Record<string, unknown> = {
      scout_ids:  selectedScoutIds,
      event_code: selectedEvent.event_code,
      mode:       allocationMode,
    };
    if (allocationMode === 'existing_team') body.team_id = targetTeamId;

    try {
      const res  = await fetch(`${rootUrl}/planning-board/allocate`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body:    JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data?.message || res.statusText);
      setFeedback({ ok: true, msg: `${data.allocated ?? selectedScoutIds.length} explorer(s) allocated.` });
      setSelectedScoutIds([]);
      handleSelectEvent(selectedEvent);
    } catch (e: unknown) {
      setFeedback({ ok: false, msg: e instanceof Error ? e.message : String(e) });
    } finally {
      setLoading(false);
    }
  };

  // ── Sort explorers ─────────────────────────────────────────────────────────
  const sortedExplorers = [...explorers].sort((a, b) => {
    if (sortBy === 'unit') return a.unit_name.localeCompare(b.unit_name);
    return `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`);
  });

  const allSelected  = explorers.length > 0 && selectedScoutIds.length === explorers.length;
  const someSelected = selectedScoutIds.length > 0 && selectedScoutIds.length < explorers.length;

  return (
    <div className="ems-panel ems-panel--full-height">

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

      {/* ── Two-column split ── */}
      <div className="ems-split">

        {/* Left — event list */}
        <div className="ems-split__left">
          <h3 className="ems-section-heading">Select Event</h3>

          {loading && events.length === 0 ? (
            <Spinner />
          ) : events.length === 0 ? (
            <p className="ems-planning-empty">No active events found for this filter.</p>
          ) : (
            events.map(ev => {
              const selected = selectedEvent?.id === ev.id;
              return (
                <div
                  key={ev.id}
                  role="button"
                  tabIndex={0}
                  onClick={() => handleSelectEvent(ev)}
                  onKeyDown={e => e.key === 'Enter' && handleSelectEvent(ev)}
                  className={`ems-event-card${selected ? ' ems-event-card--selected' : ''}`}
                >
                  <div className="ems-event-card__title">{ev.title} ({ev.event_code})</div>
                  <div className="ems-event-card__dates">{ev.start_date || '—'} — {ev.end_date || '—'}</div>
                  <div className="ems-event-card__stats">
                    <span>Available: <strong className="ems-event-card__stat-value">{ev.available_count}</strong></span>
                    <span>Allocated: <strong>{ev.allocated_count}</strong></span>
                  </div>
                </div>
              );
            })
          )}
        </div>

        {/* Right — availability roster */}
        <div className="ems-split__right">

          {/* Right header row */}
          <div className="ems-toolbar ems-planning-toolbar">
            <h3 className="ems-section-heading ems-planning-header">
              {selectedEvent
                ? `Explorer Availability (${selectedEvent.event_code})`
                : 'Explorer Availability'}
            </h3>
            {selectedEvent && (
              <div className="ems-toolbar__group">
                <label className="ems-toolbar__label" htmlFor="epb-sort">Sort</label>
                <select
                  id="epb-sort"
                  className="ems-select-sm"
                  value={sortBy}
                  onChange={e => setSortBy(e.target.value as SortBy)}
                >
                  <option value="name">Name</option>
                  <option value="unit">Unit</option>
                </select>
              </div>
            )}
          </div>

          {!selectedEvent ? (
            <div className="ems-empty">
              Select an event from the left to view interested explorers and assign them to a team.
            </div>
          ) : explorersLoading ? (
            <div className="ems-planning-spinner"><Spinner /></div>
          ) : explorers.length === 0 ? (
            <p className="ems-planning-empty">No explorers declared interest in this event.</p>
          ) : (
            <>
              {/* Roster table */}
              <div className="ems-table-wrap">
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
                        <tr key={exp.scout_id} className={checked ? 'ems-table-row--selected' : ''}>
                          <td className="ems-table-cell--center">
                            <input
                              type="checkbox"
                              className="ems-checkbox"
                              checked={checked}
                              onChange={() => handleSelectExplorer(exp.scout_id)}
                              aria-label={`Select ${exp.first_name} ${exp.last_name}`}
                            />
                          </td>
                          <td>
                            <div className="ems-table__name">{exp.first_name} {exp.last_name}</div>
                            <div className="ems-table__meta">Unit: {exp.unit_name}</div>
                          </td>
                          <td style={{ fontSize: '11px', color: '#666' }}>
                            {exp.team_preferences || '—'}
                          </td>
                          <td>
                            {exp.allocated_event_code ? (
                              <span className="ems-badge ems-badge--allocated">
                                {exp.allocated_team_code} ({exp.allocated_event_code})
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

              {/* Action bar */}
              <div className="ems-action-bar">
                <div className="ems-action-bar__controls">
                  <select
                    className="ems-select"
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
                      className="ems-select"
                      value={targetTeamId}
                      onChange={e => setTargetTeamId(parseInt(e.target.value))}
                      aria-label="Target team"
                    >
                      {eventTeams.map(t => (
                        <option key={t.ID} value={t.ID}>Team {t.ems_team_code}</option>
                      ))}
                    </select>
                  )}
                </div>

                <div className="ems-action-bar__actions">
                  <button
                    type="button"
                    className="button button-primary"
                    onClick={handleApplyAction}
                    disabled={loading || selectedScoutIds.length === 0}
                  >
                    {loading ? 'Applying…' : 'Apply Action'}
                  </button>
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
