# 2. Buckets of interchangeable units, checked against a unit-by-unit reference

## Context

Buy-X-get-Y and cart-level discounts treat units of the same line differently (one mug at half price, one not), so
the natural model is one record per unit. That is simple and slow: a wholesale order of 50 lines × 1,000 units with
20 rules takes about two seconds in PHP, on every cart recalculation.

## Decision

Units of a line with the same current price, floor and lock state are interchangeable for every rule. The engine
keeps them as buckets with a count and splits a bucket only when a rule treats part of it differently (the "get"
units of a BOGO group, or the units that receive a leftover cent). All tie-breaks use line order and price, never a
unit's position, so the result does not depend on which of several identical units a rule picks.

Because this is the clever part, it is checked against a deliberately unclever reference: one array entry per unit,
written separately and sharing no code with the engine.

## Consequences

- 2.6 ms at p95 for 50,000 units against about 2,000 ms for the reference; small carts are as fast as before.
- The two implementations agree on totals and per-rule amounts for every line of 20,000 random carts, and the
  comparison catches all ten planted bugs in `eval/Mutants.php`.
