<?php

/**
 * ПОДХОД 6. Акторы и деревья супервизии (Erlang/OTP). «Отмена» = сигнал выхода, «структура» = дерево супервизоров.
 *
 * НАБРОСОК API: тел нет.
 *
 * Идея. Подходы 1–3 хороши для запроса: дерево задач рождается и умирает вместе с ним. Но в долгоживущем
 * процессе есть вещи с собственным жизненным циклом: соединение с брокером, пул, подписка, лимитер.
 * У них есть состояние, к ним обращаются конкурентно, они падают и должны перезапускаться.
 * Актор — это файбер, владеющий состоянием единолично и читающий сообщения по одному:
 *
 *   - мьютексы не нужны: внутри актора нет конкурентности, сообщения обрабатываются строго по очереди;
 *   - ошибки не обрабатываются на месте («let it crash»): актор падает, супервизор перезапускает его
 *     с чистым состоянием по объявленной стратегии;
 *   - время жизни задаётся деревом: остановка супервизора останавливает всех потомков в обратном порядке.
 *
 * Идеи реализации:
 *
 * 1. Актор = Task в Group из подхода 1 + Channel из подхода 3 в роли почтового ящика. Супервизор = группа,
 *    которая вместо fail-fast применяет стратегию перезапуска. system() — это group(): не вернётся,
 *    пока дерево не остановлено. Отдельный рантайм не нужен.
 *
 * 2. Сигнал выхода — это отмена scope актора. Единственные точки приостановки актора — receive() и IO,
 *    обе стоят на suspend(), так что остановка доставляется без какого-либо отдельного механизма.
 *
 * 3. Типизация вместо «послал что угодно куда угодно»: Ref<M> параметризован типом сообщения,
 *    а запрос-ответ типизирован через Request<R> — call() возвращает R, а не mixed.
 *
 * 4. Изоляции кучи в PHP нет, поэтому она конвенциональная: сообщения — readonly-объекты.
 *    Это можно проверять статанализом, но не движком.
 *
 * 5. Ящик ограничен. Переполнение — явная политика (ждать / отбросить / уронить отправителя), иначе
 *    медленный актор превращается в утечку памяти. В Erlang этого нет по умолчанию, и там это больное место.
 *
 * 6. Интенсивность рестартов (не больше N за период) — иначе вечно падающий потомок жжёт CPU.
 *    Превышение роняет самого супервизора, и решение принимает уровень выше.
 *
 * Цена: запрос-ответ через call() медленнее и многословнее прямого вызова; два актора, вызывающие друг
 * друга синхронно, — дедлок (лечится только таймаутом на call). Акторами стоит делать «владельцев
 * ресурсов», а не каждую функцию.
 */

declare(strict_types=1);

namespace Ideas\Actors;

use Time\Duration;

// ---------------------------------------------------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Адрес актора. Переживает его перезапуски: отправители не замечают, что за адресом уже новый экземпляр.
 *
 * @template-contravariant M of object
 */
interface Ref
{
    /**
     * Асинхронная отправка: не ждёт обработки. При полном ящике действует политика актора.
     *
     * @param M $message
     */
    public function send(object $message): void;

    /**
     * Запрос-ответ с обязательным таймаутом. Если актор умер, не ответив, — ActorDown, а не вечное ожидание.
     *
     * @template R
     * @param M&Request<R> $request
     * @return R
     */
    public function call(Request $request, Duration $timeout): mixed;
}

/**
 * @template-covariant R тип ответа
 */
interface Request {}

/**
 * @template M of object
 */
interface Mailbox
{
    /**
     * @return M
     * @throws ReceiveTimeout
     */
    public function receive(?Duration $timeout = null): object;

    /**
     * Ответ на Request, полученный последним.
     */
    public function reply(mixed $value): void;
}

enum Restart
{
    /** Перезапускать всегда: соединения, консьюмеры. */
    case Permanent;

    /** Только при падении; нормальное завершение — это завершение. */
    case Transient;

    /** Не перезапускать: разовая работа. */
    case Temporary;
}

enum Strategy
{
    /** Упал один — перезапустить его одного. */
    case OneForOne;

    /** Упал один — перезапустить всех: потомки бессмысленны друг без друга. */
    case OneForAll;

    /** Упал один — перезапустить его и всех, кто запущен ПОСЛЕ него (они от него зависят). */
    case RestForOne;
}

enum Overflow
{
    case Wait;
    case DropNewest;
    case Fail;
}

interface Supervisor
{
    /**
     * @template M of object
     * @param \Closure(Mailbox<M>): void $behavior
     * @return Ref<M>
     */
    public function actor(
        string $name,
        \Closure $behavior,
        Restart $restart = Restart::Permanent,
        int $mailbox = 1024,
        Overflow $overflow = Overflow::Wait,
    ): Ref;

    /**
     * @param \Closure(Supervisor): void $children
     */
    public function supervisor(
        string $name,
        Strategy $strategy,
        \Closure $children,
        int $maxRestarts = 3,
        ?Duration $within = null,
    ): void;
}

/**
 * Корень дерева. Блокируется, пока дерево живо; отмена текущего scope останавливает дерево
 * в порядке, обратном запуску, и дожидается каждого актора.
 *
 * @param \Closure(Supervisor): void $children
 */
function system(Strategy $strategy, \Closure $children): void {}

final class ActorDown extends \RuntimeException {}
final class ReceiveTimeout extends \RuntimeException {}

// ---------------------------------------------------------------------------------------------------------------------
// 1. Владелец состояния: rate limiter без единого мьютекса
// ---------------------------------------------------------------------------------------------------------------------

/** @implements Request<bool> */
final readonly class Acquire implements Request
{
    public function __construct(public string $key, public int $cost = 1) {}
}

final readonly class Refill {}

/**
 * @param Mailbox<Acquire|Refill> $mailbox
 */
function rateLimiter(Mailbox $mailbox, int $capacity = 100): void
{
    $tokens = [];   // обычная локальная переменная: больше её никто не видит

    while (true) {
        $message = $mailbox->receive();

        if ($message instanceof Refill) {
            $tokens = [];

            continue;
        }

        $left = ($tokens[$message->key] ?? $capacity) - $message->cost;
        $allowed = $left >= 0;

        if ($allowed) {
            $tokens[$message->key] = $left;
        }

        $mailbox->reply($allowed);
    }
}

/**
 * @param Ref<Acquire> $limiter
 */
function handleRequest(Ref $limiter, string $clientId): string
{
    // $allowed выводится как bool: тип ответа зашит в Acquire implements Request<bool>.
    $allowed = $limiter->call(new Acquire($clientId), timeout: Duration::fromMilliseconds(50));

    return $allowed ? '200 OK' : '429 Too Many Requests';
}

// ---------------------------------------------------------------------------------------------------------------------
// 2. Единственный писатель в сокет + переподключение через «let it crash»
// ---------------------------------------------------------------------------------------------------------------------

final readonly class Publish
{
    public function __construct(public string $topic, public string $payload) {}
}

/**
 * @param Mailbox<Publish> $mailbox
 */
function brokerConnection(Mailbox $mailbox, string $dsn): void
{
    $socket = connect($dsn);   // не удалось — исключение, супервизор перезапустит с бэкоффом

    try {
        while (true) {
            try {
                $message = $mailbox->receive(timeout: Duration::fromSeconds(30));
                $socket->write(encode($message));
            } catch (ReceiveTimeout) {
                $socket->write(heartbeat());   // тишина в ящике — повод для heartbeat
            }
            // Ошибку записи НЕ ловим. Здесь нет ни retry, ни reconnect: актор умрёт,
            // супервизор поднимет новый с чистым соединением, а Ref у отправителей останется прежним.
        }
    } finally {
        $socket->close();
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// 3. Дерево приложения: что от чего зависит и кто кого перезапускает
// ---------------------------------------------------------------------------------------------------------------------

function main(string $brokerDsn): void
{
    system(Strategy::OneForOne, static function (Supervisor $root) use ($brokerDsn): void {
        $limiter = $root->actor('rate-limiter', rateLimiter(...));

        // Консьюмеры бессмысленны без соединения и держат его состояние (подписки, delivery tags):
        // упало соединение — перезапускаем всех, кто запущен после него.
        $root->supervisor(
            name: 'messaging',
            strategy: Strategy::RestForOne,
            maxRestarts: 5,
            within: Duration::fromSeconds(60),
            children: static function (Supervisor $messaging) use ($brokerDsn): void {
                $connection = $messaging->actor(
                    name: 'connection',
                    behavior: static fn(Mailbox $mailbox) => brokerConnection($mailbox, $brokerDsn),
                    mailbox: 10_000,
                    overflow: Overflow::Wait,   // backpressure на издателей
                );

                foreach (['orders', 'payments'] as $queue) {
                    $messaging->actor("consumer-{$queue}", static fn(Mailbox $mailbox) => consume($queue, $connection));
                }
            },
        );

        // Обычный код, не актор: HTTP-сервер на group()/timeout() из подхода 1, который ходит в акторов.
        $root->actor('http', static fn(Mailbox $mailbox) => serveHttp($limiter), restart: Restart::Permanent);
    });

    // Сюда попадаем только после полной остановки дерева: SIGTERM → отмена корневого scope →
    // http перестаёт принимать, консьюмеры доделывают сообщения, соединение закрывается последним.
}

interface Socket
{
    public function write(string $bytes): void;

    public function close(): void;
}
function connect(string $dsn): Socket {}
function encode(Publish $message): string {}
function heartbeat(): string {}
function consume(string $queue, Ref $connection): void {}
function serveHttp(Ref $limiter): void {}
