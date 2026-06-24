import React, { useState, useEffect } from 'react';
import { Expedition } from './types';

interface OSMEvent {
    id: number;
    name: string;
}

interface EventFormProps {
    seasonId: number;
    initialEvent?: Expedition | null;
    osmEvents?: OSMEvent[];
    onSaved?: () => void;
    onCancel?: () => void;
}

export const EventForm: React.FC<EventFormProps> = ({ seasonId, initialEvent, osmEvents = [], onSaved, onCancel }) => {
    const config = window.emsExpeditionBoard;
    const [formData, setFormData] = useState<Record<string, string>>({
        ems_event_code: '',
        ems_type: 'practice',
        ems_transport: 'hillwalking',
        ems_level: 'silver',
        ems_start_date: '',
        ems_end_date: '',
        ems_start_time: '',
        ems_end_time: '',
        ems_lic_name: '',
        ems_start_location: '',
        ems_end_location: '',
        ems_route_info: '',
        ems_osm_event_id: '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (initialEvent) {
            setFormData({
                ems_event_code: initialEvent.ems_event_code || '',
                ems_type: initialEvent.ems_type || 'practice',
                ems_transport: initialEvent.ems_transport || 'hillwalking',
                ems_level: initialEvent.ems_level || 'silver',
                ems_start_date: initialEvent.ems_start_date || '',
                ems_end_date: initialEvent.ems_end_date || '',
                ems_start_time: initialEvent.ems_start_time || '',
                ems_end_time: initialEvent.ems_end_time || '',
                ems_lic_name: initialEvent.ems_lic_name || '',
                ems_start_location: initialEvent.ems_start_location || '',
                ems_end_location: initialEvent.ems_end_location || '',
                ems_route_info: initialEvent.ems_route_info || '',
                ems_osm_event_id: String(initialEvent.ems_osm_event_id || ''),
            });
        }
    }, [initialEvent]);

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
        const { name, value } = e.target;
        setFormData((prev) => ({ ...prev, [name]: value }));
        setErrors((prev) => ({ ...prev, [name]: '' }));
    };

    const validate = (): boolean => {
        const next: Record<string, string> = {};
        if (!formData.ems_event_code.trim()) next.ems_event_code = 'Event code is required';
        if (!formData.ems_start_date) next.ems_start_date = 'Start date is required';
        if (!formData.ems_end_date) next.ems_end_date = 'End date is required';
        setErrors(next);
        return Object.keys(next).length === 0;
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!validate()) return;

        setSaving(true);
        const payload = {
            ...formData,
            season_id: seasonId,
            ems_osm_event_id: formData.ems_osm_event_id ? Number(formData.ems_osm_event_id) : '',
        };

        const url = initialEvent ? `${config.root_url}/events/${initialEvent.ID}` : `${config.root_url}/events`;
        const method = initialEvent ? 'PATCH' : 'POST';

        try {
            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                const body = await response.json();
                if (body?.code === 'ems_event_code_exists') {
                    setErrors((prev) => ({ ...prev, ems_event_code: 'Event code already exists in this season' }));
                    return;
                }
                throw new Error(`HTTP ${response.status}`);
            }

            onSaved?.();
        } catch (err) {
            setErrors((prev) => ({ ...prev, form: err instanceof Error ? err.message : 'Failed to save' }));
        } finally {
            setSaving(false);
        }
    };

    const fieldStyle: React.CSSProperties = {
        display: 'block',
        marginBottom: '16px',
    };

    const inputStyle: React.CSSProperties = {
        display: 'block',
        marginTop: '4px',
        width: '100%',
        boxSizing: 'border-box',
        padding: '6px 8px',
        fontSize: '14px',
    };

    const gridStyle: React.CSSProperties = {
        display: 'grid',
        gridTemplateColumns: '1fr 1fr 1fr',
        gap: '16px',
        marginBottom: '16px',
    };

    const grid2Style: React.CSSProperties = {
        display: 'grid',
        gridTemplateColumns: '1fr 1fr',
        gap: '16px',
        marginBottom: '16px',
    };

    const sectionStyle: React.CSSProperties = {
        marginBottom: '24px',
        paddingBottom: '16px',
        borderBottom: '1px solid #eee',
    };

    const sectionLabelStyle: React.CSSProperties = {
        fontSize: '13px',
        fontWeight: '600',
        color: '#666',
        textTransform: 'uppercase',
        marginBottom: '12px',
    };

    return (
        <form onSubmit={handleSubmit} className="ems-event-form" style={{ padding: '20px', background: '#fff' }}>
            {errors.form && <div className="notice notice-error"><p>{errors.form}</p></div>}

            <div style={sectionStyle}>
                <div style={sectionLabelStyle}>Identification</div>

                <label style={fieldStyle}>
                    Event Code *
                    <input name="ems_event_code" value={formData.ems_event_code} onChange={handleChange} style={inputStyle} />
                    {errors.ems_event_code && <span className="ems-field-error" style={{ color: '#d63638', fontSize: '13px' }}>{errors.ems_event_code}</span>}
                </label>

                <div style={gridStyle}>
                    <label style={fieldStyle}>
                        Type
                        <select name="ems_type" value={formData.ems_type} onChange={handleChange} style={inputStyle}>
                            <option value="training">Training</option>
                            <option value="practice">Practice</option>
                            <option value="qualifying">Qualifying</option>
                        </select>
                    </label>

                    <label style={fieldStyle}>
                        Transport
                        <select name="ems_transport" value={formData.ems_transport} onChange={handleChange} style={inputStyle}>
                            <option value="hillwalking">Hillwalking</option>
                            <option value="biking">Biking</option>
                            <option value="paddling">Paddling</option>
                        </select>
                    </label>

                    <label style={fieldStyle}>
                        Level
                        <select name="ems_level" value={formData.ems_level} onChange={handleChange} style={inputStyle}>
                            <option value="bronze">Bronze</option>
                            <option value="silver">Silver</option>
                            <option value="gold">Gold</option>
                        </select>
                    </label>
                </div>
            </div>

            <div style={sectionStyle}>
                <div style={sectionLabelStyle}>Schedule</div>

                <div style={grid2Style}>
                    <label style={fieldStyle}>
                        Start Date *
                        <input type="date" name="ems_start_date" value={formData.ems_start_date} onChange={handleChange} style={inputStyle} />
                        {errors.ems_start_date && <span className="ems-field-error" style={{ color: '#d63638', fontSize: '13px' }}>{errors.ems_start_date}</span>}
                    </label>

                    <label style={fieldStyle}>
                        End Date *
                        <input type="date" name="ems_end_date" value={formData.ems_end_date} onChange={handleChange} style={inputStyle} />
                        {errors.ems_end_date && <span className="ems-field-error" style={{ color: '#d63638', fontSize: '13px' }}>{errors.ems_end_date}</span>}
                    </label>
                </div>

                <div style={grid2Style}>
                    <label style={fieldStyle}>
                        Start Time
                        <input type="time" name="ems_start_time" value={formData.ems_start_time} onChange={handleChange} style={inputStyle} />
                    </label>

                    <label style={fieldStyle}>
                        End Time
                        <input type="time" name="ems_end_time" value={formData.ems_end_time} onChange={handleChange} style={inputStyle} />
                    </label>
                </div>
            </div>

            <div style={sectionStyle}>
                <div style={sectionLabelStyle}>Locations</div>

                <div style={grid2Style}>
                    <label style={fieldStyle}>
                        Start Location
                        <input name="ems_start_location" value={formData.ems_start_location} onChange={handleChange} style={inputStyle} />
                    </label>

                    <label style={fieldStyle}>
                        End Location
                        <input name="ems_end_location" value={formData.ems_end_location} onChange={handleChange} style={inputStyle} />
                    </label>
                </div>

                <label style={fieldStyle}>
                    Leader in Charge Name
                    <input name="ems_lic_name" value={formData.ems_lic_name} onChange={handleChange} style={inputStyle} />
                </label>
            </div>

            <div style={sectionStyle}>
                <div style={sectionLabelStyle}>OSM Integration</div>

                <label style={fieldStyle}>
                    OSM Event
                    <select name="ems_osm_event_id" value={formData.ems_osm_event_id} onChange={handleChange} style={inputStyle}>
                        <option value="">None</option>
                        {osmEvents.map((event) => (
                            <option key={event.id} value={event.id}>{event.name}</option>
                        ))}
                    </select>
                </label>
            </div>

            <div style={sectionStyle}>
                <div style={sectionLabelStyle}>Route Planning</div>

                <label style={fieldStyle}>
                    Notes
                    <textarea name="ems_route_info" value={formData.ems_route_info} onChange={handleChange} style={{ ...inputStyle, minHeight: '80px', resize: 'vertical' }} />
                </label>
            </div>

            <div className="ems-form-actions" style={{ marginTop: '8px' }}>
                <button type="submit" className="button button-primary" disabled={saving}>
                    {saving ? 'Saving…' : (initialEvent ? 'Update Event' : 'Create Event')}
                </button>
                {onCancel && <button type="button" className="button" onClick={onCancel} style={{ marginLeft: '8px' }}>Cancel</button>}
            </div>
        </form>
    );
};

export default EventForm;
