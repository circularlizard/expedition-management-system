import React, { useState, useEffect } from 'react';
import { BoardData, Explorer, FirstAidLevel } from './types';

interface OSMReferenceProps {
    data: BoardData;
    onChanged?: () => void;
}

const labels: Record<FirstAidLevel, string> = {
    none: 'None',
    first_response: 'First Response',
    full_first_aid: 'Full First Aid',
};

export const OSMReference: React.FC<OSMReferenceProps> = ({ data, onChanged }) => {
    const [levels, setLevels] = useState<Record<number, FirstAidLevel>>({});
    const [saving, setSaving] = useState<Record<number, boolean>>({});
    const [errors, setErrors] = useState<Record<number, string>>({});
    const config = window.emsExpeditionBoard;

    useEffect(() => {
        const next: Record<number, FirstAidLevel> = {};
        for (const explorer of data.explorers ?? []) {
            next[explorer.scout_id] = explorer.first_aid_level ?? 'none';
        }
        setLevels(next);
    }, [data.explorers]);

    const explorers = [...(data.explorers ?? [])].sort((a, b) => {
        const aName = `${a.last_name ?? ''}, ${a.first_name ?? ''}`;
        const bName = `${b.last_name ?? ''}, ${b.first_name ?? ''}`;
        return aName.localeCompare(bName);
    });

    const updateLevel = async (explorer: Explorer, level: FirstAidLevel) => {
        if (levels[explorer.scout_id] === level) return;
        setLevels((prev) => ({ ...prev, [explorer.scout_id]: level }));
        setSaving((prev) => ({ ...prev, [explorer.scout_id]: true }));
        setErrors((prev) => ({ ...prev, [explorer.scout_id]: '' }));
        try {
            const response = await fetch(`${config.root_url}/explorers/${explorer.scout_id}/first-aid`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({ first_aid_level: level }),
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            onChanged?.();
        } catch (e) {
            const message = e instanceof Error ? e.message : 'Failed to save';
            setErrors((prev) => ({ ...prev, [explorer.scout_id]: message }));
            setLevels((prev) => ({ ...prev, [explorer.scout_id]: explorer.first_aid_level ?? 'none' }));
        } finally {
            setSaving((prev) => ({ ...prev, [explorer.scout_id]: false }));
        }
    };

    return (
        <div className="ems-osm-reference" style={{ padding: '12px', background: '#fff' }}>
            <h2 style={{ marginTop: 0 }}>Explorer Reference</h2>
            <p>Set first aid qualifications for explorers synced from OSM.</p>

            {explorers.length === 0 ? (
                <p>No explorers have been synced yet.</p>
            ) : (
                <table className="widefat" style={{ maxWidth: '600px' }}>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Patrol</th>
                            <th>First Aid Qualification</th>
                        </tr>
                    </thead>
                    <tbody>
                        {explorers.map((explorer) => (
                            <tr key={explorer.scout_id}>
                                <td>{explorer.first_name} {explorer.last_name}</td>
                                <td>{explorer.patrol || '—'}</td>
                                <td>
                                    <select
                                        aria-label={`First aid level for ${explorer.first_name} ${explorer.last_name}`}
                                        value={levels[explorer.scout_id] ?? 'none'}
                                        onChange={(e) => updateLevel(explorer, e.target.value as FirstAidLevel)}
                                        disabled={saving[explorer.scout_id]}
                                    >
                                        {(Object.keys(labels) as FirstAidLevel[]).map((level) => (
                                            <option key={level} value={level}>{labels[level]}</option>
                                        ))}
                                    </select>
                                    {errors[explorer.scout_id] && (
                                        <span style={{ color: '#d63638', fontSize: '12px', marginLeft: '8px' }}>{errors[explorer.scout_id]}</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
};

export default OSMReference;
