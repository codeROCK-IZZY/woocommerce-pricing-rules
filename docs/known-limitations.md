# Known limitations

- **Displayed unit prices can be a rounded share.** When a line's discounted total does not divide evenly by its
  quantity (six bags sharing part of a "$10 off" discount, for example), the unit price is shown rounded, and the cart
  block's "Save" badge, which WooCommerce computes from rounded unit prices, can differ from the true saving by a cent
  or a few cents. Line totals, cart totals, orders and the amount charged are exact.
- **Product-level scope uses the parent product.** A rule for a variable product applies to all its variations;
  rules cannot target a single variation.
- **Tax-inclusive stores** are priced on the stored product price, as WooCommerce does; the engine does not
  separate tax. The integration tests run with taxes off.
- **No coupon interplay settings.** Rules always apply before coupons, and a coupon's percentage applies to the
  discounted price. There is no "do not combine with coupons" switch.
- **Rule count.** Rules are loaded from a cached query of up to 500 published rules.
- **Synthetic carts.** Random carts and rule sets are generated; they cover the rule types and their combinations
  broadly, but are not real order history.
- **Currencies with zero or three decimals** follow WooCommerce's price-decimals setting and are covered by the
  engine's integer design, but the integration tests only run a 2-decimal currency.
