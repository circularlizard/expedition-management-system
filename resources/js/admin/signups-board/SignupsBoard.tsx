import React, { useState, useEffect, useRef } from 'react';

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

const normalize = (str: string): string => {
    return (str || '').trim().toLowerCase();
};

const firstNamesSimilar = (nameA: string, nameB: string): boolean => {
    const na = normalize(nameA);
    const nb = normalize(nameB);
    return na.length >= 3 && nb.length >= 3 && na.substring(0, 3) === nb.substring(0, 3);
};

export default function SignupsBoard({ type: _ignoredProp }: { type?: string } = {}) {
    const config = window.emsSignupsBoard || { root_url: '', nonce: '' };

    const [signups, setSignups] = useState<any[]>([]);
    const [explorers, setExplorers] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Filters
    const [statusFilter, setStatusFilter] = useState<string>('submitted');
    const [typeFilter, setTypeFilter] = useState<string>('all');
    const [levelFilter, setLevelFilter] = useState<string>('all');
    const [unitFilter, setUnitFilter] = useState<string>('all');

    // Grouping & Sorting
    const [grouping, setGrouping] = useState<'none' | 'unit' | 'level' | 'explorer' | 'parent'>('none');
    const [sortKey, setSortKey] = useState<string>('created_at');
    const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');

    // Selected signup & Inspector Panel
    const [selectedSignup, setSelectedSignup] = useState<any | null>(null);
    const [editedDofeNumber, setEditedDofeNumber] = useState<string>('');
    const [reconcileSearch, setReconcileSearch] = useState<string>('');
    const [unlinkError, setUnlinkError] = useState<string | null>(null);
    const inspectorRef = useRef<HTMLDivElement>(null);

    // Pagination
    const [currentPage, setCurrentPage] = useState<number>(1);
    const [itemsPerPage, setItemsPerPage] = useState<number>(25);

    useEffect(() => {
        setCurrentPage(1);
    }, [statusFilter, typeFilter, levelFilter, unitFilter, sortKey, sortOrder, grouping]);

    useEffect(() => {
        if (selectedSignup && inspectorRef.current) {
            inspectorRef.current.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
        }
    }, [selectedSignup]);

    const fetchData = async () => {
        setLoading(true);
        setError(null);
        try {
            // Fetch combined signups list
            const signupsRes = await fetch(`${config.root_url}/signups?status=${statusFilter}`, {
                headers: { 'X-WP-Nonce': config.nonce }
            });
            if (!signupsRes.ok) {
                throw new Error('Failed to fetch signups');
            }
            const signupsData = await signupsRes.json();
            setSignups(signupsData);

            // Fetch synced explorers roster for matches
            const explorersRes = await fetch(`${config.root_url}/explorers`, {
                headers: { 'X-WP-Nonce': config.nonce }
            });
            if (explorersRes.ok) {
                const explorersData = await explorersRes.json();
                setExplorers(explorersData);
            }
        } catch (err: any) {
            setError(err.message || 'Error fetching data');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchData();
        setSelectedSignup(null);
        setUnlinkError(null);
    }, [statusFilter]);

    useEffect(() => {
        if (selectedSignup) {
            setEditedDofeNumber(selectedSignup.dofe_number || '');
            setUnlinkError(null);
            setReconcileSearch('');
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

    const handleReconcile = async (signupId: number, signupType: string, scoutId: number) => {
        try {
            const response = await fetch(`${config.root_url}/signups/reconcile`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    signup_id: signupId,
                    signup_type: signupType,
                    scout_id: scoutId
                })
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Failed to confirm match');
            }
            setSelectedSignup(null);
            fetchData();
        } catch (err: any) {
            alert(err.message);
        }
    };

    const handleUnlink = async (signupId: number, signupType: string) => {
        setUnlinkError(null);
        try {
            const response = await fetch(`${config.root_url}/signups/unlink`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    signup_id: signupId,
                    signup_type: signupType
                })
            });
            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Failed to unlink matched profile');
            }
            setSelectedSignup(null);
            fetchData();
        } catch (err: any) {
            setUnlinkError(err.message);
        }
    };

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
                throw new Error(data.message || 'Failed to allocate slot');
            }
            setSelectedSignup(null);
            fetchData();
        } catch (err: any) {
            alert(err.message);
        }
    };

    const handleArchive = async (signupId: number, signupType: string) => {
        if (!confirm('Are you sure you want to archive this signup?')) return;
        try {
            const endpoint = signupType === 'participant' ? 'participants' : 'expeditions';
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
            fetchData();
        } catch (err: any) {
            alert(err.message);
        }
    };

    // Helper: list matching suggestions with Sibling Collision Guard
    const getMatchSuggestions = (signup: any) => {
        if (!signup || signup.scout_id !== 0) return [];
        const sf = normalize(signup.explorer_first_name);
        const sl = normalize(signup.explorer_last_name);
        const se = normalize(signup.explorer_email);
        const sp = normalize(signup.parent_email);

        return explorers.filter(e => {
            const ef = normalize(e.first_name);
            const el = normalize(e.last_name);
            const ee = normalize(e.email);
            const ep = normalize(e.parent_email);

            // 1. Exact first and last name match
            if (sf === ef && sl === el) return true;

            // 2. Exact explorer email match (if present)
            if (se && se === ee) return true;

            // 3. Parent email match AND first name similarity (sibling guard)
            if (sp && sp === ep && firstNamesSimilar(signup.explorer_first_name, e.first_name)) {
                return true;
            }

            return false;
        });
    };

    // Filter signups in memory
    const filteredSignups = signups.filter(s => {
        if (typeFilter !== 'all' && s.type !== typeFilter) return false;
        if (levelFilter !== 'all' && (s.dofe_level || '').toLowerCase() !== levelFilter.toLowerCase()) return false;
        if (unitFilter !== 'all' && (s.unit_name || 'Unassigned') !== unitFilter) return false;
        return true;
    });

    // Unique units for ESU filter
    const uniqueUnits = Array.from(new Set(signups.map(s => s.unit_name || 'Unassigned'))).filter(Boolean);

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

    // Sort signups
    const sortedSignups = [...filteredSignups].sort((a, b) => {
        let valA = getSortValue(a, sortKey);
        let valB = getSortValue(b, sortKey);
        if (valA < valB) return sortOrder === 'asc' ? -1 : 1;
        if (valA > valB) return sortOrder === 'asc' ? 1 : -1;
        return 0;
    });

    // In-memory Grouping
    const getGroupedSignups = () => {
        if (grouping === 'none') {
            return [{ title: null, items: sortedSignups }];
        }

        const sortGroupItems = (items: any[]) => {
            return [...items].sort((a, b) => {
                const lastA = (a.explorer_last_name || '').toLowerCase();
                const lastB = (b.explorer_last_name || '').toLowerCase();
                if (lastA < lastB) return -1;
                if (lastA > lastB) return 1;
                const firstA = (a.explorer_first_name || '').toLowerCase();
                const firstB = (b.explorer_first_name || '').toLowerCase();
                if (firstA < firstB) return -1;
                if (firstA > firstB) return 1;
                return 0;
            });
        };

        if (grouping === 'explorer') {
            const explorerGroups: { title: string; keys: Set<string>; items: any[] }[] = [];

            sortedSignups.forEach(s => {
                let matchedGroup = null;

                const scoutId = Number(s.scout_id || 0);
                if (scoutId !== 0) {
                    const sIdStr = String(scoutId);
                    matchedGroup = explorerGroups.find(g => g.keys.has(`scout_${sIdStr}`));
                } else {
                    const email = s.explorer_email ? normalize(s.explorer_email) : '';
                    const name = `${normalize(s.explorer_first_name)}_${normalize(s.explorer_last_name)}`;
                    const parent = s.parent_email ? normalize(s.parent_email) : '';

                    matchedGroup = explorerGroups.find(g => {
                        const hasEmailMatch = email && g.keys.has(`email_${email}`);
                        const hasNameMatch = name && parent && g.keys.has(`name_${parent}_${name}`);
                        return hasEmailMatch || hasNameMatch;
                    });
                }

                const itemKeys = new Set<string>();
                if (scoutId !== 0) {
                    itemKeys.add(`scout_${scoutId}`);
                } else {
                    if (s.explorer_email) {
                        itemKeys.add(`email_${normalize(s.explorer_email)}`);
                    }
                    const name = `${normalize(s.explorer_first_name)}_${normalize(s.explorer_last_name)}`;
                    const parent = s.parent_email ? normalize(s.parent_email) : '';
                    if (name && parent) {
                        itemKeys.add(`name_${parent}_${name}`);
                    }
                }

                if (matchedGroup) {
                    itemKeys.forEach(k => matchedGroup.keys.add(k));
                    matchedGroup.items.push(s);
                } else {
                    const title = scoutId !== 0 
                        ? `${s.explorer_first_name} ${s.explorer_last_name} (Scout ID: ${scoutId})`
                        : `${s.explorer_first_name} ${s.explorer_last_name} (Guest)`;

                    explorerGroups.push({
                        title,
                        keys: itemKeys,
                        items: [s]
                    });
                }
            });

            // Sort explorer groups alphabetically by title
            return explorerGroups.sort((a, b) => {
                return a.title.localeCompare(b.title);
            }).map(g => ({
                title: g.title,
                items: sortGroupItems(g.items)
            }));
        }

        const groupsMap: { [key: string]: { title: string, items: any[] } } = {};
        sortedSignups.forEach(s => {
            let key = '';
            let title = '';
            if (grouping === 'unit') {
                key = `unit_${s.unit_name || 'Unassigned'}`;
                title = `Unit: ${s.unit_name || 'Unassigned'}`;
            } else if (grouping === 'level') {
                key = `level_${s.dofe_level || 'Unknown'}`;
                title = `Level: ${s.dofe_level || 'Unknown'}`;
            } else if (grouping === 'parent') {
                const parentUserId = Number(s.parent_user_id || 0);
                if (parentUserId !== 0) {
                    key = `user_${parentUserId}`;
                    title = `Parent ID: ${parentUserId} (${s.parent_email || 'No email'})`;
                } else {
                    const email = normalize(s.parent_email || 'unknown');
                    key = `email_${email}`;
                    title = `Parent: ${s.parent_email || 'No Email'}`;
                }
            }

            if (!groupsMap[key]) {
                groupsMap[key] = { title, items: [] };
            }
            groupsMap[key].items.push(s);
        });

        // Sort groups alphabetically by their title
        return Object.keys(groupsMap).sort((a, b) => {
            return groupsMap[a].title.localeCompare(groupsMap[b].title);
        }).map(key => ({
            title: groupsMap[key].title,
            items: sortGroupItems(groupsMap[key].items)
        }));
    };

    const groupedSignups = getGroupedSignups();

    const totalItems = grouping === 'none' ? sortedSignups.length : groupedSignups.length;
    const totalPages = Math.ceil(totalItems / itemsPerPage);

    // Paginate grouped signups directly to keep groups together on a page
    const getPaginatedGroupedSignups = () => {
        if (grouping === 'none') {
            const flatSlice = sortedSignups.slice(
                (currentPage - 1) * itemsPerPage,
                currentPage * itemsPerPage
            );
            return [{ title: null, items: flatSlice }];
        }
        return groupedSignups.slice(
            (currentPage - 1) * itemsPerPage,
            currentPage * itemsPerPage
        );
    };

    const paginatedGroupedSignups = getPaginatedGroupedSignups();

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

    // Traversal queue inside inspector panel
    const activeUnprocessedSignups = sortedSignups.filter(s => s.signup_status === 'submitted');
    const currentUnprocessedIndex = selectedSignup ? activeUnprocessedSignups.findIndex(s => s.id === selectedSignup.id) : -1;

    const handlePrevSignup = () => {
        if (currentUnprocessedIndex > 0) {
            setSelectedSignup(activeUnprocessedSignups[currentUnprocessedIndex - 1]);
        }
    };

    const handleNextSignup = () => {
        if (currentUnprocessedIndex >= 0 && currentUnprocessedIndex < activeUnprocessedSignups.length - 1) {
            setSelectedSignup(activeUnprocessedSignups[currentUnprocessedIndex + 1]);
        }
    };

    // Helper to render completions badges
    const renderPriorCompletions = (signup: any) => {
        if (signup.dofe_level === 'bronze') {
            return <span title="No Prior Award">❌</span>;
        }

        const completions = signup.dofe_level === 'silver' ? signup.bronze_completion : signup.silver_completion;
        if (!completions) {
            return <span title="No Prior Award">❌</span>;
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
            return <span title="No Prior Award">❌</span>;
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

    // Suggestions matching target
    const currentSuggestions = selectedSignup ? getMatchSuggestions(selectedSignup) : [];

    // Filtered list of explorers for manual select input
    const manualExplorerOptions = explorers.filter(e => {
        if (!reconcileSearch) return true;
        const s = normalize(reconcileSearch);
        const name = `${e.first_name} ${e.last_name}`;
        return normalize(name).includes(s) || String(e.scout_id).includes(s);
    });

    return (
        <div className="ems-signups-container">
            {/* Main Content Area */}
            <div className="ems-signups-main">
                {error && <div className="ems-error-notice">{error}</div>}

                {/* Filter and Control Bar */}
                <div className="ems-signups-toolbar">
                    {/* Status Tabs */}
                    <div className="ems-flex-center ems-gap-8">
                        <label className={`ems-filter-pill ${statusFilter === 'submitted' ? 'ems-filter-pill--active' : ''}`}>
                            <input 
                                type="radio" 
                                name="statusFilter" 
                                value="submitted" 
                                checked={statusFilter === 'submitted'} 
                                onChange={() => setStatusFilter('submitted')} 
                            />
                            Submitted
                        </label>
                        <label className={`ems-filter-pill ${statusFilter === 'archived' ? 'ems-filter-pill--active' : ''}`}>
                            <input 
                                type="radio" 
                                name="statusFilter" 
                                value="archived" 
                                checked={statusFilter === 'archived'} 
                                onChange={() => setStatusFilter('archived')} 
                            />
                            Archived
                        </label>
                    </div>

                    {/* Filters & Grouping Dropdowns */}
                    <div className="ems-flex-center ems-gap-16 ems-wrap">
                        {/* Group By selector */}
                        <div className="ems-flex-center ems-gap-8">
                            <label htmlFor="grouping-select" className="ems-toolbar__label">Group By:</label>
                            <select
                                id="grouping-select"
                                value={grouping}
                                onChange={(e) => setGrouping(e.target.value as any)}
                                className="ems-select"
                            >
                                <option value="none">Ungrouped</option>
                                <option value="explorer">Explorer</option>
                                <option value="parent">Parent</option>
                                <option value="unit">Unit</option>
                                <option value="level">Level</option>
                            </select>
                        </div>

                        {/* Form Type selector */}
                        <div className="ems-flex-center ems-gap-8">
                            <label htmlFor="type-filter" className="ems-toolbar__label">Form Type:</label>
                            <select
                                id="type-filter"
                                value={typeFilter}
                                onChange={(e) => setTypeFilter(e.target.value)}
                                className="ems-select"
                            >
                                <option value="all">All Types</option>
                                <option value="participant">Participant Place</option>
                                <option value="expedition">Expedition Preference</option>
                            </select>
                        </div>

                        {/* Level selector */}
                        <div className="ems-flex-center ems-gap-8">
                            <label htmlFor="level-filter" className="ems-toolbar__label">Filter Level:</label>
                            <select
                                id="level-filter"
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

                        {/* Unit selector */}
                        <div className="ems-flex-center ems-gap-8">
                            <label htmlFor="unit-filter" className="ems-toolbar__label">Unit:</label>
                            <select
                                id="unit-filter"
                                value={unitFilter}
                                onChange={(e) => setUnitFilter(e.target.value)}
                                className="ems-select"
                            >
                                <option value="all">All Units</option>
                                {uniqueUnits.map(u => (
                                    <option key={u} value={u}>{u}</option>
                                ))}
                            </select>
                        </div>

                        <button
                            type="button"
                            className="button button-secondary"
                            onClick={() => {
                                const exportUrl = `${config.root_url}/signups/participants/export?status=${statusFilter}&level=${levelFilter}&_wpnonce=${config.nonce}`;
                                window.location.href = exportUrl;
                            }}
                        >
                            Export CSV
                        </button>
                    </div>
                </div>

                {/* Table Grid */}
                <div className="ems-panel">
                    <div className="ems-signups-table-wrap">
                        <table className="ems-table">
                            <thead>
                                <tr>
                                    <th className="ems-table-cell--center ems-m-0"></th>
                                    {renderHeader('Form Type', 'type')}
                                    {renderHeader('Submission Date', 'created_at')}
                                    {renderHeader('Explorer Name', 'explorer_first_name')}
                                    {renderHeader('Level', 'dofe_level')}
                                    {renderHeader('ESU', 'unit_name')}
                                    {renderHeader('Email', 'explorer_email')}
                                    {renderHeader('First Aid', 'first_aid_status')}
                                    {renderHeader('DofE Number', 'dofe_number')}
                                </tr>
                            </thead>
                            <tbody>
                                {paginatedGroupedSignups.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="ems-table-cell--center ems-p-20 ems-meta-text ems-italic">
                                            No signup records found for this filter state.
                                        </td>
                                    </tr>
                                ) : (
                                    paginatedGroupedSignups.map(g => (
                                        <React.Fragment key={g.title || 'flat'}>
                                            {g.title && (
                                                <tr className="ems-table-group-header">
                                                    <td colSpan={9}><strong>{g.title}</strong></td>
                                                </tr>
                                            )}
                                            {g.items.map((s) => (
                                                <tr 
                                                    key={`${s.type}_${s.id}`} 
                                                    onClick={() => setSelectedSignup(s)}
                                                    className={`ems-row-hoverable ${selectedSignup && selectedSignup.id === s.id && selectedSignup.type === s.type ? 'ems-table-row--selected' : ''}`}
                                                >
                                                    <td className="ems-table-cell--center">
                                                        {s.scout_id !== 0 ? (
                                                            <span title="Synced with OSM" className="ems-fa-full">✓</span>
                                                        ) : (
                                                            <span title="Guest Roster / Unmatched" className="ems-fa-warning">⚠️</span>
                                                        )}
                                                    </td>
                                                    <td>
                                                        <span className={`ems-status-badge ems-status-badge--${s.type}`}>
                                                            {s.type === 'participant' ? 'Place' : 'Exped'}
                                                        </span>
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
                                                    <td>{s.unit_name || 'Unassigned'}</td>
                                                    <td>{s.explorer_email || '—'}</td>
                                                    <td>{s.first_aid_status || '—'}</td>
                                                    <td className="ems-monospace">
                                                        {s.dofe_number || '—'}
                                                        {s.dofe_registered === 'y-other' && (
                                                            <div className="ems-signups-transfer-warning">
                                                                ⚠️ Transfer Req.
                                                            </div>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </React.Fragment>
                                    ))
                                )}
                            </tbody>
                        </table>

                        {/* Pagination Bar */}
                        {totalItems > 0 && (
                            <div className="ems-table-pagination">
                                <div className="ems-meta-text">
                                    Showing {((currentPage - 1) * itemsPerPage) + 1}–{Math.min(currentPage * itemsPerPage, totalItems)} of {totalItems} {grouping === 'none' ? 'records' : (grouping === 'explorer' ? 'explorers' : 'groups')}
                                </div>
                                <div className="ems-flex-center ems-gap-8">
                                    <label htmlFor="items-per-page" className="ems-toolbar__label">Items per page:</label>
                                    <select
                                        id="items-per-page"
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
                </div>
            </div>

            {/* Inspector Panel - Slide-Out/Docked Layout */}
            {selectedSignup && (
                <div ref={inspectorRef} className={`ems-signups-inspector ems-signups-inspector--${selectedSignup.type}`}>
                    {/* Header */}
                    <div className={`ems-signups-inspector__header ems-signups-inspector__header--${selectedSignup.type}`}>
                        <div className="ems-flex-center ems-gap-6">
                            <span style={{ fontSize: '18px' }}>
                                {selectedSignup.type === 'participant' ? '📋' : '🏕️'}
                            </span>
                            <h3 className="ems-m-0 ems-font-semibold" style={{ fontSize: '15px', color: '#1d2327' }}>
                                {selectedSignup.type === 'participant' ? 'Participant Place Details' : 'Expedition Preference Details'}
                            </h3>
                        </div>
                        <div className="ems-flex-center ems-gap-4">
                            {currentUnprocessedIndex >= 0 && (
                                <>
                                    <button 
                                        onClick={handlePrevSignup} 
                                        disabled={currentUnprocessedIndex === 0}
                                        className="button button-secondary button-small"
                                        title="Previous Signup"
                                    >
                                        &lt;
                                    </button>
                                    <button 
                                        onClick={handleNextSignup} 
                                        disabled={currentUnprocessedIndex === activeUnprocessedSignups.length - 1}
                                        className="button button-secondary button-small"
                                        title="Next Signup"
                                    >
                                        &gt;
                                    </button>
                                </>
                            )}
                            <button 
                                onClick={() => setSelectedSignup(null)}
                                className="button-link"
                                style={{ fontSize: '20px', marginLeft: '8px', color: '#1d2327', textDecoration: 'none', cursor: 'pointer' }}
                            >
                                &times;
                            </button>
                        </div>
                    </div>

                    {/* Details Body */}
                    <div className="ems-signups-inspector__body">
                        {/* Matching Widget */}
                        <div className="ems-signups-matching-panel ems-mb-16">
                            <div className="ems-signups-inspector__section-title" style={{ marginTop: 0 }}>OSM Profile Linkage</div>
                            {selectedSignup.scout_id !== 0 ? (
                                <div className="ems-mt-8">
                                    <div className="ems-flex-between ems-align-center">
                                        <div className="ems-small-text">
                                            Linked to Scout ID: <strong>{selectedSignup.scout_id}</strong>
                                        </div>
                                        <button 
                                            type="button"
                                            className="button button-secondary button-small"
                                            onClick={() => handleUnlink(selectedSignup.id, selectedSignup.type)}
                                        >
                                            Unlink OSM Profile
                                        </button>
                                    </div>
                                    {unlinkError && (
                                        <div className="ems-error-notice ems-mt-8 ems-small-text">
                                            {unlinkError}
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="ems-mt-8">
                                    <div className="ems-signups-unmatched-alert">
                                        ⚠️ Unmatched guest registration.
                                    </div>
                                    
                                    {/* Recommendations */}
                                    {currentSuggestions.length > 0 && (
                                        <div className="ems-mt-8">
                                            <strong className="ems-small-text">Suggested matches:</strong>
                                            <div className="ems-signups-suggestions-list ems-mt-4">
                                                {currentSuggestions.map(sugg => (
                                                    <div key={sugg.scout_id} className="ems-flex-between ems-align-center ems-py-4">
                                                        <div className="ems-small-text">
                                                            {sugg.first_name} {sugg.last_name} ({sugg.patrol || 'No Patrol'}) - ID: {sugg.scout_id}
                                                        </div>
                                                        <button
                                                            type="button"
                                                            className="button button-small button-primary"
                                                            onClick={() => handleReconcile(selectedSignup.id, selectedSignup.type, sugg.scout_id)}
                                                        >
                                                            Confirm Match
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {/* Search Dropdown for manual reconciliation */}
                                    <div className="ems-mt-8">
                                        <label htmlFor="reconcile-search" className="ems-small-text ems-font-semibold">Search Synced OSM Explorers:</label>
                                        <input 
                                            id="reconcile-search"
                                            type="text" 
                                            placeholder="Type name or Scout ID..."
                                            value={reconcileSearch}
                                            onChange={(e) => setReconcileSearch(e.target.value)}
                                            className="ems-signups-inspector__input ems-mt-4"
                                        />
                                        {reconcileSearch && (
                                            <div className="ems-signups-search-dropdown ems-mt-4">
                                                {manualExplorerOptions.length === 0 ? (
                                                    <div className="ems-p-8 ems-meta-text ems-small-text">No matching explorers.</div>
                                                ) : (
                                                    manualExplorerOptions.slice(0, 5).map(e => (
                                                        <div 
                                                            key={e.scout_id} 
                                                            onClick={() => handleReconcile(selectedSignup.id, selectedSignup.type, e.scout_id)}
                                                            className="ems-search-dropdown-option ems-flex-between ems-align-center ems-p-8 ems-cursor-pointer"
                                                        >
                                                            <div className="ems-small-text">
                                                                {e.first_name} {e.last_name} ({e.patrol || 'No Patrol'})
                                                            </div>
                                                            <div className="ems-monospace ems-small-text">ID: {e.scout_id}</div>
                                                        </div>
                                                    ))
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Explorer Profile Details */}
                        <div className="ems-signups-inspector__section-title">Explorer Profile</div>
                        <div className="ems-signups-detail-grid">
                            <div className="ems-signups-detail-item ems-signups-detail-item--full">
                                <span className="ems-signups-inspector__label">Name</span>
                                <div className="ems-signups-inspector__value--large">
                                    {selectedSignup.explorer_first_name} {selectedSignup.explorer_last_name}
                                </div>
                            </div>
                            
                            <div className="ems-signups-detail-item">
                                <span className="ems-signups-inspector__label">Scout ID</span>
                                <div className="ems-signups-inspector__value ems-monospace ems-font-semibold">
                                    {selectedSignup.scout_id || 'Unmatched'}
                                </div>
                            </div>

                            <div className="ems-signups-detail-item">
                                <span className="ems-signups-inspector__label">Status</span>
                                <div className="ems-mt-4">
                                    <span className={`ems-status-badge ems-status-badge--${selectedSignup.signup_status}`}>
                                        {selectedSignup.signup_status}
                                    </span>
                                </div>
                            </div>

                            <div className="ems-signups-detail-item">
                                <span className="ems-signups-inspector__label">Level</span>
                                <div className="ems-mt-4">
                                    <span className={`ems-pill ems-pill--${selectedSignup.dofe_level}`}>
                                        {selectedSignup.dofe_level}
                                    </span>
                                </div>
                            </div>

                            <div className="ems-signups-detail-item">
                                <span className="ems-signups-inspector__label">Unit</span>
                                <div className="ems-signups-inspector__value ems-font-semibold">
                                    {selectedSignup.unit_name || 'Unassigned'}
                                </div>
                            </div>
                        </div>

                        {/* Form Specific Details */}
                        <div className="ems-signups-inspector__section-title">Form Submissions</div>
                        <div className="ems-signups-detail-grid">
                            {selectedSignup.type === 'participant' ? (
                                <>
                                    <div className="ems-signups-detail-item">
                                        <span className="ems-signups-inspector__label">DofE Registration Status</span>
                                        <div className="ems-signups-inspector__value">
                                            {selectedSignup.dofe_registered === 'y' ? 'Registered' : (selectedSignup.dofe_registered === 'y-other' ? 'Registered (Other)' : 'Needs Registration')}
                                        </div>
                                    </div>

                                    <div className="ems-signups-detail-item">
                                        <span className="ems-signups-inspector__label">DOB</span>
                                        <div className="ems-signups-inspector__value">{selectedSignup.dob || '—'}</div>
                                    </div>

                                    {selectedSignup.dofe_registered === 'y-other' && (
                                        <div className="ems-signups-detail-item ems-signups-detail-item--full">
                                            <div className="ems-signups-inspector__transfer-alert">
                                                <div className="ems-flex-center ems-gap-4">
                                                    <span>⚠️</span> <strong>Transfer Required</strong>
                                                </div>
                                                <div className="ems-font-normal ems-meta-text ems-small-text ems-mt-4">
                                                    From: <strong className="ems-font-semibold">{selectedSignup.dofe_org || 'Unknown Organisation'}</strong>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    <div className="ems-signups-detail-item ems-signups-detail-item--full">
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

                                    <div className="ems-signups-detail-item ems-signups-detail-item--full">
                                        <span className="ems-signups-inspector__label">Prior Level Completions</span>
                                        <div className="ems-mt-4">
                                            {renderPriorCompletions(selectedSignup)}
                                        </div>
                                    </div>

                                    <div className="ems-signups-detail-item">
                                        <span className="ems-signups-inspector__label">Payment Status</span>
                                        <div className="ems-mt-4">
                                            <span className={`ems-status-badge ems-status-badge--${selectedSignup.payment_status}`}>
                                                {selectedSignup.payment_status === 'paid' ? 'Paid' : selectedSignup.payment_status === 'failed' ? 'Failed' : 'Pending'}
                                            </span>
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <>
                                    <div className="ems-signups-detail-item">
                                        <span className="ems-signups-inspector__label">Expedition Mode</span>
                                        <div className="ems-signups-inspector__value ems-font-semibold ems-flex-center ems-gap-4">
                                            {selectedSignup.expedition_preferences?.exped_type === 'Hillwalking' ? '🥾' : selectedSignup.expedition_preferences?.exped_type === 'Biking' ? '🚲' : '🛶'} {selectedSignup.expedition_preferences?.exped_type || '—'}
                                        </div>
                                    </div>

                                    <div className="ems-signups-detail-item">
                                        <span className="ems-signups-inspector__label">eDofE Number</span>
                                        <div className="ems-signups-inspector__value ems-font-semibold ems-monospace">{selectedSignup.dofe_number || '—'}</div>
                                    </div>

                                    <div className="ems-signups-detail-item">
                                        <span className="ems-signups-inspector__label">First Aid Status</span>
                                        <div className="ems-signups-inspector__value ems-font-semibold">{selectedSignup.first_aid_status}</div>
                                    </div>

                                    <div className="ems-signups-detail-item">
                                        <span className="ems-signups-inspector__label">First Aid Expiry</span>
                                        <div className="ems-signups-inspector__value">{selectedSignup.first_aid_expiry || '—'}</div>
                                    </div>

                                    <div className="ems-signups-detail-item ems-signups-detail-item--full">
                                        <span className="ems-signups-inspector__label">Additional Support Needs</span>
                                        <div className="ems-signups-inspector__support-box">
                                            {selectedSignup.additional_support_needs || 'None declared.'}
                                        </div>
                                    </div>

                                    <div className="ems-signups-detail-item ems-signups-detail-item--full">
                                        <span className="ems-signups-inspector__label">Expedition Preferences</span>
                                        <div className="ems-signups-inspector__support-box" style={{ fontStyle: 'normal' }}>
                                            {selectedSignup.expedition_preferences ? (
                                                <div className="ems-flex-col ems-gap-8 ems-small-text">
                                                    <div><strong>Practice:</strong> {selectedSignup.expedition_preferences.exped_practice_dates || '—'}</div>
                                                    <div><strong>Qualifier:</strong> {selectedSignup.expedition_preferences.exped_qualifier_dates || '—'}</div>
                                                    <div><strong>Teammates:</strong> {selectedSignup.expedition_preferences.exped_team_names || '—'}</div>
                                                </div>
                                            ) : (
                                                <div className="ems-meta-text ems-italic">No preferences specified.</div>
                                            )}
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Contacts Block */}
                        <div className="ems-signups-inspector__section-title">Contacts</div>
                        <div className="ems-signups-detail-grid">
                            <div className="ems-signups-detail-item">
                                <span className="ems-signups-inspector__label">Explorer Email</span>
                                <div className="ems-signups-inspector__value">{selectedSignup.explorer_email || '—'}</div>
                            </div>

                            <div className="ems-signups-detail-item">
                                <span className="ems-signups-inspector__label">Parent Email</span>
                                <div className="ems-signups-inspector__value">{selectedSignup.parent_email || '—'}</div>
                            </div>

                            <div className="ems-signups-detail-item ems-signups-detail-item--full">
                                <span className="ems-signups-inspector__label">Leader Email</span>
                                <div className="ems-signups-inspector__value">{selectedSignup.leader_email || '—'}</div>
                            </div>
                        </div>

                        {/* Submission Metadata */}
                        <div className="ems-signups-inspector__section-title">Submission Info</div>
                        <div className="ems-signups-detail-grid" style={{ marginBottom: '16px' }}>
                            <div className="ems-signups-detail-item">
                                <span className="ems-signups-inspector__label">Submission ID</span>
                                <div className="ems-signups-inspector__value">#{selectedSignup.form_submission_id || '—'}</div>
                            </div>
                            <div className="ems-signups-detail-item">
                                <span className="ems-signups-inspector__label">Submitted At</span>
                                <div className="ems-signups-inspector__value">{selectedSignup.created_at || '—'}</div>
                            </div>
                        </div>
                    </div>

                    {/* Actions Footer */}
                    <div className="ems-signups-inspector__footer">
                        <div>
                            {selectedSignup.signup_status !== 'archived' && (
                                <button 
                                    onClick={() => handleArchive(selectedSignup.id, selectedSignup.type)}
                                    className="button button-link-delete"
                                >
                                    Archive
                                </button>
                            )}
                        </div>
                        <div className="ems-flex-center ems-gap-6">
                            <button onClick={() => setSelectedSignup(null)} className="button">Close</button>
                            {selectedSignup.type === 'participant' && !selectedSignup.dofe_number && (
                                <button 
                                    onClick={() => handleProcessParticipant(selectedSignup.id, editedDofeNumber)}
                                    className="button button-primary"
                                    disabled={!selectedSignup.scout_id} // Disable slot allocation for unmatched guest signups
                                    title={!selectedSignup.scout_id ? 'You must match this guest to an OSM profile before allocating a place.' : ''}
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
