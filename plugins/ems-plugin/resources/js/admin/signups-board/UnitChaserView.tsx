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
    groupSignupsByExplorerIdentity,
    isSignupSyncedWithOsm,
} from './dashboardMetrics';
import { getUnitDistrictMap, normalize } from './tableUtils';
import { RichTextEditor, ShortcodeOption } from '../expedition-board/RichTextEditor';

export type ChaserType = 'osm_share' | 'unpaired_signups' | 'missing_edofe';

export const ANOMALY_META: { key: string; label: string }[] = [
    { key: 'unmatched_osm', label: 'Unmatched OSM' },
    { key: 'pending_osm_sync', label: 'Pending OSM Sync' },
    { key: 'expedition_without_place', label: 'Expedition Without Place' },
    { key: 'place_without_expedition', label: 'Place Without Expedition' },
    { key: 'missing_edofe', label: 'Missing eDofE' },
    { key: 'level_mismatch', label: 'Level Mismatch' },
    { key: 'duplicate_signup', label: 'Duplicate' },
    { key: 'unvalidated_asn', label: 'Unvalidated ASN' },
];

interface UnitChaserViewProps {
    chaserType?: ChaserType;
    signups: any[];
    units: any[];
    onSelectExplorer?: (signup: any) => void;
    onChaserSent?: () => void;
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
    ccEmails: string[];
    primarySignup: any;
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
    onSelectExplorer,
    onChaserSent,
}) => {
    const [selectedAnomalies, setSelectedAnomalies] = useState<string[]>(
        ANOMALY_META.map((a) => a.key)
    );
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
        fetch(`${rootUrl}/signups/chaser-history?type=osm_share`, {
            headers: { 'X-WP-Nonce': nonce },
        })
            .then((res) => (res.ok ? res.json() : null))
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

    const unitGroups = useMemo<UnitChaserGroup[]>(() => {
        const activeSignups = (signups || []).filter((s: any) => s.signup_status !== 'archived');
        const buckets = groupSignupsByExplorerIdentity(activeSignups);
        const byUnit: Record<string, UnitChaserGroup> = {};

        buckets.forEach((bucket, idx) => {
            const primary = bucket.items[0];
            if (!primary) return;

            const explorerAnomalies = new Set<string>();
            bucket.items.forEach((item: any) => {
                if (Array.isArray(item.anomalies) && item.anomalies.length > 0) {
                    item.anomalies.forEach((a: string) => explorerAnomalies.add(a));
                } else {
                    if (item.type === 'expedition' && !isSignupSyncedWithOsm(item)) {
                        explorerAnomalies.add(Number(item.scout_id || 0) === 0 ? 'unmatched_osm' : 'pending_osm_sync');
                    }
                    if (!item.dofe_number) {
                        explorerAnomalies.add('missing_edofe');
                    }
                }
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
            const formEmail = (bucket.items.find((i: any) => i.explorer_email)?.explorer_email || '').trim();
            const explorerEmail = osmEmail1 || formEmail;

            const osmP1Email1 = (bucket.items.find((i: any) => i.osm_p1_email1)?.osm_p1_email1 || '').trim();
            const formParentEmail = (bucket.items.find((i: any) => i.parent_email)?.parent_email || '').trim();
            const parentEmail = osmP1Email1 || formParentEmail;

            const ccCandidates = [
                bucket.items.find((i: any) => i.osm_email2)?.osm_email2,
                osmP1Email1,
                bucket.items.find((i: any) => i.osm_p1_email2)?.osm_p1_email2,
                bucket.items.find((i: any) => i.osm_p2_email1)?.osm_p2_email1,
                bucket.items.find((i: any) => i.osm_p2_email2)?.osm_p2_email2,
                formParentEmail,
            ]
                .map((e: any) => String(e || '').trim())
                .filter(Boolean);

            const seenCc = new Set<string>();
            const ccEmails: string[] = [];
            for (const c of ccCandidates) {
                const lower = c.toLowerCase();
                if (!seenCc.has(lower)) {
                    seenCc.add(lower);
                    ccEmails.push(c);
                }
            }

            byUnit[rawUnit].explorers.push({
                key: `${rawUnit}_${idx}`,
                name: fullName,
                firstName: primary.explorer_first_name || '',
                level: (primary.dofe_level || '').toUpperCase(),
                anomalies: matching,
                scoutId: bucket.scoutId,
                email: explorerEmail,
                parentEmail,
                ccEmails,
                primarySignup: primary,
            });
        });

        return Object.values(byUnit).sort((a, b) => a.unitName.localeCompare(b.unitName));
    }, [signups, selectedAnomalies, unitLookup, unitDistrictMap]);

    const openUnitChaserModal = async (group: UnitChaserGroup) => {
        setNotice(null);
        setLoadingPreview(group.unitName);

        const hasOsm = group.explorers.some((e) =>
            e.anomalies.some((a) => a === 'unmatched_osm' || a === 'pending_osm_sync')
        );
        const inferredType: ChaserType = hasOsm ? 'osm_share' : 'unpaired_signups';

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
                    preview: true,
                }),
            });
            const data = await res.json();
            const listHtml = `<ul>${group.explorers
                .map(
                    (e) =>
                        `<li><strong>${escapeHtml(e.name)}</strong> (${escapeHtml(e.level)}) — ${e.anomalies
                            .map((a) => ANOMALY_META.find((m) => m.key === a)?.label || a)
                            .join(', ')}</li>`
                )
                .join('')}</ul>`;

            setDraft({
                audience: 'unit_leader',
                chaserType: inferredType,
                unitName: group.unitName,
                to: data?.to || group.leaderEmail || '',
                cc: '',
                subject: data?.subject || `DofE Sign-up Anomalies for ${group.unitName}`,
                message:
                    (data?.message ? data.message : '<p>Hello,</p><p>Please review the following Explorers:</p>') +
                    `<p><strong>Selected Anomalies:</strong></p>${listHtml}`,
                anomalies: Array.from(new Set(group.explorers.flatMap((e) => e.anomalies))),
                includedSignups: group.explorers.map((e) => ({
                    id: Number(e.primarySignup.id),
                    type: String(e.primarySignup.type),
                    scout_id: Number(e.scoutId || 0),
                })),
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
        const to = exp.email || exp.parentEmail || exp.ccEmails[0] || '';
        const ccList = (exp.ccEmails || []).filter((c) => c.toLowerCase() !== to.toLowerCase());
        const cc = ccList.join(', ');

        setDraft({
            audience: 'individual',
            chaserType: 'unpaired_signups',
            unitName: group.unitName,
            to,
            cc,
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
                    to_override: draft.to,
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
            setNotice({ type: 'success', text: `Chaser email sent to ${draft.to}.` });
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
                    {unitGroups.map((group) => (
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
                                    disabled={loadingPreview === group.unitName}
                                    onClick={() => openUnitChaserModal(group)}
                                >
                                    <FontAwesomeIcon icon={faEnvelope} />{' '}
                                    {loadingPreview === group.unitName ? 'Loading…' : 'Email Unit Leader'}
                                </button>
                            </div>

                            <table className="ems-table">
                                <thead>
                                    <tr>
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
                    ))}
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

                        <div className={draft.audience === 'individual' ? 'ems-chaser-modal__recipients' : 'ems-mb-12'}>
                            <div>
                                <label className="ems-toolbar__label">To</label>
                                <input
                                    type="email"
                                    className="regular-text ems-w-full"
                                    value={draft.to}
                                    onChange={(e) => setDraft({ ...draft, to: e.target.value })}
                                />
                            </div>

                            {draft.audience === 'individual' && (
                                <div>
                                    <label className="ems-toolbar__label">CC (Parent Email)</label>
                                    <input
                                        type="email"
                                        className="regular-text ems-w-full"
                                        value={draft.cc}
                                        onChange={(e) => setDraft({ ...draft, cc: e.target.value })}
                                    />
                                </div>
                            )}
                        </div>

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
