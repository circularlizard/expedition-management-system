import '../../css/ems-admin.css';
import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';

interface Teammate {
    first_name: string;
    last_initial: string;
    patrol: string;
}

interface EventData {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    location: string;
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
                <h2>Participant Portal</h2>
                <p>Welcome, <strong>{me?.display_name}</strong></p>

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
                                    {p.first_name} {p.last_name} ({p.patrol || 'No Patrol'})
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
                                                onClick={() => setActiveEventId(ev.id)}
                                                className="button button-large"
                                                style={{ textAlign: 'left', padding: '15px' }}
                                            >
                                                <strong>{ev.name}</strong> ({ev.start_date} to {ev.end_date})
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Event Details render */}
                            {activeEventId && (() => {
                                const ev = explorerDetail.events[activeTab]?.find(e => e.id === activeEventId);
                                if (!ev) return null;

                                return (
                                    <div className="event-details-card" style={{ background: '#fff', border: '1px solid #ddd', borderRadius: '8px', padding: '20px' }}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '15px' }}>
                                            <h4>{ev.name}</h4>
                                            {explorerDetail.events[activeTab].length > 1 && (
                                                <button onClick={() => setActiveEventId(null)} className="button">
                                                    Back to list
                                                </button>
                                            )}
                                        </div>

                                        {/* Sub-tabs for Practice and Qualifying, Training displays basic details directly */}
                                        {activeTab === 'training' ? (
                                            <div className="training-event-details">
                                                <p><strong>Dates:</strong> {ev.start_date} to {ev.end_date}</p>
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
                                                    {(['overview', 'team', 'training', 'resources'] as const).map(subTab => (
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
                                                            <p><strong>Dates:</strong> {ev.start_date} to {ev.end_date}</p>
                                                            <p><strong>Start Location:</strong> {ev.location}</p>
                                                            {ev.leader_in_charge?.name && (
                                                                <div style={{ marginTop: '15px', padding: '10px', background: '#f9f9f9', borderRadius: '4px' }}>
                                                                    <strong>Leader in Charge:</strong> {ev.leader_in_charge.name}<br/>
                                                                    Email: <a href={`mailto:${ev.leader_in_charge.email}`}>{ev.leader_in_charge.email}</a><br/>
                                                                    Phone: {ev.leader_in_charge.phone}
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}

                                                    {activeSubTab === 'team' && (
                                                        <div>
                                                            {explorerDetail.team ? (
                                                                <div>
                                                                    <p><strong>Team Code:</strong> {explorerDetail.team.team_code}</p>
                                                                    <p><strong>Route Status:</strong> <span className={`status-badge status-${explorerDetail.team.route_status}`}>{explorerDetail.team.route_status}</span></p>

                                                                    {explorerDetail.team.whatsapp_link && (
                                                                        <div style={{ margin: '15px 0' }}>
                                                                            <a href={explorerDetail.team.whatsapp_link} target="_blank" rel="noopener noreferrer" className="button button-primary">
                                                                                Join WhatsApp Group
                                                                            </a>
                                                                        </div>
                                                                    )}

                                                                    <h5>Teammates</h5>
                                                                    <ul>
                                                                        {explorerDetail.team.teammates.map((tm, idx) => (
                                                                            <li key={idx}>
                                                                                {tm.first_name} {tm.last_initial} ({tm.patrol || 'No Patrol'})
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
                                                                                {tc.completed ? '✅' : '❌'} {tc.course_name}
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

                                                    {activeSubTab === 'resources' && (
                                                        <div>
                                                            <h5>Resources & Attachments</h5>
                                                            <p style={{ color: '#666', fontStyle: 'italic' }}>Risk Assessments, Planning Docs, and Route Card submission tools will be available here soon.</p>
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
