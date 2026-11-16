import React, { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
  faBell,
  faBicycle,
  faCalendarDay,
  faChevronDown,
  faChevronRight,
  faCircleCheck,
  faCircleExclamation,
  faClock,
  faGraduationCap,
  faKitMedical,
  faMapLocationDot,
  faPersonHiking,
  faSpinner,
  faTriangleExclamation,
  faUsers,
  faWater,
} from '@fortawesome/free-solid-svg-icons';

export interface TeamReadinessMember {
  expedition_signup_id: number;
  scout_id: number;
  wp_user_id: number | null;
  name: string;
  availability_override: string | null;
  notification_status: string;
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
  loading?: boolean;
  emptyMessage?: string;
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

function getTransportMeta(mode: string): { icon: typeof faPersonHiking; label: string } {
  const norm = (mode || '').toLowerCase();
  if (norm === 'biking' || norm === 'bike' || norm === 'cycling') {
    return { icon: faBicycle, label: 'Biking' };
  }
  if (norm === 'paddling' || norm === 'paddle' || norm === 'canoe') {
    return { icon: faWater, label: 'Paddling' };
  }
  return { icon: faPersonHiking, label: 'Foot' };
}

function formatStageLabel(stage: string): { label: string; bg: string; color: string; border: string } {
  const norm = (stage || '').toLowerCase();
  if (norm === 'practice') {
    return { label: 'Practice', bg: '#eff6ff', color: '#1d4ed8', border: '#bfdbfe' };
  }
  if (norm === 'qualifier' || norm === 'qualifying') {
    return { label: 'Qualifier', bg: '#f5f3ff', color: '#6d28d9', border: '#ddd6fe' };
  }
  return { label: 'Unscheduled', bg: '#f3f4f6', color: '#4b5563', border: '#d1d5db' };
}

function formatOfferStatusBadge(
  status: string,
  isLicScoped: boolean
): { label: string; bg: string; color: string; border: string } {
  const norm = (status || 'draft').toLowerCase();
  switch (norm) {
    case 'accepted':
      return { label: 'Accepted', bg: '#dcfce7', color: '#166534', border: '#86efac' };
    case 'notified':
      return { label: 'Notified', bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' };
    case 'queried':
      return { label: 'Queried', bg: '#fef3c7', color: '#92400e', border: '#fcd34d' };
    case 'declined':
      return { label: 'Declined', bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' };
    case 're_notify_needed':
      return {
        label: isLicScoped ? 'Pending' : 'Re-notify',
        bg: '#fef3c7',
        color: '#92400e',
        border: '#fcd34d',
      };
    case 'ready_to_notify':
      return {
        label: isLicScoped ? 'Pending' : 'Ready',
        bg: '#f3f4f6',
        color: '#374151',
        border: '#d1d5db',
      };
    case 'draft':
    default:
      return {
        label: isLicScoped ? 'Pending' : 'Draft',
        bg: '#f3f4f6',
        color: '#374151',
        border: '#d1d5db',
      };
  }
}

function formatFirstAidLevel(level: string): string {
  const norm = (level || 'none').toLowerCase();
  if (norm === 'full_first_aid') return 'Full First Aid';
  if (norm === 'first_response') return 'First Response';
  if (norm === 'none' || norm === 'no') return 'None';
  return level.replace(/_/g, ' ');
}

function formatTrainingStatusBadge(
  status: string,
  isLicScoped: boolean
): { label: string; bg: string; color: string; border: string } {
  const norm = (status || 'not_started').toLowerCase();
  switch (norm) {
    case 'complete':
      return { label: 'Complete', bg: '#dcfce7', color: '#166534', border: '#86efac' };
    case 'in_progress':
      return { label: 'In Progress', bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' };
    case 'unlinked_oidc':
      return {
        label: isLicScoped ? 'Not Linked' : 'Unlinked OIDC',
        bg: '#fef3c7',
        color: '#92400e',
        border: '#fcd34d',
      };
    case 'unavailable':
      return { label: 'Unavailable', bg: '#f3f4f6', color: '#6b7280', border: '#d1d5db' };
    case 'not_started':
    default:
      return { label: 'Not Started', bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' };
  }
}

function formatRoutePlanBadge(status: string): { label: string; bg: string; color: string; border: string } {
  const norm = (status || 'not_started').toLowerCase();
  switch (norm) {
    case 'approved':
      return { label: 'Approved', bg: '#dcfce7', color: '#166534', border: '#86efac' };
    case 'submitted':
      return { label: 'Submitted', bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' };
    case 'revisions_requested':
      return { label: 'Revisions Requested', bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' };
    case 'draft':
      return { label: 'Draft', bg: '#fef3c7', color: '#92400e', border: '#fcd34d' };
    case 'not_started':
    default:
      return { label: 'Not Started', bg: '#f3f4f6', color: '#374151', border: '#d1d5db' };
  }
}

const pillStyle = (colors: { bg: string; color: string; border: string }): React.CSSProperties => ({
  display: 'inline-flex',
  alignItems: 'center',
  gap: '4px',
  padding: '2px 8px',
  borderRadius: '9999px',
  fontSize: '11px',
  fontWeight: 600,
  lineHeight: 1.3,
  backgroundColor: colors.bg,
  color: colors.color,
  border: `1px solid ${colors.border}`,
  whiteSpace: 'nowrap',
});

export const TeamReadinessTable: React.FC<TeamReadinessTableProps> = ({
  records,
  isLicScoped = false,
  onSendReminder,
  loading = false,
  emptyMessage = 'No teams or forming cohorts match the current filters.',
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

  const showActionsCol = true; // Always show expand toggle; Send Reminder shown only when !isLicScoped && onSendReminder

  return (
    <div className="ems-table-wrap" style={{ overflowX: 'auto' }}>
      <table className="ems-table">
        <thead>
          <tr>
            <th>Team / Cohort</th>
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
            const transport = getTransportMeta(record.transport_mode);
            const stageBadge = formatStageLabel(record.stage);
            const routeBadge = formatRoutePlanBadge(record.route_plan?.status ?? 'not_started');

            const offers = record.offer_counts || {
              draft: 0,
              notified: 0,
              accepted: 0,
              queried: 0,
              declined: 0,
            };
            const draftCount =
              (offers.draft || 0) + (offers.ready_to_notify || 0) + (offers.re_notify_needed || 0);

            const training = record.training_summary || {
              complete: 0,
              in_progress: 0,
              not_started: 0,
              unlinked_oidc: 0,
            };

            const daysUntilCutoff = record.route_plan?.days_until_cutoff;
            const isPastCutoff = !!record.route_plan?.is_past_cutoff;

            return (
              <React.Fragment key={rowKey}>
                <tr
                  className={isExpanded ? 'ems-table-row--selected' : ''}
                  style={{ cursor: 'pointer' }}
                  onClick={() => toggleRow(rowKey)}
                >
                  {/* 1. Team / Cohort */}
                  <td>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                      <FontAwesomeIcon
                        icon={isExpanded ? faChevronDown : faChevronRight}
                        style={{ color: '#646970', fontSize: '11px', width: '12px' }}
                      />
                      <span className="ems-table__name">{record.team_code || record.team_title}</span>
                      {record.record_type === 'forming_cohort' && (
                        <span
                          style={pillStyle({ bg: '#f3f4f6', color: '#4b5563', border: '#d1d5db' })}
                        >
                          Forming
                        </span>
                      )}
                    </div>
                    {record.team_title && record.team_title !== record.team_code && (
                      <div className="ems-table__meta">{record.team_title}</div>
                    )}
                    {record.cohort_name && record.cohort_name !== record.team_code && (
                      <div className="ems-table__meta">Cohort: {record.cohort_name}</div>
                    )}
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px', marginTop: '6px', flexWrap: 'wrap' }}>
                      {record.dofe_level && (
                        <span className={formatDofeLevelClass(record.dofe_level)}>
                          {record.dofe_level}
                        </span>
                      )}
                      <span style={{ fontSize: '11px', color: '#50575e', display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
                        <FontAwesomeIcon icon={transport.icon} />
                        {transport.label}
                      </span>
                    </div>
                  </td>

                  {/* 2. Event & Stage */}
                  <td>
                    {record.expedition_code || record.expedition_title ? (
                      <>
                        <div className="ems-table__name">
                          {record.expedition_code ? `${record.expedition_code}` : record.expedition_title}
                        </div>
                        {record.expedition_title && record.expedition_code && (
                          <div className="ems-table__meta">{record.expedition_title}</div>
                        )}
                      </>
                    ) : (
                      <div className="ems-table__meta">No event assigned</div>
                    )}
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px', marginTop: '6px', flexWrap: 'wrap' }}>
                      <span style={pillStyle(stageBadge)}>{stageBadge.label}</span>
                      {record.expedition_date && (
                        <span style={{ fontSize: '11px', color: '#50575e', display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
                          <FontAwesomeIcon icon={faCalendarDay} />
                          {record.expedition_date}
                        </span>
                      )}
                    </div>
                  </td>

                  {/* 3. Size & Place Offers */}
                  <td>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px', marginBottom: '6px' }}>
                      <span
                        className={`ems-pill ${
                          record.size_valid ? 'ems-pill--asn-validated' : 'ems-pill--asn-action'
                        }`}
                      >
                        <FontAwesomeIcon
                          icon={record.size_valid ? faCircleCheck : faTriangleExclamation}
                          style={{ marginRight: '4px' }}
                        />
                        {record.member_count} {record.member_count === 1 ? 'member' : 'members'} (4–7)
                      </span>
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px', fontSize: '11px' }}>
                      <span style={pillStyle({ bg: '#dcfce7', color: '#166534', border: '#86efac' })}>
                        Accepted: {offers.accepted || 0}
                      </span>
                      <span style={pillStyle({ bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' })}>
                        Notified: {offers.notified || 0}
                      </span>
                      {draftCount > 0 && (
                        <span style={pillStyle({ bg: '#f3f4f6', color: '#374151', border: '#d1d5db' })}>
                          {isLicScoped ? 'Pending' : 'Draft'}: {draftCount}
                        </span>
                      )}
                      {(offers.queried || 0) > 0 && (
                        <span style={pillStyle({ bg: '#fef3c7', color: '#92400e', border: '#fcd34d' })}>
                          Queried: {offers.queried}
                        </span>
                      )}
                      {(offers.declined || 0) > 0 && (
                        <span style={pillStyle({ bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' })}>
                          Declined: {offers.declined}
                        </span>
                      )}
                    </div>
                  </td>

                  {/* 4. First Aid (Rule of Two) */}
                  <td>
                    <span
                      className={`ems-pill ${
                        record.first_aid_valid ? 'ems-pill--asn-validated' : 'ems-pill--asn-action'
                      }`}
                    >
                      <FontAwesomeIcon
                        icon={record.first_aid_valid ? faKitMedical : faTriangleExclamation}
                        style={{ marginRight: '5px' }}
                      />
                      {record.first_aid_count} / 2+ valid
                    </span>
                    <div className="ems-table__meta" style={{ marginTop: '4px' }}>
                      {record.first_aid_valid ? 'Rule of Two met' : 'Needs valid First Aiders'}
                    </div>
                  </td>

                  {/* 5. Training (Tutor LMS) */}
                  <td>
                    {!record.training_available ? (
                      <span style={pillStyle({ bg: '#f3f4f6', color: '#6b7280', border: '#d1d5db' })}>
                        <FontAwesomeIcon icon={faGraduationCap} />
                        Tutor LMS Unavailable
                      </span>
                    ) : (
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                        <span style={pillStyle({ bg: '#dcfce7', color: '#166534', border: '#86efac' })}>
                          Complete: {training.complete || 0}
                        </span>
                        <span style={pillStyle({ bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' })}>
                          In Progress: {training.in_progress || 0}
                        </span>
                        {(training.not_started || 0) > 0 && (
                          <span style={pillStyle({ bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' })}>
                            Not Started: {training.not_started}
                          </span>
                        )}
                        {(training.unlinked_oidc || 0) > 0 && (
                          <span style={pillStyle({ bg: '#fef3c7', color: '#92400e', border: '#fcd34d' })}>
                            {isLicScoped ? 'Not Linked' : 'Unlinked OIDC'}: {training.unlinked_oidc}
                          </span>
                        )}
                      </div>
                    )}
                  </td>

                  {/* 6. Route Plan Status */}
                  <td>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }}>
                      <span style={pillStyle(routeBadge)}>
                        <FontAwesomeIcon icon={faMapLocationDot} />
                        {routeBadge.label}
                        {record.route_plan?.version > 0 ? ` (v${record.route_plan.version})` : ''}
                      </span>
                    </div>
                    {daysUntilCutoff !== null && daysUntilCutoff !== undefined && (
                      <div
                        className="ems-table__meta"
                        style={{
                          marginTop: '5px',
                          color: isPastCutoff
                            ? '#991b1b'
                            : daysUntilCutoff <= 7
                            ? '#92400e'
                            : '#646970',
                          fontWeight: isPastCutoff || daysUntilCutoff <= 7 ? 600 : 400,
                          display: 'flex',
                          alignItems: 'center',
                          gap: '4px',
                        }}
                      >
                        <FontAwesomeIcon icon={faClock} />
                        {isPastCutoff
                          ? `${Math.abs(daysUntilCutoff)}d past 14-day cutoff`
                          : daysUntilCutoff === 0
                          ? '14-day cutoff is today'
                          : `${daysUntilCutoff}d until 14-day cutoff`}
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
                            onClick={() => onSendReminder(record)}
                            title="Send targeted reminder to pending or incomplete members"
                          >
                            <FontAwesomeIcon icon={faBell} style={{ marginRight: '4px' }} />
                            Send Reminder
                          </button>
                        )}
                        <button
                          type="button"
                          className="button button-small"
                          onClick={() => toggleRow(rowKey)}
                          aria-expanded={isExpanded}
                        >
                          <FontAwesomeIcon
                            icon={isExpanded ? faChevronDown : faChevronRight}
                            style={{ marginRight: '4px' }}
                          />
                          {isExpanded ? 'Hide' : 'Details'}
                        </button>
                      </div>
                    </td>
                  )}
                </tr>

                {/* Expandable Row Drawer */}
                {isExpanded && (
                  <tr className="ems-readiness-drawer-row">
                    <td
                      colSpan={showActionsCol ? 7 : 6}
                      style={{ background: '#f8fafc', padding: '14px 18px', borderBottom: '1px solid #ccd0d4' }}
                    >
                      <div
                        style={{
                          display: 'flex',
                          justifyContent: 'space-between',
                          alignItems: 'center',
                          flexWrap: 'wrap',
                          gap: '10px',
                          marginBottom: '10px',
                        }}
                      >
                        <div style={{ fontWeight: 600, fontSize: '13px', color: '#1d2327' }}>
                          Member Compliance Breakdown — {record.team_code || record.team_title} (
                          {record.members?.length || 0} members)
                        </div>
                        <div style={{ fontSize: '12px', color: '#50575e', display: 'flex', gap: '12px', flexWrap: 'wrap' }}>
                          <span>
                            <strong>Route Card:</strong> {routeBadge.label}
                            {record.route_plan?.version > 0 ? ` v${record.route_plan.version}` : ''}
                          </span>
                          {record.route_plan?.cutoff_date && (
                            <span>
                              <strong>14-Day Cutoff:</strong> {record.route_plan.cutoff_date}
                            </span>
                          )}
                        </div>
                      </div>

                      {!record.members || record.members.length === 0 ? (
                        <div style={{ fontSize: '12px', color: '#646970', padding: '8px 0' }}>
                          No members currently assigned to this {record.record_type === 'forming_cohort' ? 'cohort' : 'team'}.
                        </div>
                      ) : (
                        <div style={{ border: '1px solid #dcdcde', borderRadius: '4px', background: '#fff', overflowX: 'auto' }}>
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
                                const offerBadge = formatOfferStatusBadge(
                                  member.notification_status,
                                  isLicScoped
                                );
                                const trainingBadge = formatTrainingStatusBadge(
                                  member.training_status,
                                  isLicScoped
                                );
                                const memberKey = `${rowKey}-m-${member.expedition_signup_id || member.scout_id || mIdx}`;

                                return (
                                  <tr key={memberKey}>
                                    <td>
                                      <span style={{ fontWeight: 600, color: '#1d2327' }}>
                                        {member.name || 'Unnamed Explorer'}
                                      </span>
                                      {!isLicScoped && member.availability_override && (
                                        <div className="ems-table__meta">
                                          Override: {member.availability_override}
                                        </div>
                                      )}
                                    </td>
                                    <td>
                                      <span style={pillStyle(offerBadge)}>{offerBadge.label}</span>
                                    </td>
                                    <td>
                                      <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }}>
                                        <span
                                          style={pillStyle(
                                            member.first_aid_valid
                                              ? { bg: '#dcfce7', color: '#166534', border: '#86efac' }
                                              : { bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' }
                                          )}
                                        >
                                          <FontAwesomeIcon
                                            icon={member.first_aid_valid ? faCircleCheck : faCircleExclamation}
                                          />
                                          {member.first_aid_valid ? 'Valid' : 'Invalid / Missing'}
                                        </span>
                                        <span style={{ color: '#50575e' }}>
                                          {formatFirstAidLevel(member.first_aid_level)}
                                        </span>
                                        {member.first_aid_expiry && (
                                          <span style={{ color: '#646970' }}>
                                            (exp. {member.first_aid_expiry})
                                          </span>
                                        )}
                                      </div>
                                    </td>
                                    <td>
                                      <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
                                        <span style={pillStyle(trainingBadge)}>{trainingBadge.label}</span>
                                        {record.training_available &&
                                          member.training_status !== 'unlinked_oidc' &&
                                          member.training_status !== 'unavailable' && (
                                            <span style={{ color: '#50575e' }}>
                                              {member.completed_modules} / {member.total_modules} modules
                                            </span>
                                          )}
                                      </div>
                                    </td>
                                    <td>
                                      <span style={pillStyle(routeBadge)}>
                                        {routeBadge.label}
                                        {record.route_plan?.version > 0 ? ` (v${record.route_plan.version})` : ''}
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
