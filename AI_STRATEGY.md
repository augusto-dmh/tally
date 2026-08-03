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
