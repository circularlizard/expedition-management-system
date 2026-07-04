import React, { useState, useEffect, useCallback } from 'react';
import { Expedition, OSMEvent } from './types';
import { EventForm } from './EventForm';

interface EventsDashboardProps {
    onSelectEvent: (event: Expedition) => void;
    osmEvents?: OSMEvent[];
    osmEventsLoading?: boolean;
}

type DashboardTab = 'upcoming' | 'past';

const STATUS_COLORS: Record<string, { bg: string; color: string }> = {
    active:   { bg: '#d1fae5', color: '#065f46' },
    archived: { bg: '#f3f4f6', color: '#6b7280' },
    planning: { bg: '#e0f2fe', color: '#0369a1' },
    open:     { bg: '#d1fae5', color: '#065f46' },
    confirmed: { bg: '#c7d2fe', color: '#3730a3' },
    completed: { bg: '#f3f4f6', color: '#6b7280' },
    draft:    { bg: '#fef9c3', color: '#854d0e' },
};

function statusBadge(status?: string): React.ReactNode {
    const s = status || 'active';
    const c = STATUS_COLORS[s] || { bg: '#eee', color: '#555' };
    return (
        <span style={{
            display: 'inline-block',
            padding: '2px 10px',
            borderRadius: '12px',
            fontSize: '11px',
            fontWeight: 600,
            background: c.bg,
            color: c.color,
            textTransform: 'capitalize',
        }}>
            {s || 'active'}
        </span>
    );
}

function typePill(type?: string): React.ReactNode {
    const map: Record<string, string> = { training: '#e3f2fd', practice: '#e8f5e9', qualifying: '#f3e5f5' };
    const colorMap: Record<string, string> = { training: '#1565c0', practice: '#2e7d32', qualifying: '#7b1fa2' };
    const bg = map[type || ''] || '#eee';
    const color = colorMap[type || ''] || '#666';
    return (
        <span style={{ background: bg, color, padding: '2px 8px', borderRadius: '10px', fontSize: '11px', fontWeight: 600, textTransform: 'capitalize' }}>
            {type || '—'}
        </span>
    );
}

function levelPill(level?: string): React.ReactNode {
    const map: Record<string, { bg: string; color: string }> = {
        bronze: { bg: '#f0d4b8', color: '#7a4410' },
        silver: { bg: '#e0e0e0', color: '#444' },
        gold:   { bg: '#fff3cd', color: '#7a5c10' },
    };
    const c = map[level || ''] || { bg: '#eee', color: '#555' };
    return (
        <span style={{ background: c.bg, color: c.color, padding: '2px 8px', borderRadius: '10px', fontSize: '11px', fontWeight: 600, textTransform: 'capitalize' }}>
            {level || '—'}
        </span>
    );
}

function transportLabel(t?: string): string {
    const m: Record<string, string> = { hillwalking: '🥾 Hillwalking', biking: '🚴 Biking', paddling: '🚣 Paddling' };
    return m[t || ''] || (t || '—');
}

function formatDate(d?: string): string {
    if (!d) return '—';
    try { return new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); } catch { return d; }
}

async function fetchEvents(tab: DashboardTab, includeArchived: boolean, nonce: string, rootUrl: string): Promise<Expedition[]> {
    const params = new URLSearchParams({ tab });
    if (includeArchived) params.set('include_archived', '1');
    const resp = await fetch(`${rootUrl}/events?${params}`, {
        headers: { 'X-WP-Nonce': nonce },
    });
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const json = await resp.json();
    return (json.events ?? []) as Expedition[];
}

export const EventsDashboard: React.FC<EventsDashboardProps> = ({
    onSelectEvent,
    osmEvents = [],
    osmEventsLoading = false,
}) => {
    const config = window.emsExpeditionBoard;
    const [activeTab, setActiveTab] = useState<DashboardTab>('upcoming');
    const [includeArchived, setIncludeArchived] = useState(false);
    const [events, setEvents] = useState<Expedition[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [showCreateForm, setShowCreateForm] = useState(false);

    const load = useCallback(async (tab: DashboardTab, archived: boolean) => {
        setLoading(true);
        setError(null);
        try {
            const data = await fetchEvents(tab, archived, config.nonce, config.root_url);
            setEvents(data);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Failed to load events');
        } finally {
            setLoading(false);
        }
    }, [config.nonce, config.root_url]);

    useEffect(() => {
        load(activeTab, includeArchived);
    }, [activeTab, includeArchived, load]);

    const switchTab = (tab: DashboardTab) => {
        setActiveTab(tab);
        setShowCreateForm(false);
    };

    const onEventCreated = (savedEvent: Expedition) => {
        setShowCreateForm(false);
        load(activeTab, includeArchived);
        void savedEvent;
    };

    const tabStyle = (tab: DashboardTab): React.CSSProperties => ({
        padding: '8px 20px',
        border: 'none',
        borderBottom: activeTab === tab ? '3px solid #2271b1' : '3px solid transparent',
        background: 'none',
        color: activeTab === tab ? '#2271b1' : '#50575e',
        fontWeight: activeTab === tab ? 600 : 400,
        fontSize: '14px',
        cursor: 'pointer',
    });

    return (
        <div className="ems-events-dashboard">
            {/* Header */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                <h2 style={{ margin: 0, fontSize: '20px', color: '#1d2327' }}>Events</h2>
                <button
                    id="ems-create-event-btn"
                    type="button"
                    className="button button-primary"
                    onClick={() => setShowCreateForm((v) => !v)}
                >
                    {showCreateForm ? '✕ Close' : '+ Create Event'}
                </button>
            </div>

            {/* Inline create form */}
            {showCreateForm && (
                <div style={{ marginBottom: '20px', padding: '20px', background: '#f6f7f7', border: '1px solid #dcdcde', borderRadius: '4px' }}>
                    <h3 style={{ margin: '0 0 16px', fontSize: '16px' }}>New Event</h3>
                    <EventForm
                        seasonId={0}
                        osmEvents={osmEvents}
                        onSaved={onEventCreated}
                        onCancel={() => setShowCreateForm(false)}
                    />
                </div>
            )}

            {/* Tab bar */}
            <div style={{ display: 'flex', borderBottom: '1px solid #dcdcde', marginBottom: '16px', alignItems: 'center', gap: '0' }}>
                <button id="ems-tab-upcoming" style={tabStyle('upcoming')} onClick={() => switchTab('upcoming')}>
                    Upcoming Events
                </button>
                <button id="ems-tab-past" style={tabStyle('past')} onClick={() => switchTab('past')}>
                    Past Events
                </button>
                <label style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: '6px', fontSize: '13px', color: '#50575e', cursor: 'pointer' }}>
                    <input
                        id="ems-show-archived"
                        type="checkbox"
                        checked={includeArchived}
                        onChange={(e) => setIncludeArchived(e.target.checked)}
                    />
                    Show Archived Events
                </label>
            </div>

            {/* Content */}
            {loading && (
                <div style={{ textAlign: 'center', padding: '40px', color: '#50575e' }}>
                    <span className="spinner is-active" style={{ float: 'none', display: 'inline-block' }} />
                    <p style={{ marginTop: '8px' }}>Loading events…</p>
                </div>
            )}

            {error && (
                <div className="notice notice-error"><p>{error}</p></div>
            )}

            {!loading && !error && events.length === 0 && (
                <div className="notice notice-info" style={{ marginTop: '10px' }}>
                    <p>No {activeTab} events found.{' '}
                        <button
                            type="button"
                            className="button-link"
                            onClick={() => setShowCreateForm(true)}
                        >
                            Create one now.
                        </button>
                    </p>
                </div>
            )}

            {!loading && !error && events.length > 0 && (
                <table className="widefat striped" style={{ marginTop: 0 }}>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Transport</th>
                            <th>Level</th>
                            <th>Dates</th>
                            <th style={{ textAlign: 'center' }}>Teams</th>
                            <th style={{ textAlign: 'center' }}>Members</th>
                            <th>Route Status</th>
                            <th style={{ textAlign: 'right' }}>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {events.map((event) => (
                            <tr
                                key={event.ID}
                                style={{ cursor: 'pointer', opacity: event.ems_status === 'archived' ? 0.6 : 1 }}
                                onClick={() => onSelectEvent(event)}
                            >
                                <td>
                                    <a
                                        href="#"
                                        style={{ fontWeight: 600, textDecoration: 'none', color: '#2271b1' }}
                                        onClick={(e) => { e.preventDefault(); onSelectEvent(event); }}
                                    >
                                        {event.post_title || event.ems_event_code}
                                        {event.ems_status === 'archived' && (
                                            <span style={{ fontSize: '11px', fontStyle: 'italic', color: '#666', marginLeft: '6px' }}>(Archived)</span>
                                        )}
                                    </a>
                                </td>
                                <td>
                                    <code style={{ background: '#f0f0f0', padding: '2px 6px', borderRadius: '3px', fontSize: '12px' }}>
                                        {event.ems_event_code}
                                    </code>
                                </td>
                                <td>{typePill(event.ems_type)}</td>
                                <td style={{ fontSize: '12px', whiteSpace: 'nowrap' }}>{transportLabel(event.ems_transport)}</td>
                                <td>{levelPill(event.ems_level)}</td>
                                <td style={{ fontSize: '12px', whiteSpace: 'nowrap' }}>
                                    {formatDate(event.ems_start_date)}
                                    {event.ems_end_date !== event.ems_start_date && (
                                        <> – {formatDate(event.ems_end_date)}</>
                                    )}
                                </td>
                                <td style={{ textAlign: 'center' }}>
                                    <span style={{ fontWeight: 600 }}>{(event.teams ?? []).length}</span>
                                </td>
                                <td style={{ textAlign: 'center' }}>
                                    <span>{event.member_count ?? 0}</span>
                                </td>
                                <td>{statusBadge(event.ems_route_status || 'draft')}</td>
                                <td style={{ textAlign: 'right' }}>
                                    <button
                                        type="button"
                                        className="button button-small"
                                        style={{ color: event.ems_status === 'archived' ? '#2271b1' : '#d63638' }}
                                        onClick={async (e) => {
                                            e.stopPropagation();
                                            const isArchive = event.ems_status !== 'archived';
                                            if (window.confirm(isArchive ? `Are you sure you want to archive this event?` : `Are you sure you want to restore this event?`)) {
                                                try {
                                                    const res = await fetch(`${config.root_url}/events/${event.ID}`, {
                                                        method: 'PATCH',
                                                        headers: {
                                                            'Content-Type': 'application/json',
                                                            'X-WP-Nonce': config.nonce,
                                                        },
                                                        body: JSON.stringify({ ems_status: isArchive ? 'archived' : 'active' }),
                                                    });
                                                    if (res.ok) {
                                                        load(activeTab, includeArchived);
                                                    }
                                                } catch (err) {
                                                    console.error(err);
                                                }
                                            }
                                        }}
                                    >
                                        {event.ems_status === 'archived' ? 'Restore' : 'Archive'}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {osmEventsLoading && <p style={{ marginTop: '8px', fontSize: '12px', color: '#aaa' }}>Loading OSM events…</p>}
        </div>
    );
};

export default EventsDashboard;
