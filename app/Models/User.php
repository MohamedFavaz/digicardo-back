<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Traits\HasUlid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUlid, Notifiable, SoftDeletes;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'email_verified_at',
        'expires_at',
        'validity_months',
        'validity_label',
        'plan_price_paid',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'expires_at'        => 'datetime',
            'password'          => 'hashed',
            'role'              => UserRole::class,
            'status'            => UserStatus::class,
            'deleted_at'        => 'datetime',
            'validity_months'   => 'integer',
            'plan_price_paid'   => 'float',
        ];
    }

    /**
     * Get the profile associated with the user.
     */
    public function profile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * Get all domains owned by the user.
     */
    public function domains(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProfileDomain::class);
    }

    /**
     * Subscriptions for this user.
     */
    public function subscriptions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the currently active subscription, if any.
     */
    public function activeSubscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', ['active', 'trialing'])
            ->latestOfMany('created_at');
    }

    /**
     * User notification preferences.
     */
    public function notificationPreferences(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    /**
     * In-app notifications for this user.
     */
    public function notifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Email delivery tracking records for this user.
     */
    public function emailDeliveries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailDelivery::class);
    }

    /**
     * Account sessions for this user.
     */
    public function accountSessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AccountSession::class);
    }

    /**
     * Active (non-revoked) account sessions for this user.
     */
    public function activeAccountSessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AccountSession::class)->whereNull('revoked_at');
    }

    /**
     * Security audit events for this user.
     */
    public function securityEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AccountSecurityEvent::class);
    }

    /**
     * Moderation actions performed by this user (if admin/moderator).
     */
    public function moderationActions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ModerationAction::class, 'actor_id');
    }

    /**
     * Administrative audit logs performed by this user.
     */
    public function adminAuditLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AdminAuditLog::class, 'actor_id');
    }
}
