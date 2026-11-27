export interface RouteContextDTO {
  expeditionId: number;
  expeditionTitle?: string;
  teamId?: number;
  teamName?: string;
  assignedSupervisors?: string[];
  assignedAssessors?: string[];
}

export interface RouteCardSubmissionDTO {
  submissionId: number;
  expeditionId: number;
  teamId: number;
  routeData: Record<string, unknown>;
  status: ApprovalStatus;
  submittedAt?: string;
}

export type ApprovalStatus = 'draft' | 'submitted' | 'approved' | 'rejected' | 'changes_requested';

export type NotificationStatus = 'draft' | 'notified' | 'accepted' | 'declined';

export type MemberResolutionAction = 'assign_pending' | 'split_date' | 'remove_from_cohort';

export interface DateIntersectionSummary {
  expeditionId: number;
  eventCode?: string;
  title?: string;
  startDate?: string;
  endDate?: string;
  totalMembers: number;
  availableCount: number;
  unavailableCount: number;
  unavailableMemberIds: number[];
  isFullMatch: boolean;
  teamSizeValid: boolean;
  firstAidQualifiedCount: number;
  ruleOfTwoMet: boolean;
}

export interface CohortMemberDTO {
  id: number;
  cohortId: number;
  expeditionSignupId: number;
  scoutId: number;
  firstName: string;
  lastName: string;
  unitName?: string;
  district?: string;
  firstAidLevel?: string;
  firstAidValidUntil?: string | null;
  hasAsn?: boolean;
  availableEventCodes?: string[];
  practiceStatus?: NotificationStatus;
  qualifierStatus?: NotificationStatus;
  resolutionAction?: MemberResolutionAction | null;
  createdAt?: string;
  updatedAt?: string;
}

export interface CohortDTO {
  id: number;
  name: string;
  dofeLevel: string;
  transportMode: string;
  practiceExpeditionId: number | null;
  qualifierExpeditionId: number | null;
  practiceTeamPostId: number | null;
  qualifierTeamPostId: number | null;
  cohortStatus: string;
  notes?: string | null;
  createdBy: number;
  createdAt: string;
  updatedAt: string;
  members?: CohortMemberDTO[];
  practiceDateIntersection?: DateIntersectionSummary | null;
  qualifierDateIntersection?: DateIntersectionSummary | null;
}

export interface NotificationPreviewRecipientDTO {
  expeditionSignupId: number;
  scoutId: number;
  name: string;
  email?: string;
  currentStatus: NotificationStatus;
}

export interface NotificationPreviewDTO {
  cohortId: number;
  expeditionId: number;
  stage: 'practice' | 'qualifier';
  teamPostId?: number | null;
  recipientCount: number;
  recipients: NotificationPreviewRecipientDTO[];
  subject: string;
  bodyPreview: string;
  canSend: boolean;
  warnings?: string[];
}

export interface TeamReadinessRowDTO {
  teamId: number;
  teamName: string;
  teamCode: string;
  cohortId?: number | null;
  expeditionId: number;
  eventCode: string;
  eventTitle: string;
  stage: 'practice' | 'qualifier' | 'training';
  dofeLevel: string;
  transportMode: string;
  startDate?: string;
  endDate?: string;
  memberCount: number;
  teamSizeValid: boolean;
  offerCounts: Record<NotificationStatus, number>;
  firstAidQualifiedCount: number;
  ruleOfTwoMet: boolean;
  earliestFirstAidExpiry?: string | null;
  trainingCompletionPercent: number;
  routeCardStatus: ApprovalStatus | 'not_started';
  routeCardSubmissionId?: number | null;
}

export type TeammatePreferenceType = 'prefer' | 'avoid';

export type TeammateMatchStatus =
  | 'auto_matched'
  | 'confirmed'
  | 'ambiguous'
  | 'cross_bucket'
  | 'unit_preference'
  | 'not_found'
  | 'ignored';

export type ExplorerGender = 'male' | 'female' | 'other' | '';

export interface PreferenceCandidateDTO {
  signup_id: number;
  explorer_name: string;
  unit_name: string;
  dofe_level: string;
  transport_mode: string;
  shared_code_count: number;
  gender?: ExplorerGender;
  score?: number;
  reason?: string;
}

export interface PreferenceTokenDTO {
  id: number;
  source_signup_id: number;
  target_signup_id: number | null;
  wishlist_hash: string;
  raw_token: string;
  unit_hint: string | null;
  preference_type: TeammatePreferenceType;
  match_status: TeammateMatchStatus;
  confidence_score: number;
  flag_negation: boolean;
  candidates: PreferenceCandidateDTO[];
  resolved_target_name?: string | null;
  resolved_target_unit?: string | null;
}

export interface PreferenceQueueItemDTO {
  source_signup_id: number;
  explorer_name: string;
  unit_name: string;
  dofe_level: string;
  transport_mode: string;
  gender?: ExplorerGender;
  wishlist_raw: string;
  wishlist_hash: string;
  tokens: PreferenceTokenDTO[];
}

export interface PreferenceQueueResponseDTO {
  items: PreferenceQueueItemDTO[];
  summary: {
    total: number;
    needs_review: number;
    auto_matched: number;
    confirmed: number;
    ignored: number;
    bucket_signups_count?: number;
    full_season_signups_count?: number;
  };
}

export interface PreferenceDecisionItemDTO {
  id?: number;
  target_signup_id: number | null;
  preference_type: TeammatePreferenceType;
  match_status: 'confirmed' | 'ignored';
  raw_token?: string;
}

export interface SolverWeightsDTO {
  avoid_exclusion: number;
  cross_stage_continuity: number;
  code_availability: number;
  mutual_wishlist: number;
  one_way_wishlist: number;
  solo_gender_penalty: number;
  same_unit: number;
  first_aid_presence: number;
}

export interface ProposedTeamMemberDTO {
  expedition_signup_id: number;
  scout_id: number;
  name: string;
  unit_name: string;
  gender?: ExplorerGender;
  has_first_aid: boolean;
  practice_available: boolean;
  qualifier_available: boolean;
  resolution_status: 'ok' | 'date_conflict_pending' | 'split_date';
}

export interface ProposedTeamDTO {
  temp_id: string;
  team_name: string;
  dofe_level: string;
  transport_mode: string;
  practice_expedition_id: number | null;
  practice_code: string;
  qualifier_expedition_id: number | null;
  qualifier_code: string;
  members: ProposedTeamMemberDTO[];
  friend_match_count: number;
  has_first_aid: boolean;
  score: number;
}

export interface ExpeditionCapacitySummaryDTO {
  expedition_id: number;
  event_code: string;
  title: string;
  stage: 'practice' | 'qualifier';
  locked_teams: number;
  proposed_teams: number;
  total_teams: number;
  max_teams: number | null;
}

export interface SolverCompromisesDTO {
  date_conflict_pending: Array<{
    expedition_signup_id: number;
    explorer_name: string;
    team_name: string;
    stage: 'practice' | 'qualifier';
    event_code: string;
  }>;
  split_date: Array<{
    expedition_signup_id: number;
    explorer_name: string;
    practice_team: string;
    qualifier_team: string;
  }>;
  unfulfilled_mutual_pairs: Array<{
    source_signup_id: number;
    source_name: string;
    target_signup_id: number;
    target_name: string;
  }>;
  solo_gender_teams?: Array<{
    team_name: string;
    stage: string;
    solo_gender: 'male' | 'female';
    explorer_name: string;
  }>;
}

export interface SolverProposalDTO {
  scope: 'bucket' | 'full_season';
  buckets_solved: Array<{ dofe_level: string; transport_mode: string }>;
  proposed_teams: ProposedTeamDTO[];
  expedition_capacity: ExpeditionCapacitySummaryDTO[];
  compromises: SolverCompromisesDTO;
  warnings: string[];
  stats: {
    total_explorers: number;
    total_teams: number;
    mutual_pairs_fulfilled: number;
    mutual_pairs_total: number;
    first_aid_teams_covered: number;
  };
}

export interface SolverApplyRequestDTO {
  proposed_teams: ProposedTeamDTO[];
  replace_existing_drafts: boolean;
  buckets: Array<{ dofe_level: string; transport_mode: string }>;
}

export interface SolverApplyResponseDTO {
  created_teams: number;
  created_members: number;
  cleared_draft_teams: number;
  preserved_notified_teams: number;
}
