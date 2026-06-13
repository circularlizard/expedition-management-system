export interface Column {
    id: string;
    name: string;
}

export interface Section {
    name: string;
    extraid: string;
    [key: string]: any;
}

export interface ColumnMapperConfig {
    root_url: string;
    nonce: string;
    sections: Record<string, Section>;
}

declare global {
    interface Window {
        emsColumnMapper: ColumnMapperConfig;
    }
}
