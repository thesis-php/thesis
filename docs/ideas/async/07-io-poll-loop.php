<?php

/**
 * НИЖНИЙ ЭТАЖ. Цикл событий на Io\Poll из PHP 8.6 — без ext-uv/ext-ev/ext-event и без stream_select().
 *
 * НАБРОСОК: написан по сигнатурам из RFC poll_api, НЕ ЗАПУСКАЛСЯ (локально PHP 8.5). Места, где поведение
 * API надо уточнять на живом 8.6, помечены «УТОЧНИТЬ».
 *
 * Зачем это в разговоре про подходы к асинхронности. До 8.6 «нативный PHP» означал stream_select():
 * O(n) на каждый тик и лимит FD_SETSIZE = 1024 дескриптора. Теперь epoll/kqueue доступны из коробки,
 * и появляется выбор из двух стратегий:
 *
 *   A. Написать драйвер Revolt поверх Io\Poll (наследник AbstractDriver). Вся экосистема amphp v3
 *      получает epoll без расширений, подходы 1–3, 5, 6 работают как есть. Это путь по умолчанию.
 *
 *   B. Свой цикл, заточенный под одноразовый Waker и дерево scope. Ниже — он. Оправдан только
 *      для подхода 4 (эффекты), где планировщик обязан сам видеть каждую приостановку.
 *
 * Идеи реализации:
 *
 * 1. В Io\Poll НЕТ таймеров и сигналов (в RFC это future scope). Таймеры — своя очередь, а таймаут
 *    Context::wait() = время до ближайшего таймера. Сигналы — pcntl_async_signals() + self-pipe.
 *
 * 2. Ambient-таймауты из подхода 1 означают, что почти каждый таймер ОТМЕНЯЕТСЯ, не сработав:
 *    timeout() выставляет его на входе и снимает на выходе. Значит, оптимизировать надо отмену:
 *    ленивое удаление (пометить мёртвым, выбросить при извлечении) либо иерархическое timer wheel
 *    с O(1) на постановку и отмену.
 *
 * 3. В данных watcher'а лежит Waker. Пробуждение по готовности сокета — это getData()->resume(),
 *    без промежуточных колбэков и поиска по id.
 *
 * 4. Очередь готовых обрабатывается порциями: то, что задачи наплодили за тик, уезжает на следующий,
 *    иначе пара «пинг-понг» задач навсегда отрезает цикл от IO и таймеров.
 */

declare(strict_types=1);

namespace Ideas\Poll;

use Io\Poll\Context;
use Io\Poll\Event;

final class Waker
{
    private bool $fired = false;

    public function __construct(
        private readonly Loop $loop,
        private readonly \Fiber $fiber,
    ) {}

    public function resume(mixed $value = null): bool
    {
        if ($this->fired) {
            return false;
        }

        $this->fired = true;
        $this->loop->ready(fn() => $this->fiber->resume($value));

        return true;
    }

    public function throw(\Throwable $error): bool
    {
        if ($this->fired) {
            return false;
        }

        $this->fired = true;
        $this->loop->ready(fn() => $this->fiber->throw($error));

        return true;
    }
}

final class Timer
{
    public bool $cancelled = false;

    public function __construct(
        public readonly int $at,
        public readonly Waker $waker,
    ) {}
}

final class Loop
{
    /** @var \SplQueue<\Closure(): void> */
    private \SplQueue $ready;

    /** @var \SplPriorityQueue<int, Timer> */
    private \SplPriorityQueue $timers;

    private Context $poll;

    private int $watchers = 0;

    public function __construct()
    {
        $this->ready = new \SplQueue();
        $this->timers = new \SplPriorityQueue();
        $this->poll = new Context();   // Backend::Auto: epoll / kqueue / event ports / WSAPoll / poll
    }

    /**
     * @param \Closure(): void $step
     */
    public function ready(\Closure $step): void
    {
        $this->ready->enqueue($step);
    }

    public function spawn(\Closure $fn): void
    {
        $fiber = new \Fiber($fn);
        $this->ready($fiber->start(...));
    }

    public function timer(int $delayNanoseconds, Waker $waker): Timer
    {
        $timer = new Timer(hrtime(true) + $delayNanoseconds, $waker);
        $this->timers->insert($timer, -$timer->at);   // max-heap → ближайший сверху

        return $timer;
    }

    /**
     * @param resource $stream
     * @return \Closure(): void disarm для suspend()
     */
    public function readable($stream, Waker $waker): \Closure
    {
        // OneShot: один раз сработал — снят. Совпадает с семантикой одноразового Waker.
        // УТОЧНИТЬ: остаётся ли после срабатывания OneShot watcher активным и надо ли звать remove().
        $watcher = $this->poll->add(new \StreamPollHandle($stream), [Event::Read, Event::OneShot], $waker);
        ++$this->watchers;

        return function () use ($watcher): void {
            if ($watcher->isActive()) {
                $watcher->remove();
                --$this->watchers;
            }
        };
    }

    public function run(): void
    {
        while (true) {
            // 1. Готовые задачи — ровно столько, сколько было на начало тика.
            for ($budget = $this->ready->count(); $budget > 0; --$budget) {
                ($this->ready->dequeue())();
            }

            // 2. Созревшие таймеры; отменённые выбрасываем по дороге.
            $now = hrtime(true);
            $nearest = null;

            while (!$this->timers->isEmpty()) {
                /** @var Timer $timer */
                $timer = $this->timers->top();

                if ($timer->cancelled) {
                    $this->timers->extract();
                } elseif ($timer->at <= $now) {
                    $this->timers->extract();
                    $timer->waker->resume();
                } else {
                    $nearest = $timer->at - $now;

                    break;
                }
            }

            // 3. Ждать нечего и некого — цикл завершён. (В Revolt это момент диагностики дедлока.)
            if ($this->ready->isEmpty() && $nearest === null && $this->watchers === 0) {
                return;
            }

            // 4. IO. Есть готовые задачи — не блокируемся; нет таймеров — ждём бесконечно.
            [$seconds, $microseconds] = match (true) {
                !$this->ready->isEmpty() => [0, 0],
                $nearest === null => [null, 0],
                default => [intdiv($nearest, 1_000_000_000), intdiv($nearest % 1_000_000_000, 1_000) + 1],
            };

            // УТОЧНИТЬ: как wait() ведёт себя при EINTR от сигнала — пустой массив или FailedPollWaitException.
            foreach ($this->poll->wait($seconds, $microseconds) as $watcher) {
                --$this->watchers;
                $watcher->getData()->resume();
            }
        }
    }
}
