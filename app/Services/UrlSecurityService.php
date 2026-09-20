<?php

namespace App\Services;

class UrlSecurityService
{
    private const PROHIBITED_SCHEMES = [
        'javascript:',
        'data:',
        'vbscript:',
        'file:',
        'about:',
    ];

    private const ALLOWED_BOOKING_HOSTS = [
        'calendly.com',
        'cal.com',
    ];

    /**
     * Validate whether a URL is secure (HTTP/HTTPS, no prohibited schemes or control characters).
     */
    public function isValidHttpUrl(?string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        $trimmed = trim($url);
        $lowered = strtolower($trimmed);

        foreach (self::PROHIBITED_SCHEMES as $prohibited) {
            if (str_starts_with($lowered, $prohibited) || str_contains($lowered, $prohibited)) {
                return false;
            }
        }

        if (!preg_match('/^https?:\/\/[^\s\/$.?#].[^\s]*$/i', $trimmed)) {
            return false;
        }

        $parsed = parse_url($trimmed);
        if (!$parsed || !isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }

        if (empty($parsed['host'])) {
            return false;
        }

        return true;
    }

    /**
     * Parse and extract video provider and video ID from YouTube or Vimeo URLs.
     *
     * @return array{provider: string, video_id: string, embed_url: string}|null
     */
    public function parseVideoUrl(string $url): ?array
    {
        if (!$this->isValidHttpUrl($url)) {
            return null;
        }

        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '';

        // YouTube handling
        if ($host === 'youtu.be') {
            $videoId = trim($path, '/');
            if (preg_match('/^[a-zA-Z0-9_-]{6,15}$/', $videoId)) {
                return [
                    'provider' => 'youtube',
                    'video_id' => $videoId,
                    'embed_url' => "https://www.youtube-nocookie.com/embed/{$videoId}",
                ];
            }
        } elseif (str_ends_with($host, 'youtube.com')) {
            $videoId = null;
            if (str_starts_with($path, '/watch')) {
                parse_str($parsed['query'] ?? '', $query);
                $videoId = $query['v'] ?? null;
            } elseif (str_starts_with($path, '/embed/') || str_starts_with($path, '/shorts/') || str_starts_with($path, '/v/')) {
                $segments = explode('/', trim($path, '/'));
                $videoId = $segments[1] ?? null;
            }

            if ($videoId && preg_match('/^[a-zA-Z0-9_-]{6,15}$/', $videoId)) {
                return [
                    'provider' => 'youtube',
                    'video_id' => $videoId,
                    'embed_url' => "https://www.youtube-nocookie.com/embed/{$videoId}",
                ];
            }
        }

        // Vimeo handling
        if ($host === 'vimeo.com' || str_ends_with($host, 'vimeo.com')) {
            if (preg_match('/(?:vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/[^\/]*\/videos\/|album\/(?:\d+\/)?video\/|video\/|)(\d+))/', $url, $matches)) {
                $videoId = $matches[1];
                return [
                    'provider' => 'vimeo',
                    'video_id' => $videoId,
                    'embed_url' => "https://player.vimeo.com/video/{$videoId}",
                ];
            }
        }

        return null;
    }

    /**
     * Parse and extract music resource details from Spotify, Apple Music, or SoundCloud.
     *
     * @return array{provider: string, resource_type: ?string, resource_id: ?string, embed_url: string}|null
     */
    public function parseMusicUrl(string $url): ?array
    {
        if (!$this->isValidHttpUrl($url)) {
            return null;
        }

        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '';

        // Spotify
        if ($host === 'open.spotify.com' || str_ends_with($host, 'spotify.com')) {
            if (preg_match('/^\/(track|album|playlist|artist|episode|show)\/([a-zA-Z0-9]+)/', $path, $matches)) {
                $type = $matches[1];
                $id = $matches[2];
                return [
                    'provider' => 'spotify',
                    'resource_type' => $type,
                    'resource_id' => $id,
                    'embed_url' => "https://open.spotify.com/embed/{$type}/{$id}",
                ];
            }
        }

        // Apple Music
        if ($host === 'music.apple.com' || str_ends_with($host, 'music.apple.com')) {
            $embedUrl = preg_replace('/^https?:\/\/music\.apple\.com/', 'https://embed.music.apple.com', $url);
            return [
                'provider' => 'apple_music',
                'resource_type' => null,
                'resource_id' => null,
                'embed_url' => $embedUrl,
            ];
        }

        // SoundCloud
        if ($host === 'soundcloud.com' || str_ends_with($host, 'soundcloud.com')) {
            $encoded = urlencode($url);
            return [
                'provider' => 'soundcloud',
                'resource_type' => null,
                'resource_id' => null,
                'embed_url' => "https://w.soundcloud.com/player/?url={$encoded}&color=%23ff5500&auto_play=false&hide_related=true&show_comments=false&show_user=true&show_reposts=false&show_teaser=false",
            ];
        }

        return null;
    }

    /**
     * Validate booking provider hostname.
     */
    public function validateBookingUrl(string $url): ?string
    {
        if (!$this->isValidHttpUrl($url)) {
            return null;
        }

        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');

        foreach (self::ALLOWED_BOOKING_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return $allowed;
            }
        }

        return null;
    }

    /**
     * Normalize phone number to standard E.164 compatible format.
     */
    public function normalizePhoneNumber(string $phone): ?string
    {
        $sanitized = preg_replace('/[^\d+]/', '', $phone);
        if (empty($sanitized) || strlen($sanitized) < 7 || strlen($sanitized) > 17) {
            return null;
        }

        return $sanitized;
    }
}
