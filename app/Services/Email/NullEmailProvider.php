<?php

namespace App\Services\Email;

use App\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\Ulid;

class NullEmailProvider implements EmailProviderInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected static array $sentMessages = [];

    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        array $headers = []
    ): string {
        $messageId = 'null_' . (string) Ulid::generate();

        self::$sentMessages[] = [
            'id' => $messageId,
            'to' => $to,
            'subject' => $subject,
            'html' => $htmlBody,
            'text' => $textBody,
            'headers' => $headers,
            'sent_at' => now()->toIso8601String(),
        ];

        Log::info('[NullEmailProvider] Email simulated', [
            'message_id' => $messageId,
            'to' => $to,
            'subject' => $subject,
        ]);

        return $messageId;
    }

    public function sendTemplate(string $to, string $template, array $data = []): string
    {
        $subject = $data['subject'] ?? 'Digicardo Notification';
        $body = "Template: {$template}\n" . json_encode($data);

        return $this->send($to, $subject, "<pre>{$body}</pre>", $body);
    }

    public function supports(string $driver): bool
    {
        return in_array(strtolower($driver), ['null', 'array', 'log', 'testing', 'sync'], true);
    }

    /**
     * Retrieve all simulated sent messages (for testing assertions).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getSentMessages(): array
    {
        return self::$sentMessages;
    }

    /**
     * Clear recorded sent messages.
     */
    public static function clearSentMessages(): void
    {
        self::$sentMessages = [];
    }
}
