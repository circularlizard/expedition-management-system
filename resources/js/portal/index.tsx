import '../../css/ems-admin.css';
import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { OSMReadOnlyMap } from '../admin/expedition-board/OSMReadOnlyMap';

interface Teammate {
    first_name: string;
    last_initial: string;
    patrol: string;
}

interface EventData {
    id: number;
    name: string;
    start_date: string;
    start_time?: string;
    end_date: string;
    end_time?: string;
    location: string;
    end_location?: string;
    required_first_aid_level: string;
    route_deadline?: string;
    route_info?: string;
    whatsapp_explorers?: string | null;
    whatsapp_parents?: string | null;
    level: string;
    type: string;
    event_code?: string;
    osm_event_url: string | null;
    leader_in_charge: {
        name: string;
        email: string;
        phone: string;
    };
}

interface Signup {
    id: number;
    dofe_level: string;
    signup_status: string;
    payment_status?: string;
    created_at: string;
    type: 'participant' | 'expedition';
}

interface TrainingCourse {
    course_name: string;
    completed: boolean;
    completion_date: string | null;
    course_url: string;
}

interface ExplorerDetail {
    explorer: {
        scout_id: number;
        first_name: string;
        last_name: string;
        first_aid_level: string;
    };
    signups: Signup[];
    events: {
        training: EventData[];
        practice: EventData[];
        qualifying: EventData[];
    };
    training_checklist: TrainingCourse[];
    team: {
        team_code: string;
        route_status: string;
        whatsapp_link: string | null;
        teammates: Teammate[];
    } | null;
}

const ResolvedLocation: React.FC<{ value?: string }> = ({ value }) => {
    const [resolvedName, setResolvedName] = useState('');
    const [loading, setLoading] = useState(false);
    const coordsMatch = value ? value.trim().match(/^(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)$/) : null;

    useEffect(() => {
        if (!coordsMatch) {
            setResolvedName('');
            return;
        }
        const lat = parseFloat(coordsMatch[1]);
        const lng = parseFloat(coordsMatch[2]);
        setLoading(true);
        fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`, {
            headers: { 'Accept-Language': 'en' }
        })
            .then(res => {
                if (res.ok) return res.json();
                throw new Error();
            })
            .then(data => {
                const addr = data.address;
                const name = addr.road || addr.suburb || addr.town || addr.city || addr.county || '';
                const county = addr.county || addr.state || '';
                setResolvedName(name ? (county ? `${name}, ${county}` : name) : '');
            })
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [value]);

    if (!value) return <span>Not Specified</span>;

    if (!coordsMatch) {
        return <span>{value}</span>;
    }

    const lat = coordsMatch[1];
    const lng = coordsMatch[2];
    const mapUrl = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=15/${lat}/${lng}`;

    return (
        <span>
            <a href={mapUrl} target="_blank" rel="noopener noreferrer" className="ems-detail-link" style={{ marginRight: '8px' }}>
                {lat}, {lng} ↗
            </a>
            {loading && <span style={{ fontSize: '12px', color: '#666', fontStyle: 'italic' }}>Resolving address…</span>}
            {!loading && resolvedName && (
                <span style={{ fontSize: '13px', color: '#444' }}>
                    ({resolvedName})
                </span>
            )}
        </span>
    );
};

const IconUser = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: '6px', verticalAlign: 'middle' }}>
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
        <circle cx="12" cy="7" r="4" />
    </svg>
);

const IconWarning = ({ color = '#0073aa' }: { color?: string }) => (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: '6px', verticalAlign: 'middle' }}>
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
        <line x1="12" y1="9" x2="12" y2="13" />
        <line x1="12" y1="17" x2="12.01" y2="17" />
    </svg>
);

const IconCheck = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2e7d32" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: '6px', verticalAlign: 'middle' }}>
        <polyline points="20 6 9 17 4 12" />
    </svg>
);

const IconCross = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d32f2f" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: '6px', verticalAlign: 'middle' }}>
        <line x1="18" y1="6" x2="6" y2="18" />
        <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
);

const IconMapPin = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: '4px', verticalAlign: 'middle' }}>
        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
        <circle cx="12" cy="10" r="3" />
    </svg>
);

const IconWhatsApp = () => (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style={{ marginRight: '6px', verticalAlign: 'middle' }}>
        <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946C.06 5.348 5.397.01 12.008.01c3.202.001 6.212 1.246 8.477 3.514 2.266 2.268 3.507 5.28 3.505 8.484-.004 6.657-5.34 11.997-11.953 11.997-2.005-.001-3.973-.5-5.739-1.453L0 24zm6.59-4.846c1.6.95 3.188 1.449 4.825 1.451 5.436 0 9.86-4.37 9.864-9.799.002-2.63-1.023-5.101-2.885-6.963C16.588 2.025 14.12 1 11.49 1 6.059 1 1.633 5.37 1.63 10.8c-.001 1.73.456 3.418 1.32 4.925L1.91 20.353l4.737-1.199z" />
    </svg>
);

interface Profile {
    scout_id: number;
    first_name: string;
    last_name: string;
    patrol: string;
}

export function PortalApp() {
    const config = (window as any).emsPortal || {
        root_url: '/wp-json/ems/v1',
        nonce: '',
        user_data: { logged_in: false, first_name: '', last_name: '', email: '', access_type: '' },
        login_url: '#'
    };

    const [me, setMe] = useState<{
        logged_in: boolean;
        access_type?: string;
        display_name?: string;
        profiles?: Profile[];
    } | null>(null);

    const [activeScoutId, setActiveScoutId] = useState<number | null>(null);
    const [explorerDetail, setExplorerDetail] = useState<ExplorerDetail | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [activeTab, setActiveTab] = useState<'training' | 'practice' | 'qualifying'>('training');
    const [activeEventId, setActiveEventId] = useState<number | null>(null);
    const [activeSubTab, setActiveSubTab] = useState<'overview' | 'team' | 'training' | 'resources'>('overview');
    const [currentPage, setCurrentPage] = useState<'signups' | 'expeditions'>('signups');

    const activeScout = activeScoutId || (me?.profiles && me.profiles.length > 0 ? me.profiles[0].scout_id : null);

    useEffect(() => {
        if (!config.user_data?.logged_in) {
            setLoading(false);
            return;
        }
        setLoading(true);
        fetch(`${config.root_url}/portal/me`, {
            headers: { 'X-WP-Nonce': config.nonce }
        })
            .then(res => res.ok ? res.json() : Promise.reject())
            .then(data => {
                setMe(data);
                if (data.profiles && data.profiles.length > 0) {
                    setActiveScoutId(data.profiles[0].scout_id);
                }
                setLoading(false);
            })
            .catch(() => {
                setError('Failed to load session details.');
                setLoading(false);
            });
    }, []);

    useEffect(() => {
        if (!activeScout) {
            setExplorerDetail(null);
            return;
        }

        setLoading(true);
        setError(null);
        setActiveEventId(null);

        fetch(`${config.root_url}/portal/explorer/${activeScout}`, {
            headers: { 'X-WP-Nonce': config.nonce }
        })
            .then(res => {
                if (res.status === 403) {
                    return Promise.reject('You do not have permission to view this explorer profile.');
                }
                if (res.status === 404) {
                    return Promise.reject('Explorer record not found.');
                }
                return res.ok ? res.json() : Promise.reject('Failed to load explorer details.');
            })
            .then(data => {
                setExplorerDetail(data);
                setLoading(false);
            })
            .catch(err => {
                setError(typeof err === 'string' ? err : 'An error occurred while loading.');
                setLoading(false);
            });
    }, [activeScout]);

    // Handle initial selection for tab event view
    useEffect(() => {
        if (explorerDetail) {
            const list = explorerDetail.events[activeTab] || [];
            if (list.length === 1) {
                setActiveEventId(list[0].id);
            } else {
                setActiveEventId(null);
            }
        }
    }, [activeTab, explorerDetail]);

    if (loading && !me) {
        return <div className="ems-portal-container"><p>Loading portal...</p></div>;
    }

    if (!config.user_data?.logged_in || (me && !me.logged_in)) {
        return (
            <div className="ems-portal-container portal-login-prompt" style={{ padding: '40px', textAlign: 'center', background: '#f9f9f9', borderRadius: '8px', border: '1px solid #ddd' }}>
                <h2>Expedition Management System</h2>
                <p>Welcome to the expedition portal. Please log in using your Online Scout Manager account to access training, team information, and expedition details.</p>
                <a href={config.login_url} className="button button-primary button-large" style={{ marginTop: '20px', display: 'inline-block' }}>
                    Log In via Online Scout Manager
                </a>
            </div>
        );
    }

    if (me && me.access_type !== 'parent' && me.access_type !== 'member' && !me.profiles?.length) {
        return (
            <div className="ems-portal-container" style={{ padding: '20px', background: '#fff9e6', borderLeft: '4px solid #ffcc00' }}>
                <h3>Administrator / Leader View</h3>
                <p>Logged in as: <strong>{me.display_name}</strong> ({me.access_type})</p>
                <p>You do not currently have a linked explorer or parent role. To manage expeditions, please visit the internal admin dashboard.</p>
            </div>
        );
    }

    const currentTimelineStep = () => {
        if (!explorerDetail) return 0;
        if (explorerDetail.team) {
            if (explorerDetail.team.route_status === 'approved') return 4;
            if (explorerDetail.team.team_code !== 'UNALLOCATED') return 3;
            return 2;
        }
        if (explorerDetail.signups && explorerDetail.signups.length > 0) return 1;
        return 0;
    };

    const getTimelineClass = (step: number) => {
        const current = currentTimelineStep();
        if (current >= step) return 'step-complete';
        return 'step-pending';
    };

    return (
        <div className="ems-portal-container" style={{ maxWidth: '1000px', margin: '0 auto', fontFamily: 'inherit' }}>
            <div className="portal-header" style={{ marginBottom: '30px', borderBottom: '1px solid #eee', paddingBottom: '15px' }}>
                {me?.access_type === 'parent' && me.profiles && me.profiles.length > 1 && (
                    <div className="child-selector" style={{ marginTop: '15px', marginBottom: '15px', display: 'flex', alignItems: 'center', gap: '10px' }}>
                        <label htmlFor="ems-child-select" style={{ fontWeight: 'bold' }}>Showing details for:</label>
                        <select
                            id="ems-child-select"
                            value={activeScout || ''}
                            onChange={(e) => setActiveScoutId(Number(e.target.value))}
                            style={{ padding: '6px 12px', borderRadius: '4px', border: '1px solid #ccc', background: '#fff', fontSize: '14px' }}
                        >
                            {me.profiles.map(p => (
                                <option key={p.scout_id} value={p.scout_id}>
                                    {p.first_name} {p.last_name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                {/* Top-Level Portal Page Navigation */}
                <div className="portal-nav-tabs" style={{ display: 'flex', gap: '5px', marginTop: '20px', borderBottom: '1px solid #ccc' }}>
                    <button
                        onClick={() => setCurrentPage('signups')}
                        className={`portal-tab ${currentPage === 'signups' ? 'active' : ''}`}
                        style={{
                            padding: '10px 20px',
                            background: 'none',
                            border: 'none',
                            borderBottom: currentPage === 'signups' ? '3px solid #0073aa' : '3px solid transparent',
                            color: currentPage === 'signups' ? '#0073aa' : '#555',
                            fontWeight: 'bold',
                            cursor: 'pointer',
                            fontSize: '15px',
                            transition: 'all 0.2s ease'
                        }}
                    >
                        Applications / Sign-ups
                    </button>
                    <button
                        onClick={() => setCurrentPage('expeditions')}
                        className={`portal-tab ${currentPage === 'expeditions' ? 'active' : ''}`}
                        style={{
                            padding: '10px 20px',
                            background: 'none',
                            border: 'none',
                            borderBottom: currentPage === 'expeditions' ? '3px solid #0073aa' : '3px solid transparent',
                            color: currentPage === 'expeditions' ? '#0073aa' : '#555',
                            fontWeight: 'bold',
                            cursor: 'pointer',
                            fontSize: '15px',
                            transition: 'all 0.2s ease'
                        }}
                    >
                        Expeditions & Events
                    </button>
                </div>
            </div>

            {error && (
                <div className="portal-error" style={{ background: '#fbebeb', color: '#cc0000', padding: '15px', borderRadius: '4px', marginBottom: '20px' }}>
                    {error}
                </div>
            )}

            {loading && <p>Loading details...</p>}

            {!loading && explorerDetail && (
                <div className="portal-content">
                    {/* Page 1: Sign-up Applications */}
                    {currentPage === 'signups' && (
                        <div className="portal-section" style={{ marginBottom: '35px' }}>
                            <h3>Sign-ups for {explorerDetail.explorer.first_name} {explorerDetail.explorer.last_name}</h3>
                            {explorerDetail.signups.length === 0 ? (
                                <p>No active sign-ups found.</p>
                            ) : (
                                <table className="wp-list-table widefat fixed striped" style={{ width: '100%' }}>
                                    <thead>
                                        <tr>
                                            <th>Level</th>
                                            <th>Application Type</th>
                                            <th>Status</th>
                                            <th>Payment</th>
                                            <th>Submitted</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {explorerDetail.signups.map(s => (
                                            <tr key={s.id}>
                                                <td style={{ textTransform: 'capitalize' }}><strong>{s.dofe_level}</strong></td>
                                                <td style={{ textTransform: 'capitalize' }}>{s.type}</td>
                                                <td><span className={`status-badge status-${s.signup_status}`} style={{ textTransform: 'capitalize' }}>{s.signup_status}</span></td>
                                                <td>{s.payment_status ? <span className={`payment-badge payment-${s.payment_status}`} style={{ textTransform: 'capitalize' }}>{s.payment_status}</span> : '—'}</td>
                                                <td>{new Date(s.created_at).toLocaleDateString()}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    )}

                    {/* Page 2: Expeditions & Events */}
                    {currentPage === 'expeditions' && (
                        <div className="portal-section">
                            <h3>Expeditions & Events for {explorerDetail.explorer.first_name} {explorerDetail.explorer.last_name}</h3>
                            <div className="category-tabs" style={{ display: 'flex', borderBottom: '2px solid #ccc', marginBottom: '20px' }}>
                            {(['training', 'practice', 'qualifying'] as const).map(tab => {
                                const count = explorerDetail.events[tab]?.length || 0;
                                const isGreyed = count === 0;
                                return (
                                    <button
                                        key={tab}
                                        onClick={() => !isGreyed && setActiveTab(tab)}
                                        className={`tab-link ${activeTab === tab ? 'active' : ''} ${isGreyed ? 'greyed-out' : ''}`}
                                        style={{
                                            padding: '10px 20px',
                                            border: 'none',
                                            background: activeTab === tab ? '#0073aa' : 'transparent',
                                            color: activeTab === tab ? '#white' : (isGreyed ? '#bbb' : '#333'),
                                            cursor: isGreyed ? 'not-allowed' : 'pointer',
                                            fontWeight: 'bold',
                                            borderBottom: activeTab === tab ? '3px solid #005177' : 'none'
                                        }}
                                        disabled={isGreyed}
                                    >
                                        {tab.toUpperCase()} ({count})
                                    </button>
                                );
                            })}
                        </div>

                        {/* Category Tab Content */}
                        <div className="tab-content">
                            {/* If multiple events, render a list selector */}
                            {explorerDetail.events[activeTab]?.length > 1 && !activeEventId && (
                                <div className="event-selector-list">
                                    <p>Please select an event below to view its details:</p>
                                    <div className="list-group" style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                                        {explorerDetail.events[activeTab].map(ev => (
                                            <button
                                                key={ev.id}
                                                onClick={() => {
                                                    setActiveEventId(ev.id);
                                                    setActiveSubTab('overview');
                                                }}
                                                className="button button-secondary"
                                                style={{ textAlign: 'left', padding: '15px' }}
                                            >
                                                <strong>{ev.name}</strong> ({ev.start_date} {ev.start_time && `@ ${ev.start_time}`} to {ev.end_date} {ev.end_time && `@ ${ev.end_time}`})
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {activeEventId && (() => {
                                const ev = explorerDetail.events[activeTab]?.find(e => e.id === activeEventId);
                                if (!ev) return null;

                                return (
                                    <div className="event-details-card" style={{ background: '#fff', border: '1px solid #ddd', borderRadius: '8px', padding: '20px' }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '15px', borderBottom: '1px solid #eaeaea', paddingBottom: '10px' }}>
                                            <div>
                                                <h4 style={{ margin: 0 }}>{ev.name} {ev.event_code && `(${ev.event_code})`}</h4>
                                                <p style={{ margin: '5px 0 0 0', color: '#666', fontSize: '14px' }}>
                                                    <strong>Dates & Times:</strong> {ev.start_date} {ev.start_time && `@ ${ev.start_time}`} to {ev.end_date} {ev.end_time && `@ ${ev.end_time}`}
                                                </p>
                                            </div>
                                            {explorerDetail.events[activeTab].length > 1 && (
                                                <button onClick={() => setActiveEventId(null)} className="button">
                                                    Back to list
                                                </button>
                                            )}
                                        </div>

                                        {/* Sub-tabs for Practice and Qualifying, Training displays basic details directly */}
                                        {activeTab === 'training' ? (
                                            <div className="training-event-details">
                                                <p><strong>Location:</strong> {ev.location}</p>
                                                {ev.osm_event_url && (
                                                    <p>
                                                        <a href={ev.osm_event_url} target="_blank" rel="noopener noreferrer" className="button button-secondary">
                                                            View Online Scout Manager Event
                                                        </a>
                                                    </p>
                                                )}
                                                {ev.leader_in_charge?.name && (
                                                    <div style={{ marginTop: '15px', padding: '10px', background: '#f9f9f9', borderRadius: '4px' }}>
                                                        <strong>Leader in Charge:</strong> {ev.leader_in_charge.name}<br/>
                                                        Email: <a href={`mailto:${ev.leader_in_charge.email}`}>{ev.leader_in_charge.email}</a><br/>
                                                        Phone: {ev.leader_in_charge.phone}
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="expedition-event-details">
                                                <div className="sub-tabs" style={{ display: 'flex', gap: '5px', borderBottom: '1px solid #eee', marginBottom: '15px' }}>
                                                    {(['overview', 'team', 'training', 'route'] as const).map(subTab => (
                                                        <button
                                                            key={subTab}
                                                            onClick={() => setActiveSubTab(subTab)}
                                                            className={`sub-tab-link ${activeSubTab === subTab ? 'active' : ''}`}
                                                            style={{
                                                                padding: '8px 15px',
                                                                border: 'none',
                                                                background: activeSubTab === subTab ? '#eaeaea' : 'transparent',
                                                                cursor: 'pointer',
                                                                fontWeight: activeSubTab === subTab ? 'bold' : 'normal'
                                                            }}
                                                        >
                                                            {subTab.toUpperCase()}
                                                        </button>
                                                    ))}
                                                </div>

                                                <div className="sub-tab-content">
                                                    {activeSubTab === 'overview' && (
                                                        <div>
                                                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '15px', marginBottom: '20px' }}>
                                                                <div>
                                                                    <p><strong>Level:</strong> <span style={{ textTransform: 'capitalize' }}>{ev.level}</span></p>
                                                                    <p><strong>Type:</strong> <span style={{ textTransform: 'capitalize' }}>{ev.type}</span></p>
                                                                    <p><strong>Start Date & Time:</strong> {ev.start_date} {ev.start_time && `@ ${ev.start_time}`}</p>
                                                                    <p><strong>End Date & Time:</strong> {ev.end_date} {ev.end_time && `@ ${ev.end_time}`}</p>
                                                                </div>
                                                                <div>
                                                                    {ev.leader_in_charge?.name && (
                                                                        <div style={{ padding: '10px', background: '#f9f9f9', borderRadius: '4px' }}>
                                                                            <strong>Leader in Charge:</strong> {ev.leader_in_charge.name}<br/>
                                                                            Email: <a href={`mailto:${ev.leader_in_charge.email}`}>{ev.leader_in_charge.email}</a><br/>
                                                                            Phone: {ev.leader_in_charge.phone}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>

                                                            <div style={{ borderTop: '1px solid #eee', paddingTop: '15px', marginTop: '15px' }}>
                                                                <h5>WhatsApp Groups & QR Codes</h5>
                                                                <div style={{ display: 'flex', gap: '30px', flexWrap: 'wrap' }}>
                                                                    {ev.whatsapp_explorers && (
                                                                        <div style={{ flex: 1, minWidth: '200px', textAlign: 'center', padding: '10px', border: '1px solid #eee', borderRadius: '6px' }}>
                                                                            <h6 style={{ margin: '0 0 10px 0' }}>Explorers Group</h6>
                                                                            <p style={{ color: '#0073aa', fontSize: '12px', fontWeight: 'bold', margin: '5px 0 10px 0' }}>
                                                                                <IconWarning />Note: Explorers groups are for explorers only.
                                                                            </p>
                                                                            <a href={ev.whatsapp_explorers} target="_blank" rel="noopener noreferrer" className="button button-primary" style={{ marginBottom: '10px', display: 'inline-block' }}>
                                                                                <IconWhatsApp />Join Explorers Chat
                                                                            </a>
                                                                            <div style={{ marginTop: '10px' }}>
                                                                                <img src={`https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(ev.whatsapp_explorers)}`} alt="Explorer WhatsApp QR" style={{ width: '120px', height: '120px' }} />
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                    {ev.whatsapp_parents && (
                                                                        <div style={{ flex: 1, minWidth: '200px', textAlign: 'center', padding: '10px', border: '1px solid #eee', borderRadius: '6px' }}>
                                                                            <h6 style={{ margin: '0 0 10px 0' }}>Parents Group</h6>
                                                                            <a href={ev.whatsapp_parents} target="_blank" rel="noopener noreferrer" className="button button-primary" style={{ marginBottom: '10px', display: 'inline-block' }}>
                                                                                <IconWhatsApp />Join Parents Chat
                                                                            </a>
                                                                            <div style={{ marginTop: '10px' }}>
                                                                                <img src={`https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(ev.whatsapp_parents)}`} alt="Parent WhatsApp QR" style={{ width: '120px', height: '120px' }} />
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                    {!ev.whatsapp_explorers && !ev.whatsapp_parents && (
                                                                        <p style={{ color: '#666', fontStyle: 'italic' }}>No WhatsApp group links configured for this event yet.</p>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    )}

                                                    {activeSubTab === 'team' && (
                                                        <div>
                                                            {explorerDetail.team ? (
                                                                <div>
                                                                    <p><strong>Team Code:</strong> {explorerDetail.team.team_code}</p>

                                                                    <div style={{ margin: '15px 0', padding: '15px', background: '#f9f9f9', borderRadius: '6px', borderLeft: '4px solid #0073aa' }}>
                                                                        <p style={{ margin: '0 0 8px 0' }}><strong>Required First Aid Level:</strong> <span style={{ textTransform: 'capitalize' }}>{ev.required_first_aid_level.replace(/_/g, ' ')}</span></p>
                                                                        <p style={{ margin: '0 0 8px 0' }}><strong>Your First Aid Status:</strong> <span style={{ textTransform: 'capitalize' }}>{explorerDetail.explorer.first_aid_level.replace(/_/g, ' ')}</span></p>
                                                                        {ev.required_first_aid_level !== 'none' && (
                                                                            <p style={{ margin: '10px 0 0 0', fontSize: '13px', color: '#0073aa', fontWeight: 'bold' }}>
                                                                                <IconWarning />Note: At least 2 people in the team need the required level of first aid.
                                                                            </p>
                                                                        )}
                                                                    </div>

                                                                    <h5>Teammates</h5>
                                                                    <ul style={{ listStyle: 'none', paddingLeft: 0 }}>
                                                                        {explorerDetail.team.teammates.map((tm, idx) => (
                                                                            <li key={idx} style={{ padding: '8px 10px', borderBottom: '1px solid #eee', background: '#fff' }}>
                                                                                <IconUser /> {tm.first_name} {tm.last_initial} ({tm.patrol || 'No Patrol'})
                                                                            </li>
                                                                        ))}
                                                                    </ul>
                                                                </div>
                                                            ) : (
                                                                <p>You have not been allocated to a specific team yet.</p>
                                                            )}
                                                        </div>
                                                    )}

                                                    {activeSubTab === 'training' && (
                                                        <div>
                                                            <h5>Training Course Requirements</h5>
                                                            {explorerDetail.training_checklist.length === 0 ? (
                                                                <p>No training course requirements configured for this event.</p>
                                                            ) : (
                                                                <ul style={{ listStyle: 'none', paddingLeft: 0 }}>
                                                                    {explorerDetail.training_checklist.map((tc, idx) => (
                                                                        <li key={idx} style={{ padding: '8px 0', borderBottom: '1px solid #f0f0f0', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                                                                            <span>
                                                                                {tc.completed ? <IconCheck /> : <IconCross />} {tc.course_name}
                                                                            </span>
                                                                            <a href={tc.course_url} target="_blank" rel="noopener noreferrer" className="button button-small">
                                                                                Go to Course
                                                                            </a>
                                                                        </li>
                                                                    ))}
                                                                </ul>
                                                            )}
                                                        </div>
                                                    )}

                                                    {activeSubTab === 'route' && (
                                                        <div>
                                                            <h5>Route details</h5>
                                                            <p><strong><IconMapPin />Start Point:</strong> <ResolvedLocation value={ev.location} /></p>
                                                            <p><strong><IconMapPin />End Point:</strong> <ResolvedLocation value={ev.end_location} /></p>
                                                            <p><strong>Route Status:</strong> <span className={`status-badge status-${explorerDetail.team?.route_status || 'pending'}`} style={{ textTransform: 'capitalize' }}>{explorerDetail.team?.route_status || 'pending'}</span></p>
                                                            <p><strong>Route Deadline:</strong> {ev.route_deadline || 'No Deadline Set'}</p>

                                                            <div style={{ marginTop: '20px', marginBottom: '20px' }}>
                                                                <OSMReadOnlyMap startLocation={ev.location} endLocation={ev.end_location} />
                                                            </div>

                                                            {(ev.location || ev.end_location) && (() => {
                                                                const parseCoords = (val?: string): [number, number] | null => {
                                                                    if (!val) return null;
                                                                    const match = val.trim().match(/^(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)$/);
                                                                    return match ? [parseFloat(match[1]), parseFloat(match[2])] : null;
                                                                };
                                                                const start = parseCoords(ev.location);
                                                                const end = parseCoords(ev.end_location);

                                                                let url = '';
                                                                let label = 'Open in OpenStreetMaps ↗';

                                                                if (start && end) {
                                                                    const geojson = {
                                                                        type: 'FeatureCollection',
                                                                        features: [
                                                                            {
                                                                                type: 'Feature',
                                                                                geometry: {
                                                                                    type: 'LineString',
                                                                                    coordinates: [
                                                                                        [start[1], start[0]],
                                                                                        [end[1], end[0]]
                                                                                    ]
                                                                                },
                                                                                properties: {
                                                                                    name: 'Start to End Straight Line'
                                                                                }
                                                                            }
                                                                        ]
                                                                    };
                                                                    url = `https://geojson.io/#data=data:application/json,${encodeURIComponent(JSON.stringify(geojson))}`;
                                                                    label = 'Open Route Map (Straight Line) ↗';
                                                                } else {
                                                                    url = `https://www.openstreetmap.org/search?query=${encodeURIComponent(ev.location || ev.end_location || '')}`;
                                                                }

                                                                return (
                                                                    <div style={{ textAlign: 'right', marginTop: '-15px', marginBottom: '15px' }}>
                                                                        <a
                                                                            href={url}
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            style={{ fontSize: '12px', color: '#0073aa', textDecoration: 'underline', fontWeight: 'bold' }}
                                                                        >
                                                                            {label}
                                                                        </a>
                                                                    </div>
                                                                );
                                                            })()}

                                                            {ev.route_info && (
                                                                <div style={{ marginTop: '15px', marginBottom: '15px', padding: '15px', background: '#f9f9f9', borderRadius: '6px', borderLeft: '4px solid #0073aa' }}>
                                                                    <strong>Route Planning Information:</strong>
                                                                    <div style={{ margin: '10px 0 0 0' }} dangerouslySetInnerHTML={{ __html: ev.route_info }} />
                                                                </div>
                                                            )}

                                                            <div style={{ marginTop: '20px', padding: '15px', border: '1px dashed #0073aa', borderRadius: '6px', background: '#fcfdff', textAlign: 'center' }}>
                                                                <p style={{ margin: 0, fontWeight: 'bold', color: '#0073aa' }}>📁 Route Card & GPX Upload Area</p>
                                                                <p style={{ margin: '5px 0 0 0', fontSize: '13px', color: '#666' }}>Route card PDF/GPX submission tools will be wired here (Milestone 9).</p>
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                );
                            })()}
                        </div>
                    </div>
                    )}
                </div>
            )}
        </div>
    );
}

document.addEventListener('DOMContentLoaded', () => {
    const rootEl = document.getElementById('ems-portal-root');
    if (rootEl) {
        createRoot(rootEl).render(<PortalApp />);
    }
});
