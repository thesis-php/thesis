# tools/trace-recorder

Records a program on the virtual time of thesis/async into the trace that the demos of the documentation site draw:
`Recorder::record($program)` gives `{rows, events}`, `bin/record <demo.php>` prints it as JSON
(the demo file returns the callable). A task is labelled by its name (`$group->spawn($task, name: 'fetch A')`,
the keys of `all()` and `race()`), a group by its name (`group($body, name: 'checkout')`) or by the function
that created it (`group`, `race`, `map`).

- The format is the site's: a row `{label, scope, parent, start, end, cancelAt, outcome}` per group, task, daemon,
  timeout and shield, an event `{t, text, kind, scope}`. Times are milliseconds since the first event, `outcome`
  is `value`, `error` or `cancelled`. The rest of the detail (the value, the error) is in the `text` of the events.
- The recorder is a listener of every event of `Thesis\Async\Trace` (`__invoke(Event)`): it formats a value
  or an error at once (`formatValue()`), never keeps it, and keeps nothing of a `TaskCreated` but the label.
- `cancelAt` of a row comes from `ScopeCancelled::$reached` and from a scope created cancelled, never from
  a rule of its own about shields: the library knows what a cancellation reaches.
- A task row starts at `TaskStarted`: a task cancelled before it started has `start: null`.
