<?php

declare(strict_types=1);

namespace Thesis\Http\Server\Stub;

use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Server\ErrorHandler;

final readonly class TextErrorHandler implements ErrorHandler
{
    public function handleError(int $status, Request $request): Response
    {
        return new Response($status, body: "{$request->method} {$status}");
    }
}
