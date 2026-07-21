import React, { useState, useEffect, useCallback } from 'react';
import { Expedition, OSMEvent } from './types';
import { EventForm } from './EventForm';

interface EventsDashboardProps {
    onSelectEvent: (event: Expedition) => void;
    onEditEvent?: (event: Expedition) => void;
    osmEvents?: OSMEvent[];
    osmEventsLoading?: boolean;
    onCreateEvent?: () => void;
}

type DashboardTab = 'upcoming' | 'past';

function statusBadge(status?: string): React.ReactNode {
    const s = status || 'active';
    return (
        <span className={`ems-status-badge ems-status-badge--${s.toLowerCase()}`}>
            {s}
        </span>
    );
}

function typePill(type?: string): React.ReactNode {
    const t = type || '';
    const className = t ? `ems-pill ems-pill--${t.toLowerCase()}` : 'ems-status-badge';
    return (
        <span className={className}>
            {type || '—'}
        </span>
    );
}

function levelPill(level?: string): React.ReactNode {
    const l = level || '';
    const className = l ? `ems-pill ems-pill--${l.toLowerCase()}` : 'ems-status-badge';
    return (
        <span className={className}>
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
    onEditEvent,
    osmEvents = [],
    osmEventsLoading = false,
    onCreateEvent,
}) => {
    const config = window.emsExpeditionBoard;
    const [activeTab, setActiveTab] = useState<DashboardTab>('upcoming');
    const [includeArchived, setIncludeArchived] = useState(false);
    const [events, setEvents] = useState<Expedition[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

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
    };

    const tabClass = (tab: DashboardTab): string =>
        `ems-tab-nav__button${activeTab === tab ? ' ems-tab-nav__button--active' : ''}`;

    return (
        <div className="ems-events-dashboard">
            {/* Header */}
            <div className="ems-flex-between ems-mb-16">
                <h2 className="ems-dashboard-title">Events</h2>
                <button
                    id="ems-create-event-btn"
                    type="button"
                    className="button button-primary"
                    onClick={onCreateEvent}
                >
                    + Create Event
                </button>
            </div>

            {/* Tab bar */}
            <div className="ems-tab-nav">
                <button id="ems-tab-upcoming" className={tabClass('upcoming')} onClick={() => switchTab('upcoming')}>
                    Upcoming Events
                </button>
                <button id="ems-tab-past" className={tabClass('past')} onClick={() => switchTab('past')}>
                    Past Events
                </button>
                <label className={`ems-tab-nav__checkbox-label ems-filter-pill ${includeArchived ? 'ems-filter-pill--active' : ''}`}>
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
                <div className="ems-loading-state">
                    <span className="spinner is-active" />
                    <p>Loading events…</p>
                </div>
            )}

            {error && (
                <div className="notice notice-error"><p>{error}</p></div>
            )}

            {!loading && !error && events.length === 0 && (
                <div className="notice notice-info ems-mt-10">
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
                <div className="ems-panel">
                    <table className="ems-table ems-mt-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th>Transport</th>
                                <th>Level</th>
                                <th>Dates</th>
                                <th className="ems-table-cell--center">Teams</th>
                                <th className="ems-table-cell--center">Members</th>
                                <th>Route Status</th>
                                <th className="ems-table-cell--right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.map((event) => (
                                <tr
                                    key={event.ID}
                                    className={`ems-row-hoverable${event.ems_status === 'archived' ? ' ems-table-row--archived' : ''}`}
                                    onClick={() => onSelectEvent(event)}
                                >
                                    <td>
                          <a
                                             href="#"
                                             className="ems-table__link"
                                             onClick={(e) => { e.preventDefault(); onSelectEvent(event); }}
                                         >
                                            {event.post_title || event.ems_event_code}
                                            {event.ems_status === 'archived' && (
                                                <span className="ems-meta-text ems-ml-6">(Archived)</span>
                                            )}
                                        </a>
                                    </td>
                                    <td>
                                        <code className="ems-code-badge">
                                            {event.ems_event_code}
                                        </code>
                                    </td>
                                    <td>{typePill(event.ems_type)}</td>
                                    <td className="ems-table-cell--small">{transportLabel(event.ems_transport)}</td>
                                    <td>{levelPill(event.ems_level)}</td>
                                    <td className="ems-table-cell--small">
                                        {formatDate(event.ems_start_date)}
                                        {event.ems_end_date !== event.ems_start_date && (
                                            <> – {formatDate(event.ems_end_date)}</>
                                        )}
                                    </td>
                                    <td className="ems-table-cell--center">
                                        <strong>{(event.teams ?? []).length}</strong>
                                    </td>
                                    <td className="ems-table-cell--center">
                                        {event.member_count ?? 0}
                                    </td>
                                    <td>{statusBadge(event.ems_route_status || 'draft')}</td>
                                    <td className="ems-table-cell--right">
                                        {onEditEvent && (
                                            <button
                                                type="button"
                                                className="button button-small ems-mr-8"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onEditEvent(event);
                                                }}
                                            >
                                                Edit
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            className={`button button-small ${event.ems_status === 'archived' ? '' : 'ems-btn-archive-red'}`}
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
                </div>
            )}

            {osmEventsLoading && <p className="ems-osm-loading">Loading OSM events…</p>}
        </div>
    );
};

export default EventsDashboard;
