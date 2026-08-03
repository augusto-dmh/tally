# ADR-0002: Represent money as integer cents in a value object

- Status: Accepted
- Date: 2026-08-02

## Context

tally moves money. Every amount it touches is summed, subtracted from a balance,
compared against a balance, and persisted as the record of what happened. Those
operations have to be exact: a transfer of `100.50` must debit exactly `100.50`,
a wallet that holds exactly the transfer amount must reach exactly zero, and two
amounts that are the same must compare equal — not nearly equal.

PHP's native decimal type is `float`, and floats are binary: `100.50` is not
representable, `0.1 + 0.2 !== 0.3`, and errors accumulate across a chain of
arithmetic. In a ledger, that surfaces as balances that drift from the sum of
their entries and comparisons that fail for amounts a human would call identical.

The amounts here are single-currency (BRL) with exactly two decimal places, and
they enter the system as JSON — where a client may send `100.50` as a number or
`"100.50"` as a string, and PHP will happily turn either into a float on the way
in.

## Decision

Money is an integer count of cents, wrapped in a `Money` value object, and no
float appears anywhere on a money path.

`Money` is constructed either from cents or from a decimal string. The string
parser reads the digits rather than converting: a regex splits the integer and
fractional parts, the fraction is right-padded to two digits, and the value is
assembled with integer arithmetic — so the value never passes through a float
even for an instant. Addition, subtraction, and equality are integer operations
on that count.

Alternatives considered:

- **Floats with rounding at the edges.** Cheapest to write and the reason most
  naive implementations lose cents: rounding hides the drift rather than
  preventing it, and equality stays unreliable no matter where the rounding sits.
- **`DECIMAL` columns and nothing else.** MySQL's `DECIMAL` is exact, but the
  arithmetic that matters happens in PHP, between reading a balance and writing
  it back. An exact column fed by inexact application math is exact storage of a
  wrong number.
- **An arbitrary-precision library (BCMath/GMP, or a money package).** Correct,
  and the right answer for multi-currency systems, allocation, or interest — none
  of which this service has. For one currency at two fixed decimals, an integer
  in a small value object gets the same exactness without a dependency or the
  string-typed arithmetic that comes with it.

## Consequences

- Monetary columns are integers named for their unit — `balance_cents`,
  `amount_cents` — so the storage unit is impossible to misread at the call site
  or in a query console.
- Conversion happens only at the API edge, and by integer arithmetic in both
  directions: parsing splits digits on the way in, rendering formats
  `intdiv($cents, 100)` and `$cents % 100` into a decimal string on the way out.
  The API therefore answers with a string (`"value": "100.50"`), not a JSON
  number a client could re-float.
- Sub-cent values are unrepresentable by construction. An amount with more than
  two decimal places is rejected with `422` rather than silently rounded — a
  refusal at the boundary instead of a quiet loss inside it.
- A future second currency, or any operation that divides money (splits, fees,
  interest), needs its own decision: this one buys exactness for fixed two-decimal
  arithmetic and nothing more.
