import React, { useState, useEffect, useCallback } from 'react';
import { Expedition, Team, Member, Explorer, FirstAidLevel, OSMEvent } from './types';
import { EventForm } from './EventForm';
import { OSMReadOnlyMap } from './OSMReadOnlyMap';
import EventPlanningBoard from './EventPlanningBoard';

interface EventDetailPageProps {
    event: Expedition;
    onBack: () => void;
    explorers?: Explorer[];
    osmEvents?: OSMEvent[];
    allEvents?: Expedition[];
    onEventUpdated?: (updated: Expedition) => void;
    initialEdit?: boolean;
}

type DetailTab = 'overview' | 'teams' | 'training' | 'asn' | 'volunteers' | 'qrcodes' | 'route';

const FA_LABELS: Record<FirstAidLevel, string> = {
    none: 'None',
    first_response: 'First Response',
    full_first_aid: 'Full First Aid',
};

function formatDate(d?: string): string {
    if (!d) return '—';
    try { return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); } catch { return d; }
}

function capitalize(s: string): string {
    return s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : '—';
}

function transportLabel(t?: string): string {
    const m: Record<string, string> = { hillwalking: '🥾 Hillwalking', biking: '🚴 Biking', paddling: '🚣 Paddling' };
    return m[t || ''] || (t || '—');
}

function statusBadge(status?: string): React.ReactNode {
    const s = status || 'active';
    const modifier = s === 'archived' ? 'ems-status-badge--archived' : 'ems-status-badge--active';
    return (
        <span className={`ems-status-badge ${modifier}`}>{s}</span>
    );
}

async function apiFetch(path: string, options?: RequestInit): Promise<Response> {
    const config = window.emsExpeditionBoard;
    return fetch(`${config.root_url}${path}`, {
        ...options,
        headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json', ...(options?.headers ?? {}) },
    });
}

const FieldVal: React.FC<{ label: string; value?: React.ReactNode }> = ({ label, value }) => (
    <div className="ems-meta-field">
        <div className="ems-meta-field__label">{label}</div>
        <div className={value ? 'ems-meta-field__value' : 'ems-meta-field__value ems-meta-field__value--empty'}>{value || '—'}</div>
    </div>
);

const LocationDisplay: React.FC<{ value?: string }> = ({ value }) => {
    const [resolvedName, setResolvedName] = useState<string>('');
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
            headers: { 'Accept-Language': 'en' }
        })
            .then(res => {
                if (res.ok) return res.json();
                throw new Error();
            })
            .then(data => {
                const addr = data.address;
                const name = addr.road || addr.suburb || addr.town || addr.city || addr.county || '';
                const county = addr.county || addr.state || '';
                setResolvedName(name ? (county ? `${name}, ${county}` : name) : '');
            })
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [value]);

    if (!value) return <span>—</span>;

    if (!coordsMatch) {
        return <span>{value}</span>;
    }

    const lat = coordsMatch[1];
    const lng = coordsMatch[2];
    const mapUrl = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=15/${lat}/${lng}`;

    return (
        <div>
            <a href={mapUrl} target="_blank" rel="noopener noreferrer" className="ems-detail-link">
                {lat}, {lng} ↗
            </a>
            {loading && <div className="ems-detail-link-loading">Resolving address…</div>}
            {!loading && resolvedName && (
                <div className="ems-detail-link-resolved">
                    {resolvedName}
                </div>
            )}
        </div>
    );
};

function grd(cols: number): string {
    return `ems-form-grid-${cols}`;
}

/* Overview */
const OverviewTab: React.FC<{ event: Expedition; osmEvents?: OSMEvent[]; onUpdated?: (e: Expedition) => void; initialEdit?: boolean }> = ({ event, osmEvents = [], onUpdated, initialEdit = false }) => {
    const [editing, setEditing] = useState(initialEdit);
    const matchedOsm = osmEvents?.find(e => Number(e.event_id) === Number(event.ems_osm_event_id));
    const osmEventDisplay = matchedOsm ? matchedOsm.name : (event.ems_osm_event_id || '—');

    return (
        <div>
            <div className="ems-edit-bar">
                <button className="button" onClick={() => setEditing((v) => !v)}>{editing ? 'Cancel' : 'Edit Event'}</button>
            </div>
            {editing ? (
                <EventForm seasonId={0} initialEvent={event} osmEvents={osmEvents} onSaved={(u) => { setEditing(false); onUpdated?.(u); }} onCancel={() => setEditing(false)} />
            ) : (
                <>
                    <div className="ems-tab-section"><h3 className="ems-tab-section-title">Event Details</h3>
                        <div className={grd(4)}>
                            <FieldVal label="Type" value={capitalize(event.ems_type)} />
                            <FieldVal label="Transport" value={capitalize(event.ems_transport || '')} />
                            <FieldVal label="Level" value={capitalize(event.ems_level)} />
                            <FieldVal label="First Aid" value={FA_LABELS[event.ems_first_aid_level as FirstAidLevel] ?? event.ems_first_aid_level} />
                        </div>
                    </div>
                    <div className="ems-tab-section"><h3 className="ems-tab-section-title">Schedule</h3>
                        <div className={grd(4)}>
                            <FieldVal label="Start Date" value={formatDate(event.ems_start_date)} />
                            <FieldVal label="Start Time" value={event.ems_start_time} />
                            <FieldVal label="End Date" value={formatDate(event.ems_end_date)} />
                            <FieldVal label="End Time" value={event.ems_end_time} />
                        </div>
                    </div>
                    <div className="ems-tab-section"><h3 className="ems-tab-section-title">Leader in Charge</h3>
                        <div className={grd(5)}>
                            <FieldVal label="Name" value={event.ems_lic_name} />
                            <FieldVal label="Public Email" value={event.ems_lic_email} />
                            <FieldVal label="Public Phone" value={event.ems_lic_phone} />
                            <FieldVal label="Private Email" value={event.ems_lic_private_email} />
                            <FieldVal label="Private Phone" value={event.ems_lic_private_phone} />
                        </div>
                    </div>
                    <div className="ems-tab-section"><h3 className="ems-tab-section-title">OSM Sync</h3>
                        <div className={grd(2)}>
                            <FieldVal label="OSM Event" value={osmEventDisplay} />
                            <FieldVal label="Route Status" value={capitalize(event.ems_route_status || 'draft')} />
                        </div>
                    </div>
                </>
            )}
        </div>
    );
};

/* Route */
const RouteTab: React.FC<{ event: Expedition }> = ({ event }) => {
    return (
        <div>
            <div className="ems-tab-section">
                <h3 className="ems-tab-section-title">Route Information & Planning</h3>
                <div className="ems-form-grid-3 ems-mb-16">
                    <FieldVal label="Start Location" value={<LocationDisplay value={event.ems_start_location} />} />
                    <FieldVal label="End Location" value={<LocationDisplay value={event.ems_end_location} />} />
                    <FieldVal label="Route Deadline" value={formatDate(event.ems_route_deadline)} />
                </div>
                <OSMReadOnlyMap startLocation={event.ems_start_location} endLocation={event.ems_end_location} />
                {event.ems_route_info ? (
                    <div className="ems-mt-24">
                        <h4 className="ems-tab-section-title">Route Details</h4>
                        <div
                            className="ems-rte-readonly ems-rte-readonly__content"
                            dangerouslySetInnerHTML={{ __html: event.ems_route_info }}
                        />
                    </div>
                ) : (
                    <p className="ems-meta-text ems-italic ems-mt-16">No route description uploaded yet.</p>
                )}
            </div>
        </div>
    );
};

/* Teams */
function sortByName(members: Member[]): Member[] {
    return [...members].sort((a, b) => `${a.first_name} ${a.last_name}`.localeCompare(`${b.first_name} ${b.last_name}`));
}

// Removed legacy TeamsTab and TeamCard components. Shared EventPlanningBoard is used instead.

/* Training */
const TrainingTab: React.FC<{ eventId: number }> = ({ eventId }) => {
    const config = window.emsExpeditionBoard;
    const [courses, setCourses] = useState<Array<{ id: number; title: string }>>([]);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [completion, setCompletion] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(`${config.root_url}/events/${eventId}/training-requirements`, { headers: { 'X-WP-Nonce': config.nonce } });
            if (res.ok) {
                const data = await res.json();
                setCourses(data.courses ?? []);
                setSelectedIds(data.course_ids ?? []);
                setCompletion(data.completion ?? []);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setLoading(false);
        }
    }, [config.nonce, config.root_url, eventId]);

    useEffect(() => { load(); }, [load]);

    const handleToggleCourse = (id: number) => {
        setSelectedIds(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );
    };

    const handleSave = async () => {
        setSaving(true);
        try {
            const res = await fetch(`${config.root_url}/events/${eventId}/training-requirements`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
                body: JSON.stringify({ course_ids: selectedIds }),
            });
            if (res.ok) {
                await load();
            }
        } catch (e) {
            console.error(e);
        } finally {
            setSaving(false);
        }
    };

    if (loading) return <p>Loading training requirements…</p>;

    const requiredCourses = courses.filter(c => selectedIds.includes(c.id));

    return (
        <div>
            <div className="ems-tab-section">
                <h3 className="ems-tab-section-title">Tutor LMS Training Requirements</h3>
                <p className="ems-tab-section-description">Select the training courses required for participants in this expedition:</p>

                {/* Course Selector Checklist */}
                <div className="ems-training-grid">
                    {courses.map(course => (
                        <label key={course.id} className="ems-training-course">
                            <input
                                type="checkbox"
                                checked={selectedIds.includes(course.id)}
                                onChange={() => handleToggleCourse(course.id)}
                                className="ems-checkbox ems-m-0"
                            />
                            {course.title}
                        </label>
                    ))}
                    {courses.length === 0 && <p className="ems-training-empty">No Tutor LMS courses found.</p>}
                </div>

                <button type="button" className="button button-primary ems-mt-16" onClick={handleSave} disabled={saving || courses.length === 0}>
                    {saving ? 'Saving…' : 'Save Training Requirements'}
                </button>
            </div>

            <div className="ems-tab-section">
                {/* Participant Completion Matrix */}
                <h3 className="ems-tab-section-title">Explorer Completion Status</h3>
                {requiredCourses.length === 0 ? (
                    <div className="ems-training-info-box">
                        No training requirements selected. Select courses above to track participant completion.
                    </div>
                ) : (
                    <table className="widefat striped ems-completion-table">
                        <thead>
                            <tr>
                                <th>Explorer</th>
                                {requiredCourses.map(c => (
                                    <th key={c.id}>{c.title}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {[...completion].sort((a, b) => `${a.first_name} ${a.last_name}`.localeCompare(`${b.first_name} ${b.last_name}`)).map(row => (
                                <tr key={row.scout_id}>
                                    <td className="ems-completion-name">
                                        <div>{row.first_name} {row.last_name}</div>
                                        <div className="ems-table-cell--meta">Unit: {row.unit_name ?? 'Unassigned'}</div>
                                    </td>
                                    {requiredCourses.map(c => {
                                        const status = row.matrix[c.id];
                                        const isComplete = status === 'complete';
                                        return (
                                            <td key={c.id}>
                                                <span className={`ems-status-badge ems-status-badge--${isComplete ? 'success' : 'danger'}`}>
                                                    {isComplete ? '✅ Complete' : '❌ Incomplete'}
                                                </span>
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                            {completion.length === 0 && (
                                <tr>
                                    <td colSpan={requiredCourses.length + 1} className="ems-empty-cell">
                                        No participants assigned to this event yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                )}
            </div>
        </div>
    );
};

/* Support Needs / ASN Tab */
const ASNTab: React.FC<{ eventId: number; onTeamChanged: () => void }> = ({ eventId, onTeamChanged }) => {
    const config = window.emsExpeditionBoard;
    const [explorers, setExplorers] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [notes, setNotes] = useState<Record<number, string>>({});
    const [initialNotes, setInitialNotes] = useState<Record<number, string>>({});
    const [parentAsn, setParentAsn] = useState<Record<number, string>>({});
    const [savedStatus, setSavedStatus] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(`${config.root_url}/events/${eventId}/teams`, { headers: { 'X-WP-Nonce': config.nonce } });
            if (res.ok) {
                const teams: Team[] = await res.json();
                // Extract all members
                const allMembers = teams.flatMap(t => (t.members ?? []).map(m => ({ ...m, teamName: t.ems_team_code })));
                // Filter only those with ASN or load all?
                // Let's load all and let the organizer view and edit notes!
                setExplorers(allMembers);
                
                // Fetch details/organiser notes for each
                const notesMap: Record<number, string> = {};
                const parentAsnMap: Record<number, string> = {};
                await Promise.all(allMembers.map(async (m) => {
                    if (m.scout_id) {
                        try {
                            const asnRes = await fetch(`${config.root_url}/explorers/${m.scout_id}/asn`, { headers: { 'X-WP-Nonce': config.nonce } });
                            if (asnRes.ok) {
                                const data = await asnRes.json();
                                notesMap[m.scout_id] = data.organiser_notes || '';
                                parentAsnMap[m.scout_id] = data.parent_asn || '';
                            }
                        } catch (err) {
                            console.error(err);
                        }
                    }
                }));
                setNotes(notesMap);
                setInitialNotes({ ...notesMap });
                setParentAsn(parentAsnMap);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setLoading(false);
        }
    }, [config.nonce, config.root_url, eventId]);

    useEffect(() => { load(); }, [load]);

    const handleSaveAll = async () => {
        setSaving(true);
        setSavedStatus(false);
        try {
            const modifiedScoutIds = Object.keys(notes).map(Number).filter(id => {
                const init = initialNotes[id] || '';
                const current = notes[id] || '';
                return init !== current;
            });

            await Promise.all(modifiedScoutIds.map(async (scoutId) => {
                await apiFetch(`/explorers/${scoutId}/asn`, {
                    method: 'POST',
                    body: JSON.stringify({ organiser_notes: notes[scoutId] || '' })
                });
            }));

            setInitialNotes({ ...notes });
            setSavedStatus(true);
            setTimeout(() => {
                setSavedStatus(false);
            }, 3000);
            onTeamChanged(); // refresh parent board
        } catch (e) {
            console.error(e);
        } finally {
            setSaving(false);
        }
    };

    if (loading) return <p>Loading support needs…</p>;

    return (
        <div className="ems-tab-section">
            <h3 className="ems-tab-section-title">Additional Support Needs (Medical / PII)</h3>
            <p className="ems-tab-section-description">Secure directory of registered medical or accessibility requirements for this expedition.</p>

            <table className="widefat striped ems-asn-table">
                <thead>
                    <tr>
                        <th className="ems-asn-th-name">Explorer / Team</th>
                        <th>Information Provided by Parent (Sign Up)</th>
                        <th>Organiser's Confidential Notes</th>
                    </tr>
                </thead>
                <tbody>
                    {explorers.map(exp => (
                        <tr key={exp.scout_id}>
                            <td>
                                <strong>{exp.first_name} {exp.last_name}</strong>
                                <div className="ems-asn-team">
                                    Team: <code>{exp.teamName === 'UNALLOCATED' ? 'Unallocated' : exp.teamName}</code>
                                </div>
                            </td>
                            <td className={`ems-asn-parent-note ${parentAsn[exp.scout_id] ? 'ems-asn-parent-note--has' : 'ems-asn-parent-note--empty'}`}>
                                {parentAsn[exp.scout_id] || '— No support needs declared by parent —'}
                            </td>
                            <td>
                                <textarea
                                    className="ems-asn-textarea"
                                    aria-label={`Organiser notes for ${exp.first_name} ${exp.last_name}`}
                                    value={notes[exp.scout_id ?? 0] || ''}
                                    onChange={(e) => setNotes(prev => ({ ...prev, [exp.scout_id ?? 0]: e.target.value }))}
                                    placeholder="Enter private organiser notes here (e.g. medication details, actions needed)…"
                                />
                            </td>
                        </tr>
                    ))}
                    {explorers.length === 0 && (
                        <tr>
                            <td colSpan={3} className="ems-empty-cell">
                                No participants assigned to this event yet.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
            
            <div className="ems-flex-between ems-mt-16">
                <div>
                    {savedStatus && <span className="ems-saved-indicator">✓ Confidential notes saved successfully.</span>}
                </div>
                <button
                    type="button"
                    className="button button-primary"
                    onClick={handleSaveAll}
                    disabled={saving}
                >
                    {saving ? 'Saving…' : 'Save Confidential Notes'}
                </button>
            </div>
        </div>
    );
};

/* QR Codes */
const QRCodesTab: React.FC<{ event: Expedition }> = ({ event }) => {
    const [explorerLink, setExplorerLink] = useState(event.ems_expedition_whatsapp_explorers ?? '');
    const [parentLink, setParentLink] = useState(event.ems_expedition_whatsapp_parents ?? '');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    const save = async (e: React.FormEvent) => {
        e.preventDefault(); setSaving(true); setSaved(false);
        try {
            const res = await apiFetch(`/events/${event.ID}/whatsapp`, { method: 'POST', body: JSON.stringify({ explorer_link: explorerLink, parent_link: parentLink }) });
            if (res.ok) { setSaved(true); setTimeout(() => setSaved(false), 3000); }
        } catch { /* silent */ } finally { setSaving(false); }
    };

    return (
        <div className="ems-tab-section">
            <h3 className="ems-tab-section-title">WhatsApp Group Links & QR Codes</h3>
            <form onSubmit={save} className="ems-qr-form">
                <table className="form-table ems-qr-table">
                    <tbody>
                        <tr><th scope="row" className="ems-qr-th">Explorers WhatsApp Link</th><td><input id="ems-whatsapp-explorers" type="url" className="large-text" value={explorerLink} onChange={(e) => setExplorerLink(e.target.value)} placeholder="https://chat.whatsapp.com/…" /></td></tr>
                        <tr><th scope="row" className="ems-qr-th">Parents WhatsApp Link</th><td><input id="ems-whatsapp-parents" type="url" className="large-text" value={parentLink} onChange={(e) => setParentLink(e.target.value)} placeholder="https://chat.whatsapp.com/…" /></td></tr>
                    </tbody>
                </table>
                <button type="submit" className="button button-primary ems-mt-16" disabled={saving}>{saving ? 'Saving…' : 'Save Links'}</button>
                {saved && <span className="ems-qr-saved">✓ Saved</span>}
            </form>
            <div className="ems-qr-container">
                {explorerLink && (
                    <div className="ems-qr-item">
                        <div className="ems-qr-item__title">🧭 Explorers Group</div>
                        <a href={explorerLink} target="_blank" rel="noopener noreferrer" className="ems-qr-item__link">Open link ↗</a>
                        <img src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(explorerLink)}`} alt="Explorers WhatsApp QR Code" className="ems-qr-item__image" />
                    </div>
                )}
                {parentLink && (
                    <div className="ems-qr-item">
                        <div className="ems-qr-item__title">👨‍👩‍👧 Parents Group</div>
                        <a href={parentLink} target="_blank" rel="noopener noreferrer" className="ems-qr-item__link">Open link ↗</a>
                        <img src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(parentLink)}`} alt="Parents WhatsApp QR Code" className="ems-qr-item__image" />
                    </div>
                )}
                {!explorerLink && !parentLink && <p className="ems-qr-empty">No WhatsApp links set yet.</p>}
            </div>
        </div>
    );
};

/* Secure ASN Drawer Overlay */
interface ASNDrawerProps {
    scoutId: number;
    onClose: () => void;
    onSaved: () => void;
}

const ASNDrawer: React.FC<ASNDrawerProps> = ({ scoutId, onClose, onSaved }) => {
    const config = window.emsExpeditionBoard;
    const [loading, setLoading] = useState(true);
    const [data, setData] = useState<any>(null);
    const [notes, setNotes] = useState('');
    const [saving, setSaving] = useState(false);
    const [success, setSuccess] = useState(false);

    useEffect(() => {
        let active = true;
        setLoading(true);
        fetch(`${config.root_url}/explorers/${scoutId}/asn`, { headers: { 'X-WP-Nonce': config.nonce } })
            .then(res => res.json())
            .then(json => {
                if (active) {
                    setData(json);
                    setNotes(json.organiser_notes || '');
                    setLoading(false);
                }
            })
            .catch(err => {
                console.error(err);
                if (active) setLoading(false);
            });

        return () => { active = false; };
    }, [scoutId, config.nonce, config.root_url]);

    const handleSave = async () => {
        setSaving(true);
        setSuccess(false);
        try {
            const res = await apiFetch(`/explorers/${scoutId}/asn`, {
                method: 'POST',
                body: JSON.stringify({ organiser_notes: notes }),
            });
            if (res.ok) {
                setSuccess(true);
                onSaved();
                setTimeout(() => setSuccess(false), 2000);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="ems-asn-drawer">
            <div className="ems-asn-drawer__header">
                <span className="ems-asn-drawer__title">🔒 Support Needs (Confidential)</span>
                <button type="button" className="ems-asn-drawer__close" onClick={onClose}>×</button>
            </div>

            <div className="ems-asn-drawer__content">
                {loading ? (
                    <p>Loading medical support records…</p>
                ) : !data ? (
                    <p className="ems-asn-drawer__error">Failed to retrieve data safely.</p>
                ) : (
                    <>
                        <div>
                            <h3 className="ems-asn-drawer__name">{data.first_name} {data.last_name}</h3>
                            <code className="ems-asn-drawer__scout-id">Scout ID: {data.scout_id}</code>
                        </div>

                        <div>
                            <strong className="ems-asn-drawer__label">
                                Declared by Parent (Sign Up)
                            </strong>
                            <div className="ems-asn-drawer__parent-asn">
                                {data.parent_asn || 'No support needs declared by parent.'}
                            </div>
                        </div>

                        <div>
                            <strong className="ems-asn-drawer__label">
                                Organiser notes
                            </strong>
                            <textarea
                                className="ems-asn-drawer__notes"
                                aria-label="Organiser notes"
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Add confidential leader notes (e.g. details of inhalers, food allergies, action plans)…"
                            />
                        </div>
                    </>
                )}
            </div>

            <div className="ems-asn-drawer__footer">
                {success && <span className="ems-saved-indicator">✓ Saved</span>}
                <button type="button" className="button" onClick={onClose}>Close</button>
                <button type="button" className="button button-primary" onClick={handleSave} disabled={loading || saving}>
                    {saving ? 'Saving…' : 'Save Notes'}
                </button>
            </div>
        </div>
    );
};

/* Volunteers Tab */
const VolunteersTab: React.FC<{ event: Expedition, allEvents?: Expedition[] }> = ({ event, allEvents = [] }) => {
    const [volunteers, setVolunteers] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [rosterCollapsed, setRosterCollapsed] = useState(false);
    const [roleFilter, setRoleFilter] = useState('all');

    const config = window.emsExpeditionBoard;

    const fetchVolunteers = async () => {
        setLoading(true);
        try {
            const res = await fetch(`${config.root_url}/volunteers`, { headers: { 'X-WP-Nonce': config.nonce } });
            if (res && res.ok) {
                const data = await res.json();
                setVolunteers(data);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchVolunteers();
    }, [event.ID]);

    const handleAssign = async (volunteerId: number, confirmVal: number) => {
        try {
            const res = await fetch(`${config.root_url}/volunteers/assign`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify({
                    volunteer_id: volunteerId,
                    expedition_post_id: event.ID,
                    confirmed: confirmVal
                })
            });
            if (res.ok) {
                fetchVolunteers();
            }
        } catch (e) {
            console.error(e);
        }
    };

    if (loading) {
        return <div>Loading volunteers list...</div>;
    }

    const assignedVolunteers = volunteers.filter(v => 
        v.availability.some((s: any) => s.expedition_post_id === event.ID && s.confirmed === 1)
    );

    const availableVolunteers = volunteers.filter(v => 
        v.availability.some((s: any) => s.expedition_post_id === event.ID && s.confirmed === 0)
    ).filter(v => {
        if (roleFilter === 'all') return true;
        return v.preferred_roles?.some((r: string) => r.toLowerCase() === roleFilter.toLowerCase());
    });

    const getDatesForEvent = () => {
        const dates: string[] = [];
        if (!event.ems_start_date || !event.ems_end_date) return dates;
        let curr = new Date(event.ems_start_date);
        const end = new Date(event.ems_end_date);
        while (curr <= end) {
            dates.push(curr.toISOString().split('T')[0]);
            curr.setDate(curr.getDate() + 1);
        }
        return dates;
    };

    const datesList = getDatesForEvent();

    return (
        <div className={`ems-split ems-planning-split ${rosterCollapsed ? 'ems-planning-split--collapsed-roster' : ''}`}>
            
            {/* Left Column — Available Volunteers */}
            <div className="ems-split__left ems-planning-split__left">
                <h3 className="ems-section-heading ems-mb-16">Available Volunteers</h3>
                
                <div className="ems-toolbar ems-planning-toolbar ems-mb-12">
                    <div className="ems-toolbar__group">
                        <label className="ems-toolbar__label" htmlFor="vol-role-filter">Role Filter</label>
                        <select
                            id="vol-role-filter"
                            className="ems-select-sm"
                            value={roleFilter}
                            onChange={e => setRoleFilter(e.target.value)}
                        >
                            <option value="all">All Roles</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="assessor">Assessor</option>
                            <option value="leader">Leader</option>
                        </select>
                    </div>
                </div>

                <div className="ems-roster-list" style={{ maxHeight: '600px', overflowY: 'auto' }}>
                    {availableVolunteers.map(v => {
                        const eventShifts = v.availability.filter((s: any) => s.expedition_post_id === event.ID);
                        const signupType = eventShifts[0]?.signup_type || 'part';
                        return (
                            <div key={v.id} className="ems-roster-item" style={{ padding: '12px', borderBottom: '1px solid #eee' }}>
                                <div className="ems-flex-between">
                                    <div>
                                        <strong style={{ fontSize: '14px' }}>{v.first_name} {v.last_name}</strong>
                                        <div style={{ fontSize: '12px', color: '#666', marginTop: '2px' }}>
                                            Roles: {v.preferred_roles?.join(', ') || 'None'}
                                        </div>
                                        <div style={{ fontSize: '11px', color: '#888', marginTop: '2px' }}>
                                            FA: {v.qualifications?.first_aid || 'None'} | Commitment: {signupType === 'whole' ? 'Whole' : 'Partial'}
                                        </div>
                                    </div>
                                    <button 
                                        type="button"
                                        className="button button-small button-primary" 
                                        onClick={() => handleAssign(v.id, 1)}
                                    >
                                        Assign
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                    {availableVolunteers.length === 0 && (
                        <p className="ems-empty-italic" style={{ padding: '12px' }}>No available volunteers matching filter.</p>
                    )}
                </div>
            </div>

            {/* Right Column — Assigned & Shifts Grid */}
            <div className="ems-split__right ems-planning-split__right">
                <div className="ems-flex-between ems-mb-16" style={{ alignItems: 'center' }}>
                    <h3 className="ems-section-heading ems-m-0">Assigned Volunteers</h3>
                    <button
                        type="button"
                        className="button button-secondary"
                        onClick={() => setRosterCollapsed(prev => !prev)}
                    >
                        {rosterCollapsed ? 'Show Available Volunteers' : 'Hide Available Volunteers'}
                    </button>
                </div>

                <div className="ems-grid ems-grid--2 ems-mb-16">
                    <div className="ems-panel" style={{ padding: '12px', background: '#f9f9f9', borderLeft: '4px solid #00a0d2' }}>
                        <div style={{ fontWeight: 600, fontSize: '13px', color: '#444' }}>Supervisors Check</div>
                        <div style={{ fontSize: '20px', fontWeight: 'bold', marginTop: '4px', color: assignedVolunteers.filter(v => v.preferred_roles?.includes('supervisor')).length >= 2 ? '#46b450' : '#dc3232' }}>
                            {assignedVolunteers.filter(v => v.preferred_roles?.includes('supervisor')).length} / 2
                        </div>
                    </div>
                    <div className="ems-panel" style={{ padding: '12px', background: '#f9f9f9', borderLeft: '4px solid #00a0d2' }}>
                        <div style={{ fontWeight: 600, fontSize: '13px', color: '#444' }}>Assessors Check</div>
                        <div style={{ fontSize: '20px', fontWeight: 'bold', marginTop: '4px', color: assignedVolunteers.some(v => v.preferred_roles?.includes('assessor')) ? '#46b450' : '#dc3232' }}>
                            {assignedVolunteers.filter(v => v.preferred_roles?.includes('assessor')).length} / 1
                        </div>
                    </div>
                </div>

                <div className="ems-planning-grid ems-mb-24">
                    {assignedVolunteers.map(v => {
                        const eventShifts = v.availability.filter((s: any) => s.expedition_post_id === event.ID);
                        const signupType = eventShifts[0]?.signup_type || 'part';
                        return (
                            <div key={v.id} className="ems-planning-card" style={{ padding: '12px' }}>
                                <div className="ems-flex-between ems-mb-8">
                                    <strong style={{ fontSize: '15px' }}>{v.first_name} {v.last_name}</strong>
                                    <button 
                                        type="button" 
                                        className="button button-small" 
                                        style={{ border: '1px solid #ccc', color: '#d63638' }}
                                        onClick={() => handleAssign(v.id, 0)}
                                    >
                                        Remove
                                    </button>
                                </div>
                                <div style={{ fontSize: '13px', margin: '4px 0' }}>
                                    <strong>Roles:</strong> {v.preferred_roles?.join(', ')}
                                </div>
                                <div style={{ fontSize: '12px', color: '#666' }}>
                                    <strong>First Aid:</strong> {v.qualifications?.first_aid || 'None'}
                                </div>
                                <div style={{ fontSize: '12px', color: '#666' }}>
                                    <strong>Commitment:</strong> {signupType === 'whole' ? 'Whole Event' : 'Partial'}
                                </div>
                            </div>
                        );
                    })}
                    {assignedVolunteers.length === 0 && (
                        <div className="ems-empty" style={{ gridColumn: 'span 2' }}>
                            No volunteers assigned to this event yet.
                        </div>
                    )}
                </div>

                {/* Event Schedule / Shifts Grid */}
                <div className="ems-panel">
                    <h4 className="ems-panel-title" style={{ padding: '12px 16px', margin: 0, borderBottom: '1px solid #eee' }}>Shifts Schedule Grid</h4>
                    {datesList.length === 0 ? (
                        <p className="ems-empty-italic" style={{ padding: '16px' }}>No event dates defined.</p>
                    ) : (
                        <table className="ems-table ems-mt-0">
                            <thead>
                                <tr>
                                    <th>Volunteer</th>
                                    {datesList.map((d, idx) => (
                                        <React.Fragment key={d}>
                                            <th className="ems-schedule-th" style={{ fontSize: '10px' }}>
                                                {d}<br/><span className="ems-schedule-sub">(Day)</span>
                                            </th>
                                            {idx < datesList.length - 1 && (
                                                <th className="ems-schedule-th ems-schedule--night" style={{ fontSize: '10px' }}>
                                                    {d}<br/><span className="ems-schedule-sub">(Night)</span>
                                                </th>
                                            )}
                                        </React.Fragment>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {volunteers.map(v => {
                                    const eventShifts = v.availability.filter((s: any) => s.expedition_post_id === event.ID);
                                    if (eventShifts.length === 0) return null;

                                    const signupType = eventShifts[0]?.signup_type || 'part';

                                    return (
                                        <tr key={v.id}>
                                            <td>
                                                <strong>{v.first_name} {v.last_name}</strong>
                                                <div className="ems-meta-text">
                                                    {v.preferred_roles?.join(', ')}
                                                </div>
                                            </td>
                                            {datesList.map((d, idx) => {
                                                const dayShift = eventShifts.find((s: any) => s.date === d && s.overnight === 0);
                                                const nightShift = idx < datesList.length - 1 ? eventShifts.find((s: any) => s.date === d && s.overnight === 1) : null;

                                                const renderCell = (shift: any) => {
                                                    if (!shift) return <span className="ems-schedule-status-nil">—</span>;
                                                    if (shift.confirmed === 1) {
                                                        return <span className="ems-schedule-status-confirmed" style={{ color: '#46b450', fontWeight: 'bold' }}>✓ Confirmed</span>;
                                                    }
                                                    if (shift.confirmed === -1) {
                                                        const otherConfirmedShift = v.availability.find(
                                                            (s: any) => s.date === shift.date && s.overnight === shift.overnight && s.confirmed === 1
                                                        );
                                                        let conflictLabel = 'Conflicted';
                                                        if (otherConfirmedShift) {
                                                            const otherEvent = allEvents.find((e: any) => e.ID === otherConfirmedShift.expedition_post_id);
                                                            if (otherEvent) {
                                                                conflictLabel = `Conflict: ${otherEvent.ems_event_code}`;
                                                            }
                                                        }
                                                        return <span className="ems-schedule-status-conflict" style={{ color: '#dc3232' }} title={conflictLabel}>{conflictLabel}</span>;
                                                    }
                                                    return <span className="ems-schedule-status-pending" style={{ color: '#ffb900' }}>Pending</span>;
                                                };

                                                return (
                                                    <React.Fragment key={d}>
                                                        <td className="ems-schedule-td">{renderCell(dayShift)}</td>
                                                        {idx < datesList.length - 1 && (
                                                            <td className="ems-schedule-td ems-schedule--night">{renderCell(nightShift)}</td>
                                                        )}
                                                    </React.Fragment>
                                                );
                                            })}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </div>
    );
};

/* Main */
export const EventDetailPage: React.FC<EventDetailPageProps> = ({
    event: initialEvent,
    onBack,
    explorers = [],
    osmEvents = [],
    allEvents = [],
    onEventUpdated,
    initialEdit = false,
}) => {
    const config = window.emsExpeditionBoard;
    const [activeTab, setActiveTab] = useState<DetailTab>('overview');
    const [event, setEvent] = useState<Expedition>(initialEvent);
    const [activeAsnScoutId, setActiveAsnScoutId] = useState<number | null>(null);

    useEffect(() => { setEvent(initialEvent); }, [initialEvent]);

    const handleUpdated = (updated: Expedition) => {
        setEvent(updated);
        onEventUpdated?.(updated);
    };

    const handleArchiveEventDetail = async () => {
        const isArchive = event.ems_status !== 'archived';
        const msg = isArchive 
            ? 'Are you sure you want to archive this event? It will be hidden from the dashboard unless "Show Archived Events" is toggled.'
            : 'Are you sure you want to restore this event?';
            
        if (!window.confirm(msg)) return;
        
        try {
            const res = await apiFetch(`/events/${event.ID}`, {
                method: 'PATCH',
                body: JSON.stringify({ ems_status: isArchive ? 'archived' : 'active' })
            });
            if (res.ok) {
                const u = await res.json();
                handleUpdated(u);
            }
        } catch (e) {
            console.error(e);
        }
    };

    const refreshTeams = async () => {
        try {
            const res = await fetch(`${config.root_url}/events/${event.ID}/teams`, { headers: { 'X-WP-Nonce': config.nonce } });
            if (res.ok) {
                const data = await res.json();
                handleUpdated({ ...event, teams: data });
            }
        } catch (e) {
            console.error(e);
        }
    };

    const tabBtn = (tab: DetailTab, label: string, id: string) => (
        <button id={id} type="button" className={`nav-tab ${activeTab === tab ? 'nav-tab-active' : ''}`} onClick={() => setActiveTab(tab)}>{label}</button>
    );

    const visibleTeams = (event.teams ?? []).filter(t => t.ems_team_code !== 'UNALLOCATED' || (t.members ?? []).length > 0);

    return (
        <div className="ems-event-detail">
            <div className="ems-event-detail__toolbar">
                <button id="ems-back-to-events" type="button" className="button-link ems-event-detail__back" onClick={onBack}>← Back to Events</button>

                <button
                    type="button"
                    className={`button ${event.ems_status === 'archived' ? 'ems-event-detail__archive--archived' : 'ems-event-detail__archive'}`}
                    onClick={handleArchiveEventDetail}
                >
                    {event.ems_status === 'archived' ? 'Restore Event' : 'Archive Event'}
                </button>
            </div>

            <div className="ems-event-detail__header">
                <div style={{ display: 'flex', alignItems: 'center', gap: '16px' }}>
                    {event.ems_level && ['bronze', 'silver', 'gold'].includes(event.ems_level.toLowerCase()) && (
                        <img
                            src={`${config.plugin_url}assets/images/dofe_logo_${event.ems_level.toLowerCase()}.png`}
                            alt={`${event.ems_level} Award Logo`}
                            style={{ width: '48px', height: '48px', objectFit: 'contain' }}
                        />
                    )}
                    <div>
                        <h1 className="ems-event-detail__title">{event.post_title || event.ems_event_code}</h1>
                        <div className="ems-event-detail__meta" style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '8px' }}>
                            <code className="ems-event-detail__code">{event.ems_event_code}</code>
                            {statusBadge(event.ems_status)}
                            <span className="ems-event-detail__date">{formatDate(event.ems_start_date)} – {formatDate(event.ems_end_date)}</span>
                            <span className={`ems-pill ems-pill--${event.ems_type?.toLowerCase()}`}>{event.ems_type}</span>
                            <span className="ems-pill ems-pill--transport">{transportLabel(event.ems_transport)}</span>
                        </div>
                    </div>
                </div>
                <div className="ems-event-detail__stats">
                    <div>{visibleTeams.filter(t => t.ems_team_code !== 'UNALLOCATED').length} team{visibleTeams.filter(t => t.ems_team_code !== 'UNALLOCATED').length !== 1 ? 's' : ''}</div>
                    <div>{event.member_count ?? 0} members</div>
                </div>
            </div>

            <nav className="nav-tab-wrapper ems-event-detail__tabs">
                {tabBtn('overview', 'Overview', 'ems-detail-tab-overview')}
                {tabBtn('teams', 'Teams', 'ems-detail-tab-teams')}
                {tabBtn('route', 'Route', 'ems-detail-tab-route')}
                {tabBtn('training', 'Training', 'ems-detail-tab-training')}
                {tabBtn('asn', 'Support Needs', 'ems-detail-tab-asn')}
                {tabBtn('volunteers', 'Volunteers', 'ems-detail-tab-volunteers')}
                {tabBtn('qrcodes', 'QR Codes', 'ems-detail-tab-qrcodes')}
            </nav>

            <div className="ems-event-detail__content">
                {activeTab === 'overview' && <OverviewTab event={event} osmEvents={osmEvents} onUpdated={handleUpdated} initialEdit={initialEdit} />}
                {activeTab === 'teams' && (
                    <EventPlanningBoard
                        event={{
                            id: event.ID,
                            event_code: event.ems_event_code,
                            title: event.post_title,
                            available_count: 0,
                            level: event.ems_level,
                            first_aid_level: event.ems_first_aid_level,
                        }}
                        onTeamChanged={refreshTeams}
                        onViewAsn={setActiveAsnScoutId}
                    />
                )}
                {activeTab === 'route' && <RouteTab event={event} />}
                {activeTab === 'training' && <TrainingTab eventId={event.ID} />}
                {activeTab === 'asn' && <ASNTab eventId={event.ID} onTeamChanged={refreshTeams} />}
                {activeTab === 'volunteers' && <VolunteersTab event={event} allEvents={allEvents} />}
                {activeTab === 'qrcodes' && <QRCodesTab event={event} />}
            </div>

            {/* Secure ASN Drawer Modal */}
            {activeAsnScoutId !== null && (
                <ASNDrawer
                    scoutId={activeAsnScoutId}
                    onClose={() => setActiveAsnScoutId(null)}
                    onSaved={refreshTeams}
                />
            )}
        </div>
    );
};

export default EventDetailPage;
