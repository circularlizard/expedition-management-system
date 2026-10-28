import React, { useState, useEffect, useRef, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faPersonHiking,
    faBicycle,
    faWater,
    faTriangleExclamation,
    faXmark,
    faClipboardList,
    faCampground,
    faCheck
} from '@fortawesome/free-solid-svg-icons';
import SignupsDashboard, { TableDrilldownFilters } from './SignupsDashboard';
import { computeSignupsDashboardMetrics, getSignupRecordKey, groupSignupsByExplorerIdentity } from './dashboardMetrics';

interface SignupsConfig {
    root_url: string;
    nonce: string;
    units?: any[];
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

    const [activeViewTab, setActiveViewTab] = useState<'dashboard' | 'table'>('dashboard');
    const [signups, setSignups] = useState<any[]>([]);
    const [explorers, setExplorers] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Filters
    const [statusFilter, setStatusFilter] = useState<string>('submitted');
    const [typeFilter, setTypeFilter] = useState<string>('all');
    const [levelFilter, setLevelFilter] = useState<string>('all');
    const [expeditionTypeFilter, setExpeditionTypeFilter] = useState<string>('all');
    const [unitFilter, setUnitFilter] = useState<string>('all');
    const [districtFilter, setDistrictFilter] = useState<string>('all');
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [anomalyFilter, setAnomalyFilter] = useState<'all' | 'no_expedition' | 'no_participant' | 'duplicate' | 'unmatched_osm'>('all');

    // Grouping & Sorting
    const [grouping, setGrouping] = useState<'none' | 'unit' | 'level' | 'explorer' | 'parent'>('none');
    const [sortKey, setSortKey] = useState<string>('created_at');
    const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');

    // Selected signup & Inspector Panel
    const [selectedSignup, setSelectedSignup] = useState<any | null>(null);
    const [editedDofeNumber, setEditedDofeNumber] = useState<string>('');
    const [editedUnitId, setEditedUnitId] = useState<string>('');
    const [savingField, setSavingField] = useState<'level' | 'unit' | null>(null);
    const [levelSaveSuccess, setLevelSaveSuccess] = useState<boolean>(false);
    const [unitSaveSuccess, setUnitSaveSuccess] = useState<boolean>(false);
    const [reconcileSearch, setReconcileSearch] = useState<string>('');
    const [unlinkError, setUnlinkError] = useState<string | null>(null);
    const inspectorRef = useRef<HTMLDivElement>(null);
    const availableUnits: any[] = config.units || [];
    const prevEntryKeyRef = useRef<string | null>(null);

    const selectedEntryKey = selectedSignup ? `${selectedSignup.type}_${selectedSignup.id}` : null;

    useEffect(() => {
        if (!levelSaveSuccess) return;
        const timer = setTimeout(() => setLevelSaveSuccess(false), 3500);
        return () => clearTimeout(timer);
    }, [levelSaveSuccess]);

    useEffect(() => {
        if (!unitSaveSuccess) return;
        const timer = setTimeout(() => setUnitSaveSuccess(false), 3500);
        return () => clearTimeout(timer);
    }, [unitSaveSuccess]);

    // Pagination
    const [currentPage, setCurrentPage] = useState<number>(1);
    const [itemsPerPage, setItemsPerPage] = useState<number>(25);

    useEffect(() => {
        setCurrentPage(1);
    }, [statusFilter, typeFilter, levelFilter, expeditionTypeFilter, unitFilter, districtFilter, searchQuery, anomalyFilter, sortKey, sortOrder, grouping]);

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
            if (prevEntryKeyRef.current !== selectedEntryKey) {
                setLevelSaveSuccess(false);
                setUnitSaveSuccess(false);
                setSavingField(null);
                setUnlinkError(null);
                setReconcileSearch('');
                prevEntryKeyRef.current = selectedEntryKey;
            }
            setEditedDofeNumber(selectedSignup.dofe_number || '');
            if (selectedSignup.unit_id) {
                setEditedUnitId(String(selectedSignup.unit_id));
            } else {
                const matchedUnit = availableUnits.find(
                    (u: any) =>
                        normalize(u.short_code) === normalize(selectedSignup.unit_name) ||
                        normalize(u.name) === normalize(selectedSignup.unit_name)
                );
                setEditedUnitId(matchedUnit ? String(matchedUnit.unit_id) : '');
            }
        } else {
            prevEntryKeyRef.current = null;
        }
    }, [selectedSignup, selectedEntryKey]);

    useEffect(() => {
        if (!loading && signups && signups.length > 0) {
            const params = new URLSearchParams(window.location.search);
            const idParam = params.get('id');
            if (idParam) {
                const targetId = parseInt(idParam, 10);
                const found = signups.find(s => s.id === targetId);
                if (found) {
                    setActiveViewTab('table');
                    setSelectedSignup(found);
                }
            }
        }
    }, [loading, signups]);

    const handleNavigateToTable = (filters: TableDrilldownFilters) => {
        setStatusFilter('submitted');
        setTypeFilter(filters.typeFilter ?? 'all');
        setLevelFilter(filters.levelFilter ?? 'all');
        setExpeditionTypeFilter(filters.expeditionTypeFilter ?? 'all');
        setAnomalyFilter(filters.anomalyFilter ?? 'all');
        setGrouping(filters.grouping ?? 'none');
        setSelectedSignup(null);
        setActiveViewTab('table');
    };

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

    const handleUpdateLevel = async (signupId: number, signupType: string, newLevel: string) => {
        if (!newLevel) return;
        setSavingField('level');
        setLevelSaveSuccess(false);
        try {
            const response = await fetch(`${config.root_url}/signups/level`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    signup_id: signupId,
                    signup_type: signupType,
                    dofe_level: newLevel
                })
            });
            const data = await response.json();
            if (!response.ok || (data.code && data.message)) {
                throw new Error(data.message || 'Failed to update level');
            }
            setSelectedSignup((prev: any) =>
                prev
                    ? {
                          ...prev,
                          dofe_level: data.dofe_level,
                      }
                    : null
            );
            setLevelSaveSuccess(true);
            fetchData();
        } catch (err: any) {
            alert(err.message);
        } finally {
            setSavingField(null);
        }
    };

    const handleUpdateUnit = async (signupId: number, signupType: string, unitId: number) => {
        if (!unitId) return;
        setSavingField('unit');
        setUnitSaveSuccess(false);
        try {
            const response = await fetch(`${config.root_url}/signups/unit`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    signup_id: signupId,
                    signup_type: signupType,
                    unit_id: unitId
                })
            });
            const data = await response.json();
            if (!response.ok || (data.code && data.message)) {
                throw new Error(data.message || 'Failed to update unit');
            }
            setSelectedSignup((prev: any) =>
                prev
                    ? {
                          ...prev,
                          unit_id: data.unit_id,
                          unit_name: data.unit_name,
                          leader_email: data.leader_email,
                      }
                    : null
            );
            setUnitSaveSuccess(true);
            fetchData();
        } catch (err: any) {
            alert(err.message);
        } finally {
            setSavingField(null);
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

            // 1. Exact first and last name match
            if (sf === ef && sl === el) return true;

            // 2. Exact explorer email match (if present) against any explorer email
            const explorerEmails = [e.email1, e.email2, e.email].map(normalize).filter(Boolean);
            if (se && explorerEmails.includes(se)) return true;

            // 3. Parent email match (against any parent email) AND first name similarity (sibling guard)
            const parentEmails = [e.p1_email1, e.p1_email2, e.p2_email1, e.p2_email2, e.parent_email].map(normalize).filter(Boolean);
            if (sp && parentEmails.includes(sp) && firstNamesSimilar(signup.explorer_first_name, e.first_name)) {
                return true;
            }

            return false;
        });
    };

    const dashboardMetrics = useMemo(() => computeSignupsDashboardMetrics(signups), [signups]);

    const allExplorerBuckets = useMemo(() => groupSignupsByExplorerIdentity(signups), [signups]);

    const unitDistrictMap = useMemo(() => {
        const map: Record<string, string> = {};
        const unitsList = (window.emsSignupsBoard?.units || config.units || availableUnits || []);
        unitsList.forEach((u: any) => {
            const dist = (u.district || '').trim();
            if (dist) {
                if (u.name) map[u.name] = dist;
                if (u.short_code) map[u.short_code] = dist;
                if (u.unit_id) map[String(u.unit_id)] = dist;
            }
        });
        return map;
    }, [config.units, availableUnits]);

    const uniqueDistricts = useMemo(() => {
        const set = new Set<string>();
        const unitsList = (window.emsSignupsBoard?.units || config.units || availableUnits || []);
        unitsList.forEach((u: any) => {
            const dist = (u.district || '').trim();
            if (dist && dist.toLowerCase() !== 'unassigned') set.add(dist);
        });
        signups.forEach((s: any) => {
            const dist = (s.district || unitDistrictMap[s.unit_name] || unitDistrictMap[String(s.unit_id)] || '').trim();
            if (dist && dist.toLowerCase() !== 'unassigned') set.add(dist);
        });
        return Array.from(set).sort((a, b) => a.localeCompare(b));
    }, [config.units, availableUnits, signups, unitDistrictMap]);

    // Filter signups in memory
    const filteredSignups = signups.filter(s => {
        if (typeFilter !== 'all' && s.type !== typeFilter) return false;
        if (levelFilter !== 'all' && (s.dofe_level || '').toLowerCase() !== levelFilter.toLowerCase()) return false;
        if (expeditionTypeFilter !== 'all') {
            const expedType = normalize(s.expedition_preferences?.exped_type || '');
            if (expedType !== normalize(expeditionTypeFilter)) return false;
        }
        if (unitFilter !== 'all' && (s.unit_name || 'Unassigned') !== unitFilter) return false;
        if (districtFilter !== 'all') {
            const itemDistrict = (s.district || unitDistrictMap[s.unit_name] || unitDistrictMap[String(s.unit_id)] || '').trim() || 'Unassigned';
            if (normalize(itemDistrict) !== normalize(districtFilter)) return false;
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
        if (anomalyFilter !== 'all') {
            const key = getSignupRecordKey(s);
            if (anomalyFilter === 'no_expedition' && !dashboardMetrics.participantWithoutExpedition.signupKeys.has(key)) return false;
            if (anomalyFilter === 'no_participant' && !dashboardMetrics.expeditionWithoutParticipant.signupKeys.has(key)) return false;
            if (anomalyFilter === 'duplicate' && !dashboardMetrics.duplicates.signupKeys.has(key)) return false;
            if (anomalyFilter === 'unmatched_osm' && !dashboardMetrics.unmatchedOsm.signupKeys.has(key)) return false;
        }
        return true;
    });

    // Build consolidated Explorer rows from filteredSignups (indexed by Explorer and Level)
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
                const dofeWithNum = entries.find((e: any) => e.dofe_number);
                const transferReq = entries.some((e: any) => e.dofe_registered === 'y-other');
                const latestCreatedAt = entries.reduce((latest: string, e: any) => {
                    if (!e.created_at) return latest;
                    return !latest || e.created_at > latest ? e.created_at : latest;
                }, '');

                const baseKey = bucket.scoutId !== 0 ? `scout_${bucket.scoutId}` : `explorer_${primary.type}_${primary.id}_${idx}`;
                rows.push({
                    rowKey: `${baseKey}_lvl_${lvl || 'none'}_p${participantEntries.length}_e${expeditionEntries.length}`,
                    scout_id: bucket.scoutId,
                    explorer_first_name: primary.explorer_first_name || '',
                    explorer_last_name: primary.explorer_last_name || '',
                    explorer_email: entries.find((e: any) => e.explorer_email)?.explorer_email || '',
                    parent_email: entries.find((e: any) => e.parent_email)?.parent_email || '',
                    parent_user_id: entries.find((e: any) => e.parent_user_id)?.parent_user_id || 0,
                    unit_name: entries.find((e: any) => e.unit_name && e.unit_name !== 'Unassigned')?.unit_name || primary.unit_name || 'Unassigned',
                    dofe_level: lvl || primary.dofe_level || '',
                    dofe_number: dofeWithNum ? dofeWithNum.dofe_number : null,
                    dofe_registered: transferReq ? 'y-other' : (primary.dofe_registered || 'n'),
                    expedition_preferences: expedWithPrefs ? expedWithPrefs.expedition_preferences : null,
                    created_at: latestCreatedAt,
                    participantEntries,
                    expeditionEntries,
                    entries,
                });
            });
        });

        return rows;
    }, [filteredSignups]);

    // Entries belonging to the currently selected explorer in the Inspector Panel
    const selectedExplorerEntries = useMemo(() => {
        if (!selectedSignup) return [];
        const bucket = allExplorerBuckets.find((b) =>
            b.items.some((item: any) => item.id === selectedSignup.id && item.type === selectedSignup.type)
        );
        if (!bucket) return [selectedSignup];
        const parts = bucket.items.filter((i: any) => i.type === 'participant');
        const expeds = bucket.items.filter((i: any) => i.type === 'expedition');
        return [...parts, ...expeds];
    }, [selectedSignup, allExplorerBuckets]);

    // Unique units for ESU filter
    const uniqueUnits = Array.from(new Set(signups.map(s => s.unit_name || 'Unassigned'))).filter(Boolean);

    const getSortValue = (item: any, key: string) => {
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

    // Sort explorer rows
    const sortedSignups = [...explorerRows].sort((a, b) => {
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
            const groupsMap: { [key: string]: { title: string, items: any[] } } = {};
            sortedSignups.forEach(row => {
                const key = row.scout_id !== 0
                    ? `scout_${row.scout_id}`
                    : `guest_${normalize(row.explorer_first_name)}_${normalize(row.explorer_last_name)}_${normalize(row.parent_email)}`;
                const title = row.scout_id !== 0
                    ? `${row.explorer_first_name} ${row.explorer_last_name} (Scout ID: ${row.scout_id})`
                    : `${row.explorer_first_name} ${row.explorer_last_name} (Guest)`;
                if (!groupsMap[key]) {
                    groupsMap[key] = { title, items: [] };
                }
                groupsMap[key].items.push(row);
            });
            return Object.keys(groupsMap).sort((a, b) => {
                return groupsMap[a].title.localeCompare(groupsMap[b].title);
            }).map(key => ({
                title: groupsMap[key].title,
                items: groupsMap[key].items,
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

    const totalItems = sortedSignups.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));

    // Paginate explorer rows across groups while keeping group headers intact
    const getPaginatedGroupedSignups = () => {
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = currentPage * itemsPerPage;

        if (grouping === 'none') {
            return [{ title: null, items: sortedSignups.slice(startIndex, endIndex) }];
        }

        const result: Array<{ title: string | null; items: any[] }> = [];
        let offset = 0;

        for (const group of groupedSignups) {
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
    const activeUnprocessedSignups = sortedSignups
        .map((row) => row.entries[0])
        .filter((s) => s && s.signup_status === 'submitted');
    const currentUnprocessedIndex = selectedSignup
        ? activeUnprocessedSignups.findIndex((s) =>
              selectedExplorerEntries.some((entry: any) => entry.id === s.id && entry.type === s.type)
          )
        : -1;

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
            return <span title="No Prior Award"><FontAwesomeIcon icon={faXmark} className="ems-text-muted" /></span>;
        }

        const completions = signup.dofe_level === 'silver' ? signup.bronze_completion : signup.silver_completion;
        if (!completions) {
            return <span title="No Prior Award"><FontAwesomeIcon icon={faXmark} className="ems-text-muted" /></span>;
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
            return <span title="No Prior Award"><FontAwesomeIcon icon={faXmark} className="ems-text-muted" /></span>;
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

                {/* Top View Tab Navigation */}
                <div className="ems-tab-nav">
                    <button
                        type="button"
                        className={`ems-tab-nav__button ${activeViewTab === 'dashboard' ? 'ems-tab-nav__button--active' : ''}`}
                        onClick={() => {
                            setSelectedSignup(null);
                            setActiveViewTab('dashboard');
                        }}
                    >
                        Dashboard
                    </button>
                    <button
                        type="button"
                        className={`ems-tab-nav__button ${activeViewTab === 'table' ? 'ems-tab-nav__button--active' : ''}`}
                        onClick={() => setActiveViewTab('table')}
                    >
                        Signups Table ({explorerRows.length})
                    </button>
                </div>

                {activeViewTab === 'dashboard' ? (
                    <SignupsDashboard
                        signups={signups}
                        onNavigateToTable={handleNavigateToTable}
                    />
                ) : (
                    <>
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

                                {/* Expedition Type selector */}
                                <div className="ems-flex-center ems-gap-8">
                                    <label htmlFor="expedition-type-filter" className="ems-toolbar__label">Expedition Type:</label>
                                    <select
                                        id="expedition-type-filter"
                                        value={expeditionTypeFilter}
                                        onChange={(e) => setExpeditionTypeFilter(e.target.value)}
                                        className="ems-select"
                                    >
                                        <option value="all">All Expeditions</option>
                                        <option value="hillwalking">Hillwalking</option>
                                        <option value="biking">Biking</option>
                                        <option value="paddling">Paddling</option>
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

                                {/* District selector */}
                                <div className="ems-flex-center ems-gap-8">
                                    <label htmlFor="district-filter" className="ems-toolbar__label">Filter District:</label>
                                    <select
                                        id="district-filter"
                                        value={districtFilter}
                                        onChange={(e) => setDistrictFilter(e.target.value)}
                                        className="ems-select"
                                    >
                                        <option value="all">All Districts</option>
                                        {uniqueDistricts.map(d => (
                                            <option key={d} value={d}>{d}</option>
                                        ))}
                                        <option value="unassigned">Unassigned</option>
                                    </select>
                                </div>

                                {/* Search Explorer */}
                                <div className="ems-flex-center ems-gap-8">
                                    <label htmlFor="explorer-search" className="ems-toolbar__label">Search Explorer:</label>
                                    <input
                                        type="text"
                                        id="explorer-search"
                                        value={searchQuery}
                                        onChange={(e) => setSearchQuery(e.target.value)}
                                        placeholder="Search by name..."
                                        className="ems-input"
                                    />
                                </div>

                                {/* Quick View / Anomaly selector */}
                                <div className="ems-flex-center ems-gap-8">
                                    <label htmlFor="anomaly-filter" className="ems-toolbar__label">Filter View:</label>
                                    <select
                                        id="anomaly-filter"
                                        value={anomalyFilter}
                                        onChange={(e) => setAnomalyFilter(e.target.value as any)}
                                        className="ems-select"
                                    >
                                        <option value="all">All Records</option>
                                        <option value="no_expedition">Participant Only (No Expedition)</option>
                                        <option value="no_participant">Expedition Only (No Participant Place)</option>
                                        <option value="duplicate">Duplicate Signups</option>
                                        <option value="unmatched_osm">No Synced OSM Record</option>
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

                        {anomalyFilter !== 'all' && (
                            <div className="ems-dashboard-active-filter-banner">
                                <span>
                                    <strong>Active Dashboard Filter:</strong>{' '}
                                    {anomalyFilter === 'no_expedition'
                                        ? 'Showing explorers who signed up for a Participant Place without a matching Expedition Preference.'
                                        : anomalyFilter === 'no_participant'
                                        ? 'Showing explorers who signed up for an Expedition Preference without a matching Participant Place.'
                                        : anomalyFilter === 'duplicate'
                                        ? 'Showing duplicate signups (multiple active submissions for the same Form Type and DofE Level).'
                                        : 'Showing signups with no synced OSM Explorer record.'}
                                </span>
                                <button
                                    type="button"
                                    className="button button-small"
                                    onClick={() => {
                                        setAnomalyFilter('all');
                                        setTypeFilter('all');
                                        setLevelFilter('all');
                                    }}
                                >
                                    Clear Filter
                                </button>
                            </div>
                        )}

                        {/* Table Grid */}
                        <div className="ems-panel">
                            <div className="ems-signups-table-wrap">
                                <table className="ems-table">
                                    <thead>
                                        <tr>
                                            <th className="ems-table-cell--center ems-m-0">OSM Sync</th>
                                            {renderHeader('Explorer Name', 'explorer_first_name')}
                                            {renderHeader('Level', 'dofe_level')}
                                            {renderHeader('Participant Place', 'participant')}
                                            {renderHeader('Expedition Signup', 'expedition_signup')}
                                            {renderHeader('Expedition Type', 'expedition')}
                                            {renderHeader('Unit', 'unit_name')}
                                            {renderHeader('Email', 'explorer_email')}
                                            {renderHeader('DofE Number', 'dofe_number')}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {paginatedGroupedSignups.length === 0 || (paginatedGroupedSignups.length === 1 && paginatedGroupedSignups[0].items.length === 0) ? (
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
                                                    {g.items.map((row: any) => {
                                                        const isRowSelected = Boolean(
                                                            selectedSignup &&
                                                            row.entries.some(
                                                                (entry: any) => entry.id === selectedSignup.id && entry.type === selectedSignup.type
                                                            )
                                                        );
                                                        return (
                                                            <tr 
                                                                key={row.rowKey} 
                                                                onClick={() => setSelectedSignup(row.entries[0])}
                                                                className={`ems-row-hoverable ${isRowSelected ? 'ems-table-row--selected' : ''}`}
                                                            >
                                                                <td className="ems-table-cell--center">
                                                                    {row.scout_id !== 0 ? (
                                                                        <span title="Synced with OSM" className="ems-fa-full">✓</span>
                                                                    ) : (
                                                                        <span title="Guest Roster / Unmatched" className="ems-fa-warning">
                                                                            <FontAwesomeIcon icon={faTriangleExclamation} />
                                                                        </span>
                                                                    )}
                                                                </td>
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
                                                                        <span
                                                                            key="has-place"
                                                                            title="Participant Place submitted"
                                                                            className="ems-fa-full ems-cursor-pointer"
                                                                            onClick={(e) => {
                                                                                e.stopPropagation();
                                                                                setSelectedSignup(row.participantEntries[0]);
                                                                            }}
                                                                        >
                                                                            {`✓${row.participantEntries.length > 1 ? ` (${row.participantEntries.length})` : ''}`}
                                                                        </span>
                                                                    ) : (
                                                                        <span key="no-place">—</span>
                                                                    )}
                                                                </td>
                                                                <td className="ems-table-cell--center">
                                                                    {row.expeditionEntries.length > 0 ? (
                                                                        <span
                                                                            key="has-exped"
                                                                            title="Expedition Signup submitted"
                                                                            className="ems-fa-full ems-cursor-pointer"
                                                                            onClick={(e) => {
                                                                                e.stopPropagation();
                                                                                setSelectedSignup(row.expeditionEntries[0]);
                                                                            }}
                                                                        >
                                                                            {`✓${row.expeditionEntries.length > 1 ? ` (${row.expeditionEntries.length})` : ''}`}
                                                                        </span>
                                                                    ) : (
                                                                        <span key="no-exped">—</span>
                                                                    )}
                                                                </td>
                                                                <td>
                                                                    {row.expedition_preferences?.exped_type ? (
                                                                        <span key="has-exped-type" className="ems-pill ems-pill--transport">
                                                                            {normalize(row.expedition_preferences.exped_type) === 'hillwalking' ? (
                                                                                <FontAwesomeIcon icon={faPersonHiking} className="ems-pill-icon" />
                                                                            ) : normalize(row.expedition_preferences.exped_type) === 'biking' ? (
                                                                                <FontAwesomeIcon icon={faBicycle} className="ems-pill-icon" />
                                                                            ) : (
                                                                                <FontAwesomeIcon icon={faWater} className="ems-pill-icon" />
                                                                            )}
                                                                            {row.expedition_preferences.exped_type}
                                                                        </span>
                                                                    ) : (
                                                                        <span key="no-exped-type">—</span>
                                                                    )}
                                                                </td>
                                                                <td>{row.unit_name || 'Unassigned'}</td>
                                                                <td>{row.explorer_email || '—'}</td>
                                                                <td className="ems-monospace">
                                                                    <span>{row.dofe_number || '—'}</span>
                                                                    {row.dofe_registered === 'y-other' && (
                                                                        <div className="ems-signups-transfer-warning">
                                                                            <FontAwesomeIcon icon={faTriangleExclamation} /> Transfer Req.
                                                                        </div>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        );
                                                    })}
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
                    </>
                )}
            </div>

            {/* Inspector Panel - Slide-Out/Docked Layout */}
            {selectedSignup && (
                <div
                    key={`inspector_${selectedSignup.type}_${selectedSignup.id}`}
                    ref={inspectorRef}
                    className={`ems-signups-inspector ems-signups-inspector--${selectedSignup.type}`}
                >
                    {/* Header */}
                    <div className={`ems-signups-inspector__header ems-signups-inspector__header--${selectedSignup.type}`}>
                        <div className="ems-flex-center ems-gap-6">
                            <span style={{ fontSize: '16px' }}>
                                {selectedSignup.type === 'participant' ? (
                                    <FontAwesomeIcon icon={faClipboardList} />
                                ) : (
                                    <FontAwesomeIcon icon={faCampground} />
                                )}
                            </span>
                            <h3 className="ems-m-0 ems-font-semibold" style={{ fontSize: '15px', color: '#1d2327' }}>
                                {selectedSignup.type === 'participant' ? 'Participant Place Details' : 'Expedition Signup Details'}
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
                        {/* Explorer Entry Selector */}
                        <div className="ems-signups-matching-panel ems-mb-16">
                            <label htmlFor="inspector-entry-select" className="ems-signups-inspector__label">
                                Signup Entry ({selectedExplorerEntries.length})
                            </label>
                            <div className="ems-mt-4">
                                <select
                                    id="inspector-entry-select"
                                    aria-label="Select Signup Entry"
                                    className="ems-select ems-w-full"
                                    value={`${selectedSignup.type}_${selectedSignup.id}`}
                                    onChange={(e) => {
                                        const found = selectedExplorerEntries.find(
                                            (entry: any) => `${entry.type}_${entry.id}` === e.target.value
                                        );
                                        if (found) {
                                            setSelectedSignup(found);
                                        }
                                    }}
                                >
                                    {selectedExplorerEntries.map((entry: any) => (
                                        <option key={`${entry.type}_${entry.id}`} value={`${entry.type}_${entry.id}`}>
                                            {entry.type === 'participant' ? 'Participant Place' : 'Expedition Signup'} — {(entry.dofe_level || '').toUpperCase()} (#{entry.id}{entry.created_at ? `, ${entry.created_at.substring(0, 10)}` : ''})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {/* Matching Widget */}
                        <div className="ems-signups-matching-panel ems-mb-16">
                            <div className="ems-signups-inspector__section-title ems-mt-0">OSM Profile Linkage</div>
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
                                    {(() => {
                                        const pairedScoutId = selectedExplorerEntries?.find((e: any) => Number(e.scout_id || 0) !== 0)?.scout_id;
                                        if (pairedScoutId) {
                                            return (
                                                <div className="ems-signups-paired-sync">
                                                    <div className="ems-small-text ems-mb-8">
                                                        Paired registration is already linked to OSM (Scout ID: <strong>{pairedScoutId}</strong>).
                                                    </div>
                                                    <button
                                                        type="button"
                                                        className="button button-small button-primary"
                                                        onClick={() => handleReconcile(selectedSignup.id, selectedSignup.type, Number(pairedScoutId))}
                                                    >
                                                        Sync with Paired OSM Record
                                                    </button>
                                                </div>
                                            );
                                        }
                                        return (
                                            <div className="ems-signups-unmatched-alert">
                                                <FontAwesomeIcon icon={faTriangleExclamation} /> Unmatched guest registration.
                                            </div>
                                        );
                                    })()}
                                    
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
                                <div className="ems-flex-center ems-gap-8 ems-mt-4">
                                    <select
                                        aria-label="Change Level"
                                        value={selectedSignup.dofe_level || 'bronze'}
                                        onChange={(e) => handleUpdateLevel(selectedSignup.id, selectedSignup.type, e.target.value)}
                                        disabled={savingField === 'level'}
                                        className="ems-select ems-select-sm"
                                    >
                                        <option value="bronze">Bronze</option>
                                        <option value="silver">Silver</option>
                                        <option value="gold">Gold</option>
                                    </select>
                                    {savingField === 'level' && (
                                        <span className="ems-field-status ems-field-status--saving" aria-label="Saving level…">
                                            <span className="ems-spinner ems-spinner-sm" /> Saving…
                                        </span>
                                    )}
                                    {levelSaveSuccess && (
                                        <span className="ems-field-status ems-field-status--success" data-testid="level-save-success">
                                            <FontAwesomeIcon icon={faCheck} /> Saved
                                        </span>
                                    )}
                                </div>
                            </div>

                            <div className="ems-signups-detail-item ems-signups-detail-item--full">
                                <span className="ems-signups-inspector__label">Unit</span>
                                <div className="ems-flex-center ems-gap-8 ems-mt-4">
                                    <select
                                        aria-label="Change Unit"
                                        value={editedUnitId}
                                        onChange={(e) => {
                                            const newUnitId = e.target.value;
                                            setEditedUnitId(newUnitId);
                                            if (newUnitId) {
                                                handleUpdateUnit(selectedSignup.id, selectedSignup.type, Number(newUnitId));
                                            }
                                        }}
                                        disabled={savingField === 'unit'}
                                        className="ems-select"
                                    >
                                        <option value="">Select unit...</option>
                                        {availableUnits.map((u: any, idx: number) => (
                                            <option key={u.unit_id ?? u.id ?? u.name ?? idx} value={String(u.unit_id ?? u.id ?? u.name)}>
                                                {u.short_code || u.name}
                                                {u.name && u.short_code && u.name !== u.short_code ? ` (${u.name})` : ''}
                                            </option>
                                        ))}
                                    </select>
                                    {savingField === 'unit' && (
                                        <span className="ems-field-status ems-field-status--saving" aria-label="Saving unit…">
                                            <span className="ems-spinner ems-spinner-sm" /> Saving…
                                        </span>
                                    )}
                                    {unitSaveSuccess && (
                                        <span className="ems-field-status ems-field-status--success" data-testid="unit-save-success">
                                            <FontAwesomeIcon icon={faCheck} /> Saved
                                        </span>
                                    )}
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
                                                    <FontAwesomeIcon icon={faTriangleExclamation} /> <strong>Transfer Required</strong>
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
                                            value={selectedSignup.dofe_number || ''} 
                                            readOnly
                                            placeholder="—"
                                            className="ems-signups-inspector__input ems-monospace"
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
                                            {selectedSignup.expedition_preferences?.exped_type === 'Hillwalking' ? (
                                                <FontAwesomeIcon icon={faPersonHiking} className="ems-pill-icon" />
                                            ) : selectedSignup.expedition_preferences?.exped_type === 'Biking' ? (
                                                <FontAwesomeIcon icon={faBicycle} className="ems-pill-icon" />
                                            ) : (
                                                <FontAwesomeIcon icon={faWater} className="ems-pill-icon" />
                                            )}{' '}
                                            {selectedSignup.expedition_preferences?.exped_type || '—'}
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
                                                    <div>
                                                        <strong>Practice:</strong>{' '}
                                                        {Array.isArray(selectedSignup.expedition_preferences.exped_practice_dates)
                                                            ? selectedSignup.expedition_preferences.exped_practice_dates.join(', ')
                                                            : (selectedSignup.expedition_preferences.exped_practice_dates || '—')}
                                                    </div>
                                                    <div>
                                                        <strong>Qualifier:</strong>{' '}
                                                        {Array.isArray(selectedSignup.expedition_preferences.exped_qualifier_dates)
                                                            ? selectedSignup.expedition_preferences.exped_qualifier_dates.join(', ')
                                                            : (selectedSignup.expedition_preferences.exped_qualifier_dates || '—')}
                                                    </div>
                                                    <div>
                                                        <strong>Teammates:</strong>{' '}
                                                        {selectedSignup.expedition_preferences.exped_team_names || '—'}
                                                    </div>
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
                                    onClick={() => handleProcessParticipant(selectedSignup.id, selectedSignup.dofe_number || '')}
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
