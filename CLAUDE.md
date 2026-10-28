# CLAUDE.md

See [AGENTS.md](AGENTS.md). It is the guide for working on this package and
applies here in full: the layout, the invariants, how to run the suite, how to
update to a new Centrifugo API, and the list of things that look wrong but are
deliberate.

Three points from it are worth repeating, because they are the ones most easily
lost:

- **Never edit `generated/` or `API.md`.** Change `proto/`, `gen/` or a
  docblock, then run `composer generate` and `composer docs`.
- **Every call throws unless it did everything asked.** A broadcast or batch in
  which some parts failed throws `BroadcastFailedChannelsException` or
  `BatchFailedCommandsException`, carrying every part's outcome.
- **Replies decode to objects, never associative arrays**: a payload sent back
  must be the same JSON value.

Run `composer check` before reporting a change as done, and
`composer test-integration` with Centrifugo running for a change to the
transport, errors, batching or handlers.
