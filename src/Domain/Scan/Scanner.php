<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Scan;

use ForbiddenChecker\Infrastructure\Logging\Logger;
use ForbiddenChecker\Infrastructure\Security\SsrfGuard;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class Scanner
{
    public function __construct(
        private readonly UrlNormalizer $urlNormalizer,
        private readonly ResultScorer $resultScorer,
        private readonly SuppressionService $suppressionService,
        private readonly SsrfGuard $ssrfGuard,
        private readonly Logger $logger,
        private readonly int $timeout,
        private readonly int $maxRetries,
        private readonly int $maxPages,
        private readonly int $maxResultsPerKeyword
    ) {
    }

    public function clearRuntimeCaches(): void
    {
        $this->suppressionService->clearCache();
    }

    /**
     * @param array<int, string> $keywords
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function scanTarget(string $target, array $keywords, array $options = []): array
    {
        $normalized = $this->urlNormalizer->normalizeInput($target);
        if ($normalized === null) {
            return [
                'status' => 'failed',
                'target' => $target,
                'base_url' => null,
                'matches' => [],
                'errors' => [['code' => 'invalid_target', 'message' => 'Invalid target URL or domain.']],
                'fetch_details' => [],
            ];
        }

        $baseUrl = $this->urlNormalizer->baseUrl($normalized);
        if ($baseUrl === null) {
            return [
                'status' => 'failed',
                'target' => $target,
                'base_url' => null,
                'matches' => [],
                'errors' => [['code' => 'invalid_base_url', 'message' => 'Unable to determine base URL.']],
                'fetch_details' => [],
            ];
        }

        $regexMode = (($options['keyword_mode'] ?? 'exact') === 'regex');
        $exactMatch = (bool) ($options['exact_match'] ?? false);

        $allMatches = [];
        $allErrors = [];
        $fetchDetails = [];

        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }

            $keywordMatches = [];
            $searchUrl = $baseUrl . '/?s=' . rawurlencode($keyword);
            $wpResults = $this->crawlSearchPages($searchUrl, $baseUrl, $keyword, $regexMode, $exactMatch, 'wordpress_search', $fetchDetails, $allErrors);
            $keywordMatches = array_merge($keywordMatches, $wpResults);

            if (count($keywordMatches) === 0) {
                $restUrl = $baseUrl . '/wp-json/wp/v2/search?search=' . rawurlencode($keyword) . '&per_page=' . $this->maxResultsPerKeyword;
                $restMatches = $this->scanWpRest($restUrl, $keyword, $regexMode, $exactMatch, $fetchDetails, $allErrors);
                $keywordMatches = array_merge($keywordMatches, $restMatches);
            }

            if (count($keywordMatches) === 0) {
                $homeFallback = $this->scanGeneric($baseUrl, $keyword, $regexMode, $exactMatch, $fetchDetails, $allErrors);
                $keywordMatches = array_merge($keywordMatches, $homeFallback);
            }

            foreach ($keywordMatches as $match) {
                $signature = strtolower((string) $match['url']) . '|' . strtolower($keyword);
                $allMatches[$signature] = $match;
            }
        }

        $matches = array_values($allMatches);
        $suppressedCount = 0;
        foreach ($matches as &$match) {
            $suppressed = $this->suppressionService->isSuppressed((string) $match['title'], (string) $match['url'], (string) parse_url($baseUrl, PHP_URL_HOST));
            if ($suppressed) {
                $suppressedCount++;
            }
            $match['suppressed'] = $suppressed;
        }
        unset($match);

        $effectiveMatches = array_values(array_filter($matches, static fn (array $m): bool => ($m['suppressed'] ?? false) !== true));

        $status = 'completed';
        if (count($allErrors) > 0 && count($effectiveMatches) > 0) {
            $status = 'partial';
        } elseif (count($allErrors) > 0 && count($effectiveMatches) === 0) {
            $status = 'failed';
        }

        return [
            'status' => $status,
            'target' => $target,
            'base_url' => $baseUrl,
            'matches' => $effectiveMatches,
            'suppressed_matches' => $suppressedCount,
            'errors' => $allErrors,
            'fetch_details' => $fetchDetails,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fetchDetails
     * @param array<int, array<string, string>> $allErrors
     * @return array<int, array<string, mixed>>
     */
    private function crawlSearchPages(
        string $searchUrl,
        string $baseUrl,
        string $keyword,
        bool $regexMode,
        bool $exactMatch,
        string $source,
        array &$fetchDetails,
        array &$allErrors
    ): array {
        $results = [];
        $visited = [];
        $nextUrl = $searchUrl;
        $pageCount = 0;

        while ($nextUrl !== null && $pageCount < $this->maxPages) {
            if (isset($visited[$nextUrl])) {
                break;
            }
            $visited[$nextUrl] = true;

            $response = $this->fetchWithRetry($nextUrl);
            $fetchDetails[] = [
                'url' => $nextUrl,
                'status' => $response['status_code'],
                'content_type' => $response['content_type'],
                'error' => $response['error'],
            ];

            if (!$response['success']) {
                $allErrors[] = ['code' => 'fetch_failed', 'message' => 'Fetch failed for: ' . $nextUrl . ' (' . ($response['error'] ?? 'unknown') . ')'];
                break;
            }

            if (!$this->isHtmlContentType((string) $response['content_type'])) {
                $allErrors[] = ['code' => 'invalid_content_type', 'message' => 'Expected HTML content type for: ' . $nextUrl];
                break;
            }

            $html = (string) $response['body'];
            $parsed = $this->parseHtmlForKeyword($html, $baseUrl, $keyword, $regexMode, $exactMatch, $source);
            $results = array_merge($results, $parsed);
            if (count($results) >= $this->maxResultsPerKeyword) {
                return array_slice($results, 0, $this->maxResultsPerKeyword);
            }

            $nextUrl = $this->extractNextPageUrl($html, $baseUrl);
            $pageCount++;
        }

        return array_slice($results, 0, $this->maxResultsPerKeyword);
    }

    /**
     * @param array<int, array<string, mixed>> $fetchDetails
     * @param array<int, array<string, string>> $allErrors
     * @return array<int, array<string, mixed>>
     */
    private function scanWpRest(
        string $url,
        string $keyword,
        bool $regexMode,
        bool $exactMatch,
        array &$fetchDetails,
        array &$allErrors
    ): array {
        $response = $this->fetchWithRetry($url);
        $fetchDetails[] = [
            'url' => $url,
            'status' => $response['status_code'],
            'content_type' => $response['content_type'],
            'error' => $response['error'],
        ];

        if (!$response['success']) {
            return [];
        }

        if (!str_contains((string) $response['content_type'], 'application/json')) {
            return [];
        }

        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded)) {
            $allErrors[] = ['code' => 'invalid_json', 'message' => 'Invalid JSON from WordPress REST endpoint.'];
            return [];
        }

        $matches = [];
        // Optimization: Prepare keyword once outside loop
        $preparedKeyword = $this->prepareKeyword($keyword, $regexMode, $exactMatch);

        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $link = trim((string) ($item['url'] ?? ''));
            if ($title === '' || $link === '') {
                continue;
            }
            if (!$this->matchKeyword($title, $preparedKeyword, $regexMode, $exactMatch)) {
                continue;
            }

            $matches[] = [
                'keyword' => $keyword,
                'title' => $title,
                'url' => $link,
                'source' => 'wordpress_rest',
                'severity' => $this->resultScorer->score($keyword, $title, $link, $regexMode),
            ];

            if (count($matches) >= $this->maxResultsPerKeyword) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @param array<int, array<string, mixed>> $fetchDetails
     * @param array<int, array<string, string>> $allErrors
     * @return array<int, array<string, mixed>>
     */
    private function scanGeneric(
        string $baseUrl,
        string $keyword,
        bool $regexMode,
        bool $exactMatch,
        array &$fetchDetails,
        array &$allErrors
    ): array {
        $response = $this->fetchWithRetry($baseUrl);
        $fetchDetails[] = [
            'url' => $baseUrl,
            'status' => $response['status_code'],
            'content_type' => $response['content_type'],
            'error' => $response['error'],
        ];

        if (!$response['success']) {
            return [];
        }

        if (!$this->isHtmlContentType((string) $response['content_type'])) {
            $allErrors[] = ['code' => 'invalid_content_type', 'message' => 'Fallback scan received non-HTML content.'];
            return [];
        }

        return $this->parseHtmlForKeyword((string) $response['body'], $baseUrl, $keyword, $regexMode, $exactMatch, 'generic_html');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseHtmlForKeyword(
        string $html,
        string $baseUrl,
        string $keyword,
        bool $regexMode,
        bool $exactMatch,
        string $source
    ): array {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if (!$loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $results = [];

        // Optimization: Prepare keyword once outside loop
        $preparedKeyword = $this->prepareKeyword($keyword, $regexMode, $exactMatch);

        $query = "//a[normalize-space(string(.)) != '']";
        if (!$regexMode && !$exactMatch) {
            $query = sprintf(
                "//a[contains(translate(normalize-space(string(.)), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), %s)]",
                $this->xpathLiteral($preparedKeyword)
            );
        }

        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return [];
        }

        $seen = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $title = trim($node->textContent);
            $href = trim($node->getAttribute('href'));

            if ($title === '' || $href === '') {
                continue;
            }

            $resolved = $this->urlNormalizer->resolveUrl($baseUrl, $href);
            if ($resolved === null) {
                continue;
            }

            if (!$this->matchKeyword($title, $preparedKeyword, $regexMode, $exactMatch)) {
                continue;
            }

            if (isset($seen[$resolved])) {
                continue;
            }
            $seen[$resolved] = true;

            $results[] = [
                'keyword' => $keyword,
                'title' => $title,
                'url' => $resolved,
                'source' => $source,
                'severity' => $this->resultScorer->score($keyword, $title, $resolved, $regexMode),
            ];

            if (count($results) >= $this->maxResultsPerKeyword) {
                break;
            }
        }

        return $results;
    }

    private function extractNextPageUrl(string $html, string $baseUrl): ?string
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $candidates = [
            "//a[@rel='next']/@href",
            "//a[contains(concat(' ', normalize-space(@class), ' '), ' next ')]/@href",
            "//link[@rel='next']/@href",
        ];

        foreach ($candidates as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }
            $value = trim((string) $nodes->item(0)->nodeValue);
            if ($value === '') {
                continue;
            }
            $resolved = $this->urlNormalizer->resolveUrl($baseUrl, $value);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWithRetry(string $url): array
    {
        $attempts = 0;
        $lastError = null;
        $lastResponse = null;

        while ($attempts <= $this->maxRetries) {
            // Replaced fetchOnce with fetchWithRedirects to handle redirects manually and securely
            $response = $this->fetchWithRedirects($url);
            if ($response['success']) {
                // If we get a 5xx error, it's not "success" in terms of fetch, but fetchWithRedirects returns success=true for HTTP responses.
                // fetchRaw sets success=false only for HTTP 500+ or network errors.
                // Wait, fetchRaw logic: $success = $status > 0 && $status < 500;
                // So if status is 503, success is false. So we retry.
                return $response;
            }

            $lastResponse = $response;
            $lastError = $response['error'];
            $attempts++;

            if ($attempts <= $this->maxRetries) {
                $baseDelayMs = (int) pow(2, $attempts) * 150;
                $jitterMs = random_int(0, 120);
                usleep(($baseDelayMs + $jitterMs) * 1000);
            }
        }

        return $lastResponse ?? [
            'success' => false,
            'status_code' => 0,
            'content_type' => null,
            'body' => '',
            'error' => $lastError ?? 'Unknown fetch failure.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchWithRedirects(string $url): array
    {
        $currentUrl = $url;
        $redirects = 0;
        $maxRedirects = 5;

        while ($redirects <= $maxRedirects) {
            $response = $this->fetchRaw($currentUrl);

            // If network error, return immediately (let fetchWithRetry handle retry)
            if (!$response['success'] && empty($response['status_code'])) {
                 return $response;
            }

            $status = (int) ($response['status_code'] ?? 0);

            // Handle redirects
            if ($status >= 300 && $status < 400) {
                $headers = (string) ($response['headers'] ?? '');
                if (preg_match('/^Location:\s*(.*)$/im', $headers, $matches)) {
                    $location = trim($matches[1]);
                    if ($location !== '') {
                        $nextUrl = $this->urlNormalizer->resolveUrl($currentUrl, $location);
                        if ($nextUrl !== null) {
                            try {
                                // Validate the new URL against SSRF policy
                                $this->ssrfGuard->validateAndResolve($nextUrl);
                                $currentUrl = $nextUrl;
                                $redirects++;
                                continue;
                            } catch (\Throwable $e) {
                                return [
                                    'success' => false,
                                    'status_code' => 0,
                                    'content_type' => null,
                                    'body' => '',
                                    'error' => 'SSRF_BLOCK_REDIRECT: ' . $e->getMessage(),
                                ];
                            }
                        }
                    }
                }
            }

            // Not a redirect or max redirects reached (actually max redirects logic is implicitly handled by loop condition)
            // Wait, if loop finishes, we return the last response.
            // But if we are redirecting, we 'continue'.
            // So if we break here, it means we are done.
            return $response;
        }

        return [
            'success' => false,
            'status_code' => 0,
            'content_type' => null,
            'body' => '',
            'error' => 'Too many redirects.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRaw(string $url): array
    {
        try {
            $resolved = $this->ssrfGuard->validateAndResolve($url);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status_code' => 0,
                'content_type' => null,
                'body' => '',
                'headers' => '',
                'error' => 'SSRF_BLOCK: ' . $e->getMessage(),
            ];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // SECURITY: Disable auto-follow to prevent SSRF bypass
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $this->timeout),
            CURLOPT_USERAGENT => 'CybokronForbiddenContentChecker/3.0',
            CURLOPT_FAILONERROR => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => true,
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $resolved['host'], $resolved['port'], $resolved['ip'])],
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            return [
                'success' => false,
                'status_code' => $status,
                'content_type' => $contentType,
                'body' => '',
                'headers' => '',
                'error' => 'CURL_' . $errno . ': ' . $error,
            ];
        }

        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $success = $status > 0 && $status < 500;

        if (!$success) {
            return [
                'success' => false,
                'status_code' => $status,
                'content_type' => $contentType,
                'body' => $body,
                'headers' => $headers,
                'error' => 'HTTP_' . $status,
            ];
        }

        return [
            'success' => true,
            'status_code' => $status,
            'content_type' => $contentType,
            'body' => $body,
            'headers' => $headers,
            'error' => null,
        ];
    }

    private function isHtmlContentType(string $contentType): bool
    {
        $ct = strtolower($contentType);
        if ($ct === '') {
            return true;
        }

        return str_contains($ct, 'text/html') || str_contains($ct, 'application/xhtml+xml');
    }

    private function prepareKeyword(string $keyword, bool $regexMode, bool $exactMatch): string
    {
        if ($regexMode) {
            return '/' . str_replace('/', '\\/', $keyword) . '/iu';
        }

        if ($exactMatch) {
            return mb_strtolower(trim($keyword), 'UTF-8');
        }

        return mb_strtolower($keyword, 'UTF-8');
    }

    private function matchKeyword(string $text, string $preparedKeyword, bool $regexMode, bool $exactMatch): bool
    {
        if ($regexMode) {
            return @preg_match($preparedKeyword, $text) === 1;
        }

        if ($exactMatch) {
            return mb_strtolower(trim($text), 'UTF-8') === $preparedKeyword;
        }

        return str_contains(mb_strtolower($text, 'UTF-8'), $preparedKeyword);
    }

    private function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        $parts = explode("'", $value);
        $quoted = array_map(static fn (string $part): string => "'" . $part . "'", $parts);
        return 'concat(' . implode(", \"'\", ", $quoted) . ')';
    }
}
