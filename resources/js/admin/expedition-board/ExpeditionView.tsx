import React, { useState, useEffect } from 'react';
import { BoardData, Expedition, Team, Member, FirstAidLevel, OSMEvent } from './types';
import { EventForm } from './EventForm';

interface ExpeditionViewProps {
    data: BoardData;
    osmEvents?: OSMEvent[];
}

const FA_LABELS: Record<FirstAidLevel, string> = {
    none: 'None',
    first_response: 'First Response',
    full_first_aid: 'Full First Aid',
};

const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

function typePillClass(type: string): string {
    const map: Record<string, string> = {
        training: 'ems-pill--training',
        practice: 'ems-pill--practice',
        qualifying: 'ems-pill--qualifying',
    };
    return `ems-pill ${map[type] || 'ems-pill--training'}`;
}
function transportPillClass(t?: string): string {
    const map: Record<string, string> = {
        hillwalking: 'ems-pill--hillwalking',
        biking: 'ems-pill--biking',
        paddling: 'ems-pill--paddling',
    };
    return `ems-pill ${map[t || ''] || ''}`;
}
function levelPillClass(l: string): string {
    const map: Record<string, string> = {
        bronze: 'ems-pill--bronze',
        silver: 'ems-pill--silver',
        gold: 'ems-pill--gold',
    };
    return `ems-pill ${map[l] || ''}`;
}
function firstAidPillClass(l?: string): string {
    const map: Record<string, string> = {
        none: 'ems-pill--fa-none',
        first_response: 'ems-pill--fa-first-response',
        full_first_aid: 'ems-pill--fa-full-first-aid',
    };
    return `ems-pill ${map[l ?? 'none'] || 'ems-pill--fa-none'}`;
}

function sortedMembers(members: Member[]): Member[] {
    return [...members].sort((a, b) =>
        `${a.first_name} ${a.last_name}`.localeCompare(`${b.first_name} ${b.last_name}`)
    );
}

function FaIcon({ level }: { level?: FirstAidLevel }) {
    if (level === 'full_first_aid') {
        return <span title="Full First Aid" className="ems-fa-full">⊕</span>;
    }
    if (level === 'first_response') {
        return <span title="First Response" className="ems-fa-response">✚</span>;
    }
    return null;
}

const FaKey: React.FC = () => (
    <div className="ems-fa-legend">
        <span><span className="ems-fa-full">⊕</span> Full First Aid</span>
        <span><span className="ems-fa-response">✚</span> First Response</span>
    </div>
);

const gridClass = (cols: number): string => {
    if (cols >= 5) return 'ems-form-grid-4';
    if (cols === 4) return 'ems-form-grid-4';
    if (cols === 3) return 'ems-form-grid-3';
    return 'ems-form-grid-2';
};

const FieldVal: React.FC<{ label: string; value: React.ReactNode }> = ({ label, value }) => (
    <div className="ems-meta-field">
        <div className="ems-meta-field__label">{label}</div>
        <div className={value ? 'ems-meta-field__value' : 'ems-meta-field__value ems-meta-field__value--empty'}>{value || '—'}</div>
    </div>
);

const TeamRow: React.FC<{ team: Team }> = ({ team }) => {
    const members = sortedMembers(team.members ?? []);
    const size = team.member_count ?? members.length;
    const hasFa = members.some((m) => m.first_aid_level && m.first_aid_level !== 'none');
    const sizeWarn = size < 4 || size > 7;
    const faBadge = hasFa ? (
        <span className="ems-fa-badge ems-fa-badge--has">
            First Aid ✓
        </span>
    ) : (
        <span className="ems-fa-badge ems-fa-badge--none">
            No First Aid
        </span>
    );

    return (
        <tr>
            <td style={{ fontWeight: 600, verticalAlign: 'top' }}>{team.ems_team_code}</td>
            <td style={{ color: sizeWarn ? '#d63638' : '#1d2327', fontWeight: sizeWarn ? 600 : 400, verticalAlign: 'top' }}>
                {size}
                {sizeWarn && (
                    <span className="ems-team-size-warn">⚠</span>
                )}
            </td>
            <td style={{ verticalAlign: 'top' }}>{faBadge}</td>
            <td style={{ fontSize: '12px', verticalAlign: 'top' }}>
                {members.length === 0 ? (
                    <span style={{ color: '#aaa' }}>—</span>
                ) : (
                    <ul className="ems-member-list">
                        {members.map((m) => (
                            <li key={m.scout_id ?? m.user_id} className="ems-member-item">
                                <FaIcon level={m.first_aid_level} />
                                {m.first_name} {m.last_name}
                            </li>
                        ))}
                    </ul>
                )}
            </td>
        </tr>
    );
};

const TrainingRequirementsTab: React.FC<{ eventId: number }> = ({ eventId }) => {
    const config = window.emsExpeditionBoard;
    const [courses, setCourses] = useState<{ id: number; title: string }[]>([]);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

    useEffect(() => {
        let isMounted = true;
        setLoading(true);
        setMessage(null);
        fetch(`${config.root_url}/events/${eventId}/training-requirements`, {
            headers: {
                'X-WP-Nonce': config.nonce,
            }
        })
        .then((res) => {
            if (!res.ok) throw new Error('Failed to load training requirements');
            return res.json();
        })
        .then((data) => {
            if (isMounted) {
                setCourses(data.courses || []);
                setSelectedIds(data.course_ids || []);
                setLoading(false);
            }
        })
        .catch((err: any) => {
            if (isMounted) {
                setMessage({ type: 'error', text: err.message || 'Error loading courses' });
                setLoading(false);
            }
        });

        return () => {
            isMounted = false;
        };
    }, [eventId]);

    const handleCheckboxChange = (courseId: number, checked: boolean) => {
        setSelectedIds((prev) =>
            checked ? [...prev, courseId] : prev.filter((id) => id !== courseId)
        );
    };

    const handleSave = async () => {
        setSaving(true);
        setMessage(null);
        try {
            const res = await fetch(`${config.root_url}/events/${eventId}/training-requirements`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({ course_ids: selectedIds }),
            });

            if (!res.ok) throw new Error('Failed to save requirements');
            const data = await res.json();
            if (data.success) {
                setMessage({ type: 'success', text: 'Training requirements saved successfully.' });
            } else {
                throw new Error('Failed to save requirements');
            }
        } catch (err: any) {
            setMessage({ type: 'error', text: err.message || 'Error saving training requirements' });
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return <div className="ems-training-loading">Loading training requirements...</div>;
    }

    return (
        <div className="ems-mt-16">
            <h3 className="ems-section-header">
                Required Tutor LMS Courses
            </h3>
            <p className="ems-meta-text ems-mb-16">
                Select the courses that explorers must complete to be cleared for this expedition.
            </p>

            {message && (
                <div className={`notice notice-${message.type}`} style={{ margin: '0 0 16px 0', padding: '8px 12px', borderLeftWidth: '4px' }}>
                    <p style={{ margin: 0, fontSize: '13px' }}>{message.text}</p>
                </div>
            )}

            {courses.length === 0 ? (
                <p style={{ color: '#888', fontStyle: 'italic' }}>No Tutor LMS courses found.</p>
            ) : (
                <div className="ems-training-list">
                    {courses.map((course) => {
                        const isChecked = selectedIds.includes(course.id);
                        return (
                            <label key={course.id} className="ems-training-item">
                                <input
                                    type="checkbox"
                                    checked={isChecked}
                                    onChange={(e) => handleCheckboxChange(course.id, e.target.checked)}
                                    disabled={saving}
                                    style={{ margin: 0 }}
                                />
                                {course.title}
                            </label>
                        );
                    })}
                </div>
            )}

            <button
                type="button"
                className="button button-primary"
                onClick={handleSave}
                disabled={saving}
            >
                {saving ? 'Saving...' : 'Save Requirements'}
            </button>
        </div>
    );
};

const ExpeditionDetail: React.FC<{
    expedition: Expedition;
    osmEvents: OSMEvent[];
    onSaved: (updated: Expedition) => void;
}> = ({ expedition: e, osmEvents, onSaved }) => {
    const [editing, setEditing] = useState(false);
    const [activeSubTab, setActiveSubTab] = useState<'overview' | 'teams' | 'training'>('overview');
    const totalMembers = e.teams.reduce((acc, t) => acc + (t.member_count ?? t.members?.length ?? 0), 0);
    const osmEvent = e.ems_osm_event_id ? osmEvents.find((o) => o.event_id === Number(e.ems_osm_event_id) || o.id === Number(e.ems_osm_event_id)) : null;

    if (editing) {
        return (
            <div className="ems-edit-panel">
                <h2 className="ems-edit-title">
                    Editing: {e.post_title}{' '}
                    <span className="ems-edit-code">({e.ems_event_code})</span>
                </h2>
                <EventForm
                    seasonId={e.season_id ?? 0}
                    initialEvent={e}
                    osmEvents={osmEvents}
                    onSaved={(saved) => { setEditing(false); onSaved(saved); }}
                    onCancel={() => setEditing(false)}
                />
            </div>
        );
    }

    return (
        <div className="ems-expedition-panel">
            <div className="ems-expedition-header">
                <div>
                    <h2 className="ems-expedition-title">{e.post_title}</h2>
                    <div className="ems-expedition-code">{e.ems_event_code}</div>
                </div>
                <div className="ems-expedition-header__actions">
                    <button
                        type="button"
                        className="button button-primary"
                        onClick={() => setEditing(true)}
                    >
                        Edit
                    </button>
                </div>
            </div>

            {/* Sub-tabs */}
            <nav className="nav-tab-wrapper ems-mb-20">
                <button
                    type="button"
                    className={`nav-tab ${activeSubTab === 'overview' ? 'nav-tab-active' : ''} ems-sub-tab`}
                    onClick={() => setActiveSubTab('overview')}
                >
                    Overview
                </button>
                <button
                    type="button"
                    className={`nav-tab ${activeSubTab === 'teams' ? 'nav-tab-active' : ''} ems-sub-tab`}
                    onClick={() => setActiveSubTab('teams')}
                >
                    Teams
                </button>
                <button
                    type="button"
                    className={`nav-tab ${activeSubTab === 'training' ? 'nav-tab-active' : ''} ems-sub-tab`}
                    onClick={() => setActiveSubTab('training')}
                >
                    Training Requirements
                </button>
            </nav>

            {activeSubTab === 'overview' && (
                <div>
                    {/* Identification */}
                    <div className="ems-detail-section">
                        <div className="ems-detail-section-label">Identification</div>
                        <div className={gridClass(4)}>
                            <FieldVal label="Type" value={e.ems_type ? <span className={typePillClass(e.ems_type)}>{capitalize(e.ems_type)}</span> : null} />
                            <FieldVal label="Transport" value={e.ems_transport ? <span className={transportPillClass(e.ems_transport)}>{capitalize(e.ems_transport)}</span> : null} />
                            <FieldVal label="Level" value={e.ems_level ? <span className={levelPillClass(e.ems_level)}>{capitalize(e.ems_level)}</span> : null} />
                            <FieldVal label="First aid required" value={<span className={firstAidPillClass(e.ems_first_aid_level)}>{e.ems_first_aid_level ? FA_LABELS[e.ems_first_aid_level] : 'None'}</span>} />
                        </div>
                    </div>

                    {/* Schedule */}
                    <div className="ems-detail-section">
                        <div className="ems-detail-section-label">Schedule</div>
                        <div className={gridClass(4)}>
                            <FieldVal label="Start date" value={e.ems_start_date || null} />
                            <FieldVal label="Start time" value={e.ems_start_time || null} />
                            <FieldVal label="End date" value={e.ems_end_date || null} />
                            <FieldVal label="End time" value={e.ems_end_time || null} />
                        </div>
                    </div>

                    {/* Locations */}
                    <div className="ems-detail-section">
                        <div className="ems-detail-section-label">Locations</div>
                        <div className={gridClass(5)}>
                            <FieldVal label="Leader in charge" value={e.ems_lic_name || null} />
                            <FieldVal label="Leader email" value={e.ems_lic_email || null} />
                            <FieldVal label="Leader phone" value={e.ems_lic_phone || null} />
                            <FieldVal label="OSM event" value={osmEvent ? `${osmEvent.name} (${osmEvent.event_id})` : (e.ems_osm_event_id ? String(e.ems_osm_event_id) : null)} />
                            <FieldVal label="Total explorers" value={totalMembers > 0 ? String(totalMembers) : null} />
                        </div>
                    </div>

                    {/* Route Planning */}
                    <div className="ems-detail-section">
                        <div className="ems-detail-section-label">Route Planning</div>
                        <div className={`${gridClass(4)} ems-mb-20`}>
                            <FieldVal label="Start location" value={e.ems_start_location || null} />
                            <FieldVal label="End location" value={e.ems_end_location || null} />
                            <FieldVal label="Status" value={e.ems_status ? capitalize(e.ems_status) : null} />
                            <FieldVal label="Route deadline" value={e.ems_route_deadline || null} />
                        </div>
                        <div className="ems-meta-field__label ems-mb-6">Notes</div>
                        {e.ems_route_info
                            ? <div dangerouslySetInnerHTML={{ __html: e.ems_route_info }} className="ems-detail-notes" />
                            : <div className="ems-meta-field__value ems-meta-field__value--empty">—</div>
                        }
                    </div>
                </div>
            )}

            {activeSubTab === 'teams' && (
                <div>
                    <h3 className="ems-section-header ems-mt-0 ems-mb-4">
                        Teams ({e.teams.length})
                    </h3>
                    <FaKey />

                    {e.teams.length === 0 ? (
                        <p className="ems-meta-text">No teams yet.</p>
                    ) : (
                        <table className="widefat striped ems-team-table">
                            <thead>
                                <tr>
                                    <th>Team</th>
                                    <th>Size</th>
                                    <th>First Aid</th>
                                    <th>Members (A–Z)</th>
                                </tr>
                            </thead>
                            <tbody>
                                {e.teams.map((team) => (
                                    <TeamRow key={team.ID} team={team} />
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            )}

            {activeSubTab === 'training' && (
                <TrainingRequirementsTab eventId={e.ID} />
            )}
        </div>
    );
};

export const ExpeditionView: React.FC<ExpeditionViewProps> = ({ data, osmEvents = [] }) => {
    const allExpeditions: Expedition[] = (data.seasons ?? []).flatMap((s) =>
        s.events.map((ev) => ({ ...ev, season_id: ev.season_id ?? s.ID }))
    );

    const [selectedId, setSelectedId] = useState<number | null>(allExpeditions[0]?.ID ?? null);
    const [overrides, setOverrides] = useState<Record<number, Expedition>>({});

    const selected = (() => {
        const base = allExpeditions.find((e) => e.ID === selectedId) ?? null;
        if (!base) return null;
        return overrides[base.ID] ? { ...base, ...overrides[base.ID] } : base;
    })();

    const handleSaved = (updated: Expedition) => {
        setOverrides((prev) => ({ ...prev, [updated.ID]: updated }));
    };

    if (allExpeditions.length === 0) {
        return <div className="notice notice-info"><p>No expeditions found. Create a season and add expeditions first.</p></div>;
    }

    return (
        <div className="ems-expedition-view">
            <div className="ems-expedition-select-wrap">
                <label htmlFor="expedition-view-select" className="ems-expedition-select-label">Expedition:</label>
                <select
                    id="expedition-view-select"
                    aria-label="Select expedition"
                    className="ems-expedition-select ems-select"
                    value={selectedId ?? ''}
                    onChange={(e) => setSelectedId(Number(e.target.value))}
                >
                    {data.seasons.map((season) => (
                        <optgroup key={season.ID} label={season.post_title}>
                            {season.events.map((ev) => (
                                <option key={ev.ID} value={ev.ID}>
                                    {ev.ems_event_code} — {ev.post_title}
                                </option>
                            ))}
                        </optgroup>
                    ))}
                </select>
            </div>

            {selected && (
                <ExpeditionDetail
                    expedition={selected}
                    osmEvents={osmEvents}
                    onSaved={handleSaved}
                />
            )}
        </div>
    );
};
