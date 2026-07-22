import '../../../css/ems-admin.css';
import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { EventRosterPanel } from './EventRosterPanel';

interface Shift {
    id: number;
    volunteer_id: number;
    expedition_post_id: number;
    date: string;
    overnight: number;
    confirmed: number;
    signup_type?: string;
}

interface Volunteer {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    qualifications: {
        first_aid?: string;
        permits?: string[];
    };
    preferred_roles: string[];
    constraints?: {
        max_practices?: number;
        max_qualifiers?: number;
        max_total?: number;
    };
    availability: Shift[];
}

interface Expedition {
    ID: number;
    post_title: string;
    ems_event_code: string;
    ems_start_date: string;
    ems_end_date: string;
    ems_type?: 'training' | 'practice' | 'qualifying';
    ems_level?: 'bronze' | 'silver' | 'gold' | 'multiple';
}

function formatHeaderDate(startStr: string, endStr: string): string {
    if (!startStr) return '';
    try {
        const start = new Date(startStr);
        const end = endStr ? new Date(endStr) : null;
        const options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short' };
        const startFormatted = start.toLocaleDateString('en-GB', options);
        if (end && startStr !== endStr) {
            const endFormatted = end.toLocaleDateString('en-GB', options);
            return `${startFormatted} – ${endFormatted}`;
        }
        return startFormatted;
    } catch {
        return startStr;
    }
}

function VolunteersDashboard() {
    const [activeTab, setActiveTab] = useState<'staffing' | 'registry'>('staffing');
    const [volunteers, setVolunteers] = useState<Volunteer[]>([]);
    const [events, setEvents] = useState<Expedition[]>([]);
    const [loading, setLoading] = useState(true);
    
    // Selection state
    const [selectedEventId, setSelectedEventId] = useState<number | null>(null);
    const [selectedVolunteerId, setSelectedVolunteerId] = useState<number | null>(null);
    
    // Staffing filter state
    const [coverageFilter, setCoverageFilter] = useState<'all' | 'needs' | 'partial' | 'full' | 'has_pending'>('all');
    
    // Registry Search/Filter state
    const [searchTerm, setSearchTerm] = useState('');
    
    // Constraints editor state
    const [editingConstraints, setEditingConstraints] = useState<{
        max_practices: string;
        max_qualifiers: string;
        max_total: string;
    } | null>(null);

    // Manual availability editor state
    const [editingAvailabilityEventId, setEditingAvailabilityEventId] = useState<number | null>(null);
    const [tempShifts, setTempShifts] = useState<{ date: string; overnight: number }[]>([]);
    const [tempSignupType, setTempSignupType] = useState<'whole' | 'part'>('part');

    // Force-assign search query
    const [assignSearch, setAssignSearch] = useState('');
    const [saving, setSaving] = useState(false);

    const config = (window as any).emsVolunteers || { root_url: '/wp-json/ems/v1', nonce: '' };

    const fetchAll = async () => {
        setLoading(true);
        try {
            const [vRes, eRes] = await Promise.all([
                fetch(`${config.root_url}/volunteers`, { headers: { 'X-WP-Nonce': config.nonce } }),
                fetch(`${config.root_url}/expedition-board`, { headers: { 'X-WP-Nonce': config.nonce } })
            ]);

            if (vRes.ok) {
                const vData = await vRes.json();
                setVolunteers(vData || []);
            }
            if (eRes.ok) {
                const eData = await eRes.json();
                const evList: Expedition[] = [];
                if (eData.seasons) {
                    eData.seasons.forEach((s: any) => {
                        if (s.events) {
                            s.events.forEach((ev: any) => evList.push(ev));
                        }
                    });
                }
                setEvents(evList || []);
                if (evList.length > 0 && !selectedEventId) {
                    setSelectedEventId(evList[0].ID);
                }
            }
        } catch (err) {
            console.error('Error fetching data', err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchAll();
    }, []);

    // Set initial volunteer selection
    useEffect(() => {
        if (volunteers.length > 0 && !selectedVolunteerId) {
            setSelectedVolunteerId(volunteers[0].id);
        }
    }, [volunteers]);

    // Track state of inline constraints changes when selection updates
    useEffect(() => {
        const vol = volunteers.find(v => v.id === selectedVolunteerId);
        if (vol) {
            setEditingConstraints({
                max_practices: vol.constraints?.max_practices?.toString() || '',
                max_qualifiers: vol.constraints?.max_qualifiers?.toString() || '',
                max_total: vol.constraints?.max_total?.toString() || '',
            });
        } else {
            setEditingConstraints(null);
        }
    }, [selectedVolunteerId, volunteers]);

    // Assignment & Availability API calls
    const handleAssign = async (volunteerId: number, eventId: number, confirmVal: number) => {
        try {
            const res = await fetch(`${config.root_url}/volunteers/assign`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    volunteer_id: volunteerId,
                    expedition_post_id: eventId,
                    confirmed: confirmVal
                })
            });
            if (res.ok) {
                await fetchAll();
            }
        } catch (err) {
            console.error(err);
        }
    };

    const handleSaveConstraints = async (volunteerId: number) => {
        if (!editingConstraints) return;
        setSaving(true);
        try {
            const vol = volunteers.find(v => v.id === volunteerId);
            if (!vol) return;

            const res = await fetch(`${config.root_url}/volunteers/save`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    ...vol,
                    constraints: {
                        max_practices: editingConstraints.max_practices ? parseInt(editingConstraints.max_practices) : null,
                        max_qualifiers: editingConstraints.max_qualifiers ? parseInt(editingConstraints.max_qualifiers) : null,
                        max_total: editingConstraints.max_total ? parseInt(editingConstraints.max_total) : null,
                    }
                })
            });
            if (res.ok) {
                await fetchAll();
                alert('Constraints updated successfully!');
            }
        } catch (err) {
            console.error(err);
        } finally {
            setSaving(false);
        }
    };

    const handleSaveManualAvailability = async (volunteerId: number, eventId: number) => {
        setSaving(true);
        try {
            const res = await fetch(`${config.root_url}/volunteers/availability`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    volunteer_id: volunteerId,
                    expedition_post_id: eventId,
                    shifts: tempShifts,
                    signup_type: tempSignupType
                })
            });
            if (res.ok) {
                await fetchAll();
                setEditingAvailabilityEventId(null);
                alert('Availability updated successfully!');
            }
        } catch (err) {
            console.error(err);
        } finally {
            setSaving(false);
        }
    };

    // Calculate dates list for editing availability
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

    const handleOpenAvailEditor = (vol: Volunteer, ev: Expedition) => {
        setEditingAvailabilityEventId(ev.ID);
        setTempSignupType(vol.availability.find(a => a.expedition_post_id === ev.ID)?.signup_type as any || 'part');
        const currentShifts = vol.availability
            .filter(a => a.expedition_post_id === ev.ID)
            .map(a => ({ date: a.date, overnight: a.overnight }));
        setTempShifts(currentShifts);
    };

    const toggleTempShift = (date: string, overnight: number) => {
        const exists = tempShifts.some(s => s.date === date && s.overnight === overnight);
        if (exists) {
            setTempShifts(tempShifts.filter(s => !(s.date === date && s.overnight === overnight)));
        } else {
            setTempShifts([...tempShifts, { date, overnight }]);
        }
    };

    // Constraint validation engine
    const getVolunteerAvailabilityForEvent = (vol: Volunteer, eventId: number) => {
        return vol.availability.filter(a => a.expedition_post_id === eventId);
    };

    const checkConstraints = (volunteer: Volunteer, targetEvent: Expedition): { valid: boolean; reason?: string } => {
        const activeConfirmed = volunteer.availability.filter(a => a.confirmed === 1 && a.expedition_post_id !== targetEvent.ID);
        
        // 1. Total event limit check
        const maxTotal = volunteer.constraints?.max_total;
        if (maxTotal !== undefined && maxTotal !== null) {
            if (activeConfirmed.length + 1 > maxTotal) {
                return { valid: false, reason: `Exceeds overall limit of ${maxTotal} events.` };
            }
        }

        // 2. Type-specific checks
        const eventType = targetEvent.ems_type;
        if (eventType === 'practice') {
            const maxPractices = volunteer.constraints?.max_practices;
            if (maxPractices !== undefined && maxPractices !== null) {
                const practicesCount = activeConfirmed.filter(a => {
                    const ev = events.find(e => e.ID === a.expedition_post_id);
                    return ev?.ems_type === 'practice';
                }).length;
                if (practicesCount + 1 > maxPractices) {
                    return { valid: false, reason: `Exceeds practice event limit of ${maxPractices}.` };
                }
            }
        }

        if (eventType === 'qualifying') {
            const maxQualifiers = volunteer.constraints?.max_qualifiers;
            if (maxQualifiers !== undefined && maxQualifiers !== null) {
                const qualifiersCount = activeConfirmed.filter(a => {
                    const ev = events.find(e => e.ID === a.expedition_post_id);
                    return ev?.ems_type === 'qualifying';
                }).length;
                if (qualifiersCount + 1 > maxQualifiers) {
                    return { valid: false, reason: `Exceeds qualifying event limit of ${maxQualifiers}.` };
                }
            }
        }

        return { valid: true };
    };

    // Calculate coverage health for each event based on targets
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

        // 1. Check if there are absolutely no volunteers (confirmed or pending) and no LIC
        if (confirmedVolunteers.length === 0 && pendingVolunteers.length === 0 && !hasLic) {
            return { text: 'No Volunteers', color: '#dc3232' }; // Red
        }

        // 2. Check if there are only unconfirmed/pending volunteers
        if (confirmedVolunteers.length === 0 && pendingVolunteers.length > 0 && !hasLic) {
            return { text: 'Pending Availability', color: '#2271b1' }; // Blue
        }

        // 3. Verify if fully staffed (LIC set + confirmed assessors >= required + confirmed volunteers >= required)
        const licSatisfied = hasLic;
        const assessorsSatisfied = confAssessors >= reqAssessors;
        const volunteersSatisfied = confVolunteers >= reqVolunteers;

        if (licSatisfied && assessorsSatisfied && volunteersSatisfied) {
            return { text: 'Fully Staffed', color: '#46b450' }; // Green
        } else {
            return { text: 'Under-staffed', color: '#f0b818' }; // Amber
        }
    };

    if (loading) {
        return <div className="ems-p-20">Loading volunteers availability console...</div>;
    }

    const selectedEvent = events.find(e => e.ID === selectedEventId);
    const selectedLevelColor = selectedEvent ? (selectedEvent.ems_level === 'gold' ? '#B4975A' : selectedEvent.ems_level === 'silver' ? '#A7A9AC' : selectedEvent.ems_level === 'multiple' ? '#4f46e5' : '#BA8748') : '';
    const selectedLevelBg = selectedEvent ? (selectedEvent.ems_level === 'gold' ? '#fff9e6' : selectedEvent.ems_level === 'silver' ? '#f2f2f2' : selectedEvent.ems_level === 'multiple' ? '#eef2ff' : '#f9f0e8') : '';
    const selectedVolunteer = volunteers.find(v => v.id === selectedVolunteerId);

    // Filtered events based on coverage selections
    const filteredEvents = events.filter(ev => {
        const health = getEventCoverageStatus(ev).text;
        const pendCount = volunteers.filter(v => v.availability.some(a => a.expedition_post_id === ev.ID && a.confirmed === 0)).length;

        if (coverageFilter === 'no_volunteers' && health !== 'No Volunteers') return false;
        if (coverageFilter === 'pending' && health !== 'Pending Availability') return false;
        if (coverageFilter === 'under_staffed' && health !== 'Under-staffed') return false;
        if (coverageFilter === 'fully_staffed' && health !== 'Fully Staffed') return false;
        if (coverageFilter === 'has_pending' && pendCount === 0) return false;
        return true;
    });

    const assignedVolunteers = volunteers.filter(v => 
        v.availability.some(a => a.expedition_post_id === selectedEventId && a.confirmed === 1)
    );

    const availableVolunteers = volunteers.filter(v => 
        v.availability.some(a => a.expedition_post_id === selectedEventId && a.confirmed === 0)
    );

    const otherVolunteers = volunteers.filter(v => 
        !v.availability.some(a => a.expedition_post_id === selectedEventId && a.confirmed === 1) &&
        (assignSearch === '' || `${v.first_name} ${v.last_name}`.toLowerCase().includes(assignSearch.toLowerCase()))
    );

    const filteredVolunteersRegistry = volunteers.filter(v => 
        searchTerm === '' || `${v.first_name} ${v.last_name} ${v.email}`.toLowerCase().includes(searchTerm.toLowerCase())
    );

    return (
        <div className="ems-volunteers-dashboard-wrapper">
            {/* Standard WP Navigation Tabs */}
            <nav className="nav-tab-wrapper ems-mb-20">
                <button 
                    className={`nav-tab ${activeTab === 'staffing' ? 'nav-tab-active' : ''}`} 
                    onClick={() => setActiveTab('staffing')}
                >
                    📅 Event Staffing Console
                </button>
                <button 
                    className={`nav-tab ${activeTab === 'registry' ? 'nav-tab-active' : ''}`} 
                    onClick={() => setActiveTab('registry')}
                >
                    👤 Volunteer Registry
                </button>
            </nav>

            {/* TAB 1: EVENT STAFFING */}
            {activeTab === 'staffing' && (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: '20px' }}>
                    {/* Left Pane: Events List with Filter */}
                    <div className="ems-panel" style={{ maxHeight: '80vh', overflowY: 'auto' }}>
                        <div style={{ marginBottom: '15px' }}>
                            <label className="ems-toolbar__label" htmlFor="coverage-filter-select" style={{ display: 'block', marginBottom: '4px' }}>Filter by Availability</label>
                            <select 
                                id="coverage-filter-select"
                                className="ems-select" 
                                style={{ width: '100%', height: '36px' }}
                                value={coverageFilter}
                                onChange={e => setCoverageFilter(e.target.value as any)}
                            >
                                <option value="all">All Events</option>
                                <option value="no_volunteers">No Volunteers</option>
                                <option value="pending">Pending Availability</option>
                                <option value="under_staffed">Under-staffed</option>
                                <option value="fully_staffed">Fully Staffed</option>
                                <option value="has_pending">Has Pending Signups</option>
                            </select>
                        </div>

                        <h3 className="ems-section-heading">Expeditions Coverage</h3>
                        {filteredEvents.map(ev => {
                            const health = getEventCoverageStatus(ev);
                            const isSelected = ev.ID === selectedEventId;
                            const levelColor = ev.ems_level === 'gold' ? '#B4975A' : ev.ems_level === 'silver' ? '#A7A9AC' : ev.ems_level === 'multiple' ? '#4f46e5' : '#BA8748';
                            const levelBg = ev.ems_level === 'gold' ? '#fff9e6' : ev.ems_level === 'silver' ? '#f2f2f2' : ev.ems_level === 'multiple' ? '#eef2ff' : '#f9f0e8';
                            
                            // Check signup metrics
                            const confCount = volunteers.filter(v => v.availability.some(a => a.expedition_post_id === ev.ID && a.confirmed === 1)).length;
                            const pendCount = volunteers.filter(v => v.availability.some(a => a.expedition_post_id === ev.ID && a.confirmed === 0)).length;

                            return (
                                <div 
                                    key={ev.ID} 
                                    onClick={() => setSelectedEventId(ev.ID)}
                                    className={`ems-event-card ${isSelected ? 'ems-event-card--selected' : ''}`}
                                    style={{ 
                                        padding: '12px', 
                                        marginBottom: '10px', 
                                        borderRadius: '6px', 
                                        border: isSelected ? '2px solid #2271b1' : '1px solid #ccd0d4', 
                                        borderTop: `4px solid ${levelColor}`,
                                        backgroundColor: '#ffffff',
                                        cursor: 'pointer' 
                                    }}
                                >
                                    <div style={{ marginBottom: '6px' }}>
                                        <strong className="ems-event-card__title" style={{ fontSize: '13px', display: 'block', color: '#1d2327' }}>
                                            {ev.post_title} ({ev.ems_event_code})
                                        </strong>
                                    </div>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                        <div className="ems-event-card__dates" style={{ fontSize: '12px', color: '#64748b', margin: 0 }}>
                                            Type: <strong style={{ textTransform: 'capitalize' }}>{ev.ems_type || 'practice'}</strong> | {formatHeaderDate(ev.ems_start_date, ev.ems_end_date)}
                                        </div>
                                        <span className="ems-badge" style={{ backgroundColor: health.color, color: '#fff', fontSize: '11px', padding: '2px 8px', borderRadius: '4px', whiteSpace: 'nowrap' }}>
                                            {health.text}
                                        </span>
                                    </div>
                                    <div style={{ fontSize: '11px', marginTop: '6px', color: '#666', fontWeight: 500 }}>
                                        👤 {confCount} Confirmed {pendCount > 0 ? `| ${pendCount} Pending` : ''}
                                        {confCount === 0 && pendCount === 0 && ' | No volunteers registered'}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {/* Right Pane: Event Detail & Roster Builder */}
                    {selectedEvent ? (
                        <EventRosterPanel 
                            selectedEvent={selectedEvent}
                            volunteers={volunteers}
                            onAssign={handleAssign}
                            rootUrl={config.root_url}
                            nonce={config.nonce}
                            allEvents={events}
                        />
                    ) : (
                        <div className="ems-panel ems-empty">Select an expedition from the left list to manage staffing.</div>
                    )}
                </div>
            )}

            {/* TAB 2: VOLUNTEER REGISTRY */}
            {activeTab === 'registry' && (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: '20px' }}>
                    {/* Left Pane: Volunteers Search List */}
                    <div className="ems-panel" style={{ maxHeight: '80vh', overflowY: 'auto' }}>
                        <div style={{ marginBottom: '12px' }}>
                            <input 
                                type="text" 
                                placeholder="Search volunteers..." 
                                value={searchTerm} 
                                onChange={e => setSearchTerm(e.target.value)} 
                                style={{ width: '100%', padding: '6px' }}
                            />
                        </div>
                        {filteredVolunteersRegistry.map(v => {
                            const isSelected = v.id === selectedVolunteerId;
                            const confirmedCount = v.availability.filter(a => a.confirmed === 1).length;
                            return (
                                <div 
                                    key={v.id} 
                                    onClick={() => setSelectedVolunteerId(v.id)}
                                    style={{ 
                                        padding: '12px', 
                                        marginBottom: '8px', 
                                        borderRadius: '4px', 
                                        border: isSelected ? '2px solid #2271b1' : '1px solid #ccd0d4', 
                                        cursor: 'pointer',
                                        backgroundColor: isSelected ? '#f0f6fc' : '#fff'
                                    }}
                                >
                                    <strong>{v.first_name} {v.last_name}</strong>
                                    <div className="ems-small-text" style={{ color: '#666' }}>{v.email}</div>
                                    <div style={{ fontSize: '11px', marginTop: '4px', color: '#15803D' }}>
                                        Assignments: <strong>{confirmedCount} Confirmed</strong> {v.constraints?.max_total ? `(Max ${v.constraints.max_total})` : ''}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    {/* Right Pane: Volunteer Schedule Profile & Constraints */}
                    {selectedVolunteer ? (
                        <div className="ems-panel">
                            <h2 style={{ borderBottom: '1px solid #eee', paddingBottom: '8px', marginBottom: '16px' }}>
                                {selectedVolunteer.first_name} {selectedVolunteer.last_name}
                            </h2>

                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px', marginBottom: '24px' }}>
                                {/* Personal Info */}
                                <div>
                                    <h3>Contact Info</h3>
                                    <p><strong>Email:</strong> {selectedVolunteer.email}</p>
                                    <p><strong>Phone:</strong> {selectedVolunteer.phone || '—'}</p>
                                    <p><strong>First Aid:</strong> {selectedVolunteer.qualifications?.first_aid || 'None'}</p>
                                    <p><strong>Preferred Roles:</strong> {selectedVolunteer.preferred_roles?.join(', ').toUpperCase() || 'None'}</p>
                                </div>

                                {/* Constraints Configuration */}
                                <div>
                                    <h3>Constraints & Limits</h3>
                                    {editingConstraints && (
                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                                            <label>
                                                Max Practice Events
                                                <input 
                                                    type="number" 
                                                    value={editingConstraints.max_practices} 
                                                    onChange={e => setEditingConstraints({ ...editingConstraints, max_practices: e.target.value })}
                                                    style={{ display: 'block', width: '100%', padding: '4px' }}
                                                />
                                            </label>
                                            <label>
                                                Max Qualifying Events
                                                <input 
                                                    type="number" 
                                                    value={editingConstraints.max_qualifiers} 
                                                    onChange={e => setEditingConstraints({ ...editingConstraints, max_qualifiers: e.target.value })}
                                                    style={{ display: 'block', width: '100%', padding: '4px' }}
                                                />
                                            </label>
                                            <label>
                                                Max Total Events
                                                <input 
                                                    type="number" 
                                                    value={editingConstraints.max_total} 
                                                    onChange={e => setEditingConstraints({ ...editingConstraints, max_total: e.target.value })}
                                                    style={{ display: 'block', width: '100%', padding: '4px' }}
                                                />
                                            </label>
                                            <button 
                                                className="button button-primary" 
                                                onClick={() => handleSaveConstraints(selectedVolunteer.id)}
                                                disabled={saving}
                                            >
                                                Save Constraints
                                            </button>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Availability & Overrides */}
                            <div>
                                <h3 style={{ borderBottom: '1px solid #eee', paddingBottom: '6px' }}>Availability Overrides</h3>
                                {events.map(ev => {
                                    const availRow = selectedVolunteer.availability.find(a => a.expedition_post_id === ev.ID);
                                    const isEditingThis = editingAvailabilityEventId === ev.ID;
                                    const isConfirmed = availRow?.confirmed === 1;

                                    // Better visual highlighting depending on availability status
                                    let borderStyle = '1px solid #ccd0d4';
                                    let bgStyle = '#f9f9f9';
                                    let statusLabel = 'No Availability Indicated';
                                    
                                    if (availRow) {
                                        if (isConfirmed) {
                                            borderStyle = '2px solid var(--ems-primary, #15803D)';
                                            bgStyle = 'var(--ems-primary-light, #DCFCE7)';
                                            statusLabel = 'Assigned (Confirmed)';
                                        } else {
                                            borderStyle = '2.5px dashed #C2410C'; // Accent orange borders
                                            bgStyle = '#fffdf5'; // Soft amber
                                            statusLabel = 'Available (Pending)';
                                        }
                                    }

                                    return (
                                        <div 
                                            key={ev.ID} 
                                            style={{ 
                                                border: borderStyle, 
                                                borderRadius: '4px', 
                                                padding: '12px', 
                                                marginBottom: '10px',
                                                backgroundColor: bgStyle,
                                                transition: 'all 0.15s ease-in-out'
                                            }}
                                        >
                                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                                <div>
                                                    <strong>{ev.post_title}</strong> ({ev.ems_event_code})
                                                    <div style={{ fontSize: '11px', fontWeight: 600, color: isConfirmed ? '#15803D' : (availRow ? '#9A3412' : '#666'), marginTop: '2px' }}>
                                                        Status: {statusLabel}
                                                    </div>
                                                </div>
                                                {!isEditingThis ? (
                                                    <button 
                                                        className="button button-small"
                                                        onClick={() => handleOpenAvailEditor(selectedVolunteer, ev)}
                                                    >
                                                        Modify
                                                    </button>
                                                ) : (
                                                    <div style={{ display: 'flex', gap: '6px' }}>
                                                        <button 
                                                            className="button button-small button-primary"
                                                            onClick={() => handleSaveManualAvailability(selectedVolunteer.id, ev.ID)}
                                                            disabled={saving}
                                                        >
                                                            Save
                                                        </button>
                                                        <button 
                                                            className="button button-small"
                                                            onClick={() => setEditingAvailabilityEventId(null)}
                                                        >
                                                            Cancel
                                                        </button>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Expandable manual shifts checkboxes inside availability editor */}
                                            {isEditingThis && (
                                                <div style={{ marginTop: '10px', padding: '10px', background: '#f3f4f6', borderRadius: '4px' }}>
                                                    <div style={{ marginBottom: '8px' }}>
                                                        <label style={{ marginRight: '12px' }}>
                                                            <input 
                                                                type="radio" 
                                                                checked={tempSignupType === 'whole'} 
                                                                onChange={() => setTempSignupType('whole')}
                                                            /> Whole Event
                                                        </label>
                                                        <label>
                                                            <input 
                                                                type="radio" 
                                                                checked={tempSignupType === 'part'} 
                                                                onChange={() => setTempSignupType('part')}
                                                            /> Part of Event
                                                        </label>
                                                    </div>

                                                    {tempSignupType === 'part' && (
                                                        <table className="widefat" style={{ background: '#fff' }}>
                                                            <thead>
                                                                <tr>
                                                                    <th>Date</th>
                                                                    <th>Daytime</th>
                                                                    <th>Overnight</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                {getDatesForEvent(ev).map((date, idx) => (
                                                                    <tr key={date}>
                                                                        <td>{date}</td>
                                                                        <td>
                                                                            <input 
                                                                                type="checkbox"
                                                                                checked={tempShifts.some(s => s.date === date && s.overnight === 0)}
                                                                                onChange={() => toggleTempShift(date, 0)}
                                                                            />
                                                                        </td>
                                                                        <td>
                                                                            {idx < getDatesForEvent(ev).length - 1 ? (
                                                                                <input 
                                                                                    type="checkbox"
                                                                                    checked={tempShifts.some(s => s.date === date && s.overnight === 1)}
                                                                                    onChange={() => toggleTempShift(date, 1)}
                                                                                />
                                                                            ) : '—'}
                                                                        </td>
                                                                    </tr>
                                                                ))}
                                                            </tbody>
                                                        </table>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    ) : (
                        <div className="ems-panel ems-empty">Select a volunteer from the left list.</div>
                    )}
                </div>
            )}
        </div>
    );
}

document.addEventListener('DOMContentLoaded', () => {
    const rootEl = document.getElementById('ems-volunteers-root');
    if (rootEl) {
        const root = createRoot(rootEl);
        root.render(<VolunteersDashboard />);
    }
});

export default VolunteersDashboard;
