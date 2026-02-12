<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Scan;

use ForbiddenChecker\Infrastructure\Logging\Logger;
use ForbiddenChecker\Infrastructure\Security\SsrfGuard;

final class SitemapDiscovery
{
    private const MAX_URLS = 500;
    private const MAX_DEPTH = 2;

    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly Logger $logger,
        private readonly int $timeout = 15
    ) {
    }

    /**
     * Discover URLs from a domain's sitemap.xml.
     *
     * @return array{urls: array<int, string>, errors: array<int, string>}
     */
    public function discover(string $domain): array
    {
        $baseUrl = $this->urlNormalizer->baseUrl(
            $this->urlNormalizer->normalizeInput($domain) ?? $domain
        );

        if ($baseUrl === null) {
            return ['urls' => [], 'errors' => ['Invalid domain or URL.']];
        }

        $sitemapUrl = rtrim($baseUrl, '/') . '/sitemap.xml';
        $urls = [];
        $errors = [];

        $this->crawlSitemap($sitemapUrl, $urls, $errors, 0);

        $urls = array_values(array_unique($urls));
        if (count($urls) > self::MAX_URLS) {
            $urls = array_slice($urls, 0, self::MAX_URLS);
        }

        return ['urls' => $urls, 'errors' => $errors];
    }

    /**
     * @param array<int, string> $urls
     * @param array<int, string> $errors
     */
    private function crawlSitemap(string $url, array &$urls, array &$errors, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        if (count($urls) >= self::MAX_URLS) {
            return;
        }

        $response = $this->fetchUrl($url);
        if ($response === null) {
            $errors[] = 'Failed to fetch: ' . $url;
            return;
        }

        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($response);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if ($xml === false) {
            $errors[] = 'Invalid XML in: ' . $url;
            return;
        }

        // Register namespaces for sitemap protocol
        $namespaces = $xml->getNamespaces(true);
        $ns = $namespaces[''] ?? 'http://www.sitemaps.org/schemas/sitemap/0.9';

        $xml->registerXPathNamespace('sm', $ns);

        // Check for sitemap index (contains other sitemaps)
        $sitemaps = $xml->xpath('//sm:sitemap/sm:loc');
        if ($sitemaps !== false && count($sitemaps) > 0) {
            foreach ($sitemaps as $loc) {
                $childUrl = trim((string) $loc);
                if ($childUrl !== '') {
                    $this->crawlSitemap($childUrl, $urls, $errors, $depth + 1);
                }
                if (count($urls) >= self::MAX_URLS) {
                    return;
                }
            }
            return;
        }

        // Parse URL entries
        $entries = $xml->xpath('//sm:url/sm:loc');
        if ($entries === false) {
            return;
        }

        foreach ($entries as $loc) {
            $pageUrl = trim((string) $loc);
            if ($pageUrl !== '' && filter_var($pageUrl, FILTER_VALIDATE_URL) !== false) {
                $urls[] = $pageUrl;
            }
            if (count($urls) >= self::MAX_URLS) {
                return;
            }
        }
    }

    private function fetchUrl(string $url): ?string
    {
        try {
            $resolved = $this->ssrfGuard->validateAndResolve($url);
        } catch (\Throwable $e) {
            $this->logger->warning('SitemapDiscovery SSRF blocked: ' . $e->getMessage());
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $this->timeout),
            CURLOPT_USERAGENT => 'CybokronForbiddenContentChecker/3.2 SitemapDiscovery',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $resolved['host'], $resolved['port'], $resolved['ip'])],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($body === false || $errno !== 0 || $status < 200 || $status >= 400) {
            return null;
        }

        return (string) $body;
    }
}
