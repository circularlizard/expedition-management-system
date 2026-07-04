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
        fetchSignups();
        setSelectedSignup(null);
    }, [statusFilter]);

    useEffect(() => {
        if (selectedSignup) {
            setEditedDofeNumber(selectedSignup.dofe_number || '');
        }
    }, [selectedSignup]);

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

    const handleProcessExpedition = async (signupId: number) => {
        try {
            const response = await fetch(`${config.root_url}/signups/expeditions/${signupId}/process`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': config.nonce
                }
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Failed to process signup');
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
        if (levelFilter === 'all') return true;
        return s.dofe_level === levelFilter;
    });

    // Paging list of unprocessed signups inside the Inspector Panel
    const unprocessedSignups = filteredSignups.filter(s => s.signup_status === defaultStatus);
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
                <div style={{ display: 'inline-flex', justifyContent: 'center', alignItems: 'center', width: '24px', height: '24px', borderRadius: '50%', background: '#d63638', color: '#fff', fontWeight: 'bold', fontSize: '12px' }}>
                    X
                </div>
            );
        }

        const completions = signup.dofe_level === 'silver' ? signup.bronze_completion : signup.silver_completion;
        if (!completions || typeof completions !== 'object') {
            return (
                <div style={{ display: 'inline-flex', justifyContent: 'center', alignItems: 'center', width: '24px', height: '24px', borderRadius: '50%', background: '#d63638', color: '#fff', fontWeight: 'bold', fontSize: '12px' }}>
                    X
                </div>
            );
        }

        const completedList: JSX.Element[] = [];
        if (completions.volunteering === 'completed') {
            completedList.push(
                <span key="V" title="Volunteering Completed" style={{ display: 'inline-flex', justifyContent: 'center', alignItems: 'center', width: '22px', height: '22px', borderRadius: '50%', background: '#d63638', color: '#fff', fontWeight: 'bold', fontSize: '11px', marginRight: '4px' }}>
                    V
                </span>
            );
        }
        if (completions.skills === 'completed') {
            completedList.push(
                <span key="S" title="Skills Completed" style={{ display: 'inline-flex', justifyContent: 'center', alignItems: 'center', width: '22px', height: '22px', borderRadius: '50%', background: '#2271b1', color: '#fff', fontWeight: 'bold', fontSize: '11px', marginRight: '4px' }}>
                    S
                </span>
            );
        }
        if (completions.physical === 'completed') {
            completedList.push(
                <span key="P" title="Physical Completed" style={{ display: 'inline-flex', justifyContent: 'center', alignItems: 'center', width: '22px', height: '22px', borderRadius: '50%', background: '#dba617', color: '#fff', fontWeight: 'bold', fontSize: '11px', marginRight: '4px' }}>
                    P
                </span>
            );
        }
        if (completions.expedition === 'completed') {
            completedList.push(
                <span key="E" title="Expedition Completed" style={{ display: 'inline-flex', justifyContent: 'center', alignItems: 'center', width: '22px', height: '22px', borderRadius: '50%', background: '#00a32a', color: '#fff', fontWeight: 'bold', fontSize: '11px', marginRight: '4px' }}>
                    E
                </span>
            );
        }

        if (completedList.length === 0) {
            return (
                <div style={{ display: 'inline-flex', justifyContent: 'center', alignItems: 'center', width: '24px', height: '24px', borderRadius: '50%', background: '#d63638', color: '#fff', fontWeight: 'bold', fontSize: '12px' }}>
                    X
                </div>
            );
        }

        return <div style={{ display: 'flex' }}>{completedList}</div>;
    };

    if (loading && signups.length === 0) {
        return (
            <div style={{ padding: '20px', background: '#fff', border: '1px solid #ccd0d4', borderRadius: '4px' }}>
                <p>Loading signups...</p>
            </div>
        );
    }

    return (
        <div style={{ fontFamily: 'Inter, sans-serif', color: '#1d2327', display: 'flex', gap: '20px', position: 'relative' }}>
            {/* Main Content Area */}
            <div style={{ flex: 1, minWidth: 0 }}>
                {error && (
                    <div style={{ padding: '12px 16px', background: '#fcf0f1', borderLeft: '4px solid #d63638', color: '#d63638', marginBottom: '16px', borderRadius: '2px' }}>
                        {error}
                    </div>
                )}

                {/* Filter and Control Bar */}
                <div style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    background: 'linear-gradient(135deg, #ffffff 0%, #f9f9f9 100%)',
                    padding: '16px 20px',
                    border: '1px solid #ccd0d4',
                    borderRadius: '8px',
                    marginBottom: '20px',
                    boxShadow: '0 2px 4px rgba(0,0,0,0.02)'
                }}>
                    {/* Status Tabs */}
                    <div style={{ display: 'flex', gap: '8px' }}>
                        {type === 'participant' ? (
                            <>
                                <label style={{ display: 'inline-flex', alignItems: 'center', cursor: 'pointer', padding: '6px 12px', background: statusFilter === 'received' ? '#e5f3ff' : '#fff', border: `1px solid ${statusFilter === 'received' ? '#2271b1' : '#ccd0d4'}`, borderRadius: '20px', color: statusFilter === 'received' ? '#1d2327' : '#646970', fontWeight: '500', transition: 'all 0.2s' }}>
                                    <input type="radio" name="statusFilter" value="received" checked={statusFilter === 'received'} onChange={() => setStatusFilter('received')} style={{ display: 'none' }} />
                                    Received
                                </label>
                                <label style={{ display: 'inline-flex', alignItems: 'center', cursor: 'pointer', padding: '6px 12px', background: statusFilter === 'allocated' ? '#e5f3ff' : '#fff', border: `1px solid ${statusFilter === 'allocated' ? '#2271b1' : '#ccd0d4'}`, borderRadius: '20px', color: statusFilter === 'allocated' ? '#1d2327' : '#646970', fontWeight: '500', transition: 'all 0.2s' }}>
                                    <input type="radio" name="statusFilter" value="allocated" checked={statusFilter === 'allocated'} onChange={() => setStatusFilter('allocated')} style={{ display: 'none' }} />
                                    Allocated
                                </label>
                            </>
                        ) : (
                            <>
                                <label style={{ display: 'inline-flex', alignItems: 'center', cursor: 'pointer', padding: '6px 12px', background: statusFilter === 'pending' ? '#e5f3ff' : '#fff', border: `1px solid ${statusFilter === 'pending' ? '#2271b1' : '#ccd0d4'}`, borderRadius: '20px', color: statusFilter === 'pending' ? '#1d2327' : '#646970', fontWeight: '500', transition: 'all 0.2s' }}>
                                    <input type="radio" name="statusFilter" value="pending" checked={statusFilter === 'pending'} onChange={() => setStatusFilter('pending')} style={{ display: 'none' }} />
                                    Active (Pending)
                                </label>
                                <label style={{ display: 'inline-flex', alignItems: 'center', cursor: 'pointer', padding: '6px 12px', background: statusFilter === 'processed' ? '#e5f3ff' : '#fff', border: `1px solid ${statusFilter === 'processed' ? '#2271b1' : '#ccd0d4'}`, borderRadius: '20px', color: statusFilter === 'processed' ? '#1d2327' : '#646970', fontWeight: '500', transition: 'all 0.2s' }}>
                                    <input type="radio" name="statusFilter" value="processed" checked={statusFilter === 'processed'} onChange={() => setStatusFilter('processed')} style={{ display: 'none' }} />
                                    Processed
                                </label>
                            </>
                        )}
                        <label style={{ display: 'inline-flex', alignItems: 'center', cursor: 'pointer', padding: '6px 12px', background: statusFilter === 'archived' ? '#e5f3ff' : '#fff', border: `1px solid ${statusFilter === 'archived' ? '#2271b1' : '#ccd0d4'}`, borderRadius: '20px', color: statusFilter === 'archived' ? '#1d2327' : '#646970', fontWeight: '500', transition: 'all 0.2s' }}>
                            <input type="radio" name="statusFilter" value="archived" checked={statusFilter === 'archived'} onChange={() => setStatusFilter('archived')} style={{ display: 'none' }} />
                            Archived
                        </label>
                    </div>

                    {/* Level Filter */}
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                        <label htmlFor="level-filter" style={{ fontWeight: '600' }}>Filter Level:</label>
                        <select
                            id="level-filter"
                            aria-label="Filter Level"
                            value={levelFilter}
                            onChange={(e) => setLevelFilter(e.target.value)}
                            style={{ padding: '6px 12px', border: '1px solid #ccd0d4', borderRadius: '4px', background: '#fff', fontSize: '13px' }}
                        >
                            <option value="all">All Levels</option>
                            <option value="bronze">Bronze</option>
                            <option value="silver">Silver</option>
                            <option value="gold">Gold</option>
                        </select>
                    </div>
                </div>

                {/* Table Grid */}
                <div style={{ background: '#fff', border: '1px solid #ccd0d4', borderRadius: '8px', overflow: 'hidden', boxShadow: '0 2px 8px rgba(0,0,0,0.03)' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left' }}>
                        <thead>
                            <tr style={{ background: '#f6f7f7', borderBottom: '1px solid #ccd0d4', fontWeight: '600' }}>
                                <th style={{ padding: '12px 16px' }}>Submission Date</th>
                                <th style={{ padding: '12px 16px' }}>Explorer Name</th>
                                <th style={{ padding: '12px 16px' }}>Level</th>
                                <th style={{ padding: '12px 16px' }}>ESU</th>
                                <th style={{ padding: '12px 16px' }}>Email</th>
                                {type === 'participant' ? (
                                    <>
                                        <th style={{ padding: '12px 16px' }}>Prior Level Completed</th>
                                        <th style={{ padding: '12px 16px' }}>DofE Number</th>
                                        <th style={{ padding: '12px 16px' }}>Status</th>
                                    </>
                                ) : (
                                    <>
                                        <th style={{ padding: '12px 16px' }}>First Aid</th>
                                        <th style={{ padding: '12px 16px' }}>DofE Number</th>
                                    </>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {filteredSignups.length === 0 ? (
                                <tr>
                                    <td colSpan={type === 'participant' ? 8 : 7} style={{ padding: '24px', textAlign: 'center', color: '#646970' }}>
                                        No signup records found for this filter state.
                                    </td>
                                </tr>
                            ) : (
                                filteredSignups.map((s) => (
                                    <tr 
                                        key={s.id} 
                                        onClick={() => setSelectedSignup(s)}
                                        style={{ 
                                            borderBottom: '1px solid #f0f0f1', 
                                            cursor: 'pointer',
                                            background: selectedSignup && selectedSignup.id === s.id ? '#f0f6fc' : '#fff',
                                            transition: 'background-color 0.2s' 
                                        }}
                                    >
                                        <td style={{ padding: '16px' }}>
                                            {s.created_at ? s.created_at.substring(0, 16) : '—'}
                                        </td>
                                        <td style={{ padding: '16px' }}>
                                            <strong>{s.explorer_first_name} {s.explorer_last_name}</strong>
                                        </td>
                                        <td style={{ padding: '16px' }}>
                                            <span style={{
                                                display: 'inline-block',
                                                padding: '4px 8px',
                                                borderRadius: '12px',
                                                fontSize: '11px',
                                                fontWeight: 'bold',
                                                textTransform: 'uppercase',
                                                background: s.dofe_level === 'bronze' ? '#fbf0e6' : s.dofe_level === 'silver' ? '#f0f0f1' : '#fef8e2',
                                                color: s.dofe_level === 'bronze' ? '#c86f26' : s.dofe_level === 'silver' ? '#50575e' : '#b28900',
                                                border: `1px solid ${s.dofe_level === 'bronze' ? '#f5d3b3' : s.dofe_level === 'silver' ? '#dcdcde' : '#f8e39d'}`
                                            }}>
                                                {s.dofe_level}
                                            </span>
                                        </td>
                                        <td style={{ padding: '16px' }}>
                                            {s.unit_name}
                                        </td>
                                        <td style={{ padding: '16px' }}>
                                            {s.explorer_email || '—'}
                                        </td>
                                        {type === 'participant' ? (
                                            <>
                                                 <td style={{ padding: '16px' }}>
                                                     {renderPriorCompletions(s)}
                                                 </td>
                                                 <td style={{ padding: '16px', fontFamily: 'monospace' }}>
                                                     {s.dofe_number || '—'}
                                                     {s.dofe_registered === 'y-other' && (
                                                         <div style={{
                                                             fontSize: '10px',
                                                             color: '#d63638',
                                                             fontWeight: 'bold',
                                                             textTransform: 'uppercase',
                                                             marginTop: '2px',
                                                             fontFamily: 'sans-serif'
                                                         }}>
                                                             ⚠️ Transfer Req.
                                                         </div>
                                                     )}
                                                 </td>
                                                <td style={{ padding: '16px' }}>
                                                    <span style={{
                                                        display: 'inline-block',
                                                        padding: '2px 8px',
                                                        borderRadius: '12px',
                                                        fontSize: '11px',
                                                        fontWeight: 'bold',
                                                        textTransform: 'uppercase',
                                                        background: s.signup_status === 'allocated' ? '#e5f8eb' : (s.signup_status === 'archived' ? '#f5f5f5' : '#fef8e2'),
                                                        color: s.signup_status === 'allocated' ? '#00a32a' : (s.signup_status === 'archived' ? '#646970' : '#b28900'),
                                                        border: `1px solid ${s.signup_status === 'allocated' ? '#a3e2b2' : (s.signup_status === 'archived' ? '#dcdcde' : '#f8e39d')}`
                                                    }}>
                                                        {s.signup_status}
                                                    </span>
                                                </td>
                                            </>
                                        ) : (
                                            <>
                                                <td style={{ padding: '16px' }}>
                                                    {s.first_aid_status}
                                                </td>
                                                <td style={{ padding: '16px', fontFamily: 'monospace' }}>
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
                <div style={{
                    width: '380px',
                    background: '#fff',
                    border: '1px solid #ccd0d4',
                    borderRadius: '8px',
                    boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
                    display: 'flex',
                    flexDirection: 'column',
                    maxHeight: 'calc(100vh - 120px)',
                    position: 'sticky',
                    top: '20px',
                    alignSelf: 'flex-start'
                }}>
                    {/* Header */}
                    <div style={{
                        padding: '16px 20px',
                        background: '#f6f7f7',
                        borderBottom: '1px solid #ccd0d4',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        borderTopLeftRadius: '8px',
                        borderTopRightRadius: '8px'
                    }}>
                        <h3 style={{ margin: 0, fontSize: '15px', fontWeight: '600' }}>Explorer Details</h3>
                        <div style={{ display: 'flex', gap: '4px', alignItems: 'center' }}>
                            {currentUnprocessedIndex >= 0 && (
                                <>
                                    <button 
                                        onClick={handlePrevSignup} 
                                        disabled={currentUnprocessedIndex === 0}
                                        style={{ border: '1px solid #ccd0d4', background: '#fff', borderRadius: '4px', padding: '3px 8px', cursor: currentUnprocessedIndex === 0 ? 'default' : 'pointer', opacity: currentUnprocessedIndex === 0 ? 0.5 : 1 }}
                                    >
                                        &lt;
                                    </button>
                                    <button 
                                        onClick={handleNextSignup} 
                                        disabled={currentUnprocessedIndex === unprocessedSignups.length - 1}
                                        style={{ border: '1px solid #ccd0d4', background: '#fff', borderRadius: '4px', padding: '3px 8px', cursor: currentUnprocessedIndex === unprocessedSignups.length - 1 ? 'default' : 'pointer', opacity: currentUnprocessedIndex === unprocessedSignups.length - 1 ? 0.5 : 1 }}
                                    >
                                        &gt;
                                    </button>
                                </>
                            )}
                            <button 
                                onClick={() => setSelectedSignup(null)}
                                style={{ border: 'none', background: 'transparent', fontSize: '18px', cursor: 'pointer', color: '#646970', marginLeft: '6px' }}
                            >
                                &times;
                            </button>
                        </div>
                    </div>

                    {/* Details Body */}
                    <div style={{ padding: '20px', overflowY: 'auto', flex: 1, display: 'flex', flexDirection: 'column', gap: '16px' }}>
                        <div>
                            <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Name</span>
                            <div style={{ fontSize: '16px', fontWeight: 'bold', marginTop: '2px' }}>
                                {selectedSignup.explorer_first_name} {selectedSignup.explorer_last_name}
                            </div>
                        </div>

                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: '8px' }}>
                            <div>
                                <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Status</span>
                                <div style={{ marginTop: '2px' }}>
                                    <span style={{
                                        display: 'inline-block',
                                        padding: '2px 8px',
                                        borderRadius: '12px',
                                        fontSize: '10px',
                                        fontWeight: 'bold',
                                        textTransform: 'uppercase',
                                        background: selectedSignup.signup_status === 'allocated' || selectedSignup.signup_status === 'processed' ? '#e5f8eb' : (selectedSignup.signup_status === 'archived' ? '#f5f5f5' : '#fef8e2'),
                                        color: selectedSignup.signup_status === 'allocated' || selectedSignup.signup_status === 'processed' ? '#00a32a' : (selectedSignup.signup_status === 'archived' ? '#646970' : '#b28900'),
                                        border: `1px solid ${selectedSignup.signup_status === 'allocated' || selectedSignup.signup_status === 'processed' ? '#a3e2b2' : (selectedSignup.signup_status === 'archived' ? '#dcdcde' : '#f8e39d')}`
                                    }}>
                                        {selectedSignup.signup_status}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Level</span>
                                <div style={{ marginTop: '2px' }}>
                                    <span style={{
                                        display: 'inline-block',
                                        padding: '2px 6px',
                                        borderRadius: '10px',
                                        fontSize: '10px',
                                        fontWeight: 'bold',
                                        textTransform: 'uppercase',
                                        background: selectedSignup.dofe_level === 'bronze' ? '#fbf0e6' : selectedSignup.dofe_level === 'silver' ? '#f0f0f1' : '#fef8e2',
                                        color: selectedSignup.dofe_level === 'bronze' ? '#c86f26' : selectedSignup.dofe_level === 'silver' ? '#50575e' : '#b28900'
                                    }}>
                                        {selectedSignup.dofe_level}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Unit</span>
                                <div style={{ fontSize: '13px', fontWeight: '500', marginTop: '2px' }}>{selectedSignup.unit_name}</div>
                            </div>
                        </div>

                        {type === 'participant' && (
                            <>
                                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                                    <div>
                                        <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>DofE Registration Status</span>
                                        <div style={{ fontSize: '13px', marginTop: '2px' }}>
                                            {selectedSignup.dofe_registered === 'y' ? 'Registered' : (selectedSignup.dofe_registered === 'y-other' ? 'Registered (Other)' : 'Needs Registration')}
                                        </div>
                                    </div>
                                    <div>
                                        <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>DOB</span>
                                        <div style={{ fontSize: '13px', marginTop: '2px' }}>{selectedSignup.dob || '—'}</div>
                                    </div>
                                </div>

                                {selectedSignup.dofe_registered === 'y-other' && (
                                    <div style={{
                                        padding: '10px 12px',
                                        background: '#fdf3f4',
                                        color: '#d63638',
                                        border: '1px solid #fbc4c5',
                                        borderRadius: '6px',
                                        fontSize: '12px',
                                        fontWeight: '600',
                                        display: 'flex',
                                        flexDirection: 'column',
                                        gap: '2px',
                                        marginTop: '-4px'
                                    }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
                                            <span>⚠️</span> <strong>Transfer Required</strong>
                                        </div>
                                        <div style={{ fontWeight: 'normal', color: '#50575e', fontSize: '11px', marginTop: '2px' }}>
                                            From: <strong style={{ color: '#1d2327' }}>{selectedSignup.dofe_org || 'Unknown Organisation'}</strong>
                                        </div>
                                    </div>
                                )}

                                <div>
                                    <label htmlFor="inspector-dofe-num" style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600', display: 'block' }}>eDofE Number</label>
                                    <input 
                                        id="inspector-dofe-num"
                                        type="text" 
                                        value={editedDofeNumber} 
                                        onChange={(e) => setEditedDofeNumber(e.target.value)}
                                        placeholder="Enter eDofE number..."
                                        style={{ width: '100%', padding: '6px 10px', border: '1px solid #ccd0d4', borderRadius: '4px', marginTop: '4px' }}
                                    />
                                </div>

                                <div>
                                    <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Prior Level Completions</span>
                                    <div style={{ marginTop: '4px' }}>
                                        {renderPriorCompletions(selectedSignup)}
                                    </div>
                                </div>
                            </>
                        )}

                        <div>
                            <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Explorer Email</span>
                            <div style={{ fontSize: '13px', marginTop: '2px' }}>{selectedSignup.explorer_email || '—'}</div>
                        </div>

                        <div>
                            <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Parent Email</span>
                            <div style={{ fontSize: '13px', marginTop: '2px' }}>{selectedSignup.parent_email || '—'}</div>
                        </div>

                        <div>
                            <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Leader Email</span>
                            <div style={{ fontSize: '13px', marginTop: '2px' }}>{selectedSignup.leader_email || '—'}</div>
                        </div>

                        <div>
                            <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Payment Status</span>
                            <div style={{ marginTop: '2px' }}>
                                <span style={{
                                    display: 'inline-block',
                                    padding: '2px 8px',
                                    borderRadius: '12px',
                                    fontSize: '11px',
                                    fontWeight: 'bold',
                                    textTransform: 'uppercase',
                                    background: selectedSignup.payment_status === 'paid' ? '#e5f8eb' : '#fcf0f1',
                                    color: selectedSignup.payment_status === 'paid' ? '#00a32a' : '#d63638',
                                    border: `1px solid ${selectedSignup.payment_status === 'paid' ? '#a3e2b2' : '#f5c2c3'}`
                                }}>
                                    {selectedSignup.payment_status === 'paid' ? 'Paid' : selectedSignup.payment_status === 'failed' ? 'Failed' : 'Pending'}
                                </span>
                            </div>
                        </div>

                        <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                            <div>
                                <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Submission ID</span>
                                <div style={{ fontSize: '13px', marginTop: '2px' }}>#{selectedSignup.form_submission_id || '—'}</div>
                            </div>
                            <div>
                                <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Submitted At</span>
                                <div style={{ fontSize: '13px', marginTop: '2px' }}>{selectedSignup.created_at || '—'}</div>
                            </div>
                        </div>

                        {type === 'expedition' && (
                            <>
                                <div>
                                    <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>First Aid Status</span>
                                    <div style={{ fontSize: '13px', marginTop: '2px', fontWeight: '500' }}>{selectedSignup.first_aid_status}</div>
                                </div>
                                {selectedSignup.first_aid_expiry && (
                                    <div>
                                        <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>First Aid Expiry</span>
                                        <div style={{ fontSize: '13px', marginTop: '2px' }}>{selectedSignup.first_aid_expiry}</div>
                                    </div>
                                )}
                                <div>
                                    <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Additional Support Needs</span>
                                    <div style={{ fontSize: '13px', marginTop: '4px', padding: '10px', background: '#f6f7f7', border: '1px solid #ccd0d4', borderRadius: '4px', fontStyle: 'italic' }}>
                                        {selectedSignup.additional_support_needs || 'None declared.'}
                                    </div>
                                </div>
                                <div>
                                    <span style={{ fontSize: '11px', textTransform: 'uppercase', color: '#646970', fontWeight: '600' }}>Expedition Preferences</span>
                                    <div style={{ fontSize: '12px', marginTop: '6px', display: 'flex', flexDirection: 'column', gap: '8px' }}>
                                        {selectedSignup.expedition_preferences ? (
                                            <>
                                                <div><strong>Type:</strong> {selectedSignup.expedition_preferences.exped_type || '—'}</div>
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
                    </div>

                    {/* Actions Footer */}
                    <div style={{ padding: '16px 20px', borderTop: '1px solid #ccd0d4', background: '#f6f7f7', display: 'flex', justifyContent: 'space-between', borderBottomLeftRadius: '8px', borderBottomRightRadius: '8px' }}>
                        <div>
                            {selectedSignup.signup_status !== 'archived' && (
                                <button 
                                    onClick={() => handleArchive(selectedSignup.id)}
                                    className="button button-link-delete"
                                    style={{ color: '#d63638' }}
                                >
                                    Archive
                                </button>
                            )}
                        </div>
                        <div style={{ display: 'flex', gap: '6px' }}>
                            <button onClick={() => setSelectedSignup(null)} className="button">Close</button>
                            {type === 'participant' ? (
                                selectedSignup.signup_status === 'received' && (
                                    <button 
                                        onClick={() => handleProcessParticipant(selectedSignup.id, editedDofeNumber)}
                                        className="button button-primary"
                                    >
                                        Allocate Slot
                                    </button>
                                )
                            ) : (
                                selectedSignup.signup_status === 'pending' && (
                                    <button 
                                        onClick={() => handleProcessExpedition(selectedSignup.id)}
                                        className="button button-primary"
                                    >
                                        Process Entry
                                    </button>
                                )
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
