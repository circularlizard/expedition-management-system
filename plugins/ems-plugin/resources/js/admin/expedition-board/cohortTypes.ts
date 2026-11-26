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

export interface ConfirmedTeammateEdge {
  target_signup_id: number;
  preference_type?: 'prefer' | 'avoid';
  match_status?: 'auto_matched' | 'confirmed' | string;
  target_name?: string;
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
  confirmed_preferences?: ConfirmedTeammateEdge[];
  teammate_preferences?: ConfirmedTeammateEdge[];
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
  offer_status?: string;
  offer_query_message?: string | null;
  offer_responded_at?: string | null;
  practice_team_post_id_override?: number | null;
  qualifier_team_post_id_override?: number | null;
  practice_override_cohort_id?: number;
  practice_override_cohort_name?: string;
  qualifier_override_cohort_id?: number;
  qualifier_override_cohort_name?: string;
  is_stage_guest?: boolean;
  guest_stage?: 'practice' | 'qualifier';
  home_cohort_id?: number;
  home_cohort_name?: string;
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
  is_standalone_team?: boolean;
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

const VALID_FIRST_AID_VALUES = new Set([
  'first-aid-yes',
  'first_response',
  'first-response',
  'full_first_aid',
  'first_aider',
  'first_aid_plus',
]);

export function hasValidFirstAid(
  firstAidLevel?: string | null,
  firstAidStatus?: string | null,
  expiry?: string | null,
  onDate?: string | null
): boolean {
  const level = (firstAidLevel || '').trim().toLowerCase();
  const status = (firstAidStatus || '').trim().toLowerCase();
  const effective = level && level !== 'none' ? level : status;
  if (!VALID_FIRST_AID_VALUES.has(effective)) {
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

const WISHLIST_STOP_WORDS = new Set([
  'none',
  'n/a',
  'na',
  'no',
  'any',
  'anyone',
  'tbc',
  'tbd',
  'friends',
  'please',
  'with',
]);

/**
 * Splits a free-text teammate wishlist string into individual name tokens.
 */
export function parseWishlistTokens(raw: string): string[] {
  if (!raw || !raw.trim()) return [];
  return raw
    .split(/[,;\n/&]+|\band\b/gi)
    .map(part => part.replace(/^[-•*\s]+|[-•*\s.]+$/g, '').trim())
    .filter(part => part.length >= 2 && !WISHLIST_STOP_WORDS.has(part.toLowerCase()));
}

function levenshtein(a: string, b: string): number {
  const m = a.length;
  const n = b.length;
  if (m === 0) return n;
  if (n === 0) return m;
  const dp: number[] = Array.from({ length: n + 1 }, (_, j) => j);
  for (let i = 1; i <= m; i++) {
    let prev = dp[0];
    dp[0] = i;
    for (let j = 1; j <= n; j++) {
      const temp = dp[j];
      dp[j] = a[i - 1] === b[j - 1] ? prev : 1 + Math.min(prev, dp[j], dp[j - 1]);
      prev = temp;
    }
  }
  return dp[n];
}

function similarity(a: string, b: string): number {
  const maxLen = Math.max(a.length, b.length);
  if (maxLen === 0) return 1;
  return 1 - levenshtein(a, b) / maxLen;
}

export interface FuzzyExplorerMatch {
  id: number;
  name: string;
  score: number;
}

/**
 * Finds the best fuzzy-matched explorer in a list for a single wishlist name token.
 */
export function fuzzyMatchExplorer(
  token: string,
  candidates: Array<{ id: number; name: string }>,
  excludeId?: number
): FuzzyExplorerMatch | null {
  const q = token.trim().toLowerCase();
  if (q.length < 2) return null;

  const qParts = q.split(/\s+/).filter(p => p.length >= 2);
  let best: FuzzyExplorerMatch | null = null;

  for (const c of candidates) {
    if (excludeId !== undefined && c.id === excludeId) continue;
    const target = c.name.trim().toLowerCase();
    if (!target) continue;

    let score = 0;
    if (target === q) {
      score = 1;
    } else if (target.includes(q) || q.includes(target)) {
      score = 0.92;
    } else {
      const fullSim = similarity(q, target);
      const tParts = target.split(/\s+/).filter(Boolean);
      let partScore = 0;
      if (qParts.length >= 2 && tParts.length >= 2) {
        const firstSim = similarity(qParts[0], tParts[0]);
        const lastSim = similarity(qParts[qParts.length - 1], tParts[tParts.length - 1]);
        partScore = (firstSim + lastSim) / 2;
      } else if (qParts.length === 1) {
        const bestSingle = Math.max(...tParts.map(tp => similarity(qParts[0], tp)));
        partScore = bestSingle * 0.85;
      }
      score = Math.max(fullSim, partScore);
    }

    if (score >= 0.65 && (!best || score > best.score)) {
      best = { id: c.id, name: c.name, score };
    }
  }

  return best;
}
