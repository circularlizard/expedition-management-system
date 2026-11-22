import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faPersonHiking,
  faBicycle,
  faWater,
  faEnvelope,
  faLayerGroup,
  faTableCells,
} from '@fortawesome/free-solid-svg-icons';
import CohortsContinuityView from './CohortsContinuityView';
import StageMatrixView from './StageMatrixView';
import { ManualNotifyModal } from './ManualNotifyModal';
import Explorer360Drawer from '../shared/Explorer360Drawer';
import { ModuleErrorBoundary } from '../../shared/ModuleErrorBoundary';
import {
  Cohort,
  CohortPlanningMatrixProps,
  MatrixEvent,
  MatrixExplorer,
  NotifyModalOptions,
  toCohortTransportMode,
} from './cohortTypes';

export type { CohortPlanningMatrixProps };

export default function CohortPlanningMatrix({
  onViewAsn,
  onOpenNotifyModal,
}: CohortPlanningMatrixProps = {}) {
  const config = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '' };
  const rootUrl = config.root_url;
  const nonce = config.nonce;
  const units: any[] = config.units || [];

  // ── Layer 1: Hard Filter / Cohort Scope ─────────────────────────────────────
  const [level, setLevel] = useState<'bronze' | 'silver' | 'gold'>('silver');
  const [transport, setTransport] = useState<string>('hillwalking');
  const [activeView, setActiveView] = useState<'cohorts' | 'matrix'>('cohorts');

  const allowedTransports = useMemo(
    () => [
      { key: 'hillwalking', label: 'Hillwalking', icon: faPersonHiking },
      { key: 'biking', label: 'Biking', icon: faBicycle },
      { key: 'paddling', label: 'Paddling', icon: faWater },
    ],
    []
  );

  // ── Data State ──────────────────────────────────────────────────────────────
  const [cohorts, setCohorts] = useState<Cohort[]>([]);
  const [practiceEvents, setPracticeEvents] = useState<MatrixEvent[]>([]);
  const [qualifierEvents, setQualifierEvents] = useState<MatrixEvent[]>([]);
  const [practiceExplorers, setPracticeExplorers] = useState<MatrixExplorer[]>([]);
  const [qualifierExplorers, setQualifierExplorers] = useState<MatrixExplorer[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<{ ok: boolean; msg: string } | null>(null);
  const [notifyModalOpts, setNotifyModalOpts] = useState<NotifyModalOptions | null>(null);
  const [drawerTarget, setDrawerTarget] = useState<{ scoutId?: number; signupId?: number } | null>(null);

  const handleOpenNotifyModal = useCallback(
    (opts: NotifyModalOptions) => {
      if (onOpenNotifyModal) {
        onOpenNotifyModal(opts);
      }
      setNotifyModalOpts(opts);
    },
    [onOpenNotifyModal]
  );

  const fetchAllData = useCallback(async () => {
    setLoading(true);
    setError(null);

    const transportMode = toCohortTransportMode(transport);
    const cohortParams = new URLSearchParams({
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
      const [cohortsResp, practiceResp, qualifyingResp] = await Promise.all([
        fetch(`${rootUrl}/teams-builder?${cohortParams.toString()}`, {
          headers: { 'X-WP-Nonce': nonce },
        }),
        fetch(`${rootUrl}/planning-board/matrix?${practiceParams.toString()}`, {
          headers: { 'X-WP-Nonce': nonce },
        }),
        fetch(`${rootUrl}/planning-board/matrix?${qualifyingParams.toString()}`, {
          headers: { 'X-WP-Nonce': nonce },
        }),
      ]);

      if (!cohortsResp.ok) {
        throw new Error(`Failed to load teams (HTTP ${cohortsResp.status})`);
      }
      if (!practiceResp.ok) {
        throw new Error(`Failed to load practice matrix (HTTP ${practiceResp.status})`);
      }
      if (!qualifyingResp.ok) {
        throw new Error(`Failed to load qualifying matrix (HTTP ${qualifyingResp.status})`);
      }

      const [cohortsData, practiceData, qualifyingData] = await Promise.all([
        cohortsResp.json(),
        practiceResp.json(),
        qualifyingResp.json(),
      ]);

      setCohorts(Array.isArray(cohortsData) ? cohortsData : []);
      setPracticeEvents(Array.isArray(practiceData?.events) ? practiceData.events : []);
      setPracticeExplorers(Array.isArray(practiceData?.explorers) ? practiceData.explorers : []);
      setQualifierEvents(Array.isArray(qualifyingData?.events) ? qualifyingData.events : []);
      setQualifierExplorers(Array.isArray(qualifyingData?.explorers) ? qualifyingData.explorers : []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load planning data');
    } finally {
      setLoading(false);
    }
  }, [level, transport, rootUrl, nonce]);

  useEffect(() => {
    fetchAllData();
  }, [fetchAllData]);

  // Live counter badge for draft + re_notify_needed members across cohorts
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

  return (
    <div className="ems-planning-matrix">
      {/* ── Feedback Notice ── */}
      {feedback && (
        <div className={`notice ${feedback.ok ? 'notice-success' : 'notice-error'} is-dismissible ems-mb-16`}>
          <p>{feedback.msg}</p>
          <button type="button" className="notice-dismiss" onClick={() => setFeedback(null)}>
            <span className="screen-reader-text">Dismiss</span>
          </button>
        </div>
      )}

      {error && (
        <div className="notice notice-error ems-mb-16">
          <p>{error}</p>
        </div>
      )}

      {/* ── LAYER 1: Hard Filter / Cohort Scope + View Switcher + Notify Action ── */}
      <div
        className="ems-planning-scope-bar"
        role="region"
        aria-label="Team Scope Filters"
        style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '12px' }}
      >
        <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '16px' }}>
          <div className="ems-planning-scope-group">
            <span className="ems-planning-scope-label">Level:</span>
            <div className="ems-btn-group" role="group" aria-label="Level Selector">
              {(['bronze', 'silver', 'gold'] as const).map(l => (
                <button
                  key={l}
                  type="button"
                  className={`ems-scope-btn ${level === l ? 'ems-scope-btn--active' : ''}`}
                  onClick={() => setLevel(l)}
                >
                  {l.charAt(0).toUpperCase() + l.slice(1)}
                </button>
              ))}
            </div>
          </div>

          <div className="ems-planning-scope-group">
            <span className="ems-planning-scope-label">Mode:</span>
            <div className="ems-btn-group" role="group" aria-label="Transport Mode Selector">
              {allowedTransports.map(t => (
                <button
                  key={t.key}
                  type="button"
                  className={`ems-scope-btn ${transport === t.key ? 'ems-scope-btn--active' : ''}`}
                  onClick={() => setTransport(t.key)}
                >
                  <FontAwesomeIcon icon={t.icon} className="ems-scope-btn-icon" /> {t.label}
                </button>
              ))}
            </div>
          </div>

          <div className="ems-planning-scope-group">
            <span className="ems-planning-scope-label">VIEW:</span>
            <div className="ems-btn-group" role="group" aria-label="Planning Sub-View">
              <button
                type="button"
                className={`ems-scope-btn ${activeView === 'cohorts' ? 'ems-scope-btn--active' : ''}`}
                onClick={() => setActiveView('cohorts')}
              >
                <FontAwesomeIcon icon={faLayerGroup} className="ems-scope-btn-icon" /> 1. Team Builder
              </button>
              <button
                type="button"
                className={`ems-scope-btn ${activeView === 'matrix' ? 'ems-scope-btn--active' : ''}`}
                onClick={() => setActiveView('matrix')}
              >
                <FontAwesomeIcon icon={faTableCells} className="ems-scope-btn-icon" /> 2. Date Grid
              </button>
            </div>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <button
            type="button"
            className="button button-primary"
            onClick={() =>
              handleOpenNotifyModal({
                scope: 'all',
                level,
                transport,
              })
            }
          >
            <FontAwesomeIcon icon={faEnvelope} /> Notify Teams…
            {pendingNotifyCount > 0 && (
              <span
                className="ems-badge"
                style={{
                  marginLeft: '6px',
                  background: '#ffffff',
                  color: '#1e40af',
                  fontWeight: 700,
                  padding: '1px 6px',
                  borderRadius: '10px',
                  fontSize: '11px',
                }}
              >
                {pendingNotifyCount}
              </span>
            )}
          </button>
        </div>
      </div>

      {/* ── Active Sub-View Content ── */}
      {loading ? (
        <div className="ems-planning-spinner">
          <span className="ems-spinner" aria-label="Loading team planning board…" />
        </div>
      ) : activeView === 'cohorts' ? (
        <ModuleErrorBoundary moduleName="Team Builder">
          <CohortsContinuityView
            level={level}
            transport={transport}
            rootUrl={rootUrl}
            nonce={nonce}
            units={units}
            cohorts={cohorts}
            practiceEvents={practiceEvents}
            qualifierEvents={qualifierEvents}
            practiceExplorers={practiceExplorers}
            qualifierExplorers={qualifierExplorers}
            onRefreshAll={fetchAllData}
            onFeedback={setFeedback}
            onViewAsn={onViewAsn}
            onOpenNotifyModal={handleOpenNotifyModal}
          />
        </ModuleErrorBoundary>
      ) : (
        <ModuleErrorBoundary moduleName="Date Grid">
          <StageMatrixView
            level={level}
            transport={transport}
            rootUrl={rootUrl}
            nonce={nonce}
            units={units}
            cohorts={cohorts}
            practiceEvents={practiceEvents}
            qualifierEvents={qualifierEvents}
            practiceExplorers={practiceExplorers}
            qualifierExplorers={qualifierExplorers}
            onRefreshAll={fetchAllData}
            onFeedback={setFeedback}
          />
        </ModuleErrorBoundary>
      )}

      {notifyModalOpts && (
        <ModuleErrorBoundary moduleName="Place Offer Notifications">
          <ManualNotifyModal
            isOpen={true}
            onClose={() => setNotifyModalOpts(null)}
            initialScope={notifyModalOpts.scope}
            cohortId={notifyModalOpts.cohortId ?? null}
            cohortName={notifyModalOpts.cohortName ?? null}
            dofeLevel={notifyModalOpts.level ?? level}
            transportMode={toCohortTransportMode(notifyModalOpts.transport ?? transport)}
            onInspectExplorer={(target) => setDrawerTarget(target)}
            onDispatched={(res) => {
              const count = typeof res?.dispatched_count === 'number' ? res.dispatched_count : undefined;
              setFeedback({
                ok: true,
                msg: count !== undefined
                  ? `Dispatched ${count} Place Offer email${count === 1 ? '' : 's'}.`
                  : 'Place Offer emails dispatched.',
              });
              fetchAllData();
            }}
          />
        </ModuleErrorBoundary>
      )}

      <Explorer360Drawer
        isOpen={drawerTarget !== null}
        scoutId={drawerTarget?.scoutId && drawerTarget.scoutId > 0 ? drawerTarget.scoutId : undefined}
        signupType={drawerTarget?.signupId ? 'expedition' : undefined}
        signupId={drawerTarget?.signupId}
        rootUrl={rootUrl}
        nonce={nonce}
        initialTab="teams"
        onClose={() => setDrawerTarget(null)}
        onUpdated={fetchAllData}
      />
    </div>
  );
}
