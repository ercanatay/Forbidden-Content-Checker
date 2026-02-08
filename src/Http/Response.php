<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http;

final class Response
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $key => $value) {
            header($key . ': ' . $value);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function html(string $html, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        foreach ($headers as $key => $value) {
            header($key . ': ' . $value);
        }

        echo $html;
    }

    public static function file(string $path, string $contentType, string $downloadName): void
    {
        if (!is_file($path)) {
            self::json([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'file_not_found',
                    'message' => 'File not found.',
                    'locale' => 'en-US',
                    'traceId' => '',
                    'details' => [],
                ],
                'meta' => [],
            ], 404);
            return;
        }

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function envelopeSuccess(array $data, array $meta = [], int $status = 200): void
    {
        self::json([
            'success' => true,
            'data' => $data,
            'error' => null,
            'meta' => $meta,
        ], $status);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function envelopeError(
        string $code,
        string $message,
        string $locale,
        string $traceId,
        array $details = [],
        int $status = 400,
        array $meta = []
    ): void {
        self::json([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
                'locale' => $locale,
                'traceId' => $traceId,
                'details' => $details,
            ],
            'meta' => $meta,
        ], $status);
    }
}
