import React, { useState } from 'react';
import { useBoard } from './useBoard';
import { useOSMEvents } from './useOSMEvents';
import { EventsDashboard } from './EventsDashboard';
import { EventDetailPage } from './EventDetailPage';
import { ExpeditionView } from './ExpeditionView';
import { Expedition } from './types';
import EventPlanningBoard from './EventPlanningBoard';

type BoardTab = 'dashboard' | 'detail' | 'expedition-view' | 'planning';

const ExpeditionBoard: React.FC = () => {
    const { data, loading, error, refetch } = useBoard();
    const { events: osmEvents, loading: osmEventsLoading } = useOSMEvents();
    const [activeTab, setActiveTab] = useState<BoardTab>('dashboard');
    const [selectedEvent, setSelectedEvent] = useState<Expedition | null>(null);

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
        setActiveTab('detail');
    };

    const handleBack = () => {
        setSelectedEvent(null);
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

            {activeTab !== 'detail' && (
                <nav className="nav-tab-wrapper">
                    <button
                        className={`nav-tab ${activeTab === 'dashboard' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('dashboard')}
                    >
                        Events Dashboard
                    </button>
                    <button
                        className={`nav-tab ${activeTab === 'expedition-view' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('expedition-view')}
                    >
                        Expedition View
                    </button>
                    <button
                        className={`nav-tab ${activeTab === 'planning' ? 'nav-tab-active' : ''}`}
                        onClick={() => setActiveTab('planning')}
                    >
                        Event Planning
                    </button>
                </nav>
            )}

            <div className={`tab-content ${activeTab === 'detail' ? '' : 'ems-mt-20'}`}>
                {activeTab === 'dashboard' && (
                    <EventsDashboard
                        onSelectEvent={handleSelectEvent}
                        osmEvents={osmEvents}
                        osmEventsLoading={osmEventsLoading}
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
                    />
                )}
                {activeTab === 'expedition-view' && (
                    <ExpeditionView data={data} osmEvents={osmEvents} />
                )}
                {activeTab === 'planning' && (
                    <EventPlanningBoard />
                )}
            </div>
        </div>
    );
};

export default ExpeditionBoard;
