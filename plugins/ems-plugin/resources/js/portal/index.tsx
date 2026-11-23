import '../../css/ems-portal.css';
import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { OSMReadOnlyMap } from '../admin/expedition-board/OSMReadOnlyMap';
import { ModuleErrorBoundary } from '../shared/ModuleErrorBoundary';
import { AdminPreviewBar, PreviewTarget } from './AdminPreviewBar';

interface Teammate {
    first_name: string;
    last_initial: string;
    patrol: string;
}

interface EventData {
    id: number;
    name: string;
    start_date: string;
    start_time?: string;
    end_date: string;
    end_time?: string;
    location: string;
    end_location?: string;
    required_first_aid_level: string;
    route_deadline?: string;
    route_info?: string;
    whatsapp_explorers?: string | null;
    whatsapp_parents?: string | null;
    level: string;
    type: string;
    transport_mode?: string;
    event_code?: string;
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
    dob?: string;
    dofe_registered?: string;
    dofe_number?: string;
    dofe_org?: string;
    bronze_completion?: any;
    silver_completion?: any;
    expedition_preferences?: any;
    additional_support_needs?: string;
    first_aid_status?: string;
    first_aid_expiry?: string;
    form_submission_id?: number;
}

interface TrainingCourse {
    course_name: string;
    completed: boolean;
    completion_date: string | null;
    course_url: string;
}

interface OfferStage {
    expedition_id: number;
    expedition_name: string;
    event_code?: string;
    team_post_id?: number;
    team_code?: string;
    teammates?: Teammate[];
    type: string;
    start_date: string;
    end_date: string;
}

function renderTeammateGroups(teammates: Teammate[]) {
    const groups = new Map<string, string[]>();
    for (const tm of teammates) {
        const unit = (tm.patrol || '').trim();
        const displayName = `${tm.first_name} ${tm.last_initial}`.trim();
        if (!groups.has(unit)) groups.set(unit, []);
        groups.get(unit)!.push(displayName);
    }
    return (
        <ul className="ems-portal-teammate-groups">
            {Array.from(groups.entries()).map(([unit, names]) => (
                <li key={unit || '__none'} className="ems-portal-teammate-group">
                    {unit && <span className="ems-portal-unit-pill">{unit}</span>}
                    <span className="ems-portal-teammate-names">{names.join(', ')}</span>
                </li>
            ))}
        </ul>
    );
}

interface OfferRecord {
    cohort_id: number;
    expedition_signup_id: number;
    team_post_id?: number;
    team_name: string;
    dofe_level: string;
    transport_mode: string;
    notification_status?: string;
    offer_status: 'pending' | 'accepted' | 'queried' | 'declined';
    offer_query_message?: string | null;
    offer_responded_at?: string | null;
    deadline?: {
        allowed: boolean;
        communicated_date: string | null;
        effective_hard_cutoff: string | null;
        grace_applied?: boolean;
        reason?: string;
    };
    stages?: OfferStage[];
}

interface ExplorerDetail {
    explorer: {
        scout_id: number;
        first_name: string;
        last_name: string;
        first_aid_level: string;
        additional_support_needs?: string;
    };
    signups: Signup[];
    events: {
        training: EventData[];
        practice: EventData[];
        qualifying: EventData[];
    };
    training_checklist: TrainingCourse[];
    training_readonly?: boolean;
    team: {
        team_code: string;
        route_status: string;
        whatsapp_link: string | null;
        teammates: Teammate[];
    } | null;
    offers?: OfferRecord[];
}

const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

function getOrdinalSuffix(day: number): string {
    const mod100 = day % 100;
    if (mod100 >= 11 && mod100 <= 13) return 'th';
    if (day % 10 === 1) return 'st';
    if (day % 10 === 2) return 'nd';
    if (day % 10 === 3) return 'rd';
    return 'th';
}

function parseDateParts(raw?: string | null): { day: number; monthIdx: number; year: number } | null {
    if (!raw) return null;
    const match = String(raw).trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (match) {
        return {
            year: Number(match[1]),
            monthIdx: Number(match[2]) - 1,
            day: Number(match[3]),
        };
    }
    const parsed = new Date(raw);
    if (Number.isNaN(parsed.getTime())) return null;
    return {
        day: parsed.getDate(),
        monthIdx: parsed.getMonth(),
        year: parsed.getFullYear(),
    };
}

function formatOrdinalDate(raw?: string | null): string {
    const parts = parseDateParts(raw);
    if (!parts) return raw ? String(raw) : '';
    return `${parts.day}${getOrdinalSuffix(parts.day)} ${MONTHS[parts.monthIdx] || ''} ${parts.year}`;
}

function formatDateRange(startRaw?: string | null, endRaw?: string | null): string {
    const s = parseDateParts(startRaw);
    const e = parseDateParts(endRaw);
    if (!s && !e) return '';
    if (s && !e) return formatOrdinalDate(startRaw);
    if (!s && e) return formatOrdinalDate(endRaw);
    if (!s || !e) return '';

    if (s.year === e.year && s.monthIdx === e.monthIdx) {
        if (s.day === e.day) {
            return `${s.day}${getOrdinalSuffix(s.day)} ${MONTHS[s.monthIdx]} ${s.year}`;
        }
        return `${s.day}${getOrdinalSuffix(s.day)}–${e.day}${getOrdinalSuffix(e.day)} ${MONTHS[s.monthIdx]} ${s.year}`;
    }
    return `${formatOrdinalDate(startRaw)} – ${formatOrdinalDate(endRaw)}`;
}

function capitalizeLabel(value?: string | null): string {
    const norm = (value || '').trim();
    if (!norm) return '';
    if (norm.toLowerCase() === 'foot' || norm.toLowerCase() === 'hillwalking') return 'Hillwalking';
    return norm.charAt(0).toUpperCase() + norm.slice(1).toLowerCase();
}

function formatEventCardTitle(ev: EventData): string {
    const code = (ev.event_code || '').trim();
    const name = (ev.name || '').trim();
    const typeNorm = (ev.type || '').toLowerCase();
    const stageLabel =
        typeNorm === 'practice'
            ? 'Practice Expedition'
            : typeNorm === 'qualifying' || typeNorm === 'qualifier'
            ? 'Qualifying Expedition'
            : typeNorm === 'training'
            ? 'Training Event'
            : '';

    if (code) {
        if (!name || name.toLowerCase() === code.toLowerCase()) {
            return stageLabel ? `${code} · ${stageLabel}` : code;
        }
        if (name.toLowerCase().startsWith(code.toLowerCase())) {
            return name;
        }
        return `${code} · ${name}`;
    }
    return name || stageLabel || 'Expedition Event';
}

const ResolvedLocation: React.FC<{ value?: string }> = ({ value }) => {
    const [resolvedName, setResolvedName] = useState('');
    const [loading, setLoading] = useState(false);
    const coordsMatch = value ? value.trim().match(/^(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)$/) : null;

    useEffect(() => {
        if (!coordsMatch) {
            setResolvedName('');
            return;
        }
        const lat = parseFloat(coordsMatch[1]);
        const lng = parseFloat(coordsMatch[2]);
        setLoading(true);
        fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`, {
            headers: { 'Accept-Language': 'en' },
        })
            .then((res) => (res.ok ? res.json() : Promise.reject()))
            .then((data) => {
                const addr = data.address;
                const name = addr.road || addr.suburb || addr.town || addr.city || addr.county || '';
                const county = addr.county || addr.state || '';
                setResolvedName(name ? (county ? `${name}, ${county}` : name) : '');
            })
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [value]);

    if (!value) return <span>Not Specified</span>;
    if (!coordsMatch) return <span>{value}</span>;

    const lat = coordsMatch[1];
    const lng = coordsMatch[2];
    const mapUrl = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=15/${lat}/${lng}`;

    return (
        <span>
            <a href={mapUrl} target="_blank" rel="noopener noreferrer" className="ems-detail-link" style={{ marginRight: '8px' }}>
                {lat}, {lng}
            </a>
            {loading && <span style={{ fontSize: '12px', color: '#64748b', fontStyle: 'italic' }}>Resolving address…</span>}
            {!loading && resolvedName && (
                <span style={{ fontSize: '13px', color: '#475569' }}>({resolvedName})</span>
            )}
        </span>
    );
};

interface Profile {
    scout_id: number;
    first_name: string;
    last_name: string;
    patrol: string;
    relationship?: 'self' | 'child';
    label?: string;
}

type PortalMainTab = 'expeditions' | 'training' | 'route' | 'signups';

export function PortalApp() {
    const config = (window as any).emsPortal || {
        root_url: '/wp-json/ems/v1',
        nonce: '',
        user_data: { logged_in: false, first_name: '', last_name: '', email: '', access_type: '' },
        login_url: '#',
    };

    const [me, setMe] = useState<{
        logged_in: boolean;
        access_type?: string;
        display_name?: string;
        profiles?: Profile[];
        is_admin?: boolean;
        is_preview?: boolean;
        preview_target?: PreviewTarget;
    } | null>(null);

    const [previewTarget, setPreviewTarget] = useState<PreviewTarget | null>(() => {
        try {
            const saved = sessionStorage.getItem('ems_portal_preview_target');
            return saved ? JSON.parse(saved) : null;
        } catch {
            return null;
        }
    });

    const [activeScoutId, setActiveScoutId] = useState<number | null>(null);
    const [explorerDetail, setExplorerDetail] = useState<ExplorerDetail | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [currentPage, setCurrentPage] = useState<PortalMainTab>('expeditions');
    const [selectedSignupId, setSelectedSignupId] = useState<number | null>(null);
    const [queryingOfferKey, setQueryingOfferKey] = useState<string | null>(null);
    const [updatingOfferKey, setUpdatingOfferKey] = useState<string | null>(null);
    const [queryMessage, setQueryMessage] = useState<string>('');
    const [offerSavingKey, setOfferSavingKey] = useState<string | null>(null);
    const [offerNotice, setOfferNotice] = useState<string | null>(null);

    const activeScout = activeScoutId || (me?.profiles && me.profiles.length > 0 ? me.profiles[0].scout_id : null);
    const isAdmin = Boolean(config.user_data?.is_admin || me?.is_admin);

    const handleRespondToOffer = async (
        cohortId: number,
        expeditionSignupId: number,
        teamPostId: number | undefined,
        action: 'accept' | 'query' | 'decline',
        message = ''
    ) => {
        const key = cohortId > 0 && expeditionSignupId > 0 ? `${cohortId}:${expeditionSignupId}` : `team:${teamPostId || 0}`;
        setOfferSavingKey(key);
        setError(null);
        setOfferNotice(null);
        try {
            const res = await fetch(`${config.root_url}/portal/offers/respond`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({
                    scout_id: activeScout,
                    cohort_id: cohortId,
                    expedition_signup_id: expeditionSignupId,
                    team_post_id: teamPostId || 0,
                    action,
                    message,
                }),
            });
            const data = await res.json();
            if (!res.ok) {
                throw new Error(data?.message || 'Failed to record your offer response.');
            }
            setQueryingOfferKey(null);
            setUpdatingOfferKey(null);
            setQueryMessage('');
            setOfferNotice(`Offer marked as ${data.offer_status}.`);
            setExplorerDetail((prev) =>
                prev
                    ? {
                          ...prev,
                          offers: (prev.offers || []).map((o) => {
                              const oKey =
                                  o.cohort_id > 0 && o.expedition_signup_id > 0
                                      ? `${o.cohort_id}:${o.expedition_signup_id}`
                                      : `team:${o.team_post_id || 0}`;
                              return oKey === key
                                  ? {
                                        ...o,
                                        offer_status: data.offer_status,
                                        offer_query_message: data.offer_query_message,
                                        offer_responded_at: data.offer_responded_at,
                                    }
                                  : o;
                          }),
                      }
                    : prev
            );
        } catch (err: any) {
            setError(err?.message || 'Failed to record your offer response.');
        } finally {
            setOfferSavingKey(null);
        }
    };

    const fetchSession = (target: PreviewTarget | null) => {
        if (!config.user_data?.logged_in) {
            setLoading(false);
            return;
        }
        setLoading(true);
        setError(null);

        let url = `${config.root_url}/portal/me`;
        const isAdminUser = Boolean(config.user_data?.is_admin);
        if (isAdminUser && target) {
            if (target.type === 'user' && target.id) {
                url += `?preview_user_id=${target.id}`;
            } else if (target.type === 'explorer' && target.scout_id) {
                url += `?preview_scout_id=${target.scout_id}`;
            }
        }

        fetch(url, {
            headers: { 'X-WP-Nonce': config.nonce },
        })
            .then((res) => (res.ok ? res.json() : Promise.reject()))
            .then((data) => {
                setMe(data);
                if (data.profiles && data.profiles.length > 0) {
                    setActiveScoutId(data.profiles[0].scout_id);
                } else {
                    setActiveScoutId(null);
                    setExplorerDetail(null);
                }
                setLoading(false);
            })
            .catch(() => {
                setError('Failed to load session details.');
                setLoading(false);
            });
    };

    useEffect(() => {
        fetchSession(previewTarget);
    }, []);

    const handleSelectPreviewTarget = (target: PreviewTarget | null) => {
        setPreviewTarget(target);
        try {
            if (target) {
                sessionStorage.setItem('ems_portal_preview_target', JSON.stringify(target));
            } else {
                sessionStorage.removeItem('ems_portal_preview_target');
            }
        } catch {
            // Ignore storage errors
        }
        fetchSession(target);
    };

    useEffect(() => {
        if (!activeScout) {
            setExplorerDetail(null);
            return;
        }

        setLoading(true);
        setError(null);

        let explorerUrl = `${config.root_url}/portal/explorer/${activeScout}`;
        if (isAdmin && (previewTarget?.accessType === 'parent' || me?.access_type === 'parent')) {
            explorerUrl += `?preview_access_type=parent`;
        }

        fetch(explorerUrl, {
            headers: { 'X-WP-Nonce': config.nonce },
        })
            .then((res) => {
                if (res.status === 403) {
                    return Promise.reject('You do not have permission to view this explorer profile.');
                }
                if (res.status === 404) {
                    return Promise.reject('Explorer record not found.');
                }
                return res.ok ? res.json() : Promise.reject('Failed to load explorer details.');
            })
            .then((data) => {
                setExplorerDetail(data);
                setLoading(false);
            })
            .catch((err) => {
                setError(typeof err === 'string' ? err : 'An error occurred while loading.');
                setLoading(false);
            });
    }, [activeScout, previewTarget]);

    if (loading && !me) {
        return (
            <div className="ems-portal-root ems-portal-container">
                <p>Loading portal...</p>
            </div>
        );
    }

    if (!config.user_data?.logged_in || (me && !me.logged_in)) {
        return (
            <div className="ems-portal-root ems-portal-container portal-login-prompt ems-portal-welcome-card">
                <h2>Expedition Management System</h2>
                <p>
                    Welcome to the expedition portal. Please log in using your Online Scout Manager account to access
                    training, team information, and expedition details.
                </p>
                <a href={config.login_url} className="ems-btn-scout-primary" style={{ marginTop: '16px' }}>
                    Log In via Online Scout Manager
                </a>
            </div>
        );
    }

    if (me && me.access_type !== 'parent' && me.access_type !== 'member' && !me.profiles?.length) {
        if (isAdmin) {
            return (
                <div className="ems-portal-root ems-portal-container">
                    <AdminPreviewBar
                        isAdmin={isAdmin}
                        activeTarget={previewTarget}
                        onSelectTarget={handleSelectPreviewTarget}
                        rootUrl={config.root_url}
                        nonce={config.nonce}
                    />
                    <div
                        className="portal-login-prompt ems-portal-welcome-card"
                        style={{ textAlign: 'center', padding: '30px', marginTop: '20px' }}
                    >
                        <h2>Administrator Portal Preview</h2>
                        <p style={{ maxWidth: '600px', margin: '0 auto 20px', color: '#475569' }}>
                            You are logged in with administrator privileges. Use the selector above to preview the portal
                            experience as any parent or explorer without needing to switch user accounts.
                        </p>
                    </div>
                </div>
            );
        }

        return (
            <div
                className="ems-portal-root ems-portal-container"
                style={{ padding: '20px', background: '#fffbeb', borderLeft: '4px solid #ffe627' }}
            >
                <h3>Administrator / Leader View</h3>
                <p>
                    Logged in as: <strong>{me.display_name}</strong> ({me.access_type})
                </p>
                <p>
                    You do not currently have a linked explorer or parent role. To manage expeditions, please visit the
                    internal admin dashboard.
                </p>
            </div>
        );
    }

    const expeditionEvents = [
        ...(explorerDetail?.events?.practice || []),
        ...(explorerDetail?.events?.qualifying || []),
    ];

    const primaryOffer = explorerDetail?.offers?.[0];
    const defaultTransport = primaryOffer?.transport_mode ? capitalizeLabel(primaryOffer.transport_mode) : 'Hillwalking';

    return (
        <div className="ems-portal-root ems-portal-container">
            <AdminPreviewBar
                isAdmin={isAdmin}
                activeTarget={previewTarget}
                onSelectTarget={handleSelectPreviewTarget}
                rootUrl={config.root_url}
                nonce={config.nonce}
            />

            <div className="portal-header" style={{ marginBottom: '20px' }}>
                {me?.profiles && me.profiles.length > 1 && (
                    <div className="ems-portal-switcher" role="group" aria-label="Explorer Selector">
                        <span className="ems-portal-switcher__label">
                            Showing details for:
                        </span>
                        <div className="ems-portal-switcher__buttons">
                            {me.profiles.map((p) => {
                                const isSelected = activeScout === p.scout_id;
                                return (
                                    <button
                                        key={p.scout_id}
                                        type="button"
                                        aria-pressed={isSelected}
                                        className={`ems-portal-switcher__btn ${isSelected ? 'is-active' : ''}`}
                                        onClick={() => {
                                            setActiveScoutId(p.scout_id);
                                            setSelectedSignupId(null);
                                            setOfferNotice(null);
                                        }}
                                    >
                                        {p.label === 'Myself'
                                            ? `Myself (${p.first_name} ${p.last_name})`
                                            : `${p.first_name} ${p.last_name}`}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* 4-Tab Portal Navigation */}
                <div className="ems-portal-tabs" role="tablist" aria-label="Participant Portal Navigation">
                    {(
                        [
                            { id: 'expeditions', label: 'My Expeditions' },
                            { id: 'training', label: 'Training' },
                            { id: 'route', label: 'Route Planner' },
                            { id: 'signups', label: 'My Sign-ups' },
                        ] as const
                    ).map((tab) => {
                        const isActive = currentPage === tab.id;
                        return (
                            <button
                                key={tab.id}
                                type="button"
                                role="tab"
                                aria-selected={isActive}
                                onClick={() => {
                                    setCurrentPage(tab.id);
                                    if (tab.id !== 'signups') setSelectedSignupId(null);
                                }}
                                className={`ems-portal-tab ${isActive ? 'is-active' : ''}`}
                            >
                                {tab.label}
                            </button>
                        );
                    })}
                </div>
            </div>

            {error && (
                <div
                    className="portal-error"
                    role="alert"
                    style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', background: '#fef2f2', color: '#991b1b', padding: '12px 16px', borderRadius: '6px', marginBottom: '16px', border: '1px solid #fecaca' }}
                >
                    <span>{error}</span>
                    <button
                        type="button"
                        aria-label="Dismiss error"
                        onClick={() => setError(null)}
                        style={{ background: 'transparent', border: 'none', color: 'inherit', cursor: 'pointer', fontSize: '18px', lineHeight: 1, padding: '0 4px' }}
                    >
                        ×
                    </button>
                </div>
            )}

            {loading && <p>Loading details...</p>}

            {!loading && explorerDetail && (
                <div className="portal-content">
                    {offerNotice && (
                        <div
                            className="notice notice-success"
                            role="status"
                            style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', padding: '10px 14px', marginBottom: '16px', background: '#edfaef', border: '1px solid #86efac', borderRadius: '6px', color: '#166534' }}
                        >
                            <span>{offerNotice}</span>
                            <button
                                type="button"
                                aria-label="Dismiss message"
                                onClick={() => setOfferNotice(null)}
                                style={{ background: 'transparent', border: 'none', color: 'inherit', cursor: 'pointer', fontSize: '18px', lineHeight: 1, padding: '0 4px' }}
                            >
                                ×
                            </button>
                        </div>
                    )}

                    {/* TAB 1: My Expeditions (Default) */}
                    {currentPage === 'expeditions' && (
                        <ModuleErrorBoundary moduleName="Portal Expeditions">
                            {(() => {
                                const eventsById = new Map<number, EventData>();
                                for (const ev of expeditionEvents) {
                                    eventsById.set(ev.id, ev);
                                }
                                const matchedEventIds = new Set<number>();

                                const offers = Array.isArray(explorerDetail.offers) ? explorerDetail.offers : [];

                                type TeamContainer = {
                                    key: string;
                                    offer: OfferRecord | null;
                                    title: string;
                                    transportLabel: string;
                                    stages: Array<{
                                        stageLabel: string;
                                        eventCode: string;
                                        teamCode?: string;
                                        teammates?: Teammate[];
                                        startDate: string;
                                        endDate: string;
                                        event?: EventData;
                                    }>;
                                };

                                const teamContainers: TeamContainer[] = offers.map((offer) => {
                                    const key =
                                        offer.cohort_id > 0 && offer.expedition_signup_id > 0
                                            ? `${offer.cohort_id}:${offer.expedition_signup_id}`
                                            : `team:${offer.team_post_id || 0}`;
                                    const title = [
                                        offer.team_name,
                                        capitalizeLabel(offer.dofe_level),
                                        capitalizeLabel(offer.transport_mode),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ');
                                    const transportLabel = capitalizeLabel(offer.transport_mode) || defaultTransport;

                                    const sortedStages = [...(offer.stages || [])].sort((a, b) => {
                                        const aRank = a.type?.toLowerCase() === 'practice' ? 0 : 1;
                                        const bRank = b.type?.toLowerCase() === 'practice' ? 0 : 1;
                                        return aRank - bRank;
                                    });

                                    const stages = sortedStages.map((st) => {
                                        const matchedEv = eventsById.get(st.expedition_id);
                                        if (matchedEv) matchedEventIds.add(matchedEv.id);
                                        const stageLabel =
                                            st.type?.toLowerCase() === 'practice'
                                                ? 'Practice'
                                                : st.type?.toLowerCase().includes('qualif')
                                                ? 'Qualifier'
                                                : capitalizeLabel(st.type) || 'Stage';
                                        return {
                                            stageLabel,
                                            eventCode: st.event_code || matchedEv?.event_code || st.expedition_name,
                                            teamCode: st.team_code || '',
                                            teammates: st.teammates,
                                            startDate: st.start_date || matchedEv?.start_date || '',
                                            endDate: st.end_date || matchedEv?.end_date || '',
                                            event: matchedEv,
                                        };
                                    });

                                    return { key, offer, title, transportLabel, stages };
                                });

                                const unmatchedEvents = expeditionEvents.filter((ev) => !matchedEventIds.has(ev.id));
                                if (unmatchedEvents.length > 0 || (teamContainers.length === 0 && explorerDetail.team)) {
                                    teamContainers.push({
                                        key: `team:${explorerDetail.team?.team_code || 'assigned'}`,
                                        offer: null,
                                        title: explorerDetail.team?.team_code
                                            ? `${explorerDetail.team.team_code} · ${defaultTransport}`
                                            : 'Assigned Expeditions',
                                        transportLabel: defaultTransport,
                                        stages: unmatchedEvents.map((ev) => ({
                                            stageLabel:
                                                ev.type?.toLowerCase() === 'practice'
                                                    ? 'Practice'
                                                    : ev.type?.toLowerCase().includes('qualif')
                                                    ? 'Qualifier'
                                                    : capitalizeLabel(ev.type) || 'Expedition',
                                            eventCode: ev.event_code || ev.name,
                                            teamCode: explorerDetail.team?.team_code || '',
                                            teammates: undefined as Teammate[] | undefined,
                                            startDate: ev.start_date,
                                            endDate: ev.end_date,
                                            event: ev,
                                        })),
                                    });
                                }

                                if (teamContainers.length === 0) {
                                    return (
                                        <div className="ems-portal-event-card">
                                            <p style={{ margin: 0, color: '#475569' }}>
                                                No expeditions have been assigned yet for {explorerDetail.explorer.first_name}{' '}
                                                {explorerDetail.explorer.last_name}.
                                            </p>
                                        </div>
                                    );
                                }

                                return (
                                    <div>
                                        {teamContainers.map((teamItem) => {
                                            const offer = teamItem.offer;
                                            const canRoleRespond =
                                                me?.access_type === 'parent' ||
                                                me?.access_type === 'network_member' ||
                                                Boolean(isAdmin && !me?.is_preview);
                                            const canRespond = canRoleRespond && (offer ? offer.deadline?.allowed !== false : false);
                                            const isEditingResponse = offer
                                                ? offer.offer_status === 'pending' || updatingOfferKey === teamItem.key
                                                : false;
                                            const displayDeadlineDate = offer?.deadline
                                                ? offer.deadline.grace_applied && offer.deadline.effective_hard_cutoff
                                                    ? offer.deadline.effective_hard_cutoff
                                                    : offer.deadline.communicated_date || offer.deadline.effective_hard_cutoff || null
                                                : null;
                                            const deadlineClosed = Boolean(offer && offer.deadline?.allowed === false);

                                            return (
                                                <div key={teamItem.key} className="ems-portal-offer-card">
                                                    <div className="ems-portal-offer-card__header">
                                                        <h3 className="ems-portal-offer-card__title">{teamItem.title}</h3>
                                                        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                                            {offer && offer.offer_status === 'pending' && offer.notification_status === 'notified' && (
                                                                <span className="ems-portal-status-pill ems-portal-status-pill--info">
                                                                    Offer Sent
                                                                </span>
                                                            )}
                                                            {offer && displayDeadlineDate && (
                                                                <span
                                                                    className="ems-portal-deadline-pill"
                                                                    style={
                                                                        deadlineClosed
                                                                            ? { background: '#fee2e2', color: '#991b1b', borderColor: '#fecaca' }
                                                                            : undefined
                                                                    }
                                                                >
                                                                    {deadlineClosed
                                                                        ? `Closed ${formatOrdinalDate(offer.deadline?.effective_hard_cutoff || displayDeadlineDate)}`
                                                                        : offer.offer_status === 'pending'
                                                                        ? `Respond by ${formatOrdinalDate(displayDeadlineDate)}`
                                                                        : `Changes close ${formatOrdinalDate(displayDeadlineDate)}`}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Your Teammates (fallback when stages do not carry per-stage teammates) */}
                                                    {!teamItem.stages.some((st) => Array.isArray(st.teammates)) && (
                                                        <div style={{ marginBottom: '12px' }}>
                                                            <div className="ems-portal-offer-section-label">Your Teammates</div>
                                                            {explorerDetail.team && explorerDetail.team.teammates.length > 0 ? (
                                                                renderTeammateGroups(explorerDetail.team.teammates)
                                                            ) : (
                                                                <p style={{ margin: 0, fontSize: '13px', color: '#64748b' }}>
                                                                    Teammates will appear here once allocated.
                                                                </p>
                                                            )}
                                                        </div>
                                                    )}

                                                    {/* Place Offer Status / Actions */}
                                                    {offer && (
                                                        <>
                                                            {offer.offer_status !== 'pending' &&
                                                                updatingOfferKey !== teamItem.key && (
                                                                    <div
                                                                        className={`ems-portal-offer-confirmation ems-portal-offer-confirmation--${offer.offer_status}`}
                                                                    >
                                                                        <div>
                                                                            <strong>
                                                                                {offer.offer_status === 'accepted'
                                                                                    ? 'Offer Accepted'
                                                                                    : offer.offer_status === 'queried'
                                                                                    ? 'Query Sent to Expedition Team'
                                                                                    : 'Offer Declined'}
                                                                            </strong>
                                                                            {offer.offer_status === 'queried' &&
                                                                                offer.offer_query_message && (
                                                                                    <div style={{ marginTop: '4px', fontSize: '13px' }}>
                                                                                        “{offer.offer_query_message}”
                                                                                    </div>
                                                                                )}
                                                                        </div>
                                                                        {canRespond && (
                                                                            <button
                                                                                type="button"
                                                                                className="ems-btn-scout-link"
                                                                                onClick={() => {
                                                                                    setUpdatingOfferKey(teamItem.key);
                                                                                    setQueryingOfferKey(null);
                                                                                }}
                                                                            >
                                                                                Update Response
                                                                            </button>
                                                                        )}
                                                                    </div>
                                                                )}

                                                            {isEditingResponse && (
                                                                <>
                                                                    {!canRoleRespond ? (
                                                                        <div style={{ color: '#475569', fontSize: '13px', marginTop: '8px', background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '6px', padding: '8px 12px' }}>
                                                                            Pending parent/guardian response — only a parent, guardian, or networker can accept or decline this offer.
                                                                        </div>
                                                                    ) : !canRespond ? (
                                                                        <div style={{ color: '#991b1b', fontSize: '13px', marginTop: '8px' }}>
                                                                            {offer.deadline?.reason ||
                                                                                'The response deadline has passed.'}
                                                                        </div>
                                                                    ) : (
                                                                        <div className="ems-portal-offer-actions">
                                                                            <button
                                                                                type="button"
                                                                                className="ems-btn-scout-primary"
                                                                                disabled={offerSavingKey === teamItem.key}
                                                                                onClick={() =>
                                                                                    handleRespondToOffer(
                                                                                        offer.cohort_id,
                                                                                        offer.expedition_signup_id,
                                                                                        offer.team_post_id,
                                                                                        'accept'
                                                                                    )
                                                                                }
                                                                            >
                                                                                Accept Offer
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                className="ems-btn-scout-secondary"
                                                                                disabled={offerSavingKey === teamItem.key}
                                                                                onClick={() => {
                                                                                    setQueryingOfferKey(
                                                                                        queryingOfferKey === teamItem.key
                                                                                            ? null
                                                                                            : teamItem.key
                                                                                    );
                                                                                    setQueryMessage('');
                                                                                }}
                                                                            >
                                                                                Request Team or Date Change
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                className="ems-btn-scout-decline"
                                                                                disabled={offerSavingKey === teamItem.key}
                                                                                onClick={() =>
                                                                                    handleRespondToOffer(
                                                                                        offer.cohort_id,
                                                                                        offer.expedition_signup_id,
                                                                                        offer.team_post_id,
                                                                                        'decline'
                                                                                    )
                                                                                }
                                                                            >
                                                                                Decline
                                                                            </button>
                                                                            {updatingOfferKey === teamItem.key && (
                                                                                <button
                                                                                    type="button"
                                                                                    className="ems-btn-scout-link"
                                                                                    onClick={() => {
                                                                                        setUpdatingOfferKey(null);
                                                                                        setQueryingOfferKey(null);
                                                                                    }}
                                                                                >
                                                                                    Cancel
                                                                                </button>
                                                                            )}
                                                                        </div>
                                                                    )}

                                                                    {queryingOfferKey === teamItem.key && (
                                                                        <div className="ems-portal-query-box">
                                                                            <div style={{ fontSize: '13px', color: '#1e293b', lineHeight: 1.5 }}>
                                                                                <p style={{ margin: '0 0 6px 0' }}>
                                                                                    If you cannot make the proposed dates, please provide as much information as possible about your availability.
                                                                                </p>
                                                                                <p style={{ margin: 0, fontSize: '12.5px', color: '#475569' }}>
                                                                                    For general enquiries, please email{' '}
                                                                                    <a href="mailto:expeditions@sesscouts.org.uk" style={{ color: 'var(--ems-scout-blue)', fontWeight: 600 }}>
                                                                                        expeditions@sesscouts.org.uk
                                                                                    </a>.
                                                                                </p>
                                                                            </div>
                                                                            <textarea
                                                                                rows={3}
                                                                                maxLength={1000}
                                                                                placeholder="Describe the team or date changes needed and any alternative dates you are available..."
                                                                                value={queryMessage}
                                                                                onChange={(e) => setQueryMessage(e.target.value.slice(0, 1000))}
                                                                            />
                                                                            <div
                                                                                style={{
                                                                                    fontSize: '12px',
                                                                                    color: queryMessage.length >= 900 ? '#b45309' : '#64748b',
                                                                                    textAlign: 'right',
                                                                                    marginTop: '-4px',
                                                                                }}
                                                                            >
                                                                                {queryMessage.length} / 1000 characters
                                                                            </div>
                                                                            <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                                                                                <button
                                                                                    type="button"
                                                                                    className="ems-btn-scout-primary"
                                                                                    disabled={
                                                                                        offerSavingKey === teamItem.key ||
                                                                                        !queryMessage.trim() ||
                                                                                        queryMessage.length > 1000
                                                                                    }
                                                                                    onClick={() =>
                                                                                        handleRespondToOffer(
                                                                                            offer.cohort_id,
                                                                                            offer.expedition_signup_id,
                                                                                            offer.team_post_id,
                                                                                            'query',
                                                                                            queryMessage
                                                                                        )
                                                                                    }
                                                                                >
                                                                                    Submit Request
                                                                                </button>
                                                                                <button
                                                                                    type="button"
                                                                                    className="ems-btn-scout-secondary"
                                                                                    disabled={offerSavingKey === teamItem.key}
                                                                                    onClick={() => {
                                                                                        setQueryingOfferKey(null);
                                                                                        setQueryMessage('');
                                                                                    }}
                                                                                >
                                                                                    Cancel
                                                                                </button>
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                </>
                                                            )}
                                                        </>
                                                    )}

                                                    {/* Nested Practice & Qualifier Expedition Sub-Cards */}
                                                    {teamItem.stages.length > 0 && (
                                                        <div className="ems-portal-nested-expeditions">
                                                            {teamItem.stages.map((st, sIdx) => {
                                                                const ev = st.event;
                                                                const modeLabel =
                                                                    capitalizeLabel(ev?.transport_mode) ||
                                                                    teamItem.transportLabel;
                                                                const licName = ev?.leader_in_charge?.name
                                                                    ? ev.leader_in_charge.name
                                                                    : 'To be confirmed';
                                                                const hasWhatsApp = Boolean(
                                                                    ev?.whatsapp_explorers || ev?.whatsapp_parents
                                                                );

                                                                return (
                                                                    <div
                                                                        key={`${st.eventCode}-${sIdx}`}
                                                                        className="ems-portal-nested-exp-card"
                                                                    >
                                                                        <div className="ems-portal-nested-exp-card__header">
                                                                            <span className="ems-portal-stage-badge">
                                                                                {st.stageLabel}
                                                                            </span>
                                                                            {st.teamCode && (
                                                                                <span
                                                                                    className="ems-portal-stage-badge"
                                                                                    style={{ background: '#e0e7ff', color: '#3730a3' }}
                                                                                >
                                                                                    {st.teamCode}
                                                                                </span>
                                                                            )}
                                                                            <span className="ems-portal-nested-exp-card__code">
                                                                                {st.stageLabel} · {st.eventCode}
                                                                            </span>
                                                                        </div>
                                                                        <div className="ems-portal-nested-exp-card__dates">
                                                                            {formatDateRange(st.startDate, st.endDate)}
                                                                        </div>
                                                                        <div className="ems-portal-nested-exp-card__meta">
                                                                            Mode: {modeLabel} · Leader in Charge:{' '}
                                                                            <strong>{licName}</strong>
                                                                            {ev?.leader_in_charge?.email && (
                                                                                <div>
                                                                                    <a href={`mailto:${ev.leader_in_charge.email}`}>
                                                                                        {ev.leader_in_charge.email}
                                                                                    </a>
                                                                                </div>
                                                                            )}
                                                                        </div>

                                                                        {Array.isArray(st.teammates) && (
                                                                            <div style={{ marginTop: '10px', paddingTop: '8px', borderTop: '1px solid #e2e8f0' }}>
                                                                                <div className="ems-portal-offer-section-label" style={{ marginBottom: '4px' }}>
                                                                                    Teammates{st.teamCode ? ` (${st.teamCode})` : ''}
                                                                                </div>
                                                                                {st.teammates.length > 0 ? (
                                                                                    renderTeammateGroups(st.teammates)
                                                                                ) : (
                                                                                    <p style={{ margin: 0, fontSize: '12px', color: '#64748b' }}>
                                                                                        Teammates will appear here once allocated.
                                                                                    </p>
                                                                                )}
                                                                            </div>
                                                                        )}

                                                                        {hasWhatsApp && ev && (
                                                                            <div style={{ marginTop: '10px', display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                                                                                {ev.whatsapp_explorers && (
                                                                                    <a
                                                                                        href={ev.whatsapp_explorers}
                                                                                        target="_blank"
                                                                                        rel="noopener noreferrer"
                                                                                        className="ems-btn-scout-secondary"
                                                                                        style={{ padding: '5px 10px', fontSize: '12px' }}
                                                                                    >
                                                                                        Explorers WhatsApp
                                                                                    </a>
                                                                                )}
                                                                                {ev.whatsapp_parents && (
                                                                                    <a
                                                                                        href={ev.whatsapp_parents}
                                                                                        target="_blank"
                                                                                        rel="noopener noreferrer"
                                                                                        className="ems-btn-scout-secondary"
                                                                                        style={{ padding: '5px 10px', fontSize: '12px' }}
                                                                                    >
                                                                                        Parents WhatsApp
                                                                                    </a>
                                                                                )}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                );
                                                            })}
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                );
                            })()}
                        </ModuleErrorBoundary>
                    )}

                    {/* TAB 2: Training */}
                    {currentPage === 'training' && (
                        <ModuleErrorBoundary moduleName="Portal Training">
                            <div className="ems-portal-event-card">
                                <h3 className="ems-portal-event-card__title">
                                    Online Training &amp; First Aid — {explorerDetail.explorer.first_name}{' '}
                                    {explorerDetail.explorer.last_name}
                                </h3>
                                <div
                                    style={{
                                        margin: '0 0 16px 0',
                                        padding: '12px 14px',
                                        background: '#f0f6fc',
                                        borderRadius: '6px',
                                        borderLeft: '4px solid var(--ems-scout-navy)',
                                        fontSize: '13px',
                                        color: 'var(--ems-scout-navy)',
                                    }}
                                >
                                    <strong>Note:</strong> The explorer must log in to their own account to complete
                                    online training. Progress completed under a parent account is not credited to the
                                    explorer.
                                </div>

                                <p style={{ fontSize: '14px', marginBottom: '16px' }}>
                                    <strong>First Aid Qualification:</strong>{' '}
                                    <span style={{ textTransform: 'capitalize' }}>
                                        {(explorerDetail.explorer.first_aid_level || 'none').replace(/[-_]/g, ' ')}
                                    </span>
                                </p>

                                <h4 style={{ margin: '0 0 10px 0', fontSize: '15px' }}>Training Course Requirements</h4>
                                {explorerDetail.training_checklist.length === 0 ? (
                                    <p style={{ color: '#475569', fontStyle: 'italic' }}>
                                        Training information has not been specified yet and will be added later.
                                    </p>
                                ) : (
                                    <ul style={{ listStyle: 'none', paddingLeft: 0, margin: 0 }}>
                                        {explorerDetail.training_checklist.map((tc, idx) => (
                                            <li
                                                key={idx}
                                                style={{
                                                    padding: '10px 0',
                                                    borderBottom: '1px solid #e2e8f0',
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    justifyContent: 'space-between',
                                                    gap: '12px',
                                                }}
                                            >
                                                <span>
                                                    <strong style={{ color: tc.completed ? '#15803d' : '#64748b', marginRight: '8px' }}>
                                                        {tc.completed ? 'Complete' : 'Incomplete'}
                                                    </strong>
                                                    {tc.course_name}
                                                </span>
                                                {!explorerDetail.training_readonly && tc.course_url && (
                                                    <a
                                                        href={tc.course_url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="ems-btn-scout-secondary"
                                                    >
                                                        Go to Course
                                                    </a>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </ModuleErrorBoundary>
                    )}

                    {/* TAB 3: Route Planner */}
                    {currentPage === 'route' && (
                        <ModuleErrorBoundary moduleName="Portal Route Planner">
                            <div className="ems-portal-event-card">
                                <h3 className="ems-portal-event-card__title">
                                    Route Planning — {explorerDetail.explorer.first_name}{' '}
                                    {explorerDetail.explorer.last_name}
                                </h3>
                                {expeditionEvents.length === 0 ? (
                                    <p style={{ color: '#475569', margin: 0 }}>
                                        Route details will appear here once you are allocated to an expedition.
                                    </p>
                                ) : (
                                    expeditionEvents.map((ev) => {
                                        const cleanRouteInfo = (ev.route_info || '').replace(/<[^>]*>/g, '').trim();
                                        const hasStart = Boolean((ev.location || '').trim());
                                        const hasEnd = Boolean((ev.end_location || '').trim());
                                        const hasAnyRoute =
                                            hasStart ||
                                            hasEnd ||
                                            Boolean(cleanRouteInfo) ||
                                            Boolean((ev.route_deadline || '').trim());

                                        return (
                                            <div
                                                key={ev.id}
                                                style={{
                                                    paddingTop: '12px',
                                                    marginTop: '12px',
                                                    borderTop: '1px solid #e2e8f0',
                                                }}
                                            >
                                                <h4 style={{ margin: '0 0 8px 0', color: 'var(--ems-scout-navy)' }}>
                                                    {formatEventCardTitle(ev)}
                                                </h4>
                                                {!hasAnyRoute ? (
                                                    <p style={{ color: '#475569', fontStyle: 'italic' }}>
                                                        Route information has not been specified yet and will be added later.
                                                    </p>
                                                ) : (
                                                    <>
                                                        {hasStart && (
                                                            <p>
                                                                <strong>Start Point:</strong> <ResolvedLocation value={ev.location} />
                                                            </p>
                                                        )}
                                                        {hasEnd && (
                                                            <p>
                                                                <strong>End Point:</strong> <ResolvedLocation value={ev.end_location} />
                                                            </p>
                                                        )}
                                                        <p>
                                                            <strong>Route Status:</strong>{' '}
                                                            <span style={{ textTransform: 'capitalize' }}>
                                                                {explorerDetail.team?.route_status || 'pending'}
                                                            </span>
                                                        </p>
                                                        <p>
                                                            <strong>Route Deadline:</strong>{' '}
                                                            {ev.route_deadline ? formatOrdinalDate(ev.route_deadline) : 'To be added later'}
                                                        </p>
                                                        {(hasStart || hasEnd) && (
                                                            <div style={{ margin: '14px 0' }}>
                                                                <OSMReadOnlyMap startLocation={ev.location} endLocation={ev.end_location} />
                                                            </div>
                                                        )}
                                                        {cleanRouteInfo && (
                                                            <div
                                                                style={{
                                                                    marginTop: '12px',
                                                                    padding: '12px 14px',
                                                                    background: '#f8fafc',
                                                                    borderRadius: '6px',
                                                                    borderLeft: '4px solid var(--ems-scout-blue)',
                                                                }}
                                                                dangerouslySetInnerHTML={{ __html: ev.route_info || '' }}
                                                            />
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                        </ModuleErrorBoundary>
                    )}

                    {/* TAB 4: My Sign-ups */}
                    {currentPage === 'signups' && (
                        <ModuleErrorBoundary moduleName="Portal Signups">
                            <div className="ems-portal-event-card">
                                <h3 className="ems-portal-event-card__title">
                                    My Sign-ups — {explorerDetail.explorer.first_name} {explorerDetail.explorer.last_name}
                                </h3>
                                {explorerDetail.signups.length === 0 ? (
                                    <p style={{ margin: 0, color: '#475569' }}>No active sign-ups found.</p>
                                ) : (
                                    <div style={{ display: 'flex', gap: '20px', alignItems: 'flex-start', flexWrap: 'wrap' }}>
                                        <div style={{ flex: 1, minWidth: '300px', overflowX: 'auto' }}>
                                            <table className="wp-list-table widefat fixed striped" style={{ width: '100%' }}>
                                                <thead>
                                                    <tr>
                                                        <th>Level</th>
                                                        <th>Application Type</th>
                                                        <th>Status</th>
                                                        <th>Payment</th>
                                                        <th>Submitted</th>
                                                        <th style={{ width: '90px', textAlign: 'right' }}>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {explorerDetail.signups.map((s) => (
                                                        <tr
                                                            key={s.id}
                                                            onClick={() => setSelectedSignupId(s.id === selectedSignupId ? null : s.id)}
                                                            style={{
                                                                cursor: 'pointer',
                                                                background: selectedSignupId === s.id ? '#f0f6fc' : undefined,
                                                            }}
                                                        >
                                                            <td style={{ textTransform: 'capitalize' }}>
                                                                <strong>{s.dofe_level}</strong>
                                                            </td>
                                                            <td style={{ textTransform: 'capitalize' }}>{s.type}</td>
                                                            <td style={{ textTransform: 'capitalize' }}>{s.signup_status}</td>
                                                            <td style={{ textTransform: 'capitalize' }}>
                                                                {s.payment_status || '—'}
                                                            </td>
                                                            <td>{formatOrdinalDate(s.created_at)}</td>
                                                            <td style={{ textAlign: 'right' }}>
                                                                <span style={{ color: 'var(--ems-scout-blue)', textDecoration: 'underline' }}>
                                                                    {selectedSignupId === s.id ? 'Hide' : 'View'}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>

                                        {selectedSignupId && (() => {
                                            const s = explorerDetail.signups.find((item) => item.id === selectedSignupId);
                                            if (!s) return null;
                                            return (
                                                <div className="ems-signups-inspector" style={{ alignSelf: 'flex-start' }}>
                                                    <div className="ems-signups-inspector__header">
                                                        <h4 className="ems-signups-inspector__title" style={{ margin: 0, fontSize: '15px' }}>
                                                            Application Details
                                                        </h4>
                                                        <button
                                                            type="button"
                                                            onClick={() => setSelectedSignupId(null)}
                                                            style={{ background: 'none', border: 'none', fontSize: '20px', cursor: 'pointer', padding: '0 5px', color: '#64748b' }}
                                                        >
                                                            ×
                                                        </button>
                                                    </div>
                                                    <div className="ems-signups-inspector__body">
                                                        <div>
                                                            <span className="ems-signups-inspector__label">DofE Level</span>
                                                            <div className="ems-signups-inspector__value--large" style={{ textTransform: 'capitalize' }}>
                                                                {s.dofe_level}
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <span className="ems-signups-inspector__label">Application Type</span>
                                                            <div className="ems-signups-inspector__value" style={{ textTransform: 'capitalize' }}>
                                                                {s.type} signup
                                                            </div>
                                                        </div>
                                                        {s.dofe_number && (
                                                            <div>
                                                                <span className="ems-signups-inspector__label">DofE Number</span>
                                                                <div className="ems-signups-inspector__value ems-monospace">{s.dofe_number}</div>
                                                            </div>
                                                        )}
                                                        {s.additional_support_needs && (
                                                            <div>
                                                                <span className="ems-signups-inspector__label">Additional Support Needs</span>
                                                                <div className="ems-signups-inspector__value">{s.additional_support_needs}</div>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })()}
                                    </div>
                                )}
                            </div>
                        </ModuleErrorBoundary>
                    )}
                </div>
            )}
        </div>
    );
}

document.addEventListener('DOMContentLoaded', () => {
    const rootEl = document.getElementById('ems-portal-root');
    if (rootEl) {
        createRoot(rootEl).render(
            <ModuleErrorBoundary moduleName="Portal">
                <PortalApp />
            </ModuleErrorBoundary>
        );
    }
});
