# Public API: ideas

Conventions of naming and of the design of the public API that the packages already follow, and the places
that break them. A backlog, not a decision: once a convention is agreed, it goes to `agents/php.md`
and is deleted from here, and so is a deviation once it is fixed or accepted (then into the "Decisions"
of its package). Found by reading `src/` of every package.

## Conventions the code follows

### Names

- A name is not prefixed by its package, the namespace qualifies it (`Amqp\Config`, `Amqp\Channel` beside
  `Async\Channel`). Only the abstract base of a package's exceptions is: `AmqpException`, `IoException`.
- An interface is a role noun without `Interface` (`Reader`, `Closer`, `Resolver`, `Mutex`, `RateLimiter`).
  An implementation says where its state lives or how it works (`LocalMutex`, `LocalTokenBucketRateLimiter`,
  `DnsResolver`, `WallClock`, `FrozenClock`); the only implementation of a public interface is `Internal\XxxImpl`
  (`GroupImpl`, `TaskImpl`, `ContinuationImpl`, `SignalTrapImpl`).
- Parameters: `$length` is exact, `$max`/`$maxLength` is an upper bound (`Buffer::read($length)`,
  `Reader::read($max)`, `Buffer::fillUntil($maxLength)`), a rate is `$per<Unit>` (`$perSecond`, `$perTick`).
  A callable is named by what runs it, as in the conventions of thesis/async: `$body`, `$task`, a role
  (`$register`, `$release`), otherwise `$fn`.
- Variants of one operation share its name and differ by an affix, so they sort together:
  `connect()`/`connectUnix()`, `listen()`/`listenUnix()`, `synchronized()`/`synchronizedKey()`,
  `toSec()`/`toSecFloat()`. `tryX()` never waits and tells by its result (`trySend(): SendResult`,
  `tryReceive($default)`), `xOr()` returns a default instead of throwing (`timeoutOr()`).
- A protocol client keeps the names of its protocol in camelCase, so that its documentation applies:
  `exchangeDeclare()`, `queueBind()`, `confirmSelect()`, `$routingKey`, `noAck`, `ReplyCode::PreconditionFailed`.
  Methods of the class `basic` drop the prefix (`publish()`, `consume()`, `qos()`).

### Exceptions

- An exception says what happened, with no `Exception` suffix: `<Noun>Closed` for a resource closed under
  the caller (`StreamClosed`, `SocketClosed`, `ChannelClosed`), `<Verb>Failed` for an operation (`ReadFailed`,
  `WriteFailed`, `ConnectFailed`, `ListenFailed`, `TlsFailed`, `DnsFailed`), or a participle (`Cancelled`,
  `TimedOut`, `PublishNacked`, `MessageReturned`).
- It is `final` and extends the abstract base of its package (net has none and extends `IoException`:
  its failures are I/O; async has none and extends `\RuntimeException`).
- What a handler needs is a `public readonly` property (`ChannelClosed::$replyCode`, `TimedOut::$timeout`,
  `PublishBatchFailed::$nacked`), never only in the message.
- A mistake of the caller is an exception of PHP, not ours: a wrong value is `\ValueError`, a wrong state
  or order is `\LogicException` with the fix in the message (`call confirmSelect() first.`,
  `call run() first.`).
- A message is a sentence ending with a period, except one that copies PHP (the polyfill). A failure is "Failed to <verb> …", a closed resource "The <noun> is closed." /
  "… was closed while reading.", a second reader "Another fiber is reading from the <noun>."

### Objects

- An object that only the library creates (`Socket`, `Server`, `Channel`, `Delivery`, `Consumer`) has an
  `@internal` constructor or `create()`: the user gets it from a function or a method (`connect()`,
  `$client->channel()`, `$channel->consume()`).
- A public static constructor is `from<Source>()` when it parses or reads (`Config::fromUri()`,
  `DnsResolver::fromFiles()`), the name of a well-known instance otherwise (`DnsResolver::system()`,
  `Order::native()`, `Instant::now()`).
- State is a property, not a `getX()`/`isX()` method: `public private(set) bool $isClosed` or a `get` hook.
  The only `get*()` is `getIterator()`. A bool starts with `is` (`$isDone`, `$isReleased`, `$isSettled`,
  `isCancelled()`, `isBound()`), unless it is a field of a protocol (`Delivery::$redelivered`).
- A sequence is `\IteratorAggregate` (`Async\Channel`, `Consumer`, `Batch`, `SignalTrap`), a size is
  `\Countable` (`Buffer`, `Async\Channel`, `Batch`).
- `close()` is idempotent and ends the waits on the object: they throw `XxxClosed`, or return `false`/`null`
  where the end is normal, so a loop ends by itself (`while (null !== $socket = $server->accept())`,
  `StreamWatcher::readable(): bool`). A source that the other side has to confirm is stopped by `cancel()`
  (`Consumer`), also idempotent.
- A method that settles something once returns whether this call did (`Deferred::complete()`,
  `Deferred::fail()`, `Lock::release()`, `Continuation::resume()`): a second call is not an error.

### Options

- Options are named parameters with defaults: of the function (`connect()`, `listen()`) or, when they are kept
  and passed around, of a `final readonly` object with promoted public properties (`Amqp\Config`, `ClientTls`,
  `ServerTls`, `Certificate`).
- An option whose default is not a constant expression (a `Duration`) is nullable, and its PHPDoc says
  what null is ("30 seconds if null": `Amqp\Config::$connectTimeout`, `DnsResolver::$timeout`).
- A package takes the type of the package below instead of a copy of it: `Amqp\Config::$tls` is
  `Net\ClientTls`, every duration is `Time\Duration`.

## Deviations

- `\InvalidArgumentException` instead of `\ValueError`: `Amqp\Config` (a malformed URI or host, an unknown
  auth mechanism, also `Amqp\Internal\address()`), `Io\Stream::__construct()` (not a stream resource; that one
  is rather a `\TypeError`).
- `Amqp\ConnectionFailed` against `Net\ConnectFailed` for the same failure. Either it is renamed to
  `ConnectFailed`, or the pair `ConnectionClosed`/`ConnectionFailed` is the reason and goes to the "Decisions"
  of amqp.
- Constructors of exceptions: `@internal` in async (`TimedOut`, `ChannelClosed`, `TaskCancelled`), public in amqp
  (`ChannelClosed`, `ConnectionClosed`, `MessageReturned`, `PublishBatchFailed`). To decide: may a user create
  them, in the tests of their own code for instance?
- Two ways to hide a constructor: `@internal __construct()` (amqp `Channel`, `Delivery`, `Consumer`) and a private
  constructor with `@internal static create()` (net `Socket`, `Server`; `Cancelled` has a reason of its own:
  its constructor must stay private). `create()` of net only prepares the stream, which the constructor could do.
  If one way is enough, it is `@internal __construct()`.
- Messages: the rate limiters say "`LocalSlidingWindowRateLimiter needs a positive window, got …`", `Backoff`
  says "`The initial delay of a backoff must be positive.`"; no period in `DnsFailed(lastError('Connection refused'))`
  and in both messages of `Instant`.
- Accepted, not a deviation: `Channel` and `ChannelClosed` exist both in async and in amqp. amqp follows
  its protocol and imports `Async\Channel` as `AsyncChannel`.
