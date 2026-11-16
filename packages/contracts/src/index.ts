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
