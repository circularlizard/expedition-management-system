import React, { useState } from 'react';
import { Expedition } from '../expedition-board/types';

export interface Volunteer {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    preferred_roles?: string[];
    qualifications?: {
        first_aid?: string;
    };
    constraints?: {
        max_practices?: number | string;
        max_qualifiers?: number | string;
        max_total?: number | string;
    };
    availability: Array<{
        expedition_post_id: number;
        date: string;
        overnight: number;
        confirmed: number;
        signup_type?: 'whole' | 'part';
    }>;
}

interface EventRosterPanelProps {
    selectedEvent: Expedition;
    volunteers: Volunteer[];
    onAssign: (volunteerId: number, eventId: number, confirmed: number) => Promise<void>;
    rootUrl: string;
    nonce: string;
    allEvents: Expedition[];
}

export const EventRosterPanel: React.FC<EventRosterPanelProps> = ({
    selectedEvent,
    volunteers,
    onAssign,
    rootUrl,
    nonce,
    allEvents
}) => {
    const [assignSearch, setAssignSearch] = useState('');
    const [saving, setSaving] = useState(false);

    // Helpers
    const getDatesForEvent = (ev: Expedition) => {
        const dates: string[] = [];
        if (!ev.ems_start_date || !ev.ems_end_date) return dates;
        let curr = new Date(ev.ems_start_date);
        const end = new Date(ev.ems_end_date);
        while (curr <= end) {
            dates.push(curr.toISOString().split('T')[0]);
            curr.setDate(curr.getDate() + 1);
        }
        return dates;
    };

    const getVolunteerAvailabilityForEvent = (vol: Volunteer, eventId: number) => {
        return vol.availability.filter(a => a.expedition_post_id === eventId);
    };

    const checkConstraints = (volunteer: Volunteer, targetEvent: Expedition): { valid: boolean; reason?: string } => {
        const activeConfirmed = volunteer.availability.filter(a => a.confirmed === 1 && a.expedition_post_id !== targetEvent.ID);
        
        // 1. Total event limit check
        const maxTotal = volunteer.constraints?.max_total;
        if (maxTotal !== undefined && maxTotal !== null && maxTotal !== '') {
            if (activeConfirmed.length + 1 > Number(maxTotal)) {
                return { valid: false, reason: `Exceeds overall limit of ${maxTotal} events.` };
            }
        }

        // 2. Type-specific checks
        const eventType = targetEvent.ems_type;
        if (eventType === 'practice') {
            const maxPractices = volunteer.constraints?.max_practices;
            if (maxPractices !== undefined && maxPractices !== null && maxPractices !== '') {
                const practicesCount = activeConfirmed.filter(a => {
                    const ev = allEvents.find(e => e.ID === a.expedition_post_id);
                    return ev?.ems_type === 'practice';
                }).length;
                if (practicesCount + 1 > Number(maxPractices)) {
                    return { valid: false, reason: `Exceeds practice event limit of ${maxPractices}.` };
                }
            }
        }

        if (eventType === 'qualifying') {
            const maxQualifiers = volunteer.constraints?.max_qualifiers;
            if (maxQualifiers !== undefined && maxQualifiers !== null && maxQualifiers !== '') {
                const qualifiersCount = activeConfirmed.filter(a => {
                    const ev = allEvents.find(e => e.ID === a.expedition_post_id);
                    return ev?.ems_type === 'qualifying';
                }).length;
                if (qualifiersCount + 1 > Number(maxQualifiers)) {
                    return { valid: false, reason: `Exceeds qualifying event limit of ${maxQualifiers}.` };
                }
            }
        }

        return { valid: true };
    };

    const getEventCoverageStatus = (event: Expedition): { text: string; color: string } => {
        const confirmedVolunteers = volunteers.filter(v => 
            v.availability.some(a => a.expedition_post_id === event.ID && a.confirmed === 1)
        );
        const pendingVolunteers = volunteers.filter(v => 
            v.availability.some(a => a.expedition_post_id === event.ID && a.confirmed === 0)
        );

        const reqAssessors = parseInt(event.ems_req_assessors as string) || 0;
        const reqVolunteers = parseInt(event.ems_req_volunteers as string) || 0;
        const hasLic = !!event.ems_lic_name;

        const confAssessors = confirmedVolunteers.filter(v => v.preferred_roles?.includes('assessor')).length;
        const confVolunteers = confirmedVolunteers.filter(v => !v.preferred_roles?.includes('assessor')).length;

        if (confirmedVolunteers.length === 0 && pendingVolunteers.length === 0 && !hasLic) {
            return { text: 'No Volunteers', color: '#dc3232' };
        }

        if (confirmedVolunteers.length === 0 && pendingVolunteers.length > 0 && !hasLic) {
            return { text: 'Pending Availability', color: '#2271b1' };
        }

        const licSatisfied = hasLic;
        const assessorsSatisfied = confAssessors >= reqAssessors;
        const volunteersSatisfied = confVolunteers >= reqVolunteers;

        if (licSatisfied && assessorsSatisfied && volunteersSatisfied) {
            return { text: 'Fully Staffed', color: '#46b450' };
        } else {
            return { text: 'Under-staffed', color: '#f0b818' };
        }
    };

    const assignedVolunteers = volunteers.filter(v => 
        v.availability.some(a => a.expedition_post_id === selectedEvent.ID && a.confirmed === 1)
    );

    const availableVolunteers = volunteers.filter(v => 
        v.availability.some(a => a.expedition_post_id === selectedEvent.ID && a.confirmed === 0)
    );

    const otherVolunteers = volunteers.filter(v => 
        !v.availability.some(a => a.expedition_post_id === selectedEvent.ID && a.confirmed === 1) &&
        (assignSearch === '' || `${v.first_name} ${v.last_name}`.toLowerCase().includes(assignSearch.toLowerCase()))
    );

    const selectedLevelColor = selectedEvent.ems_level === 'gold' ? '#B4975A' : selectedEvent.ems_level === 'silver' ? '#A7A9AC' : selectedEvent.ems_level === 'multiple' ? '#4f46e5' : '#BA8748';
    const selectedLevelBg = selectedEvent.ems_level === 'gold' ? '#fff9e6' : selectedEvent.ems_level === 'silver' ? '#f2f2f2' : selectedEvent.ems_level === 'multiple' ? '#eef2ff' : '#f9f0e8';

    return (
        <div className="ems-panel">
            <div style={{ 
                border: '1px solid #ccd0d4', 
                borderTop: `4px solid ${selectedLevelColor}`, 
                backgroundColor: selectedLevelBg, 
                padding: '16px', 
                borderRadius: '6px', 
                marginBottom: '16px' 
            }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <h2 style={{ margin: 0 }}>
                        <a 
                            href={`admin.php?page=ems&event_id=${selectedEvent.ID}`} 
                            target="_blank" 
                            rel="noopener noreferrer"
                            style={{ color: '#2271b1', textDecoration: 'underline' }}
                        >
                            {selectedEvent.post_title} ({selectedEvent.ems_event_code})
                        </a>
                    </h2>
                    <span className="ems-badge" style={{ backgroundColor: getEventCoverageStatus(selectedEvent).color, color: '#fff', fontSize: '11px', padding: '2px 8px', borderRadius: '4px' }}>
                        {getEventCoverageStatus(selectedEvent).text}
                    </span>
                </div>
                <p style={{ margin: '4px 0 0 0', color: '#666' }}>
                    Type: <strong style={{ textTransform: 'capitalize' }}>{selectedEvent.ems_type || 'practice'}</strong> | Dates: {selectedEvent.ems_start_date} to {selectedEvent.ems_end_date}
                </p>
            </div>

            {/* Staffing Targets & Health Metrics */}
            <div style={{ display: 'flex', gap: '15px', background: '#fafafa', border: '1px solid #ddd', padding: '12px', borderRadius: '6px', marginBottom: '16px', fontSize: '12px' }}>
                <div><strong>Staffing Metrics:</strong></div>
                <div>👑 LIC: {selectedEvent.ems_lic_name ? '1/1' : '0/1'}</div>
                <div>🎓 Assessors: {assignedVolunteers.filter(v => v.preferred_roles?.includes('assessor')).length} / {selectedEvent.ems_req_assessors || 0}</div>
                <div>👥 Volunteers: {assignedVolunteers.filter(v => !v.preferred_roles?.includes('assessor')).length} / {selectedEvent.ems_req_volunteers || 0}</div>
            </div>


            {/* Leader in Charge details card */}
            <div style={{ background: '#f0f6fc', border: '1px solid #ccd0d4', padding: '12px', borderRadius: '6px', marginBottom: '16px' }}>
                <h4 style={{ margin: '0 0 6px 0', fontSize: '13px', color: '#1d2327' }}>👑 Leader in Charge (LIC)</h4>
                {selectedEvent.ems_lic_name ? (
                    <div>
                        <strong>{selectedEvent.ems_lic_name}</strong>
                        {selectedEvent.ems_lic_email && <div style={{ fontSize: '12px', color: '#555', marginTop: '2px' }}>📧 {selectedEvent.ems_lic_email}</div>}
                        {selectedEvent.ems_lic_phone && <div style={{ fontSize: '12px', color: '#555', marginTop: '2px' }}>📞 {selectedEvent.ems_lic_phone}</div>}
                    </div>
                ) : (
                    <span style={{ color: '#d63638', fontStyle: 'italic' }}>No Leader in Charge assigned to this event.</span>
                )}
            </div>

            {/* Current Roster */}
            <div className="ems-mb-20">
                <h3>Confirmed Roster & Shifts Grid ({assignedVolunteers.length})</h3>
                {assignedVolunteers.length === 0 ? (
                    <div className="ems-empty" style={{ padding: '20px' }}>No volunteers confirmed for this event.</div>
                ) : (
                    <div style={{ overflowX: 'auto' }}>
                        <table className="widefat striped" style={{ borderCollapse: 'collapse', width: '100%' }}>
                            <thead>
                                <tr>
                                    <th style={{ minWidth: '150px' }}>Volunteer</th>
                                    <th>Roles</th>
                                    {getDatesForEvent(selectedEvent).map((d, idx) => {
                                        const dateLabel = new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
                                        const isLastDay = idx === getDatesForEvent(selectedEvent).length - 1;
                                        return (
                                            <React.Fragment key={d}>
                                                <th style={{ textAlign: 'center', backgroundColor: '#fef3c7', color: '#78350f', fontSize: '11px', padding: '8px 4px' }}>
                                                    ☀ {dateLabel}<br/>(Day)
                                                </th>
                                                {!isLastDay && (
                                                    <th style={{ textAlign: 'center', backgroundColor: '#334155', color: '#f8fafc', fontSize: '11px', padding: '8px 4px' }}>
                                                        🌙 {dateLabel}<br/>(Night)
                                                    </th>
                                                )}
                                            </React.Fragment>
                                        );
                                    })}
                                    <th style={{ textAlign: 'center' }}>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {assignedVolunteers.map(v => {
                                    const dates = getDatesForEvent(selectedEvent);
                                    const shifts = v.availability.filter(a => a.expedition_post_id === selectedEvent.ID);
                                    
                                    return (
                                        <tr key={v.id}>
                                            <td style={{ padding: '8px' }}>
                                                <strong>{v.first_name} {v.last_name}</strong>
                                                <span className="ems-small-text" style={{ display: 'block', color: '#64748b' }}>{v.email}</span>
                                            </td>
                                            <td style={{ padding: '8px', fontSize: '11px' }}>
                                                {v.preferred_roles?.join(', ').toUpperCase() || 'NONE'}
                                            </td>
                                            {dates.map((d, idx) => {
                                                const isLastDay = idx === dates.length - 1;
                                                const dayShift = shifts.find(s => s.date === d && s.overnight === 0);
                                                const nightShift = !isLastDay ? shifts.find(s => s.date === d && s.overnight === 1) : null;

                                                const renderCellStatus = (shift: typeof shifts[0] | null | undefined, isOvernight: boolean) => {
                                                    if (!shift) return <span style={{ color: '#cbd5e1' }}>—</span>;
                                                    if (shift.confirmed === 1) {
                                                        return <span style={{ color: '#166534', fontWeight: 'bold', backgroundColor: '#dcfce7', padding: '2px 6px', borderRadius: '4px', fontSize: '11px' }}>✓ Yes</span>;
                                                    }
                                                    if (shift.confirmed === 0) {
                                                        return <span style={{ color: isOvernight ? '#f8fafc' : '#b45309', backgroundColor: isOvernight ? '#475569' : '#fef3c7', padding: '2px 6px', borderRadius: '4px', fontSize: '11px' }}>? Req</span>;
                                                    }
                                                    return <span style={{ color: '#cbd5e1' }}>—</span>;
                                                };

                                                return (
                                                    <React.Fragment key={d}>
                                                        <td style={{ textAlign: 'center', verticalAlign: 'middle', padding: '8px 4px' }}>
                                                            {renderCellStatus(dayShift, false)}
                                                        </td>
                                                        {!isLastDay && (
                                                            <td style={{ textAlign: 'center', verticalAlign: 'middle', padding: '8px 4px', backgroundColor: '#f8fafc' }}>
                                                                {renderCellStatus(nightShift, true)}
                                                            </td>
                                                        )}
                                                    </React.Fragment>
                                                );
                                            })}
                                            <td style={{ textAlign: 'center', padding: '8px' }}>
                                                <button 
                                                    type="button"
                                                    className="button button-small" 
                                                    onClick={() => onAssign(v.id, selectedEvent.ID, 0)}
                                                >
                                                    Unassign
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Available Submissions (Pending List) */}
            <div className="ems-mb-20">
                <h3>Indicated Availability (Pending Assignments)</h3>
                {availableVolunteers.length === 0 ? (
                    <div className="ems-empty" style={{ padding: '20px' }}>No other volunteers have submitted availability for this event.</div>
                ) : (
                    <div style={{ overflowX: 'auto' }}>
                        <table className="widefat striped" style={{ borderCollapse: 'collapse', width: '100%' }}>
                            <thead>
                                <tr>
                                    <th style={{ minWidth: '150px' }}>Volunteer</th>
                                    <th>Limits & Conflicts</th>
                                    {getDatesForEvent(selectedEvent).map((d, idx) => {
                                        const dateLabel = new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
                                        const isLastDay = idx === getDatesForEvent(selectedEvent).length - 1;
                                        return (
                                            <React.Fragment key={d}>
                                                <th style={{ textAlign: 'center', backgroundColor: '#fef3c7', color: '#78350f', fontSize: '11px', padding: '8px 4px' }}>
                                                    ☀ {dateLabel}<br/>(Day)
                                                </th>
                                                {!isLastDay && (
                                                    <th style={{ textAlign: 'center', backgroundColor: '#334155', color: '#f8fafc', fontSize: '11px', padding: '8px 4px' }}>
                                                        🌙 {dateLabel}<br/>(Night)
                                                    </th>
                                                )}
                                            </React.Fragment>
                                        );
                                    })}
                                    <th style={{ textAlign: 'center' }}>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {availableVolunteers.map(v => {
                                    const validation = checkConstraints(v, selectedEvent);
                                    const dates = getDatesForEvent(selectedEvent);
                                    const shifts = v.availability.filter(a => a.expedition_post_id === selectedEvent.ID);

                                    return (
                                        <tr key={v.id}>
                                            <td style={{ padding: '8px' }}>
                                                <strong>{v.first_name} {v.last_name}</strong>
                                                <span className="ems-small-text" style={{ display: 'block', color: '#64748b' }}>{v.email}</span>
                                            </td>
                                            <td style={{ padding: '8px' }}>
                                                {validation.valid ? (
                                                    <span style={{ color: '#00a32a' }}>✓ Safe</span>
                                                ) : (
                                                    <span className="ems-badge" style={{ backgroundColor: '#fef2f2', color: '#b91c1c', border: '1px solid #fecaca', fontSize: '11px' }} title={validation.reason}>
                                                        ⚠ {validation.reason}
                                                    </span>
                                                )}
                                            </td>
                                            {dates.map((d, idx) => {
                                                const isLastDay = idx === dates.length - 1;
                                                const dayShift = shifts.find(s => s.date === d && s.overnight === 0);
                                                const nightShift = !isLastDay ? shifts.find(s => s.date === d && s.overnight === 1) : null;

                                                const renderCellStatus = (shift: typeof shifts[0] | null | undefined, isOvernight: boolean) => {
                                                    if (!shift) return <span style={{ color: '#cbd5e1' }}>—</span>;
                                                    if (shift.confirmed === 0) {
                                                        return <span style={{ color: isOvernight ? '#f8fafc' : '#b45309', backgroundColor: isOvernight ? '#475569' : '#fef3c7', padding: '2px 6px', borderRadius: '4px', fontSize: '11px', fontWeight: 'bold' }}>? Yes</span>;
                                                    }
                                                    return <span style={{ color: '#cbd5e1' }}>—</span>;
                                                };

                                                return (
                                                    <React.Fragment key={d}>
                                                        <td style={{ textAlign: 'center', verticalAlign: 'middle', padding: '8px 4px' }}>
                                                            {renderCellStatus(dayShift, false)}
                                                        </td>
                                                        {!isLastDay && (
                                                            <td style={{ textAlign: 'center', verticalAlign: 'middle', padding: '8px 4px', backgroundColor: '#f8fafc' }}>
                                                                {renderCellStatus(nightShift, true)}
                                                            </td>
                                                        )}
                                                    </React.Fragment>
                                                );
                                            })}
                                            <td style={{ textAlign: 'center', padding: '8px' }}>
                                                <button 
                                                    type="button"
                                                    className="button button-small button-primary"
                                                    onClick={() => onAssign(v.id, selectedEvent.ID, 1)}
                                                >
                                                    Assign
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Custom Assignment (Override Search) */}
            <div>
                <h3>Assign Other Volunteers</h3>
                <div style={{ marginBottom: '10px' }}>
                    <input 
                        type="text" 
                        placeholder="Search by name to force-assign..." 
                        value={assignSearch} 
                        onChange={e => setAssignSearch(e.target.value)}
                        style={{ width: '100%', padding: '6px' }}
                    />
                </div>
                {assignSearch && (
                    <div style={{ maxHeight: '150px', overflowY: 'auto', border: '1px solid #ccd0d4', borderRadius: '4px' }}>
                        {otherVolunteers.map(v => {
                            const validation = checkConstraints(v, selectedEvent);
                            return (
                                <div 
                                    key={v.id} 
                                    style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 12px', borderBottom: '1px solid #eee', alignItems: 'center' }}
                                >
                                    <div>
                                        <strong>{v.first_name} {v.last_name}</strong> ({v.email})
                                        {!validation.valid && (
                                            <span style={{ color: '#d63638', marginLeft: '10px', fontSize: '11px' }}>
                                                ⚠ {validation.reason}
                                            </span>
                                        )}
                                    </div>
                                    <button 
                                        type="button"
                                        className="button button-small"
                                        disabled={saving}
                                        onClick={async () => {
                                            const dates = getDatesForEvent(selectedEvent);
                                            const shiftsPayload = dates.map((d, idx) => ({
                                                date: d,
                                                overnight: idx < dates.length - 1 ? 1 : 0
                                            }));
                                            setSaving(true);
                                            try {
                                                await fetch(`${rootUrl}/volunteers/availability`, {
                                                    method: 'POST',
                                                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                                                    body: JSON.stringify({
                                                        volunteer_id: v.id,
                                                        expedition_post_id: selectedEvent.ID,
                                                        shifts: shiftsPayload,
                                                        signup_type: 'whole'
                                                    })
                                                });
                                                await onAssign(v.id, selectedEvent.ID, 1);
                                                setAssignSearch('');
                                            } catch (e) {
                                                console.error(e);
                                            } finally {
                                                setSaving(false);
                                            }
                                        }}
                                    >
                                        Force Assign
                                    </button>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
};
