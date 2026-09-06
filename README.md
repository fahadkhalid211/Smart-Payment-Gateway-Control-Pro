# Smart-Payment-Gateway-Control
Conditionally disable WooCommerce payment methods based on product, category, cart total, user role, shipping method, country, or order quantity.


Smart Payment Gateway Control gives store owners full control over which payment methods appear at checkout by letting you build simple IF → THEN rules.

= Supported Conditions =
* **Product Category** — hide a gateway when the cart contains a product from a specific category (sub-categories included automatically).
* **Specific Product** — hide a gateway when a particular product is in the cart.
* **Cart Total** — hide a gateway above/below a certain order value.
* **User Role** — restrict payment methods by customer role (Guest, Customer, Wholesale, etc.).
* **Shipping Method** — show/hide gateways based on the chosen shipping method.
* **Billing Country** — control payment options by the customer's country.
* **Order Quantity** — hide gateways when total item count is above or below a threshold.

= Operator Support =
* is / is not
* Greater than / Greater than or equal
* Less than / Less than or equal

= Gateway Support =
Works with **any** payment gateway registered in WooCommerce — Stripe, PayPal, Cash on Delivery, BACS Bank Transfer, and any custom or third-party gateway.

= Use Cases =
* COD available for physical products only
* Stripe only for digital downloads
* Bank transfer only for wholesale customers (role-based)
* Hide PayPal for orders above a certain value
* Restrict payment methods to specific countries

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **WooCommerce → Payment Rules**.
4. Click **Add Rule**, set your condition, and click **Save Rules**.

== Frequently Asked Questions ==

= Does this work with custom / third-party gateways? =
Yes. All gateways registered with WooCommerce are automatically available.

= Do parent-category rules apply to sub-categories? =
Yes. Rules on a parent category automatically trigger for products in child categories.

= Is the plugin HPOS-compatible? =
Yes. Full compatibility with WooCommerce High-Performance Order Storage (HPOS) is declared.


