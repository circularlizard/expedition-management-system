import React, { useState } from 'react';

interface SeasonFormProps {
    onSaved?: () => void;
    onCancel?: () => void;
}

export const SeasonForm: React.FC<SeasonFormProps> = ({ onSaved, onCancel }) => {
    const config = window.emsExpeditionBoard;
    const [year, setYear] = useState('');
    const [title, setTitle] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!year.trim()) {
            setErrors({ year: 'Season year is required' });
            return;
        }

        setSaving(true);
        setErrors({});
        try {
            const response = await fetch(`${config.root_url}/seasons`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({ year: year.trim(), post_title: title.trim() }),
            });

            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                if (body?.code === 'ems_season_year_exists') {
                    setErrors({ year: 'A season with this year already exists' });
                    return;
                }
                throw new Error(`HTTP ${response.status}`);
            }

            onSaved?.();
        } catch (err) {
            setErrors({ form: err instanceof Error ? err.message : 'Failed to create season' });
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={handleSubmit} className="ems-season-form" style={{ marginBottom: '20px', padding: '16px', border: '1px solid #ddd', background: '#fff' }}>
            {errors.form && <div className="notice notice-error"><p>{errors.form}</p></div>}

            <label style={{ display: 'block', marginBottom: '12px' }}>
                Season Year
                <input
                    name="year"
                    placeholder="e.g. 2026-27"
                    value={year}
                    onChange={(e) => { setYear(e.target.value); setErrors((p) => ({ ...p, year: '' })); }}
                    style={{ display: 'block', marginTop: '4px' }}
                />
                {errors.year && <span className="ems-field-error" style={{ color: '#d63638' }}>{errors.year}</span>}
            </label>

            <label style={{ display: 'block', marginBottom: '12px' }}>
                Title <span style={{ color: '#888' }}>(optional)</span>
                <input
                    name="post_title"
                    placeholder="Defaults to '<year> Season'"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    style={{ display: 'block', marginTop: '4px' }}
                />
            </label>

            <div className="ems-form-actions">
                <button type="submit" className="button button-primary" disabled={saving}>
                    {saving ? 'Saving…' : 'Create Season'}
                </button>
                {onCancel && <button type="button" className="button" onClick={onCancel} style={{ marginLeft: '8px' }}>Cancel</button>}
            </div>
        </form>
    );
};

export default SeasonForm;
