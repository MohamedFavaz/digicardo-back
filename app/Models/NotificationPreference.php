<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'notification_preferences';

    protected $fillable = [
        'user_id',
        'email_enabled',
        'security_email_enabled',
        'marketing_email_enabled',
        'contact_email_enabled',
        'subscription_email_enabled',
        'domain_email_enabled',
        'in_app_enabled',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
        'security_email_enabled' => 'boolean',
        'marketing_email_enabled' => 'boolean',
        'contact_email_enabled' => 'boolean',
        'subscription_email_enabled' => 'boolean',
        'domain_email_enabled' => 'boolean',
        'in_app_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
