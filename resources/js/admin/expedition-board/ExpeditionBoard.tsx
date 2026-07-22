import React, { useState, useEffect } from 'react';
import { useBoard } from './useBoard';
import { useOSMEvents } from './useOSMEvents';
import { EventsDashboard } from './EventsDashboard';
import { EventDetailPage } from './EventDetailPage';
import { Expedition } from './types';
import EventPlanningBoard from './EventPlanningBoard';
import { EventForm } from './EventForm';

type BoardTab = 'dashboard' | 'detail' | 'planning' | 'create';

const ExpeditionBoard: React.FC = () => {
    const { data, loading, error, refetch } = useBoard();
    const { events: osmEvents, loading: osmEventsLoading } = useOSMEvents();
    const [activeTab, setActiveTab] = useState<BoardTab>('dashboard');
    const [selectedEvent, setSelectedEvent] = useState<Expedition | null>(null);
    const [initialEdit, setInitialEdit] = useState(false);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const eventId = params.get('event_id');
        if (eventId && data?.seasons) {
            for (const season of data.seasons) {
                const event = season.events.find(e => e.ID === Number(eventId));
                if (event) {
                    setSelectedEvent(event);
                    setInitialEdit(false);
                    setActiveTab('detail');
                    break;
                }
            }
        }
    }, [data]);

    if (loading) return <p>Loading board…</p>;
    if (error) return <div className="notice notice-error"><p>{error}</p></div>;
    if (!data || !Array.isArray(data.seasons)) {
        return (
            <div className="notice notice-error">
                <p>The expedition board could not be displayed: the server returned an unexpected response shape (no <code>seasons</code> data). Please reload, and if the problem persists check the REST endpoint <code>ems/v1/expedition-board</code>.</p>
            </div>
        );
    }

    const explorers = data.explorers ?? [];

    const handleSelectEvent = (event: Expedition) => {
        setSelectedEvent(event);
        setInitialEdit(false);
        setActiveTab('detail');
    };

    const handleEditEvent = (event: Expedition) => {
        setSelectedEvent(event);
        setInitialEdit(true);
        setActiveTab('detail');
    };

    const handleBack = () => {
        setSelectedEvent(null);
        setInitialEdit(false);
        setActiveTab('dashboard');
    };

    const handleEventUpdated = (updated: Expedition) => {
        setSelectedEvent(updated);
        refetch();
    };

    return (
        <div className="ems-board">
            <div className="ems-board-header">
                <span className="ems-board-synced">
                    Last synced with OSM: {data.last_sync ? new Date(data.last_sync).toLocaleString() : 'Never'}
                </span>
            </div>

            {activeTab !== 'detail' && activeTab !== 'create' && (
                <nav className="nav-tab-wrapper">
                    <button
                        className={`nav-tab ${activeTab === 'dashboard' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('dashboard')}
                    >
                        Events Dashboard
                    </button>
                    <button
                        className={`nav-tab ${activeTab === 'planning' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('planning')}
                    >
                        Event Planning
                    </button>
                </nav>
            )}

            <div className={`tab-content ${(activeTab === 'detail' || activeTab === 'create') ? '' : 'ems-mt-20'}`}>
                {activeTab === 'dashboard' && (
                    <EventsDashboard
                        onSelectEvent={handleSelectEvent}
                        onEditEvent={handleEditEvent}
                        osmEvents={osmEvents}
                        osmEventsLoading={osmEventsLoading}
                        onCreateEvent={() => setActiveTab('create')}
                    />
                )}
                {activeTab === 'detail' && selectedEvent && (
                    <EventDetailPage
                        event={selectedEvent}
                        onBack={handleBack}
                        explorers={explorers}
                        osmEvents={osmEvents}
                        allEvents={data.seasons[0]?.events ?? []}
                        onEventUpdated={handleEventUpdated}
                        initialEdit={initialEdit}
                    />
                )}
                {activeTab === 'create' && (
                    <div className="ems-event-detail">
                        <div className="ems-event-detail__toolbar">
                            <button id="ems-back-to-events" type="button" className="button-link ems-event-detail__back" onClick={handleBack}>← Back to Events</button>
                        </div>
                        <div className="ems-event-detail__header">
                            <div>
                                <h1 className="ems-event-detail__title">Create Event</h1>
                            </div>
                        </div>
                        <div className="ems-event-detail__content">
                            <div className="ems-panel">
                                <EventForm
                                    seasonId={0}
                                    osmEvents={osmEvents}
                                    onSaved={(savedEvent) => {
                                        handleBack();
                                        refetch();
                                    }}
                                    onCancel={handleBack}
                                />
                            </div>
                        </div>
                    </div>
                )}
                {activeTab === 'planning' && (
                    <EventPlanningBoard />
                )}
            </div>
        </div>
    );
};

export default ExpeditionBoard;
