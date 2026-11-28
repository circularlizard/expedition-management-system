import React, { useState, useEffect, useCallback } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faPersonHiking,
    faBicycle,
    faWater,
    faUsers,
    faUser,
    faLock
} from '@fortawesome/free-solid-svg-icons';
import { Expedition, Team, Member, Explorer, FirstAidLevel, OSMEvent, ExpeditionRouteDay, RouteWaypoint } from './types';
import { EventForm } from './EventForm';
import { OSMReadOnlyMap } from './OSMReadOnlyMap';
import { EventRosterPanel } from '../volunteers/EventRosterPanel';
import { RichTextEditor } from './RichTextEditor';
import { OSMMapPicker, ActiveWaypointTarget } from './OSMMapPicker';
import { RouteDeadlineInput, formatRouteDeadlineWithOffset } from './RouteDeadlineInput';

interface EventDetailPageProps {
    event: Expedition;
    onBack: () => void;
    osmEvents?: OSMEvent[];
    allEvents?: Expedition[];
    lastSync?: string | null;
    onEventUpdated?: (updated: Expedition) => void;
    initialEdit?: boolean;
    onOpenTeamBuilder?: (opts: { level?: string; transport?: string; view?: 'team-builder' | 'date-grid' }) => void;
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

function transportLabel(t?: string): React.ReactNode {
    if (t === 'hillwalking') {
        return <><FontAwesomeIcon icon={faPersonHiking} className="ems-pill-icon" /> Hillwalking</>;
    }
    if (t === 'biking') {
        return <><FontAwesomeIcon icon={faBicycle} className="ems-pill-icon" /> Biking</>;
    }
    if (t === 'paddling') {
        return <><FontAwesomeIcon icon={faWater} className="ems-pill-icon" /> Paddling</>;
    }
    return t || '—';
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

function formatDuration(startStr?: string, endStr?: string): string {
    if (!startStr || !endStr) return '—';
    const s = new Date(`${startStr}T00:00:00`);
    const e = new Date(`${endStr}T00:00:00`);
    if (Number.isNaN(s.getTime()) || Number.isNaN(e.getTime()) || e < s) return '—';
    const nights = Math.round((e.getTime() - s.getTime()) / (1000 * 60 * 60 * 24));
    const days = nights + 1;
    return `${days} day${days === 1 ? '' : 's'} / ${nights} night${nights === 1 ? '' : 's'}`;
}

function parseLegacyCoords(val?: string): { lat: number | null; lng: number | null } {
    if (!val) return { lat: null, lng: null };
    const m = val.trim().match(/^(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)$/);
    if (!m) return { lat: null, lng: null };
    return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
}

function formatCoords(lat: number | null, lng: number | null): string {
    if (lat === null || lng === null || Number.isNaN(lat) || Number.isNaN(lng)) return '—';
    return `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
}

function makeWaypoint(id: string, label: string, lat: number | null = null, lng: number | null = null): RouteWaypoint {
    return { id, label, lat, lng, notes: '' };
}

function seedRouteDays(event: Expedition): ExpeditionRouteDay[] {
    if (Array.isArray(event.ems_route_days) && event.ems_route_days.length > 0) {
        return event.ems_route_days.map((d, idx) => ({
            day_number: d.day_number || idx + 1,
            date: d.date || '',
            start: { ...d.start },
            checkpoints: (d.checkpoints || []).map((cp) => ({ ...cp })),
            end: { ...d.end },
        }));
    }

    let dayCount = 1;
    let startDateObj: Date | null = null;
    if (event.ems_start_date && event.ems_end_date) {
        const s = new Date(`${event.ems_start_date}T00:00:00`);
        const e = new Date(`${event.ems_end_date}T00:00:00`);
        if (!Number.isNaN(s.getTime()) && !Number.isNaN(e.getTime()) && e >= s) {
            dayCount = Math.min(14, Math.max(1, Math.round((e.getTime() - s.getTime()) / (1000 * 60 * 60 * 24)) + 1));
            startDateObj = s;
        }
    }

    const legacyStart = parseLegacyCoords(event.ems_start_location);
    const legacyEnd = parseLegacyCoords(event.ems_end_location);

    const days: ExpeditionRouteDay[] = [];
    for (let i = 0; i < dayCount; i++) {
        const dayNum = i + 1;
        let dateStr = '';
        if (startDateObj) {
            const d = new Date(startDateObj.getTime() + i * 86400000);
            dateStr = d.toISOString().split('T')[0];
        }
        const isFirst = i === 0;
        const isLast = i === dayCount - 1;
        days.push({
            day_number: dayNum,
            date: dateStr,
            start: makeWaypoint(`day-${dayNum}-start`, 'Start', isFirst ? legacyStart.lat : null, isFirst ? legacyStart.lng : null),
            checkpoints: [],
            end: makeWaypoint(`day-${dayNum}-end`, 'End', isLast ? legacyEnd.lat : null, isLast ? legacyEnd.lng : null),
        });
    }
    return days;
}

/* Overview */
const OverviewTab: React.FC<{
    event: Expedition;
    osmEvents?: OSMEvent[];
    lastSync?: string | null;
    editing: boolean;
    onCancelEdit: () => void;
    onUpdated?: (e: Expedition) => void;
}> = ({ event, osmEvents = [], lastSync = null, editing, onCancelEdit, onUpdated }) => {
    const matchedOsm = osmEvents?.find(e => Number(e.event_id) === Number(event.ems_osm_event_id) || Number(e.id) === Number(event.ems_osm_event_id));
    const osmEventDisplay = matchedOsm ? matchedOsm.name : (event.ems_osm_event_id ? `#${event.ems_osm_event_id}` : 'Not linked');

    const allocatedTeams = (event.teams ?? []).filter(t => t.ems_team_code !== 'UNALLOCATED').length;
    const maxTeamsNum = event.ems_max_teams ? Number(event.ems_max_teams) : 0;
    const maxTeamsLabel = maxTeamsNum > 0 ? `${maxTeamsNum} Max` : 'Uncapped';

    const assignedVol = event.assigned_volunteers ?? 0;
    const reqVol = Number(event.ems_req_volunteers || 0);
    const assignedAssessor = event.assigned_assessors ?? 0;
    const reqAssessor = Number(event.ems_req_assessors || 0);
    const routesApproved = event.routes_approved ?? 0;

    return (
        <div>
            {editing ? (
                <EventForm
                    seasonId={0}
                    initialEvent={event}
                    osmEvents={osmEvents}
                    onSaved={(u) => {
                        onCancelEdit();
                        onUpdated?.(u);
                    }}
                    onCancel={onCancelEdit}
                />
            ) : (
                <>
                    {/* 4-Card Operational Summary Strip */}
                    <div className="ems-overview-summary-strip">
                        <div className="ems-summary-kpi">
                            <div className="ems-summary-kpi__label">Teams</div>
                            <div className="ems-summary-kpi__value">{allocatedTeams} / {maxTeamsLabel}</div>
                        </div>
                        <div className="ems-summary-kpi">
                            <div className="ems-summary-kpi__label">Staffing</div>
                            <div className="ems-summary-kpi__value">
                                Volunteers: {assignedVol}/{reqVol} · Assessors: {assignedAssessor}/{reqAssessor}
                            </div>
                        </div>
                        <div className="ems-summary-kpi">
                            <div className="ems-summary-kpi__label">Route Plans</div>
                            <div className="ems-summary-kpi__value">{routesApproved}/{allocatedTeams} Approved</div>
                            <div className="ems-summary-kpi__sub">
                                Deadline: {formatRouteDeadlineWithOffset(event.ems_route_deadline, event.ems_start_date, formatDate)}
                            </div>
                        </div>
                        <div className="ems-summary-kpi">
                            <div className="ems-summary-kpi__label">OSM Event</div>
                            <div className="ems-summary-kpi__value">
                                {event.ems_osm_event_id ? `#${event.ems_osm_event_id}` : 'Not linked'}
                            </div>
                            <div className="ems-summary-kpi__sub">
                                {event.ems_osm_event_id
                                    ? `Synced: ${lastSync ? new Date(lastSync).toLocaleString() : 'Never'}`
                                    : 'Link in Edit Event'}
                            </div>
                        </div>
                    </div>

                    <div className="ems-overview-cards">
                        <div className="ems-overview-card">
                            <h3 className="ems-overview-card__title">Expedition Summary</h3>
                            <div className={grd(2)}>
                                <FieldVal label="Type" value={capitalize(event.ems_type)} />
                                <FieldVal label="Level" value={capitalize(event.ems_level)} />
                                <FieldVal label="Transport" value={capitalize(event.ems_transport || '')} />
                                <FieldVal label="First Aid Required" value={FA_LABELS[event.ems_first_aid_level as FirstAidLevel] ?? event.ems_first_aid_level} />
                                <FieldVal label="Start Date & Time" value={`${formatDate(event.ems_start_date)}${event.ems_start_time ? ` · ${event.ems_start_time}` : ''}`} />
                                <FieldVal label="End Date & Time" value={`${formatDate(event.ems_end_date)}${event.ems_end_time ? ` · ${event.ems_end_time}` : ''}`} />
                                <FieldVal label="Duration" value={formatDuration(event.ems_start_date, event.ems_end_date)} />
                            </div>
                        </div>

                        <div className="ems-overview-card">
                            <h3 className="ems-overview-card__title">Leader in Charge</h3>
                            <div className={grd(2)}>
                                <FieldVal label="Name" value={event.ems_lic_name} />
                                <FieldVal label="Public Email" value={event.ems_lic_email} />
                                <FieldVal label="Public Phone" value={event.ems_lic_phone} />
                                <FieldVal label="Private Email" value={event.ems_lic_private_email} />
                                <FieldVal label="Private Phone" value={event.ems_lic_private_phone} />
                            </div>
                        </div>

                        <div className="ems-overview-card">
                            <h3 className="ems-overview-card__title">Requirements &amp; OSM Sync</h3>
                            <div className={grd(2)}>
                                <FieldVal label="Required Assessors" value={event.ems_req_assessors || '0'} />
                                <FieldVal label="Required Volunteers" value={event.ems_req_volunteers || '0'} />
                                <FieldVal label="Max Teams" value={maxTeamsNum > 0 ? String(maxTeamsNum) : 'Uncapped'} />
                                <FieldVal label="OSM Event" value={osmEventDisplay} />
                                <FieldVal label="Route Status" value={capitalize(event.ems_route_status || 'draft')} />
                            </div>
                        </div>

                        <div className="ems-overview-card">
                            <h3 className="ems-overview-card__title">Joining Instructions &amp; Notes</h3>
                            {event.ems_joining_instructions ? (
                                <div className="ems-joining-instructions-body">{event.ems_joining_instructions}</div>
                            ) : (
                                <p className="ems-meta-text ems-italic">No joining instructions added yet. Use Edit Event to add details.</p>
                            )}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
};

/* Route */
const RouteTab: React.FC<{ event: Expedition; onUpdated?: (e: Expedition) => void }> = ({ event, onUpdated }) => {
    const [editing, setEditing] = useState(false);
    const [saving, setSaving] = useState(false);
    const [days, setDays] = useState<ExpeditionRouteDay[]>(() => seedRouteDays(event));
    const [activeTarget, setActiveTarget] = useState<ActiveWaypointTarget | null>(null);
    const [formData, setFormData] = useState({
        ems_route_status: event.ems_route_status || 'draft',
        ems_route_deadline: event.ems_route_deadline || '',
        ems_route_info: event.ems_route_info || '',
    });

    useEffect(() => {
        if (!editing) {
            setDays(seedRouteDays(event));
            setActiveTarget(null);
            setFormData({
                ems_route_status: event.ems_route_status || 'draft',
                ems_route_deadline: event.ems_route_deadline || '',
                ems_route_info: event.ems_route_info || '',
            });
        }
    }, [event, editing]);

    const updateWaypoint = (
        dayIndex: number,
        kind: 'start' | 'checkpoint' | 'end',
        patch: Partial<RouteWaypoint>,
        checkpointIndex?: number
    ) => {
        setDays((prev) =>
            prev.map((d, idx) => {
                if (idx !== dayIndex) return d;
                if (kind === 'start') return { ...d, start: { ...d.start, ...patch } };
                if (kind === 'end') return { ...d, end: { ...d.end, ...patch } };
                const nextCps = d.checkpoints.map((cp, cIdx) =>
                    cIdx === checkpointIndex ? { ...cp, ...patch } : cp
                );
                return { ...d, checkpoints: nextCps };
            })
        );
    };

    const handleMapPick = (target: ActiveWaypointTarget, lat: number, lng: number) => {
        updateWaypoint(target.dayIndex, target.kind, { lat, lng }, target.checkpointIndex);
        setActiveTarget(null);
    };

    const handleAddCheckpoint = (dayIndex: number) => {
        let newCpIndex = 0;
        setDays((prev) =>
            prev.map((d, idx) => {
                if (idx !== dayIndex) return d;
                newCpIndex = d.checkpoints.length;
                const cpNum = newCpIndex + 1;
                return {
                    ...d,
                    checkpoints: [
                        ...d.checkpoints,
                        makeWaypoint(`cp-${d.day_number}-${cpNum}-${Date.now()}`, `Checkpoint ${cpNum}`),
                    ],
                };
            })
        );
        setActiveTarget({ dayIndex, kind: 'checkpoint', checkpointIndex: newCpIndex });
    };

    const handleRemoveCheckpoint = (dayIndex: number, cpIndex: number) => {
        setDays((prev) =>
            prev.map((d, idx) => {
                if (idx !== dayIndex) return d;
                return { ...d, checkpoints: d.checkpoints.filter((_, i) => i !== cpIndex) };
            })
        );
        setActiveTarget(null);
    };

    const handleAddDay = () => {
        setDays((prev) => {
            const nextNum = prev.length + 1;
            const lastDay = prev[prev.length - 1];
            let nextDate = '';
            if (lastDay?.date) {
                const d = new Date(`${lastDay.date}T00:00:00`);
                if (!Number.isNaN(d.getTime())) {
                    d.setDate(d.getDate() + 1);
                    nextDate = d.toISOString().split('T')[0];
                }
            }
            return [
                ...prev,
                {
                    day_number: nextNum,
                    date: nextDate,
                    start: makeWaypoint(`day-${nextNum}-start`, 'Start'),
                    checkpoints: [],
                    end: makeWaypoint(`day-${nextNum}-end`, 'End'),
                },
            ];
        });
    };

    const handleRemoveDay = (dayIndex: number) => {
        setDays((prev) => {
            if (prev.length <= 1) return prev;
            return prev
                .filter((_, idx) => idx !== dayIndex)
                .map((d, idx) => ({ ...d, day_number: idx + 1 }));
        });
        setActiveTarget(null);
    };

    const handleCopyPrevEnd = (dayIndex: number) => {
        if (dayIndex <= 0) return;
        const prevEnd = days[dayIndex - 1]?.end;
        if (!prevEnd) return;
        updateWaypoint(dayIndex, 'start', {
            label: prevEnd.label || `Day ${dayIndex} End`,
            lat: prevEnd.lat,
            lng: prevEnd.lng,
            notes: prevEnd.notes || '',
        });
    };

    const handleSave = async () => {
        setSaving(true);
        try {
            const firstStart = days[0]?.start;
            const lastEnd = days[days.length - 1]?.end;
            const derivedStart =
                firstStart && firstStart.lat !== null && firstStart.lng !== null
                    ? `${firstStart.lat.toFixed(6)}, ${firstStart.lng.toFixed(6)}`
                    : event.ems_start_location || '';
            const derivedEnd =
                lastEnd && lastEnd.lat !== null && lastEnd.lng !== null
                    ? `${lastEnd.lat.toFixed(6)}, ${lastEnd.lng.toFixed(6)}`
                    : event.ems_end_location || '';

            const res = await apiFetch(`/events/${event.ID}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    ...formData,
                    ems_route_days: days,
                    ems_start_location: derivedStart,
                    ems_end_location: derivedEnd,
                }),
            });
            if (res.ok) {
                const updated = await res.json();
                onUpdated?.(updated);
                setEditing(false);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setSaving(false);
        }
    };

    const readOnlyDays = Array.isArray(event.ems_route_days) && event.ems_route_days.length > 0
        ? event.ems_route_days
        : seedRouteDays(event);

    const renderCoordInputs = (
        wp: RouteWaypoint,
        dayIndex: number,
        kind: 'start' | 'checkpoint' | 'end',
        cpIndex?: number
    ) => {
        const isActive =
            activeTarget?.dayIndex === dayIndex &&
            activeTarget?.kind === kind &&
            (kind !== 'checkpoint' || activeTarget?.checkpointIndex === cpIndex);

        return (
            <div className="ems-route-wp-row">
                <input
                    type="text"
                    className="ems-form-input ems-route-wp-label"
                    aria-label={`Day ${days[dayIndex].day_number} ${kind === 'checkpoint' ? `CP${(cpIndex ?? 0) + 1}` : kind} label`}
                    value={wp.label}
                    onChange={(e) => updateWaypoint(dayIndex, kind, { label: e.target.value }, cpIndex)}
                    placeholder="Location name"
                />
                <input
                    type="number"
                    step="0.000001"
                    className="ems-form-input ems-route-wp-coord"
                    aria-label={`Day ${days[dayIndex].day_number} ${kind === 'checkpoint' ? `CP${(cpIndex ?? 0) + 1}` : kind} latitude`}
                    value={wp.lat ?? ''}
                    onChange={(e) =>
                        updateWaypoint(
                            dayIndex,
                            kind,
                            { lat: e.target.value === '' ? null : parseFloat(e.target.value) },
                            cpIndex
                        )
                    }
                    placeholder="Lat"
                />
                <input
                    type="number"
                    step="0.000001"
                    className="ems-form-input ems-route-wp-coord"
                    aria-label={`Day ${days[dayIndex].day_number} ${kind === 'checkpoint' ? `CP${(cpIndex ?? 0) + 1}` : kind} longitude`}
                    value={wp.lng ?? ''}
                    onChange={(e) =>
                        updateWaypoint(
                            dayIndex,
                            kind,
                            { lng: e.target.value === '' ? null : parseFloat(e.target.value) },
                            cpIndex
                        )
                    }
                    placeholder="Lng"
                />
                <input
                    type="text"
                    className="ems-form-input ems-route-wp-notes"
                    aria-label={`Day ${days[dayIndex].day_number} ${kind === 'checkpoint' ? `CP${(cpIndex ?? 0) + 1}` : kind} notes`}
                    value={wp.notes || ''}
                    onChange={(e) => updateWaypoint(dayIndex, kind, { notes: e.target.value }, cpIndex)}
                    placeholder="Notes (optional)"
                />
                <button
                    type="button"
                    className={`button button-small ${isActive ? 'button-primary' : ''}`}
                    onClick={() =>
                        setActiveTarget(
                            isActive ? null : { dayIndex, kind, checkpointIndex: cpIndex }
                        )
                    }
                >
                    {isActive ? 'Picking…' : 'Pick on Map'}
                </button>
                {kind === 'checkpoint' && cpIndex !== undefined && (
                    <button
                        type="button"
                        className="button-link ems-import-error"
                        onClick={() => handleRemoveCheckpoint(dayIndex, cpIndex)}
                    >
                        Remove
                    </button>
                )}
            </div>
        );
    };

    return (
        <div>
            <div className="ems-edit-bar">
                <button className="button" onClick={() => setEditing(v => !v)}>
                    {editing ? 'Cancel' : 'Edit Route'}
                </button>
            </div>

            {editing ? (
                <div className="ems-form-section">
                    <h3 className="ems-tab-section-title">Edit Route Details</h3>

                    <OSMMapPicker
                        days={days}
                        activeTarget={activeTarget}
                        onSelectTarget={setActiveTarget}
                        onMapPick={handleMapPick}
                        onAddCheckpoint={handleAddCheckpoint}
                    />

                    <div className="ems-route-days-editor ems-mt-16">
                        {days.map((day, dIdx) => (
                            <div key={day.day_number} className="ems-route-day-card">
                                <div className="ems-route-day-card__header">
                                    <div>
                                        <strong>Day {day.day_number}</strong>
                                        {day.date && <span className="ems-meta-text ems-ml-6">({formatDate(day.date)})</span>}
                                    </div>
                                    <div className="ems-route-day-card__actions">
                                        {dIdx > 0 && (
                                            <button
                                                type="button"
                                                className="button button-small"
                                                onClick={() => handleCopyPrevEnd(dIdx)}
                                            >
                                                Same as Day {day.day_number - 1} End
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            className="button button-small"
                                            onClick={() => handleAddCheckpoint(dIdx)}
                                        >
                                            + Add Checkpoint
                                        </button>
                                        {days.length > 1 && (
                                            <button
                                                type="button"
                                                className="button-link ems-import-error"
                                                onClick={() => handleRemoveDay(dIdx)}
                                            >
                                                Remove Day
                                            </button>
                                        )}
                                    </div>
                                </div>

                                <div className="ems-route-day-card__body">
                                    <div className="ems-route-wp-group">
                                        <div className="ems-route-wp-tag ems-route-wp-tag--start">Start</div>
                                        {renderCoordInputs(day.start, dIdx, 'start')}
                                    </div>

                                    {day.checkpoints.map((cp, cpIdx) => (
                                        <div key={cp.id || cpIdx} className="ems-route-wp-group">
                                            <div className="ems-route-wp-tag ems-route-wp-tag--cp">CP{cpIdx + 1}</div>
                                            {renderCoordInputs(cp, dIdx, 'checkpoint', cpIdx)}
                                        </div>
                                    ))}

                                    <div className="ems-route-wp-group">
                                        <div className="ems-route-wp-tag ems-route-wp-tag--end">End</div>
                                        {renderCoordInputs(day.end, dIdx, 'end')}
                                    </div>
                                </div>
                            </div>
                        ))}

                        <button type="button" className="button" onClick={handleAddDay}>
                            + Add Day
                        </button>
                    </div>

                    <div className="ems-form-grid-2 ems-mt-16">
                        <label className="ems-form-group">
                            <div className="ems-form-label">Route Planning Status</div>
                            <select
                                value={formData.ems_route_status}
                                onChange={e => setFormData(prev => ({ ...prev, ems_route_status: e.target.value }))}
                                className="ems-form-input"
                            >
                                <option value="draft">Draft</option>
                                <option value="confirmed">Confirmed</option>
                            </select>
                        </label>
                        <RouteDeadlineInput
                            startDate={event.ems_start_date}
                            deadline={formData.ems_route_deadline}
                            onChange={(nextDeadline) =>
                                setFormData((prev) => ({ ...prev, ems_route_deadline: nextDeadline }))
                            }
                        />
                    </div>

                    <label className="ems-form-group ems-mt-16">
                        <div className="ems-form-label">Route Information</div>
                        <RichTextEditor
                            value={formData.ems_route_info}
                            ariaLabel="Notes"
                            onChange={value => setFormData(prev => ({ ...prev, ems_route_info: value }))}
                        />
                    </label>

                    <div className="ems-form-actions ems-mt-16">
                        <button type="button" className="button button-primary" onClick={handleSave} disabled={saving}>
                            {saving ? 'Saving…' : 'Save Route Details'}
                        </button>
                    </div>
                </div>
            ) : (
                <div className="ems-tab-section">
                    <div className="ems-form-grid-3 ems-mb-16">
                        <FieldVal label="Route Status" value={capitalize(event.ems_route_status || 'draft')} />
                        <FieldVal
                            label="Route Deadline"
                            value={formatRouteDeadlineWithOffset(event.ems_route_deadline, event.ems_start_date, formatDate)}
                        />
                        <FieldVal label="Days Planned" value={`${readOnlyDays.length} day${readOnlyDays.length === 1 ? '' : 's'}`} />
                    </div>

                    {event.ems_route_info ? (
                        <div className="ems-mb-16">
                            <h4 className="ems-tab-section-title">Route Description</h4>
                            <div
                                className="ems-rte-readonly ems-rte-readonly__content"
                                dangerouslySetInnerHTML={{ __html: event.ems_route_info }}
                            />
                        </div>
                    ) : (
                        <p className="ems-meta-text ems-italic ems-mb-16">No route description uploaded yet.</p>
                    )}

                    <OSMReadOnlyMap
                        days={readOnlyDays}
                        startLocation={event.ems_start_location}
                        endLocation={event.ems_end_location}
                    />

                    <div className="ems-route-itinerary ems-mt-16">
                        <h4 className="ems-tab-section-title">Day-by-Day Itinerary</h4>
                        <table className="widefat striped ems-route-itinerary__table">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Start</th>
                                    <th>Intermediate Checkpoints</th>
                                    <th>End</th>
                                </tr>
                            </thead>
                            <tbody>
                                {readOnlyDays.map((day) => (
                                    <tr key={day.day_number}>
                                        <td>
                                            <strong>Day {day.day_number}</strong>
                                            {day.date && <div className="ems-meta-text">{formatDate(day.date)}</div>}
                                        </td>
                                        <td>
                                            <div className="ems-route-itinerary__label">{day.start.label || 'Start'}</div>
                                            <div className="ems-route-itinerary__coords">{formatCoords(day.start.lat, day.start.lng)}</div>
                                            {day.start.notes && <div className="ems-route-itinerary__notes">{day.start.notes}</div>}
                                        </td>
                                        <td>
                                            {day.checkpoints.length === 0 ? (
                                                <span className="ems-meta-text">None</span>
                                            ) : (
                                                <ul className="ems-route-itinerary__cp-list">
                                                    {day.checkpoints.map((cp, idx) => (
                                                        <li key={cp.id || idx}>
                                                            <strong>CP{idx + 1}:</strong> {cp.label || `Checkpoint ${idx + 1}`}{' '}
                                                            <span className="ems-route-itinerary__coords">({formatCoords(cp.lat, cp.lng)})</span>
                                                            {cp.notes && <div className="ems-route-itinerary__notes">{cp.notes}</div>}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </td>
                                        <td>
                                            <div className="ems-route-itinerary__label">{day.end.label || 'End'}</div>
                                            <div className="ems-route-itinerary__coords">{formatCoords(day.end.lat, day.end.lng)}</div>
                                            {day.end.notes && <div className="ems-route-itinerary__notes">{day.end.notes}</div>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
};

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
                                                    {isComplete ? 'Complete' : 'Incomplete'}
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
                    {explorers.map(exp => {
                        const noteVal = notes[exp.scout_id ?? 0] || '';
                        const isMultiline = noteVal.includes('\n') || noteVal.length > 60;
                        return (
                        <tr key={exp.scout_id}>
                            <td className="ems-asn-explorer-cell">
                                <div className="ems-asn-explorer-name">{exp.first_name} {exp.last_name}</div>
                                <div className="ems-asn-team">
                                    <span className="ems-asn-team-pill">
                                        {exp.teamName === 'UNALLOCATED' ? 'Unallocated' : exp.teamName}
                                    </span>
                                </div>
                            </td>
                            <td className={`ems-asn-parent-note ${parentAsn[exp.scout_id] ? 'ems-asn-parent-note--has' : 'ems-asn-parent-note--empty'}`}>
                                {parentAsn[exp.scout_id] || '— No support needs declared by parent —'}
                            </td>
                            <td>
                                <textarea
                                    rows={1}
                                    className={`ems-asn-textarea${isMultiline ? ' ems-asn-textarea--multiline' : ''}`}
                                    aria-label={`Organiser notes for ${exp.first_name} ${exp.last_name}`}
                                    value={noteVal}
                                    onChange={(e) => setNotes(prev => ({ ...prev, [exp.scout_id ?? 0]: e.target.value }))}
                                    placeholder="Enter private organiser notes here (e.g. medication details, actions needed)…"
                                />
                            </td>
                        </tr>
                        );
                    })}
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
                        <div className="ems-qr-item__title">Explorers Group</div>
                        <a href={explorerLink} target="_blank" rel="noopener noreferrer" className="ems-qr-item__link">Open link ↗</a>
                        <img src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(explorerLink)}`} alt="Explorers WhatsApp QR Code" className="ems-qr-item__image" />
                    </div>
                )}
                {parentLink && (
                    <div className="ems-qr-item">
                        <div className="ems-qr-item__title">Parents Group</div>
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
                <span className="ems-asn-drawer__title">
                    <FontAwesomeIcon icon={faLock} className="ems-btn-icon" /> Support Needs (Confidential)
                </span>
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

    return (
        <div style={{ maxWidth: '800px', margin: '0 auto' }}>
            <EventRosterPanel 
                selectedEvent={event}
                volunteers={volunteers}
                onAssign={async (volunteerId, eventId, confirmed) => {
                    await handleAssign(volunteerId, confirmed);
                }}
                rootUrl={config.root_url}
                nonce={config.nonce}
                allEvents={allEvents}
            />
        </div>
    );
};

/* Main */
export const EventDetailPage: React.FC<EventDetailPageProps> = ({
    event: initialEvent,
    onBack,
    osmEvents = [],
    allEvents = [],
    lastSync = null,
    onEventUpdated,
    initialEdit = false,
    onOpenTeamBuilder,
}) => {
    const config = window.emsExpeditionBoard;
    const [activeTab, setActiveTab] = useState<DetailTab>('overview');
    const [event, setEvent] = useState<Expedition>(initialEvent);
    const [editing, setEditing] = useState<boolean>(initialEdit);
    const [activeAsnScoutId, setActiveAsnScoutId] = useState<number | null>(null);

    useEffect(() => { setEvent(initialEvent); }, [initialEvent]);
    useEffect(() => { setEditing(initialEdit); }, [initialEdit, initialEvent.ID]);

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
            <div className={`ems-event-detail__header ems-event-detail__header--${event.ems_level?.toLowerCase()}`}>
                <div className="ems-event-detail__header-left">
                    <div className="ems-event-detail__back-row">
                        <button id="ems-back-to-events" type="button" className="button-link ems-event-detail__back" onClick={onBack}>← Expeditions</button>
                    </div>
                    <div className="ems-event-detail__header-main">
                        {event.ems_level && ['bronze', 'silver', 'gold'].includes(event.ems_level.toLowerCase()) && (
                            <img
                                src={`${config.plugin_url}assets/images/dofe_logo_${event.ems_level.toLowerCase()}.png`}
                                alt={`${event.ems_level} Award Logo`}
                                className="ems-event-detail__logo"
                            />
                        )}
                        <div>
                            <h1 className="ems-event-detail__title">{event.post_title || event.ems_event_code}</h1>
                            <div className="ems-event-detail__meta">
                                <code className="ems-event-detail__code">{event.ems_event_code}</code>
                                {statusBadge(event.ems_status)}
                                <span className="ems-event-detail__date">{formatDate(event.ems_start_date)} – {formatDate(event.ems_end_date)}</span>
                                <span className={`ems-pill ems-pill--${event.ems_type?.toLowerCase()}`}>{event.ems_type}</span>
                                <span className="ems-pill ems-pill--transport">{transportLabel(event.ems_transport)}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div className="ems-event-detail__stats-pills">
                    <div className="ems-event-detail__stat-pill">
                        <FontAwesomeIcon icon={faUsers} className="ems-btn-icon" /> {visibleTeams.filter(t => t.ems_team_code !== 'UNALLOCATED').length} Team{visibleTeams.filter(t => t.ems_team_code !== 'UNALLOCATED').length !== 1 ? 's' : ''}
                    </div>
                    <div className="ems-event-detail__stat-pill">
                        <FontAwesomeIcon icon={faUser} className="ems-btn-icon" /> {event.member_count ?? 0} Member{ (event.member_count ?? 0) !== 1 ? 's' : ''}
                        {Boolean(event.pool_count) && (
                            <span className="ems-meta-text"> ({event.pool_count} pool)</span>
                        )}
                    </div>
                    {!editing && (
                        <button
                            type="button"
                            className="button button-primary"
                            onClick={() => {
                                setActiveTab('overview');
                                setEditing(true);
                            }}
                        >
                            Edit Event
                        </button>
                    )}
                    <button
                        type="button"
                        className={`button ${event.ems_status === 'archived' ? 'ems-event-detail__archive--archived' : 'ems-event-detail__archive'}`}
                        onClick={handleArchiveEventDetail}
                    >
                        {event.ems_status === 'archived' ? 'Restore Event' : 'Archive Event'}
                    </button>
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
                {activeTab === 'overview' && (
                    <OverviewTab
                        event={event}
                        osmEvents={osmEvents}
                        lastSync={lastSync}
                        editing={editing}
                        onCancelEdit={() => setEditing(false)}
                        onUpdated={handleUpdated}
                    />
                )}
                {activeTab === 'teams' && (
                    <div className="ems-tab-section">
                        <div className="ems-flex-between ems-wrap ems-gap-8 ems-mb-16">
                            <div>
                                <h3 className="ems-tab-section-title" style={{ marginBottom: '4px' }}>
                                    Allocated Event Teams
                                </h3>
                                <p className="ems-meta-text" style={{ margin: 0 }}>
                                    Team creation, explorer allocation, availability scoring, and Place Offer emails are managed in the unified Team Builder.
                                </p>
                            </div>
                            {onOpenTeamBuilder && (
                                <div className="ems-flex-center ems-gap-8">
                                    <button
                                        type="button"
                                        className="button button-primary"
                                        onClick={() =>
                                            onOpenTeamBuilder({
                                                level: event.ems_level,
                                                transport: event.ems_transport,
                                                view: 'team-builder',
                                            })
                                        }
                                    >
                                        Open in Team Builder →
                                    </button>
                                    <button
                                        type="button"
                                        className="button"
                                        onClick={() =>
                                            onOpenTeamBuilder({
                                                level: event.ems_level,
                                                transport: event.ems_transport,
                                                view: 'date-grid',
                                            })
                                        }
                                    >
                                        Availability &amp; Date Grid →
                                    </button>
                                </div>
                            )}
                        </div>

                        {visibleTeams.filter((t) => t.ems_team_code !== 'UNALLOCATED').length === 0 ? (
                            <div className="notice notice-info">
                                <p>No teams have been allocated to this event yet.</p>
                            </div>
                        ) : (
                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: '12px' }}>
                                {visibleTeams
                                    .filter((t) => t.ems_team_code !== 'UNALLOCATED')
                                    .map((team) => {
                                        const members = team.members ?? [];
                                        return (
                                            <div key={team.ID} className="ems-panel ems-p-16">
                                                <div className="ems-flex-between ems-mb-8">
                                                    <strong>{team.ems_team_code || team.post_title}</strong>
                                                    <span className="ems-badge">
                                                        {members.length} member{members.length === 1 ? '' : 's'}
                                                    </span>
                                                </div>
                                                {members.length === 0 ? (
                                                    <p className="ems-meta-text" style={{ margin: 0 }}>No members yet.</p>
                                                ) : (
                                                    <ul style={{ margin: 0, paddingLeft: '18px', fontSize: '13px' }}>
                                                        {members.map((m, mIdx) => (
                                                            <li key={m.id || m.scout_id || mIdx}>
                                                                {`${m.first_name || ''} ${m.last_name || ''}`.trim() || `Scout #${m.scout_id ?? m.user_id}`}
                                                                {m.first_aid_level && m.first_aid_level !== 'none' && (
                                                                    <span className="ems-meta-text"> ({FA_LABELS[m.first_aid_level] || m.first_aid_level})</span>
                                                                )}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </div>
                                        );
                                    })}
                            </div>
                        )}
                    </div>
                )}
                {activeTab === 'route' && <RouteTab event={event} onUpdated={handleUpdated} />}
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
