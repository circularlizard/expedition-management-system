export interface ReconciliationEntry {
    email: string;
    first_name: string;
    last_name: string;
    member_id?: number;
    gf_id?: string;
}

export interface ReconciliationData {
    matched: ReconciliationEntry[];
    only_in_osm: ReconciliationEntry[];
    only_in_gf: ReconciliationEntry[];
}

declare global {
    interface Window {
        emsReconciliation: ReconciliationData;
    }
}
