# Pricing Rules for WooCommerce: evaluation

20,000 seeded random carts (1 to 8 lines, quantities up to 12, prices from free to $2,500) against random rule sets of all four types, with priorities, scopes, roles, schedules, exclusivity and discount caps. 75.4% of the carts received a discount.

## Correctness

- The engine and a separately written unit-by-unit reference agree on every line of **20,000 / 20,000** carts (totals and per-rule amounts).
- That comparison catches **10 of 10** deliberately planted bugs:
  - caught: round down instead of half up
  - caught: BOGO discounts the dearest unit
  - caught: BOGO ignores the group limit
  - caught: floor not enforced
  - caught: exclusive does not lock
  - caught: cart leftover cents dropped
  - caught: cart threshold uses >
  - caught: bulk counts all lines together
  - caught: priority order reversed
  - caught: rule end date inclusive

## Common shortcuts, measured

| Shortcut | Carts affected | This engine |
|---|---:|---:|
| Spreading a cart discount by rounding each line separately | 29% (1449 of 5000; up to 2¢ off) | 0 |
| Stacking percentages by adding them (prices below zero) | 49.1% (2457 of 5000) | 0 |

## Speed (20 rules)

| Cart | Units | Engine p50 | Engine p95 | Unit-by-unit reference p50 |
|---|---:|---:|---:|---:|
| 5 lines × 3 | 15 | 0.124 ms | 0.166 ms | 0.21 ms |
| 20 lines × 10 | 200 | 0.436 ms | 0.532 ms | 2.92 ms |
| 50 lines × 1000 | 50,000 | 1.566 ms | 2.58 ms | 1845.59 ms |

## Gate: 8 / 8 checks passed

| Check | Value | Target | |
|---|---|---|---|
| Carts where the engine differs from the independent reference | 0 / 20000 | = 0 | pass |
| Planted bugs caught by that comparison | 10 / 10 | = all | pass |
| Items priced below their floor or below zero | 0 | = 0 | pass |
| Carts whose lines do not add up to the total | 0 | = 0 | pass |
| Carts whose per-rule savings do not add up | 0 | = 0 | pass |
| Cart discounts off by a cent or more | 0 / 5000 | = 0 | pass |
| Negative prices from stacked percentages | 0 / 5000 | = 0 | pass |
| p95 time, 50 lines × 1,000 units, 20 rules | 2.58 ms | ≤ 25 ms | pass |
