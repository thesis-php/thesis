<?php

/**
 * ПОДХОД 1. Типовые задачи на ambient scopes. Запускается: `php 01-scopes-examples.php`.
 *
 * На что смотреть: ни одна функция «бизнес-кода» (fetch, charge, rollback) не принимает Cancellation,
 * при этом отменяется всё и всегда, а из group() невозможно выйти, оставив за спиной живую задачу.
 */

declare(strict_types=1);

namespace Ideas\Examples;

use Amp\Cancellation;
use Ideas\Cancelled;
use Ideas\Group;
use Ideas\Scope;
use Ideas\TimedOut;
use function Ideas\all;
use function Ideas\amp;
use function Ideas\each;
use function Ideas\group;
use function Ideas\race;
use function Ideas\shield;
use function Ideas\sleep;
use function Ideas\timeout;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/01-scopes-kernel.php';

$started = hrtime(true);

function log(string $message): void
{
    global $started;

    printf("  %4d ms  %s\n", intdiv(hrtime(true) - $started, 1_000_000), $message);
}

function example(string $title, \Closure $body): void
{
    global $started;

    echo "\n", $title, "\n";
    $started = hrtime(true);

    try {
        $result = $body();
        log('=> ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $error) {
        log('=> ' . $error::class . ': ' . $error->getMessage());
    }
}

/**
 * «IO-вызов»: обычная синхронная на вид функция без единого упоминания отмены.
 */
function fetch(string $what, float $latency, ?\Throwable $failure = null): string
{
    log("→ {$what}");

    try {
        sleep($latency);
    } catch (Cancelled $cancelled) {
        log("✗ {$what} отменён");

        throw $cancelled;
    }

    if ($failure !== null) {
        log("! {$what} упал");

        throw $failure;
    }

    log("← {$what}");

    return $what;
}

example('1. Fan-out: три запроса параллельно', static fn(): array => all([
    'user' => static fn() => fetch('user', 0.05),
    'orders' => static fn() => fetch('orders', 0.08),
    'recommendations' => static fn() => fetch('recommendations', 0.03),
]));

example('2. Fail-fast: первая ошибка отменяет соседей, group() дожидается их и бросает её', static fn(): array => all([
    'user' => static fn() => fetch('user', 0.20),
    'orders' => static fn() => fetch('orders', 0.05, new \RuntimeException('orders: 503')),
    'recommendations' => static fn() => fetch('recommendations', 0.20),
]));

example('3. Дедлайн на поддерево: таймаут снаружи, конкурентность внутри', static fn(): array => timeout(0.1, static fn(): array => all([
    static fn() => fetch('fast', 0.05),
    static fn() => fetch('slow', 5.0),
    // Вложенный таймаут длиннее внешнего: сработает внешний, и вложенный не примет его за свой.
    static fn() => timeout(10, static fn() => fetch('slow-with-own-timeout', 5.0)),
])));

example('4. Hedged request: реплику спрашиваем, только если primary не ответил за 50 мс', static fn(): string => race(
    static fn() => fetch('primary', 0.30),
    static function (): string {
        sleep(0.05);

        return fetch('replica', 0.04);
    },
));

example('5. Ограниченная конкурентность: 7 задач, не больше 3 одновременно', static function (): string {
    each(range(1, 7), concurrency: 3, fn: static fn(int $n) => fetch("job-{$n}", 0.03));

    return 'done';
});

example('6. Фоновая задача, привязанная к работе: heartbeat живёт ровно пока идёт обработка', static fn(): string => group(
    static function (Group $group): string {
        $group->daemon(static function (): never {
            while (true) {
                sleep(0.03);
                log('  ♥ heartbeat');
            }
        });

        return fetch('long processing', 0.1);
    },
));

example('7. Очистка под отменой: rollback через shield() (со своим дедлайном)', static fn() => timeout(0.05, static function (): void {
    log('BEGIN');

    try {
        fetch('UPDATE accounts', 1.0);
        log('COMMIT');
    } catch (\Throwable $error) {
        // Без shield() этот fetch мгновенно получил бы Cancelled: scope всё ещё отменён.
        shield(static fn() => timeout(1.0, static fn() => fetch('ROLLBACK', 0.02)));

        throw $error;
    }
}));

example('8. Отмену нельзя проглотить: она возвращается на каждой точке приостановки', static fn() => timeout(0.05, static function (): string {
    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        try {
            return fetch("attempt-{$attempt}", 1.0);
        } catch (\Exception $error) {
            // Типовой ретрай: Cancelled сюда вообще не попадает, потому что она \Error.
            log('  retry после ' . $error::class);
        } catch (\Throwable $error) {
            // А здесь её «съели» намеренно. В Amp на этом отмена была бы потеряна навсегда.
            log('  проглотили ' . $error::class);
        }
    }

    return fetch('работа после проглоченной отмены', 0.01);
}));

example('9. Мост в amphp: scope превращается в Cancellation только на границе', static fn() => timeout(0.05, static function (): void {
    log('→ Amp\delay(10)');
    amp(static fn(Cancellation $cancellation) => \Amp\delay(10, cancellation: $cancellation));
}));

example('10. Отмена отдельной задачи: подписка, которую гасим по событию', static fn(): string => group(
    static function (Group $group): string {
        $subscription = $group->go(static fn() => fetch('subscription stream', 10.0));

        sleep(0.03);
        $subscription->cancel();

        try {
            $subscription->await();
        } catch (Cancelled) {
            return 'подписка остановлена, группа жива';
        }
    },
));

example('11. Таймаут можно поймать и продолжить: fallback', static function (): string {
    try {
        return timeout(0.03, static fn() => fetch('recommendations', 1.0));
    } catch (TimedOut) {
        return fetch('cached recommendations', 0.01);
    }
});
