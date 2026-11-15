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
