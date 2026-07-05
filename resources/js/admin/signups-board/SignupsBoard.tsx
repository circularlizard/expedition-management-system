import React, { useState, useEffect } from 'react';

interface SignupsConfig {
    root_url: string;
    nonce: string;
}

declare global {
    interface Window {
        emsSignupsBoard: SignupsConfig;
    }
}

const isSectionCompleted = (completions: any, section: string): boolean => {
    if (!completions) return false;
    if (Array.isArray(completions)) {
        return completions.some((s: string) => s.toLowerCase() === section.toLowerCase());
    }
    if (typeof completions === 'object') {
        return completions[section] === 'completed';
    }
    return false;
};

interface SignupsBoardProps {
    type: 'participant' | 'expedition';
}

export default function SignupsBoard({ type }: SignupsBoardProps) {
    const config = window.emsSignupsBoard || { root_url: '', nonce: '' };

    const [signups, setSignups] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Filters
    const defaultStatus = type === 'participant' ? 'received' : 'pending';
    const [statusFilter, setStatusFilter] = useState<string>(defaultStatus);
    const [levelFilter, setLevelFilter] = useState<string>('all');
    const [expedTypeFilter, setExpedTypeFilter] = useState<string>('all');

    // Sorting
    const [sortKey, setSortKey] = useState<string>('created_at');
    const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');

    // Selected signup for Inspector Panel
    const [selectedSignup, setSelectedSignup] = useState<any | null>(null);
    const [editedDofeNumber, setEditedDofeNumber] = useState<string>('');

    const fetchSignups = async () => {
        setLoading(true);
        setError(null);
        try {
            const endpoint = type === 'participant' ? 'participants' : 'expeditions';
            const response = await fetch(`${config.root_url}/signups/${endpoint}?status=${statusFilter}`, {
                headers: {
                    'X-WP-Nonce': config.nonce
                }
            });
            if (response && response.ok) {
                const data = await response.json();
                setSignups(data);
            } else {
                throw new Error('Failed to fetch signups');
            }
        } catch (err: any) {
            setError(err.message || 'Error fetching signups');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const statusParam = params.get('status');
        if (statusParam) {
            setStatusFilter(statusParam);
        } else {
            setStatusFilter(type === 'participant' ? 'received' : 'pending');
        }
        setLevelFilter('all');
        setExpedTypeFilter('all');
        setSortKey('created_at');
        setSortOrder('desc');
        setSelectedSignup(null);
    }, [type]);

    useEffect(() => {
        fetchSignups();
        setSelectedSignup(null);
    }, [type, statusFilter]);

    useEffect(() => {
        if (selectedSignup) {
            setEditedDofeNumber(selectedSignup.dofe_number || '');
        }
    }, [selectedSignup]);

    useEffect(() => {
        if (!loading && signups && signups.length > 0) {
            const params = new URLSearchParams(window.location.search);
            const idParam = params.get('id');
            if (idParam) {
                const targetId = parseInt(idParam, 10);
                const found = signups.find(s => s.id === targetId);
                if (found) {
                    setSelectedSignup(found);
                }
            }
        }
    }, [loading, signups]);

    const handleProcessParticipant = async (signupId: number, dofeNumber: string) => {
        try {
            const response = await fetch(`${config.root_url}/signups/participants/${signupId}/process`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({ dofe_number: dofeNumber })
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Failed to allocate place');
            }
            setSelectedSignup(null);
            fetchSignups();
        } catch (err: any) {
            alert(err.message);
        }
    };

    const handleArchive = async (signupId: number) => {
        if (!confirm('Are you sure you want to archive this signup?')) return;
        try {
            const endpoint = type === 'participant' ? 'participants' : 'expeditions';
            const response = await fetch(`${config.root_url}/signups/${endpoint}/${signupId}/archive`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': config.nonce
                }
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Failed to archive signup');
            }
            setSelectedSignup(null);
            fetchSignups();
        } catch (err: any) {
            alert(err.message);
        }
    };

    // Filter signups in memory by Level
    const filteredSignups = signups.filter(s => {
        if (levelFilter !== 'all' && s.dofe_level !== levelFilter) {
            return false;
        }
        if (type === 'expedition' && expedTypeFilter !== 'all') {
            const expedType = s.expedition_preferences?.exped_type || '';
            if (expedType.toLowerCase() !== expedTypeFilter.toLowerCase()) {
                return false;
            }
        }
        return true;
    });

    const getSortValue = (item: any, key: string) => {
        if (key === 'explorer_first_name') {
            return `${item.explorer_first_name || ''} ${item.explorer_last_name || ''}`.toLowerCase();
        }
        if (key === 'expedition') {
            return (item.expedition_preferences?.exped_type || '').toLowerCase();
        }
        if (key === 'prior_completions') {
            if (item.dofe_level === 'bronze') return -1;
            const completions = item.dofe_level === 'silver' ? item.bronze_completion : item.silver_completion;
            if (!completions) return 0;
            let count = 0;
            if (isSectionCompleted(completions, 'volunteering')) count++;
            if (isSectionCompleted(completions, 'skills')) count++;
            if (isSectionCompleted(completions, 'physical')) count++;
            if (isSectionCompleted(completions, 'expedition')) count++;
            return count;
        }
        const val = item[key];
        if (typeof val === 'string') {
            return val.toLowerCase();
        }
        return val ?? '';
    };

    const sortedSignups = [...filteredSignups].sort((a, b) => {
        const aVal = getSortValue(a, sortKey);
        const bVal = getSortValue(b, sortKey);

        if (aVal < bVal) return sortOrder === 'asc' ? -1 : 1;
        if (aVal > bVal) return sortOrder === 'asc' ? 1 : -1;
        return 0;
    });

    const toggleSort = (key: string) => {
        if (sortKey === key) {
            setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
        } else {
            setSortKey(key);
            setSortOrder('asc');
        }
    };

    const renderHeader = (label: string, key: string) => {
        const isCurrent = sortKey === key;
        return (
            <th 
                onClick={() => toggleSort(key)} 
                className="ems-osm-ref-col-header"
            >
                <div className="ems-flex-center ems-gap-6">
                    {label}
                    <span className={`ems-osm-ref-col-sort ${isCurrent ? 'ems-osm-ref-col-sort--active' : 'ems-osm-ref-col-sort--inactive'}`}>
                        {isCurrent ? (sortOrder === 'asc' ? '▲' : '▼') : '⇅'}
                    </span>
                </div>
            </th>
        );
    };

    // Paging list of unprocessed signups inside the Inspector Panel
    const unprocessedSignups = sortedSignups.filter(s => s.signup_status === defaultStatus);
    const currentUnprocessedIndex = selectedSignup ? unprocessedSignups.findIndex(s => s.id === selectedSignup.id) : -1;

    const handlePrevSignup = () => {
        if (currentUnprocessedIndex > 0) {
            setSelectedSignup(unprocessedSignups[currentUnprocessedIndex - 1]);
        }
    };

    const handleNextSignup = () => {
        if (currentUnprocessedIndex >= 0 && currentUnprocessedIndex < unprocessedSignups.length - 1) {
            setSelectedSignup(unprocessedSignups[currentUnprocessedIndex + 1]);
        }
    };

    // Helper to render prior completion badges
    const renderPriorCompletions = (signup: any) => {
        if (signup.dofe_level === 'bronze') {
            return (
                <span title="No Prior Award" style={{ fontSize: '16px' }}>❌</span>
            );
        }

        const completions = signup.dofe_level === 'silver' ? signup.bronze_completion : signup.silver_completion;
        if (!completions) {
            return (
                <span title="No Prior Award" style={{ fontSize: '16px' }}>❌</span>
            );
        }

        const completedList: JSX.Element[] = [];
        if (isSectionCompleted(completions, 'volunteering')) {
            completedList.push(
                <span key="V" title="Volunteering Completed" className="ems-skill-badge ems-avatar-circle--red">
                    V
                </span>
            );
        }
        if (isSectionCompleted(completions, 'skills')) {
            completedList.push(
                <span key="S" title="Skills Completed" className="ems-skill-badge ems-avatar-circle--blue">
                    S
                </span>
            );
        }
        if (isSectionCompleted(completions, 'physical')) {
            completedList.push(
                <span key="P" title="Physical Completed" className="ems-skill-badge ems-avatar-circle--gold">
                    P
                </span>
            );
        }
        if (isSectionCompleted(completions, 'expedition')) {
            completedList.push(
                <span key="E" title="Expedition Completed" className="ems-skill-badge ems-avatar-circle--green">
                    E
                </span>
            );
        }

        if (completedList.length === 0) {
            return (
                <span title="No Prior Award" style={{ fontSize: '16px' }}>❌</span>
            );
        }

        return <div className="ems-signups-completed-list">{completedList}</div>;
    };

    if (loading && signups.length === 0) {
        return (
            <div className="ems-card ems-p-20">
                <p>Loading signups...</p>
            </div>
        );
    }

    return (
        <div className="ems-signups-container">
            {/* Main Content Area */}
            <div className="ems-signups-main">
                {error && (
                    <div className="ems-error-notice">
                        {error}
                    </div>
                )}

                {/* Filter and Control Bar */}
                <div className="ems-signups-toolbar">
                    {/* Status Tabs */}
                    <div className="ems-flex-center ems-gap-8">
                        {type === 'participant' ? (
                            <>
                                <label className={`ems-filter-pill ${statusFilter === 'received' ? 'ems-filter-pill--active' : ''}`}>
                                    <input type="radio" name="statusFilter" value="received" checked={statusFilter === 'received'} onChange={() => setStatusFilter('received')} />
                                    Received
                                </label>
                                <label className={`ems-filter-pill ${statusFilter === 'allocated' ? 'ems-filter-pill--active' : ''}`}>
                                    <input type="radio" name="statusFilter" value="allocated" checked={statusFilter === 'allocated'} onChange={() => setStatusFilter('allocated')} />
                                    Allocated
                                </label>
                            </>
                        ) : (
                            <>
                                <label className={`ems-filter-pill ${statusFilter === 'pending' ? 'ems-filter-pill--active' : ''}`}>
                                    <input type="radio" name="statusFilter" value="pending" checked={statusFilter === 'pending'} onChange={() => setStatusFilter('pending')} />
                                    Active (Pending)
                                </label>
                            </>
                        )}
                        <label className={`ems-filter-pill ${statusFilter === 'archived' ? 'ems-filter-pill--active' : ''}`}>
                            <input type="radio" name="statusFilter" value="archived" checked={statusFilter === 'archived'} onChange={() => setStatusFilter('archived')} />
                            Archived
                        </label>
                    </div>

                    {/* Level & Expedition Type Filters */}
                    <div className="ems-flex-center ems-gap-16">
                        <div className="ems-flex-center ems-gap-8">
                            <label htmlFor="level-filter" className="ems-toolbar__label">Filter Level:</label>
                            <select
                                id="level-filter"
                                aria-label="Filter Level"
                                value={levelFilter}
                                onChange={(e) => setLevelFilter(e.target.value)}
                                className="ems-select"
                            >
                                <option value="all">All Levels</option>
                                <option value="bronze">Bronze</option>
                                <option value="silver">Silver</option>
                                <option value="gold">Gold</option>
                            </select>
                        </div>

                        {type === 'expedition' && (
                            <div className="ems-flex-center ems-gap-8">
                                <label htmlFor="exped-type-filter" className="ems-toolbar__label">Expedition Type:</label>
                                <select
                                    id="exped-type-filter"
                                    aria-label="Filter Expedition Type"
                                    value={expedTypeFilter}
                                    onChange={(e) => setExpedTypeFilter(e.target.value)}
                                    className="ems-select"
                                >
                                    <option value="all">All Types</option>
                                    <option value="hillwalking">Hillwalking</option>
                                    <option value="paddling">Paddling</option>
                                    <option value="biking">Biking</option>
                                </select>
                            </div>
                        )}
                    </div>
                </div>

                {/* Table Grid */}
                <div className="ems-signups-table-wrap">
                    <table className="ems-table">
                        <thead>
                            <tr>
                                <th className="ems-table-cell--center ems-m-0"></th>
                                {renderHeader('Submission Date', 'created_at')}
                                {renderHeader('Explorer Name', 'explorer_first_name')}
                                {renderHeader('Level', 'dofe_level')}
                                {type === 'expedition' && renderHeader('Expedition', 'expedition')}
                                {renderHeader('ESU', 'unit_name')}
                                {renderHeader('Email', 'explorer_email')}
                                {type === 'participant' ? (
                                    <>
                                        {renderHeader('Prior Level Completed', 'prior_completions')}
                                        {renderHeader('DofE Number', 'dofe_number')}
                                        {renderHeader('Status', 'signup_status')}
                                    </>
                                ) : (
                                    <>
                                        {renderHeader('First Aid', 'first_aid_status')}
                                        {renderHeader('DofE Number', 'dofe_number')}
                                    </>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {sortedSignups.length === 0 ? (
                                <tr>
                                    <td colSpan={type === 'participant' ? 10 : 9} className="ems-table-cell--center ems-p-20 ems-meta-text ems-italic">
                                        No signup records found for this filter state.
                                    </td>
                                </tr>
                            ) : (
                                sortedSignups.map((s) => (
                                    <tr 
                                        key={s.id} 
                                        onClick={() => setSelectedSignup(s)}
                                        className={`ems-row-hoverable ${selectedSignup && selectedSignup.id === s.id ? 'ems-table-row--selected' : ''}`}
                                    >
                                        <td className="ems-table-cell--center">
                                            {s.is_synced_osm ? (
                                                <span title="Synced with OSM" className="ems-fa-full">✓</span>
                                            ) : null}
                                        </td>
                                        <td>
                                            {s.created_at ? s.created_at.substring(0, 16) : '—'}
                                        </td>
                                        <td>
                                            <strong>{s.explorer_first_name} {s.explorer_last_name}</strong>
                                        </td>
                                        <td>
                                            <span className={`ems-pill ems-pill--${s.dofe_level}`}>
                                                {s.dofe_level}
                                            </span>
                                        </td>
                                        {type === 'expedition' && (
                                            <td className="ems-table-cell--small">
                                                {s.expedition_preferences?.exped_type === 'Hillwalking' ? '🥾' : s.expedition_preferences?.exped_type === 'Biking' ? '🚲' : '🛶'} {s.expedition_preferences?.exped_type || '—'}
                                            </td>
                                        )}
                                        <td>
                                            {s.unit_name}
                                        </td>
                                        <td>
                                            {s.explorer_email || '—'}
                                        </td>
                                        {type === 'participant' ? (
                                            <>
                                                 <td>
                                                     {renderPriorCompletions(s)}
                                                 </td>
                                                 <td className="ems-monospace">
                                                     {s.dofe_number || '—'}
                                                     {s.dofe_registered === 'y-other' && (
                                                         <div className="ems-signups-transfer-warning">
                                                             ⚠️ Transfer Req.
                                                         </div>
                                                     )}
                                                 </td>
                                                <td>
                                                    <span className={`ems-status-badge ems-status-badge--${s.signup_status}`}>
                                                        {s.signup_status}
                                                    </span>
                                                </td>
                                            </>
                                        ) : (
                                            <>
                                                <td>
                                                    {s.first_aid_status}
                                                </td>
                                                <td className="ems-monospace">
                                                    {s.dofe_number || '—'}
                                                </td>
                                            </>
                                        )}
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Inspector Panel - Slide-Out/Docked Layout */}
            {selectedSignup && (
                <div className="ems-signups-inspector">
                    {/* Header */}
                    <div className="ems-signups-inspector__header">
                        <h3 className="ems-m-0 ems-font-semibold">Explorer Details</h3>
                        <div className="ems-flex-center ems-gap-4">
                            {currentUnprocessedIndex >= 0 && (
                                <>
                                    <button 
                                        onClick={handlePrevSignup} 
                                        disabled={currentUnprocessedIndex === 0}
                                        className="button button-secondary button-small"
                                    >
                                        &lt;
                                    </button>
                                    <button 
                                        onClick={handleNextSignup} 
                                        disabled={currentUnprocessedIndex === unprocessedSignups.length - 1}
                                        className="button button-secondary button-small"
                                    >
                                        &gt;
                                    </button>
                                </>
                            )}
                            <button 
                                onClick={() => setSelectedSignup(null)}
                                className="button-link"
                            >
                                &times;
                            </button>
                        </div>
                    </div>

                    {/* Details Body */}
                    <div className="ems-signups-inspector__body">
                        <div className="ems-flex-between">
                            <div>
                                <span className="ems-signups-inspector__label">Name</span>
                                <div className="ems-signups-inspector__value--large">
                                    {selectedSignup.explorer_first_name} {selectedSignup.explorer_last_name}
                                </div>
                            </div>
                            <div className="ems-table-cell--right">
                                <span className="ems-signups-inspector__label">Scout ID</span>
                                <div className="ems-signups-inspector__value ems-monospace ems-font-semibold">
                                    {selectedSignup.scout_id}
                                </div>
                            </div>
                        </div>

                        <div className="ems-flex-between ems-gap-8">
                            <div>
                                <span className="ems-signups-inspector__label">Status</span>
                                <div className="ems-mt-4">
                                    <span className={`ems-status-badge ems-status-badge--${selectedSignup.signup_status}`}>
                                        {selectedSignup.signup_status}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <span className="ems-signups-inspector__label">Level</span>
                                <div className="ems-mt-4">
                                    <span className={`ems-pill ems-pill--${selectedSignup.dofe_level}`}>
                                        {selectedSignup.dofe_level}
                                    </span>
                                </div>
                            </div>
                            {type === 'expedition' && (
                                <div>
                                    <span className="ems-signups-inspector__label">Expedition</span>
                                    <div className="ems-signups-inspector__value ems-font-semibold ems-mt-4 ems-flex-center ems-gap-4">
                                        {selectedSignup.expedition_preferences?.exped_type === 'Hillwalking' ? '🥾' : selectedSignup.expedition_preferences?.exped_type === 'Biking' ? '🚲' : '🛶'} {selectedSignup.expedition_preferences?.exped_type || '—'}
                                    </div>
                                </div>
                            )}
                            <div>
                                <span className="ems-signups-inspector__label">Unit</span>
                                <div className="ems-signups-inspector__value ems-font-semibold ems-mt-4">{selectedSignup.unit_name}</div>
                            </div>
                        </div>

                        {type === 'participant' && (
                            <>
                                <div className="ems-flex-between">
                                    <div>
                                        <span className="ems-signups-inspector__label">DofE Registration Status</span>
                                        <div className="ems-signups-inspector__value ems-mt-4">
                                            {selectedSignup.dofe_registered === 'y' ? 'Registered' : (selectedSignup.dofe_registered === 'y-other' ? 'Registered (Other)' : 'Needs Registration')}
                                        </div>
                                    </div>
                                    <div>
                                        <span className="ems-signups-inspector__label">DOB</span>
                                        <div className="ems-signups-inspector__value ems-mt-4">{selectedSignup.dob || '—'}</div>
                                    </div>
                                </div>

                                {selectedSignup.dofe_registered === 'y-other' && (
                                    <div className="ems-signups-inspector__transfer-alert">
                                        <div className="ems-flex-center ems-gap-4">
                                            <span>⚠️</span> <strong>Transfer Required</strong>
                                        </div>
                                        <div className="ems-font-normal ems-meta-text ems-small-text ems-mt-4">
                                            From: <strong className="ems-font-semibold">{selectedSignup.dofe_org || 'Unknown Organisation'}</strong>
                                        </div>
                                    </div>
                                )}

                                <div>
                                    <label htmlFor="inspector-dofe-num" className="ems-signups-inspector__label">eDofE Number</label>
                                    <input 
                                        id="inspector-dofe-num"
                                        type="text" 
                                        value={editedDofeNumber} 
                                        onChange={(e) => setEditedDofeNumber(e.target.value)}
                                        placeholder="Enter eDofE number..."
                                        className="ems-signups-inspector__input"
                                    />
                                </div>

                                <div>
                                    <span className="ems-signups-inspector__label">Prior Level Completions</span>
                                    <div className="ems-mt-4">
                                        {renderPriorCompletions(selectedSignup)}
                                    </div>
                                </div>

                                <div>
                                    <span className="ems-signups-inspector__label">Payment Status</span>
                                    <div className="ems-mt-4">
                                        <span className={`ems-status-badge ems-status-badge--${selectedSignup.payment_status}`}>
                                            {selectedSignup.payment_status === 'paid' ? 'Paid' : selectedSignup.payment_status === 'failed' ? 'Failed' : 'Pending'}
                                        </span>
                                    </div>
                                </div>
                            </>
                        )}

                        {/* Expedition Specific Content */}
                        {type === 'expedition' && (
                            <>
                                <div>
                                    <span className="ems-signups-inspector__label">eDofE Number</span>
                                    <div className="ems-signups-inspector__value ems-font-semibold ems-mt-4 ems-monospace">{selectedSignup.dofe_number || '—'}</div>
                                </div>

                                <div className="ems-flex-between ems-gap-16">
                                    <div className="ems-main">
                                        <span className="ems-signups-inspector__label">First Aid Status</span>
                                        <div className="ems-signups-inspector__value ems-mt-4 ems-font-semibold">{selectedSignup.first_aid_status}</div>
                                    </div>
                                    {selectedSignup.first_aid_expiry && (
                                        <div className="ems-main">
                                            <span className="ems-signups-inspector__label">First Aid Expiry</span>
                                            <div className="ems-signups-inspector__value ems-mt-4">{selectedSignup.first_aid_expiry}</div>
                                        </div>
                                    )}
                                </div>

                                <div>
                                    <span className="ems-signups-inspector__label">Additional Support Needs</span>
                                    <div className="ems-signups-inspector__support-box">
                                        {selectedSignup.additional_support_needs || 'None declared.'}
                                    </div>
                                </div>

                                <div>
                                    <span className="ems-signups-inspector__label">Expedition Preferences</span>
                                    <div className="ems-small-text ems-mt-4 ems-flex-col ems-gap-8">
                                        {selectedSignup.expedition_preferences ? (
                                            <>
                                                <div><strong>Practice Dates:</strong> {selectedSignup.expedition_preferences.exped_practice_dates || '—'}</div>
                                                <div><strong>Qualifier Dates:</strong> {selectedSignup.expedition_preferences.exped_qualifier_dates || '—'}</div>
                                                <div><strong>Teammates:</strong> {selectedSignup.expedition_preferences.exped_team_names || '—'}</div>
                                            </>
                                        ) : (
                                            <div>No preferences specified.</div>
                                        )}
                                    </div>
                                </div>
                            </>
                        )}

                        {/* Emails Block (rendered just above Submission Info) */}
                        <div className="ems-flex-col ems-gap-12 ems-mt-12" style={{ borderTop: '1px dashed #ccd0d4', paddingTop: '16px' }}>
                            <div>
                                <span className="ems-signups-inspector__label">Explorer Email</span>
                                <div className="ems-signups-inspector__value ems-mt-4">{selectedSignup.explorer_email || '—'}</div>
                            </div>

                            <div>
                                <span className="ems-signups-inspector__label">Parent Email</span>
                                <div className="ems-signups-inspector__value ems-mt-4">{selectedSignup.parent_email || '—'}</div>
                            </div>

                            <div>
                                <span className="ems-signups-inspector__label">Leader Email</span>
                                <div className="ems-signups-inspector__value ems-mt-4">{selectedSignup.leader_email || '—'}</div>
                            </div>
                        </div>

                        {/* Submission ID & Date Block (rendered at bottom) */}
                        <div className="ems-flex-between ems-mt-12" style={{ borderTop: '1px dashed #ccd0d4', paddingTop: '16px' }}>
                            <div>
                                <span className="ems-signups-inspector__label">Submission ID</span>
                                <div className="ems-signups-inspector__value ems-mt-4">#{selectedSignup.form_submission_id || '—'}</div>
                            </div>
                            <div>
                                <span className="ems-signups-inspector__label">Submitted At</span>
                                <div className="ems-signups-inspector__value ems-mt-4">{selectedSignup.created_at || '—'}</div>
                            </div>
                        </div>
                    </div>

                    {/* Actions Footer */}
                    <div className="ems-signups-inspector__footer">
                        <div>
                            {selectedSignup.signup_status !== 'archived' && (
                                <button 
                                    onClick={() => handleArchive(selectedSignup.id)}
                                    className="button button-link-delete"
                                >
                                    Archive
                                </button>
                            )}
                        </div>
                        <div className="ems-flex-center ems-gap-6">
                            <button onClick={() => setSelectedSignup(null)} className="button">Close</button>
                            {type === 'participant' && selectedSignup.signup_status === 'received' && (
                                <button 
                                    onClick={() => handleProcessParticipant(selectedSignup.id, editedDofeNumber)}
                                    className="button button-primary"
                                >
                                    Allocate Slot
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
