<?php

/**
 * ПОДХОД 2. Ленивые задачи-значения (ZIO / Effect-TS / cats-effect IO), склеенные через `|>` и PFA из PHP 8.6.
 *
 * НАБРОСОК API: тел нет, синтаксис `f(?, $x)` требует PHP 8.6 (на 8.5 файл не пройдёт `php -l`).
 *
 * Идея. Future — это уже ЗАПУЩЕННОЕ вычисление: его можно только ждать. Task<T> — ОПИСАНИЕ вычисления:
 * пока его не запустили, ничего не происходит. Из этого бесплатно следует то, чего eager-модель не умеет:
 *
 *   - retry / repeat / hedge: описание можно запустить второй раз, запущенную Future — нельзя;
 *   - timeout / fallback / трассировка — это преобразование значения, а не параметр функции;
 *   - структурность по построению: конкурентность создают только комбинаторы (zip, race, traverse),
 *     и каждый из них сам дожидается своих детей. Нет ни async(), ни «забытой» Future.
 *
 * Идеи реализации:
 *
 * 1. Task — тонкая обёртка над \Closure(): T. Вся механика (отмена, группы, shield) берётся из ядра подхода 1;
 *    этот слой — ~150 строк комбинаторов поверх group()/timeout()/shield(). Свой рантайм не нужен.
 *
 * 2. Комбинаторы — свободные функции, а не методы. Тогда сторонний пакет добавляет свой комбинатор
 *    (circuitBreaker, traced, cached) без наследования и трейтов, а `|>` + `?` дают fluent-запись.
 *    PFA здесь принципиальна: `|>` требует callable от одного аргумента, и `retry($schedule, ?)` его даёт.
 *
 * 3. Schedule — чистое значение (по сути iterable<Duration>), время в него не зашито.
 *    Политику ретраев можно тестировать без часов и переиспользовать между retry() и repeat().
 *
 * 4. Time\Duration из 8.6 вместо float-секунд: исчезает вечная путаница секунды/миллисекунды.
 *
 * Цена:
 *   - возвращается «цвет»: User и Task<User> — разные типы, и это ровно то, от чего файберы нас избавили.
 *     Поэтому слой стоит держать на ГРАНИЦАХ (клиент к внешнему сервису, политика ретраев), а не тащить
 *     Task<T> через весь домен;
 *   - без дженериков типы держатся только на @template и статанализе;
 *   - стектрейсы идут через цепочку замыканий.
 */

declare(strict_types=1);

namespace Ideas\Lazy;

use Time\Duration;

// ---------------------------------------------------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @template-covariant T
 */
final readonly class Task
{
    /**
     * @param \Closure(): T $run
     */
    public function __construct(private \Closure $run) {}

    /**
     * Запуск в текущем файбере и текущем scope. До этого вызова не происходит НИЧЕГО.
     *
     * @return T
     */
    public function __invoke(): mixed
    {
        return ($this->run)();
    }
}

/**
 * @template T
 * @param Task<T> $task
 * @return T
 */
function run(Task $task): mixed {}

/**
 * @template T
 * @param Task<T> $task
 * @return Task<T>
 */
function timeout(Duration $limit, Task $task): Task {}

/**
 * @template T
 * @param Task<T> $task
 * @return Task<T>
 */
function retry(Schedule $schedule, Task $task): Task {}

/**
 * Если первая попытка не уложилась в $after — запускаем вторую параллельно, берём первую успешную.
 *
 * @template T
 * @param Task<T> $task
 * @return Task<T>
 */
function hedge(Duration $after, Task $task): Task {}

/**
 * @template T
 * @template U
 * @param \Closure(T): U $fn
 * @param Task<T> $task
 * @return Task<U>
 */
function map(\Closure $fn, Task $task): Task {}

/**
 * @template T
 * @template U
 * @param \Closure(\Throwable): U $fn
 * @param Task<T> $task
 * @return Task<T|U>
 */
function recover(\Closure $fn, Task $task): Task {}

/**
 * Все параллельно; первая ошибка отменяет остальных.
 *
 * @template K of array-key
 * @template T
 * @param array<K, Task<T>> $tasks
 * @return Task<array<K, T>>
 */
function zip(array $tasks): Task {}

/**
 * @template T
 * @param Task<T> ...$tasks
 * @return Task<T>
 */
function race(Task ...$tasks): Task {}

/**
 * @template T
 * @template U
 * @param iterable<T> $items
 * @param \Closure(T): Task<U> $fn
 * @return Task<list<U>>
 */
function traverse(iterable $items, int $concurrency, \Closure $fn): Task {}

/**
 * Ресурс: release выполняется всегда и под shield — даже если use отменили.
 *
 * @template R
 * @template T
 * @param Task<R> $acquire
 * @param \Closure(R): Task<mixed> $release
 * @param \Closure(R): Task<T> $use
 * @return Task<T>
 */
function bracket(Task $acquire, \Closure $release, \Closure $use): Task {}

/**
 * Single-flight: сколько бы раз ни запустили, выполняется один раз, остальные ждут тот же результат.
 *
 * @template T
 * @param Task<T> $task
 * @return Task<T>
 */
function shared(Task $task): Task {}

/**
 * @implements \IteratorAggregate<int, Duration>
 */
final readonly class Schedule implements \IteratorAggregate
{
    public static function fixed(Duration $delay): self {}

    public static function exponential(Duration $base, float $factor = 2.0): self {}

    public function jittered(float $ratio = 0.2): self {}

    public function capped(Duration $max): self {}

    public function times(int $attempts): self {}

    /**
     * @param \Closure(\Throwable): bool $predicate
     */
    public function when(\Closure $predicate): self {}

    public function getIterator(): \Generator {}
}

// ---------------------------------------------------------------------------------------------------------------------
// Клиент на границе системы отдаёт описания, а не результаты
// ---------------------------------------------------------------------------------------------------------------------

interface Api
{
    /** @return Task<User> */
    public function user(int $id): Task;

    /** @return Task<list<Order>> */
    public function orders(int $userId): Task;

    /** @return Task<list<Product>> */
    public function recommendations(int $userId): Task;

    /** @return Task<string> */
    public function read(string $node, string $key): Task;
}

// ---------------------------------------------------------------------------------------------------------------------
// 1. Fan-out + дедлайн + fallback для некритичной части
// ---------------------------------------------------------------------------------------------------------------------

function dashboard(Api $api, int $userId): Dashboard
{
    $recommendations = $api->recommendations($userId)
        |> timeout(Duration::fromMilliseconds(300), ?)
        |> recover(static fn(\Throwable $_): array => [], ?);   // рекомендации не должны ронять страницу

    return zip([
        'user' => $api->user($userId),
        'orders' => $api->orders($userId),
        'recommendations' => $recommendations,
    ])
        |> timeout(Duration::fromSeconds(2), ?)                 // общий дедлайн на всё поддерево
        |> map(static fn(array $parts): Dashboard => new Dashboard(...$parts), ?)
        |> run(...);                                            // и только здесь что-то реально запускается
}

// ---------------------------------------------------------------------------------------------------------------------
// 2. Политика устойчивости как значение: объявляется один раз, применяется к любому вызову
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @template T
 * @param Task<T> $task
 * @return Task<T>
 */
function resilient(Task $task): Task
{
    $schedule = Schedule::exponential(Duration::fromMilliseconds(100))
        ->jittered()
        ->capped(Duration::fromSeconds(2))
        ->times(4)
        ->when(static fn(\Throwable $error): bool => $error instanceof Unavailable);

    return $task
        |> timeout(Duration::fromSeconds(1), ?)   // таймаут на ОДНУ попытку
        |> retry($schedule, ?)
        |> timeout(Duration::fromSeconds(5), ?);  // и на все попытки вместе
}

function loadUser(Api $api, int $id): User
{
    return $api->user($id) |> resilient(...) |> run(...);
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Hedged request — одной строкой, потому что описание можно запустить дважды
// ---------------------------------------------------------------------------------------------------------------------

function read(Api $api, string $key): string
{
    return $api->read('any', $key)
        |> hedge(Duration::fromMilliseconds(50), ?)
        |> run(...);
}

// ---------------------------------------------------------------------------------------------------------------------
// 4. Ограниченная конкурентность
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @param list<int> $ids
 * @return list<User>
 */
function loadUsers(Api $api, array $ids): array
{
    return traverse($ids, concurrency: 20, fn: static fn(int $id): Task => resilient($api->user($id)))
        |> run(...);
}

// ---------------------------------------------------------------------------------------------------------------------
// 5. Ресурс с гарантированной очисткой: ROLLBACK случится и при таймауте, и при внешней отмене
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @template T
 * @param \Closure(Transaction): Task<T> $body
 * @return Task<T>
 */
function transactional(Pool $pool, \Closure $body): Task
{
    return bracket(
        acquire: $pool->begin(),
        release: static fn(Transaction $tx): Task => $tx->rollbackUnlessCommitted(),
        use: $body,
    );
}

// ---------------------------------------------------------------------------------------------------------------------
// 6. Single-flight: сто конкурентных запросов за одним ключом кеша — один поход в базу
// ---------------------------------------------------------------------------------------------------------------------

final class Cache
{
    /** @var array<string, Task<mixed>> */
    private array $inflight = [];

    /**
     * @template T
     * @param Task<T> $load
     * @return T
     */
    public function get(string $key, Task $load): mixed
    {
        return ($this->inflight[$key] ??= shared($load))();
    }
}

final readonly class User {}
final readonly class Order {}
final readonly class Product {}
final readonly class Dashboard
{
    public function __construct(public User $user, public array $orders, public array $recommendations) {}
}
final class Unavailable extends \RuntimeException {}
interface Pool
{
    /** @return Task<Transaction> */
    public function begin(): Task;
}
interface Transaction
{
    /** @return Task<null> */
    public function rollbackUnlessCommitted(): Task;
}
