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

function pillStyle(colors: { bg: string; color: string }): React.CSSProperties {
    return {
        display: 'inline-flex', alignItems: 'center', gap: '2px',
        fontSize: '11px', fontWeight: 600, padding: '3px 8px',
        borderRadius: '12px', background: colors.bg, color: colors.color,
    };
}
function typePill(type: string): React.CSSProperties {
    const c: Record<string, { bg: string; color: string }> = {
        training:  { bg: '#e3f2fd', color: '#1565c0' },
        practice:  { bg: '#e8f5e9', color: '#2e7d32' },
        qualifying: { bg: '#f3e5f5', color: '#7b1fa2' },
    };
    return pillStyle(c[type] || { bg: '#eee', color: '#666' });
}
function transportPill(t?: string): React.CSSProperties {
    const c: Record<string, { bg: string; color: string }> = {
        hillwalking: { bg: '#efebe9', color: '#5d4037' },
        biking:      { bg: '#e0f2f1', color: '#00695c' },
        paddling:    { bg: '#e1f5fe', color: '#0277bd' },
    };
    return pillStyle(c[t || ''] || { bg: '#eee', color: '#666' });
}
function levelPill(l: string): React.CSSProperties {
    const c: Record<string, { bg: string; color: string }> = {
        bronze: { bg: '#f0d4b8', color: '#7a4410' },
        silver: { bg: '#e0e0e0', color: '#444' },
        gold:   { bg: '#fff3cd', color: '#7a5c10' },
    };
    return pillStyle(c[l] || { bg: '#eee', color: '#666' });
}
function firstAidPill(l?: string): React.CSSProperties {
    const c: Record<string, { bg: string; color: string }> = {
        none:           { bg: '#f5f5f5', color: '#666' },
        first_response: { bg: '#e8f5e9', color: '#2e7d32' },
        full_first_aid: { bg: '#c8e6c9', color: '#1b5e20' },
    };
    return pillStyle(c[l ?? 'none'] || c.none);
}

function sortedMembers(members: Member[]): Member[] {
    return [...members].sort((a, b) =>
        `${a.first_name} ${a.last_name}`.localeCompare(`${b.first_name} ${b.last_name}`)
    );
}

function FaIcon({ level }: { level?: FirstAidLevel }) {
    if (level === 'full_first_aid') {
        return <span title="Full First Aid" style={{ color: '#1b5e20', fontWeight: 'bold', marginRight: '4px' }}>⊕</span>;
    }
    if (level === 'first_response') {
        return <span title="First Response" style={{ color: '#2e7d32', fontWeight: 'bold', marginRight: '4px' }}>✚</span>;
    }
    return null;
}

const FaKey: React.FC = () => (
    <div style={{ display: 'flex', gap: '16px', fontSize: '11px', color: '#555', marginTop: '8px', marginBottom: '4px' }}>
        <span><span style={{ color: '#1b5e20', fontWeight: 'bold' }}>⊕</span> Full First Aid</span>
        <span><span style={{ color: '#2e7d32', fontWeight: 'bold' }}>✚</span> First Response</span>
    </div>
);

const sectionStyle: React.CSSProperties = {
    marginBottom: '24px',
    paddingBottom: '20px',
    borderBottom: '1px solid #eee',
};

const sectionLabelStyle: React.CSSProperties = {
    fontSize: '11px',
    fontWeight: 700,
    color: '#888',
    textTransform: 'uppercase',
    letterSpacing: '0.06em',
    marginBottom: '14px',
};

const gridStyle = (cols: number): React.CSSProperties => ({
    display: 'grid',
    gridTemplateColumns: `repeat(${cols}, minmax(0, 200px))`,
    gap: '16px 32px',
});

const FieldVal: React.FC<{ label: string; value: React.ReactNode }> = ({ label, value }) => (
    <div>
        <div style={{ fontSize: '11px', fontWeight: 600, color: '#999', marginBottom: '4px', textTransform: 'uppercase', letterSpacing: '0.03em' }}>{label}</div>
        <div style={{ fontSize: '14px', color: value ? '#1d2327' : '#bbb' }}>{value || '—'}</div>
    </div>
);

const TeamRow: React.FC<{ team: Team }> = ({ team }) => {
    const members = sortedMembers(team.members ?? []);
    const size = team.member_count ?? members.length;
    const hasFa = members.some((m) => m.first_aid_level && m.first_aid_level !== 'none');
    const faBadge = hasFa ? (
        <span style={{ display: 'inline-block', background: '#00a32a', color: '#fff', borderRadius: '3px', padding: '1px 7px', fontSize: '11px' }}>
            First Aid ✓
        </span>
    ) : (
        <span style={{ display: 'inline-block', background: '#d63638', color: '#fff', borderRadius: '3px', padding: '1px 7px', fontSize: '11px' }}>
            No First Aid
        </span>
    );

    const sizeColor = size < 4 || size > 7 ? '#d63638' : '#1d2327';

    return (
        <tr>
            <td style={{ fontWeight: 600, verticalAlign: 'top' }}>{team.ems_team_code}</td>
            <td style={{ color: sizeColor, fontWeight: size < 4 || size > 7 ? 600 : 400, verticalAlign: 'top' }}>
                {size}
                {(size < 4 || size > 7) && (
                    <span style={{ fontSize: '11px', marginLeft: '4px', color: '#d63638' }}>⚠</span>
                )}
            </td>
            <td style={{ verticalAlign: 'top' }}>{faBadge}</td>
            <td style={{ fontSize: '12px', verticalAlign: 'top' }}>
                {members.length === 0 ? (
                    <span style={{ color: '#aaa' }}>—</span>
                ) : (
                    <ul style={{ margin: 0, padding: 0, listStyle: 'none' }}>
                        {members.map((m) => (
                            <li key={m.scout_id ?? m.user_id} style={{ display: 'flex', alignItems: 'center', padding: '1px 0' }}>
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
        return <div style={{ padding: '20px 0', color: '#666' }}>Loading training requirements...</div>;
    }

    return (
        <div style={{ marginTop: '16px' }}>
            <h3 style={{ fontSize: '15px', fontWeight: 600, marginBottom: '12px' }}>
                Required Tutor LMS Courses
            </h3>
            <p style={{ color: '#666', fontSize: '13px', marginBottom: '16px' }}>
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
                <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', marginBottom: '20px' }}>
                    {courses.map((course) => {
                        const isChecked = selectedIds.includes(course.id);
                        return (
                            <label key={course.id} style={{ display: 'flex', alignItems: 'center', gap: '8px', fontSize: '14px', cursor: 'pointer' }}>
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
            <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: '4px', padding: '20px' }}>
                <h2 style={{ marginTop: 0, marginBottom: '16px', fontSize: '18px' }}>
                    Editing: {e.post_title}{' '}
                    <span style={{ fontWeight: 400, fontSize: '14px', color: '#666' }}>({e.ems_event_code})</span>
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
        <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: '4px', padding: '24px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '24px' }}>
                <div>
                    <h2 style={{ margin: 0, fontSize: '20px', fontWeight: 600 }}>{e.post_title}</h2>
                    <div style={{ marginTop: '4px', fontSize: '13px', color: '#888' }}>{e.ems_event_code}</div>
                </div>
                <button
                    type="button"
                    className="button button-primary"
                    style={{ flexShrink: 0, marginLeft: '16px' }}
                    onClick={() => setEditing(true)}
                >
                    Edit
                </button>
            </div>

            {/* Sub-tabs */}
            <nav className="nav-tab-wrapper" style={{ marginBottom: '20px' }}>
                <button
                    type="button"
                    className={`nav-tab ${activeSubTab === 'overview' ? 'nav-tab-active' : ''}`}
                    onClick={() => setActiveSubTab('overview')}
                    style={{ background: 'none', border: 'none', cursor: 'pointer' }}
                >
                    Overview
                </button>
                <button
                    type="button"
                    className={`nav-tab ${activeSubTab === 'teams' ? 'nav-tab-active' : ''}`}
                    onClick={() => setActiveSubTab('teams')}
                    style={{ background: 'none', border: 'none', cursor: 'pointer' }}
                >
                    Teams
                </button>
                <button
                    type="button"
                    className={`nav-tab ${activeSubTab === 'training' ? 'nav-tab-active' : ''}`}
                    onClick={() => setActiveSubTab('training')}
                    style={{ background: 'none', border: 'none', cursor: 'pointer' }}
                >
                    Training Requirements
                </button>
            </nav>

            {activeSubTab === 'overview' && (
                <div>
                    {/* Identification */}
                    <div style={sectionStyle}>
                        <div style={sectionLabelStyle}>Identification</div>
                        <div style={gridStyle(4)}>
                            <FieldVal label="Type" value={e.ems_type ? <span style={typePill(e.ems_type)}>{capitalize(e.ems_type)}</span> : null} />
                            <FieldVal label="Transport" value={e.ems_transport ? <span style={transportPill(e.ems_transport)}>{capitalize(e.ems_transport)}</span> : null} />
                            <FieldVal label="Level" value={e.ems_level ? <span style={levelPill(e.ems_level)}>{capitalize(e.ems_level)}</span> : null} />
                            <FieldVal label="First aid required" value={<span style={firstAidPill(e.ems_first_aid_level)}>{e.ems_first_aid_level ? FA_LABELS[e.ems_first_aid_level] : 'None'}</span>} />
                        </div>
                    </div>

                    {/* Schedule */}
                    <div style={sectionStyle}>
                        <div style={sectionLabelStyle}>Schedule</div>
                        <div style={gridStyle(4)}>
                            <FieldVal label="Start date" value={e.ems_start_date || null} />
                            <FieldVal label="Start time" value={e.ems_start_time || null} />
                            <FieldVal label="End date" value={e.ems_end_date || null} />
                            <FieldVal label="End time" value={e.ems_end_time || null} />
                        </div>
                    </div>

                    {/* Locations */}
                    <div style={sectionStyle}>
                        <div style={sectionLabelStyle}>Locations</div>
                        <div style={gridStyle(5)}>
                            <FieldVal label="Leader in charge" value={e.ems_lic_name || null} />
                            <FieldVal label="Leader email" value={e.ems_lic_email || null} />
                            <FieldVal label="Leader phone" value={e.ems_lic_phone || null} />
                            <FieldVal label="OSM event" value={osmEvent ? `${osmEvent.name} (${osmEvent.event_id})` : (e.ems_osm_event_id ? String(e.ems_osm_event_id) : null)} />
                            <FieldVal label="Total explorers" value={totalMembers > 0 ? String(totalMembers) : null} />
                        </div>
                    </div>

                    {/* Route Planning */}
                    <div style={sectionStyle}>
                        <div style={sectionLabelStyle}>Route Planning</div>
                        <div style={{ ...gridStyle(4), marginBottom: '20px' }}>
                            <FieldVal label="Start location" value={e.ems_start_location || null} />
                            <FieldVal label="End location" value={e.ems_end_location || null} />
                            <FieldVal label="Status" value={e.ems_status ? capitalize(e.ems_status) : null} />
                            <FieldVal label="Route deadline" value={e.ems_route_deadline || null} />
                        </div>
                        <div style={{ fontSize: '11px', fontWeight: 600, color: '#999', textTransform: 'uppercase', letterSpacing: '0.03em', marginBottom: '6px' }}>Notes</div>
                        {e.ems_route_info
                            ? <div dangerouslySetInnerHTML={{ __html: e.ems_route_info }} style={{ fontSize: '14px', maxWidth: '680px', lineHeight: 1.6 }} />
                            : <div style={{ fontSize: '14px', color: '#bbb' }}>—</div>
                        }
                    </div>
                </div>
            )}

            {activeSubTab === 'teams' && (
                <div>
                    <h3 style={{ marginTop: 0, marginBottom: '4px', fontSize: '15px' }}>
                        Teams ({e.teams.length})
                    </h3>
                    <FaKey />

                    {e.teams.length === 0 ? (
                        <p style={{ color: '#666' }}>No teams yet.</p>
                    ) : (
                        <table className="widefat striped" style={{ fontSize: '13px', marginTop: '8px' }}>
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
            <div style={{ marginBottom: '16px', display: 'flex', alignItems: 'center', gap: '12px' }}>
                <label htmlFor="expedition-view-select" style={{ fontWeight: 600 }}>Expedition:</label>
                <select
                    id="expedition-view-select"
                    aria-label="Select expedition"
                    value={selectedId ?? ''}
                    onChange={(e) => setSelectedId(Number(e.target.value))}
                    style={{ minWidth: '260px' }}
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
