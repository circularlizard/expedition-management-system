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
        setSelectedEvents(prev =>
            prev.includes(eventId) ? prev.filter(id => id !== eventId) : [...prev, eventId]
        );
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

    const handleCopyAvailability = (sourceEventId: number) => {
        const sourceShifts = shifts[sourceEventId] || [];
        const updated = { ...shifts };
        selectedEvents.forEach(targetId => {
            if (targetId !== sourceEventId) {
                // Copy shifts matching by relative offsets if dates differ, or directly copy if identical structure.
                // Simple drop-in copy for this stage:
                updated[targetId] = [...sourceShifts];
            }
        });
        setShifts(updated);
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
                    <h3>Step 1: Select Events</h3>
                    {events.map(e => (
                        <label key={e.ID} style={{ display: 'block', margin: '8px 0', cursor: 'pointer' }}>
                            <input type="checkbox" checked={selectedEvents.includes(e.ID)} onChange={() => toggleEvent(e.ID)} />
                            <strong>{e.post_title}</strong> ({e.ems_event_code})
                        </label>
                    ))}
                    <button className="button button-primary" style={{ marginTop: '16px' }} onClick={() => setStep(3)}>Next: Availability Builder</button>
                </div>
            )}

            {step === 3 && (
                <div>
                    <h3>Step 2: Availability Builder</h3>
                    {selectedEvents.map(eventId => {
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
                                        {eventDates.map(date => (
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
                                                    <input
                                                        type="checkbox"
                                                        checked={eventShifts.some(s => s.date === date && s.overnight === 1)}
                                                        onChange={() => handleShiftToggle(eventId, date, 1)}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                <div style={{ marginTop: '8px', display: 'flex', gap: '8px' }}>
                                    <button className="button button-small" onClick={() => handleCopyAvailability(eventId)}>Copy availability to other events</button>
                                </div>
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
                    <button className="button" onClick={() => setStep(3)}>Back</button>
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
