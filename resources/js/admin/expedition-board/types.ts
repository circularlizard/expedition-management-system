export interface TrainingSummary {
    total: number;
    complete: number;
    percent: number;
}

export interface Member {
    id?: number;
    user_id: number;
    first_name: string;
    last_name: string;
    scout_id?: number;
    patrol?: string;
    unit?: string;
    training?: TrainingSummary;
}

export interface Team {
    ID: number;
    post_title: string;
    ems_team_code: string;
    ems_team_number: number;
    event_id: number;
    member_count?: number;
    size_warning?: boolean;
    members?: Member[];
}

export interface Expedition {
    ID: number;
    post_title: string;
    season_id?: number;
    ems_event_code: string;
    ems_expedition_code?: string;
    ems_type: 'training' | 'practice' | 'qualifying';
    ems_transport?: 'hillwalking' | 'biking' | 'paddling';
    ems_level: 'bronze' | 'silver' | 'gold';
    ems_start_date: string;
    ems_end_date: string;
    ems_start_time?: string;
    ems_end_time?: string;
    ems_lic_name?: string;
    ems_lic_phone?: string;
    ems_start_location?: string;
    ems_end_location?: string;
    ems_route_info?: string;
    ems_route_deadline?: string;
    ems_osm_event_id?: number | string;
    ems_status?: string;
    teams: Team[];
    member_count?: number;
}

export interface Season {
    ID: number;
    post_title: string;
    ems_season_year: string;
    ems_season_status: 'active' | 'archived';
    events: Expedition[];
}

export interface BoardData {
    seasons: Season[];
    explorers?: Explorer[];
    last_sync?: string | null;
}

export interface BoardConfig {
    root_url: string;
    nonce: string;
}

export interface Explorer {
    scout_id: number;
    first_name: string;
    last_name: string;
    wp_user_id?: number;
    patrol?: string;
}

declare global {
    interface Window {
        emsExpeditionBoard: BoardConfig;
    }
}
