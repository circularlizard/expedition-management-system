import { useState, useEffect, useCallback } from 'react';
import { BoardData } from './types';

export interface UseBoardResult {
    data: BoardData | null;
    loading: boolean;
    error: string | null;
    refetch: () => void;
}

export function useBoard(): UseBoardResult {
    const config = window.emsExpeditionBoard;
    const [data, setData] = useState<BoardData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const fetchData = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await fetch(`${config.root_url}/expedition-board`, {
                headers: { 'X-WP-Nonce': config.nonce },
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const result = await response.json();
            setData(result);
        } catch (err) {
            setError(err instanceof Error ? err.message : 'Failed to load board data');
        } finally {
            setLoading(false);
        }
    }, [config.root_url, config.nonce]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    return { data, loading, error, refetch: fetchData };
}
