<?php

/**
 * ПОДХОД 5. Прозрачные футуры: результат конкурентной задачи — сразу объект нужного типа (lazy proxy из PHP 8.4).
 *
 * РАБОЧИЙ пример на ядре подхода 1. Запускается: `php 05-transparent-futures.php`.
 *
 * Идея. Future<User> в PHP — нетипизируемая обёртка (дженериков нет), которую надо не забыть развернуть.
 * Вместо неё задача возвращает User: это ленивый прокси, инициализатор которого ждёт задачу.
 * Первое обращение к свойству или методу приостанавливает файбер до готовности результата.
 * Сигнатура `eventually(class-string<T>): T` понятна и IDE, и статанализу без единого @template у вызывающего.
 *
 * Идеи реализации:
 *
 * 1. ReflectionClass::newLazyProxy(fn () => $task->await()). Проверено: инициализатор спокойно
 *    приостанавливает файбер, движок это допускает.
 *
 * 2. Структурность не теряется: за прокси стоит обычный Task в Group. Даже если к объекту
 *    так никто и не обратился, group() дождётся задачи и пробросит её ошибку.
 *
 * 3. Неявный батчинг (Haxl / DataLoader без промисов): load() только запоминает id и отдаёт прокси.
 *    Первое обращение к ЛЮБОМУ из них выполняет один запрос на всю накопленную пачку. N+1 исчезает
 *    без изменения кода, который эти объекты читает.
 *
 * Ограничения — существенные, это сахар для слоя чтения, а не универсальная модель:
 *   - только объекты пользовательских классов: ни скаляров, ни массивов, ни enum, ни внутренних классов;
 *   - нужен конкретный класс: по интерфейсу прокси не создать;
 *   - ошибка и отмена вылетают в месте первого обращения к свойству — там, где исключений никто не ждёт
 *     (например, в шаблоне);
 *   - идентичность: прокси и реальный объект — разные экземпляры (=== и spl_object_id различаются).
 */

declare(strict_types=1);

namespace Ideas\Transparent;

use Ideas\Group;
use function Ideas\group;
use function Ideas\sleep;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/01-scopes-kernel.php';

/**
 * @template T of object
 * @param class-string<T> $class
 * @param \Closure(): T $fn
 * @return T
 */
function eventually(Group $group, string $class, \Closure $fn): object
{
    $task = $group->go($fn);

    return new \ReflectionClass($class)->newLazyProxy(static fn(): object => $task->await());
}

final readonly class User
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}

final readonly class Stats
{
    public function __construct(
        public int $orders,
    ) {}
}

$started = hrtime(true);

function log(string $message): void
{
    global $started;

    printf("  %4d ms  %s\n", intdiv(hrtime(true) - $started, 1_000_000), $message);
}

// ---------------------------------------------------------------------------------------------------------------------
// 1. Fan-out без единой Future в коде
// ---------------------------------------------------------------------------------------------------------------------

echo "1. Два запроса параллельно, в коде — обычные объекты\n";

group(static function (Group $group): void {
    $user = eventually($group, User::class, static function (): User {
        sleep(0.05);

        return new User(1, 'alice');
    });

    $stats = eventually($group, Stats::class, static function (): Stats {
        sleep(0.08);

        return new Stats(orders: 42);
    });

    log('оба объекта уже на руках, запросы ещё идут');

    $name = $user->name;       // ждём user
    log("user: {$name}");

    $orders = $stats->orders;  // ждём остаток stats: суммарно 80 мс, а не 130
    log("orders: {$orders}");
});

// ---------------------------------------------------------------------------------------------------------------------
// 2. Неявный батчинг
// ---------------------------------------------------------------------------------------------------------------------

final class UserLoader
{
    /** @var array<int, int> */
    private array $pending = [];

    /** @var array<int, User> */
    private array $loaded = [];

    public function load(int $id): User
    {
        if (!isset($this->loaded[$id])) {
            $this->pending[$id] = $id;
        }

        return new \ReflectionClass(User::class)->newLazyProxy(function () use ($id): User {
            if (isset($this->pending[$id])) {
                $this->flush();
            }

            return $this->loaded[$id];
        });
    }

    private function flush(): void
    {
        $ids = $this->pending;
        $this->pending = [];

        log('SELECT * FROM users WHERE id IN (' . implode(', ', $ids) . ')');
        sleep(0.02);

        foreach ($ids as $id) {
            $this->loaded[$id] = new User($id, "user-{$id}");
        }
    }
}

echo "\n2. Пять «запросов за пользователем» — один SQL\n";
$started = hrtime(true);

$loader = new UserLoader();
$authors = array_map($loader->load(...), [3, 7, 7, 12, 40]);   // так выглядит типичный N+1 в резолверах

foreach ($authors as $author) {
    log($author->name);
}
