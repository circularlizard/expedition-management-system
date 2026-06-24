import { useState, useEffect, useCallback } from 'react';
import { OSMEvent } from './types';

export interface UseOSMEventsResult {
    events: OSMEvent[];
    loading: boolean;
    error: string | null;
    refetch: () => void;
}

export function useOSMEvents(): UseOSMEventsResult {
    const config = window.emsExpeditionBoard;
    const [events, setEvents] = useState<OSMEvent[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const fetchEvents = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await fetch(`${config.root_url}/osm-events`, {
                headers: { 'X-WP-Nonce': config.nonce },
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const result = await response.json();
            setEvents(result ?? []);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Failed to load OSM events');
        } finally {
            setLoading(false);
        }
    }, [config.root_url, config.nonce]);

    useEffect(() => {
        fetchEvents();
    }, [fetchEvents]);

    return { events, loading, error, refetch: fetchEvents };
}
