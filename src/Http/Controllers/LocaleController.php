<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http\Controllers;

use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;

final class LocaleController extends ApiController
{
    public function list(Request $request): void
    {
        $user = $this->user($request);
        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);

        Response::envelopeSuccess([
            'current' => $locale,
            'supported' => $this->app->translator()->supportedLocales(),
            'messages' => $this->app->translator()->messages($locale),
            'rtl' => $locale === 'ar-SA',
        ]);
    }
}
