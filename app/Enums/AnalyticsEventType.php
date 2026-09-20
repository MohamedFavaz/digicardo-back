<?php

namespace App\Enums;

enum AnalyticsEventType: string
{
    case ProfileView = 'profile_view';
    case ProfileUniqueView = 'profile_unique_view';
    case LinkClick = 'link_click';
    case SocialClick = 'social_click';
    case CtaClick = 'cta_click';
    case EmailClick = 'email_click';
    case PhoneClick = 'phone_click';
    case WhatsAppClick = 'whatsapp_click';
    case BookingClick = 'booking_click';
    case ContactSubmit = 'contact_submit';
    case ImageClick = 'image_click';
    case VideoPlay = 'video_play';
    case GalleryOpen = 'gallery_open';
    case FaqOpen = 'faq_open';
    case CountdownComplete = 'countdown_complete';

    /**
     * Get all valid event values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Determine whether an event type is a click/interaction event.
     */
    public function isClickEvent(): bool
    {
        return in_array($this, [
            self::LinkClick,
            self::SocialClick,
            self::CtaClick,
            self::EmailClick,
            self::PhoneClick,
            self::WhatsAppClick,
            self::BookingClick,
            self::ImageClick,
        ], true);
    }
}
