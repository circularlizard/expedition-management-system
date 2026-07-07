import '../../../css/ems-admin.css';
import React, { useState, useEffect, useRef } from 'react';
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
    const inspectorRef = useRef<HTMLDivElement>(null);

    // Pagination
    const [currentPage, setCurrentPage] = useState<number>(1);
    const [itemsPerPage, setItemsPerPage] = useState<number>(25);

    useEffect(() => {
        setCurrentPage(1);
    }, [itemsPerPage]);

    useEffect(() => {
        if (selectedVolunteer && inspectorRef.current) {
            inspectorRef.current.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
        }
    }, [selectedVolunteer]);

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

    const totalPages = Math.ceil(volunteers.length / itemsPerPage);
    const paginatedVolunteers = volunteers.slice(
        (currentPage - 1) * itemsPerPage,
        currentPage * itemsPerPage
    );

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
        return <div className="ems-p-20">Loading volunteers availability grid...</div>;
    }

    return (
        <div className="wrap">
            <div className="ems-flex-between ems-mb-16">
                <h2 className="ems-m-0">Volunteers Grid</h2>
                <span className="ems-meta-text">Total Volunteers: {volunteers.length}</span>
            </div>
            {/* Status Legend Key */}
            <div className="ems-volunteers-legend-bar">
                <span className="ems-inline-flex-center ems-gap-8">
                    <span className="ems-volunteers-status-icon ems-volunteers-status-icon--confirmed">✓</span>
                    Confirmed shift(s) on event
                </span>
                <span className="ems-inline-flex-center ems-gap-8">
                    <span className="ems-volunteers-status-icon ems-volunteers-status-icon--pending">?</span>
                    Overnight requested but not confirmed
                </span>
                <span className="ems-inline-flex-center ems-gap-8">
                    <span className="ems-volunteers-status-icon ems-volunteers-status-icon--rejected">✖</span>
                    Shift requested on different event (Conflict)
                </span>
                <span className="ems-volunteers-v-divider" />
                <span><strong>Border Style:</strong> Solid Circle = Whole Event, Dotted Circle = Partial Commitment</span>
            </div>

            <div className="ems-volunteers-split">
                <div className="ems-volunteers-table-wrap">
                    <table className="wp-list-table widefat fixed striped table-view-list">
                        <thead>
                            <tr>
                                <th className="ems-volunteers-col-name">Volunteer</th>
                                {events.map(e => (
                                    <th key={e.ID} className="ems-volunteers-col-event" title={`${e.post_title} (${e.ems_event_code})`}>
                                        <div className="ems-ellipsis">{e.post_title}</div>
                                        <span className="ems-volunteers-event-code">({e.ems_event_code})</span>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {paginatedVolunteers.map(v => (
                                <tr key={v.id} onClick={() => setSelectedVolunteer(v)} className="ems-row-hoverable ems-cursor-pointer">
                                    <td>
                                        <strong>{v.first_name} {v.last_name}</strong><br/>
                                        <span className="ems-table-cell--meta">{v.email}</span>
                                    </td>
                                    {events.map(e => {
                                        const eventShifts = v.availability.filter(s => s.expedition_post_id === e.ID);
                                        if (eventShifts.length === 0) {
                                            return <td key={e.ID} className="ems-volunteers-cell-empty">—</td>;
                                        }

                                        const hasConfirmed = eventShifts.some(s => s.confirmed === 1);
                                        const hasConflicted = eventShifts.some(s => s.confirmed === -1);
                                        const isWhole = eventShifts[0]?.signup_type === 'whole';
                                        const isOvernightSignup = eventShifts.some(s => s.overnight === 1);

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
                                            <td key={e.ID} className="ems-volunteers-cell-value">
                                                <div
                                                    title={fullTooltip}
                                                    className="ems-volunteers-cell-indicator"
                                                    style={{
                                                        background: finalBadgeColor,
                                                        border: isWhole ? '2px solid transparent' : '2px dashed #666'
                                                    }}
                                                >
                                                    {symbol}
                                                </div>
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                     </table>

                    {/* Pagination Bar */}
                    {volunteers.length > 0 && (
                        <div className="ems-table-pagination">
                            <div className="ems-meta-text">
                                Showing {((currentPage - 1) * itemsPerPage) + 1}–{Math.min(currentPage * itemsPerPage, volunteers.length)} of {volunteers.length} records
                            </div>
                            <div className="ems-flex-center ems-gap-8">
                                <label htmlFor="vol-items-per-page" className="ems-toolbar__label">Items per page:</label>
                                <select
                                    id="vol-items-per-page"
                                    className="ems-select-sm"
                                    value={itemsPerPage}
                                    onChange={(e) => {
                                        setItemsPerPage(parseInt(e.target.value));
                                        setCurrentPage(1);
                                    }}
                                >
                                    <option value={10}>10</option>
                                    <option value={25}>25</option>
                                    <option value={50}>50</option>
                                    <option value={100}>100</option>
                                </select>

                                <div className="ems-pagination-buttons ems-flex-center ems-gap-4">
                                    <button
                                        type="button"
                                        className="button button-small"
                                        onClick={() => setCurrentPage(1)}
                                        disabled={currentPage === 1}
                                    >
                                        «
                                    </button>
                                    <button
                                        type="button"
                                        className="button button-small"
                                        onClick={() => setCurrentPage(prev => Math.max(prev - 1, 1))}
                                        disabled={currentPage === 1}
                                    >
                                        ‹ Prev
                                    </button>
                                    <span className="ems-meta-text ems-mx-8">
                                        Page {currentPage} of {totalPages}
                                    </span>
                                    <button
                                        type="button"
                                        className="button button-small"
                                        onClick={() => setCurrentPage(prev => Math.min(prev + 1, totalPages))}
                                        disabled={currentPage === totalPages}
                                    >
                                        Next ›
                                    </button>
                                    <button
                                        type="button"
                                        className="button button-small"
                                        onClick={() => setCurrentPage(totalPages)}
                                        disabled={currentPage === totalPages}
                                    >
                                        »
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {selectedVolunteer && (
                    <div ref={inspectorRef} className="ems-signups-inspector ems-p-16">
                        <div className="ems-flex-between ems-mb-12">
                            <h3 className="ems-m-0 ems-font-semibold">Volunteer Details</h3>
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
                                <div key={ev.ID} className="ems-volunteer-avail-item">
                                    <p className="ems-m-0 ems-mb-4 ems-meta-text">
                                        <strong>{ev.post_title}</strong> ({ev.ems_event_code})
                                    </p>
                                    <p className="ems-m-0 ems-mb-6 ems-table-cell--meta">
                                        Commitment: <strong>{signupType === 'whole' ? 'Whole Event' : 'Partial'}</strong>
                                    </p>
                                    {signupType === 'part' && (
                                        <div className="ems-volunteer-avail-shifts">
                                            <strong className="ems-mb-2 ems-display-block">Requested Shifts:</strong>
                                            {eventShifts.map((s, idx) => (
                                                <div key={idx} className="ems-volunteer-avail-shift">
                                                    📅 {s.date} {s.overnight ? '(Overnight)' : '(Day)'}
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                    <div className="ems-flex-center ems-gap-6">
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
