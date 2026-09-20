<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;

class NotificationPreferenceService
{
    /**
     * Get or initialize default notification preferences for a user.
     */
    public function getPreferences(User $user): NotificationPreference
    {
        return NotificationPreference::firstOrCreate(
            ['user_id' => $user->id],
            [
                'email_enabled' => true,
                'security_email_enabled' => true,
                'marketing_email_enabled' => false,
                'contact_email_enabled' => true,
                'subscription_email_enabled' => true,
                'domain_email_enabled' => true,
                'in_app_enabled' => true,
            ]
        );
    }

    /**
     * Update user notification preferences enforcing security constraints.
     *
     * @param User $user
     * @param array<string, mixed> $data
     * @return NotificationPreference
     */
    public function updatePreferences(User $user, array $data): NotificationPreference
    {
        $prefs = $this->getPreferences($user);

        $allowedFields = [
            'email_enabled',
            'marketing_email_enabled',
            'contact_email_enabled',
            'subscription_email_enabled',
            'domain_email_enabled',
            'in_app_enabled',
        ];

        $updates = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = (bool) $data[$field];
            }
        }

        // Security emails must NEVER be disabled
        $updates['security_email_enabled'] = true;

        $prefs->update($updates);

        return $prefs->fresh();
    }
}
