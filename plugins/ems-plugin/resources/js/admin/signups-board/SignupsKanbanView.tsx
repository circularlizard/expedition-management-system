import React, { useMemo, useState } from 'react';

export type KanbanStage = 'to_process' | 'on_hold' | 'returned' | 'processed' | 'cancelled';

const KANBAN_COLUMNS: { key: KanbanStage; label: string; color: string }[] = [
    { key: 'to_process', label: 'To Process', color: '#2271b1' },
    { key: 'on_hold', label: 'On Hold', color: '#dba617' },
    { key: 'returned', label: 'Returned', color: '#d63638' },
    { key: 'processed', label: 'Processed', color: '#00a32a' },
    { key: 'cancelled', label: 'Cancelled', color: '#646970' },
];

const ANOMALY_LABELS: Record<string, string> = {
    place_without_expedition: 'No Form 7',
    expedition_without_place: 'No Form 6',
    level_mismatch: 'Level Mismatch',
    duplicate_signup: 'Duplicate',
    unmatched_osm: 'Unmatched OSM',
    pending_osm_sync: 'Pending OSM Sync',
    missing_edofe: 'Missing eDofE',
    unvalidated_asn: 'Unvalidated ASN',
};

interface SignupsKanbanViewProps {
    signups: any[];
    rootUrl: string;
    nonce: string;
    onSelectSignup: (signup: any) => void;
    onUpdated: () => void;
}

export default function SignupsKanbanView({
    signups,
    rootUrl,
    nonce,
    onSelectSignup,
    onUpdated,
}: SignupsKanbanViewProps) {
    const [savingKey, setSavingKey] = useState<string | null>(null);
    const [notesDraft, setNotesDraft] = useState<Record<string, string>>({});
    const [anomalyOnly, setAnomalyOnly] = useState<boolean>(false);

    const activeSignups = useMemo(() => {
        return (signups || []).filter((s) => {
            if (!s || s.signup_status === 'archived') return false;
            if (anomalyOnly && (!Array.isArray(s.anomalies) || s.anomalies.length === 0)) {
                return false;
            }
            return true;
        });
    }, [signups, anomalyOnly]);

    const grouped = useMemo(() => {
        const map: Record<KanbanStage, any[]> = {
            to_process: [],
            on_hold: [],
            returned: [],
            processed: [],
            cancelled: [],
        };
        activeSignups.forEach((s) => {
            const stage = (s.kanban_stage || 'to_process') as KanbanStage;
            if (map[stage]) {
                map[stage].push(s);
            } else {
                map.to_process.push(s);
            }
        });
        return map;
    }, [activeSignups]);

    const updateStage = async (item: any, nextStage: KanbanStage, notes?: string) => {
        const key = `${item.type}_${item.id}`;
        setSavingKey(key);
        try {
            const res = await fetch(`${rootUrl}/signups/${item.type}/${item.id}/kanban`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({
                    kanban_stage: nextStage,
                    ...(notes !== undefined ? { processing_notes: notes } : {}),
                }),
            });
            if (res.ok) {
                onUpdated();
            }
        } finally {
            setSavingKey(null);
        }
    };

    return (
        <div className="ems-kanban-wrap">
            <div className="ems-signups-toolbar ems-mb-16">
                <label className="ems-flex-center ems-gap-8">
                    <input
                        type="checkbox"
                        checked={anomalyOnly}
                        onChange={(e) => setAnomalyOnly(e.target.checked)}
                    />
                    <span>Show Anomalies Only</span>
                </label>
                <span className="ems-meta-text">
                    Showing {activeSignups.length} active sign-up records across 5 Kanban stages (independent of archive status).
                </span>
            </div>

            <div
                className="ems-kanban-grid"
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(5, minmax(220px, 1fr))',
                    gap: '12px',
                    alignItems: 'start',
                    overflowX: 'auto',
                }}
            >
                {KANBAN_COLUMNS.map((col) => {
                    const cards = grouped[col.key];
                    return (
                        <div
                            key={col.key}
                            className="ems-panel"
                            style={{ padding: '10px', borderTop: `3px solid ${col.color}` }}
                        >
                            <div
                                className="ems-flex-between ems-mb-12"
                                style={{ fontWeight: 600, fontSize: '13px' }}
                            >
                                <span>{col.label}</span>
                                <span className="ems-badge">{cards.length}</span>
                            </div>

                            <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                                {cards.length === 0 ? (
                                    <div className="ems-meta-text" style={{ fontSize: '12px', fontStyle: 'italic' }}>
                                        No sign-ups
                                    </div>
                                ) : (
                                    cards.map((item) => {
                                        const cardKey = `${item.type}_${item.id}`;
                                        const isSaving = savingKey === cardKey;
                                        const anomalies: string[] = Array.isArray(item.anomalies) ? item.anomalies : [];
                                        const currentNotes =
                                            notesDraft[cardKey] !== undefined
                                                ? notesDraft[cardKey]
                                                : item.processing_notes || '';

                                        return (
                                            <div
                                                key={cardKey}
                                                className="ems-card"
                                                style={{
                                                    padding: '10px',
                                                    border: '1px solid #dcdcde',
                                                    borderRadius: '4px',
                                                    background: '#fff',
                                                }}
                                            >
                                                <div className="ems-flex-between" style={{ gap: '6px', marginBottom: '4px' }}>
                                                    <button
                                                        type="button"
                                                        className="button-link"
                                                        style={{ fontWeight: 600, textAlign: 'left', padding: 0 }}
                                                        onClick={() => onSelectSignup(item)}
                                                    >
                                                        {item.explorer_first_name} {item.explorer_last_name}
                                                    </button>
                                                    <span className="ems-badge" style={{ fontSize: '11px' }}>
                                                        {item.type === 'participant' ? 'Form 6' : 'Form 7'}
                                                    </span>
                                                </div>

                                                <div className="ems-meta-text" style={{ fontSize: '12px', marginBottom: '6px' }}>
                                                    {(item.dofe_level || '').toUpperCase()} &middot; {item.unit_name || 'Unassigned'}
                                                </div>

                                                {anomalies.length > 0 && (
                                                    <div
                                                        style={{
                                                            display: 'flex',
                                                            flexWrap: 'wrap',
                                                            gap: '4px',
                                                            marginBottom: '6px',
                                                        }}
                                                    >
                                                        {anomalies.map((flag) => (
                                                            <span
                                                                key={flag}
                                                                style={{
                                                                    fontSize: '10px',
                                                                    padding: '2px 6px',
                                                                    borderRadius: '3px',
                                                                    background: '#fcf0f1',
                                                                    color: '#8a2424',
                                                                    border: '1px solid #f0c2c4',
                                                                }}
                                                            >
                                                                {ANOMALY_LABELS[flag] || flag}
                                                            </span>
                                                        ))}
                                                    </div>
                                                )}

                                                <div style={{ display: 'flex', gap: '4px', marginBottom: '4px' }}>
                                                    <select
                                                        value={col.key}
                                                        disabled={isSaving}
                                                        onChange={(e) =>
                                                            updateStage(item, e.target.value as KanbanStage)
                                                        }
                                                        style={{ width: '100%', fontSize: '12px' }}
                                                    >
                                                        {KANBAN_COLUMNS.map((opt) => (
                                                            <option key={opt.key} value={opt.key}>
                                                                Move to: {opt.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>

                                                <div style={{ display: 'flex', gap: '4px' }}>
                                                    <input
                                                        type="text"
                                                        placeholder="Triage note..."
                                                        value={currentNotes}
                                                        onChange={(e) =>
                                                            setNotesDraft((prev) => ({
                                                                ...prev,
                                                                [cardKey]: e.target.value,
                                                            }))
                                                        }
                                                        style={{ flex: 1, fontSize: '11px', minWidth: 0 }}
                                                    />
                                                    {notesDraft[cardKey] !== undefined &&
                                                        notesDraft[cardKey] !== (item.processing_notes || '') && (
                                                            <button
                                                                type="button"
                                                                className="button button-small"
                                                                disabled={isSaving}
                                                                onClick={() =>
                                                                    updateStage(item, col.key, notesDraft[cardKey])
                                                                }
                                                            >
                                                                Save
                                                            </button>
                                                        )}
                                                </div>
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
