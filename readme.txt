=== Gateway Conditioner for WooCommerce ===
Contributors: fahadkhalid211
Tags: woocommerce, payment gateway, checkout, conditional, payment methods, gateway conditioner
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conditionally filter and control WooCommerce payment gateways at checkout using flexible IF → THEN rules based on product, cart, user, country, and more.

== Description ==

Gateway Conditioner for WooCommerce gives you complete conditional control over which payment gateways appear at checkout. Define flexible IF → THEN rules evaluated in order with support for unlimited rules and 7 condition types:

* Product Category — show or hide a gateway based on product categories in the cart
* Specific Product — target individual products or product variations
* Cart Total — apply rules when the order value is above, below, or equal to a threshold
* User Role — target logged-in roles or guests
* Shipping Method — trigger rules based on the chosen shipping method
* Billing Country — restrict payment options by country
* Order Quantity — apply rules based on total item count in the cart

All rules are managed from a modern admin UI under **WooCommerce → Payment Rules**.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to WooCommerce → Payment Rules to create your rules.

== Frequently Asked Questions ==

= Does this work with HPOS (High-Performance Order Storage)? =
Yes, the plugin declares full compatibility with WooCommerce HPOS.

= Which WooCommerce versions are supported? =
WooCommerce 6.0 and above.

= Can I have multiple rules? =
Yes. You can create unlimited rules with multi-condition AND logic. Rules are evaluated in the order they appear.

== Changelog ==

= 2.2.0 =
* Complete rebrand to Gateway Conditioner for WooCommerce
* Clean standalone architecture with zero external licensing dependencies
* All 7 condition types and unlimited rules available natively
* Updated text domain to gateway-conditioner-for-woocommerce

= 2.0.1 =
* CSS changes | Better UI

= 2.0.0 =
* Complete rewrite with new rule-based UI
* Added support for 7 condition types
* Added support for AND-condition groups per rule

= 1.0.0 =
* Initial release
