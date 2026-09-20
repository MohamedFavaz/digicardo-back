<?php

namespace App\Enums;

enum ModerationActionType: string
{
    case UserSuspended = 'user_suspended';
    case UserReactivated = 'user_reactivated';
    case UserRoleChanged = 'user_role_changed';
    case ProfileUnderReview = 'profile_under_review';
    case ProfileRestricted = 'profile_restricted';
    case ProfileSuspended = 'profile_suspended';
    case ProfileRestored = 'profile_restored';
    case ReportInvestigating = 'report_investigating';
    case ReportResolved = 'report_resolved';
    case ReportDismissed = 'report_dismissed';
}
