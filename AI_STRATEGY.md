# AI Strategy

How AI is used to build tally, and where the human keeps the wheel.

## Approach

AI (Claude) works on this codebase as a directed collaborator: research,
implementation under spec, and review. Direction, architecture decisions, and
acceptance stay human. Three habits structure the collaboration:

- **Direct** — tasks are framed with explicit constraints (scope, layering
  rules, verification gates) rather than open-ended prompts.
- **Criticize** — generated code and proposals are reviewed against fundamentals
  (correctness under concurrency, security, runtime behavior) before acceptance;
  disagreements are resolved by evidence, not authority.
- **Iterate** — decisions get recorded when made (ADRs for architecture, this log
  for notable AI interactions); wrong turns are kept on record, not rewritten.

## Decision log

Newest first. Only load-bearing interactions — routine completions are not logged.

### 2026-08-03 — Notification inside the transaction: cost observed live (accepted for now)
The transfer flow calls the notifier inside the database transaction, and the
very first live end-to-end run put a price on that: the public notifier
answered `504`, the exception unwound the transaction, and the client got `502`
with both balances untouched and an auto-increment gap left behind. Keeping the
simple placement was a reviewed decision, not an oversight — it buys a strong
guarantee (no committed transfer without an attempted notification, no
notification for an uncommitted transfer) at the price of coupling the API's
availability to a third party. The observed cost is on record here so the
trade-off gets revisited with evidence instead of opinion.

### 2026-08-03 — Runtime test-double binding (rejected, with the mechanism)
An earlier attempt bound test fakes by mutating the DI container at runtime and
flaked unpredictably. The mechanism, found while designing the test strategy
for this feature: Hyperf's testing harness builds a **new** container per test
and swaps the global `ApplicationContext` in `setUp`, so any binding made at
runtime is discarded with the previous container, and anything
container-derived that was captured before `setUp` — an HTTP test client built
in a constructor, for instance — keeps talking to a stale one. The accepted
approach follows from that: override the two external ports (authorizer,
notifier) through configuration when `APP_ENV=testing`, keep real MySQL
persistence in feature tests, and construct the client inside each test.
Recorded as a rejection because the symptom was flakiness but the lesson is the
lifecycle.

### 2026-08-02 — Development workflow vendored before implementation (accepted, human-directed)
A first implementation of the transfer feature was started ad hoc and
deliberately set aside: without a spec, independent verification, and decision
capture, review confidence didn't scale past the domain layer (the test
strategy in particular needed rethinking). The workflow skills were vendored
first — spec-driven development, ADR/RFC authoring, plan stress-testing, skill
authoring (provenance in `SKILLS.md`) — so each change runs research → ADR/RFC
when needed → spec-driven implementation → PR. The discarded attempt is kept on
record per this log's charter; its domain shape informs the next attempt.

### 2026-08-02 — Project scaffold: installer minimality (accepted, human-directed)
The official `hyperf-skeleton` installer was run with defaults, yielding core +
`hyperf/database` + `hyperf/redis` only — no queue, messaging, RPC, or tracing
components. Deliberate: every dependency must arrive with the change that needs
it and a recorded reason, keeping `composer.json` an honest map of the
architecture's evolution.

### 2026-08-02 — Timezone: keep UTC default (accepted after review)
The installer's UTC default was kept rather than configuring a local timezone.
For a payments service this is the correct posture, not a shortcut: all
persistence and logs in UTC, conversion only at presentation edges.

### 2026-08-02 — Framework choice (human decision, AI-researched)
The Hyperf-vs-Laravel/Symfony evaluation was researched by AI (framework
capabilities, runtime trade-offs, ecosystem state); the decision and its
rationale are human-owned and recorded in ADR-0001.
