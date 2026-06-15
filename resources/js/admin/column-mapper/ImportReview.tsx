import React, { useState } from 'react';
import { Section } from './types';

interface BucketedData {
    clean: any[];
    partial: any[];
    unparseable: any[];
}

interface ImportReviewProps {
    config: any;
}

const ImportReview: React.FC<ImportReviewProps> = ({ config }) => {
    const [selectedSection, setSelectedSection] = useState<string>('');
    const [buckets, setBuckets] = useState<BucketedData | null>(null);
    const [loading, setLoading] = useState(false);
    const [committing, setCommitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<number | null>(null);

    if (!config || !config.sections) {
        return null;
    }

    const fetchReview = async (sectionId: string) => {
        const section = config.sections[sectionId];
        if (!section || !section.extraid) return;

        setLoading(true);
        setError(null);
        setResult(null);
        try {
            const response = await fetch(`${config.root_url}/flexi-review?section_id=${sectionId}&flexi_id=${section.extraid}`, {
                headers: { 'X-WP-Nonce': config.nonce }
            });
            const data = await response.json();
            setBuckets(data.buckets);
        } catch (err) {
            setError('Failed to fetch review data');
        } finally {
            setLoading(false);
        }
    };

    const handleCommit = async () => {
        if (!buckets || buckets.clean.length === 0) return;

        setCommitting(true);
        setError(null);
        try {
            const response = await fetch(`${config.root_url}/flexi-commit`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify(buckets.clean)
            });
            const data = await response.json();
            setResult(data.count);
            setBuckets(null);
        } catch (err) {
            setError('Failed to commit records');
        } finally {
            setCommitting(false);
        }
    };

    return (
        <div className="ems-import-review" style={{ marginTop: '30px', borderTop: '1px solid #ccc', paddingTop: '20px' }}>
            <h2>Step 2: Review & Commit Data</h2>
            
            {error && <div className="notice notice-error"><p>{error}</p></div>}
            {result !== null && (
                <div className="notice notice-success">
                    <p>Successfully processed {result} records!</p>
                </div>
            )}

            <div className="section-selector" style={{ marginBottom: '20px' }}>
                <label htmlFor="ems-review-select" style={{ marginRight: '10px' }}>Select Section to Review:</label>
                <select 
                    id="ems-review-select"
                    value={selectedSection} 
                    onChange={(e) => {
                        setSelectedSection(e.target.value);
                        fetchReview(e.target.value);
                    }}
                >
                    <option value="">-- Select Section --</option>
                    {Object.entries(config.sections as Record<string, Section>).map(([id, section]) => (
                        <option key={id} value={id}>{section.name}</option>
                    ))}
                </select>
            </div>

            {loading && <p>Loading data from OSM...</p>}

            {buckets && (
                <div className="buckets-display">
                    <div className="bucket clean" style={{ marginBottom: '20px' }}>
                        <h3>Ready to Commit ({buckets.clean.length})</h3>
                        <p className="description">These rows have all required fields and matching WordPress users.</p>
                        {buckets.clean.length > 0 && (
                            <table className="widefat striped">
                                <thead>
                                    <tr>
                                        <th>Expedition</th>
                                        <th>Team</th>
                                        <th>Scout ID</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {buckets.clean.slice(0, 10).map((row, i) => (
                                        <tr key={i}>
                                            <td>{row.expedition_code}</td>
                                            <td>{row.team_code}</td>
                                            <td>{row.participant_scout_id}</td>
                                        </tr>
                                    ))}
                                    {buckets.clean.length > 10 && <tr><td colSpan={3}>... and {buckets.clean.length - 10} more</td></tr>}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <div className="bucket partial" style={{ marginBottom: '20px', color: '#666' }}>
                        <h3>Partial / Missing Data ({buckets.partial.length})</h3>
                        <p className="description">These rows are missing required fields or have unmatched Scout IDs. They will be skipped.</p>
                        {buckets.partial.length > 0 && (
                            <ul style={{ maxHeight: '150px', overflowY: 'auto', border: '1px solid #ddd', padding: '10px' }}>
                                {buckets.partial.slice(0, 20).map((row, i) => (
                                    <li key={i}>
                                        Row {i + 1}: {row.expedition_code || 'No Exp'} / {row.team_code || 'No Team'} / {row.participant_scout_id || 'No ID'}
                                        {row._error && <span style={{ color: '#d63638', marginLeft: '10px' }}>({row._error})</span>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <p className="submit">
                        <button 
                            className="button button-primary" 
                            onClick={handleCommit}
                            disabled={committing || buckets.clean.length === 0}
                        >
                            {committing ? 'Committing...' : `Commit ${buckets.clean.length} Records`}
                        </button>
                    </p>
                </div>
            )}
        </div>
    );
};

export default ImportReview;
