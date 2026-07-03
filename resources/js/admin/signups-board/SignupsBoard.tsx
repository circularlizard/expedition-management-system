import React, { useState, useEffect } from 'react';
import { Signup, Explorer } from '../expedition-board/types';

interface SignupsConfig {
    root_url: string;
    nonce: string;
}

declare global {
    interface Window {
        emsSignupsBoard: SignupsConfig;
    }
}

export default function SignupsBoard() {
    const config = window.emsSignupsBoard || { root_url: '', nonce: '' };

    const [signups, setSignups] = useState<any[]>([]);
    const [explorers, setExplorers] = useState<Explorer[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Filters
    const [statusFilter, setStatusFilter] = useState<'pending' | 'processed' | 'archived'>('pending');
    const [levelFilter, setLevelFilter] = useState<string>('all');

    // Link Modal state
    const [linkModalSignup, setLinkModalSignup] = useState<any | null>(null);
    const [searchQuery, setSearchQuery] = useState('');

    const fetchSignups = async () => {
        setLoading(true);
        setError(null);
        try {
            // Mapping status filter to API status values
            let apiStatus = 'active';
            if (statusFilter === 'processed') apiStatus = 'processed';
            if (statusFilter === 'archived') apiStatus = 'archived';

            const response = await fetch(`${config.root_url}/signups?status=${apiStatus}`, {
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

    const fetchExplorers = async () => {
        try {
            const response = await fetch(`${config.root_url}/expedition-board`, {
                headers: {
                    'X-WP-Nonce': config.nonce
                }
            });
            if (response && response.ok) {
                const data = await response.json();
                setExplorers(data.explorers || []);
            }
        } catch (err) {
            console.error('Error fetching board/explorers', err);
        }
    };

    useEffect(() => {
        fetchSignups();
    }, [statusFilter]);

    useEffect(() => {
        fetchExplorers();
    }, []);

    const handleProcess = async (signupId: number) => {
        try {
            const response = await fetch(`${config.root_url}/signups/${signupId}/process`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': config.nonce
                }
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Failed to process signup');
            }
            fetchSignups();
            fetchExplorers();
        } catch (err: any) {
            alert(err.message);
        }
    };

    const handleReconcile = async (signupId: number, scoutId: number) => {
        try {
            const response = await fetch(`${config.root_url}/signups/${signupId}/reconcile`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({ scout_id: scoutId })
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Failed to reconcile signup');
            }
            setLinkModalSignup(null);
            setSearchQuery('');
            fetchSignups();
            fetchExplorers();
        } catch (err: any) {
            alert(err.message);
        }
    };

    const handleArchive = async (signupId: number) => {
        if (!confirm('Are you sure you want to archive this signup?')) return;
        try {
            const response = await fetch(`${config.root_url}/signups/${signupId}/archive`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': config.nonce
                }
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Failed to archive signup');
            }
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

    // Filter explorers list in Modal search
    const filteredExplorers = explorers.filter(exp => {
        if (!searchQuery) return true;
        const q = searchQuery.toLowerCase();
        const fullName = `${exp.first_name} ${exp.last_name}`.toLowerCase();
        const patrol = (exp.patrol || '').toLowerCase();
        return fullName.includes(q) || patrol.includes(q);
    });

    if (loading && signups.length === 0) {
        return (
            <div style={{ padding: '20px', background: '#fff', border: '1px solid #ccd0d4', borderRadius: '4px' }}>
                <p>Loading explorer signups...</p>
            </div>
        );
    }

    return (
        <div style={{ fontFamily: 'Inter, sans-serif', color: '#1d2327' }}>
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
                    <label style={{ display: 'inline-flex', alignItems: 'center', cursor: 'pointer', padding: '6px 12px', background: statusFilter === 'pending' ? '#e5f3ff' : '#fff', border: `1px solid ${statusFilter === 'pending' ? '#2271b1' : '#ccd0d4'}`, borderRadius: '20px', color: statusFilter === 'pending' ? '#1d2327' : '#646970', fontWeight: '500', transition: 'all 0.2s' }}>
                        <input
                            type="radio"
                            name="statusFilter"
                            value="pending"
                            checked={statusFilter === 'pending'}
                            onChange={() => setStatusFilter('pending')}
                            style={{ display: 'none' }}
                        />
                        Active (Pending)
                    </label>
                    <label style={{ display: 'inline-flex', alignItems: 'center', cursor: 'pointer', padding: '6px 12px', background: statusFilter === 'processed' ? '#e5f3ff' : '#fff', border: `1px solid ${statusFilter === 'processed' ? '#2271b1' : '#ccd0d4'}`, borderRadius: '20px', color: statusFilter === 'processed' ? '#1d2327' : '#646970', fontWeight: '500', transition: 'all 0.2s' }}>
                        <input
                            type="radio"
                            name="statusFilter"
                            value="processed"
                            checked={statusFilter === 'processed'}
                            onChange={() => setStatusFilter('processed')}
                            style={{ display: 'none' }}
                        />
                        Processed
                    </label>
                    <label style={{ display: 'inline-flex', alignItems: 'center', cursor: 'pointer', padding: '6px 12px', background: statusFilter === 'archived' ? '#e5f3ff' : '#fff', border: `1px solid ${statusFilter === 'archived' ? '#2271b1' : '#ccd0d4'}`, borderRadius: '20px', color: statusFilter === 'archived' ? '#1d2327' : '#646970', fontWeight: '500', transition: 'all 0.2s' }}>
                        <input
                            type="radio"
                            name="statusFilter"
                            value="archived"
                            checked={statusFilter === 'archived'}
                            onChange={() => setStatusFilter('archived')}
                            style={{ display: 'none' }}
                        />
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

            {/* Roster Table */}
            <div style={{ background: '#fff', border: '1px solid #ccd0d4', borderRadius: '8px', overflow: 'hidden', boxShadow: '0 2px 8px rgba(0,0,0,0.03)' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left' }}>
                    <thead>
                        <tr style={{ background: '#f6f7f7', borderBottom: '1px solid #ccd0d4', fontWeight: '600' }}>
                            <th style={{ padding: '12px 16px' }}>Name</th>
                            <th style={{ padding: '12px 16px' }}>Level</th>
                            <th style={{ padding: '12px 16px' }}>ESU Unit</th>
                            <th style={{ padding: '12px 16px' }}>Payment</th>
                            <th style={{ padding: '12px 16px' }}>Link Status</th>
                            <th style={{ padding: '12px 16px' }}>DofE Num</th>
                            <th style={{ padding: '12px 16px', textAlign: 'right' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredSignups.length === 0 ? (
                            <tr>
                                <td colSpan={7} style={{ padding: '24px', textAlign: 'center', color: '#646970' }}>
                                    No signup records found for this state.
                                </td>
                            </tr>
                        ) : (
                            filteredSignups.map((s) => {
                                const isLinked = s.linkage_status === 'linked';
                                const isProposed = s.linkage_status === 'proposed';
                                const isUnlinked = s.linkage_status === 'unlinked';
                                const isProcessed = s.signup_status === 'processed';
                                const isPaid = s.payment_status === 'paid';

                                return (
                                    <tr key={s.id} style={{ borderBottom: '1px solid #f0f0f1', transition: 'background-color 0.2s' }}>
                                        {/* Name */}
                                        <td style={{ padding: '16px' }}>
                                            <strong style={{ fontSize: '14px', color: '#1d2327' }}>
                                                {s.explorer_first_name} {s.explorer_last_name}
                                            </strong>
                                        </td>

                                        {/* Level */}
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

                                        {/* ESU Unit */}
                                        <td style={{ padding: '16px', color: s.unit_name === 'Unassigned' ? '#999' : '#1d2327' }}>
                                            {s.unit_name}
                                        </td>

                                        {/* Payment */}
                                        <td style={{ padding: '16px' }}>
                                            <span style={{
                                                fontSize: '13px',
                                                fontWeight: '600',
                                                color: isPaid ? '#00a32a' : '#d63638'
                                            }}>
                                                {isPaid ? 'Paid' : s.payment_status === 'failed' ? 'Failed' : 'Pending'}
                                            </span>
                                        </td>

                                        {/* Link Status */}
                                        <td style={{ padding: '16px' }}>
                                            <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', alignItems: 'flex-start' }}>
                                                {isLinked && (
                                                    <span style={{ color: '#00a32a', fontWeight: '500' }}>✅ Linked</span>
                                                )}
                                                {isProposed && (
                                                    <>
                                                        <span style={{ color: '#dba617', fontWeight: '500' }}>
                                                            🟡 Proposed (Fuzzy Match)
                                                        </span>
                                                        <button
                                                            onClick={() => handleReconcile(s.id, s.proposed_scout_id)}
                                                            className="button button-small"
                                                            style={{ marginTop: '2px', background: '#f0f6fc', border: '1px solid #d0e3f7', color: '#1a73e8' }}
                                                        >
                                                            Confirm Link
                                                        </button>
                                                    </>
                                                )}
                                                {isUnlinked && (
                                                    <>
                                                        <span style={{ color: '#d63638', fontWeight: '500' }}>❌ Unlinked</span>
                                                        <button
                                                            onClick={() => setLinkModalSignup(s)}
                                                            className="button button-small"
                                                            style={{ marginTop: '2px' }}
                                                        >
                                                            Link Explorer
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                        </td>

                                        {/* DofE Number */}
                                        <td style={{ padding: '16px', fontFamily: 'monospace' }}>
                                            {s.dofe_number || '—'}
                                        </td>

                                        {/* Record Actions */}
                                        <td style={{ padding: '16px', textAlign: 'right' }}>
                                            <div style={{ display: 'inline-flex', gap: '6px' }}>
                                                {!isProcessed && (
                                                    <button
                                                        onClick={() => handleProcess(s.id)}
                                                        disabled={isUnlinked || !isPaid}
                                                        className="button button-primary button-small"
                                                        style={{
                                                            background: isUnlinked || !isPaid ? '#a7aaad' : undefined,
                                                            borderColor: isUnlinked || !isPaid ? '#a7aaad' : undefined,
                                                            cursor: isUnlinked || !isPaid ? 'not-allowed' : 'pointer'
                                                        }}
                                                    >
                                                        Process
                                                    </button>
                                                )}
                                                <button
                                                    onClick={() => setLinkModalSignup(s)}
                                                    className="button button-secondary button-small"
                                                >
                                                    Re-Link
                                                </button>
                                                {s.signup_status !== 'archived' && (
                                                    <button
                                                        onClick={() => handleArchive(s.id)}
                                                        className="button button-link-delete button-small"
                                                        style={{ color: '#d63638', textDecoration: 'none' }}
                                                    >
                                                        Archive
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            {/* Manual Link Modal */}
            {linkModalSignup && (
                <div style={{
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    right: 0,
                    bottom: 0,
                    background: 'rgba(0,0,0,0.4)',
                    display: 'flex',
                    justifyContent: 'center',
                    alignItems: 'center',
                    zIndex: 99999,
                    backdropFilter: 'blur(2px)'
                }}>
                    <div style={{
                        background: '#fff',
                        width: '500px',
                        maxWidth: '90%',
                        borderRadius: '8px',
                        overflow: 'hidden',
                        boxShadow: '0 10px 25px rgba(0,0,0,0.15)',
                        border: '1px solid #ccd0d4'
                    }}>
                        {/* Header */}
                        <div style={{
                            padding: '16px 20px',
                            borderBottom: '1px solid #f0f0f1',
                            background: '#f6f7f7',
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center'
                        }}>
                            <h2 style={{ margin: 0, fontSize: '16px', fontWeight: '600' }}>
                                Link Signup to OSM Explorer
                            </h2>
                            <button
                                onClick={() => {
                                    setLinkModalSignup(null);
                                    setSearchQuery('');
                                }}
                                style={{
                                    border: 'none',
                                    background: 'transparent',
                                    fontSize: '20px',
                                    cursor: 'pointer',
                                    color: '#646970'
                                }}
                            >
                                &times;
                            </button>
                        </div>

                        {/* Search and Results */}
                        <div style={{ padding: '20px' }}>
                            <p style={{ margin: '0 0 12px 0', fontSize: '13px', color: '#646970' }}>
                                Searching for matches for <strong>{linkModalSignup.explorer_first_name} {linkModalSignup.explorer_last_name}</strong>.
                            </p>

                            <input
                                type="text"
                                placeholder="Search by name or patrol..."
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                                style={{
                                    width: '100%',
                                    padding: '8px 12px',
                                    border: '1px solid #ccd0d4',
                                    borderRadius: '4px',
                                    marginBottom: '16px',
                                    boxShadow: 'inset 0 1px 2px rgba(0,0,0,0.07)'
                                }}
                            />

                            <div style={{ maxHeight: '250px', overflowY: 'auto', border: '1px solid #ccd0d4', borderRadius: '4px' }}>
                                {filteredExplorers.length === 0 ? (
                                    <div style={{ padding: '16px', textAlign: 'center', color: '#646970' }}>
                                        No matching synced explorers found.
                                    </div>
                                ) : (
                                    filteredExplorers.map((exp) => (
                                        <div
                                            key={exp.scout_id}
                                            style={{
                                                display: 'flex',
                                                justifyContent: 'space-between',
                                                alignItems: 'center',
                                                padding: '10px 16px',
                                                borderBottom: '1px solid #f0f0f1',
                                                transition: 'background 0.2s'
                                            }}
                                        >
                                            <div>
                                                <strong style={{ fontSize: '13px' }}>{exp.first_name} {exp.last_name}</strong>
                                                <div style={{ fontSize: '11px', color: '#646970' }}>
                                                    Scout ID: {exp.scout_id} | Patrol: {exp.patrol || 'None'}
                                                </div>
                                            </div>
                                            <button
                                                onClick={() => handleReconcile(linkModalSignup.id, exp.scout_id)}
                                                className="button button-small button-primary"
                                            >
                                                Link
                                            </button>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>

                        {/* Footer */}
                        <div style={{
                            padding: '12px 20px',
                            borderTop: '1px solid #f0f0f1',
                            background: '#f6f7f7',
                            textAlign: 'right'
                        }}>
                            <button
                                onClick={() => {
                                    setLinkModalSignup(null);
                                    setSearchQuery('');
                                }}
                                className="button"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
