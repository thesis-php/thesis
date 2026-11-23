<?php

declare(strict_types=1);

namespace Thesis\Demo\Internal;

use Thesis\Http\Request;
use Thesis\Http\Response;
use Thesis\Log\Logger;
use function Thesis\Async\delay;
use function Thesis\Time\ms;

/**
 * @internal
 */
final readonly class DelayedResponse
{
    public function __construct(
        private Logger $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        $path = $request->uri->getRawPath();
        $this->logger->info('Started {path}', ['path' => $path]);

        delay(ms(250));

        $this->logger->info('Finished {path}', ['path' => $path]);

        return new Response(body: "OK {$path}\n");
    }
}
