# Tally Project Context

tally is a payments service: users and merchants holding wallets, moving money
through an HTTP transfer API, built on Hyperf 3.2 / Swoole (PHP 8.3) with
MySQL 8. Users transfer to users or merchants; merchants only receive.
Philosophy: a framework-free domain, every load-bearing decision recorded in an
ADR when it is made (never retroactively), and claims proven by tests and
measurements rather than asserted.

## Working in This Repo (Operational)

- Composer scripts are the verification contract: `composer test`,
  `composer analyse`, `composer cs-fix`. Reference script names in docs and
  agent briefs; never inline the underlying commands. A Makefile and CI take
  over as the contract when the work brings a real reason.
- Everything runs inside the `hyperf/hyperf:8.3-alpine-v3.19-swoole` container
  (Compose: app + mysql). Run one-off containers as the host user
  (`-u 1000:1000`) — files created as root inside the container are unwritable
  on the host.
- All persistence, logs, and timestamps are UTC; conversion happens only at
  presentation edges.
- Bash cwd resets between calls: always use absolute paths.
- `gh pr edit` fails on a GraphQL deprecation — use
  `gh api -X PATCH repos/augusto-dmh/tally/pulls/<n>` instead.
- Never `sleep N && cmd` — use `gh pr checks <n> --watch` as a background task
  or a Monitor until-loop.

## Durable Decisions

Never re-litigate these without a superseding ADR:

- **Runtime** (ADR-0001): Hyperf 3.2 on Swoole over Laravel/Symfony — the
  domain's interesting problems (concurrency, partial failure, behavior under
  load) live in the coroutine runtime.
- **Dependency discipline** (AI_STRATEGY 2026-08-02): every dependency arrives
  with the change that needs it and a recorded reason — `composer.json` stays
  an honest map of the architecture's evolution.
- **UTC default** (AI_STRATEGY 2026-08-02): kept deliberately, not as a
  leftover.

## Workflow

RESEARCH → RFC/ADR → tlc-spec-driven cycle → IMPLEMENT → PR

- One cycle = one PR = one tlc-spec-driven run. All work lands via PRs; merge
  with merge commits (`gh pr merge <n> --merge`) after explicit user approval —
  the merge is always a user gate.
- Branch names are product-framed (e.g. `feat/transfer-foundation`). Commits
  are `type: lowercase narrative phrase`. PR bodies are
  Summary/Changes/Verification with no footer. No AI attribution anywhere in
  git history or PRs.
- History is self-contained and describes only the present: no internal IDs
  (task, phase, cycle, requirement), no `.specs/` paths, and no forward
  roadmaps in commit messages, PR titles, PR bodies, or committed docs.
- `.specs/` is local working state and is gitignored — never commit it. When a
  cycle closes, snapshot it to the private research repo.
- Research happens in a private companion repo; only its distilled conclusions
  land here, as ADRs or RFCs.
- AI_STRATEGY.md is a live log: add load-bearing entries when they happen,
  especially rejections with mechanism-grounded reasons.
- Every behavior change ships with tests derived from spec acceptance criteria.

## Progressive Documentation Loading

Only read documents relevant to the current task:

- Why a decision was made → `docs/adr/`
- How AI is used, and notable accepted/rejected interactions → `AI_STRATEGY.md`
- Cycle working state → `.specs/` (local only, owned by tlc-spec-driven)

## Current Constraints

- Solo maintainer: cycles must stay PR-sized.
- Public repo: technical merit only — no personal-motivation framing in
  committed files.
- The domain layer imports nothing from the framework.
