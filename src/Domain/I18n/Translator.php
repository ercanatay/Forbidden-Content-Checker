<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\I18n;

use ForbiddenChecker\Http\Request;

final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $catalog = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config, private readonly string $localeDir)
    {
        $this->loadCatalog();
    }

    /**
     * @param array<string, string|int|float> $replacements
     */
    public function t(string $key, ?string $locale = null, array $replacements = []): string
    {
        $locale = $this->normalizeLocale($locale ?? (string) $this->config['default_locale']);
        $value = $this->catalog[$locale][$key]
            ?? $this->catalog[(string) $this->config['default_locale']][$key]
            ?? $key;

        foreach ($replacements as $token => $replacement) {
            $value = str_replace('{' . $token . '}', (string) $replacement, $value);
        }

        return $value;
    }

    public function resolveLocale(Request $request, ?string $userLocale = null): string
    {
        $supported = $this->supportedLocales();

        $explicit = $request->query('lang');
        if ($explicit && in_array($explicit, $supported, true)) {
            return $explicit;
        }

        if ($userLocale && in_array($userLocale, $supported, true)) {
            return $userLocale;
        }

        $acceptLanguage = $request->header('Accept-Language') ?? '';
        if ($acceptLanguage !== '') {
            $parts = explode(',', $acceptLanguage);
            foreach ($parts as $part) {
                $candidate = trim(explode(';', $part)[0]);
                if (in_array($candidate, $supported, true)) {
                    return $candidate;
                }

                // fallback for language-only header like "en"
                foreach ($supported as $locale) {
                    if (str_starts_with($locale, $candidate . '-')) {
                        return $locale;
                    }
                }
            }
        }

        return (string) $this->config['default_locale'];
    }

    /**
     * @return array<int, string>
     */
    public function supportedLocales(): array
    {
        return $this->config['supported_locales'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(string $locale): array
    {
        $normalized = $this->normalizeLocale($locale);

        return $this->catalog[$normalized] ?? $this->catalog[(string) $this->config['default_locale']] ?? [];
    }

    private function normalizeLocale(string $locale): string
    {
        return in_array($locale, $this->supportedLocales(), true) ? $locale : (string) $this->config['default_locale'];
    }

    private function loadCatalog(): void
    {
        foreach ($this->supportedLocales() as $locale) {
            $file = $this->localeDir . '/' . $locale . '.json';
            if (!is_file($file)) {
                $this->catalog[$locale] = [];
                continue;
            }

            $content = file_get_contents($file);
            $decoded = json_decode((string) $content, true);
            $this->catalog[$locale] = is_array($decoded) ? array_map(static fn ($value): string => (string) $value, $decoded) : [];
        }
    }
}
