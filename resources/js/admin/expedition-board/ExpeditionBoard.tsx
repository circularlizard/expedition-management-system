import React, { useState } from 'react';
import { useBoard } from './useBoard';
import { Season } from './types';
import { SeasonDashboard } from './SeasonDashboard';
import { CrossEventTeamView } from './CrossEventTeamView';
import { ExplorerMovePanel } from './ExplorerMovePanel';
import { TeamMovePanel } from './TeamMovePanel';

type BoardTab = 'dashboard' | 'cross-event' | 'move-explorer' | 'move-team';

const ExpeditionBoard: React.FC = () => {
    const { data, loading, error } = useBoard();
    const [activeTab, setActiveTab] = useState<BoardTab>('dashboard');
    const [activeSeasonId, setActiveSeasonId] = useState<number | null>(null);

    if (loading) return <p>Loading board...</p>;
    if (error) return <div className="notice notice-error"><p>{error}</p></div>;
    if (!data || !Array.isArray(data.seasons)) return null;

    const seasons = data.seasons;
    const activeSeason: Season | null =
        seasons.find((s) => s.ID === activeSeasonId) ?? seasons[0] ?? null;

    return (
        <div className="ems-board">
            <div className="ems-board-header" style={{ marginBottom: '20px', color: '#666', fontSize: '0.9em' }}>
                Last synced with OSM: {data.last_sync ? new Date(data.last_sync).toLocaleString() : 'Never'}
            </div>

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
