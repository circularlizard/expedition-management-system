import React, { useState, useEffect, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faChevronDown,
  faChevronRight,
  faCircleCheck,
  faCircleExclamation,
  faPaperPlane,
  faSpinner,
  faTriangleExclamation,
  faXmark,
} from '@fortawesome/free-solid-svg-icons';

export type DispatchModalMode = 'place_offer' | 'compliance_reminder';

export interface DispatchModalWarning {
  code: string;
  severity?: 'warning' | 'error' | 'info' | string;
  message: string;
  count?: number;
}

export interface DispatchModalRecipient {
  expedition_signup_id: number;
  scout_id?: number;
  explorer_name: string;
  explorer_email: string;
  parent_email?: string;
  has_valid_email: boolean;
  notification_status: string;
  cohort_id?: number | null;
  cohort_name?: string | null;
  team_code?: string | null;
  warnings?: string[];
  gaps?: string[];
}

export interface DispatchModalSampleEmail {
  subject?: string;
  html?: string;
  message?: string;
}

export interface DispatchModalResult {
  dispatched_count?: number;
  sent_count?: number;
  failed_count?: number;
  skipped_count?: number;
  [key: string]: unknown;
}

export interface TeamEmailDispatchModalProps {
  isOpen: boolean;
  onClose: () => void;
  mode: DispatchModalMode;
  title?: string;
  subtitle?: string;
  ariaLabel?: string;
  scopeControls?: React.ReactNode;
  recipients: DispatchModalRecipient[];
  warnings?: DispatchModalWarning[];
  loadingPreview?: boolean;
  previewError?: string | null;
  sampleEmail?: DispatchModalSampleEmail | null;
  selectedIds: number[];
  onToggleRecipient: (signupId: number) => void;
  onToggleSelectAll: () => void;
  onInspectExplorer?: (target: { scoutId?: number; signupId?: number }) => void;
  showTeamColumn?: boolean;
  dispatching?: boolean;
  dispatchError?: string | null;
  dispatchResult?: DispatchModalResult | null;
  onDispatch: () => void;
  emptyMessage?: string;
}

const UK_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function formatDispatchUkDate(value: string | null | undefined): string {
  if (!value) return '';
  const match = value.trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return value;
  const year = match[1];
  const monthIdx = parseInt(match[2], 10) - 1;
  const day = parseInt(match[3], 10);
  if (monthIdx < 0 || monthIdx > 11 || Number.isNaN(day)) return value;
  return `${day} ${UK_MONTHS[monthIdx]} ${year}`;
}

export function formatEventSubtitle(
  eventCode?: string | null,
  eventTitle?: string | null,
  eventDate?: string | null
): string {
  const code = (eventCode || '').trim();
  const title = (eventTitle || '').trim();
  let eventPart = '';
  if (code && title) {
    if (title.toLowerCase() === code.toLowerCase() || title.toLowerCase().startsWith(code.toLowerCase())) {
      eventPart = title;
    } else {
      eventPart = `${code} — ${title}`;
    }
  } else {
    eventPart = code || title;
  }
  const datePart = formatDispatchUkDate(eventDate);
  if (eventPart && datePart) return `${eventPart} · ${datePart}`;
  return eventPart || datePart || '';
}

const WARNING_LABELS: Record<string, { label: string; isError?: boolean }> = {
  unmatched_osm: { label: 'Unmatched OSM' },
  insufficient_first_aid: { label: 'First Aid < 2', isError: true },
  team_size_out_of_range: { label: 'Team Size (4–7)' },
  date_conflict_pending: { label: 'Date Conflict' },
  unlinked_oidc: { label: 'Unlinked OIDC' },
  missing_email: { label: 'Missing Email', isError: true },
};

const OFFER_STATUS_STYLES: Record<string, { label: string; bg: string; color: string }> = {
  draft: { label: 'Draft', bg: '#f3f4f6', color: '#374151' },
  notified: { label: 'Notified', bg: '#dcfce7', color: '#166534' },
  re_notify_needed: { label: 'Re-notify Needed', bg: '#fef3c7', color: '#92400e' },
  accepted: { label: 'Accepted', bg: '#dbeafe', color: '#1e40af' },
  queried: { label: 'Queried', bg: '#fef3c7', color: '#92400e' },
  declined: { label: 'Declined', bg: '#fee2e2', color: '#991b1b' },
};

function getOfferStatusBadge(status: string): { label: string; bg: string; color: string } {
  const key = (status || 'draft').toLowerCase();
  return (
    OFFER_STATUS_STYLES[key] || {
      label: status || 'Draft',
      bg: '#f3f4f6',
      color: '#374151',
    }
  );
}

function getGapBadgeStyle(gap: string): { bg: string; color: string; border: string } {
  const lower = gap.toLowerCase();
  if (lower.includes('first aid') || lower.includes('not started')) {
    return { bg: '#fef2f2', color: '#991b1b', border: '#fecaca' };
  }
  if (lower.includes('unlinked') || lower.includes('offer')) {
    return { bg: '#fffbeb', color: '#92400e', border: '#fde68a' };
  }
  return { bg: '#f1f5f9', color: '#334155', border: '#cbd5e1' };
}

export const TeamEmailDispatchModal: React.FC<TeamEmailDispatchModalProps> = ({
  isOpen,
  onClose,
  mode,
  title,
  subtitle,
  ariaLabel,
  scopeControls,
  recipients,
  warnings = [],
  loadingPreview = false,
  previewError = null,
  sampleEmail = null,
  selectedIds,
  onToggleRecipient,
  onToggleSelectAll,
  onInspectExplorer,
  showTeamColumn = false,
  dispatching = false,
  dispatchError = null,
  dispatchResult = null,
  onDispatch,
  emptyMessage = 'No explorers found for the selected scope.',
}) => {
  const [templateExpanded, setTemplateExpanded] = useState<boolean>(false);
  const [confirmed, setConfirmed] = useState<boolean>(false);

  useEffect(() => {
    if (!isOpen) return;
    setConfirmed(false);
    setTemplateExpanded(false);
  }, [isOpen, mode, recipients.length]);

  const validRecipients = useMemo(
    () => recipients.filter((r) => r.has_valid_email && r.expedition_signup_id > 0),
    [recipients]
  );

  const missingEmailCount = useMemo(
    () => recipients.filter((r) => !r.has_valid_email).length,
    [recipients]
  );

  const allValidSelected =
    validRecipients.length > 0 &&
    validRecipients.every((r) => selectedIds.includes(r.expedition_signup_id));

  const effectiveWarnings = useMemo(() => {
    const list: DispatchModalWarning[] = [...warnings];
    if (missingEmailCount > 0 && !list.some((w) => w.code === 'missing_email')) {
      list.push({
        code: 'missing_email',
        severity: 'error',
        message: `${missingEmailCount} recipient(s) do not have a valid explorer email address and cannot be notified.`,
        count: missingEmailCount,
      });
    }
    return list;
  }, [warnings, missingEmailCount]);

  if (!isOpen) {
    return null;
  }

  const resolvedTitle =
    title || (mode === 'place_offer' ? 'Manual Place Offer Dispatch' : 'Send Team Readiness Reminder');
  const resolvedAriaLabel = ariaLabel || resolvedTitle;
  const sampleHtml = sampleEmail?.html ?? sampleEmail?.message ?? '';
  const sampleSubject = sampleEmail?.subject ?? '';

  return (
    <div
      className="ems-modal-backdrop"
      role="dialog"
      aria-modal="true"
      aria-label={resolvedAriaLabel}
    >
      <div className="ems-modal-card ems-overview-card ems-dispatch-modal">
        {/* Header & Context */}
        <div className="ems-dispatch-modal__header">
          <div>
            <h2 className="ems-dispatch-modal__title">{resolvedTitle}</h2>
            {subtitle && <div className="ems-dispatch-modal__subtitle">{subtitle}</div>}
          </div>
          <button
            type="button"
            className="button button-link ems-dispatch-modal__close"
            onClick={onClose}
            aria-label="Close modal"
            disabled={dispatching}
          >
            <FontAwesomeIcon icon={faXmark} size="lg" />
          </button>
        </div>

        {/* Optional Scope Controls (Place Offer batch/team selector) */}
        {scopeControls}

        {/* Success Banner after Dispatch */}
        {dispatchResult && (
          <div className="ems-dispatch-alert ems-dispatch-alert--ok ems-dispatch-section">
            <FontAwesomeIcon icon={faCircleCheck} />
            <div>
              <strong>
                {mode === 'place_offer'
                  ? 'Place Offer emails dispatched successfully!'
                  : 'Readiness reminder emails dispatched successfully!'}
              </strong>
              <span style={{ marginLeft: '8px' }}>
                Sent: <strong>{dispatchResult.dispatched_count ?? dispatchResult.sent_count ?? 0}</strong>
                {typeof dispatchResult.failed_count === 'number' && dispatchResult.failed_count > 0 && (
                  <> · Failed: <strong>{dispatchResult.failed_count}</strong></>
                )}
                {typeof dispatchResult.skipped_count === 'number' && dispatchResult.skipped_count > 0 && (
                  <> · Skipped: <strong>{dispatchResult.skipped_count}</strong></>
                )}
              </span>
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

        {loadingPreview ? (
          <div style={{ padding: '32px', textAlign: 'center', color: '#64748b' }}>
            <FontAwesomeIcon icon={faSpinner} spin size="lg" />
            <div style={{ marginTop: '8px', fontSize: '13px' }}>Loading recipient preview &amp; readiness checks…</div>
          </div>
        ) : (
          <>
            {/* 1. Pre-Flight Readiness Banner */}
            <div className="ems-dispatch-section">
              <div className="ems-dispatch-section__title" style={{ marginBottom: '6px' }}>
                Pre-Flight Readiness Checks
              </div>

              {effectiveWarnings.length === 0 ? (
                <div className="ems-dispatch-alert ems-dispatch-alert--ok">
                  <FontAwesomeIcon icon={faCircleCheck} />
                  <span>
                    {recipients.length > 0
                      ? 'All pre-flight readiness checks passed (team size 4–7, First Aid Rule of Two, OSM match, emails valid).'
                      : emptyMessage}
                  </span>
                </div>
              ) : (
                <ul className="ems-dispatch-alert-list">
                  {effectiveWarnings.map((w, idx) => {
                    const meta = WARNING_LABELS[w.code] || { label: w.code };
                    const isError = w.severity === 'error' || meta.isError;
                    return (
                      <li
                        key={`${w.code}-${idx}`}
                        className={`ems-dispatch-alert ${
                          isError ? 'ems-dispatch-alert--error' : 'ems-dispatch-alert--warn'
                        }`}
                      >
                        <FontAwesomeIcon icon={isError ? faCircleExclamation : faTriangleExclamation} />
                        <span
                          className="ems-badge"
                          style={{
                            background: isError ? '#fee2e2' : '#fef3c7',
                            color: isError ? '#991b1b' : '#92400e',
                            border: `1px solid ${isError ? '#fca5a5' : '#fcd34d'}`,
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

            {/* 2. Unified Recipient Table */}
            <div className="ems-dispatch-section">
              <div className="ems-dispatch-section__header">
                <div className="ems-dispatch-section__title">
                  Recipients ({selectedIds.length} of {recipients.length} selected)
                </div>
                {validRecipients.length > 0 && (
                  <button
                    type="button"
                    className="button button-small"
                    onClick={onToggleSelectAll}
                    disabled={dispatching}
                  >
                    {allValidSelected ? 'Deselect All' : 'Select All Valid'}
                  </button>
                )}
              </div>

              {recipients.length === 0 ? (
                <div
                  style={{
                    padding: '16px',
                    textAlign: 'center',
                    background: '#f8fafc',
                    border: '1px solid #dcdcde',
                    borderRadius: '4px',
                    color: '#64748b',
                    fontSize: '13px',
                  }}
                >
                  {emptyMessage}
                </div>
              ) : (
                <div className="ems-dispatch-table-wrap">
                  <table className="widefat striped" style={{ border: 0 }}>
                    <thead>
                      <tr>
                        <th style={{ width: '36px' }}>Send</th>
                        <th>Explorer</th>
                        <th>Email &amp; Parent CC</th>
                        {showTeamColumn && <th>Team</th>}
                        <th>{mode === 'place_offer' ? 'Status' : 'Compliance Gaps'}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {recipients.map((rec, idx) => {
                        const signupId = rec.expedition_signup_id || 0;
                        const canSelect = rec.has_valid_email && signupId > 0;
                        const checked = canSelect && selectedIds.includes(signupId);
                        const offerBadge = getOfferStatusBadge(rec.notification_status);
                        const gaps = rec.gaps || [];

                        return (
                          <tr
                            key={`${signupId}-${idx}`}
                            className={!canSelect ? 'ems-dispatch-row--invalid' : undefined}
                          >
                            <td>
                              <input
                                type="checkbox"
                                checked={checked}
                                disabled={!canSelect || dispatching}
                                aria-label={`Select ${rec.explorer_name}`}
                                onChange={() => canSelect && onToggleRecipient(signupId)}
                              />
                            </td>
                            <td>
                              {onInspectExplorer ? (
                                <button
                                  type="button"
                                  className="button-link ems-dispatch-explorer-btn"
                                  onClick={() =>
                                    onInspectExplorer({
                                      scoutId: rec.scout_id && rec.scout_id > 0 ? rec.scout_id : undefined,
                                      signupId: signupId > 0 ? signupId : undefined,
                                    })
                                  }
                                >
                                  {rec.explorer_name || 'Unnamed Explorer'}
                                </button>
                              ) : (
                                <strong>{rec.explorer_name || 'Unnamed Explorer'}</strong>
                              )}
                              {rec.warnings && rec.warnings.length > 0 && (
                                <div className="ems-dispatch-gap-list" style={{ marginTop: '3px' }}>
                                  {rec.warnings.map((wCode) => {
                                    const meta = WARNING_LABELS[wCode] || { label: wCode };
                                    return (
                                      <span
                                        key={wCode}
                                        className="ems-badge"
                                        style={{
                                          fontSize: '10px',
                                          padding: '2px 6px',
                                          background: meta.isError ? '#fee2e2' : '#fef3c7',
                                          color: meta.isError ? '#991b1b' : '#92400e',
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
                                {rec.has_valid_email && rec.explorer_email ? (
                                  rec.explorer_email
                                ) : (
                                  <span style={{ color: '#991b1b', fontWeight: 600 }}>
                                    Missing / invalid email
                                  </span>
                                )}
                              </div>
                              {rec.parent_email ? (
                                <div className="ems-dispatch-cc">Parent CC: {rec.parent_email}</div>
                              ) : (
                                <div className="ems-dispatch-cc">Parent CC: None recorded</div>
                              )}
                            </td>
                            {showTeamColumn && (
                              <td style={{ fontSize: '12px' }}>
                                <div>{rec.team_code || '—'}</div>
                                {rec.cohort_name && (
                                  <div className="ems-dispatch-cc">{rec.cohort_name}</div>
                                )}
                              </td>
                            )}
                            <td>
                              {mode === 'place_offer' ? (
                                <span
                                  className="ems-badge"
                                  style={{ background: offerBadge.bg, color: offerBadge.color }}
                                >
                                  {offerBadge.label}
                                </span>
                              ) : gaps.length === 0 ? (
                                <span style={{ color: '#15803d', fontWeight: 600, fontSize: '12px' }}>
                                  All checks complete
                                </span>
                              ) : (
                                <div className="ems-dispatch-gap-list">
                                  {gaps.map((g, gIdx) => {
                                    const gStyle = getGapBadgeStyle(g);
                                    return (
                                      <span
                                        key={gIdx}
                                        className="ems-badge"
                                        style={{
                                          fontSize: '11px',
                                          background: gStyle.bg,
                                          color: gStyle.color,
                                          border: `1px solid ${gStyle.border}`,
                                        }}
                                      >
                                        {g}
                                      </span>
                                    );
                                  })}
                                </div>
                              )}
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
            {sampleEmail && (
              <div className="ems-dispatch-section">
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
                      <div className="ems-meta-text ems-mb-4" style={{ fontSize: '12.5px' }}>
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

            {/* 4. Confirmation Gate */}
            <div className="ems-dispatch-confirm-box">
              <label className="ems-dispatch-confirm-label">
                <input
                  type="checkbox"
                  checked={confirmed}
                  disabled={dispatching || selectedIds.length === 0}
                  onChange={(e) => setConfirmed(e.target.checked)}
                  style={{ marginTop: '2px' }}
                />
                <span>
                  I confirm I have reviewed the recipients and warnings and want to dispatch these emails.
                </span>
              </label>
            </div>
          </>
        )}

        {/* Footer */}
        <div className="ems-dispatch-modal__footer">
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
            disabled={loadingPreview || dispatching || !confirmed || selectedIds.length === 0}
            onClick={onDispatch}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
          >
            {dispatching ? (
              <>
                <FontAwesomeIcon icon={faSpinner} spin />
                Dispatching…
              </>
            ) : (
              <>
                <FontAwesomeIcon icon={faPaperPlane} />
                Dispatch Emails ({selectedIds.length})
              </>
            )}
          </button>
        </div>
      </div>
    </div>
  );
};

export default TeamEmailDispatchModal;
