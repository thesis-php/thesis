<?php

declare(strict_types=1);

namespace Thesis\Http\Server;

use Testo\Assert;
use Testo\Test;
use Thesis\Dispatcher;
use Thesis\Http\Request;
use Thesis\Http\Server\Event\RequestEnded;
use Thesis\Http\Server\Stub\RecordingLogger;
use function Thesis\Time\ms;

#[Test]
final class ServerLoggerTest
{
    public function accessLoggingSubscribesOnlyWhenEnabledAndOmitsTheQuery(): void
    {
        $logger = new RecordingLogger();
        Assert::false(new Dispatcher([new ServerLogger($logger)])->listens(RequestEnded::class));

        $dispatcher = new Dispatcher([new ServerLogger($logger, accessLog: true)]);
        $dispatcher->dispatch(new RequestEnded(
            request: Request::get('/users/42?token=secret'),
            status: 200,
            responseWritten: false,
            duration: ms(25),
        ));

        Assert::same($logger->records, [['info', '{method} {path}', [
            'method' => 'GET',
            'path' => '/users/42',
            'status' => 200,
            'response_written' => false,
            'duration_ms' => 25.0,
        ]]]);
    }
}
