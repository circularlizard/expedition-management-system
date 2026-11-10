import React, { useState, useEffect, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faAward,
    faPersonHiking,
    faBicycle,
    faWater,
} from '@fortawesome/free-solid-svg-icons';
import {
    groupSignupsByExplorerIdentity,
    computeExpeditionDistrictUnitMatrix,
} from '../admin/signups-board/dashboardMetrics';
import {
    normalize,
    getUnitDistrictMap,
    getUniqueUnits,
    getUniqueDistricts,
    getSignupsSortValue,
    partitionGroupedRows,
    paginateGroupedRows
} from '../admin/signups-board/tableUtils';

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
            units?: any[];
        };
    }
}

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

    const [activeTab, setActiveTab] = useState<'explorers' | 'summary'>('explorers');
    const [summaryDistrictFilter, setSummaryDistrictFilter] = useState<string>('all');
    const [summarySearchQuery, setSummarySearchQuery] = useState<string>('');

    const [grouping, setGrouping] = useState<'none' | 'level' | 'expedition' | 'esu'>('esu');
    const [levelFilter, setLevelFilter] = useState<string>('all');
    const [expeditionTypeFilter, setExpeditionTypeFilter] = useState<string>('all');
    const [unitFilter, setUnitFilter] = useState<string>('all');
    const [districtFilter, setDistrictFilter] = useState<string>('all');
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [sortKey, setSortKey] = useState<string>('explorer_first_name');
    const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('asc');
    const [currentPage, setCurrentPage] = useState<number>(1);
    const [itemsPerPage, setItemsPerPage] = useState<number>(25);

    useEffect(() => {
        setCurrentPage(1);
    }, [grouping, levelFilter, expeditionTypeFilter, unitFilter, districtFilter, searchQuery, sortKey, sortOrder]);

    const availableUnits: any[] = (window.emsLeaderDashboard?.units || (config as any).units || []);

    const unitDistrictMap = useMemo(() => {
        return getUnitDistrictMap(availableUnits);
    }, [availableUnits]);

    const uniqueDistricts = useMemo(() => {
        return getUniqueDistricts(availableUnits, signups, unitDistrictMap);
    }, [availableUnits, signups, unitDistrictMap]);

    const matrixData = useMemo(() => {
        return computeExpeditionDistrictUnitMatrix(signups, unitDistrictMap);
    }, [signups, unitDistrictMap]);

    const filteredMatrixGroups = useMemo(() => {
        let groups = matrixData.groups;
        if (summaryDistrictFilter !== 'all') {
            groups = groups.filter((g) => g.district.toLowerCase() === summaryDistrictFilter.toLowerCase());
        }
        if (summarySearchQuery.trim()) {
            const q = summarySearchQuery.trim().toLowerCase();
            groups = groups
                .map((g) => ({
                    ...g,
                    units: g.units.filter((u) => u.unit.toLowerCase().includes(q) || g.district.toLowerCase().includes(q)),
                }))
                .filter((g) => g.units.length > 0);
        }
        return groups;
    }, [matrixData.groups, summaryDistrictFilter, summarySearchQuery]);

    const availableSummaryDistricts = useMemo(() => {
        return matrixData.groups.map((g) => g.district);
    }, [matrixData.groups]);

    const handleDrilldown = (options: {
        level?: string;
        expedType?: string;
        unit?: string;
        district?: string;
    }) => {
        if (options.level !== undefined) {
            setLevelFilter(options.level);
        }
        if (options.expedType !== undefined) {
            setExpeditionTypeFilter(options.expedType);
        }
        if (options.unit !== undefined) {
            setUnitFilter(options.unit);
        }
        if (options.district !== undefined) {
            setDistrictFilter(options.district);
        }
        setSearchQuery('');
        setCurrentPage(1);
        setActiveTab('explorers');
    };

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
            if (districtFilter !== 'all') {
                const itemDistrict = (s.district || unitDistrictMap[s.unit_name] || unitDistrictMap[String(s.unit_id)] || '').trim() || 'Unassigned';
                if (normalize(itemDistrict) !== normalize(districtFilter)) {
                    return false;
                }
            }
            if (searchQuery.trim() !== '') {
                const q = normalize(searchQuery);
                const first = normalize(s.explorer_first_name);
                const last = normalize(s.explorer_last_name);
                const full = `${first} ${last}`.trim();
                const rev = `${last} ${first}`.trim();
                if (!first.includes(q) && !last.includes(q) && !full.includes(q) && !rev.includes(q)) {
                    return false;
                }
            }
            return true;
        });
    }, [signups, levelFilter, expeditionTypeFilter, unitFilter, districtFilter, searchQuery, unitDistrictMap]);

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

    const uniqueUnits = useMemo(() => getUniqueUnits(signups), [signups]);

    const sortedRows = useMemo(() => {
        return [...explorerRows].sort((a, b) => {
            const valA = getSignupsSortValue(a, sortKey);
            const valB = getSignupsSortValue(b, sortKey);
            if (valA < valB) return sortOrder === 'asc' ? -1 : 1;
            if (valA > valB) return sortOrder === 'asc' ? 1 : -1;
            return 0;
        });
    }, [explorerRows, sortKey, sortOrder]);

    const groupedRows = useMemo(() => {
        return partitionGroupedRows(sortedRows, grouping);
    }, [grouping, sortedRows]);

    const totalItems = sortedRows.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));

    const paginatedGroupedRows = useMemo(() => {
        return paginateGroupedRows(groupedRows, currentPage, itemsPerPage);
    }, [groupedRows, currentPage, itemsPerPage]);

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

            <div className="ems-tab-nav ems-mb-20">
                <button
                    type="button"
                    className={`ems-tab-nav__button ${activeTab === 'explorers' ? 'ems-tab-nav__button--active' : ''}`}
                    onClick={() => setActiveTab('explorers')}
                >
                    Explorer Signups ({totalItems})
                </button>
                <button
                    type="button"
                    className={`ems-tab-nav__button ${activeTab === 'summary' ? 'ems-tab-nav__button--active' : ''}`}
                    onClick={() => setActiveTab('summary')}
                >
                    Unit Summary
                </button>
            </div>

            {activeTab === 'summary' ? (
                <div>
                    <div className="ems-flex-center ems-gap-16 ems-wrap ems-mb-20">
                        {availableSummaryDistricts.length > 1 && (
                            <div className="ems-flex-center ems-gap-8">
                                <label htmlFor="summary-district-filter">Filter District:</label>
                                <select
                                    id="summary-district-filter"
                                    value={summaryDistrictFilter}
                                    onChange={(e) => setSummaryDistrictFilter(e.target.value)}
                                >
                                    <option value="all">All Districts</option>
                                    {availableSummaryDistricts.map((d) => (
                                        <option key={d} value={d}>{d}</option>
                                    ))}
                                </select>
                            </div>
                        )}

                        <div className="ems-flex-center ems-gap-8">
                            <label htmlFor="summary-unit-search">Search Unit:</label>
                            <input
                                type="text"
                                id="summary-unit-search"
                                value={summarySearchQuery}
                                onChange={(e) => setSummarySearchQuery(e.target.value)}
                                placeholder="Search unit..."
                                className="ems-input"
                            />
                        </div>
                    </div>

                    {matrixData.totalExpeditions === 0 ? (
                        <p className="ems-table-cell--center ems-italic ems-mt-16">
                            No expedition signups found.
                        </p>
                    ) : (
                        <div className="ems-matrix-table-wrap">
                            <table
                                className="ems-matrix-table"
                                data-testid="leader-expedition-matrix"
                                aria-label="Expedition signups by district and unit"
                            >
                                <thead>
                                    <tr>
                                        <th rowSpan={2} scope="col">District</th>
                                        <th rowSpan={2} scope="col">Unit</th>
                                        <th colSpan={3} scope="colgroup" className="ems-matrix-th-level ems-matrix-th-bronze">
                                            <FontAwesomeIcon icon={faAward} /> Bronze
                                        </th>
                                        <th colSpan={3} scope="colgroup" className="ems-matrix-th-level ems-matrix-th-silver">
                                            <FontAwesomeIcon icon={faAward} /> Silver
                                        </th>
                                        <th colSpan={3} scope="colgroup" className="ems-matrix-th-level ems-matrix-th-gold">
                                            <FontAwesomeIcon icon={faAward} /> Gold
                                        </th>
                                        <th rowSpan={2} scope="col" className="ems-matrix-th-level">Total</th>
                                    </tr>
                                    <tr>
                                        <th scope="col" className="ems-matrix-th-sub">
                                            <FontAwesomeIcon icon={faPersonHiking} /> HW
                                        </th>
                                        <th scope="col" className="ems-matrix-th-sub">
                                            <FontAwesomeIcon icon={faBicycle} /> Bike
                                        </th>
                                        <th scope="col" className="ems-matrix-th-sub">
                                            <FontAwesomeIcon icon={faWater} /> Paddle
                                        </th>

                                        <th scope="col" className="ems-matrix-th-sub">
                                            <FontAwesomeIcon icon={faPersonHiking} /> HW
                                        </th>
                                        <th scope="col" className="ems-matrix-th-sub">
                                            <FontAwesomeIcon icon={faBicycle} /> Bike
                                        </th>
                                        <th scope="col" className="ems-matrix-th-sub">
                                            <FontAwesomeIcon icon={faWater} /> Paddle
                                        </th>

                                        <th scope="col" className="ems-matrix-th-sub">
                                            <FontAwesomeIcon icon={faPersonHiking} /> HW
                                        </th>
                                        <th scope="col" className="ems-matrix-th-sub">
                                            <FontAwesomeIcon icon={faBicycle} /> Bike
                                        </th>
                                        <th scope="col" className="ems-matrix-th-sub">
                                            <FontAwesomeIcon icon={faWater} /> Paddle
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredMatrixGroups.map((group) => (
                                        <React.Fragment key={group.district}>
                                            {group.units.map((row) => (
                                                <tr key={`${group.district}_${row.unit}`} data-testid={`matrix-row-${row.unit}`}>
                                                    <th scope="row" className="ems-matrix-col-district">{group.district}</th>
                                                    <td className="ems-matrix-col-unit">{row.unit}</td>

                                                    {/* Bronze */}
                                                    <td className="ems-matrix-cell-number" data-testid="cell-bronze-hillwalking">
                                                        {row.counts.bronze.hillwalking > 0 ? (
                                                            <button
                                                                type="button"
                                                                className="ems-matrix-btn-cell"
                                                                onClick={() =>
                                                                    handleDrilldown({
                                                                        level: 'bronze',
                                                                        expedType: 'hillwalking',
                                                                        unit: row.unit,
                                                                        district: group.district,
                                                                    })
                                                                }
                                                            >
                                                                {row.counts.bronze.hillwalking}
                                                            </button>
                                                        ) : (
                                                            <span className="ems-matrix-muted-zero">0</span>
                                                        )}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="cell-bronze-biking">
                                                        {row.counts.bronze.biking > 0 ? (
                                                            <button
                                                                type="button"
                                                                className="ems-matrix-btn-cell"
                                                                onClick={() =>
                                                                    handleDrilldown({
                                                                        level: 'bronze',
                                                                        expedType: 'biking',
                                                                        unit: row.unit,
                                                                        district: group.district,
                                                                    })
                                                                }
                                                            >
                                                                {row.counts.bronze.biking}
                                                            </button>
                                                        ) : (
                                                            <span className="ems-matrix-muted-zero">0</span>
                                                        )}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="cell-bronze-paddling">
                                                        {row.counts.bronze.paddling > 0 ? (
                                                            <button
                                                                type="button"
                                                                className="ems-matrix-btn-cell"
                                                                onClick={() =>
                                                                    handleDrilldown({
                                                                        level: 'bronze',
                                                                        expedType: 'paddling',
                                                                        unit: row.unit,
                                                                        district: group.district,
                                                                    })
                                                                }
                                                            >
                                                                {row.counts.bronze.paddling}
                                                            </button>
                                                        ) : (
                                                            <span className="ems-matrix-muted-zero">0</span>
                                                        )}
                                                    </td>

                                                    {/* Silver */}
                                                    <td className="ems-matrix-cell-number" data-testid="cell-silver-hillwalking">
                                                        {row.counts.silver.hillwalking > 0 ? (
                                                            <button
                                                                type="button"
                                                                className="ems-matrix-btn-cell"
                                                                onClick={() =>
                                                                    handleDrilldown({
                                                                        level: 'silver',
                                                                        expedType: 'hillwalking',
                                                                        unit: row.unit,
                                                                        district: group.district,
                                                                    })
                                                                }
                                                            >
                                                                {row.counts.silver.hillwalking}
                                                            </button>
                                                        ) : (
                                                            <span className="ems-matrix-muted-zero">0</span>
                                                        )}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="cell-silver-biking">
                                                        {row.counts.silver.biking > 0 ? (
                                                            <button
                                                                type="button"
                                                                className="ems-matrix-btn-cell"
                                                                onClick={() =>
                                                                    handleDrilldown({
                                                                        level: 'silver',
                                                                        expedType: 'biking',
                                                                        unit: row.unit,
                                                                        district: group.district,
                                                                    })
                                                                }
                                                            >
                                                                {row.counts.silver.biking}
                                                            </button>
                                                        ) : (
                                                            <span className="ems-matrix-muted-zero">0</span>
                                                        )}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="cell-silver-paddling">
                                                        {row.counts.silver.paddling > 0 ? (
                                                            <button
                                                                type="button"
                                                                className="ems-matrix-btn-cell"
                                                                onClick={() =>
                                                                    handleDrilldown({
                                                                        level: 'silver',
                                                                        expedType: 'paddling',
                                                                        unit: row.unit,
                                                                        district: group.district,
                                                                    })
                                                                }
                                                            >
                                                                {row.counts.silver.paddling}
                                                            </button>
                                                        ) : (
                                                            <span className="ems-matrix-muted-zero">0</span>
                                                        )}
                                                    </td>

                                                    {/* Gold */}
                                                    <td className="ems-matrix-cell-number" data-testid="cell-gold-hillwalking">
                                                        {row.counts.gold.hillwalking > 0 ? (
                                                            <button
                                                                type="button"
                                                                className="ems-matrix-btn-cell"
                                                                onClick={() =>
                                                                    handleDrilldown({
                                                                        level: 'gold',
                                                                        expedType: 'hillwalking',
                                                                        unit: row.unit,
                                                                        district: group.district,
                                                                    })
                                                                }
                                                            >
                                                                {row.counts.gold.hillwalking}
                                                            </button>
                                                        ) : (
                                                            <span className="ems-matrix-muted-zero">0</span>
                                                        )}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="cell-gold-biking">
                                                        {row.counts.gold.biking > 0 ? (
                                                            <button
                                                                type="button"
                                                                className="ems-matrix-btn-cell"
                                                                onClick={() =>
                                                                    handleDrilldown({
                                                                        level: 'gold',
                                                                        expedType: 'biking',
                                                                        unit: row.unit,
                                                                        district: group.district,
                                                                    })
                                                                }
                                                            >
                                                                {row.counts.gold.biking}
                                                            </button>
                                                        ) : (
                                                            <span className="ems-matrix-muted-zero">0</span>
                                                        )}
                                                    </td>
                                                    <td className="ems-matrix-cell-number" data-testid="cell-gold-paddling">
                                                        {row.counts.gold.paddling > 0 ? (
                                                            <button
                                                                type="button"
                                                                className="ems-matrix-btn-cell"
                                                                onClick={() =>
                                                                    handleDrilldown({
                                                                        level: 'gold',
                                                                        expedType: 'paddling',
                                                                        unit: row.unit,
                                                                        district: group.district,
                                                                    })
                                                                }
                                                            >
                                                                {row.counts.gold.paddling}
                                                            </button>
                                                        ) : (
                                                            <span className="ems-matrix-muted-zero">0</span>
                                                        )}
                                                    </td>

                                                    {/* Unit Total */}
                                                    <td className="ems-matrix-cell-number" data-testid="cell-total">
                                                        {row.total > 0 ? (
                                                            <button
                                                                type="button"
                                                                className="ems-matrix-btn-cell ems-font-bold"
                                                                onClick={() =>
                                                                    handleDrilldown({
                                                                        unit: row.unit,
                                                                        district: group.district,
                                                                        level: 'all',
                                                                        expedType: 'all',
                                                                    })
                                                                }
                                                            >
                                                                {row.total}
                                                            </button>
                                                        ) : (
                                                            <span className="ems-matrix-muted-zero">0</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}

                                            {/* District Subtotal */}
                                            <tr
                                                key={`subtotal_${group.district}`}
                                                className="ems-matrix-row-subtotal"
                                                data-testid={`matrix-subtotal-${group.district}`}
                                            >
                                                <td colSpan={2} className="ems-matrix-col-subtotal-label">
                                                    <strong>{group.district} Subtotal</strong>
                                                </td>
                                                <td className="ems-matrix-cell-number" data-testid="subtotal-bronze-hillwalking">
                                                    {group.subtotal.counts.bronze.hillwalking > 0 ? (
                                                        <button
                                                            type="button"
                                                            className="ems-matrix-btn-cell"
                                                            onClick={() =>
                                                                handleDrilldown({
                                                                    level: 'bronze',
                                                                    expedType: 'hillwalking',
                                                                    district: group.district,
                                                                    unit: 'all',
                                                                })
                                                            }
                                                        >
                                                            {group.subtotal.counts.bronze.hillwalking}
                                                        </button>
                                                    ) : (
                                                        <span className="ems-matrix-muted-zero">0</span>
                                                    )}
                                                </td>
                                                <td className="ems-matrix-cell-number" data-testid="subtotal-bronze-biking">
                                                    {group.subtotal.counts.bronze.biking > 0 ? (
                                                        <button
                                                            type="button"
                                                            className="ems-matrix-btn-cell"
                                                            onClick={() =>
                                                                handleDrilldown({
                                                                    level: 'bronze',
                                                                    expedType: 'biking',
                                                                    district: group.district,
                                                                    unit: 'all',
                                                                })
                                                            }
                                                        >
                                                            {group.subtotal.counts.bronze.biking}
                                                        </button>
                                                    ) : (
                                                        <span className="ems-matrix-muted-zero">0</span>
                                                    )}
                                                </td>
                                                <td className="ems-matrix-cell-number" data-testid="subtotal-bronze-paddling">
                                                    {group.subtotal.counts.bronze.paddling > 0 ? (
                                                        <button
                                                            type="button"
                                                            className="ems-matrix-btn-cell"
                                                            onClick={() =>
                                                                handleDrilldown({
                                                                    level: 'bronze',
                                                                    expedType: 'paddling',
                                                                    district: group.district,
                                                                    unit: 'all',
                                                                })
                                                            }
                                                        >
                                                            {group.subtotal.counts.bronze.paddling}
                                                        </button>
                                                    ) : (
                                                        <span className="ems-matrix-muted-zero">0</span>
                                                    )}
                                                </td>

                                                <td className="ems-matrix-cell-number" data-testid="subtotal-silver-hillwalking">
                                                    {group.subtotal.counts.silver.hillwalking > 0 ? (
                                                        <button
                                                            type="button"
                                                            className="ems-matrix-btn-cell"
                                                            onClick={() =>
                                                                handleDrilldown({
                                                                    level: 'silver',
                                                                    expedType: 'hillwalking',
                                                                    district: group.district,
                                                                    unit: 'all',
                                                                })
                                                            }
                                                        >
                                                            {group.subtotal.counts.silver.hillwalking}
                                                        </button>
                                                    ) : (
                                                        <span className="ems-matrix-muted-zero">0</span>
                                                    )}
                                                </td>
                                                <td className="ems-matrix-cell-number" data-testid="subtotal-silver-biking">
                                                    {group.subtotal.counts.silver.biking > 0 ? (
                                                        <button
                                                            type="button"
                                                            className="ems-matrix-btn-cell"
                                                            onClick={() =>
                                                                handleDrilldown({
                                                                    level: 'silver',
                                                                    expedType: 'biking',
                                                                    district: group.district,
                                                                    unit: 'all',
                                                                })
                                                            }
                                                        >
                                                            {group.subtotal.counts.silver.biking}
                                                        </button>
                                                    ) : (
                                                        <span className="ems-matrix-muted-zero">0</span>
                                                    )}
                                                </td>
                                                <td className="ems-matrix-cell-number" data-testid="subtotal-silver-paddling">
                                                    {group.subtotal.counts.silver.paddling > 0 ? (
                                                        <button
                                                            type="button"
                                                            className="ems-matrix-btn-cell"
                                                            onClick={() =>
                                                                handleDrilldown({
                                                                    level: 'silver',
                                                                    expedType: 'paddling',
                                                                    district: group.district,
                                                                    unit: 'all',
                                                                })
                                                            }
                                                        >
                                                            {group.subtotal.counts.silver.paddling}
                                                        </button>
                                                    ) : (
                                                        <span className="ems-matrix-muted-zero">0</span>
                                                    )}
                                                </td>

                                                <td className="ems-matrix-cell-number" data-testid="subtotal-gold-hillwalking">
                                                    {group.subtotal.counts.gold.hillwalking > 0 ? (
                                                        <button
                                                            type="button"
                                                            className="ems-matrix-btn-cell"
                                                            onClick={() =>
                                                                handleDrilldown({
                                                                    level: 'gold',
                                                                    expedType: 'hillwalking',
                                                                    district: group.district,
                                                                    unit: 'all',
                                                                })
                                                            }
                                                        >
                                                            {group.subtotal.counts.gold.hillwalking}
                                                        </button>
                                                    ) : (
                                                        <span className="ems-matrix-muted-zero">0</span>
                                                    )}
                                                </td>
                                                <td className="ems-matrix-cell-number" data-testid="subtotal-gold-biking">
                                                    {group.subtotal.counts.gold.biking > 0 ? (
                                                        <button
                                                            type="button"
                                                            className="ems-matrix-btn-cell"
                                                            onClick={() =>
                                                                handleDrilldown({
                                                                    level: 'gold',
                                                                    expedType: 'biking',
                                                                    district: group.district,
                                                                    unit: 'all',
                                                                })
                                                            }
                                                        >
                                                            {group.subtotal.counts.gold.biking}
                                                        </button>
                                                    ) : (
                                                        <span className="ems-matrix-muted-zero">0</span>
                                                    )}
                                                </td>
                                                <td className="ems-matrix-cell-number" data-testid="subtotal-gold-paddling">
                                                    {group.subtotal.counts.gold.paddling > 0 ? (
                                                        <button
                                                            type="button"
                                                            className="ems-matrix-btn-cell"
                                                            onClick={() =>
                                                                handleDrilldown({
                                                                    level: 'gold',
                                                                    expedType: 'paddling',
                                                                    district: group.district,
                                                                    unit: 'all',
                                                                })
                                                            }
                                                        >
                                                            {group.subtotal.counts.gold.paddling}
                                                        </button>
                                                    ) : (
                                                        <span className="ems-matrix-muted-zero">0</span>
                                                    )}
                                                </td>

                                                <td className="ems-matrix-cell-number" data-testid="subtotal-total">
                                                    {group.subtotal.total > 0 ? (
                                                        <button
                                                            type="button"
                                                            className="ems-matrix-btn-cell ems-font-bold"
                                                            onClick={() =>
                                                                handleDrilldown({
                                                                    district: group.district,
                                                                    unit: 'all',
                                                                    level: 'all',
                                                                    expedType: 'all',
                                                                })
                                                            }
                                                        >
                                                            {group.subtotal.total}
                                                        </button>
                                                    ) : (
                                                        <span className="ems-matrix-muted-zero">0</span>
                                                    )}
                                                </td>
                                            </tr>
                                        </React.Fragment>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="ems-matrix-row-grand-total" data-testid="matrix-grand-total">
                                        <td colSpan={2} className="ems-matrix-col-subtotal-label">
                                            <strong>Total</strong>
                                        </td>
                                        <td className="ems-matrix-cell-number" data-testid="grand-total-bronze-hillwalking">
                                            {matrixData.grandTotal.counts.bronze.hillwalking > 0 ? (
                                                <button
                                                    type="button"
                                                    className="ems-matrix-btn-cell"
                                                    onClick={() =>
                                                        handleDrilldown({
                                                            level: 'bronze',
                                                            expedType: 'hillwalking',
                                                            district: 'all',
                                                            unit: 'all',
                                                        })
                                                    }
                                                >
                                                    {matrixData.grandTotal.counts.bronze.hillwalking}
                                                </button>
                                            ) : (
                                                <span className="ems-matrix-muted-zero">0</span>
                                            )}
                                        </td>
                                        <td className="ems-matrix-cell-number" data-testid="grand-total-bronze-biking">
                                            {matrixData.grandTotal.counts.bronze.biking > 0 ? (
                                                <button
                                                    type="button"
                                                    className="ems-matrix-btn-cell"
                                                    onClick={() =>
                                                        handleDrilldown({
                                                            level: 'bronze',
                                                            expedType: 'biking',
                                                            district: 'all',
                                                            unit: 'all',
                                                        })
                                                    }
                                                >
                                                    {matrixData.grandTotal.counts.bronze.biking}
                                                </button>
                                            ) : (
                                                <span className="ems-matrix-muted-zero">0</span>
                                            )}
                                        </td>
                                        <td className="ems-matrix-cell-number" data-testid="grand-total-bronze-paddling">
                                            {matrixData.grandTotal.counts.bronze.paddling > 0 ? (
                                                <button
                                                    type="button"
                                                    className="ems-matrix-btn-cell"
                                                    onClick={() =>
                                                        handleDrilldown({
                                                            level: 'bronze',
                                                            expedType: 'paddling',
                                                            district: 'all',
                                                            unit: 'all',
                                                        })
                                                    }
                                                >
                                                    {matrixData.grandTotal.counts.bronze.paddling}
                                                </button>
                                            ) : (
                                                <span className="ems-matrix-muted-zero">0</span>
                                            )}
                                        </td>

                                        <td className="ems-matrix-cell-number" data-testid="grand-total-silver-hillwalking">
                                            {matrixData.grandTotal.counts.silver.hillwalking > 0 ? (
                                                <button
                                                    type="button"
                                                    className="ems-matrix-btn-cell"
                                                    onClick={() =>
                                                        handleDrilldown({
                                                            level: 'silver',
                                                            expedType: 'hillwalking',
                                                            district: 'all',
                                                            unit: 'all',
                                                        })
                                                    }
                                                >
                                                    {matrixData.grandTotal.counts.silver.hillwalking}
                                                </button>
                                            ) : (
                                                <span className="ems-matrix-muted-zero">0</span>
                                            )}
                                        </td>
                                        <td className="ems-matrix-cell-number" data-testid="grand-total-silver-biking">
                                            {matrixData.grandTotal.counts.silver.biking > 0 ? (
                                                <button
                                                    type="button"
                                                    className="ems-matrix-btn-cell"
                                                    onClick={() =>
                                                        handleDrilldown({
                                                            level: 'silver',
                                                            expedType: 'biking',
                                                            district: 'all',
                                                            unit: 'all',
                                                        })
                                                    }
                                                >
                                                    {matrixData.grandTotal.counts.silver.biking}
                                                </button>
                                            ) : (
                                                <span className="ems-matrix-muted-zero">0</span>
                                            )}
                                        </td>
                                        <td className="ems-matrix-cell-number" data-testid="grand-total-silver-paddling">
                                            {matrixData.grandTotal.counts.silver.paddling > 0 ? (
                                                <button
                                                    type="button"
                                                    className="ems-matrix-btn-cell"
                                                    onClick={() =>
                                                        handleDrilldown({
                                                            level: 'silver',
                                                            expedType: 'paddling',
                                                            district: 'all',
                                                            unit: 'all',
                                                        })
                                                    }
                                                >
                                                    {matrixData.grandTotal.counts.silver.paddling}
                                                </button>
                                            ) : (
                                                <span className="ems-matrix-muted-zero">0</span>
                                            )}
                                        </td>

                                        <td className="ems-matrix-cell-number" data-testid="grand-total-gold-hillwalking">
                                            {matrixData.grandTotal.counts.gold.hillwalking > 0 ? (
                                                <button
                                                    type="button"
                                                    className="ems-matrix-btn-cell"
                                                    onClick={() =>
                                                        handleDrilldown({
                                                            level: 'gold',
                                                            expedType: 'hillwalking',
                                                            district: 'all',
                                                            unit: 'all',
                                                        })
                                                    }
                                                >
                                                    {matrixData.grandTotal.counts.gold.hillwalking}
                                                </button>
                                            ) : (
                                                <span className="ems-matrix-muted-zero">0</span>
                                            )}
                                        </td>
                                        <td className="ems-matrix-cell-number" data-testid="grand-total-gold-biking">
                                            {matrixData.grandTotal.counts.gold.biking > 0 ? (
                                                <button
                                                    type="button"
                                                    className="ems-matrix-btn-cell"
                                                    onClick={() =>
                                                        handleDrilldown({
                                                            level: 'gold',
                                                            expedType: 'biking',
                                                            district: 'all',
                                                            unit: 'all',
                                                        })
                                                    }
                                                >
                                                    {matrixData.grandTotal.counts.gold.biking}
                                                </button>
                                            ) : (
                                                <span className="ems-matrix-muted-zero">0</span>
                                            )}
                                        </td>
                                        <td className="ems-matrix-cell-number" data-testid="grand-total-gold-paddling">
                                            {matrixData.grandTotal.counts.gold.paddling > 0 ? (
                                                <button
                                                    type="button"
                                                    className="ems-matrix-btn-cell"
                                                    onClick={() =>
                                                        handleDrilldown({
                                                            level: 'gold',
                                                            expedType: 'paddling',
                                                            district: 'all',
                                                            unit: 'all',
                                                        })
                                                    }
                                                >
                                                    {matrixData.grandTotal.counts.gold.paddling}
                                                </button>
                                            ) : (
                                                <span className="ems-matrix-muted-zero">0</span>
                                            )}
                                        </td>

                                        <td className="ems-matrix-cell-number" data-testid="grand-total-total">
                                            {matrixData.grandTotal.total > 0 ? (
                                                <button
                                                    type="button"
                                                    className="ems-matrix-btn-cell ems-font-bold"
                                                    onClick={() =>
                                                        handleDrilldown({
                                                            district: 'all',
                                                            unit: 'all',
                                                            level: 'all',
                                                            expedType: 'all',
                                                        })
                                                    }
                                                >
                                                    {matrixData.grandTotal.total}
                                                </button>
                                            ) : (
                                                <span className="ems-matrix-muted-zero">0</span>
                                            )}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    )}
                </div>
            ) : (
                <>
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

                <div className="ems-flex-center ems-gap-8">
                    <label htmlFor="leader-district-filter">Filter District:</label>
                    <select
                        id="leader-district-filter"
                        value={districtFilter}
                        onChange={(e) => setDistrictFilter(e.target.value)}
                    >
                        <option value="all">All Districts</option>
                        {uniqueDistricts.map((d) => (
                            <option key={d} value={d}>{d}</option>
                        ))}
                        <option value="unassigned">Unassigned</option>
                    </select>
                </div>

                <div className="ems-flex-center ems-gap-8">
                    <label htmlFor="leader-explorer-search">Search Explorer:</label>
                    <input
                        type="text"
                        id="leader-explorer-search"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        placeholder="Search by name..."
                        className="ems-input"
                    />
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
                </>
            )}
        </div>
    );
};
