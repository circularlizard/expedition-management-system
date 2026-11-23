import React, { useEffect, useMemo, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faCheck,
    faEnvelope,
    faExclamationTriangle,
    faHistory,
    faPeopleGroup,
    faRotate,
    faUser,
    faXmark,
} from '@fortawesome/free-solid-svg-icons';

export type DrawerTab = 'overview' | 'signups' | 'teams' | 'history';

export interface Explorer360DrawerProps {
    isOpen: boolean;
    scoutId?: number;
    signupType?: 'participant' | 'expedition';
    signupId?: number;
    rootUrl: string;
    nonce: string;
    units?: any[];
    explorers?: any[];
    initialTab?: DrawerTab;
    fallbackSignups?: any[];
    unlinkError?: string | null;
    levelSaveSuccess?: boolean;
    unitSaveSuccess?: boolean;
    asnSaveSuccess?: boolean;
    onClose: () => void;
    onUpdated?: () => void;
    onReconcile?: (signupId: number, signupType: string, scoutId: number) => Promise<void>;
    onUnlink?: (signupId: number, signupType: string) => Promise<void>;
    onArchive?: (signupId: number, signupType: string) => Promise<void>;
    onOpenAsnModal?: (signup: any) => void;
    onAsnChanged?: (signupId: number, validated: number | null) => Promise<void>;
    onOpenChaser?: (signup: any) => void;
    onStatusChanged?: (signupId: number, signupType: string, stage: string, syncedExpIds?: number[]) => void;
    onPlaceAllocatedChanged?: (expeditionSignupId: number, allocated: boolean) => void;
    onLevelChanged?: (signupId: number, signupType: string, level: string) => Promise<void>;
    onUnitChanged?: (signupId: number, signupType: string, unitId: number) => Promise<void>;
}

const ANOMALY_LABELS: Record<string, string> = {
    place_without_expedition: 'No Expedition',
    expedition_without_place: 'No Participant Place',
    level_mismatch: 'Level Mismatch',
    duplicate_signup: 'Duplicate',
    unmatched_osm: 'Unmatched OSM',
    pending_osm_sync: 'Pending OSM Sync',
    missing_edofe: 'Missing eDofE',
    unvalidated_asn: 'Unvalidated ASN',
};

export const Explorer360Drawer: React.FC<Explorer360DrawerProps> = ({
    isOpen,
    scoutId,
    signupType,
    signupId,
    rootUrl,
    nonce,
    units = [],
    explorers = [],
    initialTab = 'signups',
    fallbackSignups = [],
    unlinkError = null,
    levelSaveSuccess = false,
    unitSaveSuccess = false,
    asnSaveSuccess = false,
    onClose,
    onUpdated,
    onReconcile,
    onUnlink,
    onArchive,
    onOpenAsnModal,
    onAsnChanged,
    onOpenChaser,
    onStatusChanged,
    onPlaceAllocatedChanged,
    onLevelChanged,
    onUnitChanged,
}) => {
    const [activeTab, setActiveTab] = useState<DrawerTab>(initialTab);
    const [loading, setLoading] = useState<boolean>(false);
    const [data, setData] = useState<any | null>(null);
    const [savingKey, setSavingKey] = useState<string | null>(null);
    const [savedKey, setSavedKey] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [reconcileSearch, setReconcileSearch] = useState<string>('');
    const [selectedMatchScoutId, setSelectedMatchScoutId] = useState<string>('');
    const [pendingEdits, setPendingEdits] = useState<
        Record<string, { stage?: string; level?: string; unitId?: number; placeAllocated?: boolean }>
    >({});

    useEffect(() => {
        setActiveTab(initialTab);
        setPendingEdits({});
    }, [initialTab, scoutId, signupId, signupType]);

    const load360 = async () => {
        if (!isOpen || !rootUrl) return;
        const qs =
            scoutId && scoutId > 0
                ? `scout_id=${scoutId}`
                : signupId && signupType
                ? `signup_type=${encodeURIComponent(signupType)}&signup_id=${signupId}`
                : '';
        if (!qs) return;

        setLoading(true);
        setError(null);
        try {
            const res = await fetch(`${rootUrl}/explorers/360?${qs}`, {
                headers: { 'X-WP-Nonce': nonce },
            });
            if (res && res.ok) {
                const json = await res.json();
                if (json && json.explorer) {
                    setData(json);
                    return;
                }
            }
            setData(null);
        } catch {
            setData(null);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (isOpen) {
            load360();
        } else {
            setData(null);
            setError(null);
        }
    }, [isOpen, scoutId, signupType, signupId]);

    const resolvedSignups: any[] = useMemo(() => {
        if (Array.isArray(data?.signups)) return data.signups;
        return fallbackSignups || [];
    }, [data, fallbackSignups]);

    const activeSignups = useMemo(
        () => resolvedSignups.filter((s) => s.signup_status !== 'archived'),
        [resolvedSignups]
    );
    const archivedSignups = useMemo(
        () => resolvedSignups.filter((s) => s.signup_status === 'archived'),
        [resolvedSignups]
    );

    const explorerInfo = useMemo(() => {
        if (data?.explorer) return data.explorer;
        const first = resolvedSignups[0] || {};
        return {
            scout_id: scoutId || first.scout_id || 0,
            is_synced_osm: Boolean(first.is_synced_osm),
            first_name: first.explorer_first_name || '',
            last_name: first.explorer_last_name || '',
            unit_name: first.unit_name || 'Unassigned',
            dofe_number: first.dofe_number || null,
            first_aid_level: resolvedSignups.find((s) => s.first_aid_status)?.first_aid_status || 'none',
        };
    }, [data, resolvedSignups, scoutId]);

    const discrepancies: any[] = data?.contacts?.discrepancies || [];
    const osmContacts = data?.contacts?.osm_canonical || null;
    const resolvedContacts = data?.contacts?.resolved || {
        explorer_email: resolvedSignups[0]?.explorer_email || '',
        parent_email: resolvedSignups[0]?.parent_email || '',
    };

    const suggestedMatches = useMemo(() => {
        if (Number(explorerInfo.scout_id || 0) > 0) return [];
        const targetFirst = (explorerInfo.first_name || '').trim().toLowerCase();
        const targetEmail = (resolvedContacts.explorer_email || '').trim().toLowerCase();
        const targetParentEmail = (resolvedContacts.parent_email || '').trim().toLowerCase();
        return explorers.filter((e) => {
            const eFirst = (e.first_name || '').trim().toLowerCase();
            const nameMatch =
                targetFirst &&
                eFirst &&
                (eFirst === targetFirst ||
                    (targetFirst.length >= 3 && eFirst.startsWith(targetFirst.slice(0, 3))) ||
                    (eFirst.length >= 3 && targetFirst.startsWith(eFirst.slice(0, 3))));
            if (!nameMatch) return false;
            const eEmail = (e.explorer_email || '').trim().toLowerCase();
            const eParent = (e.parent_email || '').trim().toLowerCase();
            return (
                (targetEmail && (eEmail === targetEmail || eParent === targetEmail)) ||
                (targetParentEmail && (eParent === targetParentEmail || eEmail === targetParentEmail))
            );
        });
    }, [explorerInfo, resolvedContacts, explorers]);

    if (!isOpen) return null;

    const markSaved = (key: string) => {
        setSavedKey(key);
        setTimeout(() => setSavedKey((cur) => (cur === key ? null : cur)), 2500);
    };

    const handleStageChange = async (s: any, nextStage: string) => {
        const key = `stage_${s.type}_${s.id}`;
        setSavingKey(key);
        setError(null);
        try {
            const res = await fetch(`${rootUrl}/signups/${s.id}/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({ type: s.type, kanban_stage: nextStage }),
            });
            if (!res.ok) throw new Error('Failed to update processing status.');
            const json = await res.json().catch(() => ({}));
            const syncedIds: number[] = Array.isArray(json?.synced_expedition_ids)
                ? json.synced_expedition_ids.map(Number)
                : [];
            onStatusChanged?.(s.id, s.type, nextStage, syncedIds);
            markSaved(key);
            await load360();
        } catch (err: any) {
            setError(err?.message || 'Failed to update processing status.');
        } finally {
            setSavingKey(null);
        }
    };

    const handlePlaceAllocatedToggle = async (s: any, allocated: boolean) => {
        const key = `place_${s.id}`;
        setSavingKey(key);
        setError(null);
        try {
            const res = await fetch(`${rootUrl}/signups/expeditions/${s.id}/place-allocated`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({ participant_place_allocated: allocated }),
            });
            if (!res.ok) throw new Error('Failed to update Participant Place Allocated.');
            onPlaceAllocatedChanged?.(s.id, allocated);
            markSaved(key);
            await load360();
        } catch (err: any) {
            setError(err?.message || 'Failed to update Participant Place Allocated.');
        } finally {
            setSavingKey(null);
        }
    };

    const handleSyncContactsFromOsm = async () => {
        const sid = Number(explorerInfo.scout_id || 0);
        if (sid <= 0) return;
        setSavingKey('sync_contacts');
        setError(null);
        try {
            const res = await fetch(`${rootUrl}/explorers/${sid}/sync-contacts-from-osm`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
            });
            if (!res.ok) throw new Error('Failed to sync contacts from OSM.');
            markSaved('sync_contacts');
            await load360();
            onUpdated?.();
        } catch (err: any) {
            setError(err?.message || 'Failed to sync contacts from OSM.');
        } finally {
            setSavingKey(null);
        }
    };

    const handleDiscardEdits = () => {
        setPendingEdits({});
        setError(null);
    };

    const handleSaveAllEdits = async () => {
        const entries = Object.entries(pendingEdits);
        if (entries.length === 0) return;
        setSavingKey('batch_save');
        setError(null);
        try {
            for (const [cardKey, patch] of entries) {
                const signup = activeSignups.find((item) => `${item.type}_${item.id}` === cardKey);
                if (!signup) continue;
                if (patch.stage !== undefined) {
                    await handleStageChange(signup, patch.stage);
                }
                if (patch.level !== undefined && onLevelChanged) {
                    await onLevelChanged(signup.id, signup.type, patch.level);
                }
                if (patch.unitId !== undefined && onUnitChanged) {
                    await onUnitChanged(signup.id, signup.type, patch.unitId);
                }
                if (patch.placeAllocated !== undefined && signup.type === 'expedition') {
                    await handlePlaceAllocatedToggle(signup, patch.placeAllocated);
                }
            }
            setPendingEdits({});
            markSaved('batch_save');
            await load360();
        } catch (err: any) {
            setError(err?.message || 'Failed to save changes.');
        } finally {
            setSavingKey(null);
        }
    };

    const isDirty = Object.keys(pendingEdits).length > 0;
    const BLOCKING_ANOMALIES = new Set(['missing_edofe', 'unmatched_osm', 'duplicate_signup']);

    const filteredExplorers = explorers.filter((e) => {
        if (!reconcileSearch) return true;
        const q = reconcileSearch.toLowerCase();
        return `${e.first_name || ''} ${e.last_name || ''} ${e.scout_id || ''}`.toLowerCase().includes(q);
    });

    return (
        <div
            className="ems-drawer-backdrop"
            role="dialog"
            aria-modal="true"
            aria-label="Explorer 360 Drawer"
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0,0,0,0.35)',
                zIndex: 100000,
                display: 'flex',
                justifyContent: 'flex-end',
            }}
            onClick={onClose}
        >
            <div
                className="ems-drawer-panel"
                style={{
                    width: '100%',
                    maxWidth: '680px',
                    height: '100%',
                    background: '#fff',
                    boxShadow: '-4px 0 20px rgba(0,0,0,0.18)',
                    display: 'flex',
                    flexDirection: 'column',
                    overflow: 'hidden',
                }}
                onClick={(e) => e.stopPropagation()}
            >
                {/* Header */}
                <div
                    style={{
                        padding: '16px 20px',
                        borderBottom: '1px solid #dcdcde',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        background: '#f6f7f7',
                    }}
                >
                    <div>
                        <h2 style={{ margin: 0, fontSize: '18px' }}>
                            {explorerInfo.first_name} {explorerInfo.last_name}
                        </h2>
                        <div className="ems-meta-text" style={{ fontSize: '12px', marginTop: '2px' }}>
                            Scout ID: <strong>{explorerInfo.scout_id || 'Unmatched'}</strong> &middot;{' '}
                            {explorerInfo.unit_name || 'Unassigned'} &middot;{' '}
                            {explorerInfo.is_synced_osm ? 'Synced with OSM' : 'Not Synced with OSM'}
                        </div>
                    </div>
                    <button type="button" className="button button-small" onClick={onClose} aria-label="Close Explorer 360">
                        <FontAwesomeIcon icon={faXmark} /> Close
                    </button>
                </div>

                {/* Drawer Tabs */}
                <nav className="nav-tab-wrapper ems-drawer-tabs">
                    <button
                        type="button"
                        className={`nav-tab ${activeTab === 'overview' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('overview')}
                    >
                        <FontAwesomeIcon icon={faUser} /> Overview ({discrepancies.length})
                    </button>
                    <button
                        type="button"
                        className={`nav-tab ${activeTab === 'signups' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('signups')}
                    >
                        Sign-ups ({activeSignups.length})
                    </button>
                    <button
                        type="button"
                        className={`nav-tab ${activeTab === 'teams' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('teams')}
                    >
                        <FontAwesomeIcon icon={faPeopleGroup} /> Teams &amp; Training
                    </button>
                    <button
                        type="button"
                        className={`nav-tab ${activeTab === 'history' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('history')}
                    >
                        <FontAwesomeIcon icon={faHistory} /> History
                    </button>
                </nav>

                {/* Body */}
                <div style={{ flex: 1, overflowY: 'auto', padding: '16px 20px' }}>
                    {Number(explorerInfo.scout_id || 0) > 0 && !explorerInfo.is_synced_osm && (
                        <div className="notice notice-warning inline ems-mb-12" style={{ padding: '6px 10px', fontSize: '12px' }}>
                            Scout ID {explorerInfo.scout_id} is not yet in the synced OSM Explorers list.
                        </div>
                    )}
                    {error && <div className="notice notice-error ems-mb-12"><p>{error}</p></div>}
                    {unlinkError && (
                        <div className="ems-error-notice ems-small-text ems-mb-12">
                            {unlinkError}
                        </div>
                    )}
                    {loading && !data && <p className="ems-meta-text">Loading Explorer 360° details…</p>}

                    {activeTab === 'overview' && (
                        <div>
                            <div className="ems-panel ems-p-16 ems-mb-16">
                                <div className="ems-flex-between ems-mb-12">
                                    <strong>
                                        <FontAwesomeIcon icon={faEnvelope} /> Consolidated Contacts
                                    </strong>
                                    {osmContacts && discrepancies.length > 0 && (
                                        <button
                                            type="button"
                                            className="button button-small button-primary"
                                            disabled={savingKey === 'sync_contacts'}
                                            onClick={handleSyncContactsFromOsm}
                                        >
                                            <FontAwesomeIcon icon={faRotate} />{' '}
                                            {savingKey === 'sync_contacts'
                                                ? 'Syncing…'
                                                : 'Sync All Active Sign-ups from OSM'}
                                        </button>
                                    )}
                                    {savedKey === 'sync_contacts' && (
                                        <span className="ems-field-status ems-field-status--success">
                                            <FontAwesomeIcon icon={faCheck} /> Synced
                                        </span>
                                    )}
                                </div>

                                <div className="ems-signups-detail-grid">
                                    <div className="ems-signups-detail-item">
                                        <span className="ems-signups-inspector__label">
                                            Explorer Email {osmContacts ? '(OSM Primary)' : '(Latest Sign-up)'}
                                        </span>
                                        <div className="ems-signups-inspector__value">
                                            {resolvedContacts.explorer_email || '—'}
                                        </div>
                                    </div>
                                    {osmContacts && (
                                        <div className="ems-signups-detail-item">
                                            <span className="ems-signups-inspector__label">
                                                Explorer Email 2 (OSM)
                                            </span>
                                            <div className="ems-signups-inspector__value">
                                                {osmContacts.email2 || '—'}
                                            </div>
                                        </div>
                                    )}
                                    <div className="ems-signups-detail-item">
                                        <span className="ems-signups-inspector__label">
                                            Parent 1 Email {osmContacts ? '(OSM Primary)' : '(Latest Sign-up)'}
                                        </span>
                                        <div className="ems-signups-inspector__value">
                                            {resolvedContacts.parent_email || '—'}
                                        </div>
                                    </div>
                                    {osmContacts && (
                                        <>
                                            <div className="ems-signups-detail-item">
                                                <span className="ems-signups-inspector__label">
                                                    Parent 1 Email 2 (OSM)
                                                </span>
                                                <div className="ems-signups-inspector__value">
                                                    {osmContacts.p1_email2 || '—'}
                                                </div>
                                            </div>
                                            <div className="ems-signups-detail-item">
                                                <span className="ems-signups-inspector__label">
                                                    Parent 2 Email (OSM)
                                                </span>
                                                <div className="ems-signups-inspector__value">
                                                    {osmContacts.p2_email1 || '—'}
                                                </div>
                                            </div>
                                            <div className="ems-signups-detail-item">
                                                <span className="ems-signups-inspector__label">
                                                    Parent 2 Email 2 (OSM)
                                                </span>
                                                <div className="ems-signups-inspector__value">
                                                    {osmContacts.p2_email2 || '—'}
                                                </div>
                                            </div>
                                        </>
                                    )}
                                </div>

                                {discrepancies.length > 0 && (
                                    <div
                                        className="notice notice-warning inline"
                                        style={{ marginTop: '12px', padding: '8px 12px' }}
                                    >
                                        <strong>
                                            <FontAwesomeIcon icon={faExclamationTriangle} /> Contact Mismatch Detected:
                                        </strong>
                                        <ul style={{ margin: '6px 0 0 16px', fontSize: '12px' }}>
                                            {discrepancies.map((d, i) => (
                                                <li key={i}>
                                                    {d.signup_type === 'expedition' ? 'Expedition' : 'Participant Place'} #{d.signup_id}{' '}
                                                    <code>{d.field}</code>: Form has <strong>{d.form_value}</strong> vs OSM{' '}
                                                    <strong>{d.osm_value}</strong>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>

                            {/* OSM Profile & Match */}
                            <div className="ems-panel ems-p-16">
                                <strong style={{ display: 'block', marginBottom: '8px' }}>OSM Record &amp; Linking</strong>
                                {Number(explorerInfo.scout_id || 0) > 0 ? (
                                    <div className="ems-flex-between">
                                        <div>
                                            <div>Patrol: <strong>{explorerInfo.patrol || '—'}</strong></div>
                                            <div>eDofE: <strong className="ems-monospace">{explorerInfo.dofe_number || '—'}</strong></div>
                                            <div>First Aid: <strong>{explorerInfo.first_aid_level || 'none'}</strong></div>
                                        </div>
                                        {onUnlink && activeSignups[0] && (
                                            <button
                                                type="button"
                                                className="button button-link-delete"
                                                onClick={() => onUnlink(activeSignups[0].id, activeSignups[0].type)}
                                            >
                                                Unlink OSM Profile
                                            </button>
                                        )}
                                    </div>
                                ) : (
                                    <div>
                                        <p className="ems-meta-text" style={{ marginTop: 0 }}>
                                            Not yet matched to an OSM Explorer.
                                        </p>
                                        {onReconcile && activeSignups[0] && (
                                            <div>
                                                {suggestedMatches.length > 0 && (
                                                    <div className="ems-mb-12">
                                                        <strong style={{ fontSize: '12px', display: 'block', marginBottom: '6px' }}>
                                                            Suggested matches:
                                                        </strong>
                                                        {suggestedMatches.map((m) => (
                                                            <div key={m.scout_id} className="ems-flex-between ems-mb-6">
                                                                <span>
                                                                    {m.first_name} {m.last_name} ({m.scout_id})
                                                                </span>
                                                                <button
                                                                    type="button"
                                                                    className="button button-small button-primary"
                                                                    onClick={async () => {
                                                                        await onReconcile(
                                                                            activeSignups[0].id,
                                                                            activeSignups[0].type,
                                                                            Number(m.scout_id)
                                                                        );
                                                                        await load360();
                                                                    }}
                                                                >
                                                                    Confirm Match
                                                                </button>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                                <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                                                    <input
                                                        type="text"
                                                        placeholder="Search OSM Explorer…"
                                                        value={reconcileSearch}
                                                        onChange={(e) => setReconcileSearch(e.target.value)}
                                                        className="regular-text"
                                                        style={{ flex: 1, minWidth: '160px' }}
                                                    />
                                                    <select
                                                        value={selectedMatchScoutId}
                                                        onChange={(e) => setSelectedMatchScoutId(e.target.value)}
                                                        className="ems-select"
                                                    >
                                                        <option value="">Select OSM Explorer…</option>
                                                        {filteredExplorers.slice(0, 50).map((e) => (
                                                            <option key={e.scout_id} value={String(e.scout_id)}>
                                                                {e.first_name} {e.last_name} ({e.scout_id})
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <button
                                                        type="button"
                                                        className="button button-primary"
                                                        disabled={!selectedMatchScoutId}
                                                        onClick={async () => {
                                                            await onReconcile(
                                                                activeSignups[0].id,
                                                                activeSignups[0].type,
                                                                Number(selectedMatchScoutId)
                                                            );
                                                            await load360();
                                                        }}
                                                    >
                                                        Link Selected
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {activeTab === 'signups' && (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                            {activeSignups.length === 0 ? (
                                <p className="ems-meta-text">No active sign-ups.</p>
                            ) : (
                                activeSignups.map((s) => {
                                    const cardKey = `${s.type}_${s.id}`;
                                    const patch = pendingEdits[cardKey] || {};
                                    const savedStage = (s.kanban_stage || 'new') === 'to_process' ? 'new' : (s.kanban_stage || 'new');
                                    const curStage = patch.stage !== undefined ? patch.stage : savedStage;
                                    const curLevel = patch.level !== undefined ? patch.level : (s.dofe_level || 'bronze');
                                    const curUnitId = patch.unitId !== undefined ? String(patch.unitId) : (s.unit_id ? String(s.unit_id) : '');
                                    const curPlaceAllocated = patch.placeAllocated !== undefined ? patch.placeAllocated : Boolean(s.participant_place_allocated);
                                    const stageKey = `stage_${s.type}_${s.id}`;
                                    const placeKey = `place_${s.id}`;
                                    const anomalies: string[] = Array.isArray(s.anomalies) ? s.anomalies : [];
                                    const hasBlockingAnomaly = anomalies.some((flag) => BLOCKING_ANOMALIES.has(flag));
                                    const cardSeverityClass =
                                        anomalies.length === 0
                                            ? ''
                                            : hasBlockingAnomaly
                                            ? 'ems-drawer-card--danger'
                                            : 'ems-drawer-card--warning';
                                    const awardLevel = ['bronze', 'silver', 'gold'].includes(String(curLevel).toLowerCase())
                                        ? String(curLevel).toLowerCase()
                                        : 'silver';

                                    const updatePending = (delta: Partial<{ stage: string; level: string; unitId: number; placeAllocated: boolean }>) => {
                                        setPendingEdits((prev) => ({
                                            ...prev,
                                            [cardKey]: {
                                                ...(prev[cardKey] || {}),
                                                ...delta,
                                            },
                                        }));
                                    };

                                    return (
                                        <div
                                            key={cardKey}
                                            data-testid={`inspector-entry-${cardKey}`}
                                            className={`ems-drawer-card ${cardSeverityClass}`}
                                        >
                                            <div className={`ems-drawer-card__header ems-drawer-card__header--${awardLevel}`}>
                                                <div className="ems-drawer-card__title">
                                                    <span className={`ems-status-badge ems-status-badge--${s.type}`}>
                                                        {s.type === 'expedition' ? 'Expedition' : 'Participant Place'}
                                                    </span>
                                                    <span className={`ems-pill ems-pill--${awardLevel}`}>
                                                        {awardLevel}
                                                    </span>
                                                    <span className="ems-meta-text ems-monospace">#{s.id}</span>
                                                </div>
                                                {onArchive && s.signup_status !== 'archived' && (
                                                    <button
                                                        type="button"
                                                        className="ems-drawer-archive-btn"
                                                        onClick={async () => {
                                                            await onArchive(s.id, s.type);
                                                            await load360();
                                                        }}
                                                    >
                                                        Archive
                                                    </button>
                                                )}
                                            </div>

                                            <div className="ems-drawer-card__body">
                                                {anomalies.length > 0 && (
                                                    <div className="ems-drawer-anomalies">
                                                        {anomalies.map((flag) => {
                                                            const isDanger = BLOCKING_ANOMALIES.has(flag);
                                                            return (
                                                                <span
                                                                    key={flag}
                                                                    className={`ems-anomaly-pill ${
                                                                        isDanger ? 'ems-anomaly-pill--danger' : 'ems-anomaly-pill--warning'
                                                                    }`}
                                                                >
                                                                    {ANOMALY_LABELS[flag] || flag}
                                                                </span>
                                                            );
                                                        })}
                                                        {anomalies.includes('missing_edofe') && onOpenChaser && (
                                                            <button
                                                                type="button"
                                                                className="ems-drawer-action-link"
                                                                onClick={() => onOpenChaser(s)}
                                                            >
                                                                Send Chaser
                                                            </button>
                                                        )}
                                                        {anomalies.includes('pending_osm_sync') && (
                                                            <button
                                                                type="button"
                                                                className="ems-drawer-action-link"
                                                                onClick={() => setActiveTab('overview')}
                                                            >
                                                                Sync from OSM
                                                            </button>
                                                        )}
                                                        {anomalies.includes('unmatched_osm') && (
                                                            <button
                                                                type="button"
                                                                className="ems-drawer-action-link"
                                                                onClick={() => setActiveTab('overview')}
                                                            >
                                                                Link OSM
                                                            </button>
                                                        )}
                                                    </div>
                                                )}

                                                {Number(s.scout_id || 0) === 0 && Number(explorerInfo.scout_id || 0) > 0 && onReconcile && (
                                                    <div className="notice notice-info inline" style={{ margin: 0, padding: '6px 10px', fontSize: '12px' }}>
                                                        <span>Paired registration is already linked to OSM (Scout ID {explorerInfo.scout_id}). </span>
                                                        <button
                                                            type="button"
                                                            className="button button-small button-primary"
                                                            onClick={async () => {
                                                                await onReconcile(s.id, s.type, Number(explorerInfo.scout_id));
                                                                await load360();
                                                            }}
                                                        >
                                                            Sync with Paired OSM Record
                                                        </button>
                                                    </div>
                                                )}

                                                <div className="ems-drawer-controls-grid">
                                                    <div>
                                                        <span className="ems-drawer-kv__label">Processing Status</span>
                                                        <select
                                                            aria-label="Drawer Processing Status"
                                                            value={curStage}
                                                            disabled={savingKey === stageKey || savingKey === 'batch_save'}
                                                            onChange={(e) => updatePending({ stage: e.target.value })}
                                                            className="ems-select ems-drawer-select"
                                                        >
                                                            <option value="new">New</option>
                                                            {s.type === 'participant' ? (
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
                                                    </div>

                                                    <div>
                                                        <span className="ems-drawer-kv__label">Level</span>
                                                        {onLevelChanged ? (
                                                            <select
                                                                aria-label="Change Level"
                                                                value={curLevel}
                                                                disabled={savingKey === 'batch_save'}
                                                                onChange={(e) => updatePending({ level: e.target.value })}
                                                                className="ems-select ems-drawer-select"
                                                            >
                                                                <option value="bronze">Bronze</option>
                                                                <option value="silver">Silver</option>
                                                                <option value="gold">Gold</option>
                                                            </select>
                                                        ) : (
                                                            <div className="ems-drawer-kv__value">{s.dofe_level}</div>
                                                        )}
                                                        {levelSaveSuccess && (
                                                            <span className="ems-field-status ems-field-status--success" data-testid="level-save-success">
                                                                <FontAwesomeIcon icon={faCheck} /> Saved
                                                            </span>
                                                        )}
                                                    </div>

                                                    <div>
                                                        <span className="ems-drawer-kv__label">Unit</span>
                                                        {units.length > 0 && onUnitChanged ? (
                                                            <select
                                                                aria-label="Change Unit"
                                                                value={curUnitId}
                                                                disabled={savingKey === 'batch_save'}
                                                                onChange={(e) => {
                                                                    if (e.target.value) {
                                                                        updatePending({ unitId: Number(e.target.value) });
                                                                    }
                                                                }}
                                                                className="ems-select ems-drawer-select"
                                                            >
                                                                <option value="">Select unit...</option>
                                                                {units.map((u: any, idx: number) => (
                                                                    <option key={u.unit_id ?? u.id ?? idx} value={String(u.unit_id ?? u.id)}>
                                                                        {u.short_code || u.name}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        ) : (
                                                            <div className="ems-drawer-kv__value">{s.unit_name || 'Unassigned'}</div>
                                                        )}
                                                        {unitSaveSuccess && (
                                                            <span className="ems-field-status ems-field-status--success" data-testid="unit-save-success">
                                                                <FontAwesomeIcon icon={faCheck} /> Saved
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>

                                                {s.type === 'expedition' && (
                                                    <div className="ems-flex-center ems-gap-8">
                                                        <label className="ems-flex-center ems-gap-8">
                                                            <input
                                                                type="checkbox"
                                                                checked={curPlaceAllocated}
                                                                disabled={savingKey === placeKey || savingKey === 'batch_save'}
                                                                onChange={(e) => updatePending({ placeAllocated: e.target.checked })}
                                                            />
                                                            <span>
                                                                <strong>Participant Place Allocated</strong>
                                                                {s.participant_signup_id
                                                                    ? ` (Linked #${s.participant_signup_id})`
                                                                    : ' (Manual / Prior Year)'}
                                                            </span>
                                                        </label>
                                                    </div>
                                                )}

                                                <div className="ems-drawer-kv-grid">
                                                    <div>
                                                        <span className="ems-drawer-kv__label">eDofE Number</span>
                                                        <div data-testid={`drawer-dofe-num-${s.id}`}>
                                                            {s.dofe_number ? (
                                                                <span className="ems-drawer-kv__value ems-monospace">{s.dofe_number}</span>
                                                            ) : (
                                                                <span className="ems-drawer-kv__empty">Not recorded</span>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {s.type === 'expedition' && (
                                                        <>
                                                            <div>
                                                                <span className="ems-drawer-kv__label">Expedition Mode</span>
                                                                <div>
                                                                    {s.expedition_preferences?.exped_type ? (
                                                                        <span className="ems-drawer-kv__value">{s.expedition_preferences.exped_type}</span>
                                                                    ) : (
                                                                        <span className="ems-drawer-kv__empty">Not recorded</span>
                                                                    )}
                                                                </div>
                                                            </div>

                                                            <div>
                                                                <span className="ems-drawer-kv__label">ASN</span>
                                                                <div>
                                                                    {s.additional_support_needs ? (
                                                                        <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
                                                                            <span className="ems-drawer-kv__value">{s.additional_support_needs}</span>
                                                                            {onAsnChanged && (
                                                                                <div className="ems-flex-center ems-gap-8">
                                                                                    <select
                                                                                        aria-label="Accommodation Status"
                                                                                        value={
                                                                                            s.asn_validated === 1 || s.asn_validated === '1'
                                                                                                ? '1'
                                                                                                : s.asn_validated === 0 || s.asn_validated === '0'
                                                                                                ? '0'
                                                                                                : ''
                                                                                        }
                                                                                        onChange={(e) => {
                                                                                            const val = e.target.value === '' ? null : Number(e.target.value);
                                                                                            onAsnChanged(s.id, val);
                                                                                        }}
                                                                                        className="ems-select ems-drawer-select"
                                                                                    >
                                                                                        <option value="">Unreviewed</option>
                                                                                        <option value="1">Action Needed</option>
                                                                                        <option value="0">No Action Needed</option>
                                                                                    </select>
                                                                                    {asnSaveSuccess && (
                                                                                        <span className="ems-field-status ems-field-status--success" data-testid="asn-save-success">
                                                                                            <FontAwesomeIcon icon={faCheck} /> Saved
                                                                                        </span>
                                                                                    )}
                                                                                </div>
                                                                            )}
                                                                            {onOpenAsnModal && (
                                                                                <button
                                                                                    type="button"
                                                                                    className="ems-drawer-action-link"
                                                                                    style={{ textAlign: 'left', padding: 0 }}
                                                                                    onClick={() => onOpenAsnModal(s)}
                                                                                >
                                                                                    Review ASN
                                                                                </button>
                                                                            )}
                                                                        </div>
                                                                    ) : (
                                                                        <span className="ems-drawer-kv__empty">Not recorded</span>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })
                            )}

                            {archivedSignups.length > 0 && (
                                <details className="ems-panel ems-p-12">
                                    <summary style={{ cursor: 'pointer', fontWeight: 600 }}>
                                        Archived / Past Sign-ups ({archivedSignups.length})
                                    </summary>
                                    <div style={{ marginTop: '8px', display: 'flex', flexDirection: 'column', gap: '6px' }}>
                                        {archivedSignups.map((s) => (
                                            <div key={`arch_${s.type}_${s.id}`} className="ems-meta-text" style={{ fontSize: '12px' }}>
                                                {s.type === 'expedition' ? 'Expedition' : 'Participant Place'} ({s.dofe_level}) &middot;{' '}
                                                {s.kanban_stage || 'new'} &middot; {s.created_at}
                                            </div>
                                        ))}
                                    </div>
                                </details>
                            )}
                        </div>
                    )}

                    {activeTab === 'teams' && (
                        <div>
                            <div className="ems-panel ems-p-16 ems-mb-16">
                                <strong style={{ display: 'block', marginBottom: '8px' }}>Team Allocations</strong>
                                {Array.isArray(data?.teams) && data.teams.length > 0 ? (
                                    <ul style={{ margin: 0, paddingLeft: '16px' }}>
                                        {data.teams.map((t: any) => (
                                            <li key={t.id} style={{ marginBottom: '6px' }}>
                                                <strong>{t.cohort_name || t.team_name || `Team #${t.team_post_id}`}</strong> ({t.dofe_level} / {t.transport_mode}) —{' '}
                                                Notification: <code>{t.notification_status || 'draft'}</code>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="ems-meta-text ems-m-0">No team allocations yet.</p>
                                )}
                            </div>

                            <div className="ems-panel ems-p-16">
                                <strong style={{ display: 'block', marginBottom: '8px' }}>Training &amp; First Aid</strong>
                                <div>
                                    Tutor LMS: <strong>{data?.training?.status || 'unavailable'}</strong>
                                    {data?.training?.total_modules > 0 && (
                                        <span> ({data.training.completed_modules}/{data.training.total_modules} modules)</span>
                                    )}
                                </div>
                                <div style={{ marginTop: '4px' }}>
                                    First Aid Level: <strong>{explorerInfo.first_aid_level || 'none'}</strong>
                                </div>
                            </div>
                        </div>
                    )}

                    {activeTab === 'history' && (
                        <div className="ems-panel ems-p-16">
                            <strong style={{ display: 'block', marginBottom: '8px' }}>Interaction &amp; Chase History</strong>
                            {Array.isArray(data?.history) && data.history.length > 0 ? (
                                <ul style={{ margin: 0, paddingLeft: '16px', fontSize: '13px' }}>
                                    {data.history.map((h: any, idx: number) => (
                                        <li key={idx} style={{ marginBottom: '6px' }}>
                                            <strong>{h.timestamp}</strong> &middot; {h.title}
                                            {h.detail && <div className="ems-meta-text">{h.detail}</div>}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="ems-meta-text ems-m-0">No logged chaser interactions for this Explorer yet.</p>
                            )}
                        </div>
                    )}
                </div>

                {isDirty && (
                    <div className="ems-drawer-save-bar" data-testid="drawer-save-bar">
                        <span style={{ fontSize: '13px', fontWeight: 600, color: '#1e293b' }}>
                            Unsaved changes
                        </span>
                        <div className="ems-flex-center ems-gap-8">
                            <button
                                type="button"
                                className="button"
                                disabled={savingKey === 'batch_save'}
                                onClick={handleDiscardEdits}
                            >
                                Discard
                            </button>
                            <button
                                type="button"
                                className="button button-primary"
                                disabled={savingKey === 'batch_save'}
                                onClick={handleSaveAllEdits}
                            >
                                {savingKey === 'batch_save' ? 'Saving…' : 'Save Changes'}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

export default Explorer360Drawer;
