import '../../../css/ems-admin.css';
import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';

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
    availability: Shift[];
}

interface Expedition {
    ID: number;
    post_title: string;
    ems_event_code: string;
    ems_start_date: string;
    ems_end_date: string;
}

function VolunteersDashboard() {
    const [volunteers, setVolunteers] = useState<Volunteer[]>([]);
    const [events, setEvents] = useState<Expedition[]>([]);
    const [loading, setLoading] = useState(true);
    const [selectedVolunteer, setSelectedVolunteer] = useState<Volunteer | null>(null);

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
                setVolunteers(vData);
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
                setEvents(evList);
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

    const handleAssign = async (eventId: number, confirmVal: number) => {
        if (!selectedVolunteer) return;
        try {
            const res = await fetch(`${config.root_url}/volunteers/assign`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    volunteer_id: selectedVolunteer.id,
                    expedition_post_id: eventId,
                    confirmed: confirmVal
                })
            });
            if (res.ok) {
                fetchAll();
                const updatedRes = await fetch(`${config.root_url}/volunteers`, { headers: { 'X-WP-Nonce': config.nonce } });
                if (updatedRes.ok) {
                    const list = await updatedRes.json();
                    const matching = list.find((v: Volunteer) => v.id === selectedVolunteer.id);
                    if (matching) {
                        setSelectedVolunteer(matching);
                    }
                }
            }
        } catch (err) {
            console.error(err);
        }
    };

    if (loading) {
        return <div style={{ padding: '20px' }}>Loading volunteers availability grid...</div>;
    }

    return (
        <div>
            {/* Status Legend Key */}
            <div style={{
                background: '#fff',
                border: '1px solid #ccd0d4',
                padding: '12px 16px',
                borderRadius: '6px',
                marginBottom: '20px',
                display: 'flex',
                gap: '24px',
                fontSize: '13px',
                color: '#1d2327',
                alignItems: 'center',
                boxShadow: '0 1px 3px rgba(0,0,0,0.04)'
            }}>
                <strong>Status Key:</strong>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: '8px' }}>
                    <span style={{ background: '#46b450', color: '#fff', width: '20px', height: '20px', borderRadius: '50%', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold', fontSize: '11px' }}>✓</span>
                    Confirmed
                </span>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: '8px' }}>
                    <span style={{ background: '#f0b818', color: '#fff', width: '20px', height: '20px', borderRadius: '50%', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold', fontSize: '11px' }}>?</span>
                    Pending
                </span>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: '8px' }}>
                    <span style={{ background: '#dc3232', color: '#fff', width: '20px', height: '20px', borderRadius: '50%', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold', fontSize: '11px' }}>✖</span>
                    Conflicted
                </span>
                <span style={{ borderLeft: '1px solid #ccd0d4', height: '16px', margin: '0 8px' }} />
                <span><strong>Border Style:</strong> Solid Circle = Whole Event, Dotted Circle = Partial Commitment</span>
            </div>

            <div style={{ display: 'flex', gap: '20px', position: 'relative' }}>
                <div style={{ flex: 1, overflowX: 'auto' }}>
                    <table className="wp-list-table widefat fixed striped table-view-list">
                        <thead>
                            <tr>
                                <th style={{ width: '200px' }}>Volunteer</th>
                                {events.map(e => (
                                    <th key={e.ID} style={{ fontSize: '11px', textAlign: 'center', width: '100px' }} title={`${e.post_title} (${e.ems_event_code})`}>
                                        <div style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{e.post_title}</div>
                                        <span style={{ fontSize: '9px', color: '#666', fontWeight: 'normal' }}>({e.ems_event_code})</span>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {volunteers.map(v => (
                                <tr key={v.id} onClick={() => setSelectedVolunteer(v)} style={{ cursor: 'pointer' }}>
                                    <td>
                                        <strong>{v.first_name} {v.last_name}</strong><br/>
                                        <span style={{ fontSize: '11px', color: '#666' }}>{v.email}</span>
                                    </td>
                                    {events.map(e => {
                                        const eventShifts = v.availability.filter(s => s.expedition_post_id === e.ID);
                                        if (eventShifts.length === 0) {
                                            return <td key={e.ID} style={{ textAlign: 'center', color: '#ccc' }}>—</td>;
                                        }

                                        const hasConfirmed = eventShifts.some(s => s.confirmed === 1);
                                        const hasConflicted = eventShifts.some(s => s.confirmed === -1);
                                        const isWhole = eventShifts[0]?.signup_type === 'whole';

                                        let badgeColor = '#f0b818';
                                        let symbol = '?';
                                        let titleText = 'Pending';
                                        if (hasConfirmed) {
                                            badgeColor = '#46b450';
                                            symbol = '✓';
                                            titleText = 'Confirmed';
                                        } else if (hasConflicted) {
                                            badgeColor = '#dc3232';
                                            symbol = '✖';
                                            titleText = 'Conflicted';
                                        }

                                        // Desaturate fill color for partial commitment
                                        let finalBadgeColor = badgeColor;
                                        if (!isWhole) {
                                            if (hasConfirmed) {
                                                finalBadgeColor = '#9ccc9c'; 
                                            } else if (hasConflicted) {
                                                finalBadgeColor = '#e59393'; 
                                            } else {
                                                finalBadgeColor = '#e3c878'; 
                                            }
                                        }

                                        const commitmentLabel = isWhole ? 'Whole Event' : 'Partial Availability';
                                        const fullTooltip = `${titleText} (${commitmentLabel}) on ${e.post_title}`;

                                        return (
                                            <td key={e.ID} style={{ textAlign: 'center', verticalAlign: 'middle' }}>
                                                <div
                                                    title={fullTooltip}
                                                    style={{
                                                        display: 'inline-flex',
                                                        alignItems: 'center',
                                                        justifyContent: 'center',
                                                        width: '24px',
                                                        height: '24px',
                                                        borderRadius: '50%',
                                                        background: finalBadgeColor,
                                                        color: '#fff',
                                                        fontWeight: 'bold',
                                                        fontSize: '11px',
                                                        border: isWhole ? '2px solid transparent' : '2px dashed #666',
                                                        boxShadow: '0 1px 2px rgba(0,0,0,0.1)'
                                                    }}
                                                >
                                                    {symbol}
                                                </div>
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {selectedVolunteer && (
                    <div style={{ width: '320px', background: '#fff', border: '1px solid #ccd0d4', padding: '16px', borderRadius: '4px' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
                            <h3>Volunteer Details</h3>
                            <button className="button" onClick={() => setSelectedVolunteer(null)}>Close</button>
                        </div>
                        <p><strong>Name:</strong> {selectedVolunteer.first_name} {selectedVolunteer.last_name}</p>
                        <p><strong>Email:</strong> {selectedVolunteer.email}</p>
                        <p><strong>Phone:</strong> {selectedVolunteer.phone || '—'}</p>
                        <p><strong>First Aid:</strong> {selectedVolunteer.qualifications?.first_aid || 'None'}</p>
                        <p><strong>Roles:</strong> {selectedVolunteer.preferred_roles?.join(', ') || 'None'}</p>

                        <h4>Availability Management</h4>
                        {events.map(ev => {
                            const eventShifts = selectedVolunteer.availability.filter(s => s.expedition_post_id === ev.ID);
                            if (eventShifts.length === 0) return null;

                            const isConfirmed = eventShifts.some(s => s.confirmed === 1);
                            const signupType = eventShifts[0]?.signup_type || 'part';

                            return (
                                <div key={ev.ID} style={{ borderBottom: '1px solid #eee', paddingBottom: '8px', marginBottom: '8px' }}>
                                    <p style={{ margin: '0 0 4px 0', fontSize: '12px' }}>
                                        <strong>{ev.post_title}</strong> ({ev.ems_event_code})
                                    </p>
                                    <p style={{ margin: '0 0 6px 0', fontSize: '11px', color: '#666' }}>
                                        Commitment: <strong>{signupType === 'whole' ? 'Whole Event' : 'Partial'}</strong>
                                    </p>
                                    {signupType === 'part' && (
                                        <div style={{ background: '#f9f9f9', padding: '6px', borderRadius: '3px', fontSize: '11px', marginBottom: '8px' }}>
                                            <strong style={{ display: 'block', marginBottom: '2px' }}>Requested Shifts:</strong>
                                            {eventShifts.map((s, idx) => (
                                                <div key={idx} style={{ padding: '2px 0' }}>
                                                    📅 {s.date} {s.overnight ? '(Overnight)' : '(Day)'}
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                    <div style={{ display: 'flex', gap: '6px' }}>
                                        {!isConfirmed && (
                                            <button className="button button-small button-primary" onClick={() => handleAssign(ev.ID, 1)}>Confirm Assignment</button>
                                        )}
                                        {isConfirmed && (
                                            <button className="button button-small" onClick={() => handleAssign(ev.ID, 0)}>Unassign</button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
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
