<?php

/**
 * ТОЧКА ОТСЧЁТА. Те же типовые задачи на amphp v3 — чтобы было с чем сравнивать остальные наброски.
 *
 * Что именно бесит (и что каждый следующий набросок пытается убрать):
 *
 * A. Cancellation — параметр. Он протекает в сигнатуру КАЖДОЙ функции на пути от контроллера до сокета.
 *    Забыли пробросить в одном месте — всё поддерево неотменяемо, и статически это не ловится.
 * B. Таймаут — это тоже Cancellation, поэтому «дедлайн на операцию» = ручная склейка CompositeCancellation
 *    на каждом уровне. А TimeoutCancellation, который не дождались, продолжает держать таймер в цикле.
 * C. async() неструктурен. Future живёт сам по себе: упавшая соседняя задача его не отменяет,
 *    выход из функции его не дожидается. Отсюда UnhandledFutureError и задачи-сироты.
 * D. Отмена edge-triggered и это обычный \Exception: один `catch (\Exception)` в цикле ретраев — и её нет.
 * E. Очистка: в finally у нас на руках уже отменённый Cancellation; для rollback нужно не забыть
 *    взять новый, иначе rollback отменится в первой же строке.
 *
 * Функции fetchUser() и т. п. — условные, тела не важны.
 */

declare(strict_types=1);

namespace Ideas\Baseline;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use function Amp\async;
use function Amp\delay;

// ---------------------------------------------------------------------------------------------------------------------
// 1. Fan-out с fail-fast
// ---------------------------------------------------------------------------------------------------------------------

function dashboard(int $userId, ?Cancellation $cancellation = null): array                               // (A)
{
    // Чтобы ошибка одного запроса отменила остальные, нужен собственный DeferredCancellation,
    // склеенный с пришедшим снаружи.
    $deferred = new DeferredCancellation();
    $cancellation = new CompositeCancellation($cancellation ?? new NullCancellation(), $deferred->getCancellation());

    $futures = [
        'user' => async(fetchUser(...), $userId, $cancellation),                                          // (A)
        'orders' => async(fetchOrders(...), $userId, $cancellation),                                      // (A)
        'recommendations' => async(fetchRecommendations(...), $userId, $cancellation),                    // (A)
    ];

    try {
        return Future\await($futures, $cancellation);
    } catch (\Throwable $exception) {
        $deferred->cancel($exception);

        // (C) Мы попросили соседей остановиться, но НЕ дождались их: функция вернёт управление,
        //     а они ещё выполняются. Чтобы дождаться, нужен второй проход по $futures с глотанием ошибок.
        foreach ($futures as $future) {
            $future->ignore();
        }

        throw $exception;
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// 2. Дедлайн на операцию
// ---------------------------------------------------------------------------------------------------------------------

function dashboardWithDeadline(int $userId, ?Cancellation $cancellation = null): array
{
    $timeout = new TimeoutCancellation(2);                                                               // (B)
    $cancellation = $cancellation === null ? $timeout : new CompositeCancellation($cancellation, $timeout);

    try {
        return dashboard($userId, $cancellation);
    } catch (CancelledException $exception) {
        // (B) Это наш таймаут или нас отменили снаружи? Приходится лезть в getPrevious().
        throw $exception;
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Hedged request
// ---------------------------------------------------------------------------------------------------------------------

function hedged(string $key, ?Cancellation $cancellation = null): string
{
    $deferred = new DeferredCancellation();
    $cancellation = new CompositeCancellation($cancellation ?? new NullCancellation(), $deferred->getCancellation());

    try {
        return Future\awaitFirst([
            async(readFrom(...), 'primary', $key, $cancellation),
            async(static function () use ($key, $cancellation): string {
                delay(0.05, cancellation: $cancellation);

                return readFrom('replica', $key, $cancellation);
            }),
        ], $cancellation);
    } finally {
        $deferred->cancel(); // (C) проигравший отменён, но не дождан
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// 4. Ретраи и очистка
// ---------------------------------------------------------------------------------------------------------------------

function transfer(Connection $connection, ?Cancellation $cancellation = null): void
{
    $connection->execute('BEGIN', $cancellation);

    try {
        for ($attempt = 1; ; ++$attempt) {
            try {
                $connection->execute('UPDATE accounts ...', $cancellation);

                break;
            } catch (\Exception $exception) {                                                             // (D)
                // CancelledException — тоже \Exception. Без отдельного catch выше мы «ретраим отмену».
                if ($attempt === 3) {
                    throw $exception;
                }
            }
        }

        $connection->execute('COMMIT', $cancellation);
    } catch (\Throwable $exception) {
        $connection->execute('ROLLBACK', new TimeoutCancellation(1));                                    // (E)

        throw $exception;
    }
}

function fetchUser(int $userId, ?Cancellation $cancellation = null): array {}
function fetchOrders(int $userId, ?Cancellation $cancellation = null): array {}
function fetchRecommendations(int $userId, ?Cancellation $cancellation = null): array {}
function readFrom(string $node, string $key, ?Cancellation $cancellation = null): string {}

interface Connection
{
    public function execute(string $sql, ?Cancellation $cancellation = null): void;
}
