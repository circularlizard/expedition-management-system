import React, { useEffect, useState, useCallback } from 'react';
import { SelectControl, Button, CheckboxControl, Spinner, Notice } from '@wordpress/components';

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
  scout_id:             number;
  first_name:           string;
  last_name:            string;
  unit_name:            string;
  dofe_level:           string;
  allocated_team_code?: string;
  allocated_event_code?: string;
}

interface EventTeam {
  ID:            number;
  ems_team_code: string;
}

type LevelFilter     = 'silver' | 'gold';
type TypeFilter      = 'practice' | 'qualifier';
type AllocationMode  = 'unallocated' | 'new_team' | 'existing_team';
type SortBy          = 'name' | 'unit';

export default function EventPlanningBoard() {
  const config  = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '' };
  const rootUrl = config.root_url;
  const nonce   = config.nonce;

  const [levelFilter, setLevelFilter]   = useState<LevelFilter>('silver');
  const [typeFilter,  setTypeFilter]    = useState<TypeFilter>('practice');
  const [events,      setEvents]        = useState<PlanningEvent[]>([]);
  const [loading,     setLoading]       = useState(false);
  const [error,       setError]         = useState<string | null>(null);

  const [selectedEvent,      setSelectedEvent]     = useState<PlanningEvent | null>(null);
  const [explorers,          setExplorers]          = useState<PlanningExplorer[]>([]);
  const [explorersLoading,   setExplorersLoading]  = useState(false);
  const [selectedScoutIds,   setSelectedScoutIds]  = useState<number[]>([]);
  const [sortBy,             setSortBy]             = useState<SortBy>('name');
  const [allocationMode,     setAllocationMode]    = useState<AllocationMode>('unallocated');
  const [targetTeamId,       setTargetTeamId]      = useState<number>(0);
  const [eventTeams,         setEventTeams]        = useState<EventTeam[]>([]);
  const [actionFeedback,     setActionFeedback]    = useState<{ type: 'success' | 'error'; msg: string } | null>(null);

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
    setActionFeedback(null);
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
  const handleSelectExplorer = (scout_id: number) => {
    setSelectedScoutIds(prev =>
      prev.includes(scout_id) ? prev.filter(id => id !== scout_id) : [...prev, scout_id]
    );
  };

  const handleToggleSelectAll = () => {
    setSelectedScoutIds(prev => prev.length === explorers.length ? [] : explorers.map(e => e.scout_id));
  };

  // ── Apply allocation action ────────────────────────────────────────────────
  const handleApplyAction = async () => {
    if (!selectedEvent || selectedScoutIds.length === 0) return;
    setLoading(true);
    setActionFeedback(null);

    const body: Record<string, unknown> = {
      scout_ids:  selectedScoutIds,
      event_code: selectedEvent.event_code,
      mode:       allocationMode,
    };
    if (allocationMode === 'existing_team') {
      body.team_id = targetTeamId;
    }

    try {
      const res = await fetch(`${rootUrl}/planning-board/allocate`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body:    JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data?.message || res.statusText);
      setActionFeedback({ type: 'success', msg: `${data.allocated ?? selectedScoutIds.length} explorer(s) allocated.` });
      setSelectedScoutIds([]);
      handleSelectEvent(selectedEvent); // refresh
    } catch (e: unknown) {
      setActionFeedback({ type: 'error', msg: e instanceof Error ? e.message : String(e) });
    } finally {
      setLoading(false);
    }
  };

  // ── Sort explorers ─────────────────────────────────────────────────────────
  const sortedExplorers = [...explorers].sort((a, b) => {
    if (sortBy === 'unit') return a.unit_name.localeCompare(b.unit_name);
    return `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`);
  });

  return (
    <div className="ems-panel ems-panel--full-height">

      {/* Error banner */}
      {error && (
        <Notice status="error" isDismissible onRemove={() => setError(null)}>
          {error}
        </Notice>
      )}

      {/* Action feedback */}
      {actionFeedback && (
        <Notice
          status={actionFeedback.type === 'success' ? 'success' : 'error'}
          isDismissible
          onRemove={() => setActionFeedback(null)}
        >
          {actionFeedback.msg}
        </Notice>
      )}

      {/* ── Toolbar ── */}
      <div className="ems-toolbar">
        <div className="ems-toolbar__group">
          <span className="ems-toolbar__label">Level</span>
          <SelectControl
            className="ems-select-sm"
            value={levelFilter}
            options={[
              { label: 'Silver', value: 'silver' },
              { label: 'Gold',   value: 'gold'   },
            ]}
            onChange={(v) => setLevelFilter(v as LevelFilter)}
            __nextHasNoMarginBottom
          />
        </div>

        <div className="ems-toolbar__group">
          <span className="ems-toolbar__label">Type</span>
          <Button
            variant={typeFilter === 'practice' ? 'primary' : 'secondary'}
            size="small"
            onClick={() => setTypeFilter('practice')}
          >
            Practice
          </Button>
          <Button
            variant={typeFilter === 'qualifier' ? 'primary' : 'secondary'}
            size="small"
            onClick={() => setTypeFilter('qualifier')}
          >
            Qualifier
          </Button>
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
            <p style={{ color: '#646970', fontStyle: 'italic' }}>
              No active events found for this filter.
            </p>
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
                  <div className="ems-event-card__title">
                    {ev.title} ({ev.event_code})
                  </div>
                  <div className="ems-event-card__dates">
                    {ev.start_date || '—'} — {ev.end_date || '—'}
                  </div>
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
          <div className="ems-toolbar" style={{ borderBottom: 'none', paddingBottom: 0, marginBottom: '12px' }}>
            <h3 className="ems-section-heading" style={{ margin: 0, flex: 1 }}>
              {selectedEvent
                ? `Explorer Availability (${selectedEvent.event_code})`
                : 'Explorer Availability'}
            </h3>
            {selectedEvent && (
              <div className="ems-toolbar__group">
                <span className="ems-toolbar__label">Sort</span>
                <SelectControl
                  className="ems-select-sm"
                  value={sortBy}
                  options={[
                    { label: 'Name', value: 'name' },
                    { label: 'Unit', value: 'unit' },
                  ]}
                  onChange={(v) => setSortBy(v as SortBy)}
                  __nextHasNoMarginBottom
                />
              </div>
            )}
          </div>

          {!selectedEvent ? (
            <div className="ems-empty">
              Select an event from the left to view interested explorers and assign them.
            </div>
          ) : explorersLoading ? (
            <div style={{ padding: '20px' }}><Spinner /></div>
          ) : explorers.length === 0 ? (
            <p style={{ color: '#646970', fontStyle: 'italic' }}>
              No explorers declared interest in this event.
            </p>
          ) : (
            <>
              {/* Roster table */}
              <div className="ems-table-wrap">
                <table className="ems-table">
                  <thead>
                    <tr>
                      <th style={{ width: 36, textAlign: 'center' }}>
                        <CheckboxControl
                          checked={selectedScoutIds.length === explorers.length}
                          indeterminate={selectedScoutIds.length > 0 && selectedScoutIds.length < explorers.length}
                          onChange={handleToggleSelectAll}
                          label=""
                          __nextHasNoMarginBottom
                        />
                      </th>
                      <th>Name &amp; Unit</th>
                      <th>Allocation Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sortedExplorers.map(exp => (
                      <tr key={exp.scout_id} style={{ background: selectedScoutIds.includes(exp.scout_id) ? '#f0f6fc' : undefined }}>
                        <td style={{ textAlign: 'center' }}>
                          <CheckboxControl
                            checked={selectedScoutIds.includes(exp.scout_id)}
                            onChange={() => handleSelectExplorer(exp.scout_id)}
                            label=""
                            __nextHasNoMarginBottom
                          />
                        </td>
                        <td>
                          <div className="ems-table__name">{exp.first_name} {exp.last_name}</div>
                          <div className="ems-table__meta">Unit: {exp.unit_name}</div>
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
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Action bar */}
              <div className="ems-action-bar">
                <div className="ems-action-bar__controls">
                  <SelectControl
                    className="ems-select"
                    value={allocationMode}
                    options={[
                      { label: 'Add to Unallocated', value: 'unallocated' },
                      { label: 'Add to New Team',    value: 'new_team'    },
                      ...(eventTeams.length > 0
                        ? [{ label: 'Add to Existing Team…', value: 'existing_team' }]
                        : []),
                    ]}
                    onChange={(v) => setAllocationMode(v as AllocationMode)}
                    __nextHasNoMarginBottom
                  />
                  {allocationMode === 'existing_team' && eventTeams.length > 0 && (
                    <SelectControl
                      className="ems-select"
                      value={String(targetTeamId)}
                      options={eventTeams.map(t => ({ label: `Team ${t.ems_team_code}`, value: String(t.ID) }))}
                      onChange={(v) => setTargetTeamId(parseInt(v))}
                      __nextHasNoMarginBottom
                    />
                  )}
                </div>

                <div className="ems-action-bar__actions">
                  <Button
                    variant="primary"
                    onClick={handleApplyAction}
                    disabled={loading || selectedScoutIds.length === 0}
                    isBusy={loading}
                  >
                    Apply Action
                  </Button>
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
