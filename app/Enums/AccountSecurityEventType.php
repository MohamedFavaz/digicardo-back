<?php

namespace App\Enums;

enum AccountSecurityEventType: string
{
    case LoginSuccess = 'login_success';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case EmailVerified = 'email_verified';
    case EmailChanged = 'email_changed';
    case SessionRevoked = 'session_revoked';
    case AllOtherSessionsRevoked = 'all_other_sessions_revoked';
    case AccountDetailsUpdated = 'account_details_updated';
    case AccountDeletionRequested = 'account_deletion_requested';
    case AccountDeleted = 'account_deleted';
    case RecentAuthConfirmed = 'recent_auth_confirmed';

    /**
     * User-friendly human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::LoginSuccess => 'Successful Login',
            self::LoginFailed => 'Failed Login Attempt',
            self::Logout => 'Logged Out',
            self::PasswordChanged => 'Password Changed',
            self::PasswordReset => 'Password Reset',
            self::EmailVerified => 'Email Verified',
            self::EmailChanged => 'Email Changed',
            self::SessionRevoked => 'Session Revoked',
            self::AllOtherSessionsRevoked => 'All Other Sessions Revoked',
            self::AccountDetailsUpdated => 'Account Details Updated',
            self::AccountDeletionRequested => 'Account Deletion Requested',
            self::AccountDeleted => 'Account Deleted',
            self::RecentAuthConfirmed => 'Security Confirmation Verified',
        };
    }
}
