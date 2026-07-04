import React, { useState, useEffect } from 'react';
import { Expedition } from './types';

interface PlanningEvent {
  id: number;
  title: string;
  event_code: string;
  type: string;
  level: string;
  start_date: string;
  end_date: string;
  available_count: number;
  allocated_count: number;
}

interface AvailableExplorer {
  scout_id: number;
  first_name: string;
  last_name: string;
  unit_name: string;
  allocated_event_code: string | null;
  allocated_team_code: string | null;
}

interface TeamOption {
  ID: number;
  ems_team_code: string;
}

export default function EventPlanningBoard() {
  const config = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '' };
  const rootUrl = config.root_url;
  const nonce = config.nonce;

  const [events, setEvents] = useState<PlanningEvent[]>([]);
  const [selectedEvent, setSelectedEvent] = useState<PlanningEvent | null>(null);
  const [explorers, setExplorers] = useState<AvailableExplorer[]>([]);
  const [eventTeams, setEventTeams] = useState<TeamOption[]>([]);
  
  // Filters
  const [levelFilter, setLevelFilter] = useState<'silver' | 'gold'>('silver');
  const [typeFilter, setTypeFilter] = useState<'practice' | 'qualifier'>('practice');
  const [sortBy, setSortBy] = useState<'name' | 'unit'>('name');

  // Roster selection
  const [selectedScoutIds, setSelectedScoutIds] = useState<number[]>([]);
  const [allocationMode, setAllocationMode] = useState<'unallocated' | 'existing_team' | 'new_team'>('unallocated');
  const [targetTeamId, setTargetTeamId] = useState<number>(0);

  const [loading, setLoading] = useState(false);
  const [explorersLoading, setExplorersLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Fetch events
  const fetchEvents = async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await fetch(`${rootUrl}/planning-board`, {
        headers: { 'X-WP-Nonce': nonce }
      });
      if (response && response.ok) {
        const data = await response.json();
        setEvents(data);
      } else {
        throw new Error('Failed to fetch planning board events');
      }
    } catch (err: any) {
      setError(err.message || 'Error loading planning board');
    } finally {
      setLoading(false);
    }
  };

  // Fetch availability and teams for selected event
  const handleSelectEvent = async (event: PlanningEvent) => {
    setSelectedEvent(event);
    setExplorersLoading(true);
    setSelectedScoutIds([]);
    try {
      // 1. Fetch explorers availability
      const availRes = await fetch(`${rootUrl}/planning-board/availability/${event.event_code}`, {
        headers: { 'X-WP-Nonce': nonce }
      });
      if (availRes && availRes.ok) {
        const availData = await availRes.json();
        setExplorers(availData);
      }

      // 2. Fetch event teams
      const teamsRes = await fetch(`${rootUrl}/events/${event.id}/teams`, {
        headers: { 'X-WP-Nonce': nonce }
      });
      if (teamsRes && teamsRes.ok) {
        const teamsData = await teamsRes.json();
        // Filter out virtual UNALLOCATED team for display in target teams list
        const filteredTeams = teamsData.filter((t: any) => t.ems_team_code !== 'UNALLOCATED');
        setEventTeams(filteredTeams);
        if (filteredTeams.length > 0) {
          setTargetTeamId(filteredTeams[0].ID);
        } else {
          setTargetTeamId(0);
        }
      }
    } catch (err) {
      console.error('Error fetching availability/teams', err);
    } finally {
      setExplorersLoading(false);
    }
  };

  useEffect(() => {
    fetchEvents();
    setSelectedEvent(null);
    setExplorers([]);
  }, [levelFilter, typeFilter]);

  // Filter events matching active toggles
  const filteredEvents = events.filter(e => e.level === levelFilter && e.type === typeFilter);

  // Sort explorers
  const sortedExplorers = [...explorers].sort((a, b) => {
    if (sortBy === 'name') {
      const nameA = `${a.first_name} ${a.last_name}`.toLowerCase();
      const nameB = `${b.first_name} ${b.last_name}`.toLowerCase();
      return nameA.localeCompare(nameB);
    } else {
      const unitA = (a.unit_name || '').toLowerCase();
      const unitB = (b.unit_name || '').toLowerCase();
      return unitA.localeCompare(unitB);
    }
  });

  const handleToggleSelectAll = () => {
    if (selectedScoutIds.length === explorers.length) {
      setSelectedScoutIds([]);
    } else {
      setSelectedScoutIds(explorers.map(e => e.scout_id));
    }
  };

  const handleSelectExplorer = (scoutId: number) => {
    if (selectedScoutIds.includes(scoutId)) {
      setSelectedScoutIds(selectedScoutIds.filter(id => id !== scoutId));
    } else {
      setSelectedScoutIds([...selectedScoutIds, scoutId]);
    }
  };

  const handleApplyAction = async () => {
    if (!selectedEvent) return;
    if (selectedScoutIds.length === 0) {
      alert('Please select at least one explorer to allocate.');
      return;
    }

    // Verify if any selected explorers are already allocated
    const alreadyAllocated = explorers.filter(
      e => selectedScoutIds.includes(e.scout_id) && e.allocated_event_code !== null
    );

    if (alreadyAllocated.length > 0) {
      const names = alreadyAllocated.map(e => `${e.first_name} ${e.last_name}`).join(', ');
      const confirmMove = window.confirm(
        `The following explorers are already allocated to teams in other events: ${names}.\n\nDo you want to proceed and move them to ${selectedEvent.title}? (Their old empty teams will be cleaned up automatically).`
      );
      if (!confirmMove) return;
    }

    setLoading(true);
    try {
      const payload: any = {
        scout_ids: selectedScoutIds,
        event_id: selectedEvent.id,
        allocation_mode: allocationMode
      };
      if (allocationMode === 'existing_team') {
        payload.target_team_id = targetTeamId;
      }

      const response = await fetch(`${rootUrl}/planning-board/allocate`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce
        },
        body: JSON.stringify(payload)
      });

      if (response && response.ok) {
        // Refetch everything
        await fetchEvents();
        if (selectedEvent) {
          await handleSelectEvent(selectedEvent);
        }
      } else {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to allocate explorers');
      }
    } catch (err: any) {
      alert(err.message || 'Error executing allocation');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{ background: '#fff', border: '1px solid #ccd0d4', borderRadius: '8px', padding: '20px', boxShadow: '0 1px 3px rgba(0,0,0,0.04)' }}>
      {error && (
        <div style={{ padding: '10px 16px', background: '#fcf0f1', borderLeft: '4px solid #d63638', color: '#d63638', marginBottom: '16px', borderRadius: '2px' }}>
          {error}
        </div>
      )}

      {/* Toggles bar */}
      <div style={{ display: 'flex', gap: '20px', alignItems: 'center', borderBottom: '1px solid #f0f0f1', paddingBottom: '16px', marginBottom: '20px' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <strong style={{ fontSize: '13px', color: '#1d2327' }}>LEVEL:</strong>
          <select 
            value={levelFilter} 
            onChange={(e) => setLevelFilter(e.target.value as any)}
            style={{ padding: '4px 8px', border: '1px solid #ccd0d4', borderRadius: '4px', fontSize: '13px' }}
          >
            <option value="silver">Silver</option>
            <option value="gold">Gold</option>
          </select>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <strong style={{ fontSize: '13px', color: '#1d2327' }}>TYPE:</strong>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', cursor: 'pointer', fontSize: '13px' }}>
            <input 
              type="radio" 
              name="typeFilter" 
              value="practice" 
              checked={typeFilter === 'practice'} 
              onChange={() => setTypeFilter('practice')} 
            />
            Practice
          </label>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', cursor: 'pointer', fontSize: '13px', marginLeft: '8px' }}>
            <input 
              type="radio" 
              name="typeFilter" 
              value="qualifier" 
              checked={typeFilter === 'qualifier'} 
              onChange={() => setTypeFilter('qualifier')} 
            />
            Qualifier
          </label>
        </div>
      </div>

      {/* Two-Column split */}
      <div style={{ display: 'flex', gap: '24px', minHeight: '400px' }}>
        
        {/* Left Column - Events list */}
        <div style={{ flex: '1', borderRight: '1px solid #f0f0f1', paddingRight: '20px' }}>
          <h3 style={{ margin: '0 0 16px 0', fontSize: '14px', fontWeight: '600' }}>Select Event</h3>
          {loading && events.length === 0 ? (
            <p>Loading events...</p>
          ) : filteredEvents.length === 0 ? (
            <p style={{ color: '#646970', fontStyle: 'italic' }}>No active events found for this filter state.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
              {filteredEvents.map(e => {
                const isSelected = selectedEvent?.id === e.id;
                return (
                  <div
                    key={e.id}
                    onClick={() => handleSelectEvent(e)}
                    style={{
                      padding: '12px 16px',
                      border: isSelected ? '1px solid #2271b1' : '1px solid #ccd0d4',
                      borderRadius: '6px',
                      background: isSelected ? '#f0f6fc' : '#fff',
                      cursor: 'pointer',
                      transition: 'all 0.2s',
                      boxShadow: isSelected ? '0 1px 4px rgba(34,113,177,0.1)' : '0 1px 2px rgba(0,0,0,0.02)'
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                      <strong style={{ color: isSelected ? '#2271b1' : '#1d2327', fontSize: '13px' }}>
                        {e.title} ({e.event_code})
                      </strong>
                    </div>
                    <div style={{ fontSize: '11px', color: '#646970', marginTop: '6px' }}>
                      Date: {e.start_date || '—'} to {e.end_date || '—'}
                    </div>
                    <div style={{ display: 'flex', gap: '12px', fontSize: '11px', fontWeight: '500', color: '#1d2327', marginTop: '8px' }}>
                      <span>Available: <strong style={{ color: '#2271b1' }}>{e.available_count}</strong></span>
                      <span>Allocated: <strong>{e.allocated_count}</strong></span>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>

        {/* Right Column - Availability & Roster List */}
        <div style={{ flex: '1.5', display: 'flex', flexDirection: 'column' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
            <h3 style={{ margin: 0, fontSize: '14px', fontWeight: '600' }}>
              {selectedEvent ? `Explorer Availability (${selectedEvent.event_code})` : 'Explorer Availability'}
            </h3>
            {selectedEvent && (
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px', fontSize: '12px' }}>
                <span>Sort by:</span>
                <select 
                  value={sortBy} 
                  onChange={(e) => setSortBy(e.target.value as any)}
                  style={{ padding: '2px 6px', border: '1px solid #ccd0d4', borderRadius: '4px', fontSize: '12px' }}
                >
                  <option value="name">Name</option>
                  <option value="unit">Unit</option>
                </select>
              </div>
            )}
          </div>

          {!selectedEvent ? (
            <div style={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', border: '2px dashed #ccd0d4', borderRadius: '8px', padding: '40px', textAlign: 'center', color: '#646970' }}>
              Select an event from the left-hand column to view interested explorers and assign them.
            </div>
          ) : explorersLoading ? (
            <p>Loading availability...</p>
          ) : explorers.length === 0 ? (
            <p style={{ color: '#646970', fontStyle: 'italic' }}>No explorers declared interest in this event code.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
              
              {/* Roster table wrapper */}
              <div style={{ flex: '1', overflowY: 'auto', border: '1px solid #ccd0d4', borderRadius: '6px', maxHeight: '320px', marginBottom: '16px' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '13px' }}>
                  <thead>
                    <tr style={{ background: '#f6f7f7', borderBottom: '1px solid #ccd0d4', textAlign: 'left' }}>
                      <th style={{ padding: '8px 12px', width: '32px', textAlign: 'center' }}>
                        <input 
                          type="checkbox" 
                          checked={selectedScoutIds.length === explorers.length}
                          onChange={handleToggleSelectAll} 
                        />
                      </th>
                      <th style={{ padding: '8px 12px' }}>Name & Unit</th>
                      <th style={{ padding: '8px 12px' }}>Allocation Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sortedExplorers.map(exp => {
                      const isChecked = selectedScoutIds.includes(exp.scout_id);
                      return (
                        <tr 
                          key={exp.scout_id} 
                          style={{ 
                            borderBottom: '1px solid #f0f0f1', 
                            background: isChecked ? '#f9f9f9' : '#fff' 
                          }}
                        >
                          <td style={{ padding: '10px 12px', textAlign: 'center', verticalAlign: 'middle' }}>
                            <input 
                              type="checkbox" 
                              checked={isChecked}
                              onChange={() => handleSelectExplorer(exp.scout_id)} 
                            />
                          </td>
                          <td style={{ padding: '10px 12px' }}>
                            <strong>{exp.first_name} {exp.last_name}</strong>
                            <div style={{ fontSize: '11px', color: '#646970' }}>Unit: {exp.unit_name}</div>
                          </td>
                          <td style={{ padding: '10px 12px', verticalAlign: 'middle' }}>
                            {exp.allocated_event_code ? (
                              <span style={{ fontSize: '11px', color: '#b28900', fontWeight: '500' }}>
                                Allocated: {exp.allocated_team_code} ({exp.allocated_event_code})
                              </span>
                            ) : (
                              <span style={{ fontSize: '11px', color: '#646970' }}>Unallocated</span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              {/* Action Toolbar */}
              <div style={{ padding: '16px', background: '#f6f7f7', border: '1px solid #ccd0d4', borderRadius: '6px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '12px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                  <select 
                    value={allocationMode}
                    onChange={(e) => setAllocationMode(e.target.value as any)}
                    style={{ padding: '6px', border: '1px solid #ccd0d4', borderRadius: '4px', fontSize: '13px' }}
                  >
                    <option value="unallocated">Add to Unallocated</option>
                    <option value="new_team">Add to New Team</option>
                    {eventTeams.length > 0 && <option value="existing_team">Add to Existing Team...</option>}
                  </select>

                  {allocationMode === 'existing_team' && eventTeams.length > 0 && (
                    <select
                      value={targetTeamId}
                      onChange={(e) => setTargetTeamId(parseInt(e.target.value))}
                      style={{ padding: '6px', border: '1px solid #ccd0d4', borderRadius: '4px', fontSize: '13px' }}
                    >
                      {eventTeams.map(t => (
                        <option key={t.ID} value={t.ID}>Team {t.ems_team_code}</option>
                      ))}
                    </select>
                  )}
                </div>

                <div style={{ display: 'flex', gap: '8px' }}>
                  <button 
                    onClick={handleApplyAction} 
                    className="button button-primary"
                    disabled={loading || selectedScoutIds.length === 0}
                  >
                    Apply Action
                  </button>
                </div>
              </div>

            </div>
          )}
        </div>

      </div>
    </div>
  );
}
