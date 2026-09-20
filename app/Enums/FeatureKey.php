<?php

namespace App\Enums;

enum FeatureKey: string
{
    case ProfileCount = 'profile_count';
    case CustomDomainCount = 'custom_domain_count';
    case AdvancedTemplates = 'advanced_templates';
    case AnalyticsHistoryDays = 'analytics_history_days';
    case ImageUploads = 'image_uploads';
    case GalleryBlocks = 'gallery_blocks';
    case VideoBlocks = 'video_blocks';
    case MusicBlocks = 'music_blocks';
    case BookingBlocks = 'booking_blocks';
    case ContactForms = 'contact_forms';
    case AdvancedAnalytics = 'advanced_analytics';
    case RemoveBranding = 'remove_branding';

    /**
     * Determine if feature represents a numeric limit vs boolean permission.
     */
    public function isNumeric(): bool
    {
        return in_array($this, [
            self::ProfileCount,
            self::CustomDomainCount,
            self::AnalyticsHistoryDays,
        ]);
    }
}
