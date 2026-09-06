=== Smart Payment Gateway Control for WooCommerce ===
Contributors: fahadkhalid211
Tags: woocommerce, payment gateway, checkout, conditional, payment methods
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conditionally hide WooCommerce payment methods at checkout using flexible IF → THEN rules based on product, cart, user, country, and more.

== Description ==

Smart Payment Gateway Control for WooCommerce lets you define IF → THEN rules to hide payment gateways at checkout. Rules are evaluated in order and support a wide range of conditions:

* Product Category — show or hide a gateway based on product categories in the cart
* Specific Product — target individual products or product variations
* Cart Total — apply rules when the order value is above, below, or equal to a threshold
* User Role — target logged-in roles or guests
* Shipping Method — trigger rules based on the chosen shipping method
* Billing Country — restrict payment options by country
* Order Quantity — apply rules based on total item count in the cart

All rules are managed from a clean admin UI under **WooCommerce → Payment Rules**.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to WooCommerce → Payment Rules to create your first rule.

== Frequently Asked Questions ==

= Does this work with HPOS (High-Performance Order Storage)? =
Yes, the plugin declares full compatibility with WooCommerce HPOS.

= Which WooCommerce versions are supported? =
WooCommerce 6.0 and above.

= Can I have multiple rules? =
Yes. You can create as many rules as you need. Rules are evaluated in the order they appear.

== Changelog ==

= 2.0.1 =
* CSS changes | Better UI

= 2.0.0 =
* Complete rewrite with new rule-based UI
* Added support for 7 condition types
* Added support for AND-condition groups per rule

= 1.0.0 =
* Initial release
