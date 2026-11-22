import React, { useState, useEffect, useCallback, useMemo } from 'react';
import TeamEmailDispatchModal, {
  DispatchModalRecipient,
  DispatchModalResult,
  DispatchModalSampleEmail,
  DispatchModalWarning,
} from '../../shared/TeamEmailDispatchModal';

export interface ManualNotifyModalProps {
  isOpen: boolean;
  onClose: () => void;
  initialScope?: 'cohort' | 'all';
  cohortId?: number | null;
  cohortName?: string | null;
  dofeLevel?: string;
  transportMode?: string;
  onInspectExplorer?: (target: { scoutId?: number; signupId?: number }) => void;
  onDispatched?: (result: { dispatched_count?: number; [key: string]: unknown }) => void;
}

export type PreviewWarning = DispatchModalWarning;

export interface PreviewRecipient extends DispatchModalRecipient {
  availability_override?: string;
  dofe_level?: string;
  transport_mode?: string;
}

export type SampleEmail = DispatchModalSampleEmail;

export interface PreviewResponse {
  scope: string;
  recipients: PreviewRecipient[];
  total_count: number;
  sample_email: SampleEmail | null;
  warnings: PreviewWarning[];
}

export interface DispatchResponse extends DispatchModalResult {
  sent_ids?: number[];
  failed_ids?: number[];
  skipped_ids?: number[];
  errors?: Array<{ expedition_signup_id?: number; reason?: string }>;
}

function normalizeTransportOption(raw: string): string {
  const n = (raw || '').trim().toLowerCase();
  if (n === 'foot' || n === 'walking' || n === 'hillwalking') return 'hillwalking';
  if (n === 'canoe' || n === 'paddle' || n === 'paddling') return 'paddling';
  if (n === 'bike' || n === 'cycling' || n === 'biking') return 'biking';
  return n;
}

export const ManualNotifyModal: React.FC<ManualNotifyModalProps> = ({
  isOpen,
  onClose,
  initialScope,
  cohortId = null,
  cohortName = null,
  dofeLevel = '',
  transportMode = '',
  onInspectExplorer,
  onDispatched,
}) => {
  const config = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '' };
  const rootUrl = config.root_url;
  const nonce = config.nonce;

  const hasCohort = Boolean(cohortId && cohortId > 0);
  const resolvedInitialScope: 'cohort' | 'all' =
    initialScope === 'cohort' && hasCohort
      ? 'cohort'
      : initialScope === 'all'
      ? 'all'
      : hasCohort
      ? 'cohort'
      : 'all';

  const [scope, setScope] = useState<'cohort' | 'all'>(resolvedInitialScope);
  const [selectedLevel, setSelectedLevel] = useState<string>(dofeLevel || '');
  const [selectedTransport, setSelectedTransport] = useState<string>(
    normalizeTransportOption(transportMode || '')
  );

  const [preview, setPreview] = useState<PreviewResponse | null>(null);
  const [loadingPreview, setLoadingPreview] = useState<boolean>(false);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [selectedSignupIds, setSelectedSignupIds] = useState<number[]>([]);

  const [dispatching, setDispatching] = useState<boolean>(false);
  const [dispatchError, setDispatchError] = useState<string | null>(null);
  const [dispatchResult, setDispatchResult] = useState<DispatchResponse | null>(null);

  useEffect(() => {
    if (!isOpen) return;
    setScope(resolvedInitialScope);
    setSelectedLevel(dofeLevel || '');
    setSelectedTransport(normalizeTransportOption(transportMode || ''));
    setDispatchError(null);
    setDispatchResult(null);
  }, [isOpen, resolvedInitialScope, dofeLevel, transportMode]);

  const fetchPreview = useCallback(async () => {
    if (!isOpen) return;
    if (scope === 'cohort' && !hasCohort) {
      setPreview({
        scope: 'cohort',
        recipients: [],
        total_count: 0,
        sample_email: null,
        warnings: [],
      });
      setSelectedSignupIds([]);
      return;
    }

    setLoadingPreview(true);
    setPreviewError(null);

    try {
      const payload: Record<string, unknown> = {
        scope,
        cohort_id: scope === 'cohort' ? cohortId : null,
      };
      if (scope === 'all') {
        if (selectedLevel) payload.dofe_level = selectedLevel;
        if (selectedTransport) payload.transport_mode = selectedTransport;
      }

      const res = await fetch(`${rootUrl}/expeditions/notifications/preview`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify(payload),
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.message || `Preview request failed (HTTP ${res.status})`);
      }

      const normalized: PreviewResponse = {
        scope: data.scope || scope,
        recipients: Array.isArray(data.recipients) ? data.recipients : [],
        total_count: typeof data.total_count === 'number' ? data.total_count : 0,
        sample_email: data.sample_email || null,
        warnings: Array.isArray(data.warnings) ? data.warnings : [],
      };

      setPreview(normalized);
      setSelectedSignupIds(
        normalized.recipients
          .filter((r) => r.has_valid_email)
          .map((r) => r.expedition_signup_id)
      );
    } catch (err) {
      setPreviewError(err instanceof Error ? err.message : 'Failed to load notification preview.');
      setPreview(null);
      setSelectedSignupIds([]);
    } finally {
      setLoadingPreview(false);
    }
  }, [isOpen, scope, hasCohort, cohortId, selectedLevel, selectedTransport, rootUrl, nonce]);

  useEffect(() => {
    if (isOpen) {
      fetchPreview();
    }
  }, [isOpen, fetchPreview]);

  const validRecipients = useMemo(
    () => (preview?.recipients || []).filter((r) => r.has_valid_email),
    [preview]
  );

  const allValidSelected =
    validRecipients.length > 0 &&
    validRecipients.every((r) => selectedSignupIds.includes(r.expedition_signup_id));

  const toggleSelectAllValid = () => {
    if (allValidSelected) {
      setSelectedSignupIds([]);
    } else {
      setSelectedSignupIds(validRecipients.map((r) => r.expedition_signup_id));
    }
  };

  const toggleRecipient = (signupId: number) => {
    setSelectedSignupIds((prev) =>
      prev.includes(signupId) ? prev.filter((id) => id !== signupId) : [...prev, signupId]
    );
  };

  const handleDispatch = async () => {
    if (selectedSignupIds.length === 0 || dispatching) return;

    setDispatching(true);
    setDispatchError(null);

    try {
      const res = await fetch(`${rootUrl}/expeditions/notifications/dispatch`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({
          confirm_dispatch: true,
          expedition_signup_ids: selectedSignupIds,
          cohort_id: scope === 'cohort' ? cohortId : null,
        }),
      });

      const data: DispatchResponse = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(
          typeof data.message === 'string'
            ? data.message
            : `Dispatch request failed (HTTP ${res.status})`
        );
      }

      const normalizedResult: DispatchResponse = {
        ...data,
        dispatched_count:
          typeof data.dispatched_count === 'number'
            ? data.dispatched_count
            : typeof data.sent_count === 'number'
            ? data.sent_count
            : selectedSignupIds.length,
      };

      setDispatchResult(normalizedResult);
      if (onDispatched) {
        onDispatched(normalizedResult);
      }
    } catch (err) {
      setDispatchError(err instanceof Error ? err.message : 'Failed to dispatch Place Offer emails.');
    } finally {
      setDispatching(false);
    }
  };

  const subtitle =
    scope === 'cohort'
      ? cohortName
        ? `Team: ${cohortName}`
        : hasCohort
        ? `Team #${cohortId}`
        : undefined
      : [
          selectedLevel ? selectedLevel.charAt(0).toUpperCase() + selectedLevel.slice(1) : 'All Levels',
          selectedTransport ? selectedTransport.charAt(0).toUpperCase() + selectedTransport.slice(1) : 'All Modes',
        ].join(' · ');

  const scopeControls = (
    <div
      className="ems-dispatch-section"
      style={{
        background: '#f6f7f7',
        border: '1px solid #dcdcde',
        borderRadius: '4px',
        padding: '12px',
      }}
    >
      <div className="ems-dispatch-section__title" style={{ marginBottom: '6px' }}>
        Notification Scope
      </div>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '12px', alignItems: 'center' }}>
        <label
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: '6px',
            cursor: hasCohort ? 'pointer' : 'not-allowed',
            opacity: hasCohort ? 1 : 0.6,
          }}
        >
          <input
            type="radio"
            name="ems-notify-scope"
            value="cohort"
            checked={scope === 'cohort'}
            disabled={!hasCohort || dispatching}
            onChange={() => setScope('cohort')}
          />
          <span>
            Single Team{cohortName ? `: ${cohortName}` : hasCohort ? ` (#${cohortId})` : ' (None selected)'}
          </span>
        </label>

        <label style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', cursor: 'pointer' }}>
          <input
            type="radio"
            name="ems-notify-scope"
            value="all"
            checked={scope === 'all'}
            disabled={dispatching}
            onChange={() => setScope('all')}
          />
          <span>Filtered Level / Mode Batch</span>
        </label>
      </div>

      {scope === 'all' && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '10px', marginTop: '10px' }}>
          <div>
            <label htmlFor="ems-notify-level" style={{ fontSize: '12px', display: 'block', marginBottom: '2px' }}>
              DofE Level
            </label>
            <select
              id="ems-notify-level"
              className="ems-select"
              value={selectedLevel}
              disabled={dispatching}
              onChange={(e) => setSelectedLevel(e.target.value)}
            >
              <option value="">All Levels</option>
              <option value="bronze">Bronze</option>
              <option value="silver">Silver</option>
              <option value="gold">Gold</option>
            </select>
          </div>
          <div>
            <label htmlFor="ems-notify-transport" style={{ fontSize: '12px', display: 'block', marginBottom: '2px' }}>
              Transport Mode
            </label>
            <select
              id="ems-notify-transport"
              className="ems-select"
              value={selectedTransport}
              disabled={dispatching}
              onChange={(e) => setSelectedTransport(e.target.value)}
            >
              <option value="">All Modes</option>
              <option value="hillwalking">Hillwalking</option>
              <option value="biking">Biking</option>
              <option value="paddling">Paddling</option>
            </select>
          </div>
        </div>
      )}
    </div>
  );

  return (
    <TeamEmailDispatchModal
      isOpen={isOpen}
      onClose={onClose}
      mode="place_offer"
      title="Manual Place Offer Dispatch"
      subtitle={subtitle}
      ariaLabel="Dispatch Place Offer Notifications"
      scopeControls={scopeControls}
      recipients={preview?.recipients || []}
      warnings={preview?.warnings || []}
      loadingPreview={loadingPreview}
      previewError={previewError}
      sampleEmail={preview?.sample_email || null}
      selectedIds={selectedSignupIds}
      onToggleRecipient={toggleRecipient}
      onToggleSelectAll={toggleSelectAllValid}
      onInspectExplorer={onInspectExplorer}
      showTeamColumn={scope === 'all'}
      dispatching={dispatching}
      dispatchError={dispatchError}
      dispatchResult={dispatchResult}
      onDispatch={handleDispatch}
    />
  );
};

export default ManualNotifyModal;
