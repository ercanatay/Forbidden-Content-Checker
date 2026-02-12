<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http\Controllers;

use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;

final class UiController extends ApiController
{
    public function app(Request $request): void
    {
        $user = $this->user($request);
        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        $messages = $this->app->translator()->messages($locale);
        $csrf = $this->app->csrf()->token();
        $rtl = $locale === 'ar-SA';

        $initialState = [
            'locale' => $locale,
            'rtl' => $rtl,
            'supportedLocales' => $this->app->translator()->supportedLocales(),
            'messages' => $messages,
            'csrfToken' => $csrf,
            'user' => $user,
            'apiBase' => '/api/v1',
        ];

        $html = '<!doctype html>
<html lang="' . htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') . '" dir="' . ($rtl ? 'rtl' : 'ltr') . '">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cybokron Forbidden Content Checker v3</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
  <div id="app"></div>
  <script>window.__FCC_STATE__ = ' . json_encode($initialState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>
  <script src="/assets/app.js" defer></script>
</body>
</html>';

        Response::html($html);
    }
}
