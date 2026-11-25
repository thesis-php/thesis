<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Http\Status;

/**
 * @api
 */
final readonly class DefaultErrorHandler implements ErrorHandler
{
    public function handleError(int $status, Request $request): Response
    {
        $reason = Status::reasonPhrase($status);
        $title = $reason === null ? (string) $status : "{$status} {$reason}";

        return Response::html(
            body: <<<HTML
                <!doctype html>
                <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <title>{$title}</title>
                </head>
                <body>
                    <h1>{$title}</h1>
                </body>
                </html>
                HTML,
            status: $status,
        );
    }
}
