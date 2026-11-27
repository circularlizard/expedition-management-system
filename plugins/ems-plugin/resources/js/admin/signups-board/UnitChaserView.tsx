import React, { useState, useEffect, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faEnvelope,
    faExternalLinkAlt,
    faTriangleExclamation,
    faClock,
    faUser,
} from '@fortawesome/free-solid-svg-icons';
import {
    ANOMALY_META,
    computeSignupAnomaliesByKey,
    getEffectiveSignupAnomalies,
    groupSignupsByExplorerIdentity,
} from './dashboardMetrics';
import { getUnitDistrictMap, normalize } from './tableUtils';
import { RichTextEditor, ShortcodeOption } from '../expedition-board/RichTextEditor';

export type ChaserType = 'osm_share' | 'unpaired_signups' | 'missing_edofe';
export { ANOMALY_META };

interface UnitChaserViewProps {
    chaserType?: ChaserType;
    signups: any[];
    units: any[];
    initialAnomalies?: string[];
    initialLevel?: 'all' | 'bronze' | 'silver' | 'gold';
    onSelectExplorer?: (signup: any) => void;
    onChaserSent?: () => void;
}

interface RecipientOption {
    email: string;
    label: string;
}

interface ChaserExplorerItem {
    key: string;
    name: string;
    firstName: string;
    level: string;
    anomalies: string[];
    scoutId: number;
    email: string;
    parentEmail: string;
    recipientOptions: RecipientOption[];
    primarySignup: any;
    allSignups: any[];
}

interface UnitChaserGroup {
    unitName: string;
    district: string;
    leaderEmail: string;
    explorers: ChaserExplorerItem[];
}

interface EmailDraft {
    audience: 'unit_leader' | 'individual';
    chaserType: ChaserType;
    unitName: string;
    to: string;
    cc: string;
    recipientOptions?: RecipientOption[];
    selectedEmails?: string[];
    manualEmails?: string;
    subject: string;
    message: string;
    anomalies: string[];
    includedSignups: { id: number; type: string; scout_id: number }[];
    targetSignupId?: number;
    targetSignupType?: string;
    targetScoutId?: number;
    explorerName?: string;
    shortcodes?: ShortcodeOption[];
}

const escapeHtml = (str: string): string =>
    str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

export const UnitChaserView: React.FC<UnitChaserViewProps> = ({
    signups,
    units,
    initialAnomalies,
    initialLevel = 'all',
    onSelectExplorer,
    onChaserSent,
}) => {
    const [selectedAnomalies, setSelectedAnomalies] = useState<string[]>(
        initialAnomalies && initialAnomalies.length > 0
            ? initialAnomalies
            : ANOMALY_META.map((a) => a.key)
    );
    const [levelFilter, setLevelFilter] = useState<'all' | 'bronze' | 'silver' | 'gold'>(initialLevel);
    const [deselectedExplorerKeys, setDeselectedExplorerKeys] = useState<Set<string>>(() => new Set());
    const [history, setHistory] = useState<Record<string, string>>({});
    const [fluentSmtpUrl, setFluentSmtpUrl] = useState<string>('admin.php?page=fluent-mail#/logs');
    const [draft, setDraft] = useState<EmailDraft | null>(null);
    const [loadingPreview, setLoadingPreview] = useState<string | null>(null);
    const [sending, setSending] = useState<boolean>(false);
    const [notice, setNotice] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    const rawRootUrl = (typeof window !== 'undefined' && window.emsSignupsBoard?.root_url) || '';
    const rootUrl = rawRootUrl.replace(/\/+$/, '');
    const nonce = (typeof window !== 'undefined' && window.emsSignupsBoard?.nonce) || '';

    useEffect(() => {
        if (!rootUrl) return;
        let active = true;
        const req = fetch(`${rootUrl}/signups/chaser-history?type=osm_share`, {
            headers: { 'X-WP-Nonce': nonce },
        });
        if (!req || typeof req.then !== 'function') return;
        req.then((res) => (res && res.ok ? res.json() : null))
            .then((data) => {
                if (!active || !data) return;
                if (data.history && typeof data.history === 'object' && !Array.isArray(data.history)) {
                    setHistory(data.history);
                }
                if (data.fluent_smtp_url) {
                    setFluentSmtpUrl(data.fluent_smtp_url);
                }
            })
            .catch(() => {});
        return () => {
            active = false;
        };
    }, [rootUrl, nonce]);

    const unitLookup = useMemo(() => {
        const map: Record<string, any> = {};
        (units || []).forEach((u: any) => {
            if (u?.name) map[normalize(u.name)] = u;
            if (u?.short_code) map[normalize(u.short_code)] = u;
        });
        return map;
    }, [units]);

    const unitDistrictMap = useMemo(() => getUnitDistrictMap(units), [units]);

    const toggleAnomaly = (key: string) => {
        setSelectedAnomalies((prev) =>
            prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]
        );
    };

    useEffect(() => {
        if (initialAnomalies && initialAnomalies.length > 0) {
            setSelectedAnomalies(initialAnomalies);
        } else {
            setSelectedAnomalies(ANOMALY_META.map((a) => a.key));
        }
    }, [initialAnomalies]);

    useEffect(() => {
        setLevelFilter(initialLevel || 'all');
    }, [initialLevel]);

    const unitGroups = useMemo<UnitChaserGroup[]>(() => {
        const fallbackAnomalies = computeSignupAnomaliesByKey(signups || []);
        const activeSignups = (signups || []).filter((s: any) => {
            if ((s.signup_status || 'submitted') === 'archived') return false;
            const stage = String(s.kanban_stage || '');
            if (stage === 'cancelled') return false;
            if (s.type === 'expedition' && stage === 'withdrawn') return false;
            return true;
        });
        const buckets = groupSignupsByExplorerIdentity(activeSignups);
        const byUnit: Record<string, UnitChaserGroup> = {};

        buckets.forEach((bucket, idx) => {
            const scopedItems =
                levelFilter === 'all'
                    ? bucket.items
                    : bucket.items.filter((i: any) => normalize(i.dofe_level) === levelFilter);
            if (scopedItems.length === 0) return;

            const primary = scopedItems[0];
            if (!primary) return;

            const explorerAnomalies = new Set<string>();
            scopedItems.forEach((item: any) => {
                getEffectiveSignupAnomalies(item, fallbackAnomalies).forEach((a) =>
                    explorerAnomalies.add(a)
                );
            });

            const matching = Array.from(explorerAnomalies).filter((a) => selectedAnomalies.includes(a));
            if (matching.length === 0) return;

            const rawUnit = (
                bucket.items.find((i: any) => i.unit_name && i.unit_name !== 'Unassigned')?.unit_name ||
                primary.unit_name ||
                'Unassigned'
            ).trim();
            const unitMeta = unitLookup[normalize(rawUnit)];
            const district = (primary.district || unitMeta?.district || unitDistrictMap[rawUnit] || 'Unassigned').trim();
            const leaderEmail = (unitMeta?.leader_email || primary.leader_email || '').trim();

            if (!byUnit[rawUnit]) {
                byUnit[rawUnit] = {
                    unitName: rawUnit,
                    district,
                    leaderEmail,
                    explorers: [],
                };
            }

            const fullName = `${primary.explorer_first_name || ''} ${primary.explorer_last_name || ''}`.trim() || 'Unknown';
            const osmEmail1 = (bucket.items.find((i: any) => i.osm_email1)?.osm_email1 || '').trim();
            const osmEmail2 = (bucket.items.find((i: any) => i.osm_email2)?.osm_email2 || '').trim();
            const formEmail = (bucket.items.find((i: any) => i.explorer_email)?.explorer_email || '').trim();
            const explorerEmail = osmEmail1 || formEmail;

            const osmP1Email1 = (bucket.items.find((i: any) => i.osm_p1_email1)?.osm_p1_email1 || '').trim();
            const osmP1Email2 = (bucket.items.find((i: any) => i.osm_p1_email2)?.osm_p1_email2 || '').trim();
            const osmP2Email1 = (bucket.items.find((i: any) => i.osm_p2_email1)?.osm_p2_email1 || '').trim();
            const osmP2Email2 = (bucket.items.find((i: any) => i.osm_p2_email2)?.osm_p2_email2 || '').trim();
            const formParentEmail = (bucket.items.find((i: any) => i.parent_email)?.parent_email || '').trim();
            const parentEmail = osmP1Email1 || formParentEmail;

            const rawOptions: RecipientOption[] = [
                { email: osmEmail1, label: 'Explorer (OSM)' },
                { email: osmEmail2, label: 'Explorer 2 (OSM)' },
                { email: osmP1Email1, label: 'Parent 1 (OSM)' },
                { email: osmP1Email2, label: 'Parent 1 (Alt, OSM)' },
                { email: osmP2Email1, label: 'Parent 2 (OSM)' },
                { email: osmP2Email2, label: 'Parent 2 (Alt, OSM)' },
                { email: formEmail, label: 'Explorer (Form)' },
                { email: formParentEmail, label: 'Parent (Form)' },
            ];

            const seenOpt = new Set<string>();
            const recipientOptions: RecipientOption[] = [];
            for (const opt of rawOptions) {
                const clean = String(opt.email || '').trim();
                if (!clean) continue;
                const lower = clean.toLowerCase();
                if (!seenOpt.has(lower)) {
                    seenOpt.add(lower);
                    recipientOptions.push({ email: clean, label: opt.label });
                }
            }

            const explorerKey = `${rawUnit}_${primary.type || 's'}_${primary.id || idx}`;
            byUnit[rawUnit].explorers.push({
                key: explorerKey,
                name: fullName,
                firstName: primary.explorer_first_name || '',
                level: (primary.dofe_level || '').toUpperCase(),
                anomalies: matching,
                scoutId: bucket.scoutId,
                email: explorerEmail,
                parentEmail,
                recipientOptions,
                primarySignup: primary,
                allSignups: bucket.items,
            });
        });

        return Object.values(byUnit).sort((a, b) => a.unitName.localeCompare(b.unitName));
    }, [signups, selectedAnomalies, levelFilter, unitLookup, unitDistrictMap]);

    const isExplorerSelected = (key: string): boolean => !deselectedExplorerKeys.has(key);

    const toggleExplorerSelection = (key: string) => {
        setDeselectedExplorerKeys((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    };

    const toggleUnitAllExplorers = (group: UnitChaserGroup, selectAll: boolean) => {
        setDeselectedExplorerKeys((prev) => {
            const next = new Set(prev);
            group.explorers.forEach((e) => {
                if (selectAll) {
                    next.delete(e.key);
                } else {
                    next.add(e.key);
                }
            });
            return next;
        });
    };

    const openUnitChaserModal = async (group: UnitChaserGroup) => {
        const selectedExplorers = group.explorers.filter((e) => isExplorerSelected(e.key));
        if (selectedExplorers.length === 0) return;

        setNotice(null);
        setLoadingPreview(group.unitName);

        const hasOsm = selectedExplorers.some((e) =>
            e.anomalies.some((a) => a === 'unmatched_osm' || a === 'pending_osm_sync')
        );
        const hasUnpaired = selectedExplorers.some((e) =>
            e.anomalies.some((a) => a === 'place_without_expedition' || a === 'expedition_without_place')
        );
        const hasMissingEdofe = selectedExplorers.some((e) =>
            e.anomalies.includes('missing_edofe')
        );
        const inferredType: ChaserType = hasOsm
            ? 'osm_share'
            : hasUnpaired
              ? 'unpaired_signups'
              : hasMissingEdofe
                ? 'missing_edofe'
                : 'unpaired_signups';

        const includedSignups = selectedExplorers.flatMap((e) =>
            (e.allSignups || [e.primarySignup]).map((s: any) => ({
                id: Number(s.id),
                type: String(s.type),
                scout_id: Number(e.scoutId || s.scout_id || 0),
            }))
        );

        try {
            const res = await fetch(`${rootUrl}/signups/send-chaser`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({
                    chaser_type: inferredType,
                    unit_name: group.unitName,
                    included_signups: includedSignups,
                    preview: true,
                }),
            });
            const data = await res.json();

            setDraft({
                audience: 'unit_leader',
                chaserType: inferredType,
                unitName: group.unitName,
                to: data?.to || group.leaderEmail || '',
                cc: '',
                subject: data?.subject || `DofE Sign-up Anomalies for ${group.unitName}`,
                message: data?.message || '<p>Hello,</p><p>Please review the following Explorers:</p>',
                anomalies: Array.from(new Set(selectedExplorers.flatMap((e) => e.anomalies))),
                includedSignups,
                shortcodes: Array.isArray(data?.shortcodes) ? data.shortcodes : [],
            });
        } catch {
            setNotice({ type: 'error', text: 'Could not load chaser email preview.' });
        } finally {
            setLoadingPreview(null);
        }
    };

    const openIndividualChaserModal = (group: UnitChaserGroup, exp: ChaserExplorerItem) => {
        setNotice(null);
        const anomalyList = exp.anomalies
            .map((a) => ANOMALY_META.find((m) => m.key === a)?.label || a)
            .join(', ');
        const allSelected = exp.recipientOptions.map((o) => o.email);

        setDraft({
            audience: 'individual',
            chaserType: 'unpaired_signups',
            unitName: group.unitName,
            to: allSelected.join(', '),
            cc: group.leaderEmail || '',
            recipientOptions: exp.recipientOptions,
            selectedEmails: allSelected,
            manualEmails: '',
            subject: `Action Required: DofE Sign-up for ${exp.name}`,
            message: `<p>Hello,</p><p>We are reviewing the DofE sign-up for <strong>${escapeHtml(exp.name)}</strong> (${escapeHtml(exp.level)}) and noticed the following item(s) to resolve:</p><p><strong>${escapeHtml(anomalyList)}</strong></p><p>Please reply to let us know or complete the missing details.</p><p>Thank you</p>`,
            anomalies: exp.anomalies,
            includedSignups: [
                {
                    id: Number(exp.primarySignup.id),
                    type: String(exp.primarySignup.type),
                    scout_id: Number(exp.scoutId || 0),
                },
            ],
            targetSignupId: Number(exp.primarySignup.id),
            targetSignupType: String(exp.primarySignup.type),
            targetScoutId: Number(exp.scoutId || 0),
            explorerName: exp.name,
        });
    };

    const handleSend = async () => {
        if (!draft) return;
        setSending(true);
        setNotice(null);
        let effectiveTo = draft.to;
        if (draft.audience === 'individual') {
            const combined = [
                ...(draft.selectedEmails || []),
                ...(draft.manualEmails || '')
                    .split(/[,;]+/)
                    .map((s) => s.trim())
                    .filter(Boolean),
            ];
            const seen = new Set<string>();
            const deduped: string[] = [];
            for (const addr of combined) {
                const lower = addr.toLowerCase();
                if (!seen.has(lower)) {
                    seen.add(lower);
                    deduped.push(addr);
                }
            }
            effectiveTo = deduped.join(', ');
        }
        try {
            const res = await fetch(`${rootUrl}/signups/send-chaser`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({
                    target_audience: draft.audience,
                    chaser_type: draft.chaserType,
                    unit_name: draft.unitName,
                    to_override: effectiveTo,
                    cc_email: draft.cc,
                    subject_override: draft.subject,
                    body_override: draft.message,
                    anomalies: draft.anomalies,
                    included_signups: draft.includedSignups,
                    target_signup_id: draft.targetSignupId,
                    target_signup_type: draft.targetSignupType,
                    target_scout_id: draft.targetScoutId,
                    explorer_name: draft.explorerName,
                }),
            });
            const data = await res.json();
            if (!res.ok) {
                throw new Error(data?.message || 'Failed to send email.');
            }
            if (data?.history && typeof data.history === 'object') {
                setHistory(data.history);
            }
            setNotice({ type: 'success', text: `Chaser email sent to ${effectiveTo}.` });
            setDraft(null);
            onChaserSent?.();
        } catch (err: any) {
            setNotice({ type: 'error', text: err?.message || 'Failed to send chaser email.' });
        } finally {
            setSending(false);
        }
    };

    const totalExplorers = unitGroups.reduce((acc, g) => acc + g.explorers.length, 0);

    return (
        <div className="ems-chaser-view">
            <div className="ems-panel ems-p-16 ems-mb-16">
                <div className="ems-flex-between ems-wrap ems-gap-12 ems-mb-12">
                    <div>
                        <h3 className="ems-m-0">Anomalies &amp; Chasers ({totalExplorers} Explorers)</h3>
                        <p className="ems-meta-text ems-m-0">
                            Filter by anomaly type and chase either Unit Leaders (batched by Unit) or Individuals / Parents.
                        </p>
                    </div>
                    <a href={fluentSmtpUrl} target="_blank" rel="noreferrer" className="button button-secondary">
                        <FontAwesomeIcon icon={faExternalLinkAlt} /> FluentSMTP Logs
                    </a>
                </div>

                <div className="ems-flex-center ems-wrap ems-gap-8">
                    <select
                        aria-label="Filter anomalies by award level"
                        className="ems-select-sm"
                        value={levelFilter}
                        onChange={(e) => setLevelFilter(e.target.value as any)}
                    >
                        <option value="all">All Levels</option>
                        <option value="bronze">Bronze</option>
                        <option value="silver">Silver</option>
                        <option value="gold">Gold</option>
                    </select>
                    {ANOMALY_META.map((a) => {
                        const checked = selectedAnomalies.includes(a.key);
                        return (
                            <label
                                key={a.key}
                                className={`ems-filter-pill ${checked ? 'ems-filter-pill--active' : ''}`}
                            >
                                <input
                                    type="checkbox"
                                    checked={checked}
                                    onChange={() => toggleAnomaly(a.key)}
                                />
                                <span>{a.label}</span>
                            </label>
                        );
                    })}
                </div>
            </div>

            {notice && (
                <div className={`notice ${notice.type === 'success' ? 'notice-success' : 'notice-error'} ems-mb-16`}>
                    <p>{notice.text}</p>
                </div>
            )}

            {unitGroups.length === 0 ? (
                <div className="ems-panel ems-p-20 ems-meta-text">
                    No active Explorers match the selected anomalies.
                </div>
            ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                    {unitGroups.map((group) => {
                        const selectedCount = group.explorers.filter((e) => isExplorerSelected(e.key)).length;
                        const allSelected = selectedCount > 0 && selectedCount === group.explorers.length;
                        return (
                        <div key={group.unitName} className="ems-panel ems-p-16">
                            <div className="ems-flex-between ems-wrap ems-gap-12 ems-mb-12">
                                <div>
                                    <strong>{group.unitName}</strong>{' '}
                                    <span className="ems-meta-text">
                                        ({group.district}) &middot; Leader: {group.leaderEmail || 'No email'}
                                    </span>
                                    {history[group.unitName] && (
                                        <span className="ems-meta-text" style={{ marginLeft: '10px' }}>
                                            <FontAwesomeIcon icon={faClock} /> Last unit chase: {history[group.unitName]}
                                        </span>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    className="button button-primary"
                                    disabled={loadingPreview === group.unitName || selectedCount === 0}
                                    onClick={() => openUnitChaserModal(group)}
                                >
                                    <FontAwesomeIcon icon={faEnvelope} />{' '}
                                    {loadingPreview === group.unitName
                                        ? 'Loading…'
                                        : `Email Unit Leader (${selectedCount})`}
                                </button>
                            </div>

                            <table className="ems-table">
                                <thead>
                                    <tr>
                                        <th style={{ width: '32px' }}>
                                            <input
                                                type="checkbox"
                                                aria-label={`Select all explorers in ${group.unitName}`}
                                                checked={allSelected}
                                                onChange={(e) => toggleUnitAllExplorers(group, e.target.checked)}
                                            />
                                        </th>
                                        <th>Explorer</th>
                                        <th>Level</th>
                                        <th>Anomalies</th>
                                        <th>Contact</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {group.explorers.map((exp) => (
                                        <tr key={exp.key}>
                                            <td>
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Select ${exp.name}`}
                                                    checked={isExplorerSelected(exp.key)}
                                                    onChange={() => toggleExplorerSelection(exp.key)}
                                                />
                                            </td>
                                            <td>
                                                <button
                                                    type="button"
                                                    className="button-link"
                                                    onClick={() => onSelectExplorer?.(exp.primarySignup)}
                                                >
                                                    <strong>{exp.name}</strong>
                                                </button>
                                            </td>
                                            <td>{exp.level}</td>
                                            <td>
                                                <div className="ems-flex-center ems-wrap ems-gap-4">
                                                    {exp.anomalies.map((flag) => (
                                                        <span key={flag} className="ems-status-badge ems-status-badge--warning">
                                                            <FontAwesomeIcon icon={faTriangleExclamation} />{' '}
                                                            {ANOMALY_META.find((m) => m.key === flag)?.label || flag}
                                                        </span>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="ems-small-text">
                                                {exp.email || exp.parentEmail || 'No email'}
                                            </td>
                                            <td>
                                                <button
                                                    type="button"
                                                    className="button button-small"
                                                    disabled={!exp.email && !exp.parentEmail}
                                                    onClick={() => openIndividualChaserModal(group, exp)}
                                                >
                                                    <FontAwesomeIcon icon={faUser} /> Email Individual
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        );
                    })}
                </div>
            )}

            {draft && (
                <div className="ems-modal-backdrop">
                    <div
                        className="ems-modal ems-chaser-modal"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Compose Chaser Email"
                    >
                        <h3 className="ems-m-0 ems-mb-12">
                            {draft.audience === 'unit_leader'
                                ? `Email Unit Leader — ${draft.unitName}`
                                : `Email Individual / Parent — ${draft.explorerName}`}
                        </h3>

                        {draft.audience === 'unit_leader' ? (
                            <div className="ems-mb-12">
                                <label className="ems-toolbar__label">To</label>
                                <input
                                    type="email"
                                    className="regular-text ems-w-full"
                                    value={draft.to}
                                    onChange={(e) => setDraft({ ...draft, to: e.target.value })}
                                />
                            </div>
                        ) : (
                            <div className="ems-mb-12">
                                <div className="ems-mb-12">
                                    <label className="ems-toolbar__label">Recipients</label>
                                    <div style={{ display: 'flex', flexDirection: 'column', gap: '6px', marginTop: '4px' }}>
                                        {(draft.recipientOptions || []).map((opt) => {
                                            const checked = (draft.selectedEmails || []).some(
                                                (e) => e.toLowerCase() === opt.email.toLowerCase()
                                            );
                                            return (
                                                <label
                                                    key={opt.email}
                                                    style={{ display: 'flex', alignItems: 'center', gap: '8px', cursor: 'pointer', fontSize: '13px' }}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={(e) => {
                                                            const prev = draft.selectedEmails || [];
                                                            const next = e.target.checked
                                                                ? [...prev, opt.email]
                                                                : prev.filter((x) => x.toLowerCase() !== opt.email.toLowerCase());
                                                            setDraft({ ...draft, selectedEmails: next });
                                                        }}
                                                    />
                                                    <span>
                                                        <strong>{opt.email}</strong>{' '}
                                                        <span className="ems-meta-text">({opt.label})</span>
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </div>

                                <div className="ems-chaser-modal__recipients">
                                    <div>
                                        <label className="ems-toolbar__label">Additional Emails (comma-separated)</label>
                                        <input
                                            type="text"
                                            className="regular-text ems-w-full"
                                            placeholder="extra1@example.com, extra2@example.com"
                                            value={draft.manualEmails || ''}
                                            onChange={(e) => setDraft({ ...draft, manualEmails: e.target.value })}
                                        />
                                    </div>
                                    <div>
                                        <label className="ems-toolbar__label">CC (Unit Leader)</label>
                                        <input
                                            type="text"
                                            className="regular-text ems-w-full"
                                            value={draft.cc}
                                            onChange={(e) => setDraft({ ...draft, cc: e.target.value })}
                                        />
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="ems-mb-12">
                            <label className="ems-toolbar__label">Subject</label>
                            <input
                                type="text"
                                className="regular-text ems-w-full"
                                value={draft.subject}
                                onChange={(e) => setDraft({ ...draft, subject: e.target.value })}
                            />
                        </div>

                        <div className="ems-chaser-modal__body ems-mb-16">
                            <label className="ems-toolbar__label">Message</label>
                            <RichTextEditor
                                value={draft.message}
                                onChange={(html) => setDraft({ ...draft, message: html })}
                                shortcodes={draft.shortcodes}
                                minHeight="240px"
                            />
                        </div>

                        <div className="ems-chaser-modal__footer">
                            <button
                                type="button"
                                className="button button-secondary"
                                disabled={sending}
                                onClick={() => setDraft(null)}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="button button-primary"
                                disabled={sending || !draft.to}
                                onClick={handleSend}
                            >
                                {sending ? 'Sending…' : 'Send Email'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default UnitChaserView;
