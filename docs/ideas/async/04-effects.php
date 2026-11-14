<?php

/**
 * ПОДХОД 4. Эффекты и обработчики (OCaml 5 effects, Koka, Unison abilities) на голых файберах.
 *
 * НАБРОСОК API: тел нет.
 *
 * Идея. \Fiber::suspend($value) умеет передавать значение наружу, а Revolt этим не пользуется:
 * файбер просто засыпает, а «чего он ждёт» знает только колбэк, зарегистрированный в цикле.
 * Здесь файбер приостанавливается с ОПИСАНИЕМ того, что ему нужно — объектом-эффектом:
 *
 *     function sleep(Duration $d): void { perform(new Sleep($d)); }
 *
 * Что с эффектом делать, решает обработчик, установленный выше по стеку. Бизнес-код остаётся прямым
 * и синхронным на вид, а его окружение подменяется целиком, без DI и без моков:
 *
 *   - прод: Sleep → таймер цикла, Read → Io\Poll;
 *   - тесты: виртуальное время и детерминированный планировщик с seed (simulation testing, как в
 *     FoundationDB / TigerBeetle): `sleep(1 час)` выполняется мгновенно, порядок переключений
 *     воспроизводим, гонку можно найти перебором seed и потом повторить;
 *   - durable execution (Temporal / Restate): обработчик журналирует результат каждого эффекта, после
 *     падения процесса код проигрывается заново, получая ответы из журнала, и продолжает с места сбоя;
 *   - отмена: обработчик возобновляет продолжение исключением. У него перед глазами КАЖДАЯ точка
 *     приостановки, так что дедлайны, трассировка и бюджет ретраев тоже становятся обработчиками.
 *
 * Идеи реализации (и грабли, одна из них проверена запуском):
 *
 * 1. Наивная схема «каждый handle() — вложенный файбер, необработанный эффект пробрасывается выше через
 *    собственный Fiber::suspend()» НЕ СОВМЕСТИМА с Revolt. Файберы асимметричны: suspend() возвращает
 *    управление тому, кто последним вызвал resume(). Если код внутри вложенного файбера дойдёт до
 *    amphp-библиотеки, её Suspension вернёт управление НАШЕМУ обработчику с null вместо эффекта, а позже
 *    Revolt возобновит вложенный файбер напрямую, в обход обработчика. Проверено: start() возвращает null,
 *    файбер доживает уже без нас.
 *
 * 2. Поэтому схема плоская: одна задача = один файбер, запущенный планировщиком. perform() сначала ищет
 *    обработчик в стеке обработчиков задачи (обычный связный список в FiberLocal):
 *      - «хвостовой» обработчик (Now, Random, Ask, Log — отвечает сразу) вызывается как функция,
 *        без переключения файбера: такие эффекты стоят как вызов метода;
 *      - «приостанавливающий» (Sleep, Read, Fork) делает Fiber::suspend($effect) прямо в планировщик.
 *    Глубина вложенности обработчиков на цену приостановки не влияет.
 *
 * 3. Продолжение одноразовое (файбер нельзя клонировать): ни multi-shot, ни бэктрекинга. Для IO,
 *    планирования, времени и журналирования этого достаточно.
 *
 * 4. Структурная конкурентность — тоже эффект: Fork/Join интерпретирует планировщик, и он же держит
 *    дерево задач. API для пользователя при этом тот же, что в подходе 1 (group/timeout/shield),
 *    меняется только то, что под ним.
 *
 * Цена: это замена нижнего этажа. amphp-библиотеки напрямую не работают (см. п. 1) — только через
 * эффект-адаптер AwaitFuture, который запускает их в отдельном Revolt-файбере. Брать подход целиком
 * стоит ради детерминированных тестов или durable execution; иначе достаточно забрать из него одну мысль:
 * время, случайность и IO — за интерфейсом рантайма, который можно подменить.
 */

declare(strict_types=1);

namespace Ideas\Effects;

use Time\Duration;

// ---------------------------------------------------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @template-covariant R тип ответа обработчика
 */
interface Effect {}

/**
 * @template R
 * @param Effect<R> $effect
 * @return R
 */
function perform(Effect $effect): mixed {}

/**
 * @template R
 */
interface Continuation
{
    /** @param R $value */
    public function resume(mixed $value = null): void;

    public function throw(\Throwable $error): void;
}

/**
 * @template E of Effect
 */
interface Handler
{
    /** @return class-string<E> */
    public function effect(): string;

    /**
     * Хвостовой обработчик отвечает сразу ($k->resume() до возврата) — тогда переключения файбера нет.
     *
     * @param E $effect
     */
    public function handle(Effect $effect, Continuation $k): void;
}

/**
 * Выполняет $body с дополнительными обработчиками. Ближайший по стеку побеждает.
 *
 * @template T
 * @param \Closure(): T $body
 * @return T
 */
function with(Handler|Runtime $handler, \Closure $body): mixed {}

/** Набор обработчиков, закрывающий все базовые эффекты. */
interface Runtime {}

// Базовые эффекты — обычные readonly-значения. Их можно логировать, сериализовать, сравнивать в тестах.

/** @implements Effect<null> */
final readonly class Sleep implements Effect
{
    public function __construct(public Duration $duration) {}
}

/** @implements Effect<\DateTimeImmutable> */
final readonly class Now implements Effect {}

/** @implements Effect<int> */
final readonly class Random implements Effect
{
    public function __construct(public int $min, public int $max) {}
}

/**
 * @template T
 * @implements Effect<T>
 */
final readonly class Call implements Effect
{
    /**
     * Побочное действие во внешний мир с именем — единица журналирования для durable execution.
     *
     * @param \Closure(): T $action
     */
    public function __construct(public string $name, public \Closure $action) {}
}

/**
 * @template T
 * @implements Effect<list<T>>
 */
final readonly class All implements Effect
{
    /** @param list<\Closure(): T> $branches */
    public function __construct(public array $branches) {}
}

// Пользователь эффектов не видит — только обычные функции.

function sleep(Duration $duration): void
{
    perform(new Sleep($duration));
}

function now(): \DateTimeImmutable
{
    return perform(new Now());
}

/**
 * @template T
 * @param \Closure(): T $action
 * @return T
 */
function call(string $name, \Closure $action): mixed
{
    return perform(new Call($name, $action));
}

/**
 * @template T
 * @param \Closure(): T ...$branches
 * @return list<T>
 */
function all(\Closure ...$branches): array
{
    return perform(new All($branches));
}

// ---------------------------------------------------------------------------------------------------------------------
// 1. Бизнес-код: ни Cancellation, ни Clock, ни Future, ни единой зависимости от рантайма
// ---------------------------------------------------------------------------------------------------------------------

function checkout(Order $order, Payments $payments, Warehouse $warehouse, Mailer $mailer): Receipt
{
    [$payment, $reservation] = all(
        static fn() => call('charge', static fn() => $payments->charge($order)),
        static fn() => call('reserve', static fn() => $warehouse->reserve($order)),
    );

    for ($attempt = 1; ; ++$attempt) {
        try {
            call('email', static fn() => $mailer->sendReceipt($order, $payment));

            break;
        } catch (TemporaryFailure $failure) {
            if ($attempt === 5) {
                throw $failure;
            }

            sleep(Duration::fromSeconds(2 ** $attempt));
        }
    }

    return new Receipt($payment, $reservation, issuedAt: now());
}

// ---------------------------------------------------------------------------------------------------------------------
// 2. Прод: реальный цикл событий
// ---------------------------------------------------------------------------------------------------------------------

function production(Order $order, Payments $payments, Warehouse $warehouse, Mailer $mailer): Receipt
{
    return with(new EventLoopRuntime(), static fn() => checkout($order, $payments, $warehouse, $mailer));
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Тест: виртуальное время и воспроизводимый порядок переключений
// ---------------------------------------------------------------------------------------------------------------------

function testRetriesEmailWithBackoff(Order $order, Payments $payments, Warehouse $warehouse): void
{
    $mailer = new FlakyMailer(failures: 3);
    $simulation = new Simulation(seed: 42, startAt: new \DateTimeImmutable('2026-01-01 00:00:00'));

    $receipt = with($simulation, static fn() => checkout($order, $payments, $warehouse, $mailer));

    // 2 + 4 + 8 секунд бэкоффа «прошли», тест отработал за микросекунды и без единого sleep-мока.
    \assert($simulation->elapsed() == Duration::fromSeconds(14));
    \assert($receipt->issuedAt == new \DateTimeImmutable('2026-01-01 00:00:14'));
}

function findRaces(\Closure $scenario): void
{
    // Планировщик выбирает следующую готовую задачу по seed. Упало на seed=7341 —
    // этот же seed воспроизведёт то же самое чередование на любой машине.
    for ($seed = 0; $seed < 10_000; ++$seed) {
        with(new Simulation($seed), $scenario);
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// 4. Durable execution: тот же checkout() переживает рестарт процесса
// ---------------------------------------------------------------------------------------------------------------------

function durable(string $workflowId, Journal $journal, \Closure $workflow): mixed
{
    // Call: если в журнале уже есть результат шага с таким именем — отдаём его, действие НЕ выполняем.
    // Sleep: в журнале лежит момент пробуждения, после рестарта досыпаем остаток, а не всё заново.
    // Now/Random: журналируются, иначе повторный проход пойдёт по другой ветке.
    return with(new EventLoopRuntime(), static fn() => with(new Journaled($workflowId, $journal), $workflow));
}

// ---------------------------------------------------------------------------------------------------------------------
// 5. Свой эффект: дедлайн как неявный контекст (Reader), без FiberLocal и без параметра
// ---------------------------------------------------------------------------------------------------------------------

/** @implements Effect<?\DateTimeImmutable> */
final readonly class AskDeadline implements Effect {}

function remaining(): ?Duration
{
    $deadline = perform(new AskDeadline());

    return $deadline === null ? null : Duration::fromMilliseconds(
        max(0, (int) (($deadline->format('U.u') - now()->format('U.u')) * 1000)),
    );
}

function callDownstream(HttpClient $http, Request $request): Response
{
    // Остаток бюджета уезжает в заголовок: нижестоящий сервис не будет работать на уже мёртвый запрос.
    return $http->send($request->withHeader('grpc-timeout', remaining()));
}

final readonly class Order {}
final readonly class Request
{
    public function withHeader(string $name, mixed $value): self {}
}
final readonly class Response {}
final readonly class Receipt
{
    public function __construct(public mixed $payment, public mixed $reservation, public \DateTimeImmutable $issuedAt) {}
}
final class TemporaryFailure extends \RuntimeException {}
interface Payments
{
    public function charge(Order $order): mixed;
}
interface Warehouse
{
    public function reserve(Order $order): mixed;
}
interface Mailer
{
    public function sendReceipt(Order $order, mixed $payment): void;
}
interface HttpClient
{
    public function send(Request $request): Response;
}
interface Journal {}
final class FlakyMailer implements Mailer
{
    public function __construct(private int $failures) {}

    public function sendReceipt(Order $order, mixed $payment): void {}
}
final class EventLoopRuntime implements Runtime {}
final class Simulation implements Runtime
{
    public function __construct(int $seed, ?\DateTimeImmutable $startAt = null) {}

    public function elapsed(): Duration {}
}
final class Journaled implements Runtime
{
    public function __construct(string $workflowId, Journal $journal) {}
}
