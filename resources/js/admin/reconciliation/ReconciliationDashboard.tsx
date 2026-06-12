import React from 'react';
import { ReconciliationData, ReconciliationEntry } from './types';

interface Props {
    data: ReconciliationData;
}

function EntryTable({ entries, emptyMessage }: { entries: ReconciliationEntry[]; emptyMessage: string }) {
    if (entries.length === 0) {
        return <p className="ems-empty">{emptyMessage}</p>;
    }
    return (
        <table className="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                {entries.map((entry) => (
                    <tr key={entry.email}>
                        <td>{entry.first_name} {entry.last_name}</td>
                        <td>{entry.email}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export function ReconciliationDashboard({ data }: Props) {
    const { matched, only_in_osm, only_in_gf } = data;

    return (
        <div className="ems-reconciliation">
            <section aria-labelledby="matched-heading">
                <h2 id="matched-heading">Matched ({matched.length})</h2>
                <EntryTable entries={matched} emptyMessage="No matched entries." />
            </section>

            <section aria-labelledby="osm-only-heading">
                <h2 id="osm-only-heading">In OSM only ({only_in_osm.length})</h2>
                <EntryTable entries={only_in_osm} emptyMessage="No OSM-only entries." />
            </section>

            <section aria-labelledby="gf-only-heading">
                <h2 id="gf-only-heading">In Gravity Forms only ({only_in_gf.length})</h2>
                <EntryTable entries={only_in_gf} emptyMessage="No Gravity Forms-only entries." />
            </section>
        </div>
    );
}
