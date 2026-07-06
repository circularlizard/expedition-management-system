import '../../../css/ems-admin.css';
import React, { useState, useEffect } from 'react';
import { createRoot } from 'react-dom/client';

interface Shift {
    date: string;
    overnight: number;
}

interface Expedition {
    ID: number;
    post_title: string;
    ems_event_code: string;
    ems_start_date: string;
    ems_end_date: string;
}

function VolunteerSignupWizard() {
    const [step, setStep] = useState(1);
    const [events, setEvents] = useState<Expedition[]>([]);
    const [selectedEvents, setSelectedEvents] = useState<number[]>([]);
    const [eventOptions, setEventOptions] = useState<Record<number, 'whole' | 'part'>>({});
    const [shifts, setShifts] = useState<Record<number, Shift[]>>({});
    const [firstName, setFirstName] = useState('');
    const [lastName, setLastName] = useState('');
    const [email, setEmail] = useState('');
    const [phone, setPhone] = useState('');
    const [dbsNumber, setDbsNumber] = useState('');
    const [firstAid, setFirstAid] = useState('none');
    const [roles, setRoles] = useState<string[]>([]);
    const [isOSM, setIsOSM] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const [submitted, setSubmitted] = useState(false);

    const config = (window as any).emsVolunteerSignup || { root_url: '/wp-json/ems/v1', nonce: '' };

    useEffect(() => {
        // Fetch upcoming events to choose from
        fetch(`${config.root_url}/expedition-board`, { headers: { 'X-WP-Nonce': config.nonce } })
            .then(res => res.ok ? res.json() : Promise.reject())
            .then(data => {
                const evList: Expedition[] = [];
                if (data.seasons) {
                    data.seasons.forEach((s: any) => {
                        if (s.events) {
                            s.events.forEach((ev: any) => evList.push(ev));
                        }
                    });
                }
                setEvents(evList);
            })
            .catch(() => {});
    }, []);

    const toggleEvent = (eventId: number) => {
        setSelectedEvents(prev => {
            const isSelected = prev.includes(eventId);
            if (isSelected) {
                // Cleanup options and shifts
                setEventOptions(curr => {
                    const copy = { ...curr };
                    delete copy[eventId];
                    return copy;
                });
                setShifts(curr => {
                    const copy = { ...curr };
                    delete copy[eventId];
                    return copy;
                });
                return prev.filter(id => id !== eventId);
            } else {
                setEventOptions(curr => ({ ...curr, [eventId]: 'whole' }));
                return [...prev, eventId];
            }
        });
    };

    const handleOptionChange = (eventId: number, val: 'whole' | 'part') => {
        setEventOptions(prev => ({ ...prev, [eventId]: val }));
        if (val === 'whole') {
            // Automatically select all shifts for the whole event
            const ev = events.find(e => e.ID === eventId);
            if (ev) {
                const dates = getDatesForEvent(ev);
                const allShifts: Shift[] = [];
                dates.forEach((d, idx) => {
                    allShifts.push({ date: d, overnight: 0 });
                    // No overnight on the last day of the event
                    if (idx < dates.length - 1) {
                        allShifts.push({ date: d, overnight: 1 });
                    }
                });
                setShifts(prev => ({ ...prev, [eventId]: allShifts }));
            }
        } else {
            setShifts(prev => ({ ...prev, [eventId]: [] }));
        }
    };

    const getDatesForEvent = (ev: Expedition) => {
        const dates: string[] = [];
        if (!ev.ems_start_date || !ev.ems_end_date) return dates;
        let curr = new Date(ev.ems_start_date);
        const end = new Date(ev.ems_end_date);
        while (curr <= end) {
            dates.push(curr.toISOString().split('T')[0]);
            curr.setDate(curr.getDate() + 1);
        }
        return dates;
    };

    const handleShiftToggle = (eventId: number, date: string, overnight: number) => {
        const currentShifts = shifts[eventId] || [];
        const exists = currentShifts.some(s => s.date === date && s.overnight === overnight);
        let updated: Shift[];
        if (exists) {
            updated = currentShifts.filter(s => !(s.date === date && s.overnight === overnight));
        } else {
            updated = [...currentShifts, { date, overnight }];
        }
        setShifts(prev => ({ ...prev, [eventId]: updated }));
    };

    const handleOSMAuth = () => {
        // Trigger OSM Mock OIDC flow
        setIsOSM(true);
        setFirstName('Leader');
        setLastName('OSM');
        setEmail('leader@scouts.org.uk');
    };

    const handleSubmit = async () => {
        const errs: string[] = [];
        if (!firstName) errs.push('First name is required.');
        if (!email) errs.push('Email is required.');
        if (selectedEvents.length === 0) errs.push('Select at least one event.');

        if (errs.length > 0) {
            setErrors(errs);
            return;
        }

        setErrors([]);

        // Submit each selected event's availability matrix
        try {
            for (const eventId of selectedEvents) {
                const eventShifts = shifts[eventId] || [];
                const res = await fetch(`${config.root_url}/volunteers/signup`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': config.nonce
                    },
                    body: JSON.stringify({
                        first_name: firstName,
                        last_name: lastName,
                        email: email,
                        phone: phone,
                        dbs_number: dbsNumber,
                        qualifications: {
                            first_aid: firstAid
                        },
                        preferred_roles: roles,
                        expedition_post_id: eventId,
                        shifts: eventShifts
                    })
                });
                if (!res.ok) throw new Error();
            }
            setSubmitted(true);
        } catch (err) {
            setErrors(['Failed to submit availability registration.']);
        }
    };

    const hasPartialAvailability = selectedEvents.some(id => eventOptions[id] === 'part');

    const handleNextFromEvents = () => {
        if (selectedEvents.length === 0) {
            setErrors(['Select at least one event.']);
            return;
        }
        setErrors([]);

        // For any "whole" event, pre-populate all shifts
        selectedEvents.forEach(eventId => {
            if (eventOptions[eventId] === 'whole') {
                const ev = events.find(e => e.ID === eventId);
                if (ev) {
                    const dates = getDatesForEvent(ev);
                    const allShifts: Shift[] = [];
                    dates.forEach(d => {
                        allShifts.push({ date: d, overnight: 0 });
                        allShifts.push({ date: d, overnight: 1 });
                    });
                    setShifts(prev => ({ ...prev, [eventId]: allShifts }));
                }
            }
        });

        if (hasPartialAvailability) {
            setStep(3); // Go to builder
        } else {
            setStep(4); // Skip builder, go to details
        }
    };

    if (submitted) {
        return (
            <div style={{ padding: '20px', background: '#e5f5fa', borderRadius: '4px', border: '1px solid #00a0d2' }}>
                <h2>Availability Submitted!</h2>
                <p>Thank you for volunteering. An email verification link has been sent (or your OSM sync completed).</p>
            </div>
        );
    }

    return (
        <div style={{ maxWidth: '600px', margin: '0 auto', background: '#fff', border: '1px solid #ccd0d4', padding: '20px', borderRadius: '4px' }}>
            <h2>Volunteer Availability Wizard</h2>

            {errors.length > 0 && (
                <div style={{ background: '#fbeaea', border: '1px solid #dc3232', padding: '10px', borderRadius: '3px', marginBottom: '16px' }}>
                    {errors.map((e, idx) => <div key={idx} style={{ color: '#dc3232' }}>{e}</div>)}
                </div>
            )}

            {step === 1 && (
                <div>
                    <h3>Enrollment Option</h3>
                    <div style={{ display: 'flex', gap: '10px', marginBottom: '20px' }}>
                        <button className="button button-primary" onClick={handleOSMAuth}>Sign in via OSM</button>
                        <button className="button" onClick={() => setStep(2)}>Sign Up as Guest</button>
                    </div>
                    {isOSM && (
                        <div style={{ background: '#f0f0f0', padding: '10px', borderRadius: '3px', marginBottom: '10px' }}>
                            Authenticated as <strong>{firstName} {lastName}</strong> ({email})
                            <button className="button button-link" style={{ marginLeft: '10px' }} onClick={() => setStep(2)}>Proceed to wizard</button>
                        </div>
                    )}
                </div>
            )}

            {step === 2 && (
                <div>
                    <h3>Step 1: Select Events & Attendance Type</h3>
                    {events.map(e => (
                        <div key={e.ID} style={{ borderBottom: '1px solid #eee', padding: '12px 0' }}>
                            <label style={{ display: 'block', fontWeight: 'bold', cursor: 'pointer' }}>
                                <input type="checkbox" checked={selectedEvents.includes(e.ID)} onChange={() => toggleEvent(e.ID)} />
                                {e.post_title} ({e.ems_event_code})
                            </label>
                            <div style={{ fontSize: '12px', color: '#666', margin: '4px 0 8px 20px' }}>
                                Dates: {e.ems_start_date} to {e.ems_end_date} 
                                {e.ems_start_time && ` | Start Time: ${e.ems_start_time}`} 
                                {e.ems_end_time && ` | End Time: ${e.ems_end_time}`}
                            </div>
                            {selectedEvents.includes(e.ID) && (
                                <div style={{ marginLeft: '20px', display: 'flex', gap: '15px' }}>
                                    <label style={{ cursor: 'pointer', fontSize: '13px' }}>
                                        <input type="radio" name={`option-${e.ID}`} checked={eventOptions[e.ID] === 'whole'} onChange={() => handleOptionChange(e.ID, 'whole')} />
                                        Whole Event
                                    </label>
                                    <label style={{ cursor: 'pointer', fontSize: '13px' }}>
                                        <input type="radio" name={`option-${e.ID}`} checked={eventOptions[e.ID] === 'part'} onChange={() => handleOptionChange(e.ID, 'part')} />
                                        Part of Event
                                    </label>
                                </div>
                            )}
                        </div>
                    ))}
                    <button className="button button-primary" style={{ marginTop: '16px' }} onClick={handleNextFromEvents}>Next</button>
                </div>
            )}

            {step === 3 && (
                <div>
                    <h3>Step 2: Availability Builder</h3>
                    {selectedEvents.filter(id => eventOptions[id] === 'part').map(eventId => {
                        const ev = events.find(e => e.ID === eventId);
                        if (!ev) return null;
                        const eventDates = getDatesForEvent(ev);
                        const eventShifts = shifts[eventId] || [];
                        return (
                            <div key={eventId} style={{ border: '1px solid #eee', padding: '12px', borderRadius: '3px', marginBottom: '12px' }}>
                                <h4>{ev.post_title} ({ev.ems_event_code})</h4>
                                <table className="widefat">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Daytime</th>
                                            <th>Overnight</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {eventDates.map((date, idx) => (
                                            <tr key={date}>
                                                <td>{date}</td>
                                                <td>
                                                    <input
                                                        type="checkbox"
                                                        checked={eventShifts.some(s => s.date === date && s.overnight === 0)}
                                                        onChange={() => handleShiftToggle(eventId, date, 0)}
                                                    />
                                                </td>
                                                <td>
                                                    {idx < eventDates.length - 1 ? (
                                                        <input
                                                            type="checkbox"
                                                            checked={eventShifts.some(s => s.date === date && s.overnight === 1)}
                                                            onChange={() => handleShiftToggle(eventId, date, 1)}
                                                        />
                                                    ) : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        );
                    })}
                    <button className="button" onClick={() => setStep(2)}>Back</button>
                    <button className="button button-primary" style={{ marginLeft: '10px' }} onClick={() => setStep(4)}>Next: Contact Details</button>
                </div>
            )}

            {step === 4 && (
                <div>
                    <h3>Step 3: Contact & Roles</h3>
                    <div style={{ display: 'grid', gap: '10px', marginBottom: '16px' }}>
                        <label>
                            First Name *
                            <input type="text" className="regular-text" value={firstName} onChange={e => setFirstName(e.target.value)} disabled={isOSM} style={{ display: 'block', width: '100%' }} />
                        </label>
                        <label>
                            Last Name
                            <input type="text" className="regular-text" value={lastName} onChange={e => setLastName(e.target.value)} disabled={isOSM} style={{ display: 'block', width: '100%' }} />
                        </label>
                        <label>
                            Email Address *
                            <input type="email" className="regular-text" value={email} onChange={e => setEmail(e.target.value)} disabled={isOSM} style={{ display: 'block', width: '100%' }} />
                        </label>
                        <label>
                            Phone
                            <input type="tel" className="regular-text" value={phone} onChange={e => setPhone(e.target.value)} style={{ display: 'block', width: '100%' }} />
                        </label>
                        <label>
                            DBS Number
                            <input type="text" className="regular-text" value={dbsNumber} onChange={e => setDbsNumber(e.target.value)} style={{ display: 'block', width: '100%' }} />
                        </label>
                        <label>
                            First Aid Qualification
                            <select value={firstAid} onChange={e => setFirstAid(e.target.value)} style={{ display: 'block', width: '100%' }}>
                                <option value="none">None</option>
                                <option value="first_response">First Response</option>
                                <option value="full_first_aid">Full First Aid</option>
                            </select>
                        </label>
                        <div>
                            <strong>Preferred Roles</strong>
                            {['supervisor', 'assessor', 'basecamp'].map(role => (
                                <label key={role} style={{ display: 'block', margin: '4px 0' }}>
                                    <input
                                        type="checkbox"
                                        checked={roles.includes(role)}
                                        onChange={() => setRoles(prev => prev.includes(role) ? prev.filter(r => r !== role) : [...prev, role])}
                                    />
                                    {role.toUpperCase()}
                                </label>
                            ))}
                        </div>
                    </div>
                    <button className="button" onClick={() => setStep(hasPartialAvailability ? 3 : 2)}>Back</button>
                    <button className="button button-primary" style={{ marginLeft: '10px' }} onClick={handleSubmit}>Submit Availability</button>
                </div>
            )}
        </div>
    );
}

document.addEventListener('DOMContentLoaded', () => {
    const rootEl = document.getElementById('ems-volunteer-signup-root');
    if (rootEl) {
        const root = createRoot(rootEl);
        root.render(<VolunteerSignupWizard />);
    }
});
