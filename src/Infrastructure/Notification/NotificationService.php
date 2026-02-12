<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Notification;

use ForbiddenChecker\Infrastructure\Logging\Logger;
use PDO;

final class NotificationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly string $defaultWebhookUrl,
        private readonly bool $emailEnabled,
        private readonly string $emailFrom
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function notifyScanCompleted(int $scanJobId, array $payload): void
    {
        $this->sendWebhook($scanJobId, $payload);
        $this->sendEmailDigest($scanJobId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendWebhook(int $scanJobId, array $payload): void
    {
        $urls = [];

        if ($this->defaultWebhookUrl !== '') {
            $urls[] = $this->defaultWebhookUrl;
        }

        $stmt = $this->pdo->query("SELECT url FROM webhooks WHERE is_active = 1");
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        $urls = array_values(array_unique($urls));
        foreach ($urls as $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'event' => 'scan.completed',
                    'scanJobId' => $scanJobId,
                    'payload' => $payload,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            $ins = $this->pdo->prepare(
                'INSERT INTO notifications (scan_job_id, channel, destination, status, response_payload, created_at)
                 VALUES (:scan_job_id, :channel, :destination, :status, :response_payload, datetime(\'now\'))'
            );
            $ins->execute([
                ':scan_job_id' => $scanJobId,
                ':channel' => 'webhook',
                ':destination' => $url,
                ':status' => $httpCode >= 200 && $httpCode < 300 ? 'sent' : 'failed',
                ':response_payload' => json_encode(['httpCode' => $httpCode, 'error' => $err, 'response' => $response], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendEmailDigest(int $scanJobId, array $payload): void
    {
        if (!$this->emailEnabled) {
            return;
        }

        $stmt = $this->pdo->query("SELECT email FROM email_subscriptions WHERE is_active = 1");
        $subscribers = $stmt->fetchAll();
        if (!$subscribers) {
            return;
        }

        $subject = 'Cybokron Forbidden Content Checker - Scan Completed #' . $scanJobId;
        $body = "Scan #{$scanJobId} completed.\n\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subscribers as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $headers = [
                'From: ' . $this->emailFrom,
                'Content-Type: text/plain; charset=UTF-8',
            ];

            $sent = @mail($email, $subject, $body, implode("\r\n", $headers));
            $ins = $this->pdo->prepare(
                'INSERT INTO notifications (scan_job_id, channel, destination, status, response_payload, created_at)
                 VALUES (:scan_job_id, :channel, :destination, :status, :response_payload, datetime(\'now\'))'
            );
            $ins->execute([
                ':scan_job_id' => $scanJobId,
                ':channel' => 'email',
                ':destination' => $email,
                ':status' => $sent ? 'sent' : 'failed',
                ':response_payload' => null,
            ]);
        }
    }
}
