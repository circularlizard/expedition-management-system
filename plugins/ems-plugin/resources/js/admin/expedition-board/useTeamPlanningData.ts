import { useState, useEffect, useMemo, useCallback } from 'react';
import {
  Cohort,
  MatrixEvent,
  MatrixExplorer,
  toCohortTransportMode,
} from './cohortTypes';
import type { PlanningLevel } from './TeamScopeBar';

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

function dedupeByKey<T>(items: T[], getKey: (item: T) => string | number): T[] {
  const seen = new Map<string | number, T>();
  for (const item of items) {
    const key = getKey(item);
    if (!seen.has(key)) {
      seen.set(key, item);
    }
  }
  return Array.from(seen.values());
}

const getCohortKey = (c: Cohort): string | number => {
  const teamPostId = (c as Cohort & { team_post_id?: number }).team_post_id;
  if (c.id && c.id > 0) return `c_${c.id}`;
  if (teamPostId && teamPostId > 0) return `tp_${teamPostId}`;
  return `${c.dofe_level}_${c.name}`;
};

const getEventKey = (e: MatrixEvent): string | number => e.id || e.event_code;

const getExplorerKey = (exp: MatrixExplorer): string =>
  exp.expedition_signup_id > 0
    ? `s_${exp.expedition_signup_id}`
    : `o_${exp.scout_id}_${exp.first_name}_${exp.last_name}`;

export function useTeamPlanningData(
  levels: PlanningLevel[],
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

  const levelsKey = levels.join(',');

  const fetchAllData = useCallback(async () => {
    if (!enabled) return;
    const activeLevels: PlanningLevel[] = levels.length > 0 ? levels : ['silver'];
    setLoading(true);
    setError(null);

    const transportMode = toCohortTransportMode(transport);

    try {
      const levelResults = await Promise.all(
        activeLevels.map(async (level) => {
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

          return {
            cohorts: Array.isArray(teamsData) ? (teamsData as Cohort[]) : [],
            practiceEvents: Array.isArray(practiceData?.events) ? (practiceData.events as MatrixEvent[]) : [],
            practiceExplorers: Array.isArray(practiceData?.explorers) ? (practiceData.explorers as MatrixExplorer[]) : [],
            qualifierEvents: Array.isArray(qualifyingData?.events) ? (qualifyingData.events as MatrixEvent[]) : [],
            qualifierExplorers: Array.isArray(qualifyingData?.explorers) ? (qualifyingData.explorers as MatrixExplorer[]) : [],
          };
        })
      );

      setCohorts(dedupeByKey(levelResults.flatMap((r) => r.cohorts), getCohortKey));
      setPracticeEvents(dedupeByKey(levelResults.flatMap((r) => r.practiceEvents), getEventKey));
      setPracticeExplorers(dedupeByKey(levelResults.flatMap((r) => r.practiceExplorers), getExplorerKey));
      setQualifierEvents(dedupeByKey(levelResults.flatMap((r) => r.qualifierEvents), getEventKey));
      setQualifierExplorers(dedupeByKey(levelResults.flatMap((r) => r.qualifierExplorers), getExplorerKey));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load planning data');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, levelsKey, transport, rootUrl, nonce]);

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
