import React, { useState, useEffect } from 'react';
import { Expedition, OSMEvent } from './types';

interface EventFormProps {
    seasonId: number;
    initialEvent?: Expedition | null;
    osmEvents?: OSMEvent[];
    onSaved?: (savedEvent: Expedition) => void;
    onCancel?: () => void;
}

const calculateDefaultRouteDeadline = (startDateStr: string): string => {
    if (!startDateStr) return '';
    const startDate = new Date(startDateStr);
    if (isNaN(startDate.getTime())) return '';
    
    // Subtract 14 days (2 weeks)
    const deadlineDate = new Date(startDate.getTime() - 14 * 24 * 60 * 60 * 1000);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    const finalDate = deadlineDate > today ? deadlineDate : today;
    return finalDate.toISOString().split('T')[0];
};

export const EventForm: React.FC<EventFormProps> = ({ seasonId, initialEvent, osmEvents = [], onSaved, onCancel }) => {
    const config = window.emsExpeditionBoard;
    const [formData, setFormData] = useState<Record<string, string>>({
        post_title: '',
        ems_event_code: '',
        ems_type: 'practice',
        ems_transport: 'hillwalking',
        ems_level: 'silver',
        ems_first_aid_level: 'none',
        ems_start_date: '',
        ems_end_date: '',
        ems_start_time: '',
        ems_end_time: '',
        ems_lic_name: '',
        ems_lic_email: '',
        ems_lic_phone: '',
        ems_lic_private_email: '',
        ems_lic_private_phone: '',
        ems_lic_id: '',
        ems_req_assessors: '',
        ems_req_volunteers: '',
        ems_start_location: '',
        ems_end_location: '',
        ems_route_info: '',
        ems_route_deadline: '',
        ems_status: 'active',
        ems_route_status: 'draft',
        ems_osm_event_id: '',
        ems_expedition_whatsapp_explorers: '',
        ems_expedition_whatsapp_parents: '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (initialEvent) {
            setFormData({
                post_title: initialEvent.post_title || '',
                ems_event_code: initialEvent.ems_event_code || '',
                ems_type: initialEvent.ems_type || 'practice',
                ems_transport: initialEvent.ems_transport || 'hillwalking',
                ems_level: initialEvent.ems_level || 'silver',
                ems_first_aid_level: initialEvent.ems_first_aid_level || 'none',
                ems_start_date: initialEvent.ems_start_date || '',
                ems_end_date: initialEvent.ems_end_date || '',
                ems_start_time: initialEvent.ems_start_time || '',
                ems_end_time: initialEvent.ems_end_time || '',
                ems_lic_name: initialEvent.ems_lic_name || '',
                ems_lic_email: initialEvent.ems_lic_email || '',
                ems_lic_phone: initialEvent.ems_lic_phone || '',
                ems_lic_private_email: initialEvent.ems_lic_private_email || '',
                ems_lic_private_phone: initialEvent.ems_lic_private_phone || '',
                ems_lic_id: initialEvent.ems_lic_id || '',
                ems_req_assessors: String(initialEvent.ems_req_assessors || ''),
                ems_req_volunteers: String(initialEvent.ems_req_volunteers || ''),
                ems_start_location: initialEvent.ems_start_location || '',
                ems_end_location: initialEvent.ems_end_location || '',
                ems_route_info: initialEvent.ems_route_info || '',
                ems_route_deadline: initialEvent.ems_route_deadline || '',
                ems_status: initialEvent.ems_status || 'active',
                ems_route_status: initialEvent.ems_route_status || 'draft',
                ems_osm_event_id: String(initialEvent.ems_osm_event_id || ''),
                ems_expedition_whatsapp_explorers: initialEvent.ems_expedition_whatsapp_explorers || '',
                ems_expedition_whatsapp_parents: initialEvent.ems_expedition_whatsapp_parents || '',
            });
        }
    }, [initialEvent]);

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
        const { name, value } = e.target;
        
        if (name === 'ems_start_date' && value) {
            const defaultDeadline = calculateDefaultRouteDeadline(value);
            setFormData((prev) => ({
                ...prev,
                ems_start_date: value,
                ems_route_deadline: prev.ems_route_deadline || defaultDeadline
            }));
            setErrors((prev) => ({ ...prev, ems_start_date: '' }));
        } else {
            setFormData((prev) => ({ ...prev, [name]: value }));
            setErrors((prev) => ({ ...prev, [name]: '' }));
        }
    };

    const validate = (): boolean => {
        const next: Record<string, string> = {};
        if (!formData.ems_event_code.trim()) next.ems_event_code = 'Event code is required';
        if (!formData.ems_start_date) next.ems_start_date = 'Start date is required';
        if (!formData.ems_end_date) next.ems_end_date = 'End date is required';
        if (formData.ems_lic_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.ems_lic_email)) {
            next.ems_lic_email = 'Enter a valid email address';
        }
        if (formData.ems_lic_private_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.ems_lic_private_email)) {
            next.ems_lic_private_email = 'Enter a valid email address';
        }
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

        if (payload.season_id === 0) {
            delete (payload as any).season_id;
        }

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
                    setErrors((prev) => ({ ...prev, ems_event_code: 'Event code already exists' }));
                    return;
                }
                throw new Error(`HTTP ${response.status}`);
            }

            const saved = await response.json();
            onSaved?.(saved);
        } catch (err) {
            setErrors((prev) => ({ ...prev, form: err instanceof Error ? err.message : 'Failed to save' }));
        } finally {
            setSaving(false);
        }
    };

    const todayStr = new Date().toISOString().split('T')[0];

    return (
        <form onSubmit={handleSubmit} noValidate className="ems-event-form ems-form-wrapper--lg">
            {errors.form && <div className="notice notice-error"><p>{errors.form}</p></div>}

            <div className="ems-form-section">
                <label className="ems-form-group">
                    <div className="ems-form-label">Expedition Title</div>
                    <input name="post_title" value={formData.post_title} onChange={handleChange} className="ems-form-input ems-form-input--title" placeholder="Enter expedition title..." />
                </label>
                <label className="ems-form-group ems-mt-16">
                    <div className="ems-form-label">Event Code *</div>
                    <input name="ems_event_code" value={formData.ems_event_code} onChange={handleChange} className="ems-form-input" />
                    {errors.ems_event_code && <span className="ems-field-error">{errors.ems_event_code}</span>}
                </label>
            </div>

            <div className="ems-overview-cards">
                {/* Card 1: Event Details */}
                <div className="ems-overview-card">
                    <h3 className="ems-overview-card__title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        Event Details
                    </h3>
                    <label className="ems-form-group">
                        <div className="ems-form-label">Type</div>
                        <select name="ems_type" value={formData.ems_type} onChange={handleChange} className="ems-form-input">
                            <option value="training">Training</option>
                            <option value="practice">Practice</option>
                            <option value="qualifying">Qualifying</option>
                        </select>
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">Transport</div>
                        <select name="ems_transport" value={formData.ems_transport} onChange={handleChange} className="ems-form-input">
                            <option value="hillwalking">Hillwalking</option>
                            <option value="biking">Biking</option>
                            <option value="paddling">Paddling</option>
                        </select>
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">Level</div>
                        <select name="ems_level" value={formData.ems_level} onChange={handleChange} className="ems-form-input">
                            <option value="bronze">Bronze</option>
                            <option value="silver">Silver</option>
                            <option value="gold">Gold</option>
                            <option value="multiple">Multiple</option>
                        </select>
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">First Aid Required</div>
                        <select name="ems_first_aid_level" value={formData.ems_first_aid_level} onChange={handleChange} className="ems-form-input">
                            <option value="none">None</option>
                            <option value="first_response">First Response</option>
                            <option value="full_first_aid">Full First Aid</option>
                        </select>
                    </label>
                </div>

                {/* Card 2: Schedule */}
                <div className="ems-overview-card">
                    <h3 className="ems-overview-card__title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Schedule
                    </h3>
                    <label className="ems-form-group">
                        <div className="ems-form-label">Start Date *</div>
                        <input
                            type="date"
                            name="ems_start_date"
                            value={formData.ems_start_date}
                            onChange={handleChange}
                            className="ems-form-input"
                            min={initialEvent ? undefined : todayStr}
                        />
                        {errors.ems_start_date && <span className="ems-field-error">{errors.ems_start_date}</span>}
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">Start Time</div>
                        <input type="time" name="ems_start_time" value={formData.ems_start_time} onChange={handleChange} className="ems-form-input" />
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">End Date *</div>
                        <input type="date" name="ems_end_date" value={formData.ems_end_date} onChange={handleChange} className="ems-form-input" />
                        {errors.ems_end_date && <span className="ems-field-error">{errors.ems_end_date}</span>}
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">End Time</div>
                        <input type="time" name="ems_end_time" value={formData.ems_end_time} onChange={handleChange} className="ems-form-input" />
                    </label>
                </div>

                {/* Card 3: Leader in Charge */}
                <div className="ems-overview-card">
                    <h3 className="ems-overview-card__title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Leader in Charge
                    </h3>
                    <label className="ems-form-group">
                        <div className="ems-form-label">Leader in Charge Name</div>
                        <input name="ems_lic_name" value={formData.ems_lic_name} onChange={handleChange} className="ems-form-input" />
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">Leader Public Email</div>
                        <input type="email" name="ems_lic_email" value={formData.ems_lic_email} onChange={handleChange} className="ems-form-input" />
                        {errors.ems_lic_email && <span className="ems-field-error">{errors.ems_lic_email}</span>}
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">Leader Public Phone</div>
                        <input type="tel" name="ems_lic_phone" value={formData.ems_lic_phone} onChange={handleChange} className="ems-form-input" />
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">Leader Private Email</div>
                        <input type="email" name="ems_lic_private_email" value={formData.ems_lic_private_email} onChange={handleChange} className="ems-form-input" />
                        {errors.ems_lic_private_email && <span className="ems-field-error">{errors.ems_lic_private_email}</span>}
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">Leader Private Phone</div>
                        <input type="tel" name="ems_lic_private_phone" value={formData.ems_lic_private_phone} onChange={handleChange} className="ems-form-input" />
                    </label>
                </div>

                {/* Card 4: Requirements & OSM Sync */}
                <div className="ems-overview-card">
                    <h3 className="ems-overview-card__title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Requirements & OSM Sync
                    </h3>
                    <label className="ems-form-group">
                        <div className="ems-form-label">Required Assessors</div>
                        <input type="number" name="ems_req_assessors" value={formData.ems_req_assessors} onChange={handleChange} className="ems-form-input" min="0" />
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">Required Volunteers (Supervisors)</div>
                        <input type="number" name="ems_req_volunteers" value={formData.ems_req_volunteers} onChange={handleChange} className="ems-form-input" min="0" />
                    </label>
                    <label className="ems-form-group ems-mt-12">
                        <div className="ems-form-label">OSM Event</div>
                        <select name="ems_osm_event_id" value={formData.ems_osm_event_id} onChange={handleChange} className="ems-form-input">
                            <option value="">None</option>
                            {osmEvents.map((event) => (
                                <option key={event.id} value={event.id}>{event.name}</option>
                            ))}
                        </select>
                    </label>
                </div>
            </div>

            <div className="ems-form-actions ems-mt-24">
                <button type="submit" className="button button-primary" disabled={saving}>
                    {saving ? 'Saving…' : (initialEvent ? 'Update Event' : 'Create Event')}
                </button>
                {onCancel && <button type="button" className="button ems-btn-cancel" onClick={onCancel}>Cancel</button>}
            </div>
        </form>
    );
};

export default EventForm;
