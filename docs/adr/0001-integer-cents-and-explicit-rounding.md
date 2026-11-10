# 1. Integer cents and one explicit rounding step

## Context

Prices like 19.99 cannot be represented exactly in binary floating point, and percentage discounts produce
fractions of a cent. Engines that work in floats and round "at the end" differ from one another, and from what the
shopper was shown, by a cent here and there; engines that round each line's share of a cart discount separately
give "$10 off" as $9.99 or $10.01 (29% of random carts in the evaluation).

## Decision

- All engine arithmetic is in integers of the store's smallest unit (cents for 2-decimal currencies).
- A percentage discount is computed per unit and rounded half up once: `intdiv(price × bp + 5000, 10000)`.
- Percentages are in basis points (1250 = 12.5%), so admin input with two decimals is exact.
- A cart-level discount is spread over units in proportion to how much each can still be discounted, and the
  leftover cents go to the largest remainders (ties: earlier line, then higher price). The sum is exactly the
  advertised discount.
- Displayed promises (the quantity table) use the same function as the cart.

## Consequences

- Totals are reproducible and explainable to the cent; the evaluation checks that lines sum to the cart total and
  per-rule savings sum to the discount on 20,000 carts.
- Rounding per unit means 3 × 5% of $12.50 is 3 × $0.63, not 5% of $37.50 ($1.88). That is the behaviour a shopper
  expects from a per-item discount, and it is what the table on the product page shows.
