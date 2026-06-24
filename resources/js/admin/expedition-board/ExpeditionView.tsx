import React, { useState } from 'react';
import { BoardData, Expedition, Team, Member, FirstAidLevel } from './types';

interface ExpeditionViewProps {
    data: BoardData;
}

const FA_LABELS: Record<FirstAidLevel, string> = {
    none: 'None',
    first_response: 'First Response',
    full_first_aid: 'Full First Aid',
};

const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

function editUrl(postId: number): string {
    const base = window.emsExpeditionBoard?.admin_url ?? '/wp-admin/post.php';
    return `${base}?post=${postId}&action=edit`;
}

function sortedMembers(members: Member[]): Member[] {
    return [...members].sort((a, b) =>
        `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`)
    );
}

function FaIcon({ level }: { level?: FirstAidLevel }) {
    if (level === 'full_first_aid') {
        return <span title="Full First Aid" style={{ color: '#1b5e20', fontWeight: 'bold', marginRight: '4px' }}>⊕</span>;
    }
    if (level === 'first_response') {
        return <span title="First Response" style={{ color: '#2e7d32', fontWeight: 'bold', marginRight: '4px' }}>✚</span>;
    }
    return null;
}

const FaKey: React.FC = () => (
    <div style={{ display: 'flex', gap: '16px', fontSize: '11px', color: '#555', marginTop: '8px', marginBottom: '4px' }}>
        <span><span style={{ color: '#1b5e20', fontWeight: 'bold' }}>⊕</span> Full First Aid</span>
        <span><span style={{ color: '#2e7d32', fontWeight: 'bold' }}>✚</span> First Response</span>
    </div>
);

const MetaRow: React.FC<{ label: string; value: React.ReactNode }> = ({ label, value }) => (
    <tr>
        <th style={{ textAlign: 'left', fontWeight: 600, paddingRight: '16px', paddingTop: '4px', paddingBottom: '4px', whiteSpace: 'nowrap', color: '#555', width: '160px' }}>
            {label}
        </th>
        <td style={{ paddingTop: '4px', paddingBottom: '4px' }}>{value || <span style={{ color: '#aaa' }}>—</span>}</td>
    </tr>
);

const TeamRow: React.FC<{ team: Team }> = ({ team }) => {
    const members = sortedMembers(team.members ?? []);
    const size = team.member_count ?? members.length;
    const hasFa = members.some((m) => m.first_aid_level && m.first_aid_level !== 'none');
    const faBadge = hasFa ? (
        <span style={{ display: 'inline-block', background: '#00a32a', color: '#fff', borderRadius: '3px', padding: '1px 7px', fontSize: '11px' }}>
            First Aid ✓
        </span>
    ) : (
        <span style={{ display: 'inline-block', background: '#d63638', color: '#fff', borderRadius: '3px', padding: '1px 7px', fontSize: '11px' }}>
            No First Aid
        </span>
    );

    const sizeColor = size < 4 || size > 7 ? '#d63638' : '#1d2327';

    return (
        <tr>
            <td style={{ fontWeight: 600, verticalAlign: 'top' }}>{team.ems_team_code}</td>
            <td style={{ color: sizeColor, fontWeight: size < 4 || size > 7 ? 600 : 400, verticalAlign: 'top' }}>
                {size}
                {(size < 4 || size > 7) && (
                    <span style={{ fontSize: '11px', marginLeft: '4px', color: '#d63638' }}>⚠</span>
                )}
            </td>
            <td style={{ verticalAlign: 'top' }}>{faBadge}</td>
            <td style={{ fontSize: '12px', verticalAlign: 'top' }}>
                {members.length === 0 ? (
                    <span style={{ color: '#aaa' }}>—</span>
                ) : (
                    <ul style={{ margin: 0, padding: 0, listStyle: 'none' }}>
                        {members.map((m) => (
                            <li key={m.scout_id ?? m.user_id} style={{ display: 'flex', alignItems: 'center', padding: '1px 0' }}>
                                <FaIcon level={m.first_aid_level} />
                                {m.first_name} {m.last_name}
                            </li>
                        ))}
                    </ul>
                )}
            </td>
        </tr>
    );
};

const ExpeditionDetail: React.FC<{ expedition: Expedition }> = ({ expedition: e }) => {
    const totalMembers = e.teams.reduce((acc, t) => acc + (t.member_count ?? t.members?.length ?? 0), 0);

    return (
        <div style={{ background: '#fff', border: '1px solid #ddd', borderRadius: '4px', padding: '20px' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '16px' }}>
                <h2 style={{ margin: 0, fontSize: '18px' }}>
                    {e.post_title}{' '}
                    <span style={{ fontWeight: 400, fontSize: '14px', color: '#666' }}>({e.ems_event_code})</span>
                </h2>
                <a
                    href={editUrl(e.ID)}
                    target="_blank"
                    rel="noreferrer"
                    className="button"
                    style={{ flexShrink: 0, marginLeft: '16px' }}
                >
                    Edit expedition ↗
                </a>
            </div>

            <table style={{ borderCollapse: 'collapse', marginBottom: '24px' }}>
                <tbody>
                    <MetaRow label="Type" value={capitalize(e.ems_type)} />
                    <MetaRow label="Transport" value={e.ems_transport ? capitalize(e.ems_transport) : null} />
                    <MetaRow label="Level" value={e.ems_level ? capitalize(e.ems_level) : null} />
                    <MetaRow label="Status" value={e.ems_status ? capitalize(e.ems_status) : null} />
                    <MetaRow
                        label="Dates"
                        value={
                            e.ems_start_date
                                ? `${e.ems_start_date}${e.ems_start_time ? ' ' + e.ems_start_time : ''} → ${e.ems_end_date ?? ''}${e.ems_end_time ? ' ' + e.ems_end_time : ''}`
                                : null
                        }
                    />
                    <MetaRow label="Start location" value={e.ems_start_location} />
                    <MetaRow label="End location" value={e.ems_end_location} />
                    <MetaRow label="LiC name" value={e.ems_lic_name} />
                    <MetaRow label="LiC email" value={e.ems_lic_email} />
                    <MetaRow label="LiC phone" value={e.ems_lic_phone} />
                    <MetaRow
                        label="Route notes"
                        value={
                            e.ems_route_info
                                ? <div dangerouslySetInnerHTML={{ __html: e.ems_route_info }} style={{ maxWidth: '600px' }} />
                                : null
                        }
                    />
                    <MetaRow label="Route deadline" value={e.ems_route_deadline} />
                    <MetaRow label="First aid req." value={e.ems_first_aid_level ? FA_LABELS[e.ems_first_aid_level] : null} />
                    <MetaRow label="Total explorers" value={totalMembers > 0 ? String(totalMembers) : null} />
                </tbody>
            </table>

            <h3 style={{ marginTop: 0, marginBottom: '4px', fontSize: '15px' }}>
                Teams ({e.teams.length})
            </h3>
            <FaKey />

            {e.teams.length === 0 ? (
                <p style={{ color: '#666' }}>No teams yet.</p>
            ) : (
                <table className="widefat striped" style={{ fontSize: '13px', marginTop: '8px' }}>
                    <thead>
                        <tr>
                            <th>Team</th>
                            <th>Size</th>
                            <th>First Aid</th>
                            <th>Members (A–Z)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {e.teams.map((team) => (
                            <TeamRow key={team.ID} team={team} />
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
};

export const ExpeditionView: React.FC<ExpeditionViewProps> = ({ data }) => {
    const allExpeditions: Expedition[] = (data.seasons ?? []).flatMap((s) => s.events);

    const [selectedId, setSelectedId] = useState<number | null>(allExpeditions[0]?.ID ?? null);

    const selected = allExpeditions.find((e) => e.ID === selectedId) ?? null;

    if (allExpeditions.length === 0) {
        return <div className="notice notice-info"><p>No expeditions found. Create a season and add expeditions first.</p></div>;
    }

    return (
        <div className="ems-expedition-view">
            <div style={{ marginBottom: '16px', display: 'flex', alignItems: 'center', gap: '12px' }}>
                <label htmlFor="expedition-view-select" style={{ fontWeight: 600 }}>Expedition:</label>
                <select
                    id="expedition-view-select"
                    aria-label="Select expedition"
                    value={selectedId ?? ''}
                    onChange={(e) => setSelectedId(Number(e.target.value))}
                    style={{ minWidth: '260px' }}
                >
                    {data.seasons.map((season) => (
                        <optgroup key={season.ID} label={season.post_title}>
                            {season.events.map((ev) => (
                                <option key={ev.ID} value={ev.ID}>
                                    {ev.ems_event_code} — {ev.post_title}
                                </option>
                            ))}
                        </optgroup>
                    ))}
                </select>
            </div>

            {selected && <ExpeditionDetail expedition={selected} />}
        </div>
    );
};
