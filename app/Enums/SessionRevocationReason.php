<?php

namespace App\Enums;

enum SessionRevocationReason: string
{
    case UserRevoked = 'user_revoked';
    case RevokeAllOthers = 'revoke_all_others';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case AccountDeleted = 'account_deleted';
    case AccountSuspended = 'account_suspended';
    case AdminAction = 'admin_action';
    case Expired = 'expired';
}
