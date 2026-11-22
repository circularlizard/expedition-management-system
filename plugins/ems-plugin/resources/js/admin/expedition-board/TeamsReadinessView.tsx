import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faArrowsRotate,
  faBell,
  faCircleCheck,
  faCircleExclamation,
  faDownload,
  faEnvelope,
  faFilter,
  faMagnifyingGlass,
  faPaperPlane,
  faSpinner,
  faTriangleExclamation,
  faXmark,
} from '@fortawesome/free-solid-svg-icons';
import TeamReadinessTable, {
  TeamReadinessMember,
  TeamReadinessRecord,
} from '../../shared/TeamReadinessTable';
import Explorer360Drawer from '../shared/Explorer360Drawer';

export interface TeamsReadinessViewProps {
  rootUrl?: string;
  nonce?: string;
}

interface PreviewRecipient {
  expedition_signup_id: number;
  explorer_name: string;
  explorer_email: string;
  has_valid_email: boolean;
  notification_status: string;
  warnings?: string[];
}

interface PreviewResponse {
  total_count: number;
  recipients: PreviewRecipient[];
  sample_email?: {
    subject?: string;
    html?: string;
    message?: string;
  } | null;
  warnings?: Array<{ code: string; message: string }>;
}

interface DispatchResponse {
  dispatched_count?: number;
  sent_count?: number;
  failed_count?: number;
  skipped_count?: number;
  [key: string]: unknown;
}

function escapeCsvCell(value: unknown): string {
  const str = value === null || value === undefined ? '' : String(value);
  if (/[",\n\r]/.test(str)) {
    return `"${str.replace(/"/g, '""')}"`;
  }
  return str;
}

function buildReadinessCsv(records: TeamReadinessRecord[]): string {
  const headers = [
    'Record Type',
    'Team Code',
    'Team Title',
    'Team Name',
    'DofE Level',
    'Transport Mode',
    'Event Code',
    'Event Title',
    'Stage',
    'Expedition Date',
    'Member Count',
    'Size Valid (4-7)',
    'Offers Draft',
    'Offers Notified',
    'Offers Accepted',
    'Offers Queried',
    'Offers Declined',
    'First Aid Count',
    'First Aid Valid (2+)',
    'Tutor LMS Available',
    'Training Complete',
    'Training In Progress',
    'Training Not Started',
    'Training Unlinked OIDC',
    'Route Plan Status',
    'Route Plan Version',
    'Route Cutoff Date',
    'Days Until Cutoff',
  ];

  const rows = records.map((r) => {
    const offers = r.offer_counts || {
      draft: 0,
      notified: 0,
      accepted: 0,
      queried: 0,
      declined: 0,
    };
    const training = r.training_summary || {
      complete: 0,
      in_progress: 0,
      not_started: 0,
      unlinked_oidc: 0,
    };
    const draftTotal =
      (offers.draft || 0) + (offers.ready_to_notify || 0) + (offers.re_notify_needed || 0);

    return [
      r.record_type,
      r.team_code,
      r.team_title,
      r.cohort_name || '',
      r.dofe_level,
      r.transport_mode,
      r.expedition_code,
      r.expedition_title,
      r.stage,
      r.expedition_date,
      r.member_count,
      r.size_valid ? 'Yes' : 'No',
      draftTotal,
      offers.notified || 0,
      offers.accepted || 0,
      offers.queried || 0,
      offers.declined || 0,
      r.first_aid_count,
      r.first_aid_valid ? 'Yes' : 'No',
      r.training_available ? 'Yes' : 'No',
      training.complete || 0,
      training.in_progress || 0,
      training.not_started || 0,
      training.unlinked_oidc || 0,
      r.route_plan?.status || 'not_started',
      r.route_plan?.version ?? 0,
      r.route_plan?.cutoff_date || '',
      r.route_plan?.days_until_cutoff ?? '',
    ];
  });

  return [headers, ...rows].map((row) => row.map(escapeCsvCell).join(',')).join('\r\n');
}

function getMemberGaps(member: TeamReadinessMember, trainingAvailable: boolean): string[] {
  const gaps: string[] = [];
  const offerStatus = (member.notification_status || 'draft').toLowerCase();
  if (offerStatus !== 'accepted' && offerStatus !== 'declined') {
    gaps.push(`Offer: ${offerStatus.replace(/_/g, ' ')}`);
  }
  if (!member.first_aid_valid) {
    gaps.push('First Aid missing/expired');
  }
  if (trainingAvailable && member.training_status !== 'complete') {
    gaps.push(`Training: ${member.training_status.replace(/_/g, ' ')}`);
  }
  return gaps;
}

export const TeamsReadinessView: React.FC<TeamsReadinessViewProps> = ({
  rootUrl: propRootUrl,
  nonce: propNonce,
}) => {
  const config = window.emsExpeditionBoard || { root_url: '/wp-json/ems/v1', nonce: '' };
  const rootUrl = (propRootUrl || config.root_url || '/wp-json/ems/v1').replace(/\/$/, '');
  const nonce = propNonce ?? config.nonce ?? '';

  const [dofeLevel, setDofeLevel] = useState<string>('');
  const [transportMode, setTransportMode] = useState<string>('');
  const [stage, setStage] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState<string>('');

  const [records, setRecords] = useState<TeamReadinessRecord[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  // Send Reminder modal state
  const [reminderRecord, setReminderRecord] = useState<TeamReadinessRecord | null>(null);
  const [drawerTarget, setDrawerTarget] = useState<{ scoutId?: number; signupId?: number } | null>(null);
  const [selectedSignupIds, setSelectedSignupIds] = useState<number[]>([]);
  const [previewLoading, setPreviewLoading] = useState<boolean>(false);
  const [previewData, setPreviewData] = useState<PreviewResponse | null>(null);
  const [dispatching, setDispatching] = useState<boolean>(false);
  const [modalError, setModalError] = useState<string | null>(null);
  const [modalSuccess, setModalSuccess] = useState<string | null>(null);

  const fetchReadiness = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (dofeLevel) params.set('dofe_level', dofeLevel);
      if (transportMode) params.set('transport_mode', transportMode);
      if (stage) params.set('stage', stage);

      const qs = params.toString();
      const url = `${rootUrl}/expeditions/teams-readiness${qs ? `?${qs}` : ''}`;
      const res = await fetch(url, {
        headers: {
          'X-WP-Nonce': nonce,
        },
      });
      if (!res.ok) {
        let msg = `Failed to load team readiness (HTTP ${res.status})`;
        try {
          const errBody = await res.json();
          if (errBody?.message) msg = errBody.message;
        } catch {
          // ignore json parse failure
        }
        throw new Error(msg);
      }
      const data = await res.json();
      setRecords(Array.isArray(data) ? data : []);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load team readiness.');
    } finally {
      setLoading(false);
    }
  }, [rootUrl, nonce, dofeLevel, transportMode, stage]);

  useEffect(() => {
    fetchReadiness();
  }, [fetchReadiness]);

  const filteredRecords = useMemo(() => {
    const q = searchQuery.trim().toLowerCase();
    if (!q) return records;
    return records.filter((r) => {
      if ((r.team_code || '').toLowerCase().includes(q)) return true;
      if ((r.team_title || '').toLowerCase().includes(q)) return true;
      if ((r.cohort_name || '').toLowerCase().includes(q)) return true;
      if ((r.expedition_code || '').toLowerCase().includes(q)) return true;
      if ((r.expedition_title || '').toLowerCase().includes(q)) return true;
      return (r.members || []).some((m) => (m.name || '').toLowerCase().includes(q));
    });
  }, [records, searchQuery]);

  const stats = useMemo(() => {
    const total = filteredRecords.length;
    const sizeValid = filteredRecords.filter((r) => r.size_valid).length;
    const firstAidValid = filteredRecords.filter((r) => r.first_aid_valid).length;
    const routeApproved = filteredRecords.filter((r) => r.route_plan?.status === 'approved').length;
    return { total, sizeValid, firstAidValid, routeApproved };
  }, [filteredRecords]);

  const handleExportCsv = () => {
    const csv = buildReadinessCsv(filteredRecords);
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const dateSlug = new Date().toISOString().slice(0, 10);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `ems-teams-readiness-${dateSlug}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  const handleOpenReminderModal = (record: TeamReadinessRecord) => {
    setReminderRecord(record);
    setPreviewData(null);
    setModalError(null);
    setModalSuccess(null);

    // Pre-select members who have pending/incomplete compliance and a valid expedition_signup_id
    const preselected = (record.members || [])
      .filter((m) => {
        if (!m.expedition_signup_id || m.expedition_signup_id <= 0) return false;
        return getMemberGaps(m, record.training_available).length > 0;
      })
      .map((m) => m.expedition_signup_id);

    setSelectedSignupIds(preselected);
  };

  const handleCloseReminderModal = () => {
    if (dispatching) return;
    setReminderRecord(null);
    setPreviewData(null);
    setModalError(null);
    setModalSuccess(null);
  };

  const toggleMemberSelection = (signupId: number) => {
    setSelectedSignupIds((prev) =>
      prev.includes(signupId) ? prev.filter((id) => id !== signupId) : [...prev, signupId]
    );
  };

  const handlePreviewReminder = async () => {
    if (!reminderRecord || selectedSignupIds.length === 0) return;
    setPreviewLoading(true);
    setModalError(null);
    setModalSuccess(null);
    try {
      const res = await fetch(`${rootUrl}/expeditions/notifications/preview`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({
          scope: 'cohort',
          cohort_id: reminderRecord.cohort_id ?? null,
          expedition_signup_ids: selectedSignupIds,
          dofe_level: reminderRecord.dofe_level || undefined,
          transport_mode: reminderRecord.transport_mode || undefined,
        }),
      });
      const body = await res.json();
      if (!res.ok) {
        throw new Error(body?.message || `Preview failed (HTTP ${res.status})`);
      }
      setPreviewData(body as PreviewResponse);
    } catch (err) {
      setModalError(err instanceof Error ? err.message : 'Failed to preview reminder.');
    } finally {
      setPreviewLoading(false);
    }
  };

  const handleDispatchReminder = async () => {
    if (!reminderRecord || selectedSignupIds.length === 0) return;
    setDispatching(true);
    setModalError(null);
    setModalSuccess(null);
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
          cohort_id: reminderRecord.cohort_id ?? null,
        }),
      });
      const body: DispatchResponse & { message?: string } = await res.json();
      if (!res.ok) {
        throw new Error(body?.message || `Send reminder failed (HTTP ${res.status})`);
      }
      const sent = body.dispatched_count ?? body.sent_count ?? selectedSignupIds.length;
      setModalSuccess(`Reminder dispatched to ${sent} member${sent === 1 ? '' : 's'}.`);
      fetchReadiness();
    } catch (err) {
      setModalError(err instanceof Error ? err.message : 'Failed to send reminder.');
    } finally {
      setDispatching(false);
    }
  };

  return (
    <div className="ems-teams-readiness-view">
      {/* Toolbar & Filters */}
      <div
        className="ems-panel"
        style={{
          padding: '14px 16px',
          marginBottom: '16px',
          display: 'flex',
          flexWrap: 'wrap',
          justifyContent: 'space-between',
          alignItems: 'center',
          gap: '12px',
        }}
      >
        <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '10px' }}>
          <span style={{ fontWeight: 600, fontSize: '13px', color: '#1d2327', display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
            <FontAwesomeIcon icon={faFilter} style={{ color: '#646970' }} />
            Filters:
          </span>

          <select
            aria-label="Filter by DofE Level"
            className="ems-select"
            value={dofeLevel}
            onChange={(e) => setDofeLevel(e.target.value)}
          >
            <option value="">All Levels</option>
            <option value="bronze">Bronze</option>
            <option value="silver">Silver</option>
            <option value="gold">Gold</option>
          </select>

          <select
            aria-label="Filter by Transport Mode"
            className="ems-select"
            value={transportMode}
            onChange={(e) => setTransportMode(e.target.value)}
          >
            <option value="">All Modes</option>
            <option value="foot">Foot (Hillwalking)</option>
            <option value="biking">Biking</option>
            <option value="paddling">Paddling</option>
          </select>

          <select
            aria-label="Filter by Stage"
            className="ems-select"
            value={stage}
            onChange={(e) => setStage(e.target.value)}
          >
            <option value="">All Stages</option>
            <option value="practice">Practice</option>
            <option value="qualifier">Qualifier</option>
          </select>

          <div style={{ position: 'relative', display: 'inline-flex', alignItems: 'center' }}>
            <FontAwesomeIcon
              icon={faMagnifyingGlass}
              style={{ position: 'absolute', left: '9px', color: '#646970', fontSize: '12px', pointerEvents: 'none' }}
            />
            <input
              type="search"
              aria-label="Search teams, cohorts, events, or members"
              placeholder="Search team, event, member…"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              style={{ paddingLeft: '28px', minWidth: '210px', height: '30px' }}
            />
          </div>

          {(dofeLevel || transportMode || stage || searchQuery) && (
            <button
              type="button"
              className="button button-link"
              onClick={() => {
                setDofeLevel('');
                setTransportMode('');
                setStage('');
                setSearchQuery('');
              }}
            >
              Reset
            </button>
          )}
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <button
            type="button"
            className="button"
            onClick={fetchReadiness}
            disabled={loading}
          >
            <FontAwesomeIcon icon={faArrowsRotate} spin={loading} style={{ marginRight: '6px' }} />
            Refresh
          </button>
          <button
            type="button"
            className="button button-primary"
            onClick={handleExportCsv}
            disabled={loading || filteredRecords.length === 0}
          >
            <FontAwesomeIcon icon={faDownload} style={{ marginRight: '6px' }} />
            Export Event Readiness CSV
          </button>
        </div>
      </div>

      {/* Summary Strip */}
      <div
        style={{
          display: 'flex',
          flexWrap: 'wrap',
          gap: '12px',
          marginBottom: '14px',
          fontSize: '12px',
          color: '#50575e',
        }}
      >
        <span className="ems-pill">
          Teams / Cohorts: <strong>&nbsp;{stats.total}</strong>
        </span>
        <span className="ems-pill ems-pill--asn-validated">
          Size Valid (4–7): <strong>&nbsp;{stats.sizeValid} / {stats.total}</strong>
        </span>
        <span className="ems-pill ems-pill--asn-validated">
          First Aid Rule of Two Met: <strong>&nbsp;{stats.firstAidValid} / {stats.total}</strong>
        </span>
        <span className="ems-pill">
          Route Plans Approved: <strong>&nbsp;{stats.routeApproved} / {stats.total}</strong>
        </span>
      </div>

      {error && (
        <div className="ems-error-notice">
          <FontAwesomeIcon icon={faCircleExclamation} style={{ marginRight: '6px' }} />
          {error}
        </div>
      )}

      <TeamReadinessTable
        records={filteredRecords}
        loading={loading}
        onSendReminder={handleOpenReminderModal}
      />

      {/* Manual Send Reminder Modal */}
      {reminderRecord && (
        <div
          className="ems-modal-backdrop"
          role="dialog"
          aria-modal="true"
          aria-label="Send Team Readiness Reminder"
        >
          <div className="ems-modal-card ems-overview-card" style={{ maxWidth: '760px', padding: '20px 24px' }}>
            <div
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                borderBottom: '1px solid #dcdcde',
                paddingBottom: '12px',
                marginBottom: '14px',
              }}
            >
              <h2 className="ems-m-0" style={{ fontSize: '17px', display: 'flex', alignItems: 'center', gap: '8px' }}>
                <FontAwesomeIcon icon={faBell} style={{ color: '#2271b1' }} />
                Send Reminder — {reminderRecord.team_code || reminderRecord.team_title}
              </h2>
              <button
                type="button"
                className="button button-link"
                onClick={handleCloseReminderModal}
                disabled={dispatching}
                aria-label="Close reminder modal"
              >
                <FontAwesomeIcon icon={faXmark} size="lg" />
              </button>
            </div>

            <div style={{ fontSize: '13px', color: '#50575e', marginBottom: '12px' }}>
              {reminderRecord.expedition_code || reminderRecord.expedition_title ? (
                <>
                  Event: <strong>{reminderRecord.expedition_code}</strong>{' '}
                  {reminderRecord.expedition_title}
                  {reminderRecord.expedition_date ? ` (${reminderRecord.expedition_date})` : ''}
                </>
              ) : (
                'Unscheduled Forming Cohort'
              )}
            </div>

            {modalError && (
              <div className="ems-error-notice">
                <FontAwesomeIcon icon={faTriangleExclamation} style={{ marginRight: '6px' }} />
                {modalError}
              </div>
            )}

            {modalSuccess && (
              <div
                style={{
                  padding: '10px 14px',
                  background: '#dcfce7',
                  borderLeft: '4px solid #16a34a',
                  color: '#166534',
                  marginBottom: '14px',
                  fontSize: '13px',
                  borderRadius: '2px',
                }}
              >
                <FontAwesomeIcon icon={faCircleCheck} style={{ marginRight: '6px' }} />
                {modalSuccess}
              </div>
            )}

            <div style={{ marginBottom: '14px' }}>
              <div style={{ fontWeight: 600, fontSize: '13px', marginBottom: '8px' }}>
                Select Members to Remind ({selectedSignupIds.length} selected)
              </div>
              <div style={{ maxHeight: '260px', overflowY: 'auto', border: '1px solid #dcdcde', borderRadius: '4px' }}>
                <table className="ems-table" style={{ fontSize: '12px' }}>
                  <thead>
                    <tr>
                      <th style={{ width: '32px' }} />
                      <th>Explorer</th>
                      <th>Compliance Gaps</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(reminderRecord.members || []).map((member, idx) => {
                      const signupId = member.expedition_signup_id || 0;
                      const canSelect = signupId > 0;
                      const isChecked = canSelect && selectedSignupIds.includes(signupId);
                      const gaps = getMemberGaps(member, reminderRecord.training_available);

                      return (
                        <tr key={`${signupId}-${idx}`}>
                          <td>
                            <input
                              type="checkbox"
                              checked={isChecked}
                              disabled={!canSelect || dispatching}
                              onChange={() => canSelect && toggleMemberSelection(signupId)}
                            />
                          </td>
                          <td>
                            <button
                              type="button"
                              className="button-link"
                              onClick={() =>
                                setDrawerTarget({
                                  scoutId: member.scout_id,
                                  signupId: member.expedition_signup_id,
                                })
                              }
                            >
                              <strong>{member.name || 'Unnamed Explorer'}</strong>
                            </button>
                            {!canSelect && (
                              <div className="ems-table__meta">No linked expedition signup</div>
                            )}
                          </td>
                          <td>
                            {gaps.length === 0 ? (
                              <span style={{ color: '#166534', fontWeight: 600 }}>
                                <FontAwesomeIcon icon={faCircleCheck} style={{ marginRight: '4px' }} />
                                All checks complete
                              </span>
                            ) : (
                              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                                {gaps.map((g, gIdx) => (
                                  <span
                                    key={gIdx}
                                    className="ems-badge ems-badge--allocated"
                                    style={{ fontSize: '11px' }}
                                  >
                                    {g}
                                  </span>
                                ))}
                              </div>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Preview Box */}
            {previewData && (
              <div
                style={{
                  background: '#f6f7f7',
                  border: '1px solid #dcdcde',
                  borderRadius: '4px',
                  padding: '12px',
                  marginBottom: '14px',
                  fontSize: '12px',
                }}
              >
                <div style={{ fontWeight: 600, marginBottom: '6px', display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <FontAwesomeIcon icon={faEnvelope} style={{ color: '#2271b1' }} />
                  Reminder Preview ({previewData.total_count ?? previewData.recipients?.length ?? 0} recipient
                  {(previewData.total_count ?? previewData.recipients?.length ?? 0) === 1 ? '' : 's'})
                </div>
                {previewData.sample_email?.subject && (
                  <div style={{ marginBottom: '6px' }}>
                    <strong>Subject:</strong> {previewData.sample_email.subject}
                  </div>
                )}
                {previewData.sample_email?.message && (
                  <div className="ems-email-preview-box">{previewData.sample_email.message}</div>
                )}
                {previewData.sample_email?.html && !previewData.sample_email?.message && (
                  <div
                    className="ems-email-preview-box"
                    dangerouslySetInnerHTML={{ __html: previewData.sample_email.html }}
                  />
                )}
              </div>
            )}

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
              <button
                type="button"
                className="button"
                onClick={handleCloseReminderModal}
                disabled={dispatching}
              >
                Close
              </button>
              <button
                type="button"
                className="button"
                onClick={handlePreviewReminder}
                disabled={selectedSignupIds.length === 0 || previewLoading || dispatching}
              >
                <FontAwesomeIcon icon={previewLoading ? faSpinner : faEnvelope} spin={previewLoading} style={{ marginRight: '6px' }} />
                Preview
              </button>
              <button
                type="button"
                className="button button-primary"
                onClick={handleDispatchReminder}
                disabled={selectedSignupIds.length === 0 || dispatching}
              >
                <FontAwesomeIcon icon={dispatching ? faSpinner : faPaperPlane} spin={dispatching} style={{ marginRight: '6px' }} />
                {dispatching ? 'Sending…' : `Send Reminder (${selectedSignupIds.length})`}
              </button>
            </div>
          </div>
        </div>
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
        onUpdated={fetchReadiness}
      />
    </div>
  );
};

export default TeamsReadinessView;
