<?php

/**
 * ПОДХОД 3. Операции как значения + select (Concurrent ML, Go). «Отмена» = проигрыш в select.
 *
 * НАБРОСОК API: тел нет.
 *
 * Идея. В Amp «подождать A, но не дольше T, и бросить, если пришёл сигнал S» собирается из трёх разных
 * механизмов: Future, TimeoutCancellation, DeferredCancellation. Здесь это ОДИН механизм: любое ожидание —
 * значение типа Op<T>, а select() ждёт сразу несколько и фиксирует ровно одно. Проигравшие операции
 * снимаются атомарно — токены отмены для этого не нужны вовсе.
 *
 * Чем это дополняет подход 1: scopes отвечают на вопрос «когда умирает поддерево задач», select —
 * на вопрос «чего из нескольких событий я жду прямо сейчас». Пример 2 ниже (батчинг «по размеру ИЛИ
 * по времени») на одних scope/timeout выражается плохо, а на select — в лоб.
 *
 * Идеи реализации:
 *
 * 1. У Op два внутренних метода: poll() — «готово ли прямо сейчас» и arm(Waker): disarm — «разбуди меня».
 *    select(): сначала poll() по всем веткам в случайном порядке (честность: первая ветка не морит остальные),
 *    затем arm() всех на ОДИН общий одноразовый Waker из ядра подхода 1, затем disarm() проигравших.
 *
 * 2. Однопоточность делает фиксацию тривиальной. В CML/Go нужен двухфазный commit с CAS между потоками;
 *    у нас между poll() и arm() никто не вклинится, а «кто первый дёрнул Waker — тот и выиграл».
 *    Рандеву (send в небуферизованный канал встречается с recv в чужом select) — это просто проверка,
 *    что Waker партнёра ещё не сработал, и пробуждение обоих за один шаг.
 *
 * 3. select() стоит на suspend() из подхода 1, поэтому отмена scope участвует в каждом select неявной веткой.
 *    Явный «done-канал» из Go не нужен.
 *
 * 4. Именованные аргументы вместо синтаксиса: `select(job: ..., stop: ...)` — variadic с именами даёт
 *    массив со строковыми ключами, а `match` по имени ветки статически проверяем и читается как Go-шный select.
 *
 * 5. Значение, принятое из канала, не теряется при одновременной отмене: в suspend() источник выигрывает
 *    у отмены, если сработал первым. Без этого правила каналы под отменой теряют сообщения.
 *
 * Цена: каналы — низкоуровневый инструмент, из них легко собрать дедлок; закрытие канала требует
 * дисциплины «закрывает отправитель». Это уровень библиотечного кода, а не контроллеров.
 */

declare(strict_types=1);

namespace Ideas\Select;

use Ideas\Group;
use Time\Duration;
use function Ideas\group;

// ---------------------------------------------------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Ожидание как значение: его можно сохранить, передать, преобразовать и скомбинировать — до того, как ждать.
 *
 * @template-covariant T
 */
interface Op
{
    /**
     * @template U
     * @param \Closure(T): U $fn
     * @return Op<U>
     */
    public function map(\Closure $fn): self;

    /**
     * Ждать только эту операцию (select из одной ветки).
     *
     * @return T
     */
    public function await(): mixed;
}

/**
 * Ждёт первую готовую операцию; остальные снимаются. Возвращает [имя ветки, значение].
 *
 * @template T
 * @param Op<T> ...$ops
 * @return array{string, T}
 */
function select(Op ...$ops): array {}

/**
 * @return Op<null>
 */
function after(Duration $delay): Op {}

/**
 * Готова немедленно: ветка `default`, превращает select в неблокирующий.
 *
 * @template T
 * @param T $value
 * @return Op<T>
 */
function always(mixed $value = null): Op {}

/**
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Channel implements \IteratorAggregate
{
    /**
     * capacity = 0 — рандеву: send() возвращается, только когда значение забрали. Это и есть backpressure.
     */
    public function __construct(int $capacity = 0) {}

    /** @param T $value */
    public function send(mixed $value): void {}

    /** @return T */
    public function recv(): mixed {}

    /**
     * @param T $value
     * @return Op<null>
     */
    public function sending(mixed $value): Op {}

    /** @return Op<T> */
    public function receiving(): Op {}

    public function close(): void {}

    /** foreach по каналу завершается, когда канал закрыт и вычерпан. */
    public function getIterator(): \Generator {}
}

/**
 * @return Channel<int> тики; медленный потребитель тики пропускает, а не копит
 */
function ticker(Duration $period): Channel {}

// ---------------------------------------------------------------------------------------------------------------------
// 1. Пул воркеров с backpressure и graceful shutdown
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @param iterable<Job> $source
 * @param Channel<null> $shutdown
 */
function processJobs(iterable $source, Channel $shutdown, int $workers = 8): void
{
    /** @var Channel<Job> $jobs */
    $jobs = new Channel();   // рандеву: продюсер не убегает вперёд воркеров

    group(static function (Group $group) use ($source, $shutdown, $jobs, $workers): void {
        for ($i = 0; $i < $workers; ++$i) {
            $group->go(static function () use ($jobs): void {
                foreach ($jobs as $job) {
                    handle($job);
                }
            });
        }

        foreach ($source as $job) {
            // «Отдать задачу ИЛИ узнать о shutdown» — одно ожидание. Задача, которую не успели отдать,
            // никуда не делась: send-ветка просто проиграла.
            [$case] = select(
                sent: $jobs->sending($job),
                stop: $shutdown->receiving(),
            );

            if ($case === 'stop') {
                break;
            }
        }

        $jobs->close();   // воркеры доделывают взятое, их foreach завершается, group() их дожидается
    });
}

// ---------------------------------------------------------------------------------------------------------------------
// 2. Батчинг «по размеру или по времени» — эталонная задача, где select незаменим
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @param Channel<Event> $events
 */
function batchWriter(Channel $events, Storage $storage, int $maxSize = 500): void
{
    $maxDelay = Duration::fromMilliseconds(50);
    $batch = [];
    $deadline = null;   // Op, созданная один раз на батч: таймер не перезапускается с каждым событием

    while (true) {
        [$case, $event] = $batch === []
            ? select(event: $events->receiving())
            : select(event: $events->receiving(), flush: $deadline);

        if ($case === 'event') {
            $batch[] = $event;
            $deadline ??= after($maxDelay);

            if (\count($batch) < $maxSize) {
                continue;
            }
        }

        $storage->write($batch);
        $batch = [];
        $deadline = null;
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Таймаут и «не блокируйся» — частные случаи select
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @param Channel<Reply> $replies
 */
function awaitReply(Channel $replies): Reply
{
    [$case, $reply] = select(
        reply: $replies->receiving(),
        timeout: after(Duration::fromSeconds(5)),
    );

    return match ($case) {
        'reply' => $reply,
        'timeout' => throw new \RuntimeException('No reply in 5s'),
    };
}

/**
 * @param Channel<Metric> $metrics
 */
function tryReport(Channel $metrics, Metric $metric): bool
{
    // Метрики не должны тормозить запрос: если буфер полон — роняем метрику, а не ждём.
    [$case] = select(
        sent: $metrics->sending($metric),
        dropped: always(),
    );

    return $case === 'sent';
}

// ---------------------------------------------------------------------------------------------------------------------
// 4. Rate limiter и fan-in
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @param iterable<Request> $requests
 */
function throttled(iterable $requests, int $perSecond): void
{
    $tick = ticker(Duration::fromMilliseconds(intdiv(1000, $perSecond)));

    foreach ($requests as $request) {
        $tick->recv();
        send($request);
    }
}

/**
 * Слияние нескольких источников в один поток с сохранением backpressure.
 *
 * @template T
 * @param Channel<T> ...$sources
 * @return Channel<T>
 */
function merge(Group $group, Channel ...$sources): Channel
{
    $merged = new Channel();

    $group->go(static function () use ($group, $sources, $merged): void {
        group(static function (Group $forwarders) use ($sources, $merged): void {
            foreach ($sources as $source) {
                $forwarders->go(static function () use ($source, $merged): void {
                    foreach ($source as $value) {
                        $merged->send($value);
                    }
                });
            }
        });

        $merged->close();   // закрываем ровно тогда, когда все источники вычерпаны
    });

    return $merged;
}

// ---------------------------------------------------------------------------------------------------------------------
// 5. Op как часть публичного API: вызывающий сам решает, с чем это ожидание комбинировать
// ---------------------------------------------------------------------------------------------------------------------

interface Lock
{
    /** @return Op<Lease> */
    public function acquiring(): Op;
}

function withLockOrGiveUp(Lock $lock, Channel $shutdown): ?Lease
{
    // Автор Lock ничего не знал ни про таймауты, ни про shutdown — и не должен был.
    [$case, $lease] = select(
        acquired: $lock->acquiring(),
        busy: after(Duration::fromSeconds(1)),
        stop: $shutdown->receiving(),
    );

    return $case === 'acquired' ? $lease : null;
}

final readonly class Job {}
final readonly class Event {}
final readonly class Reply {}
final readonly class Metric {}
final readonly class Request {}
final readonly class Lease {}
interface Storage
{
    /** @param list<Event> $batch */
    public function write(array $batch): void;
}
function handle(Job $job): void {}
function send(Request $request): void {}
