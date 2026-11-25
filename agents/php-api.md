# PHP declarations and API contracts

- Every top-level symbol (class, interface, trait, enum, function, constant) in `src/` is marked either `@api`
  or `@internal` in its PHPDoc, never both. Internal symbols live in the `Internal` sub-namespace of the package
  (`Thesis\Async\Internal\...`) and nothing else does, so the namespace alone tells a user what is safe to depend on.
  Tests are not marked: they are not part of the API.
- Everything of a package is in `Thesis\<Name>\`. The one exception is a package that *is* its entry point:
  that symbol, named after the package, may live in the root namespace (`Thesis\Dispatcher`,
  `Thesis\exceptionally()`). Nothing else goes to the root but its test (`Thesis\DispatcherTest`),
  not `Internal`, not test helpers or fixtures: `Thesis\` is shared by all the packages, only the name
  of a package is guaranteed to be free there. A package with several peers (`async`, `io`, `time`) keeps them
  in its namespace. How the manifest declares it is in `manifests.md`.
- PHPStan (`level: max`, strict rules) is part of the contract. A constraint expressed in a PHPDoc type
  (`non-empty-array`, `positive-int`, ...) needs no runtime guard and no test for violating it.
  Don't flag "PHPDoc isn't enforced at runtime" in reviews: tighten the type instead.
- Declare the key and value types of a namespace-level `WeakMap` constant once with `@var` above its declaration.
  The Thesis PHPStan extension reads the annotation. PHPStan's `dynamicConstantNames` skips object values.
- Document a method or property in the interface or parent class that defines its contract. An override inherits
  that PHPDoc. Keep only details specific to the override and type annotations that PHPStan needs there. For
  example, it needs the type arguments on a concrete `Generator` when the inherited return type is `Traversable`.
  An override accepts every input and state the contract allows.
- A function or method with a variadic parameter whose values are a list, not options by name
  (`awaitSignal(SIGINT, SIGTERM)`), is marked `@no-named-arguments`: PHP would put
  a named argument into the array under its name as a key, and PHPStan then reports such a call.
  Without the tag a variadic parameter takes names only where they mean something (`...$options`).
- A parameter that is only called or captured by a closure is `callable` with a signature (`callable(T): void`),
  not `Closure`, in a public API and inside alike: users may pass invokable objects, `[$object, 'method']`
  and the like, and a callable passed on needs no `$fn(...)` on the way. `\Closure` stays where the value is
  stored in a property (PHP has no `callable` property type, a conversion inside costs the same allocation),
  compared by identity (`spl_object_id()`, the handler that `offSignal()` removes), told apart by `instanceof`
  (a task from a `Callback` in the microtasks of `Loop`), and as a return type.
- A duration in a public API (a timeout, a delay, a TTL, an interval) is `Time\Duration`, created and converted
  with the functions of `thesis/time` (`sec(5)`, `ms(250)`, `toSecFloat()`). Never a bare `int`/`float` of seconds
  or milliseconds, and never `float|Duration`: the unit of a number is only a convention, and a union can be widened
  later but not narrowed. Inside, time is `int` nanoseconds: exact like `Duration` (`ns()`/`toNs()` convert
  without a loss) and as cheap as a float, while a `Duration` object costs an allocation per operation.
  Convert at the boundary to what the underlying API takes (float seconds: `$ns / 1e9`).
