<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http\Controllers;

use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;

final class HealthController extends ApiController
{
    public function healthz(Request $request): void
    {
        $health = $this->app->metrics()->health();
        $status = $health['ok'] ? 200 : 503;
        Response::envelopeSuccess($health, [], $status);
    }

    public function readyz(Request $request): void
    {
        Response::envelopeSuccess($this->app->metrics()->readiness());
    }

    public function metrics(Request $request): void
    {
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        echo $this->app->metrics()->prometheus();
    }
}
