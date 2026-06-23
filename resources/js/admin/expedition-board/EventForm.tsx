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

    return (
        <form onSubmit={handleSubmit} className="ems-event-form">
            {errors.form && <div className="notice notice-error"><p>{errors.form}</p></div>}

            <label>
                Event Code
                <input name="ems_event_code" value={formData.ems_event_code} onChange={handleChange} />
                {errors.ems_event_code && <span className="ems-field-error">{errors.ems_event_code}</span>}
            </label>

            <label>
                Type
                <select name="ems_type" value={formData.ems_type} onChange={handleChange}>
                    <option value="training">Training</option>
                    <option value="practice">Practice</option>
                    <option value="qualifying">Qualifying</option>
                </select>
            </label>

            <label>
                Transport
                <select name="ems_transport" value={formData.ems_transport} onChange={handleChange}>
                    <option value="hillwalking">Hillwalking</option>
                    <option value="biking">Biking</option>
                    <option value="paddling">Paddling</option>
                </select>
            </label>

            <label>
                Level
                <select name="ems_level" value={formData.ems_level} onChange={handleChange}>
                    <option value="bronze">Bronze</option>
                    <option value="silver">Silver</option>
                    <option value="gold">Gold</option>
                </select>
            </label>

            <label>
                Start Date
                <input type="date" name="ems_start_date" value={formData.ems_start_date} onChange={handleChange} />
                {errors.ems_start_date && <span className="ems-field-error">{errors.ems_start_date}</span>}
            </label>

            <label>
                End Date
                <input type="date" name="ems_end_date" value={formData.ems_end_date} onChange={handleChange} />
                {errors.ems_end_date && <span className="ems-field-error">{errors.ems_end_date}</span>}
            </label>

            <label>
                Leader in Charge Name
                <input name="ems_lic_name" value={formData.ems_lic_name} onChange={handleChange} />
            </label>

            <label>
                Start Location
                <input name="ems_start_location" value={formData.ems_start_location} onChange={handleChange} />
            </label>

            <label>
                End Location
                <input name="ems_end_location" value={formData.ems_end_location} onChange={handleChange} />
            </label>

            <label>
                OSM Event
                <select name="ems_osm_event_id" value={formData.ems_osm_event_id} onChange={handleChange}>
                    <option value="">None</option>
                    {osmEvents.map((event) => (
                        <option key={event.id} value={event.id}>{event.name}</option>
                    ))}
                </select>
            </label>

            <label>
                Route Planning Notes
                <textarea name="ems_route_info" value={formData.ems_route_info} onChange={handleChange} />
            </label>

            <div className="ems-form-actions">
                <button type="submit" className="button button-primary" disabled={saving}>
                    {saving ? 'Saving…' : 'Save Event'}
                </button>
                {onCancel && <button type="button" className="button" onClick={onCancel}>Cancel</button>}
            </div>
        </form>
    );
};

export default EventForm;
