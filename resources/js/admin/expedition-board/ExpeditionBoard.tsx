import React, { useState } from 'react';
import { useBoard } from './useBoard';
import { Season } from './types';
import { SeasonDashboard } from './SeasonDashboard';
import { CrossEventTeamView } from './CrossEventTeamView';
import { ExplorerMovePanel } from './ExplorerMovePanel';
import { TeamMovePanel } from './TeamMovePanel';
import { SeasonForm } from './SeasonForm';

type BoardTab = 'dashboard' | 'cross-event' | 'move-explorer' | 'move-team';

const ExpeditionBoard: React.FC = () => {
    const { data, loading, error, refetch } = useBoard();
    const [activeTab, setActiveTab] = useState<BoardTab>('dashboard');
    const [activeSeasonId, setActiveSeasonId] = useState<number | null>(null);
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
    const activeSeason: Season | null =
        seasons.find((s) => s.ID === activeSeasonId) ?? seasons[0] ?? null;

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

            {seasons.length > 1 && (
                <label className="ems-season-picker" style={{ display: 'block', marginBottom: '16px' }}>
                    Season
                    <select
                        aria-label="Select season"
                        value={activeSeason?.ID ?? ''}
                        onChange={(e) => setActiveSeasonId(Number(e.target.value))}
                    >
                        {seasons.map((s) => (
                            <option key={s.ID} value={s.ID}>{s.post_title}</option>
                        ))}
                    </select>
                </label>
            )}

            <nav className="nav-tab-wrapper">
                <button className={`nav-tab ${activeTab === 'dashboard' ? 'nav-tab-active' : ''}`} onClick={() => setActiveTab('dashboard')}>
                    Season Dashboard
                </button>
                <button className={`nav-tab ${activeTab === 'cross-event' ? 'nav-tab-active' : ''}`} onClick={() => setActiveTab('cross-event')}>
                    Cross-Event View
                </button>
                <button className={`nav-tab ${activeTab === 'move-explorer' ? 'nav-tab-active' : ''}`} onClick={() => setActiveTab('move-explorer')}>
                    Move Explorer
                </button>
                <button className={`nav-tab ${activeTab === 'move-team' ? 'nav-tab-active' : ''}`} onClick={() => setActiveTab('move-team')}>
                    Move / Duplicate Teams
                </button>
            </nav>

            <div className="tab-content" style={{ marginTop: '20px' }}>
                {activeTab === 'dashboard' && <SeasonDashboard data={data} />}
                {activeTab === 'cross-event' && activeSeason && <CrossEventTeamView season={activeSeason} />}
                {activeTab === 'move-explorer' && activeSeason && <ExplorerMovePanel season={activeSeason} />}
                {activeTab === 'move-team' && activeSeason && <TeamMovePanel season={activeSeason} />}
            </div>
        </div>
    );
};

export default ExpeditionBoard;
