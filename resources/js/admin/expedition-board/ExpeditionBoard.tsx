import React, { useState } from 'react';
import { useBoard } from './useBoard';
import { useOSMEvents } from './useOSMEvents';
import { SeasonDashboard } from './SeasonDashboard';
import { ExpeditionView } from './ExpeditionView';
import { OSMReference } from './OSMReference';
import { SeasonForm } from './SeasonForm';

type BoardTab = 'dashboard' | 'expedition-view' | 'reference';

const ExpeditionBoard: React.FC = () => {
    const { data, loading, error, refetch } = useBoard();
    const { events: osmEvents, loading: osmEventsLoading } = useOSMEvents();
    const [activeTab, setActiveTab] = useState<BoardTab>('dashboard');
    const [showSeasonForm, setShowSeasonForm] = useState(false);

    if (loading) return <p>Loading board...</p>;
    if (error) return <div className="notice notice-error"><p>{error}</p></div>;
    if (!data || !Array.isArray(data.seasons)) {
        return (
            <div className="notice notice-error">
                <p>The expedition board could not be displayed: the server returned an unexpected response shape (no <code>seasons</code> data). Please reload, and if the problem persists check the REST endpoint <code>ems/v1/expedition-board</code>.</p>
            </div>
        );
    }

    const seasons = data.seasons;
    void seasons;

    return (
        <div className="ems-board">
            <div className="ems-board-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
                <span style={{ color: '#666', fontSize: '0.9em' }}>
                    Last synced with OSM: {data.last_sync ? new Date(data.last_sync).toLocaleString() : 'Never'}
                </span>
                <button
                    type="button"
                    className="button button-primary"
                    onClick={() => setShowSeasonForm((v) => !v)}
                >
                    {showSeasonForm ? 'Close' : 'Create Season'}
                </button>
            </div>

            {showSeasonForm && (
                <SeasonForm
                    onSaved={() => { setShowSeasonForm(false); refetch(); }}
                    onCancel={() => setShowSeasonForm(false)}
                />
            )}

            <nav className="nav-tab-wrapper">
                <button className={`nav-tab ${activeTab === 'dashboard' ? 'nav-tab-active' : ''}`} onClick={() => setActiveTab('dashboard')}>
                    Season Dashboard
                </button>
                <button className={`nav-tab ${activeTab === 'expedition-view' ? 'nav-tab-active' : ''}`} onClick={() => setActiveTab('expedition-view')}>
                    Expedition View
                </button>
                <button className={`nav-tab ${activeTab === 'reference' ? 'nav-tab-active' : ''}`} onClick={() => setActiveTab('reference')}>
                    OSM Reference
                </button>
            </nav>

            <div className="tab-content" style={{ marginTop: '20px' }}>
                {activeTab === 'dashboard' && <SeasonDashboard data={data} osmEvents={osmEvents} osmEventsLoading={osmEventsLoading} />}
                {activeTab === 'expedition-view' && <ExpeditionView data={data} />}
                {activeTab === 'reference' && <OSMReference data={data} onChanged={refetch} />}
            </div>
        </div>
    );
};

export default ExpeditionBoard;
