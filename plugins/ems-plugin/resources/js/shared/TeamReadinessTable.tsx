import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faBell,
  faChevronDown,
  faChevronRight,
  faSpinner,
  faUsers,
} from '@fortawesome/free-solid-svg-icons';

export interface TeamReadinessMember {
  expedition_signup_id: number;
  scout_id: number;
  wp_user_id: number | null;
  name: string;
  availability_override: string | null;
  notification_status: string;
  offer_status?: string;
  offer_query_message?: string | null;
  offer_responded_at?: string | null;
  first_aid_level: string;
  first_aid_expiry: string | null;
  first_aid_valid: boolean;
  training_status: 'complete' | 'in_progress' | 'not_started' | 'unlinked_oidc' | 'unavailable' | string;
  completed_modules: number;
  total_modules: number;
  last_completed_date: string | null;
}

export interface TeamReadinessOfferCounts {
  draft: number;
  ready_to_notify?: number;
  notified: number;
  re_notify_needed?: number;
  accepted: number;
  queried: number;
  declined: number;
}

export interface TeamReadinessTrainingSummary {
  complete: number;
  in_progress: number;
  not_started: number;
  unlinked_oidc: number;
  unavailable?: number;
}

export interface TeamReadinessRoutePlan {
  version: number;
  status: 'not_started' | 'draft' | 'submitted' | 'revisions_requested' | 'approved' | string;
  cutoff_date: string | null;
  days_until_cutoff: number | null;
  is_past_cutoff: boolean;
}

export interface TeamReadinessRecord {
  record_type: 'team' | 'forming_cohort' | string;
  team_post_id: number | null;
  team_code: string;
  team_title: string;
  cohort_id: number | null;
  cohort_name: string | null;
  cohort_status: string | null;
  dofe_level: string;
  transport_mode: string;
  stage: string;
  expedition_id: number | null;
  expedition_code: string;
  expedition_title: string;
  expedition_date: string;
  member_count: number;
  size_valid: boolean;
  offer_counts: TeamReadinessOfferCounts;
  first_aid_count: number;
  first_aid_valid: boolean;
  training_available: boolean;
  training_summary: TeamReadinessTrainingSummary;
  route_plan: TeamReadinessRoutePlan;
  members: TeamReadinessMember[];
}

export interface TeamReadinessTableProps {
  records: TeamReadinessRecord[];
  isLicScoped?: boolean;
  onSendReminder?: (record: TeamReadinessRecord) => void;
  onResolveQuery?: (member: TeamReadinessMember, record: TeamReadinessRecord) => void;
  onOpenEvent?: (eventId: number) => void;
  onOpenTeamBuilder?: (opts: { level?: string; transport?: string }) => void;
  loading?: boolean;
  emptyMessage?: string;
}

const UK_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function formatUkDate(value: string | null | undefined): string {
  if (!value) return '';
  const match = value.trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return value;
  const year = match[1];
  const monthIdx = parseInt(match[2], 10) - 1;
  const day = parseInt(match[3], 10);
  if (monthIdx < 0 || monthIdx > 11 || Number.isNaN(day)) return value;
  return `${day} ${UK_MONTHS[monthIdx]} ${year}`;
}

function formatUkMonthYear(value: string | null | undefined): string {
  if (!value) return '';
  const match = value.trim().match(/^(\d{4})-(\d{2})/);
  if (!match) return value;
  const year = match[1];
  const monthIdx = parseInt(match[2], 10) - 1;
  if (monthIdx < 0 || monthIdx > 11) return value;
  return `${UK_MONTHS[monthIdx]} ${year}`;
}

function getRecordKey(record: TeamReadinessRecord, index: number): string {
  if (record.team_post_id) {
    return `team-${record.team_post_id}`;
  }
  if (record.cohort_id) {
    return `cohort-${record.cohort_id}`;
  }
  return `row-${record.team_code}-${index}`;
}

function formatDofeLevelClass(level: string): string {
  const norm = (level || '').toLowerCase();
  if (norm === 'bronze') return 'ems-pill ems-pill--bronze';
  if (norm === 'silver') return 'ems-pill ems-pill--silver';
  if (norm === 'gold') return 'ems-pill ems-pill--gold';
  return 'ems-pill';
}

function formatDofeLevelLabel(level: string): string {
  const norm = (level || '').trim().toLowerCase();
  if (!norm) return '';
  return norm.charAt(0).toUpperCase() + norm.slice(1);
}

function formatTransportLabel(mode: string): string {
  const norm = (mode || '').toLowerCase();
  if (norm === 'biking' || norm === 'bike' || norm === 'cycling') return 'Biking';
  if (norm === 'paddling' || norm === 'paddle' || norm === 'canoe') return 'Paddling';
  return 'Foot';
}

function formatStageText(stage: string): string {
  const norm = (stage || '').toLowerCase();
  if (norm === 'practice') return 'Practice';
  if (norm === 'qualifier' || norm === 'qualifying') return 'Qualifier';
  return 'Unscheduled';
}

function formatOfferLabel(status: string, isLicScoped: boolean): { label: string; toneClass: string } {
  const norm = (status || 'draft').toLowerCase();
  switch (norm) {
    case 'accepted':
      return { label: 'Accepted', toneClass: 'ems-readiness-status--ok' };
    case 'notified':
      return { label: 'Offer Sent', toneClass: 'ems-readiness-status--info' };
    case 'queried':
      return { label: 'Queried', toneClass: 'ems-readiness-status--warn' };
    case 'declined':
      return { label: 'Declined', toneClass: 'ems-readiness-status--danger' };
    case 're_notify_needed':
      return { label: isLicScoped ? 'Pending' : 'Re-notify', toneClass: 'ems-readiness-status--warn' };
    case 'ready_to_notify':
      return { label: isLicScoped ? 'Pending' : 'Ready', toneClass: 'ems-readiness-status--muted' };
    case 'draft':
    default:
      return { label: isLicScoped ? 'Pending' : 'Draft', toneClass: 'ems-readiness-status--muted' };
  }
}

function formatFirstAidTitle(level: string): string | null {
  const norm = (level || '').trim().toLowerCase().replace(/_/g, '-');
  if (!norm || norm === 'none' || norm === 'no' || norm === 'first-aid-no') {
    return null;
  }
  if (norm === 'full-first-aid') return 'Full First Aid';
  if (norm === 'first-response') return 'First Response';
  return level.replace(/[-_]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatAvailabilityException(override: string | null, isLicScoped: boolean): string | null {
  if (!override) return null;
  const norm = override.trim().toLowerCase();
  if (!norm || norm === 'matched' || norm === 'none') return null;
  if (norm === 'date_conflict_pending') {
    return isLicScoped ? null : 'Date conflict pending';
  }
  if (norm === 'split_date' || norm === 'split_practice' || norm === 'split_qualifier') {
    return 'Split date';
  }
  return isLicScoped ? null : override.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatRouteStatus(status: string): { label: string; toneClass: string } {
  const norm = (status || 'not_started').toLowerCase();
  switch (norm) {
    case 'approved':
      return { label: 'Approved', toneClass: 'ems-readiness-status--ok' };
    case 'submitted':
      return { label: 'Submitted', toneClass: 'ems-readiness-status--warn' };
    case 'revisions_requested':
      return { label: 'Revisions Requested', toneClass: 'ems-readiness-status--danger' };
    case 'draft':
      return { label: 'Draft', toneClass: 'ems-readiness-status--muted' };
    case 'not_started':
    default:
      return { label: 'Not started', toneClass: 'ems-readiness-status--muted' };
  }
}

function formatRouteDrawerStatus(status: string): string {
  const norm = (status || 'not_started').toLowerCase();
  if (norm === 'not_started') return 'Not Started';
  return formatRouteStatus(norm).label;
}

export const TeamReadinessTable: React.FC<TeamReadinessTableProps> = ({
  records,
  isLicScoped = false,
  onSendReminder,
  onResolveQuery,
  onOpenEvent,
  onOpenTeamBuilder,
  loading = false,
  emptyMessage = 'No teams match the current filters.',
}) => {
  const [expandedKeys, setExpandedKeys] = useState<Record<string, boolean>>({});

  const toggleRow = (key: string) => {
    setExpandedKeys((prev) => ({ ...prev, [key]: !prev[key] }));
  };

  if (loading) {
    return (
      <div className="ems-panel" style={{ padding: '32px', textAlign: 'center', color: '#646970' }}>
        <FontAwesomeIcon icon={faSpinner} spin style={{ marginRight: '8px' }} />
        Loading team readiness…
      </div>
    );
  }

  if (!records || records.length === 0) {
    return (
      <div className="ems-empty">
        <div>
          <FontAwesomeIcon icon={faUsers} size="lg" style={{ marginBottom: '8px', color: '#8c8f94' }} />
          <div>{emptyMessage}</div>
        </div>
      </div>
    );
  }

  const showActionsCol = true;

  return (
    <div className="ems-table-wrap">
      <table className="ems-table">
        <thead>
          <tr>
            <th>Team</th>
            <th>Event &amp; Stage</th>
            <th>Size &amp; Place Offers</th>
            <th>First Aid (Rule of Two)</th>
            <th>Training (Tutor LMS)</th>
            <th>Route Plan Status</th>
            {showActionsCol && <th style={{ textAlign: 'right' }}>Actions</th>}
          </tr>
        </thead>
        <tbody>
          {records.map((record, idx) => {
            const rowKey = getRecordKey(record, idx);
            const isExpanded = !!expandedKeys[rowKey];
            const canonicalTeamName = record.cohort_name || record.team_title || record.team_code || 'Unnamed Team';
            const levelLabel = formatDofeLevelLabel(record.dofe_level);
            const transportLabel = formatTransportLabel(record.transport_mode);
            const stageLabel = formatStageText(record.stage);
            const routeMeta = formatRouteStatus(record.route_plan?.status ?? 'not_started');

            const offers = record.offer_counts || {
              draft: 0,
              notified: 0,
              accepted: 0,
              queried: 0,
              declined: 0,
            };
            const draftCount =
              (offers.draft || 0) + (offers.ready_to_notify || 0) + (offers.re_notify_needed || 0);
            const notifiedCount = offers.notified || 0;
            const queriedCount = offers.queried || 0;
            const declinedCount = offers.declined || 0;
            const hasNotifiedMembers =
              notifiedCount > 0 ||
              (record.members || []).some((m) => (m.notification_status || '').toLowerCase() === 'notified');

            const training = record.training_summary || {
              complete: 0,
              in_progress: 0,
              not_started: 0,
              unlinked_oidc: 0,
            };
            const trainingTotal =
              record.member_count > 0
                ? record.member_count
                : (training.complete || 0) +
                  (training.in_progress || 0) +
                  (training.not_started || 0) +
                  (training.unlinked_oidc || 0);
            const trainingComplete = training.complete || 0;
            const trainingPct = trainingTotal > 0 ? Math.min(100, Math.round((trainingComplete / trainingTotal) * 100)) : 0;

            const validFirstAiders = (record.members || [])
              .filter((m) => m.first_aid_valid && m.name)
              .map((m) => m.name);

            const daysUntilCutoff = record.route_plan?.days_until_cutoff;
            const isPastCutoff = !!record.route_plan?.is_past_cutoff;
            const sizeToneClass = record.size_valid ? 'ems-readiness-status--ok' : 'ems-readiness-status--warn';
            const faToneClass =
              record.first_aid_count >= 2
                ? 'ems-readiness-status--ok'
                : record.first_aid_count === 1
                ? 'ems-readiness-status--warn'
                : 'ems-readiness-status--danger';

            return (
              <React.Fragment key={rowKey}>
                <tr
                  className={`ems-readiness-row ${isExpanded ? 'ems-table-row--selected' : ''}`}
                  onClick={() => toggleRow(rowKey)}
                >
                  {/* 1. Team */}
                  <td>
                    <div className="ems-readiness-team-title">
                      <FontAwesomeIcon
                        icon={isExpanded ? faChevronDown : faChevronRight}
                        className="ems-readiness-team-chevron"
                      />
                      <span>{canonicalTeamName}</span>
                    </div>
                    <div className="ems-readiness-team-meta">
                      {record.team_code && (
                        <>
                          {onOpenTeamBuilder ? (
                            <button
                              type="button"
                              className="button-link"
                              style={{ padding: 0, fontSize: 'inherit' }}
                              onClick={(e) => {
                                e.stopPropagation();
                                onOpenTeamBuilder({
                                  level: record.dofe_level,
                                  transport: record.transport_mode,
                                });
                              }}
                              title="Open in Team Builder"
                            >
                              {record.team_code}
                            </button>
                          ) : (
                            <span>{record.team_code}</span>
                          )}
                          <span aria-hidden="true">·</span>
                        </>
                      )}
                      {levelLabel && (
                        <>
                          <span className={formatDofeLevelClass(record.dofe_level)}>{levelLabel}</span>
                          <span aria-hidden="true">·</span>
                        </>
                      )}
                      <span>{transportLabel}</span>
                    </div>
                  </td>

                  {/* 2. Event & Stage */}
                  <td>
                    {record.expedition_code || record.expedition_title ? (
                      <div className="ems-readiness-status" style={{ color: '#0f172a' }}>
                        {onOpenEvent && record.expedition_id ? (
                          <button
                            type="button"
                            className="button-link"
                            style={{ padding: 0, fontWeight: 600 }}
                            onClick={() => onOpenEvent(record.expedition_id!)}
                            title="Open event details"
                          >
                            {record.expedition_code || record.expedition_title}
                          </button>
                        ) : (
                          record.expedition_code || record.expedition_title
                        )}{' '}
                        · {stageLabel}
                      </div>
                    ) : (
                      <div className="ems-readiness-status ems-readiness-status--muted">Unscheduled</div>
                    )}
                    {record.expedition_date && (
                      <div className="ems-readiness-subtext">{formatUkDate(record.expedition_date)}</div>
                    )}
                  </td>

                  {/* 3. Size & Place Offers */}
                  <td>
                    <div className={`ems-readiness-status ${sizeToneClass}`}>
                      {record.member_count}/7 members
                    </div>
                    <div className="ems-readiness-subtext">
                      <span>
                        {offers.accepted || 0}/{record.member_count} accepted · {draftCount}{' '}
                        {isLicScoped ? 'pending' : 'draft'}
                      </span>
                      {notifiedCount > 0 && (
                        <span className="ems-readiness-status--info" style={{ fontWeight: 600 }}>
                          {' '}· {notifiedCount} notified
                        </span>
                      )}
                      {queriedCount > 0 && (
                        <span className="ems-readiness-status--warn" style={{ fontWeight: 600 }}>
                          {' '}· {queriedCount} queried
                        </span>
                      )}
                      {declinedCount > 0 && (
                        <span className="ems-readiness-status--danger" style={{ fontWeight: 600 }}>
                          {' '}· {declinedCount} declined
                        </span>
                      )}
                    </div>
                  </td>

                  {/* 4. First Aid (Rule of Two) */}
                  <td>
                    <div className={`ems-readiness-status ${faToneClass}`}>
                      {record.first_aid_count}/2 valid
                    </div>
                    <div className="ems-readiness-subtext">
                      {record.first_aid_count >= 2
                        ? validFirstAiders.length > 0
                          ? validFirstAiders.join(', ')
                          : 'Rule of Two met'
                        : validFirstAiders.length > 0
                        ? `${validFirstAiders.join(', ')} · Needs 1 more`
                        : 'Needs First Aiders'}
                    </div>
                  </td>

                  {/* 5. Training (Tutor LMS) */}
                  <td>
                    {!record.training_available ? (
                      <span className="ems-readiness-status ems-readiness-status--muted">
                        Tutor LMS unavailable
                      </span>
                    ) : (
                      <div>
                        <div className="ems-readiness-progress-wrap">
                          <div className="ems-readiness-progress" aria-hidden="true">
                            <div
                              className={`ems-readiness-progress__fill ${
                                trainingTotal > 0 && trainingComplete >= trainingTotal
                                  ? 'ems-readiness-progress__fill--complete'
                                  : ''
                              }`}
                              style={{ width: `${trainingPct}%` }}
                            />
                          </div>
                          <span className="ems-readiness-status" style={{ color: '#0f172a', fontWeight: 500 }}>
                            {trainingComplete}/{trainingTotal} complete
                          </span>
                        </div>
                        {(training.unlinked_oidc || 0) > 0 && (
                          <div style={{ marginTop: '4px' }}>
                            <span className="ems-readiness-pill-warn">
                              {isLicScoped ? 'Not Linked' : 'Unlinked OIDC'}: {training.unlinked_oidc}
                            </span>
                          </div>
                        )}
                      </div>
                    )}
                  </td>

                  {/* 6. Route Plan Status */}
                  <td>
                    <div className={`ems-readiness-status ${routeMeta.toneClass}`}>
                      {routeMeta.label}
                      {record.route_plan?.version > 0 ? ` v${record.route_plan.version}` : ''}
                    </div>
                    {daysUntilCutoff !== null && daysUntilCutoff !== undefined && (
                      <div
                        className={`ems-readiness-subtext ${
                          isPastCutoff ? 'ems-readiness-status--danger' : ''
                        }`}
                      >
                        {isPastCutoff
                          ? `${Math.abs(daysUntilCutoff)}d past cutoff`
                          : daysUntilCutoff === 0
                          ? 'Cutoff is today'
                          : `${daysUntilCutoff}d until cutoff`}
                      </div>
                    )}
                  </td>

                  {/* 7. Actions */}
                  {showActionsCol && (
                    <td
                      style={{ textAlign: 'right', whiteSpace: 'nowrap' }}
                      onClick={(e) => e.stopPropagation()}
                    >
                      <div style={{ display: 'inline-flex', gap: '6px', alignItems: 'center' }}>
                        {!isLicScoped && onSendReminder && (
                          <button
                            type="button"
                            className="button button-small"
                            disabled={!hasNotifiedMembers}
                            onClick={() => onSendReminder(record)}
                            title={
                              hasNotifiedMembers
                                ? 'Send targeted reminder to notified members'
                                : 'Available once place offers have been notified'
                            }
                          >
                            <FontAwesomeIcon icon={faBell} style={{ marginRight: '4px' }} />
                            Send Reminder
                          </button>
                        )}
                        <button
                          type="button"
                          className="ems-btn-ghost"
                          onClick={() => toggleRow(rowKey)}
                          aria-expanded={isExpanded}
                        >
                          {isExpanded ? 'Hide' : 'Details'}
                        </button>
                      </div>
                    </td>
                  )}
                </tr>

                {/* Expandable Row Drawer */}
                {isExpanded && (
                  <tr className="ems-readiness-drawer-row">
                    <td colSpan={showActionsCol ? 7 : 6} className="ems-readiness-drawer-cell">
                      <div className="ems-readiness-drawer-header">
                        <div className="ems-readiness-drawer-title">
                          Member Compliance Breakdown — {canonicalTeamName} ({record.members?.length || 0} members)
                        </div>
                        <div className="ems-readiness-drawer-sub">
                          <span>
                            Route Card: <strong>{formatRouteDrawerStatus(record.route_plan?.status ?? 'not_started')}</strong>
                            {record.route_plan?.version > 0 ? ` v${record.route_plan.version}` : ''}
                          </span>
                          {record.route_plan?.cutoff_date && (
                            <span> · Cutoff: {formatUkDate(record.route_plan.cutoff_date)}</span>
                          )}
                        </div>
                      </div>

                      {!record.members || record.members.length === 0 ? (
                        <div className="ems-readiness-subtext" style={{ padding: '8px 0' }}>
                          No members currently assigned to this team.
                        </div>
                      ) : (
                        <div className="ems-readiness-drawer-table-wrap">
                          <table className="ems-table" style={{ fontSize: '12px' }}>
                            <thead>
                              <tr>
                                <th>Explorer</th>
                                <th>Place Offer</th>
                                <th>First Aid Qualification</th>
                                <th>Tutor LMS Progress</th>
                                <th>Route Card Status</th>
                              </tr>
                            </thead>
                            <tbody>
                              {record.members.map((member, mIdx) => {
                                const effectiveOfferStatus =
                                  member.offer_status && ['accepted', 'queried', 'declined'].includes(member.offer_status.toLowerCase())
                                    ? member.offer_status
                                    : member.notification_status;
                                const offerInfo = formatOfferLabel(effectiveOfferStatus, isLicScoped);
                                const exceptionLabel = formatAvailabilityException(
                                  member.availability_override,
                                  isLicScoped
                                );
                                const faTitle = formatFirstAidTitle(member.first_aid_level);
                                const faExpiryFormatted = formatUkMonthYear(member.first_aid_expiry);
                                const memberTotal = member.total_modules || 0;
                                const memberDone = member.completed_modules || 0;
                                const memberPct =
                                  memberTotal > 0 ? Math.min(100, Math.round((memberDone / memberTotal) * 100)) : 0;
                                const memberKey = `${rowKey}-m-${member.expedition_signup_id || member.scout_id || mIdx}`;

                                return (
                                  <tr key={memberKey}>
                                    <td>
                                      <span style={{ fontWeight: 600, color: '#0f172a' }}>
                                        {member.name || 'Unnamed Explorer'}
                                      </span>
                                      {exceptionLabel && (
                                        <div style={{ marginTop: '3px' }}>
                                          <span className="ems-readiness-pill-warn">{exceptionLabel}</span>
                                        </div>
                                      )}
                                    </td>
                                    <td>
                                      <span className={`ems-readiness-status ${offerInfo.toneClass}`}>
                                        {offerInfo.label}
                                      </span>
                                      {member.offer_status === 'queried' && member.offer_query_message && (
                                        <div style={{ marginTop: '4px', padding: '4px 6px', background: '#fef3c7', border: '1px solid #f59e0b', borderRadius: '4px', fontSize: '11px', color: '#78350f' }}>
                                          <div><strong>Query:</strong> {member.offer_query_message}</div>
                                          {onResolveQuery && !isLicScoped && (
                                            <button
                                              type="button"
                                              className="button-link"
                                              style={{ fontSize: '11px', padding: 0, marginTop: '2px' }}
                                              onClick={() => onResolveQuery(member, record)}
                                            >
                                              Reply / Resolve
                                            </button>
                                          )}
                                        </div>
                                      )}
                                    </td>
                                    <td>
                                      {!faTitle ? (
                                        <span className="ems-readiness-status ems-readiness-status--danger">
                                          None recorded
                                        </span>
                                      ) : member.first_aid_valid ? (
                                        <span className="ems-readiness-status ems-readiness-status--ok">
                                          {faTitle}
                                          {faExpiryFormatted ? ` · Exp: ${faExpiryFormatted}` : ''}
                                        </span>
                                      ) : (
                                        <span className="ems-readiness-status ems-readiness-status--danger">
                                          {faTitle}
                                          {faExpiryFormatted ? ` · Expired ${faExpiryFormatted}` : ' · Invalid'}
                                        </span>
                                      )}
                                    </td>
                                    <td>
                                      {member.training_status === 'unlinked_oidc' ? (
                                        <span className="ems-readiness-pill-warn">
                                          {isLicScoped ? 'Not Linked' : 'Unlinked OIDC'}
                                        </span>
                                      ) : !record.training_available || member.training_status === 'unavailable' ? (
                                        <span className="ems-readiness-status ems-readiness-status--muted">
                                          Unavailable
                                        </span>
                                      ) : (
                                        <div className="ems-readiness-progress-wrap">
                                          <div className="ems-readiness-progress" aria-hidden="true">
                                            <div
                                              className={`ems-readiness-progress__fill ${
                                                member.training_status === 'complete'
                                                  ? 'ems-readiness-progress__fill--complete'
                                                  : ''
                                              }`}
                                              style={{ width: `${memberPct}%` }}
                                            />
                                          </div>
                                          <span
                                            className={`ems-readiness-status ${
                                              member.training_status === 'complete'
                                                ? 'ems-readiness-status--ok'
                                                : 'ems-readiness-status--muted'
                                            }`}
                                          >
                                            {memberDone}/{memberTotal} modules
                                          </span>
                                        </div>
                                      )}
                                    </td>
                                    <td>
                                      <span className={`ems-readiness-status ${routeMeta.toneClass}`}>
                                        {routeMeta.label}
                                        {record.route_plan?.version > 0 ? ` v${record.route_plan.version}` : ''}
                                      </span>
                                    </td>
                                  </tr>
                                );
                              })}
                            </tbody>
                          </table>
                        </div>
                      )}
                    </td>
                  </tr>
                )}
              </React.Fragment>
            );
          })}
        </tbody>
      </table>
    </div>
  );
};

export default TeamReadinessTable;
