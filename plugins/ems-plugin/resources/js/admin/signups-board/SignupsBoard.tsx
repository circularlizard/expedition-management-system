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
    faCheck,
    faCircleQuestion
} from '@fortawesome/free-solid-svg-icons';
import SignupsDashboard, { TableDrilldownFilters } from './SignupsDashboard';
import { normalizeExpeditionType } from './dashboardMetrics';
import UnitChaserView from './UnitChaserView';
import Explorer360Drawer from '../shared/Explorer360Drawer';
import { ModuleErrorBoundary } from '../../shared/ModuleErrorBoundary';
import { computeSignupsDashboardMetrics, getMatchSuggestions as findMatchSuggestions, getSignupRecordKey, groupSignupsByExplorerIdentity, isSignupSyncedWithOsm } from './dashboardMetrics';
import {
    normalize,
    isSectionCompleted,
    getUnitDistrictMap,
    getUniqueUnits,
    getUniqueDistricts,
    getSignupsSortValue,
    partitionGroupedRows,
    paginateGroupedRows
} from './tableUtils';

interface SignupsConfig {
    root_url: string;
    nonce: string;
    plugin_url?: string;
    units?: any[];
}

declare global {
    interface Window {
        emsSignupsBoard: SignupsConfig;
    }
}

export default function SignupsBoard() {
    const config = window.emsSignupsBoard || { root_url: '', nonce: '' };
    const pluginUrl = config.plugin_url || '/wp-content/plugins/ems-plugin/';
    const osmIconUrl = `${pluginUrl}assets/images/osm_icon.png`;
    const osmIconInverseUrl = `${pluginUrl}assets/images/osm_icon_inverse.png`;

    const [activeViewTab, setActiveViewTab] = useState<'dashboard' | 'table' | 'anomalies'>('dashboard');
    const [signups, setSignups] = useState<any[]>([]);
    const [explorers, setExplorers] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Filters
    const [statusFilter, setStatusFilter] = useState<string>('submitted');
    const [stageFilter, setStageFilter] = useState<string>('all');
    const [typeFilter, setTypeFilter] = useState<string>('all');
    const [levelFilter, setLevelFilter] = useState<string>('all');
    const [expeditionTypeFilter, setExpeditionTypeFilter] = useState<string>('all');
    const [unitFilter, setUnitFilter] = useState<string>('all');
    const [districtFilter, setDistrictFilter] = useState<string>('all');
    const [searchQuery, setSearchQuery] = useState<string>('');
    const [anomalyFilter, setAnomalyFilter] = useState<'all' | 'no_expedition' | 'no_participant' | 'level_mismatch' | 'duplicate' | 'unmatched_osm' | 'pending_osm_sync' | 'missing_edofe' | 'unvalidated_asn'>('all');
    const [selectedRowKeys, setSelectedRowKeys] = useState<Set<string>>(new Set());
    const [bulkStage, setBulkStage] = useState<string>('team_allocated');
    const [chaseHistory, setChaseHistory] = useState<any[]>([]);

    // Grouping & Sorting
    const [grouping, setGrouping] = useState<'none' | 'level' | 'expedition' | 'unit' | 'explorer' | 'parent'>('none');
    const [sortKey, setSortKey] = useState<string>('created_at');
    const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');

    // Selected signup & Inspector Panel
    const [selectedSignup, setSelectedSignup] = useState<any | null>(null);
    const [asnModalRow, setAsnModalRow] = useState<any | null>(null);
    const [editedUnitId, setEditedUnitId] = useState<string>('');
    const [savingField, setSavingField] = useState<'level' | 'unit' | 'asn' | 'stage' | 'place_allocated' | null>(null);
    const [levelSaveSuccess, setLevelSaveSuccess] = useState<boolean>(false);
    const [unitSaveSuccess, setUnitSaveSuccess] = useState<boolean>(false);
    const [asnSaveSuccess, setAsnSaveSuccess] = useState<boolean>(false);
    const [stageSaveSuccess, setStageSaveSuccess] = useState<boolean>(false);
    const [placeAllocatedSaveSuccess, setPlaceAllocatedSaveSuccess] = useState<boolean>(false);
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

    useEffect(() => {
        if (!asnSaveSuccess) return;
        const timer = setTimeout(() => setAsnSaveSuccess(false), 3500);
        return () => clearTimeout(timer);
    }, [asnSaveSuccess]);

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

    const [chaseHistoryLoading, setChaseHistoryLoading] = useState<boolean>(false);
    const [chaseHistoryLoaded, setChaseHistoryLoaded] = useState<boolean>(false);

    useEffect(() => {
        setChaseHistory([]);
        setChaseHistoryLoaded(false);
    }, [selectedSignup?.id, selectedSignup?.type]);

    const handleLoadChaseHistory = async () => {
        if (!selectedSignup || !config.root_url) return;
        setChaseHistoryLoading(true);
        try {
            const res = await fetch(
                `${config.root_url}/signups/${selectedSignup.type}/${selectedSignup.id}/history?scout_id=${Number(selectedSignup.scout_id || 0)}`,
                { headers: { 'X-WP-Nonce': config.nonce } }
            );
            const data = res && res.ok ? await res.json() : null;
            setChaseHistory(Array.isArray(data?.history) ? data.history : []);
            setChaseHistoryLoaded(true);
        } catch {
            setChaseHistory([]);
            setChaseHistoryLoaded(true);
        } finally {
            setChaseHistoryLoading(false);
        }
    };

    const handleUpdateProcessingStatus = async (id: number, type: string, kanbanStage: string) => {
        const previousStage = signups.find((s) => s.id === id && s.type === type)?.kanban_stage || 'new';
        setSavingField('stage');
        setStageSaveSuccess(false);
        setError(null);

        // Optimistic update so controlled <select> reflects selection immediately.
        setSignups((prev) =>
            prev.map((s) => (s.id === id && s.type === type ? { ...s, kanban_stage: kanbanStage } : s))
        );
        if (selectedSignup && selectedSignup.id === id && selectedSignup.type === type) {
            setSelectedSignup((prev: any) => (prev ? { ...prev, kanban_stage: kanbanStage } : prev));
        }

        try {
            const res = await fetch(`${config.root_url}/signups/${id}/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({ type, kanban_stage: kanbanStage }),
            });
            if (!res.ok) {
                throw new Error('Failed to update processing status.');
            }
            const resData = await res.json().catch(() => ({}));
            const syncedIds: number[] = Array.isArray(resData?.synced_expedition_ids)
                ? resData.synced_expedition_ids.map(Number)
                : [];
            if (type === 'participant' && syncedIds.length > 0) {
                const allocated = kanbanStage === 'place_allocated';
                setSignups((prev) =>
                    prev.map((s) => {
                        if (s.type !== 'expedition' || !syncedIds.includes(Number(s.id))) return s;
                        const curAnomalies: string[] = Array.isArray(s.anomalies) ? s.anomalies : [];
                        const nextAnomalies = allocated
                            ? curAnomalies.filter((a) => a !== 'expedition_without_place')
                            : curAnomalies;
                        return {
                            ...s,
                            participant_place_allocated: allocated,
                            participant_signup_id: allocated ? id : null,
                            anomalies: nextAnomalies,
                        };
                    })
                );
            }
            setStageSaveSuccess(true);
            setTimeout(() => setStageSaveSuccess(false), 3000);
        } catch (err: any) {
            // Revert on failure
            setSignups((prev) =>
                prev.map((s) => (s.id === id && s.type === type ? { ...s, kanban_stage: previousStage } : s))
            );
            if (selectedSignup && selectedSignup.id === id && selectedSignup.type === type) {
                setSelectedSignup((prev: any) => (prev ? { ...prev, kanban_stage: previousStage } : prev));
            }
            setError(err?.message || 'Failed to update processing status.');
        } finally {
            setSavingField(null);
        }
    };

    const handleBulkUpdateStatus = async () => {
        if (selectedRowKeys.size === 0) return;
        const items: { id: number; type: string }[] = [];
        explorerRows.forEach((row) => {
            if (selectedRowKeys.has(row.rowKey) && row.entries[0]) {
                items.push({ id: row.entries[0].id, type: row.entries[0].type });
            }
        });
        if (items.length === 0) return;
        const res = await fetch(`${config.root_url}/signups/bulk-status`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce,
            },
            body: JSON.stringify({ items, kanban_stage: bulkStage }),
        });
        if (res.ok) {
            setSelectedRowKeys(new Set());
            fetchData();
        }
    };

    const handleTogglePlaceAllocated = async (expeditionSignupId: number, nextAllocated: boolean) => {
        const prevRow = signups.find((s) => s.id === expeditionSignupId && s.type === 'expedition');
        const prevAllocated = Boolean(prevRow?.participant_place_allocated);
        setSavingField('place_allocated');
        setPlaceAllocatedSaveSuccess(false);
        setError(null);

        const applyLocal = (allocated: boolean) => {
            setSignups((prev) =>
                prev.map((s) => {
                    if (s.id !== expeditionSignupId || s.type !== 'expedition') return s;
                    const curAnomalies: string[] = Array.isArray(s.anomalies) ? s.anomalies : [];
                    const nextAnomalies = allocated
                        ? curAnomalies.filter((a) => a !== 'expedition_without_place')
                        : curAnomalies;
                    return { ...s, participant_place_allocated: allocated, anomalies: nextAnomalies };
                })
            );
            if (selectedSignup && selectedSignup.id === expeditionSignupId && selectedSignup.type === 'expedition') {
                setSelectedSignup((prev: any) =>
                    prev ? { ...prev, participant_place_allocated: allocated } : prev
                );
            }
        };

        applyLocal(nextAllocated);

        try {
            const res = await fetch(`${config.root_url}/signups/expeditions/${expeditionSignupId}/place-allocated`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({ participant_place_allocated: nextAllocated }),
            });
            if (!res.ok) {
                throw new Error('Failed to update Participant Place Allocated.');
            }
            setPlaceAllocatedSaveSuccess(true);
            setTimeout(() => setPlaceAllocatedSaveSuccess(false), 3000);
        } catch (err: any) {
            applyLocal(prevAllocated);
            setError(err?.message || 'Failed to update Participant Place Allocated.');
        } finally {
            setSavingField(null);
        }
    };

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
                setAsnSaveSuccess(false);
                setSavingField(null);
                setUnlinkError(null);
                setReconcileSearch('');
                prevEntryKeyRef.current = selectedEntryKey;
            }
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
        if (filters.unitFilter !== undefined) {
            setUnitFilter(filters.unitFilter);
        }
        if (filters.districtFilter !== undefined) {
            setDistrictFilter(filters.districtFilter);
        }
        setAnomalyFilter(filters.anomalyFilter ?? 'all');
        setGrouping(filters.grouping ?? 'none');
        setSelectedSignup(null);
        setActiveViewTab('table');
    };

    const handleClearFilters = () => {
        setTypeFilter('all');
        setLevelFilter('all');
        setExpeditionTypeFilter('all');
        setUnitFilter('all');
        setDistrictFilter('all');
        setSearchQuery('');
        setAnomalyFilter('all');
        setCurrentPage(1);
        setSelectedSignup(null);
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

    const handleUpdateAsnValidation = async (signupId: number, validated: number | null) => {
        setSavingField('asn');
        setAsnSaveSuccess(false);
        try {
            const response = await fetch(`${config.root_url}/signups/expeditions/${signupId}/asn-validation`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    asn_validated: validated
                })
            });
            const data = await response.json();
            if (!response.ok || (data.code && data.message)) {
                throw new Error(data.message || 'Failed to update ASN validation');
            }
            setSelectedSignup((prev: any) =>
                prev
                    ? {
                          ...prev,
                          asn_validated: data.asn_validated,
                      }
                    : null
            );
            setAsnSaveSuccess(true);
            fetchData();
        } catch (err: any) {
            alert(err.message);
        } finally {
            setSavingField(null);
        }
    };

    const handleModalUpdateAsn = async (val: number | null) => {
        if (!asnModalRow) return;
        const targetEntry =
            asnModalRow.expeditionEntries?.find(
                (e: any) => e.additional_support_needs && e.additional_support_needs.trim() !== ''
            ) || asnModalRow.expeditionEntries?.[0];
        const signupId = targetEntry ? targetEntry.id : (asnModalRow.id || null);
        if (!signupId) {
            alert('No expedition signup found to update ASN validation.');
            return;
        }
        await handleUpdateAsnValidation(signupId, val);
        setAsnModalRow(null);
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
    const getMatchSuggestions = (signup: any) => findMatchSuggestions(signup, explorers);

    const dashboardMetrics = useMemo(() => computeSignupsDashboardMetrics(signups, explorers), [signups, explorers]);

    const allExplorerBuckets = useMemo(() => groupSignupsByExplorerIdentity(signups), [signups]);

    const unitDistrictMap = useMemo(() => {
        const unitsList = (window.emsSignupsBoard?.units || config.units || availableUnits || []);
        return getUnitDistrictMap(unitsList);
    }, [config.units, availableUnits]);

    const uniqueDistricts = useMemo(() => {
        const unitsList = (window.emsSignupsBoard?.units || config.units || availableUnits || []);
        return getUniqueDistricts(unitsList, signups, unitDistrictMap);
    }, [config.units, availableUnits, signups, unitDistrictMap]);

    // Filter signups in memory
    const filteredSignups = signups.filter(s => {
        if (typeFilter !== 'all' && s.type !== typeFilter) return false;
        if (levelFilter !== 'all' && (s.dofe_level || '').toLowerCase() !== levelFilter.toLowerCase()) return false;
        if (expeditionTypeFilter !== 'all') {
            const rawExpedType = normalize(s.expedition_preferences?.exped_type || '');
            const canonicalExpedType = normalizeExpeditionType(s.expedition_preferences?.exped_type);
            const targetFilter = normalize(expeditionTypeFilter);
            if (canonicalExpedType !== targetFilter && rawExpedType !== targetFilter) return false;
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
        if (stageFilter !== 'all') {
            const curStage = (s.kanban_stage || 'new') === 'to_process' ? 'new' : (s.kanban_stage || 'new');
            if (curStage !== stageFilter) return false;
        }
        if (anomalyFilter !== 'all') {
            const key = getSignupRecordKey(s);
            const serverAnomalies: string[] = Array.isArray(s.anomalies) ? s.anomalies : [];
            if (anomalyFilter === 'no_expedition' && !dashboardMetrics.participantWithoutExpedition.signupKeys.has(key) && !serverAnomalies.includes('place_without_expedition')) return false;
            if (anomalyFilter === 'no_participant' && !dashboardMetrics.expeditionWithoutParticipant.signupKeys.has(key) && !serverAnomalies.includes('expedition_without_place')) return false;
            if (anomalyFilter === 'duplicate' && !dashboardMetrics.duplicates.signupKeys.has(key) && !serverAnomalies.includes('duplicate_signup')) return false;
            if (anomalyFilter === 'unmatched_osm' && !dashboardMetrics.unmatchedOsm.signupKeys.has(key) && !serverAnomalies.includes('unmatched_osm')) return false;
            if (anomalyFilter === 'level_mismatch' && !serverAnomalies.includes('level_mismatch')) return false;
            if (anomalyFilter === 'pending_osm_sync' && !serverAnomalies.includes('pending_osm_sync')) return false;
            if (anomalyFilter === 'missing_edofe' && !serverAnomalies.includes('missing_edofe')) return false;
            if (anomalyFilter === 'unvalidated_asn' && !serverAnomalies.includes('unvalidated_asn')) return false;
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
                const expedWithAsn = expeditionEntries.find((e: any) => e.additional_support_needs && e.additional_support_needs.trim() !== '');
                const asnEntry = expedWithAsn || expeditionEntries[0];
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
                    is_synced_osm: entries.every(isSignupSyncedWithOsm),
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
                    additional_support_needs: asnEntry?.additional_support_needs || '',
                    asn_validated: asnEntry?.asn_validated !== undefined ? asnEntry.asn_validated : null,
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

    // Unique units for ESU filter (sorted alphabetically)
    const uniqueUnits = useMemo(() => getUniqueUnits(signups), [signups]);

    // Sort explorer rows
    const sortedSignups = useMemo(() => {
        return [...explorerRows].sort((a, b) => {
            const valA = getSignupsSortValue(a, sortKey);
            const valB = getSignupsSortValue(b, sortKey);
            if (valA < valB) return sortOrder === 'asc' ? -1 : 1;
            if (valA > valB) return sortOrder === 'asc' ? 1 : -1;
            const nameA = `${a.explorer_first_name || ''} ${a.explorer_last_name || ''}`.trim().toLowerCase();
            const nameB = `${b.explorer_first_name || ''} ${b.explorer_last_name || ''}`.trim().toLowerCase();
            return nameA.localeCompare(nameB);
        });
    }, [explorerRows, sortKey, sortOrder]);

    // Grouping partitions sorted rows so table sorting is preserved inside groups
    const groupedSignups = useMemo(() => {
        return partitionGroupedRows(sortedSignups, grouping);
    }, [sortedSignups, grouping]);

    const totalItems = sortedSignups.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / itemsPerPage));

    // Paginate explorer rows across groups while keeping group headers intact
    const paginatedGroupedSignups = useMemo(() => {
        return paginateGroupedRows(groupedSignups, currentPage, itemsPerPage);
    }, [groupedSignups, currentPage, itemsPerPage]);

    const toggleSort = (key: string) => {
        setCurrentPage(1);
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
                <nav className="nav-tab-wrapper ems-nav-tab-wrapper" aria-label="Signups view">
                    <button
                        type="button"
                        className={`nav-tab ${activeViewTab === 'dashboard' ? 'nav-tab-active' : ''}`}
                        onClick={() => {
                            setSelectedSignup(null);
                            setActiveViewTab('dashboard');
                        }}
                    >
                        Dashboard
                    </button>
                    <button
                        type="button"
                        className={`nav-tab ${activeViewTab === 'table' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveViewTab('table')}
                    >
                        Signups Table ({explorerRows.length})
                    </button>
                    <button
                        type="button"
                        className={`nav-tab ${activeViewTab === 'anomalies' ? 'nav-tab-active' : ''}`}
                        onClick={() => {
                            setSelectedSignup(null);
                            setActiveViewTab('anomalies');
                        }}
                    >
                        Anomalies &amp; Chasers ({dashboardMetrics.unmatchedOsm.explorersCount + dashboardMetrics.participantWithoutExpedition.totalExplorers + dashboardMetrics.expeditionWithoutParticipant.totalExplorers})
                    </button>
                </nav>

                {activeViewTab === 'dashboard' ? (
                    <ModuleErrorBoundary moduleName="Signups Dashboard">
                        <SignupsDashboard
                            signups={signups}
                            explorers={explorers}
                            units={availableUnits}
                            onNavigateToTable={handleNavigateToTable}
                            onOpenChaserTab={() => {
                                setSelectedSignup(null);
                                setActiveViewTab('anomalies');
                            }}
                        />
                    </ModuleErrorBoundary>
                ) : activeViewTab === 'anomalies' ? (
                    <ModuleErrorBoundary moduleName="Anomalies & Chasers">
                        <UnitChaserView
                            signups={signups}
                            units={availableUnits}
                            onSelectExplorer={(s) => setSelectedSignup(s)}
                            onChaserSent={fetchData}
                        />
                    </ModuleErrorBoundary>
                ) : (
                    <ModuleErrorBoundary moduleName="Signups Table">
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
                                        <option value="level">Level</option>
                                        <option value="expedition">Expedition Type</option>
                                        <option value="unit">Unit</option>
                                        <option value="explorer">Explorer</option>
                                        <option value="parent">Parent</option>
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

                                {/* Processing Status filter */}
                                <div className="ems-flex-center ems-gap-8">
                                    <label htmlFor="stage-filter" className="ems-toolbar__label">Processing Status:</label>
                                    <select
                                        id="stage-filter"
                                        value={stageFilter}
                                        onChange={(e) => setStageFilter(e.target.value)}
                                        className="ems-select"
                                    >
                                        <option value="all">All Statuses</option>
                                        <option value="new">New</option>
                                        <option value="on_hold">On Hold (Place)</option>
                                        <option value="place_allocated">Place Allocated</option>
                                        <option value="team_allocated">Team Allocated</option>
                                        <option value="expedition_completed">Expedition Completed</option>
                                        <option value="withdrawn">Withdrawn</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
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
                                        <option value="level_mismatch">Award Level Mismatch</option>
                                        <option value="duplicate">Duplicate Signups</option>
                                        <option value="unmatched_osm">Unmatched OSM (scout_id=0)</option>
                                        <option value="pending_osm_sync">Pending OSM Sync</option>
                                        <option value="missing_edofe">Missing eDofE Number</option>
                                        <option value="unvalidated_asn">Unvalidated ASN</option>
                                    </select>
                                </div>

                                <button
                                    type="button"
                                    className="button button-secondary"
                                    onClick={handleClearFilters}
                                    data-testid="clear-filters-btn"
                                >
                                    Clear Filters
                                </button>

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

                        {selectedRowKeys.size > 0 && (
                            <div className="ems-signups-toolbar ems-mb-12" style={{ background: '#f0f6fc', padding: '8px 12px', borderRadius: '4px' }}>
                                <span><strong>{selectedRowKeys.size}</strong> selected</span>
                                <select
                                    value={bulkStage}
                                    onChange={(e) => setBulkStage(e.target.value)}
                                    className="ems-select"
                                >
                                    <option value="new">New</option>
                                    <option value="on_hold">On Hold (Place)</option>
                                    <option value="place_allocated">Place Allocated</option>
                                    <option value="team_allocated">Team Allocated</option>
                                    <option value="expedition_completed">Expedition Completed</option>
                                    <option value="withdrawn">Withdrawn</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                                <button type="button" className="button button-primary button-small" onClick={handleBulkUpdateStatus}>
                                    Set Status
                                </button>
                                <button type="button" className="button button-small" onClick={() => setSelectedRowKeys(new Set())}>
                                    Clear Selection
                                </button>
                            </div>
                        )}

                        {/* Table Grid */}
                        <div className="ems-panel">
                            <div className="ems-signups-table-wrap">
                                <table className="ems-table">
                                    <thead>
                                        <tr>
                                            <th style={{ width: '28px' }}>
                                                <input
                                                    type="checkbox"
                                                    aria-label="Select all visible rows"
                                                    checked={explorerRows.length > 0 && explorerRows.every((r) => selectedRowKeys.has(r.rowKey))}
                                                    onChange={(e) => {
                                                        if (e.target.checked) {
                                                            setSelectedRowKeys(new Set(explorerRows.map((r) => r.rowKey)));
                                                        } else {
                                                            setSelectedRowKeys(new Set());
                                                        }
                                                    }}
                                                />
                                            </th>
                                            {renderHeader('OSM Sync', 'osm_sync')}
                                            {renderHeader('Explorer Name', 'explorer_first_name')}
                                            {renderHeader('Level', 'dofe_level')}
                                            {renderHeader('Participant Place', 'participant')}
                                            {renderHeader('Expedition Signup', 'expedition_signup')}
                                            {renderHeader('Expedition Type', 'expedition')}
                                            {renderHeader('ASN', 'asn_validated')}
                                            {renderHeader('Unit', 'unit_name')}
                                            {renderHeader('Email', 'explorer_email')}
                                            {renderHeader('DofE Number', 'dofe_number')}
                                            <th>Processing Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {paginatedGroupedSignups.length === 0 || (paginatedGroupedSignups.length === 1 && paginatedGroupedSignups[0].items.length === 0) ? (
                                            <tr>
                                                <td colSpan={12} className="ems-table-cell--center ems-p-20 ems-meta-text ems-italic">
                                                    No signup records found for this filter state.
                                                </td>
                                            </tr>
                                        ) : (
                                            paginatedGroupedSignups.map(g => (
                                                <React.Fragment key={g.title || 'flat'}>
                                                    {g.title && (
                                                        <tr className="ems-table-group-header">
                                                            <td colSpan={10}>
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
                                                                <td onClick={(e) => e.stopPropagation()}>
                                                                    <input
                                                                        type="checkbox"
                                                                        aria-label={`Select ${row.explorer_first_name} ${row.explorer_last_name}`}
                                                                        checked={selectedRowKeys.has(row.rowKey)}
                                                                        onChange={(e) => {
                                                                            setSelectedRowKeys((prev) => {
                                                                                const next = new Set(prev);
                                                                                if (e.target.checked) next.add(row.rowKey);
                                                                                else next.delete(row.rowKey);
                                                                                return next;
                                                                            });
                                                                        }}
                                                                    />
                                                                </td>
                                                                <td className="ems-table-cell--center">
                                                                    {row.is_synced_osm ? (
                                                                        <img
                                                                            src={osmIconUrl}
                                                                            alt="Synced with OSM"
                                                                            title="Synced with OSM"
                                                                            className="ems-osm-sync-icon"
                                                                        />
                                                                    ) : row.scout_id !== 0 ? (
                                                                        <img
                                                                            src={osmIconInverseUrl}
                                                                            alt="Scout ID present, not synced from OSM"
                                                                            title={
                                                                                row.entries?.some(isSignupSyncedWithOsm)
                                                                                    ? 'Paired signup not synced with OSM'
                                                                                    : 'Scout ID present, not synced from OSM'
                                                                            }
                                                                            className="ems-osm-sync-icon"
                                                                        />
                                                                    ) : (
                                                                        <span
                                                                            title="Guest Roster / Unmatched"
                                                                            className="ems-osm-sync-error"
                                                                        >
                                                                            <FontAwesomeIcon icon={faXmark} />
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
                                                                <td className="ems-table-cell--center ems-signups-asn-cell">
                                                                    {row.additional_support_needs && row.additional_support_needs.trim() !== '' ? (
                                                                        row.asn_validated === 1 ? (
                                                                            <span
                                                                                className="ems-pill ems-pill--asn-action ems-cursor-pointer"
                                                                                title="Action Needed — click to review"
                                                                                data-testid="asn-pill-action"
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    setAsnModalRow(row);
                                                                                }}
                                                                            >
                                                                                !
                                                                            </span>
                                                                        ) : row.asn_validated === 0 ? (
                                                                            <span
                                                                                className="ems-pill ems-pill--asn-no-action ems-cursor-pointer"
                                                                                title="No Action Needed — click to review"
                                                                                data-testid="asn-pill-no-action"
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    setAsnModalRow(row);
                                                                                }}
                                                                            >
                                                                                ✓
                                                                            </span>
                                                                        ) : (
                                                                            <span
                                                                                className="ems-pill ems-pill--asn-pending ems-cursor-pointer"
                                                                                title="Unreviewed — click to review"
                                                                                data-testid="asn-pill-pending"
                                                                                onClick={(e) => {
                                                                                    e.stopPropagation();
                                                                                    setAsnModalRow(row);
                                                                                }}
                                                                            >
                                                                                ?
                                                                            </span>
                                                                        )
                                                                    ) : (
                                                                        <span>—</span>
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
                                                                <td onClick={(e) => e.stopPropagation()}>
                                                                    {(() => {
                                                                        const primaryEntry = row.entries[0];
                                                                        const curStage = (primaryEntry?.kanban_stage || 'new') === 'to_process' ? 'new' : (primaryEntry?.kanban_stage || 'new');
                                                                        const isPart = primaryEntry?.type === 'participant';
                                                                        return (
                                                                            <select
                                                                                aria-label="Processing status"
                                                                                value={curStage}
                                                                                onChange={(e) => primaryEntry && handleUpdateProcessingStatus(primaryEntry.id, primaryEntry.type, e.target.value)}
                                                                                className="ems-select ems-select-sm"
                                                                            >
                                                                                <option value="new">New</option>
                                                                                {isPart ? (
                                                                                    <>
                                                                                        <option value="on_hold">On Hold</option>
                                                                                        <option value="place_allocated">Place Allocated</option>
                                                                                        <option value="cancelled">Cancelled</option>
                                                                                    </>
                                                                                ) : (
                                                                                    <>
                                                                                        <option value="team_allocated">Team Allocated</option>
                                                                                        <option value="expedition_completed">Expedition Completed</option>
                                                                                        <option value="withdrawn">Withdrawn</option>
                                                                                        <option value="cancelled">Cancelled</option>
                                                                                    </>
                                                                                )}
                                                                            </select>
                                                                        );
                                                                    })()}
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
                    </ModuleErrorBoundary>
                )}
            </div>

            <Explorer360Drawer
                isOpen={Boolean(selectedSignup)}
                scoutId={selectedSignup && Number(selectedSignup.scout_id || 0) > 0 ? Number(selectedSignup.scout_id) : undefined}
                signupType={selectedSignup?.type}
                signupId={selectedSignup?.id}
                rootUrl={config.root_url}
                nonce={config.nonce}
                units={availableUnits}
                explorers={explorers}
                initialTab="signups"
                fallbackSignups={selectedExplorerEntries}
                unlinkError={unlinkError}
                levelSaveSuccess={levelSaveSuccess}
                unitSaveSuccess={unitSaveSuccess}
                asnSaveSuccess={asnSaveSuccess}
                onClose={() => setSelectedSignup(null)}
                onUpdated={fetchData}
                onReconcile={handleReconcile}
                onUnlink={handleUnlink}
                onArchive={handleArchive}
                onOpenAsnModal={(s) => setAsnModalRow(s)}
                onAsnChanged={handleUpdateAsnValidation}
                onOpenChaser={() => {
                    setSelectedSignup(null);
                    setActiveViewTab('anomalies');
                }}
                onLevelChanged={handleUpdateLevel}
                onUnitChanged={handleUpdateUnit}
                onStatusChanged={(sid, stype, stage, syncedIds = []) => {
                    setSignups((prev) =>
                        prev.map((s) => {
                            if (s.id === sid && s.type === stype) {
                                return { ...s, kanban_stage: stage };
                            }
                            if (stype === "participant" && s.type === "expedition" && syncedIds.includes(Number(s.id))) {
                                const allocated = stage === "place_allocated";
                                const curAnomalies = Array.isArray(s.anomalies) ? s.anomalies : [];
                                return {
                                    ...s,
                                    participant_place_allocated: allocated,
                                    participant_signup_id: allocated ? sid : null,
                                    anomalies: allocated ? curAnomalies.filter((a: string) => a !== "expedition_without_place") : curAnomalies,
                                };
                            }
                            return s;
                        })
                    );
                }}
                onPlaceAllocatedChanged={(expId, allocated) => {
                    setSignups((prev) =>
                        prev.map((s) => {
                            if (s.id !== expId || s.type !== "expedition") return s;
                            const curAnomalies = Array.isArray(s.anomalies) ? s.anomalies : [];
                            return {
                                ...s,
                                participant_place_allocated: allocated,
                                anomalies: allocated ? curAnomalies.filter((a: string) => a !== "expedition_without_place") : curAnomalies,
                            };
                        })
                    );
                }}
            />

            {/* ASN Leader Validation Dialog */}
            {asnModalRow && (
                <>
                    <div
                        className="ems-popover-backdrop"
                        onClick={() => savingField !== 'asn' && setAsnModalRow(null)}
                    />
                    <div
                        className="ems-asn-dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-label="ASN Leader Validation"
                    >
                        <div className="ems-asn-dialog__header">
                            <h3 className="ems-asn-dialog__title">
                                ASN Leader Validation — {asnModalRow.explorer_first_name} {asnModalRow.explorer_last_name}
                            </h3>
                            <button
                                type="button"
                                className="ems-asn-dialog__close"
                                onClick={() => setAsnModalRow(null)}
                                disabled={savingField === 'asn'}
                                aria-label="Close"
                            >
                                <FontAwesomeIcon icon={faXmark} />
                            </button>
                        </div>
                        <div className="ems-asn-dialog__body">
                            <div className="ems-mb-12">
                                <span className="ems-signups-inspector__label">Declared Support Needs:</span>
                                <div className="ems-signups-inspector__support-box ems-mt-4">
                                    {asnModalRow.additional_support_needs || 'None declared.'}
                                </div>
                            </div>
                            <div className="ems-mb-16">
                                <span className="ems-signups-inspector__label">Current Status:</span>
                                <div className="ems-mt-4">
                                    {asnModalRow.asn_validated === 1 ? (
                                        <span className="ems-pill ems-pill--asn-action">Action Needed</span>
                                    ) : asnModalRow.asn_validated === 0 ? (
                                        <span className="ems-pill ems-pill--asn-no-action">No Action Needed</span>
                                    ) : (
                                        <span className="ems-pill ems-pill--asn-pending">Unreviewed</span>
                                    )}
                                </div>
                            </div>
                        </div>
                        <div className="ems-flex-center ems-gap-8 ems-flex-wrap">
                            <button
                                type="button"
                                className="button ems-btn--asn-action"
                                disabled={savingField === 'asn'}
                                onClick={() => handleModalUpdateAsn(1)}
                            >
                                <FontAwesomeIcon icon={faTriangleExclamation} /> Action Needed
                            </button>
                            <button
                                type="button"
                                className="button ems-btn--asn-no-action"
                                disabled={savingField === 'asn'}
                                onClick={() => handleModalUpdateAsn(0)}
                            >
                                <FontAwesomeIcon icon={faCheck} /> No Action Needed
                            </button>
                            <button
                                type="button"
                                className="button ems-btn--asn-unreview"
                                disabled={savingField === 'asn'}
                                onClick={() => handleModalUpdateAsn(null)}
                            >
                                <FontAwesomeIcon icon={faCircleQuestion} /> Unreviewed
                            </button>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
