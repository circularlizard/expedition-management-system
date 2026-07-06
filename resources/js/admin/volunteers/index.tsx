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
}

interface Volunteer {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    dbs_number: string;
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
                // Flatten events from season dashboard endpoint format
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

    const handleAssign = async (availId: number, confirmVal: number) => {
        try {
            const res = await fetch(`${config.root_url}/volunteers/assign`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    availability_id: availId,
                    confirmed: confirmVal
                })
            });
            if (res.ok) {
                fetchAll();
                if (selectedVolunteer) {
                    // Update drawer data
                    const updatedRes = await fetch(`${config.root_url}/volunteers`, { headers: { 'X-WP-Nonce': config.nonce } });
                    if (updatedRes.ok) {
                        const list = await updatedRes.json();
                        const matching = list.find((v: Volunteer) => v.id === selectedVolunteer.id);
                        if (matching) {
                            setSelectedVolunteer(matching);
                        }
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

    // Get unique dates from all events to form columns
    const datesSet = new Set<string>();
    events.forEach(e => {
        if (e.ems_start_date) datesSet.add(e.ems_start_date);
        if (e.ems_end_date) datesSet.add(e.ems_end_date);
    });
    const sortedDates = Array.from(datesSet).sort();

    return (
        <div style={{ display: 'flex', gap: '20px', position: 'relative' }}>
            <div style={{ flex: 1, overflowX: 'auto' }}>
                <table className="wp-list-table widefat fixed striped table-view-list">
                    <thead>
                        <tr>
                            <th>Volunteer</th>
                            {sortedDates.map(d => (
                                <th key={d} style={{ fontSize: '10px' }}>{new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {volunteers.map(v => (
                            <tr key={v.id} onClick={() => setSelectedVolunteer(v)} style={{ cursor: 'pointer' }}>
                                <td><strong>{v.first_name} {v.last_name}</strong><br/><span style={{ fontSize: '11px', color: '#666' }}>{v.email}</span></td>
                                {sortedDates.map(d => {
                                    const dayShifts = v.availability.filter(s => s.date === d);
                                    if (dayShifts.length === 0) {
                                        return <td key={d}>—</td>;
                                    }
                                    return (
                                        <td key={d}>
                                            {dayShifts.map(s => {
                                                const ev = events.find(e => e.ID === s.expedition_post_id);
                                                const evCode = ev ? ev.ems_event_code : 'Event';
                                                let badgeColor = '#f0b818'; // Pending yellow
                                                let label = `${evCode} (P)`;
                                                if (s.confirmed === 1) {
                                                    badgeColor = '#46b450'; // Confirmed green
                                                    label = `${evCode} (C)`;
                                                } else if (s.confirmed === -1) {
                                                    badgeColor = '#dc3232'; // Conflicted red
                                                    label = `${evCode} (X)`;
                                                }
                                                return (
                                                    <div
                                                        key={s.id}
                                                        style={{
                                                            background: badgeColor,
                                                            color: '#fff',
                                                            padding: '2px 6px',
                                                            borderRadius: '3px',
                                                            fontSize: '10px',
                                                            marginBottom: '2px',
                                                            textAlign: 'center'
                                                        }}
                                                    >
                                                        {label}
                                                    </div>
                                                );
                                            })}
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
                    <p><strong>DBS Number:</strong> {selectedVolunteer.dbs_number || '—'}</p>
                    <p><strong>First Aid:</strong> {selectedVolunteer.qualifications?.first_aid || 'None'}</p>
                    <p><strong>Roles:</strong> {selectedVolunteer.preferred_roles?.join(', ') || 'None'}</p>

                    <h4>Availability Management</h4>
                    {selectedVolunteer.availability.map(s => {
                        const ev = events.find(e => e.ID === s.expedition_post_id);
                        return (
                            <div key={s.id} style={{ borderBottom: '1px solid #eee', paddingBottom: '8px', marginBottom: '8px' }}>
                                <p style={{ margin: '0 0 4px 0', fontSize: '12px' }}>
                                    <strong>{ev ? ev.post_title : 'Expedition'}</strong><br/>
                                    Date: {s.date} {s.overnight ? '(Overnight)' : '(Day)'}
                                </p>
                                <div style={{ display: 'flex', gap: '6px' }}>
                                    {s.confirmed !== 1 && (
                                        <button className="button button-small" onClick={() => handleAssign(s.id, 1)}>Confirm</button>
                                    )}
                                    {s.confirmed !== 0 && (
                                        <button className="button button-small" onClick={() => handleAssign(s.id, 0)}>Unassign</button>
                                    )}
                                </div>
                            </div>
                        );
                    })}
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
