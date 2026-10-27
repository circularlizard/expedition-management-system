import React, { useState, useEffect, useMemo } from 'react';
import { groupSignupsByExplorerIdentity } from '../admin/signups-board/dashboardMetrics';

declare global {
    interface Window {
        emsLeaderDashboard?: {
            root_url: string;
            nonce: string;
            plugin_url?: string;
            login_url: string;
            logout_url: string;
            user_data: {
                logged_in: boolean;
                display_name: string;
                can_access: boolean;
            };
        };
    }
}

const normalize = (str: string) => (str || '').trim().toLowerCase();

export const LeaderDashboard: React.FC = () => {
    const config = window.emsLeaderDashboard || {
        root_url: '',
        nonce: '',
        plugin_url: '/wp-content/plugins/ems-plugin/',
        login_url: '/wp-login.php',
        logout_url: '/wp-login.php?action=logout',
        user_data: {
            logged_in: false,
            display_name: '',
            can_access: false,
        },
    };

    const [signups, setSignups] = useState<any[]>([]);
    const [loading, setLoading] = useState<boolean>(true);
    const [error, setError] = useState<string>('');

    const [grouping, setGrouping] = useState<'none' | 'level' | 'expedition' | 'esu'>('esu');
    const [levelFilter, setLevelFilter] = useState<string>('all');
    const [expeditionTypeFilter, setExpeditionTypeFilter] = useState<string>('all');
    const [unitFilter, setUnitFilter] = useState<string>('all');
    const [sortKey, setSortKey] = useState<string>('explorer_first_name');
    const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('asc');
    const [currentPage, setCurrentPage] = useState<number>(1);
    const [itemsPerPage, setItemsPerPage] = useState<number>(25);

    useEffect(() => {
        setCurrentPage(1);
    }, [grouping, levelFilter, expeditionTypeFilter, unitFilter, sortKey, sortOrder]);

    useEffect(() => {
        if (!config.user_data.logged_in || !config.user_data.can_access) {
            setLoading(false);
            return;
        }

        const fetchSignups = async () => {
            setLoading(true);
            try {
                const res = await fetch(`${config.root_url}/leader-dashboard/signups`, {
                    headers: { 'X-WP-Nonce': config.nonce },
                });
                const data = await res.json();
                if (!res.ok || (data && data.code && data.message)) {
                    throw new Error(data.message || 'Failed to load explorer signups.');
                }
                setSignups(Array.isArray(data) ? data : []);
            } catch (err: any) {
                setError(err.message || 'Failed to load explorer signups.');
            } finally {
                setLoading(false);
            }
        };

        fetchSignups();
    }, []);

    const filteredSignups = useMemo(() => {
        return signups.filter((s) => {
            if (levelFilter !== 'all' && normalize(s.dofe_level) !== normalize(levelFilter)) {
                return false;
            }
            if (expeditionTypeFilter !== 'all') {
                const expedType = normalize(s.expedition_preferences?.exped_type || '');
                if (expedType !== normalize(expeditionTypeFilter)) {
                    return false;
                }
            }
            if (unitFilter !== 'all' && (s.unit_name || 'Unassigned') !== unitFilter) {
                return false;
            }
            return true;
        });
    }, [signups, levelFilter, expeditionTypeFilter, unitFilter]);

    const explorerRows = useMemo(() => {
        const buckets = groupSignupsByExplorerIdentity(filteredSignups);
        const rows: any[] = [];

        buckets.forEach((bucket, idx) => {
            const rawLevels = Array.from(
                new Set(bucket.items.map((i: any) => (i.dofe_level || '').trim().toLowerCase()))
            ).filter(Boolean);
            const levels = rawLevels.length > 0 ? rawLevels : [''];

            levels.forEach((lvl) => {
                const participantEntries = bucket.items.filter(
                    (i: any) => i.type === 'participant' && (lvl === '' || (i.dofe_level || '').trim().toLowerCase() === lvl)
                );
                const expeditionEntries = bucket.items.filter(
                    (i: any) => i.type === 'expedition' && (lvl === '' || (i.dofe_level || '').trim().toLowerCase() === lvl)
                );
                const entries = [...participantEntries, ...expeditionEntries];
                if (entries.length === 0) return;

                const primary = entries[0] || bucket.items[0];
                const expedWithPrefs = expeditionEntries.find((e: any) => e.expedition_preferences?.exped_type);

                const baseKey = bucket.scoutId !== 0 ? `scout_${bucket.scoutId}` : `explorer_${primary.type}_${primary.id}_${idx}`;
                rows.push({
                    rowKey: `${baseKey}_lvl_${lvl || 'none'}_p${participantEntries.length}_e${expeditionEntries.length}`,
                    scout_id: bucket.scoutId,
                    explorer_first_name: primary.explorer_first_name || '',
                    explorer_last_name: primary.explorer_last_name || '',
                    unit_name: entries.find((e: any) => e.unit_name && e.unit_name !== 'Unassigned')?.unit_name || primary.unit_name || 'Unassigned',
                    dofe_level: lvl || (primary.dofe_level || '').toLowerCase(),
                    expedition_preferences: expedWithPrefs ? expedWithPrefs.expedition_preferences : null,
                    participantEntries,
                    expeditionEntries,
                });
            });
        });

        return rows;
    }, [filteredSignups]);

    const uniqueUnits = useMemo(() => {
        return Array.from(new Set(signups.map((s) => s.unit_name || 'Unassigned')))
            .filter(Boolean)
            .sort((a, b) => a.localeCompare(b));
    }, [signups]);

    const getSortValue = (item: any, key: string) => {
        if (key === 'osm_sync') {
            return item.scout_id !== 0 ? 1 : 0;
        }
        if (key === 'explorer_first_name') {
            return `${item.explorer_first_name || ''} ${item.explorer_last_name || ''}`.toLowerCase();
        }
        if (key === 'participant') {
            return item.participantEntries?.length || 0;
        }
        if (key === 'expedition_signup') {
            return item.expeditionEntries?.length || 0;
        }
        if (key === 'expedition') {
            return (item.expedition_preferences?.exped_type || '').toLowerCase();
        }
        const val = item[key];
        return typeof val === 'string' ? val.toLowerCase() : (val ?? '');
    };

    const sortedRows = useMemo(() => {
        return [...explorerRows].sort((a, b) => {
            const valA = getSortValue(a, sortKey);
            const valB = getSortValue(b, sortKey);
            if (valA < valB) return sortOrder === 'asc' ? -1 : 1;
            if (valA > valB) return sortOrder === 'asc' ? 1 : -1;
            return 0;
        });
    }, [explorerRows, sortKey, sortOrder]);

    const groupedRows = useMemo(() => {
        if (grouping === 'none') {
            return [{ title: null, items: sortedRows }];
        }

        if (grouping === 'level') {
            const levels = ['bronze', 'silver', 'gold'];
            return levels
                .map((lvl) => ({
                    title: `${lvl.charAt(0).toUpperCase() + lvl.slice(1)} Level`,
                    items: sortedRows.filter((s) => s.dofe_level === lvl),
                }))
                .filter((g) => g.items.length > 0);
        }

        if (grouping === 'expedition') {
            const types = ['Hillwalking', 'Biking', 'Paddling'];
            const groups = types
                .map((t) => ({
                    title: t,
                    items: sortedRows.filter((s) => normalize(s.expedition_preferences?.exped_type) === normalize(t)),
                }))
                .filter((g) => g.items.length > 0);

            const unassigned = sortedRows.filter(
                (s) => !types.some((t) => normalize(s.expedition_preferences?.exped_type) === normalize(t))
            );
            if (unassigned.length > 0) {
                groups.push({ title: 'No Preference / Participant Place', items: unassigned });
            }
            return groups;
        }

        if (grouping === 'esu') {
            const units = Array.from(new Set(sortedRows.map((s) => s.unit_name || 'Unassigned'))).sort((a, b) => {
                if (a === 'Unassigned') return 1;
                if (b === 'Unassigned') return -1;
                return a.localeCompare(b);
            });
            return units
                .map((u) => ({
                    title: u,
                    items: sortedRows.filter((s) => (s.unit_name || 'Unassigned') === u),
                }))
                .filter((g) => g.items.length > 0);
        }

        return [{ title: null, items: sortedRows }];
    }, [grouping, sortedRows]);

    const totalItems = sortedRows.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));

    const paginatedGroupedRows = useMemo(() => {
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = currentPage * itemsPerPage;

        if (grouping === 'none') {
            return [{ title: null, items: sortedRows.slice(startIndex, endIndex) }];
        }

        const result: Array<{ title: string | null; items: any[] }> = [];
        let offset = 0;

        for (const group of groupedRows) {
            const groupStart = offset;
            const groupEnd = offset + group.items.length;
            offset = groupEnd;

            if (groupEnd <= startIndex || groupStart >= endIndex) {
                continue;
            }

            const sliceStart = Math.max(0, startIndex - groupStart);
            const sliceEnd = Math.min(group.items.length, endIndex - groupStart);
            if (sliceEnd > sliceStart) {
                result.push({
                    title: group.title,
                    items: group.items.slice(sliceStart, sliceEnd),
                });
            }
        }

        return result;
    }, [grouping, sortedRows, groupedRows, currentPage, itemsPerPage]);

    const handleSort = (key: string) => {
        if (sortKey === key) {
            setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
        } else {
            setSortKey(key);
            setSortOrder('asc');
        }
    };

    const renderHeader = (label: string, key: string) => (
        <th onClick={() => handleSort(key)} className="ems-cursor-pointer">
            <div className="ems-flex-center ems-gap-4">
                <span>{label}</span>
                {sortKey === key && (
                    <span className="ems-small-text">{sortOrder === 'asc' ? '▲' : '▼'}</span>
                )}
            </div>
        </th>
    );

    if (!config.user_data.logged_in) {
        return (
            <div className="ems-leader-dashboard">
                <div>
                    <h2>Unit Leader Signups Dashboard</h2>
                    <p className="ems-mb-20">Please log in with your Unit Leader account to view explorer DofE signups.</p>
                    <a href={config.login_url} className="button">
                        Log In
                    </a>
                </div>
            </div>
        );
    }

    if (!config.user_data.can_access) {
        return (
            <div className="ems-leader-dashboard">
                <div>
                    <h2>Access Restricted</h2>
                    <p className="ems-mb-20">Your account does not have Unit Leader permissions to view this dashboard.</p>
                    <a href={config.logout_url} className="button">
                        Log Out &amp; Switch Accounts
                    </a>
                </div>
            </div>
        );
    }

    if (loading) {
        return (
            <div className="ems-leader-dashboard">
                <p>Loading explorer signups...</p>
            </div>
        );
    }

    const osmIconUrl = `${config.plugin_url || '/wp-content/plugins/ems-plugin/'}assets/images/osm_icon.png`;

    return (
        <div className="ems-leader-dashboard">
            {error && <div className="ems-error-notice">{error}</div>}

            <div className="ems-flex-center ems-gap-16 ems-wrap ems-mb-20">
                <div className="ems-flex-center ems-gap-8">
                    <label htmlFor="leader-grouping-select">Group By:</label>
                    <select
                        id="leader-grouping-select"
                        value={grouping}
                        onChange={(e) => setGrouping(e.target.value as any)}
                    >
                        <option value="none">None (Flat List)</option>
                        <option value="level">DofE Level</option>
                        <option value="expedition">Expedition Type</option>
                        <option value="esu">Unit (ESU)</option>
                    </select>
                </div>

                <div className="ems-flex-center ems-gap-8">
                    <label htmlFor="leader-level-filter">Filter Level:</label>
                    <select
                        id="leader-level-filter"
                        value={levelFilter}
                        onChange={(e) => setLevelFilter(e.target.value)}
                    >
                        <option value="all">All Levels</option>
                        <option value="bronze">Bronze</option>
                        <option value="silver">Silver</option>
                        <option value="gold">Gold</option>
                    </select>
                </div>

                <div className="ems-flex-center ems-gap-8">
                    <label htmlFor="leader-expedition-type-filter">Expedition Type:</label>
                    <select
                        id="leader-expedition-type-filter"
                        value={expeditionTypeFilter}
                        onChange={(e) => setExpeditionTypeFilter(e.target.value)}
                    >
                        <option value="all">All Expeditions</option>
                        <option value="hillwalking">Hillwalking</option>
                        <option value="biking">Biking</option>
                        <option value="paddling">Paddling</option>
                    </select>
                </div>

                <div className="ems-flex-center ems-gap-8">
                    <label htmlFor="leader-unit-filter">Unit:</label>
                    <select
                        id="leader-unit-filter"
                        value={unitFilter}
                        onChange={(e) => setUnitFilter(e.target.value)}
                    >
                        <option value="all">All Units</option>
                        {uniqueUnits.map((u) => (
                            <option key={u} value={u}>{u}</option>
                        ))}
                    </select>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        {renderHeader('Explorer Name', 'explorer_first_name')}
                        {renderHeader('Level', 'dofe_level')}
                        {renderHeader('Participant Place', 'participant')}
                        {renderHeader('Expedition Signup', 'expedition_signup')}
                        {renderHeader('Expedition Type', 'expedition')}
                        {renderHeader('Unit', 'unit_name')}
                        {renderHeader('OSM Sync', 'osm_sync')}
                    </tr>
                </thead>
                <tbody>
                    {paginatedGroupedRows.length === 0 || (paginatedGroupedRows.length === 1 && paginatedGroupedRows[0].items.length === 0) ? (
                        <tr>
                            <td colSpan={7} className="ems-table-cell--center ems-italic">
                                No signup records found for this filter state.
                            </td>
                        </tr>
                    ) : (
                        paginatedGroupedRows.map((g) => (
                            <React.Fragment key={g.title || 'flat'}>
                                {g.title && (
                                    <tr className="ems-table-group-header">
                                        <td colSpan={7}>
                                            <div className="ems-table-group-header__content">
                                                <span className="ems-table-group-header__title">{g.title}</span>
                                                <span className="ems-table-group-header__badge">
                                                    {(() => {
                                                        const uniqueCount = new Set(
                                                            g.items.map((s: any) => s.scout_id !== 0 ? `scout_${s.scout_id}` : `guest_${s.explorer_first_name}_${s.explorer_last_name}`)
                                                        ).size;
                                                        return `${uniqueCount} ${uniqueCount === 1 ? 'explorer' : 'explorers'}`;
                                                    })()}
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                                {g.items.map((row: any) => (
                                    <tr key={row.rowKey}>
                                        <td>
                                            <strong>{row.explorer_first_name} {row.explorer_last_name}</strong>
                                        </td>
                                        <td>
                                            <span className={`ems-pill ems-pill--${row.dofe_level}`}>
                                                {row.dofe_level}
                                            </span>
                                        </td>
                                        <td className="ems-table-cell--center">
                                            {row.participantEntries.length > 0 ? (
                                                <span title="Participant Place submitted" className="ems-fa-full">
                                                    ✓{row.participantEntries.length > 1 ? ` (${row.participantEntries.length})` : ''}
                                                </span>
                                            ) : (
                                                <span>—</span>
                                            )}
                                        </td>
                                        <td className="ems-table-cell--center">
                                            {row.expeditionEntries.length > 0 ? (
                                                <span title="Expedition Signup submitted" className="ems-fa-full">
                                                    ✓{row.expeditionEntries.length > 1 ? ` (${row.expeditionEntries.length})` : ''}
                                                </span>
                                            ) : (
                                                <span>—</span>
                                            )}
                                        </td>
                                        <td>
                                            {row.expedition_preferences?.exped_type ? (
                                                <span>{row.expedition_preferences.exped_type}</span>
                                            ) : (
                                                <span>—</span>
                                            )}
                                        </td>
                                        <td>{row.unit_name || 'Unassigned'}</td>
                                        <td className="ems-table-cell--center">
                                            {row.scout_id !== 0 ? (
                                                <img
                                                    src={osmIconUrl}
                                                    alt="Synced with OSM"
                                                    title="Synced with OSM"
                                                    className="ems-osm-sync-icon"
                                                />
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </React.Fragment>
                        ))
                    )}
                </tbody>
            </table>

            {totalItems > 0 && (
                <div className="ems-flex-between ems-wrap ems-gap-12 ems-mt-16">
                    <div className="ems-leader-dashboard__nowrap">
                        Showing {(currentPage - 1) * itemsPerPage + 1}–{Math.min(currentPage * itemsPerPage, totalItems)} of {totalItems} explorers
                    </div>
                    <div className="ems-flex-center ems-gap-12 ems-wrap">
                        <div className="ems-flex-center ems-gap-8">
                            <label htmlFor="leader-items-per-page">Items per page:</label>
                            <select
                                id="leader-items-per-page"
                                value={itemsPerPage}
                                onChange={(e) => {
                                    setItemsPerPage(parseInt(e.target.value, 10));
                                    setCurrentPage(1);
                                }}
                            >
                                <option value={5}>5</option>
                                <option value={10}>10</option>
                                <option value={25}>25</option>
                                <option value={50}>50</option>
                                <option value={100}>100</option>
                            </select>
                        </div>

                        <div className="ems-flex-center ems-gap-8">
                            <button
                                type="button"
                                onClick={() => setCurrentPage(1)}
                                disabled={currentPage === 1}
                            >
                                «
                            </button>
                            <button
                                type="button"
                                onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                                disabled={currentPage === 1}
                            >
                                ‹ Prev
                            </button>
                            <span className="ems-leader-dashboard__nowrap">
                                Page {currentPage} of {totalPages}
                            </span>
                            <button
                                type="button"
                                onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
                                disabled={currentPage === totalPages}
                            >
                                Next ›
                            </button>
                            <button
                                type="button"
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
    );
};
