import React, { useState, useEffect } from 'react';
import { Column, Section } from './types';

const ColumnMapper: React.FC = () => {
    const config = window.emsColumnMapper;
    const [selectedSection, setSelectedSection] = useState<string>('');
    const [columns, setColumns] = useState<Column[]>([]);
    const [mapping, setMap] = useState<Record<string, string>>({});
    const [requiredFields, setRequiredFields] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState(false);

    useEffect(() => {
        fetchMap();
    }, []);

    const fetchMap = async () => {
        try {
            const response = await fetch(`${config.root_url}/flexi-column-map`, {
                headers: { 'X-WP-Nonce': config.nonce }
            });
            const data = await response.json();
            setMap(data.map || {});
            setRequiredFields(data.required_fields || []);
        } catch (err) {
            setError('Failed to fetch mapping');
        }
    };

    const fetchStructure = async (sectionId: string) => {
        const section = config.sections[sectionId];
        if (!section || !section.extraid) return;

        setLoading(true);
        setError(null);
        try {
            const response = await fetch(`${config.root_url}/flexi-structure?section_id=${sectionId}&flexi_id=${section.extraid}`, {
                headers: { 'X-WP-Nonce': config.nonce }
            });
            const data = await response.json();
            setColumns(data.columns || []);
        } catch (err) {
            setError('Failed to fetch flexi-record structure');
        } finally {
            setLoading(false);
        }
    };

    const handleSave = async () => {
        setError(null);
        setSuccess(false);
        try {
            const response = await fetch(`${config.root_url}/flexi-column-map`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce
                },
                body: JSON.stringify(mapping)
            });
            const data = await response.json();
            if (data.error) {
                setError(data.error);
            } else {
                setSuccess(true);
            }
        } catch (err) {
            setError('Failed to save mapping');
        }
    };

    const updateMapping = (field: string, columnId: string) => {
        setMap(prev => ({ ...prev, [field]: columnId }));
    };

    return (
        <div className="ems-column-mapper">
            {error && <div className="notice notice-error"><p>{error}</p></div>}
            {success && <div className="notice notice-success"><p>Mapping saved successfully!</p></div>}

            <div className="section-selector" style={{ marginBottom: '20px' }}>
                <label htmlFor="ems-section-select" style={{ marginRight: '10px' }}>Select Section to Load Columns From:</label>
                <select 
                    id="ems-section-select"
                    value={selectedSection} 
                    onChange={(e) => {
                        setSelectedSection(e.target.value);
                        fetchStructure(e.target.value);
                    }}
                >
                    <option value="">-- Select Section --</option>
                    {Object.entries(config.sections).map(([id, section]) => (
                        <option key={id} value={id}>{section.name}</option>
                    ))}
                </select>
            </div>

            {loading ? (
                <p>Loading columns...</p>
            ) : columns.length > 0 ? (
                <table className="widefat striped">
                    <thead>
                        <tr>
                            <th>EMS Field</th>
                            <th>OSM Column</th>
                        </tr>
                    </thead>
                    <tbody>
                        {requiredFields.map(field => (
                            <tr key={field}>
                                <td><strong>{field}</strong> (Required)</td>
                                <td>
                                    <select 
                                        value={mapping[field] || ''} 
                                        onChange={(e) => updateMapping(field, e.target.value)}
                                    >
                                        <option value="">-- Unmapped --</option>
                                        {columns.map(col => (
                                            <option key={col.id} value={col.id}>{col.name} ({col.id})</option>
                                        ))}
                                    </select>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : selectedSection ? (
                <p>No columns found for this flexi-record.</p>
            ) : (
                <p>Please select a section to begin mapping.</p>
            )}

            <p className="submit">
                <button 
                    className="button button-primary" 
                    onClick={handleSave}
                    disabled={loading}
                >
                    Save Mapping
                </button>
            </p>
        </div>
    );
};

export default ColumnMapper;
