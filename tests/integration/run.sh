#!/usr/bin/env bash
# Installs WordPress and WooCommerce in the compose stack, activates the plugin, creates a catalogue and rules,
# and runs the checks, including a cart driven over HTTP through the WooCommerce Store API.
# Usage: bash tests/integration/run.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

wp() { docker compose exec -T cli wp "$@"; }
SITE=http://127.0.0.1:8080

docker compose up -d
echo "waiting for WordPress"
for _ in $(seq 1 60); do curl -fsS -o /dev/null "$SITE/wp-admin/install.php" 2>/dev/null && break; sleep 2; done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url="$SITE" --title="Northside Coffee Roasters" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi
wp rewrite structure '/%postname%/' --hard >/dev/null
wp plugin is-installed woocommerce || wp plugin install woocommerce ${WC_VERSION:+--version="$WC_VERSION"} >/dev/null
wp plugin activate woocommerce pricing-rules-for-woocommerce
echo "WooCommerce $(wp plugin get woocommerce --field=version)"
wp eval-file wp-content/plugins/pricing-rules-for-woocommerce/tests/integration/setup.php

echo "== checks inside WooCommerce"
wp eval-file wp-content/plugins/pricing-rules-for-woocommerce/tests/integration/checks.php

echo "== over HTTP (Store API, used by the cart and checkout blocks)"
fail=0
ok() { echo "  ok    $1"; }
bad() { echo "  FAIL  $1"; fail=1; }
HDR=$(mktemp)
curl -fsS -D "$HDR" -o /dev/null "$SITE/wp-json/wc/store/v1/cart"
TOKEN=$(grep -i '^cart-token:' "$HDR" | cut -d' ' -f2 | tr -d '\r')
NONCE=$(grep -i '^nonce:' "$HDR" | cut -d' ' -f2 | tr -d '\r')
PID=$(wp eval 'echo get_option("wcpr_test_products")["House blend 250 g"];')
RES=$(curl -fsS -X POST "$SITE/wp-json/wc/store/v1/cart/add-item" -H "Cart-Token: $TOKEN" -H "Nonce: $NONCE" \
  -H 'Content-Type: application/json' -d "{\"id\":$PID,\"quantity\":6}")
# 6 × 12.50 at 10% = 6 × 11.25 = 67.50 → 6750 minor units.
TOTAL=$(printf '%s' "$RES" | sed -E 's/.*"total_items":"([0-9]+)".*/\1/')
[ "$TOTAL" = "6750" ] && ok "Store API cart applies the bulk tier (6750)" || bad "Store API total_items=$TOTAL"
printf '%s' "$RES" | grep -q '"regular_price":"1250"' && printf '%s' "$RES" | grep -q '"price":"1125"' \
  && ok "Store API reports regular and discounted unit prices, so the cart block shows the saving" \
  || bad "Store API prices: $(printf '%s' "$RES" | grep -o '"prices":{[^}]*}' | head -1)"

echo "== uninstall"
wp plugin deactivate pricing-rules-for-woocommerce >/dev/null
wp plugin uninstall pricing-rules-for-woocommerce --skip-delete >/dev/null
LEFT=$(wp post list --post_type=wcpr_rule --post_status=any --format=count)
[ "$LEFT" = "0" ] && ok "uninstall removes the rules" || bad "uninstall left $LEFT rules"
wp plugin activate pricing-rules-for-woocommerce >/dev/null
wp eval-file wp-content/plugins/pricing-rules-for-woocommerce/tests/integration/setup.php >/dev/null

[ "$fail" = "0" ] && echo "all HTTP checks passed" || { echo "HTTP checks failed"; exit 1; }
