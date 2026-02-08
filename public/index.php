<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

[$app, $router, $request] = fcc_bootstrap();

$dispatched = $router->dispatch($request);
if (!$dispatched) {
    \ForbiddenChecker\Http\Response::json([
        'success' => false,
        'data' => null,
        'error' => [
            'code' => 'not_found',
            'message' => 'Route not found.',
            'locale' => $app->config()['default_locale'],
            'traceId' => \ForbiddenChecker\Support\Utils::traceId(),
            'details' => ['path' => $request->uriPath(), 'method' => $request->method()],
        ],
        'meta' => [],
    ], 404);
}
