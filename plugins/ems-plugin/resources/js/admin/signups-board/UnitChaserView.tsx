import React, { useState, useEffect, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faEnvelope,
    faExternalLinkAlt,
    faTriangleExclamation,
    faClock,
} from '@fortawesome/free-solid-svg-icons';
import {
    groupSignupsByExplorerIdentity,
    isSignupSyncedWithOsm,
} from './dashboardMetrics';
import { getUnitDistrictMap, normalize } from './tableUtils';
import { RichTextEditor } from '../expedition-board/RichTextEditor';

export type ChaserType = 'osm_share' | 'unpaired_signups';

interface UnitChaserViewProps {
    chaserType: ChaserType;
    signups: any[];
    units: any[];
    onSelectExplorer?: (signup: any) => void;
}

interface ChaserExplorerItem {
    key: string;
    name: string;
    firstName: string;
    level: string;
    stateLabel: string;
    scoutId: number;
    email: string;
    primarySignup: any;
}

interface UnitChaserGroup {
    unitName: string;
    district: string;
    leaderEmail: string;
    explorers: ChaserExplorerItem[];
}

interface EmailDraft {
    unitName: string;
    to: string;
    subject: string;
    message: string;
}

export const UnitChaserView: React.FC<UnitChaserViewProps> = ({
    chaserType,
    signups,
    units,
    onSelectExplorer,
}) => {
    const [history, setHistory] = useState<Record<string, string>>({});
    const [fluentSmtpUrl, setFluentSmtpUrl] = useState<string>('admin.php?page=fluent-mail#/logs');
    const [selectedLevels, setSelectedLevels] = useState<string[]>(['bronze', 'silver', 'gold']);
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
        fetch(`${rootUrl}/signups/chaser-history?type=${encodeURIComponent(chaserType)}`, {
            headers: { 'X-WP-Nonce': nonce },
        })
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => {
                if (!active || !data) return;
                if (data.history && typeof data.history === 'object') {
                    setHistory(data.history);
                }
                if (data.fluent_smtp_url) {
                    setFluentSmtpUrl(data.fluent_smtp_url);
                }
            })
            .catch(() => {
                // Non-fatal: history is informational
            });
        return () => {
            active = false;
        };
    }, [rootUrl, nonce, chaserType]);

    const unitLookup = useMemo(() => {
        const map: Record<string, any> = {};
        (units || []).forEach((u: any) => {
            if (u?.name) map[normalize(u.name)] = u;
            if (u?.short_code) map[normalize(u.short_code)] = u;
        });
        return map;
    }, [units]);

    const unitDistrictMap = useMemo(() => getUnitDistrictMap(units), [units]);

    const unitGroups = useMemo<UnitChaserGroup[]>(() => {
        const activeSignups = (signups || []).filter((s: any) => s.signup_status !== 'archived');
        const buckets = groupSignupsByExplorerIdentity(activeSignups);
        const byUnit: Record<string, UnitChaserGroup> = {};
        const levels = ['bronze', 'silver', 'gold'] as const;

        buckets.forEach((bucket, idx) => {
            const primary = bucket.items[0];
            if (!primary) return;

            const rawUnit = (
                bucket.items.find((i: any) => i.unit_name && i.unit_name !== 'Unassigned')?.unit_name ||
                primary.unit_name ||
                'Unassigned'
            ).trim();
            const unitMeta = unitLookup[normalize(rawUnit)];
            const district =
                (primary.district || unitMeta?.district || unitDistrictMap[rawUnit] || 'Unassigned').trim();
            const leaderEmail = (unitMeta?.leader_email || primary.leader_email || '').trim();

            const ensureGroup = (): UnitChaserGroup => {
                if (!byUnit[rawUnit]) {
                    byUnit[rawUnit] = {
                        unitName: rawUnit,
                        district,
                        leaderEmail,
                        explorers: [],
                    };
                }
                return byUnit[rawUnit];
            };

            const fullName = `${primary.explorer_first_name || ''} ${primary.explorer_last_name || ''}`.trim() || 'Unknown';
            const email =
                bucket.items.find((i: any) => i.explorer_email)?.explorer_email ||
                bucket.items.find((i: any) => i.parent_email)?.parent_email ||
                '';

            if (chaserType === 'osm_share') {
                const hasUnsynced = bucket.items.some((i: any) => !isSignupSyncedWithOsm(i));
                if (!hasUnsynced) return;

                const grp = ensureGroup();
                grp.explorers.push({
                    key: `osm_${bucket.scoutId || idx}_${rawUnit}`,
                    name: fullName,
                    firstName: (primary.explorer_first_name || '').trim(),
                    level: (primary.dofe_level || '').trim().toLowerCase(),
                    stateLabel: Number(bucket.scoutId || 0) !== 0 ? `Scout ID ${bucket.scoutId} (Not Synced)` : 'No OSM Record',
                    scoutId: Number(bucket.scoutId || 0),
                    email,
                    primarySignup: primary,
                });
            } else {
                levels.forEach((lvl) => {
                    const pItems = bucket.items.filter(
                        (i: any) => i.type === 'participant' && normalize(i.dofe_level) === lvl
                    );
                    const eItems = bucket.items.filter(
                        (i: any) => i.type === 'expedition' && normalize(i.dofe_level) === lvl
                    );

                    if (pItems.length > 0 && eItems.length === 0) {
                        const grp = ensureGroup();
                        grp.explorers.push({
                            key: `unp_p_${bucket.scoutId || idx}_${lvl}`,
                            name: fullName,
                            firstName: (primary.explorer_first_name || '').trim(),
                            level: lvl,
                            stateLabel: 'Place Only (No Exped)',
                            scoutId: Number(bucket.scoutId || 0),
                            email,
                            primarySignup: pItems[0],
                        });
                    } else if (eItems.length > 0 && pItems.length === 0) {
                        const grp = ensureGroup();
                        grp.explorers.push({
                            key: `unp_e_${bucket.scoutId || idx}_${lvl}`,
                            name: fullName,
                            firstName: (primary.explorer_first_name || '').trim(),
                            level: lvl,
                            stateLabel: 'Exped Only (No Place)',
                            scoutId: Number(bucket.scoutId || 0),
                            email,
                            primarySignup: eItems[0],
                        });
                    }
                });
            }
        });

        return Object.values(byUnit)
            .map((grp) => ({
                ...grp,
                explorers: [...grp.explorers]
                    .filter((e) => !e.level || selectedLevels.includes(e.level))
                    .sort((a, b) => a.firstName.localeCompare(b.firstName)),
            }))
            .filter((grp) => grp.explorers.length > 0)
            .sort((a, b) => {
                if (a.unitName === 'Unassigned') return 1;
                if (b.unitName === 'Unassigned') return -1;
                return a.unitName.localeCompare(b.unitName);
            });
    }, [signups, units, unitLookup, unitDistrictMap, chaserType, selectedLevels]);

    const toggleLevel = (lvl: string) => {
        setSelectedLevels((prev) =>
            prev.includes(lvl) ? prev.filter((item) => item !== lvl) : [...prev, lvl]
        );
    };

    const handleOpenPreview = async (group: UnitChaserGroup) => {
        setNotice(null);
        setLoadingPreview(group.unitName);
        try {
            const res = await fetch(`${rootUrl}/signups/send-chaser`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({
                    chaser_type: chaserType,
                    unit_name: group.unitName,
                    levels: selectedLevels,
                    preview: true,
                }),
            });
            const data = await res.json();
            if (!res.ok || data?.code) {
                throw new Error(data?.message || 'Failed to load email preview.');
            }
            setDraft({
                unitName: group.unitName,
                to: data.to || group.leaderEmail || '',
                subject: data.subject || '',
                message: data.message || '',
            });
        } catch (err: any) {
            setNotice({ type: 'error', text: err?.message || 'Failed to load email preview.' });
        } finally {
            setLoadingPreview(null);
        }
    };

    const handleSendEmail = async (e: React.FormEvent) => {
        e.preventDefault();
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
                    chaser_type: chaserType,
                    unit_name: draft.unitName,
                    levels: selectedLevels,
                    preview: false,
                    to_override: draft.to,
                    subject_override: draft.subject,
                    body_override: draft.message,
                }),
            });
            const data = await res.json();
            if (!res.ok || data?.code) {
                throw new Error(data?.message || 'Failed to send email.');
            }
            if (data.history) {
                setHistory(data.history);
            } else if (data.sent_at) {
                setHistory((prev) => ({ ...prev, [draft.unitName]: data.sent_at }));
            }
            setNotice({
                type: 'success',
                text: `Email sent to ${draft.to} for ${draft.unitName}.`,
            });
            setDraft(null);
        } catch (err: any) {
            setNotice({ type: 'error', text: err?.message || 'Failed to send email.' });
        } finally {
            setSending(false);
        }
    };

    const heading =
        chaserType === 'osm_share'
            ? 'Explorers Without Synced OSM Records'
            : 'Explorers With Unpaired Signups (Place vs Expedition)';

    const description =
        chaserType === 'osm_share'
            ? 'Grouped by unit so you can email unit leaders to share these explorers in OSM.'
            : 'Grouped by unit so you can chase unit leaders for missing Participant Place or Expedition forms.';

    return (
        <div className="ems-unit-chaser-view" data-testid={`chaser-view-${chaserType}`}>
            <div className="ems-overview-card ems-mb-16">
                <div className="ems-flex-between ems-wrap ems-gap-12">
                    <div>
                        <h3 className="ems-m-0 ems-font-semibold">{heading}</h3>
                        <div className="ems-meta-text ems-mt-4">{description}</div>
                    </div>
                    <div className="ems-flex-center ems-gap-12 ems-wrap">
                        <div className="ems-flex-center ems-gap-8" role="group" aria-label="Filter chaser levels">
                            <span className="ems-small-text ems-font-semibold">Levels:</span>
                            {(['bronze', 'silver', 'gold'] as const).map((lvl) => (
                                <label key={lvl} className="ems-flex-center ems-gap-4 ems-small-text ems-cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={selectedLevels.includes(lvl)}
                                        onChange={() => toggleLevel(lvl)}
                                        data-testid={`chaser-level-filter-${lvl}`}
                                    />
                                    {lvl.charAt(0).toUpperCase() + lvl.slice(1)}
                                </label>
                            ))}
                        </div>
                        <a
                            href={fluentSmtpUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="button button-secondary"
                            data-testid="fluent-smtp-logs-link"
                        >
                            <FontAwesomeIcon icon={faExternalLinkAlt} className="ems-btn-icon" />
                            View Sent Emails (FluentSMTP)
                        </a>
                    </div>
                </div>
            </div>

            {notice && (
                <div
                    className={`notice ${notice.type === 'success' ? 'notice-success' : 'notice-error'} ems-mb-16`}
                    role="alert"
                >
                    <p>{notice.text}</p>
                </div>
            )}

            {unitGroups.length === 0 ? (
                <div className="ems-overview-card">
                    <p className="ems-meta-text ems-m-0">
                        No explorers currently require follow-up in this view.
                    </p>
                </div>
            ) : (
                unitGroups.map((group) => {
                    const lastRequested = history[group.unitName];
                    return (
                        <div
                            key={group.unitName}
                            className="ems-overview-card ems-mb-16"
                            data-testid={`chaser-unit-card-${group.unitName}`}
                        >
                            <div className="ems-flex-between ems-wrap ems-gap-12 ems-mb-12">
                                <div>
                                    <div className="ems-flex-center ems-gap-8 ems-wrap">
                                        <strong className="ems-font-semibold">{group.unitName}</strong>
                                        <span className="ems-badge">{group.district}</span>
                                        <span className="ems-badge ems-badge--warning">
                                            {group.explorers.length} explorer{group.explorers.length === 1 ? '' : 's'}
                                        </span>
                                        {lastRequested && (
                                            <span
                                                className="ems-meta-text ems-small-text"
                                                data-testid={`last-requested-${group.unitName}`}
                                            >
                                                <FontAwesomeIcon icon={faClock} /> Last requested: {lastRequested}
                                            </span>
                                        )}
                                    </div>
                                    <div className="ems-meta-text ems-small-text ems-mt-4">
                                        {group.leaderEmail ? (
                                            <span>Leader Email: {group.leaderEmail}</span>
                                        ) : (
                                            <span className="ems-fa-warning">
                                                <FontAwesomeIcon icon={faTriangleExclamation} /> No leader email configured
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    className="button button-primary"
                                    disabled={loadingPreview === group.unitName}
                                    onClick={() => handleOpenPreview(group)}
                                    data-testid={`preview-email-btn-${group.unitName}`}
                                >
                                    <FontAwesomeIcon icon={faEnvelope} className="ems-btn-icon" />
                                    {loadingPreview === group.unitName ? 'Loading…' : 'Preview & Edit Email'}
                                </button>
                            </div>

                            <table className="widefat striped">
                                <thead>
                                    <tr>
                                        <th>Explorer</th>
                                        <th>DofE Level</th>
                                        <th>Status</th>
                                        <th>Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {group.explorers.map((exp) => (
                                        <tr
                                            key={exp.key}
                                            className={onSelectExplorer ? 'ems-row-hoverable ems-cursor-pointer' : ''}
                                            onClick={() => onSelectExplorer?.(exp.primarySignup)}
                                        >
                                            <td><strong>{exp.name}</strong></td>
                                            <td>
                                                <span className={`ems-pill ems-pill--${exp.level}`}>
                                                    {exp.level || '—'}
                                                </span>
                                            </td>
                                            <td>{exp.stateLabel}</td>
                                            <td>{exp.email || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    );
                })
            )}

            {draft && (
                <div className="ems-modal-backdrop" role="dialog" aria-modal="true" aria-label="Edit Leader Email">
                    <div className="ems-modal-card ems-overview-card">
                        <h3 className="ems-m-0 ems-mb-12">
                            Edit Email for {draft.unitName}
                        </h3>
                        <form onSubmit={handleSendEmail}>
                            <div className="ems-mb-12">
                                <label htmlFor="chaser-email-to" className="ems-font-semibold">
                                    To
                                </label>
                                <input
                                    id="chaser-email-to"
                                    type="email"
                                    className="regular-text ems-w-full"
                                    required
                                    value={draft.to}
                                    onChange={(e) => setDraft({ ...draft, to: e.target.value })}
                                />
                            </div>
                            <div className="ems-mb-12">
                                <label htmlFor="chaser-email-subject" className="ems-font-semibold">
                                    Subject
                                </label>
                                <input
                                    id="chaser-email-subject"
                                    type="text"
                                    className="large-text ems-w-full"
                                    required
                                    value={draft.subject}
                                    onChange={(e) => setDraft({ ...draft, subject: e.target.value })}
                                />
                            </div>
                            <div className="ems-mb-12">
                                <label className="ems-font-semibold">
                                    HTML Body
                                </label>
                                <RichTextEditor
                                    value={draft.message}
                                    onChange={(val) => setDraft({ ...draft, message: val })}
                                    minHeight="220px"
                                    ariaLabel="HTML Body"
                                />
                            </div>
                            <div className="ems-mb-12">
                                <div className="ems-font-semibold ems-mb-4">Rendered Preview</div>
                                <div
                                    className="ems-email-preview-box"
                                    data-testid="chaser-email-html-preview"
                                    dangerouslySetInnerHTML={{ __html: draft.message }}
                                />
                            </div>
                            <div className="ems-flex-end ems-gap-8">
                                <button
                                    type="button"
                                    className="button button-secondary"
                                    onClick={() => setDraft(null)}
                                    disabled={sending}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="button button-primary"
                                    disabled={sending}
                                    data-testid="chaser-send-email-submit"
                                >
                                    {sending ? 'Sending…' : 'Send Email'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};

export default UnitChaserView;
