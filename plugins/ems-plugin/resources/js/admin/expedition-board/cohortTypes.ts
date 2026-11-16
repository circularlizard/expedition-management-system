export interface MatrixTeam {
  id: number;
  team_code: string;
  member_count: number;
  scout_ids: number[];
}

export interface MatrixEvent {
  id: number;
  event_code: string;
  title: string;
  level: string;
  type: string;
  transport: string;
  start_date: string;
  end_date: string;
  pool_count?: number;
  member_count?: number;
  teams: MatrixTeam[];
}

export interface MatrixExplorer {
  expedition_signup_id: number;
  scout_id: number;
  first_name: string;
  last_name: string;
  unit_name: string;
  unit_id?: number | null;
  district?: string;
  team_preferences: string;
  available_events: string[];
  allocated_event_code: string | null;
  allocated_team_code: string | null;
  allocated_team_id: number | null;
  has_asn: boolean;
  additional_support_needs: string;
  first_aid_status: string;
  first_aid_expiry?: string | null;
  asn_validated?: number | null;
}

export type MemberResolutionAction = 'assign_pending' | 'split_date' | 'remove_from_cohort';

export interface CohortMember {
  id: number;
  cohort_id: number;
  expedition_signup_id: number;
  scout_id: number;
  availability_override: string | null;
  member_status: string;
  notification_status?: string;
  notes: string | null;
  unit_id: number | null;
  unit_name: string | null;
  first_name: string;
  last_name: string;
  explorer_first_name: string;
  explorer_last_name: string;
  explorer_email: string;
  parent_email: string | null;
  dofe_level: string;
  expedition_preferences: string | null;
  additional_support_needs: string | null;
  asn_validated: number | null;
  first_aid_status: string;
  first_aid_expiry: string | null;
  first_aid_level: string | null;
  signup_status: string;
}

export interface Cohort {
  id: number;
  name: string;
  dofe_level: string;
  transport_mode: string;
  practice_expedition_id: number | null;
  qualifier_expedition_id: number | null;
  practice_team_post_id: number | null;
  qualifier_team_post_id: number | null;
  cohort_status: string;
  notes: string | null;
  created_by: number;
  created_at: string;
  updated_at: string;
  members: CohortMember[];
  member_count: number;
}

export interface ScheduleUnavailableMember {
  expedition_signup_id: number;
  scout_id: number;
  name: string;
}

export interface ScheduleCandidate {
  expedition_id: number;
  expedition_code: string;
  title: string;
  expedition_date: string;
  stage: 'practice' | 'qualifier';
  available_count: number;
  total_count: number;
  unavailable_members: ScheduleUnavailableMember[];
  first_aid_count: number;
  asn_count: number;
  size_valid: boolean;
}

export interface NotifyModalOptions {
  scope: 'cohort' | 'all';
  cohortId?: number;
  cohortName?: string;
  level?: string;
  transport?: string;
}

export interface CohortPlanningMatrixProps {
  onViewAsn?: (scoutId: number) => void;
  onOpenNotifyModal?: (opts: NotifyModalOptions) => void;
}

export function toCohortTransportMode(transport: string): string {
  const norm = transport.trim().toLowerCase();
  return norm === 'hillwalking' ? 'foot' : norm;
}

export function formatDateShort(dateStr?: string | null): string {
  if (!dateStr) return '';
  try {
    const parts = dateStr.split('-');
    if (parts.length === 3) {
      const day = parseInt(parts[2], 10);
      const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      const month = months[parseInt(parts[1], 10) - 1] || '';
      return `${day} ${month}`;
    }
    return dateStr;
  } catch {
    return dateStr;
  }
}

export function hasValidFirstAid(
  firstAidLevel?: string | null,
  firstAidStatus?: string | null,
  expiry?: string | null,
  onDate?: string | null
): boolean {
  const level = (firstAidLevel || '').trim().toLowerCase();
  const status = (firstAidStatus || '').trim().toLowerCase();
  const effective = level && level !== 'none' ? level : status;
  if (!effective || effective === 'none' || effective === 'no') {
    return false;
  }
  if (expiry && onDate) {
    return expiry >= onDate;
  }
  return true;
}

/**
 * Checks if two explorers have a wishlist link (A mentions B or B mentions A).
 */
export function isWishlistMatch(
  aFirstName: string,
  aLastName: string,
  aWishlist: string,
  bFirstName: string,
  bLastName: string,
  bWishlist: string
): boolean {
  const aFull = `${aFirstName} ${aLastName}`.trim().toLowerCase();
  const bFull = `${bFirstName} ${bLastName}`.trim().toLowerCase();
  const aW = (aWishlist || '').toLowerCase();
  const bW = (bWishlist || '').toLowerCase();

  const matchesName = (wishlist: string, first: string, last: string, full: string): boolean => {
    if (!wishlist || !full) return false;
    if (wishlist.includes(full)) return true;
    const f = first.trim().toLowerCase();
    const l = last.trim().toLowerCase();
    // Match both first & last name tokens if both are >= 3 chars
    if (f.length >= 3 && l.length >= 3 && wishlist.includes(f) && wishlist.includes(l)) {
      return true;
    }
    return false;
  };

  return (
    matchesName(aW, bFirstName, bLastName, bFull) ||
    matchesName(bW, aFirstName, aLastName, aFull)
  );
}
