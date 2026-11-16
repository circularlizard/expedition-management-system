import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faXmark,
  faEnvelope,
  faTriangleExclamation,
  faCircleExclamation,
  faCircleCheck,
  faChevronDown,
  faChevronRight,
  faSpinner,
  faPaperPlane,
  faUsers,
  faShieldHalved,
} from '@fortawesome/free-solid-svg-icons';

export interface ManualNotifyModalProps {
  isOpen: boolean;
  onClose: () => void;
  initialScope?: 'cohort' | 'all';
  cohortId?: number | null;
  cohortName?: string | null;
  dofeLevel?: string;
  transportMode?: string;
  onDispatched?: (result: { dispatched_count?: number; [key: string]: unknown }) => void;
}

export interface PreviewWarning {
  code: string;
  severity?: 'warning' | 'error' | 'info' | string;
  message: string;
  count?: number;
  cohort_id?: number;
  expedition_signup_ids?: number[];
}

export interface PreviewRecipient {
  expedition_signup_id: number;
  scout_id?: number;
  explorer_name: string;
  explorer_email: string;
  parent_email?: string;
  has_valid_email: boolean;
  notification_status: string;
  availability_override?: string;
  cohort_id?: number | null;
  cohort_name?: string | null;
  team_code?: string | null;
  dofe_level?: string;
  transport_mode?: string;
  warnings?: string[];
}

export interface SampleEmail {
  subject?: string;
  html?: string;
  message?: string;
}

export interface PreviewResponse {
  scope: string;
  recipients: PreviewRecipient[];
  total_count: number;
  sample_email: SampleEmail | null;
  warnings: PreviewWarning[];
}

export interface DispatchResponse {
  dispatched_count?: number;
  sent_count?: number;
  failed_count?: number;
  skipped_count?: number;
  sent_ids?: number[];
  failed_ids?: number[];
  skipped_ids?: number[];
  errors?: Array<{ expedition_signup_id?: number; reason?: string }>;
  [key: string]: unknown;
}

const WARNING_LABELS: Record<string, { label: string; isError?: boolean }> = {
  unmatched_osm: { label: 'Unmatched OSM' },
  insufficient_first_aid: { label: 'First Aid < 2', isError: true },
  team_size_out_of_range: { label: 'Team Size (4–7)' },
  date_conflict_pending: { label: 'Date Conflict' },
  missing_email: { label: 'Missing Email', isError: true },
};

const STATUS_BADGE_STYLES: Record<string, { label: string; bg: string; color: string }> = {
  draft: { label: 'Draft', bg: '#F3F4F6', color: '#374151' },
  notified: { label: 'Notified', bg: '#DCFCE7', color: '#166534' },
  re_notify_needed: { label: 'Re-notify Needed', bg: '#FEF3C7', color: '#92400E' },
  accepted: { label: 'Accepted', bg: '#DBEAFE', color: '#1E40AF' },
  declined: { label: 'Declined', bg: '#FEE2E2', color: '#991B1B' },
};

function getStatusBadge(status: string): { label: string; bg: string; color: string } {
  const key = (status || 'draft').toLowerCase();
  return (
    STATUS_BADGE_STYLES[key] || {
      label: status || 'Draft',
      bg: '#F3F4F6',
      color: '#374151',
    }
  );
}

export const ManualNotifyModal: React.FC<ManualNotifyModalProps> = ({
  isOpen,
  onClose,
  initialScope,
  cohortId = null,
  cohortName = null,
  dofeLevel = '',
  transportMode = '',
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
  const [selectedTransport, setSelectedTransport] = useState<string>(transportMode || '');

  const [preview, setPreview] = useState<PreviewResponse | null>(null);
  const [loadingPreview, setLoadingPreview] = useState<boolean>(false);
  const [previewError, setPreviewError] = useState<string | null>(null);

  const [selectedSignupIds, setSelectedSignupIds] = useState<number[]>([]);
  const [templateExpanded, setTemplateExpanded] = useState<boolean>(false);
  const [confirmed, setConfirmed] = useState<boolean>(false);

  const [dispatching, setDispatching] = useState<boolean>(false);
  const [dispatchError, setDispatchError] = useState<string | null>(null);
  const [dispatchResult, setDispatchResult] = useState<DispatchResponse | null>(null);

  // Reset modal state whenever it opens or target props change
  useEffect(() => {
    if (!isOpen) return;
    setScope(resolvedInitialScope);
    setSelectedLevel(dofeLevel || '');
    setSelectedTransport(transportMode || '');
    setConfirmed(false);
    setTemplateExpanded(false);
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
    setConfirmed(false);

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

  const missingEmailCount = useMemo(
    () => (preview?.recipients || []).filter((r) => !r.has_valid_email).length,
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

  const toggleRecipient = (signupId: number, hasValidEmail: boolean) => {
    if (!hasValidEmail) return;
    setSelectedSignupIds((prev) =>
      prev.includes(signupId) ? prev.filter((id) => id !== signupId) : [...prev, signupId]
    );
  };

  const handleDispatch = async () => {
    if (!confirmed || selectedSignupIds.length === 0 || dispatching) return;

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

  if (!isOpen) {
    return null;
  }

  const sampleHtml = preview?.sample_email?.html ?? preview?.sample_email?.message ?? '';
  const sampleSubject = preview?.sample_email?.subject ?? '';

  const effectiveWarnings: PreviewWarning[] = [...(preview?.warnings || [])];
  if (
    missingEmailCount > 0 &&
    !effectiveWarnings.some((w) => w.code === 'missing_email')
  ) {
    effectiveWarnings.push({
      code: 'missing_email',
      severity: 'error',
      message: `${missingEmailCount} recipient(s) do not have a valid explorer email address and cannot be notified.`,
      count: missingEmailCount,
    });
  }

  return (
    <div
      className="ems-modal-backdrop"
      role="dialog"
      aria-modal="true"
      aria-label="Dispatch Place Offer Notifications"
    >
      <div
        className="ems-modal-card ems-overview-card"
        style={{ maxWidth: '860px', padding: '20px 24px' }}
      >
        {/* Header */}
        <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            borderBottom: '1px solid #dcdcde',
            paddingBottom: '12px',
            marginBottom: '16px',
          }}
        >
          <h2 className="ems-m-0" style={{ fontSize: '18px', display: 'flex', alignItems: 'center', gap: '8px' }}>
            <FontAwesomeIcon icon={faEnvelope} style={{ color: '#2271b1' }} />
            Manual Place Offer Dispatch
          </h2>
          <button
            type="button"
            className="button button-link"
            onClick={onClose}
            aria-label="Close modal"
            disabled={dispatching}
            style={{ color: '#646970', textDecoration: 'none', padding: '4px 8px' }}
          >
            <FontAwesomeIcon icon={faXmark} size="lg" />
          </button>
        </div>

        {/* Scope Selector */}
        <div
          className="ems-mb-12"
          style={{
            background: '#f6f7f7',
            border: '1px solid #dcdcde',
            borderRadius: '4px',
            padding: '12px',
          }}
        >
          <div className="ems-font-semibold ems-mb-4" style={{ fontSize: '13px' }}>
            Notification Scope
          </div>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '12px', alignItems: 'center' }}>
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', cursor: hasCohort ? 'pointer' : 'not-allowed', opacity: hasCohort ? 1 : 0.6 }}>
              <input
                type="radio"
                name="ems-notify-scope"
                value="cohort"
                checked={scope === 'cohort'}
                disabled={!hasCohort || dispatching}
                onChange={() => setScope('cohort')}
              />
              <span>
                Single Cohort{cohortName ? `: ${cohortName}` : hasCohort ? ` (#${cohortId})` : ' (None selected)'}
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

        {/* Success Banner after Dispatch */}
        {dispatchResult && (
          <div
            style={{
              background: '#edfaef',
              border: '1px solid #00a32a',
              borderLeftWidth: '4px',
              borderRadius: '4px',
              padding: '12px 16px',
              marginBottom: '16px',
              color: '#1d2327',
            }}
          >
            <div style={{ fontWeight: 600, display: 'flex', alignItems: 'center', gap: '8px', color: '#007017' }}>
              <FontAwesomeIcon icon={faCircleCheck} />
              Place Offer emails dispatched successfully!
            </div>
            <div style={{ fontSize: '13px', marginTop: '4px' }}>
              Sent: <strong>{dispatchResult.dispatched_count ?? dispatchResult.sent_count ?? 0}</strong>
              {typeof dispatchResult.failed_count === 'number' && dispatchResult.failed_count > 0 && (
                <> &middot; Failed: <strong>{dispatchResult.failed_count}</strong></>
              )}
              {typeof dispatchResult.skipped_count === 'number' && dispatchResult.skipped_count > 0 && (
                <> &middot; Skipped: <strong>{dispatchResult.skipped_count}</strong></>
              )}
            </div>
          </div>
        )}

        {/* Error Notices */}
        {previewError && (
          <div className="ems-error-notice" role="alert">
            <FontAwesomeIcon icon={faCircleExclamation} /> {previewError}
          </div>
        )}
        {dispatchError && (
          <div className="ems-error-notice" role="alert">
            <FontAwesomeIcon icon={faCircleExclamation} /> {dispatchError}
          </div>
        )}

        {/* Loading State */}
        {loadingPreview ? (
          <div style={{ padding: '32px', textAlign: 'center', color: '#646970' }}>
            <FontAwesomeIcon icon={faSpinner} spin size="lg" />
            <div style={{ marginTop: '8px', fontSize: '13px' }}>Loading recipient preview &amp; safety checks...</div>
          </div>
        ) : (
          <>
            {/* 1. Pre-Flight Safety & Readiness Checklist */}
            <div className="ems-mb-12">
              <div
                className="ems-font-semibold ems-mb-4"
                style={{ fontSize: '13px', display: 'flex', alignItems: 'center', gap: '6px' }}
              >
                <FontAwesomeIcon icon={faShieldHalved} style={{ color: '#2271b1' }} />
                Pre-Flight Safety &amp; Readiness Checklist
              </div>

              {effectiveWarnings.length === 0 ? (
                <div
                  style={{
                    padding: '8px 12px',
                    background: '#edfaef',
                    border: '1px solid #b8e6bf',
                    borderRadius: '4px',
                    fontSize: '12px',
                    color: '#166534',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '8px',
                  }}
                >
                  <FontAwesomeIcon icon={faCircleCheck} />
                  <span>
                    {preview && preview.recipients.length > 0
                      ? 'All pre-flight safety checks passed (team size 4–7, First Aid Rule of Two, OSM match, emails valid).'
                      : 'No recipients matched the selected scope.'}
                  </span>
                </div>
              ) : (
                <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: '6px' }}>
                  {effectiveWarnings.map((w, idx) => {
                    const meta = WARNING_LABELS[w.code] || { label: w.code };
                    const isError = w.severity === 'error' || meta.isError;
                    return (
                      <li
                        key={`${w.code}-${idx}`}
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          gap: '8px',
                          padding: '8px 12px',
                          borderRadius: '4px',
                          fontSize: '12px',
                          background: isError ? '#FEF2F2' : '#FFFBEB',
                          border: `1px solid ${isError ? '#FECACA' : '#FDE68A'}`,
                          color: isError ? '#991B1B' : '#92400E',
                        }}
                      >
                        <FontAwesomeIcon icon={isError ? faCircleExclamation : faTriangleExclamation} />
                        <span
                          className="ems-badge"
                          style={{
                            background: isError ? '#FEE2E2' : '#FEF3C7',
                            color: isError ? '#991B1B' : '#92400E',
                            border: `1px solid ${isError ? '#FCA5A5' : '#FCD34D'}`,
                          }}
                        >
                          {meta.label}
                        </span>
                        <span>{w.message}</span>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>

            {/* 2. Recipient Checklist */}
            <div className="ems-mb-12">
              <div
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  marginBottom: '6px',
                }}
              >
                <div className="ems-font-semibold" style={{ fontSize: '13px', display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <FontAwesomeIcon icon={faUsers} style={{ color: '#2271b1' }} />
                  Recipients ({selectedSignupIds.length} of {preview?.recipients.length || 0} selected)
                </div>
                {validRecipients.length > 0 && (
                  <button
                    type="button"
                    className="button button-small"
                    onClick={toggleSelectAllValid}
                    disabled={dispatching}
                  >
                    {allValidSelected ? 'Deselect All' : 'Select All Valid'}
                  </button>
                )}
              </div>

              {!preview || preview.recipients.length === 0 ? (
                <div
                  style={{
                    padding: '16px',
                    textAlign: 'center',
                    background: '#f9fafb',
                    border: '1px solid #dcdcde',
                    borderRadius: '4px',
                    color: '#646970',
                    fontSize: '13px',
                  }}
                >
                  No explorers found for the selected scope.
                </div>
              ) : (
                <div
                  style={{
                    maxHeight: '240px',
                    overflowY: 'auto',
                    border: '1px solid #dcdcde',
                    borderRadius: '4px',
                  }}
                >
                  <table className="widefat striped" style={{ border: 0 }}>
                    <thead>
                      <tr>
                        <th style={{ width: '32px' }}>Send</th>
                        <th>Explorer</th>
                        <th>Explorer Email &amp; Parent CC</th>
                        <th>Team / Cohort</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {preview.recipients.map((rec) => {
                        const checked = selectedSignupIds.includes(rec.expedition_signup_id);
                        const statusStyle = getStatusBadge(rec.notification_status);
                        const hasEmail = rec.has_valid_email;

                        return (
                          <tr
                            key={rec.expedition_signup_id}
                            style={!hasEmail ? { opacity: 0.7, background: '#FEF2F2' } : undefined}
                          >
                            <td>
                              <input
                                type="checkbox"
                                checked={checked}
                                disabled={!hasEmail || dispatching}
                                aria-label={`Select ${rec.explorer_name}`}
                                onChange={() => toggleRecipient(rec.expedition_signup_id, hasEmail)}
                              />
                            </td>
                            <td>
                              <strong>{rec.explorer_name}</strong>
                              {rec.warnings && rec.warnings.length > 0 && (
                                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px', marginTop: '3px' }}>
                                  {rec.warnings.map((wCode) => {
                                    const meta = WARNING_LABELS[wCode] || { label: wCode };
                                    return (
                                      <span
                                        key={wCode}
                                        className="ems-badge"
                                        style={{
                                          fontSize: '10px',
                                          padding: '2px 6px',
                                          background: meta.isError ? '#FEE2E2' : '#FEF3C7',
                                          color: meta.isError ? '#991B1B' : '#92400E',
                                        }}
                                      >
                                        {meta.label}
                                      </span>
                                    );
                                  })}
                                </div>
                              )}
                            </td>
                            <td style={{ fontSize: '12px' }}>
                              <div>
                                {hasEmail ? (
                                  rec.explorer_email
                                ) : (
                                  <span style={{ color: '#991B1B', fontWeight: 600 }}>
                                    Missing / invalid email
                                  </span>
                                )}
                              </div>
                              {rec.parent_email && (
                                <div style={{ color: '#646970' }}>
                                  Parent CC: {rec.parent_email}
                                </div>
                              )}
                            </td>
                            <td style={{ fontSize: '12px' }}>
                              <div>{rec.team_code || '—'}</div>
                              {rec.cohort_name && (
                                <div style={{ color: '#646970' }}>{rec.cohort_name}</div>
                              )}
                            </td>
                            <td>
                              <span
                                className="ems-badge"
                                style={{
                                  background: statusStyle.bg,
                                  color: statusStyle.color,
                                }}
                              >
                                {statusStyle.label}
                              </span>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            {/* 3. Expandable Preview Email Template */}
            {preview?.sample_email && (
              <div className="ems-mb-12">
                <button
                  type="button"
                  className="button button-secondary"
                  onClick={() => setTemplateExpanded((prev) => !prev)}
                  aria-expanded={templateExpanded}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
                >
                  <FontAwesomeIcon icon={templateExpanded ? faChevronDown : faChevronRight} />
                  Preview Email Template
                </button>

                {templateExpanded && (
                  <div style={{ marginTop: '8px' }}>
                    {sampleSubject && (
                      <div className="ems-meta-text ems-mb-4" style={{ fontSize: '13px' }}>
                        <strong>Subject:</strong> {sampleSubject}
                      </div>
                    )}
                    <div
                      className="ems-email-preview-box"
                      dangerouslySetInnerHTML={{ __html: sampleHtml }}
                    />
                  </div>
                )}
              </div>
            )}

            {/* 4. Two-Step Confirmation Gate */}
            <div
              className="ems-mb-12"
              style={{
                padding: '10px 12px',
                background: '#f0f6fc',
                border: '1px solid #c3d9ed',
                borderRadius: '4px',
              }}
            >
              <label style={{ display: 'flex', alignItems: 'flex-start', gap: '8px', cursor: 'pointer', fontSize: '13px' }}>
                <input
                  type="checkbox"
                  checked={confirmed}
                  disabled={dispatching || selectedSignupIds.length === 0}
                  onChange={(e) => setConfirmed(e.target.checked)}
                  style={{ marginTop: '2px' }}
                />
                <span>
                  I confirm I have reviewed the recipients and safety warnings and want to dispatch these Place Offer emails.
                </span>
              </label>
            </div>
          </>
        )}

        {/* Footer */}
        <div
          className="ems-flex-end ems-gap-8"
          style={{ borderTop: '1px solid #dcdcde', paddingTop: '12px' }}
        >
          <button
            type="button"
            className="button button-secondary"
            onClick={onClose}
            disabled={dispatching}
          >
            {dispatchResult ? 'Close' : 'Cancel'}
          </button>
          <button
            type="button"
            className="button button-primary"
            disabled={
              loadingPreview ||
              dispatching ||
              !confirmed ||
              selectedSignupIds.length === 0
            }
            onClick={handleDispatch}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
          >
            {dispatching ? (
              <>
                <FontAwesomeIcon icon={faSpinner} spin />
                Dispatching...
              </>
            ) : (
              <>
                <FontAwesomeIcon icon={faPaperPlane} />
                Dispatch Place Offers
              </>
            )}
          </button>
        </div>
      </div>
    </div>
  );
};

export default ManualNotifyModal;
