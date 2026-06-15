import React, { useState, useEffect } from 'react';
import { BoardData, Expedition, Team, Member } from './types';

function downloadCsv(filename: string, rows: string[][]): void {
    const escape = (v: string | number) => {
        const s = String(v ?? '');
        return s.includes(',') || s.includes('"') || s.includes('\n')
            ? `"${s.replace(/"/g, '""')}"`
            : s;
    };
    const csv = rows.map(r => r.map(escape).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

const ExpeditionBoard: React.FC = () => {
    const config = window.emsExpeditionBoard;
    const [data, setData] = useState<BoardData | null>(null);
    const [activeTab, setActiveTab] = useState<'explorers' | 'teams' | 'expeditions' | 'units'>('expeditions');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        setLoading(true);
        try {
            const response = await fetch(`${config.root_url}/expedition-board`, {
                headers: { 'X-WP-Nonce': config.nonce }
            });
            const result = await response.json();
            setData(result);
        } catch (err) {
            setError('Failed to load dashboard data');
        } finally {
            setLoading(false);
        }
    };

    if (loading) return <p>Loading board...</p>;
    if (error) return <div className="notice notice-error"><p>{error}</p></div>;
    if (!data || !Array.isArray(data.expeditions)) return null;

    return (
        <div className="ems-board">
            <div className="ems-board-header" style={{ marginBottom: '20px', color: '#666', fontSize: '0.9em' }}>
                Last synced with OSM: {data.last_sync ? new Date(data.last_sync).toLocaleString() : 'Never'}
            </div>

            <nav className="nav-tab-wrapper">
                <button 
                    className={`nav-tab ${activeTab === 'expeditions' ? 'nav-tab-active' : ''}`}
                    onClick={() => setActiveTab('expeditions')}
                >
                    By Expedition
                </button>
                <button 
                    className={`nav-tab ${activeTab === 'teams' ? 'nav-tab-active' : ''}`}
                    onClick={() => setActiveTab('teams')}
                >
                    By Team
                </button>
                <button 
                    className={`nav-tab ${activeTab === 'explorers' ? 'nav-tab-active' : ''}`}
                    onClick={() => setActiveTab('explorers')}
                >
                    By Explorer
                </button>
                <button 
                    className={`nav-tab ${activeTab === 'units' ? 'nav-tab-active' : ''}`}
                    onClick={() => setActiveTab('units')}
                >
                    By Patrol
                </button>
            </nav>

            <div className="tab-content" style={{ marginTop: '20px' }}>
                {activeTab === 'expeditions' && <ExpeditionView data={data} />}
                {activeTab === 'teams' && <TeamView data={data} />}
                {activeTab === 'explorers' && <ExplorerView data={data} />}
                {activeTab === 'units' && <UnitView data={data} />}
            </div>
        </div>
    );
};

const ExpeditionView: React.FC<{ data: BoardData }> = ({ data }) => {
    if (!data.expeditions || data.expeditions.length === 0) {
        return (
            <div className="notice notice-info inline">
                <p>No expeditions found. To populate this list, you can:</p>
                <ol>
                    <li>Click <strong>Sync from OSM</strong> above to load members.</li>
                    <li>Configure your mappings in the <strong>Column Mapper</strong>.</li>
                    <li>Import your 2026 season flexi-record data.</li>
                </ol>
            </div>
        );
    }

    return (
        <div className="expedition-view">
            {data.expeditions.map(exp => (
                <div key={exp.ID} className="expedition-card" style={{ marginBottom: '30px', border: '1px solid #ddd', padding: '15px', background: '#fff' }}>
                    <h3>{exp.post_title} ({exp.ems_expedition_code})</h3>
                    <p>Level: {exp.ems_level} | Dates: {exp.ems_start_date} to {exp.ems_end_date}</p>
                    
                    <div className="teams-grid" style={{ display: 'flex', gap: '15px', flexWrap: 'wrap' }}>
                        {(data.teams[exp.ID] || []).map(team => (
                            <div key={team.ID} style={{ border: '1px solid #eee', padding: '10px', minWidth: '200px' }}>
                                <strong>Team {team.ems_team_code}</strong>
                                <ul style={{ margin: '5px 0 0 0', padding: '0 0 0 15px', fontSize: '0.9em' }}>
                                    {(data.members[team.ID] || []).map(m => (
                                        <li key={m.user_id}>{m.first_name} {m.last_name}</li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
};

const TeamView: React.FC<{ data: BoardData }> = ({ data }) => {
    const allTeams = Object.values(data.teams ?? {}).flat();
    
    if (allTeams.length === 0) {
        return <p>No teams created yet. Teams are created during the Flexi-Record import process.</p>;
    }

    const handleCsv = () => {
        const rows: string[][] = [
            ['Team Code', 'Expedition', 'Member Count'],
            ...allTeams.map(team => [
                team.ems_team_code,
                data.expeditions.find(e => e.ID === parseInt(team.ems_expedition_id))?.post_title ?? 'Unknown',
                String((data.members[team.ID] || []).length),
            ]),
        ];
        downloadCsv('teams.csv', rows);
    };

    return (
        <div>
            <button className="button" onClick={handleCsv} style={{ marginBottom: '10px' }}>Download CSV</button>
            <table className="widefat striped">
                <thead>
                    <tr>
                        <th>Team Code</th>
                        <th>Expedition</th>
                        <th>Members</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    {allTeams.map(team => (
                        <tr key={team.ID}>
                            <td>{team.ems_team_code}</td>
                            <td>{data.expeditions.find(e => e.ID === parseInt(team.ems_expedition_id))?.post_title || 'Unknown'}</td>
                            <td>{(data.members[team.ID] || []).length} members</td>
                            <td>Pending</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
};

const ExplorerView: React.FC<{ data: BoardData }> = ({ data }) => {
    if (!data.explorers || data.explorers.length === 0) {
        return <p>No explorers synced yet. Click <strong>Sync from OSM</strong> to pull your member lists.</p>;
    }

    const handleCsv = () => {
        const rows: string[][] = [
            ['First Name', 'Last Name', 'Scout ID', 'Patrol', 'Training %'],
            ...data.explorers.map(m => [
                m.first_name, m.last_name, m.scout_id, m.unit,
                String(m.training?.percent ?? ''),
            ]),
        ];
        downloadCsv('explorers.csv', rows);
    };

    return (
        <div>
            <button className="button" onClick={handleCsv} style={{ marginBottom: '10px' }}>Download CSV</button>
            <table className="widefat striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Scout ID</th>
                        <th>Patrol</th>
                        <th>Training Status</th>
                    </tr>
                </thead>
                <tbody>
                    {data.explorers.map(m => (
                        <tr key={m.scout_id || m.user_id}>
                            <td>{m.first_name} {m.last_name}</td>
                            <td>{m.scout_id}</td>
                            <td>{m.unit}</td>
                            <td>
                                <div style={{ width: '100px', background: '#eee', height: '10px', borderRadius: '5px' }}>
                                    <div style={{ width: `${m.training?.percent ?? 0}%`, background: (m.training?.percent ?? 0) === 100 ? '#46b450' : '#ffb900', height: '100%', borderRadius: '5px' }}></div>
                                </div>
                                <span style={{ fontSize: '0.8em' }}>{m.training?.complete ?? 0}/{m.training?.total ?? 0} courses</span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
};

const UnitView: React.FC<{ data: BoardData }> = ({ data }) => {
    if (!data.explorers || data.explorers.length === 0) {
        return <p>No explorers synced yet.</p>;
    }

    const units: Record<string, Member[]> = {};
    data.explorers.forEach(m => {
        const key = m.unit || 'Unassigned';
        if (!units[key]) units[key] = [];
        units[key].push(m);
    });

    const handleCsv = () => {
        const rows: string[][] = [ ['Patrol', 'First Name', 'Last Name', 'Scout ID'] ];
        Object.entries(units).forEach(([unit, members]) => {
            members.forEach(m => rows.push([unit, m.first_name, m.last_name, m.scout_id]));
        });
        downloadCsv('by-patrol.csv', rows);
    };

    return (
        <div>
            <button className="button" onClick={handleCsv} style={{ marginBottom: '10px' }}>Download CSV</button>
            {Object.entries(units).map(([unit, members]) => (
                <div key={unit} style={{ marginBottom: '20px' }}>
                    <h3>{unit} ({members.length})</h3>
                    <ul className="members-list" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '10px' }}>
                        {members.map(m => (
                            <li key={m.scout_id || m.user_id} style={{ border: '1px solid #eee', padding: '5px' }}>
                                {m.first_name} {m.last_name}
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
};

export default ExpeditionBoard;
