# 3. Apply discounts by setting item prices, from a captured original

## Context

WooCommerce offers three places to apply a discount: a negative fee, a programmatic coupon, or the cart item's price.
Fees are taxed differently from products and appear as a separate line; coupons interact with the shop's real coupons
and their usage limits. Changing the item price keeps tax, refunds and reports per product.

WooCommerce recalculates totals several times per request, and the product object in the cart already carries the
price set on the previous pass. Pricing from that price would discount the discount.

## Decision

- Hook `woocommerce_before_calculate_totals` at priority 20 (after plugins such as currency switchers at 10).
- Capture each cart item's price the first time it is seen in a request, and price from that captured value on every
  pass. Product objects are rebuilt from the session on each request, so the capture never goes stale.
- Set the item price to the exact line total divided by quantity. WooCommerce multiplies back and rounds the line to
  the store's decimals, which reproduces the line total in cents; the integration tests check this on 150 random
  carts.
- The original price stays the product's regular price, so the cart and checkout blocks show it struck through.

## Consequences

- One source of truth for the discounted price in the classic cart, the blocks, the Store API and orders.
- When a line's discounted total does not divide evenly by its quantity, the displayed unit price is rounded for
  display (see known limitations); line totals, order totals and the amount charged are exact.
