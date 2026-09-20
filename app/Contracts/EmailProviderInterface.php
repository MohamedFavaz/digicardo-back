<?php

namespace App\Contracts;

interface EmailProviderInterface
{
    /**
     * Send a raw HTML and/or plain-text email message.
     *
     * @param string $to
     * @param string $subject
     * @param string $htmlBody
     * @param string|null $textBody
     * @param array<string, string> $headers
     * @return string Provider message ID
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $textBody = null,
        array $headers = []
    ): string;

    /**
     * Send a templated email message.
     *
     * @param string $to
     * @param string $template
     * @param array<string, mixed> $data
     * @return string Provider message ID
     */
    public function sendTemplate(string $to, string $template, array $data = []): string;

    /**
     * Check if this provider supports the configured driver.
     */
    public function supports(string $driver): bool;
}
