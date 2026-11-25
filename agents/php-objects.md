# PHP classes and state

- Do not use traits. Related classes can share behavior in an abstract base class.

- `readonly` wherever possible: `final readonly class` for immutable classes, `readonly` on individual properties
  for partially mutable ones and for a class with a `get` hook (PHP forbids hooks on `readonly` properties).
- A value without arguments that is an attribute of the object is a property, a method is for work.
  A property depends only on the state of the object, does not wait, change anything, do I/O or throw,
  and two reads with no change between give the same value: `$package->path`, `$socket->isClosed`.
  Whether it is stored or computed by a `get` hook is hidden from the caller and may change. A method waits
  (`readable()`), changes state (`next()`), may throw (`Changelog::top()`), depends on the clock, the fiber
  or the environment (`remaining()`, `ScopedValue::get()`), creates something new on each call (`Git::catFile()`)
  or converts (`toString()`). A property is named by a noun or an adjective (`$name`, `$isDone`), a method
  by a verb. It stays a method where PHP or an interface requires one: an enum has no properties
  (`ReplyCode::isHardError()`), a static property has no hooks, `Countable::count()`.
- Named typed properties instead of id-indexed arrays (`$this->values[0]`).
  Use an array only for genuinely dynamic sets.
- No setters: writing a value is assigning a property. A property anyone may change with nothing to check
  is plain `public` (`$clock->now = $time`, not `$clock->set($time)`), one that changes only inside is
  `public private(set)` instead of a getter, one that never changes is `readonly`. A `set` hook is only
  for checking or normalizing on write. A method is for an operation with a meaning of its own
  (`advance($duration)`), for writing that waits (it may suspend the fiber) or returns something,
  and where an interface requires it.
- A copy with some properties changed is `clone($object, ['name' => $value])`, not `clone` and assignments,
  not a constructor call that repeats every property, not a `withX()` method. A `readonly` property is implicitly
  `protected(set)`, so for `clone()` from outside the class declare it `public public(set)`: it stays readonly
  (written once per object), only the copy may be made anywhere (`Thesis\Amqp\Message`).
  The array costs an allocation: a hot path keeps `clone` and assignments if a benchmark says so (`Buffer::slice()`).
- A method that doesn't use `$this` is `static` and is called via `self::`, in hand-written and generated code alike.
  Exceptions only where the language forbids it: interface implementations and overrides of instance methods.
- No static classes that pass complex state between their methods (the same arguments to every method,
  accumulators by reference). Write an object-function instead: a static entry point that creates a private
  instance, keeps the state in its properties and returns the result, e.g. `Validator::validate($monorepo)`.
- State that only one function needs (a memoized value, a cache, a lazily created instance) is a `static` variable
  inside that function, not a static property: it sits next to the only code that uses it, and since PHP 8.3
  it is initialized with any expression. A static property is only for state shared by several methods.
- When memoizing a value or changing other state shared by fibers, synchronize the whole operation if it can
  suspend between checking the state and storing the result. For example, keep one `Thesis\Async\Once` per
  lazily initialized value and have every caller await it. For competing state changes, use the same mutex
  from the first check through the update. AMQP's `confirmSelect()` and `txSelect()` share a mutex across the
  mode check, the wait for the broker's reply and the assignment. The RPC mutex alone let both fibers check
  the old mode before either recorded the new one.
- Choose how callers create an `@api` object by what the call tells them. Use public `__construct` when the class
  name and arguments make the operation clear. `new Dispatcher($listeners)` checks and prepares its listeners.
  `new Stream($resource)` takes ownership of an open resource and sets it up for non-blocking use.
  Use a named constructor when its name adds information, such as the source of the data, the resource it opens,
  or the mode it selects. A class can offer both forms. Make `__construct` private when callers should
  always use a named constructor. For internal classes and `@internal` constructors, choose the form that suits
  their callers.
- Promote a constructor parameter stored directly as a property only when the parameter and the property have the
  same type, including PHPDoc types. When the types differ, declare the property separately and assign it in
  `__construct`. For example, `Stream` takes a `resource` but stores a `?resource` so it can clear it on close.
  Document promoted properties with `@param` on `__construct`. When a named constructor prepares the property values,
  pass them to `__construct` in their final form.
- Static constructors go before property declarations and other methods. The destructor goes last.
  The constructor body uses its parameters, not the promoted properties (`$x`, not `$this->x`) where possible.

## Decisions

- Rejected: methods for derived values to keep a class `readonly`: the same kind of value was a property in one
  class and a method in another, and turning a stored value into a computed one changed the API.
  The API decides, not the modifier of the class.
