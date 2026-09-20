<?php

namespace App\Services\Email;

use App\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Uid\Ulid;

class LaravelMailProvider implements EmailProviderInterface
{
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        array $headers = []
    ): string {
        $messageId = 'msg_' . (string) Ulid::generate();

        Mail::send([], [], function ($message) use ($to, $subject, $htmlBody, $textBody, $headers) {
            $message->to($to)
                ->subject($subject)
                ->html($htmlBody);

            if ($textBody) {
                $message->text($textBody);
            }

            foreach ($headers as $key => $value) {
                $message->getHeaders()->addTextHeader($key, $value);
            }
        });

        Log::info('[LaravelMailProvider] Email dispatched via Laravel Mail', [
            'to' => $to,
            'subject' => $subject,
            'message_id' => $messageId,
        ]);

        return $messageId;
    }

    public function sendTemplate(string $to, string $template, array $data = []): string
    {
        $subject = $data['subject'] ?? 'Digicardo Notification';
        $messageId = 'tmpl_' . (string) Ulid::generate();

        Mail::send($template, $data, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });

        return $messageId;
    }

    public function supports(string $driver): bool
    {
        return true;
    }
}
