<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Scan;

final class BulkImportService
{
    private const MAX_URLS = 1000;

    public function __construct(
        private readonly UrlNormalizer $urlNormalizer
    ) {
    }

    /**
     * Parse URLs from raw text content (CSV or line-separated).
     *
     * @return array{urls: array<int, string>, skipped: int, errors: array<int, string>}
     */
    public function parseUrls(string $content, string $format = 'auto'): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['urls' => [], 'skipped' => 0, 'errors' => ['Empty content provided.']];
        }

        if ($format === 'auto') {
            $format = $this->detectFormat($content);
        }

        $rawLines = match ($format) {
            'csv' => $this->parseCsv($content),
            default => $this->parseLines($content),
        };

        $urls = [];
        $skipped = 0;
        $errors = [];
        $seen = [];

        foreach ($rawLines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $normalized = $this->urlNormalizer->normalizeInput($line);
            if ($normalized === null) {
                $skipped++;
                continue;
            }

            if (isset($seen[$normalized])) {
                $skipped++;
                continue;
            }

            $seen[$normalized] = true;
            $urls[] = $normalized;

            if (count($urls) >= self::MAX_URLS) {
                $errors[] = 'Maximum URL limit (' . self::MAX_URLS . ') reached. Remaining entries skipped.';
                break;
            }
        }

        return ['urls' => $urls, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function detectFormat(string $content): string
    {
        $firstLine = strtok($content, "\n") ?: '';
        if (str_contains($firstLine, ',') && !str_contains($firstLine, '://')) {
            return 'csv';
        }
        return 'text';
    }

    /**
     * @return array<int, string>
     */
    private function parseCsv(string $content): array
    {
        $urls = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $fields = str_getcsv($line);
            foreach ($fields as $field) {
                $field = trim((string) $field);
                if ($field !== '') {
                    $urls[] = $field;
                }
            }
        }

        return $urls;
    }

    /**
     * @return array<int, string>
     */
    private function parseLines(string $content): array
    {
        return preg_split('/\r\n|\n|\r/', $content) ?: [];
    }
}
