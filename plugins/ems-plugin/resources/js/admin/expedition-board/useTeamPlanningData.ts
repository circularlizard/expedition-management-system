import { useState, useEffect, useMemo, useCallback } from 'react';
import {
  Cohort,
  MatrixEvent,
  MatrixExplorer,
  toCohortTransportMode,
} from './cohortTypes';

export interface UseTeamPlanningDataResult {
  cohorts: Cohort[];
  practiceEvents: MatrixEvent[];
  qualifierEvents: MatrixEvent[];
  practiceExplorers: MatrixExplorer[];
  qualifierExplorers: MatrixExplorer[];
  pendingNotifyCount: number;
  loading: boolean;
  error: string | null;
  feedback: { ok: boolean; msg: string } | null;
  setFeedback: (fb: { ok: boolean; msg: string } | null) => void;
  fetchAllData: () => Promise<void>;
}

export function useTeamPlanningData(
  level: 'bronze' | 'silver' | 'gold',
  transport: string,
  enabled: boolean
): UseTeamPlanningDataResult {
  const config = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '' };
  const rootUrl = config.root_url;
  const nonce = config.nonce;

  const [cohorts, setCohorts] = useState<Cohort[]>([]);
  const [practiceEvents, setPracticeEvents] = useState<MatrixEvent[]>([]);
  const [qualifierEvents, setQualifierEvents] = useState<MatrixEvent[]>([]);
  const [practiceExplorers, setPracticeExplorers] = useState<MatrixExplorer[]>([]);
  const [qualifierExplorers, setQualifierExplorers] = useState<MatrixExplorer[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<{ ok: boolean; msg: string } | null>(null);

  const fetchAllData = useCallback(async () => {
    if (!enabled) return;
    setLoading(true);
    setError(null);

    const transportMode = toCohortTransportMode(transport);
    const teamParams = new URLSearchParams({
      dofe_level: level,
      transport_mode: transportMode,
    });
    const practiceParams = new URLSearchParams({
      level,
      transport,
      type: 'practice',
    });
    const qualifyingParams = new URLSearchParams({
      level,
      transport,
      type: 'qualifying',
    });

    try {
      const [teamsResp, practiceResp, qualifyingResp] = await Promise.all([
        fetch(`${rootUrl}/teams-builder?${teamParams.toString()}`, {
          headers: { 'X-WP-Nonce': nonce },
        }),
        fetch(`${rootUrl}/planning-board/matrix?${practiceParams.toString()}`, {
          headers: { 'X-WP-Nonce': nonce },
        }),
        fetch(`${rootUrl}/planning-board/matrix?${qualifyingParams.toString()}`, {
          headers: { 'X-WP-Nonce': nonce },
        }),
      ]);

      if (!teamsResp.ok) {
        throw new Error(`Failed to load teams (HTTP ${teamsResp.status})`);
      }
      if (!practiceResp.ok) {
        throw new Error(`Failed to load practice matrix (HTTP ${practiceResp.status})`);
      }
      if (!qualifyingResp.ok) {
        throw new Error(`Failed to load qualifying matrix (HTTP ${qualifyingResp.status})`);
      }

      const [teamsData, practiceData, qualifyingData] = await Promise.all([
        teamsResp.json(),
        practiceResp.json(),
        qualifyingResp.json(),
      ]);

      setCohorts(Array.isArray(teamsData) ? teamsData : []);
      setPracticeEvents(Array.isArray(practiceData?.events) ? practiceData.events : []);
      setPracticeExplorers(Array.isArray(practiceData?.explorers) ? practiceData.explorers : []);
      setQualifierEvents(Array.isArray(qualifyingData?.events) ? qualifyingData.events : []);
      setQualifierExplorers(Array.isArray(qualifyingData?.explorers) ? qualifyingData.explorers : []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load planning data');
    } finally {
      setLoading(false);
    }
  }, [enabled, level, transport, rootUrl, nonce]);

  useEffect(() => {
    if (enabled) {
      fetchAllData();
    }
  }, [enabled, fetchAllData]);

  const pendingNotifyCount = useMemo(() => {
    let count = 0;
    for (const c of cohorts) {
      for (const m of c.members || []) {
        const status = (m.notification_status || 'draft').toLowerCase();
        if (status === 'draft' || status === 're_notify_needed') {
          count += 1;
        }
      }
    }
    return count;
  }, [cohorts]);

  return {
    cohorts,
    practiceEvents,
    qualifierEvents,
    practiceExplorers,
    qualifierExplorers,
    pendingNotifyCount,
    loading,
    error,
    feedback,
    setFeedback,
    fetchAllData,
  };
}
