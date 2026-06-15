export interface TrainingSummary {
    total: number;
    complete: number;
    percent: number;
}

export interface Member {
    id: string;
    user_id: string;
    first_name: string;
    last_name: string;
    scout_id: string;
    unit: string;
    training: TrainingSummary;
}

export interface Team {
    ID: number;
    post_title: string;
    ems_team_code: string;
    ems_expedition_id: string;
}

export interface Expedition {
    ID: number;
    post_title: string;
    ems_expedition_code: string;
    ems_start_date: string;
    ems_end_date: string;
    ems_level: string;
    ems_status: string;
}

export interface BoardData {
    expeditions: Expedition[];
    teams: Record<number, Team[]>;
    members: Record<number, Member[]>;
    explorers: Member[];
    last_sync: string | null;
}

export interface BoardConfig {
    root_url: string;
    nonce: string;
}

declare global {
    interface Window {
        emsExpeditionBoard: BoardConfig;
    }
}
