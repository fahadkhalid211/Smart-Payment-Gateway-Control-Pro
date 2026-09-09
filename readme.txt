=== Gateway Conditioner for WooCommerce ===
Contributors: fahadkhalid211
Tags: woocommerce, payment gateway, checkout, conditional, payment methods
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.9
Stable tag: 2.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conditionally disable or restrict WooCommerce payment gateways at checkout using flexible IF → THEN rules with multi-condition AND logic.

== Description ==

Gateway Conditioner for WooCommerce gives store managers total control over which payment gateways appear on the checkout page. Create unlimited IF → THEN rules evaluated in real-time, combining conditions with AND logic — without touching a single line of code.

 7 Powerful Condition Types
- Product Category — Target specific categories with automatic child/sub-category inheritance.
- Specific Product — Restrict gateways based on individual products or product variations in the cart.
- Cart Total — Apply rules when the order subtotal or total meets conditions (greater than, less than, or equal).
- User Role — Differentiate payment options for Wholesale buyers, logged-in customer roles, or Guest shoppers.
- Shipping Method — Show or hide gateways depending on the customer's selected shipping zone or rate (e.g. Local Pickup vs Flat Rate).
- Billing Country — Restrict payment gateways based on customer location.
- Order Quantity — Conditionally trigger rules based on total item count in the cart.

 Key Features
- HPOS & Blocks Ready — Fully compatible with WooCommerce High-Performance Order Storage (HPOS) and the latest 
- WooCommerce Cart & Checkout Blocks.
- Multi-Condition AND Groups — Chain multiple conditions together on a single rule (e.g. If Cart Total > $500 AND Shipping is Local Pickup → Disable Cash on Delivery).
- Compatible with Any Gateway — Works out-of-the-box with Stripe, PayPal, BACS Bank Transfer, Cash on Delivery, Cheque, and third-party custom gateways.
- Fast & Optimized — In-memory category hierarchy caching and instant preloaded shipping methods guarantee 0ms checkout lag.
- Modern & Clean Admin UI — Responsive admin settings interface with instant rule addition, condition chaining, and re-indexing.
- Translation Ready — Fully localized with standard text domain and included `.pot` file.

== Installation ==

1. Upload the plugin folder to your `/wp-content/plugins/` directory, or install the zip file via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins menu in WordPress.
3. Navigate to WooCommerce → Payment Rules in your WordPress admin dashboard to start creating rules.

== Frequently Asked Questions ==

= Does this work with WooCommerce Cart & Checkout Blocks? =
Yes. The plugin is officially tested and declared compatible with WooCommerce Cart & Checkout Blocks.

= Does this support High-Performance Order Storage (HPOS)? =
Yes. The plugin declares full compatibility with HPOS custom order tables.

= Can I target product variations? =
Yes. The Specific Product condition supports both simple products and product variations.

= Do category rules apply to child/sub-categories? =
Yes. Category rules automatically resolve all ancestor terms, ensuring child categories trigger rules set on parent categories.

= Can I use multiple conditions on a single rule? =
Yes. Click "Add AND Condition" within any rule card to combine multiple conditions. The rule will trigger only when all conditions are satisfied.

= What happens if no rules match? =
All payment gateways enabled in your WooCommerce settings will remain normally available at checkout.

== Changelog ==

= 2.2.0 =
* Added official compatibility declarations for WooCommerce Cart & Checkout Blocks and HPOS.
* Enhanced performance with request-level category hierarchy caching.
* Preloaded shipping methods with zone support for instant selection.
* Added support for product variations in Specific Product condition.
* Implemented Post-Redirect-Get pattern for admin settings saving.
* Added clean uninstall cleanup script (`uninstall.php`).
* Added complete localization template (`.pot`).
* Hardened security with strict per-condition operator validation and permission checks.

= 2.0.0 =
* Added multi-condition AND logic support.
* Added support for 7 condition types.

= 1.0.0 =
* Initial release.
