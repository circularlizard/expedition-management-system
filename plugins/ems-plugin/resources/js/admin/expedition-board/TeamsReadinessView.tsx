import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faArrowsRotate,
  faCircleExclamation,
  faDownload,
  faMagnifyingGlass,
} from '@fortawesome/free-solid-svg-icons';
import TeamReadinessTable, {
  TeamReadinessMember,
  TeamReadinessRecord,
} from '../../shared/TeamReadinessTable';
import TeamEmailDispatchModal, {
  DispatchModalRecipient,
  DispatchModalResult,
  DispatchModalSampleEmail,
  DispatchModalWarning,
  formatEventSubtitle,
} from '../../shared/TeamEmailDispatchModal';
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
  const rawOffer = (member.offer_status || '').toLowerCase();
  const offerStatus = ['accepted', 'queried', 'declined'].includes(rawOffer)
    ? rawOffer
    : (member.notification_status || 'draft').toLowerCase();
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
  const [kpiFilter, setKpiFilter] = useState<'all' | 'size_valid' | 'first_aid_valid' | 'route_approved'>('all');

  const [records, setRecords] = useState<TeamReadinessRecord[]>([]);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  // Send Reminder modal state
  const [reminderRecord, setReminderRecord] = useState<TeamReadinessRecord | null>(null);
  const [drawerTarget, setDrawerTarget] = useState<{ scoutId?: number; signupId?: number } | null>(null);
  const [queryReplyTarget, setQueryReplyTarget] = useState<{ member: TeamReadinessMember; record: TeamReadinessRecord } | null>(null);
  const [queryReplyText, setQueryReplyText] = useState<string>('');
  const [queryReplyBusy, setQueryReplyBusy] = useState<boolean>(false);
  const [selectedSignupIds, setSelectedSignupIds] = useState<number[]>([]);
  const [previewLoading, setPreviewLoading] = useState<boolean>(false);
  const [previewData, setPreviewData] = useState<PreviewResponse | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [dispatching, setDispatching] = useState<boolean>(false);
  const [modalError, setModalError] = useState<string | null>(null);
  const [dispatchResult, setDispatchResult] = useState<DispatchModalResult | null>(null);

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

  const searchFilteredRecords = useMemo(() => {
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

  const filteredRecords = useMemo(() => {
    if (kpiFilter === 'size_valid') return searchFilteredRecords.filter((r) => r.size_valid);
    if (kpiFilter === 'first_aid_valid') return searchFilteredRecords.filter((r) => r.first_aid_valid);
    if (kpiFilter === 'route_approved') {
      return searchFilteredRecords.filter((r) => r.route_plan?.status === 'approved');
    }
    return searchFilteredRecords;
  }, [searchFilteredRecords, kpiFilter]);

  const stats = useMemo(() => {
    const total = searchFilteredRecords.length;
    const sizeValid = searchFilteredRecords.filter((r) => r.size_valid).length;
    const firstAidValid = searchFilteredRecords.filter((r) => r.first_aid_valid).length;
    const routeApproved = searchFilteredRecords.filter((r) => r.route_plan?.status === 'approved').length;
    return { total, sizeValid, firstAidValid, routeApproved };
  }, [searchFilteredRecords]);

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

  const handleOpenReminderModal = async (record: TeamReadinessRecord) => {
    setReminderRecord(record);
    setPreviewData(null);
    setPreviewError(null);
    setModalError(null);
    setDispatchResult(null);

    const allMemberIds = (record.members || [])
      .map((m) => m.expedition_signup_id || 0)
      .filter((id) => id > 0);

    const gapMemberIds = new Set(
      (record.members || [])
        .filter((m) => (m.expedition_signup_id || 0) > 0 && getMemberGaps(m, record.training_available).length > 0)
        .map((m) => m.expedition_signup_id)
    );

    if (allMemberIds.length === 0) {
      setSelectedSignupIds([]);
      return;
    }

    setPreviewLoading(true);
    try {
      const res = await fetch(`${rootUrl}/expeditions/notifications/preview`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({
          scope: 'cohort',
          cohort_id: record.cohort_id ?? null,
          expedition_signup_ids: allMemberIds,
          dofe_level: record.dofe_level || undefined,
          transport_mode: record.transport_mode || undefined,
        }),
      });
      const body = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(body?.message || `Preview failed (HTTP ${res.status})`);
      }
      const normalized = body as PreviewResponse;
      setPreviewData(normalized);
      const validIds = (normalized.recipients || [])
        .filter((r) => r.has_valid_email && gapMemberIds.has(r.expedition_signup_id))
        .map((r) => r.expedition_signup_id);
      setSelectedSignupIds(validIds);
    } catch (err) {
      setPreviewError(err instanceof Error ? err.message : 'Failed to preview reminder.');
      setSelectedSignupIds(Array.from(gapMemberIds));
    } finally {
      setPreviewLoading(false);
    }
  };

  const handleCloseReminderModal = () => {
    if (dispatching) return;
    setReminderRecord(null);
    setPreviewData(null);
    setPreviewError(null);
    setModalError(null);
    setDispatchResult(null);
  };

  const toggleMemberSelection = (signupId: number) => {
    setSelectedSignupIds((prev) =>
      prev.includes(signupId) ? prev.filter((id) => id !== signupId) : [...prev, signupId]
    );
  };

  const reminderRecipients: DispatchModalRecipient[] = useMemo(() => {
    if (!reminderRecord) return [];
    const previewById = new Map<number, PreviewRecipient>();
    for (const r of previewData?.recipients || []) {
      previewById.set(r.expedition_signup_id, r);
    }

    return (reminderRecord.members || []).map((member) => {
      const signupId = member.expedition_signup_id || 0;
      const previewRec = previewById.get(signupId) as (PreviewRecipient & { parent_email?: string }) | undefined;
      const gaps = getMemberGaps(member, reminderRecord.training_available);
      return {
        expedition_signup_id: signupId,
        scout_id: member.scout_id,
        explorer_name: previewRec?.explorer_name || member.name || 'Unnamed Explorer',
        explorer_email: previewRec?.explorer_email || '',
        parent_email: previewRec?.parent_email || '',
        has_valid_email: previewRec ? previewRec.has_valid_email : false,
        notification_status: previewRec?.notification_status || member.notification_status || 'draft',
        warnings: previewRec?.warnings || [],
        gaps,
      };
    });
  }, [reminderRecord, previewData]);

  const reminderWarnings: DispatchModalWarning[] = useMemo(() => {
    if (!reminderRecord) return [];
    const list: DispatchModalWarning[] = [...((previewData?.warnings as DispatchModalWarning[]) || [])];
    const hasCode = (code: string) => list.some((w) => w.code === code);

    if (!reminderRecord.size_valid && !hasCode('team_size_out_of_range')) {
      list.push({
        code: 'team_size_out_of_range',
        severity: 'warning',
        message: `Team size is ${reminderRecord.member_count} (recommended 4–7).`,
      });
    }
    if (!reminderRecord.first_aid_valid && !hasCode('insufficient_first_aid')) {
      list.push({
        code: 'insufficient_first_aid',
        severity: 'error',
        message: `Team has ${reminderRecord.first_aid_count} valid First Aider(s); Rule of Two requires at least 2.`,
      });
    }
    if ((reminderRecord.training_summary?.unlinked_oidc || 0) > 0 && !hasCode('unlinked_oidc')) {
      list.push({
        code: 'unlinked_oidc',
        severity: 'warning',
        message: `${reminderRecord.training_summary.unlinked_oidc} member(s) have not linked their OSM/OIDC account.`,
      });
    }
    return list;
  }, [reminderRecord, previewData]);

  const validReminderIds = useMemo(
    () =>
      reminderRecipients
        .filter((r) => r.has_valid_email && r.expedition_signup_id > 0)
        .map((r) => r.expedition_signup_id),
    [reminderRecipients]
  );

  const toggleSelectAllReminder = () => {
    const allSelected =
      validReminderIds.length > 0 && validReminderIds.every((id) => selectedSignupIds.includes(id));
    setSelectedSignupIds(allSelected ? [] : validReminderIds);
  };

  const handleDispatchReminder = async () => {
    if (!reminderRecord || selectedSignupIds.length === 0 || dispatching) return;
    setDispatching(true);
    setModalError(null);
    setDispatchResult(null);
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
      setDispatchResult({ ...body, dispatched_count: sent });
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
      <div className="ems-panel ems-readiness-toolbar">
        <div className="ems-readiness-filters">
          <span className="ems-readiness-filters__label">Filters:</span>

          <select
            aria-label="Filter by DofE Level"
            className="ems-select ems-readiness-filter-select"
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
            className="ems-select ems-readiness-filter-select"
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
            className="ems-select ems-readiness-filter-select"
            value={stage}
            onChange={(e) => setStage(e.target.value)}
          >
            <option value="">All Stages</option>
            <option value="practice">Practice</option>
            <option value="qualifier">Qualifier</option>
          </select>

          <div className="ems-readiness-search-wrap">
            <FontAwesomeIcon
              icon={faMagnifyingGlass}
              className="ems-readiness-search-icon"
            />
            <input
              type="search"
              aria-label="Search teams, events, or members"
              className="ems-readiness-search-input"
              placeholder="Search team, event, member…"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
            />
          </div>

          {(dofeLevel || transportMode || stage || searchQuery || kpiFilter !== 'all') && (
            <button
              type="button"
              className="button button-link"
              onClick={() => {
                setDofeLevel('');
                setTransportMode('');
                setStage('');
                setSearchQuery('');
                setKpiFilter('all');
              }}
            >
              Reset
            </button>
          )}
        </div>

        <div className="ems-readiness-actions">
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

      {/* Clickable KPI Summary Strip */}
      <div className="ems-readiness-kpi-strip" role="group" aria-label="Team Readiness KPI Filters">
        <button
          type="button"
          className={`ems-readiness-kpi-card ${kpiFilter === 'all' ? 'ems-readiness-kpi-card--active' : ''}`}
          aria-pressed={kpiFilter === 'all'}
          onClick={() => setKpiFilter('all')}
        >
          <span className="ems-readiness-kpi-card__label">Total Teams</span>
          <span className="ems-readiness-kpi-card__value">{stats.total}</span>
        </button>

        <button
          type="button"
          className={`ems-readiness-kpi-card ${kpiFilter === 'size_valid' ? 'ems-readiness-kpi-card--active' : ''}`}
          aria-pressed={kpiFilter === 'size_valid'}
          onClick={() => setKpiFilter((prev) => (prev === 'size_valid' ? 'all' : 'size_valid'))}
        >
          <span className="ems-readiness-kpi-card__label">Valid Size (4–7)</span>
          <span className="ems-readiness-kpi-card__value">
            {stats.sizeValid}
            <span className="ems-readiness-kpi-card__sub">/ {stats.total}</span>
          </span>
        </button>

        <button
          type="button"
          className={`ems-readiness-kpi-card ${kpiFilter === 'first_aid_valid' ? 'ems-readiness-kpi-card--active' : ''}`}
          aria-pressed={kpiFilter === 'first_aid_valid'}
          onClick={() => setKpiFilter((prev) => (prev === 'first_aid_valid' ? 'all' : 'first_aid_valid'))}
        >
          <span className="ems-readiness-kpi-card__label">First Aid Rule of Two</span>
          <span className="ems-readiness-kpi-card__value">
            {stats.firstAidValid}
            <span className="ems-readiness-kpi-card__sub">/ {stats.total}</span>
          </span>
        </button>

        <button
          type="button"
          className={`ems-readiness-kpi-card ${kpiFilter === 'route_approved' ? 'ems-readiness-kpi-card--active' : ''}`}
          aria-pressed={kpiFilter === 'route_approved'}
          onClick={() => setKpiFilter((prev) => (prev === 'route_approved' ? 'all' : 'route_approved'))}
        >
          <span className="ems-readiness-kpi-card__label">Routes Approved</span>
          <span className="ems-readiness-kpi-card__value">
            {stats.routeApproved}
            <span className="ems-readiness-kpi-card__sub">/ {stats.total}</span>
          </span>
        </button>
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
        onResolveQuery={(member, record) => {
          setQueryReplyTarget({ member, record });
          setQueryReplyText('');
        }}
      />

      {/* Unified Send Reminder Modal */}
      {reminderRecord && (
        <TeamEmailDispatchModal
          isOpen={true}
          onClose={handleCloseReminderModal}
          mode="compliance_reminder"
          title={`Send Reminder — ${reminderRecord.cohort_name || reminderRecord.team_code || reminderRecord.team_title}`}
          subtitle={
            formatEventSubtitle(
              reminderRecord.expedition_code,
              reminderRecord.expedition_title,
              reminderRecord.expedition_date
            ) || 'Unscheduled Forming Team'
          }
          ariaLabel="Send Team Readiness Reminder"
          recipients={reminderRecipients}
          warnings={reminderWarnings}
          loadingPreview={previewLoading}
          previewError={previewError}
          sampleEmail={(previewData?.sample_email as DispatchModalSampleEmail) || null}
          selectedIds={selectedSignupIds}
          onToggleRecipient={toggleMemberSelection}
          onToggleSelectAll={toggleSelectAllReminder}
          onInspectExplorer={(target) => setDrawerTarget(target)}
          dispatching={dispatching}
          dispatchError={modalError}
          dispatchResult={dispatchResult}
          onDispatch={handleDispatchReminder}
        />
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

      {queryReplyTarget && (
        <div className="ems-modal-backdrop">
          <div className="ems-modal" role="dialog" aria-modal="true" aria-label="Respond to Offer Query" style={{ maxWidth: '580px' }}>
            <h3 className="ems-m-0 ems-mb-12">
              Offer Query — {queryReplyTarget.member.name}
            </h3>
            <div style={{ background: '#fef3c7', border: '1px solid #f59e0b', padding: '10px 12px', borderRadius: '6px', marginBottom: '12px', fontSize: '13px', color: '#78350f' }}>
              <strong>Explorer / Parent Query:</strong>
              <div style={{ marginTop: '4px' }}>{queryReplyTarget.member.offer_query_message}</div>
            </div>
            <div className="ems-mb-16">
              <label className="ems-toolbar__label">Reply Message (emailed to Explorer &amp; Parent if provided)</label>
              <textarea
                rows={4}
                className="ems-w-full"
                placeholder="Write a reply explaining any changes or confirming details..."
                value={queryReplyText}
                onChange={(e) => setQueryReplyText(e.target.value)}
              />
            </div>
            <div className="ems-flex-between" style={{ gap: '8px', flexWrap: 'wrap' }}>
              <button
                type="button"
                className="button"
                disabled={queryReplyBusy}
                onClick={() => setQueryReplyTarget(null)}
              >
                Cancel
              </button>
              <div className="ems-flex-center ems-gap-8">
                <button
                  type="button"
                  className="button button-primary"
                  disabled={queryReplyBusy}
                  onClick={async () => {
                    setQueryReplyBusy(true);
                    try {
                      const res = await fetch(`${rootUrl}/teams/resolve-query`, {
                        method: 'POST',
                        headers: {
                          'Content-Type': 'application/json',
                          'X-WP-Nonce': nonce,
                        },
                        body: JSON.stringify({
                          cohort_id: queryReplyTarget.record.cohort_id || 0,
                          expedition_signup_id: queryReplyTarget.member.expedition_signup_id || 0,
                          team_post_id: queryReplyTarget.record.team_post_id || 0,
                          scout_id: queryReplyTarget.member.scout_id || 0,
                          reply_message: queryReplyText,
                          new_status: 'pending',
                        }),
                      });
                      if (!res.ok) throw new Error('Failed to resolve query.');
                      setQueryReplyTarget(null);
                      await fetchReadiness();
                    } catch (err: any) {
                      setError(err?.message || 'Failed to resolve query.');
                    } finally {
                      setQueryReplyBusy(false);
                    }
                  }}
                >
                  {queryReplyBusy ? 'Sending…' : queryReplyText.trim() ? 'Send Reply & Re-open Offer' : 'Mark Query Resolved'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default TeamsReadinessView;
