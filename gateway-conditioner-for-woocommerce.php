<?php
/**
 * Plugin Name:       Gateway Conditioner for WooCommerce
 * Plugin URI:        https://github.com/fahadkhalid211/Gateway-conditioner-for-WooCommerce
 * Description:       Conditionally disable WooCommerce payment methods based on product, category, cart total, user role, shipping method, country, or order quantity.
 * Version:           2.2.0
 * Author:            Fahad Khalid
 * Author URI:        https://linktr.ee/fahadkhalid211
 * Text Domain:       gateway-conditioner-for-woocommerce
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 *
 * @package GatewayConditionerForWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── HPOS & Checkout Blocks Compatibility ─────────────────────────────────────
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// ── Main Plugin Class ────────────────────────────────────────────────────────
if ( ! class_exists( 'GCW_Plugin' ) ) {

	final class GCW_Plugin {

		const OPTION_KEY   = 'gcw_rules';
		const MENU_SLUG    = 'gcw-settings';
		const NONCE_NAME   = 'gcw_nonce';
		const NONCE_ACTION = 'gcw_save_rules';
		const VERSION      = '2.2.0';

		private static $instance = null;

		/**
		 * Runtime memoization cache for product category slugs in the cart.
		 *
		 * @var array
		 */
		private $cat_slugs_cache = array();

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'admin_init', array( $this, 'process_save_rules' ) );
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_gateways' ) );
			add_action( 'wp_ajax_gcw_search_products', array( $this, 'ajax_products' ) );
			add_action( 'wp_ajax_gcw_search_shipping', array( $this, 'ajax_shipping' ) );
		}

		/* =====================================================================
		 * MENU & ASSETS
		 * =================================================================== */

		public function register_menu() {
			add_submenu_page(
				'woocommerce',
				esc_html__( 'Payment Rules', 'gateway-conditioner-for-woocommerce' ),
				'<span class="gcw-menu-item"><span class="dashicons dashicons-shield" style="font-size:16px;line-height:1.4;color:#7dd3fc;margin-right:4px;vertical-align:middle"></span>' . esc_html__( 'Payment Rules', 'gateway-conditioner-for-woocommerce' ) . '</span>',
				'manage_woocommerce',
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
		}

		public function enqueue_assets( $hook ) {
			if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
				return;
			}

			// Enqueue Select2 or WooCommerce selectWoo.
			if ( wp_script_is( 'selectWoo', 'registered' ) ) {
				wp_enqueue_script( 'selectWoo' );
				wp_enqueue_style( 'select2' );
			} else {
				wp_enqueue_script( 'select2' );
				wp_enqueue_style( 'select2' );
			}

			wp_enqueue_script( 'jquery' );

			wp_enqueue_style(
				'gcw-admin',
				plugin_dir_url( __FILE__ ) . 'css/gcw-admin.css',
				array(),
				self::VERSION
			);

			$rules            = $this->get_rules();
			$shipping_methods = $this->get_shipping_methods_list();

			$js_data = array(
				'idx'             => count( $rules ),
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'security'        => wp_create_nonce( 'gcw_ajax' ),
				'opMap'           => $this->get_operators_map(),
				'opLabels'        => $this->get_operator_labels(),
				'gateways'        => $this->get_gateway_options(),
				'categories'      => $this->get_category_options(),
				'roles'           => $this->get_role_options(),
				'countries'       => $this->get_country_options(),
				'shippingMethods' => $shipping_methods,
				'condTypes'       => $this->get_condition_types(),
				'i18n'            => array(
					'rule'        => esc_html__( 'Rule', 'gateway-conditioner-for-woocommerce' ),
					'rules'       => esc_html__( 'rules', 'gateway-conditioner-for-woocommerce' ),
					'ifLabel'     => esc_html__( 'If…', 'gateway-conditioner-for-woocommerce' ),
					'operator'    => esc_html__( 'Operator', 'gateway-conditioner-for-woocommerce' ),
					'value'       => esc_html__( 'Value', 'gateway-conditioner-for-woocommerce' ),
					'thenDisable' => esc_html__( 'Then disable', 'gateway-conditioner-for-woocommerce' ),
					'remove'      => esc_html__( 'Remove rule', 'gateway-conditioner-for-woocommerce' ),
					'removeCond'  => esc_html__( 'Remove condition', 'gateway-conditioner-for-woocommerce' ),
					'andLabel'    => esc_html__( 'AND', 'gateway-conditioner-for-woocommerce' ),
					'addCond'     => esc_html__( 'Add AND Condition', 'gateway-conditioner-for-woocommerce' ),
					'noRules'     => esc_html__( 'No rules yet. Click "Add Rule" to get started.', 'gateway-conditioner-for-woocommerce' ),
					'select'      => esc_html__( '— select —', 'gateway-conditioner-for-woocommerce' ),
					'searchProd'  => esc_html__( 'Search products…', 'gateway-conditioner-for-woocommerce' ),
					'searchShip'  => esc_html__( 'Search shipping methods…', 'gateway-conditioner-for-woocommerce' ),
				),
			);

			wp_enqueue_script(
				'gcw-admin',
				plugin_dir_url( __FILE__ ) . 'js/gcw-admin.js',
				array( 'jquery' ),
				self::VERSION,
				true
			);

			wp_add_inline_script( 'gcw-admin', 'var GCW = ' . wp_json_encode( $js_data ) . ';', 'before' );
		}

		/* =====================================================================
		 * AJAX ENDPOINTS
		 * =================================================================== */

		public function ajax_products() {
			check_ajax_referer( 'gcw_ajax', 'security' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( null, 403 );
			}

			$q       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
			$results = array();

			// If numeric, check if exact product or variation ID exists.
			if ( is_numeric( $q ) ) {
				$exact = wc_get_product( absint( $q ) );
				if ( $exact ) {
					$results[] = array(
						'id'   => $exact->get_id(),
						'text' => wp_strip_all_tags( $exact->get_formatted_name() ),
					);
				}
			}

			// Check SKU.
			$sku_id = wc_get_product_id_by_sku( $q );
			if ( $sku_id && ! in_array( $sku_id, wp_list_pluck( $results, 'id' ), true ) ) {
				$p_sku = wc_get_product( $sku_id );
				if ( $p_sku ) {
					$results[] = array(
						'id'   => $p_sku->get_id(),
						'text' => wp_strip_all_tags( $p_sku->get_formatted_name() ),
					);
				}
			}

			// Search by title/name via query.
			$query = new WP_Query( array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				's'              => $q,
				'posts_per_page' => 20,
				'fields'         => 'ids',
			) );

			foreach ( $query->posts as $id ) {
				if ( in_array( (int) $id, wp_list_pluck( $results, 'id' ), true ) ) {
					continue;
				}
				$p = wc_get_product( $id );
				if ( $p ) {
					$results[] = array(
						'id'   => $id,
						'text' => wp_strip_all_tags( $p->get_formatted_name() ),
					);
				}
			}

			wp_send_json( array( 'results' => $results ) );
		}

		public function ajax_shipping() {
			check_ajax_referer( 'gcw_ajax', 'security' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( null, 403 );
			}

			wp_send_json( array( 'results' => $this->get_shipping_methods_list() ) );
		}

		/* =====================================================================
		 * SAVE RULES & POST-REDIRECT-GET (PRG)
		 * =================================================================== */

		/**
		 * Handles form submission early during admin_init to implement Post-Redirect-Get.
		 */
		public function process_save_rules() {
			if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
				return;
			}

			check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'gateway-conditioner-for-woocommerce' ) );
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized granularly in save_rules().
			$raw_rules = isset( $_POST['gcw_rules'] ) && is_array( $_POST['gcw_rules'] ) ? wp_unslash( $_POST['gcw_rules'] ) : array();

			$this->save_rules( $raw_rules );

			wp_safe_redirect( add_query_arg(
				array(
					'page'      => self::MENU_SLUG,
					'gcw_saved' => '1',
				),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		/**
		 * Save payment rules to database.
		 *
		 * @param array $raw Array of rule rows.
		 */
		private function save_rules( $raw = array() ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			if ( empty( $raw ) || ! is_array( $raw ) ) {
				update_option( self::OPTION_KEY, array(), false );
				return;
			}

			$allowed_types = array_keys( $this->get_condition_types() );
			$op_map        = $this->get_operators_map();
			$clean         = array();

			foreach ( $raw as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$gw = isset( $row['gateway'] ) ? sanitize_text_field( $row['gateway'] ) : '';
				if ( ! $gw ) {
					continue;
				}

				$raw_conds = array();
				if ( ! empty( $row['conditions'] ) && is_array( $row['conditions'] ) ) {
					$raw_conds = $row['conditions'];
				} elseif ( isset( $row['condition_type'] ) ) {
					// Backward compatibility with legacy single-condition rules.
					$raw_conds = array( array(
						'condition_type' => isset( $row['condition_type'] ) ? $row['condition_type'] : '',
						'operator'       => isset( $row['operator'] ) ? $row['operator'] : 'is',
						'value'          => isset( $row['value'] ) ? $row['value'] : '',
					) );
				}

				$clean_conds = array();
				foreach ( $raw_conds as $cond ) {
					$ct = isset( $cond['condition_type'] ) ? sanitize_key( $cond['condition_type'] ) : '';
					$op = isset( $cond['operator'] ) ? sanitize_key( $cond['operator'] ) : 'is';

					// Validate condition type and permitted operators for that condition.
					if ( ! in_array( $ct, $allowed_types, true ) || empty( $op_map[ $ct ] ) || ! in_array( $op, $op_map[ $ct ], true ) ) {
						continue;
					}

					$value = $this->sanitize_value( $ct, isset( $cond['value'] ) ? $cond['value'] : '' );
					if ( '' === $value || array() === $value ) {
						continue;
					}

					$clean_conds[] = array(
						'condition_type' => $ct,
						'operator'       => $op,
						'value'          => $value,
					);
				}

				if ( empty( $clean_conds ) ) {
					continue;
				}

				$clean[] = array(
					'conditions' => $clean_conds,
					'gateway'    => $gw,
				);
			}

			update_option( self::OPTION_KEY, $clean, false );
		}

		private function sanitize_value( $type, $value ) {
			switch ( $type ) {
				case 'cart_total':
					$v = is_array( $value ) ? reset( $value ) : $value;
					return (string) abs( (float) $v );

				case 'quantity':
					$v = is_array( $value ) ? reset( $value ) : $value;
					return (string) absint( $v );

				case 'product':
					return array_values( array_filter( array_map( 'absint', (array) $value ) ) );

				case 'category':
					return array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ) ) );

				case 'user_role':
					$v = is_array( $value ) ? reset( $value ) : $value;
					return sanitize_key( $v );

				case 'shipping_method':
					$v = is_array( $value ) ? reset( $value ) : $value;
					return sanitize_text_field( $v );

				case 'country':
					$v = is_array( $value ) ? reset( $value ) : $value;
					$v = strtoupper( sanitize_text_field( $v ) );
					return preg_match( '/^[A-Z]{2}$/', $v ) ? $v : '';

				default:
					$v = is_array( $value ) ? reset( $value ) : $value;
					return sanitize_text_field( $v );
			}
		}

		/* =====================================================================
		 * ADMIN PAGE RENDERING
		 * =================================================================== */

		public function render_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'gateway-conditioner-for-woocommerce' ) );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading flash status message flag from redirect URL, no form processing or state change.
			$saved            = isset( $_GET['gcw_saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['gcw_saved'] ) );
			$rules            = $this->get_rules();
			$rule_count       = count( $rules );
			$gateways         = $this->get_gateway_options();
			$categories       = $this->get_category_options();
			$roles            = $this->get_role_options();
			$countries        = $this->get_country_options();
			$cond_types       = $this->get_condition_types();
			$op_map           = $this->get_operators_map();
			$op_labels        = $this->get_operator_labels();
			$shipping_methods = $this->get_shipping_methods_list();
			?>
			<div class="wrap" id="gcw-wrap">
			<div id="gcw-outer">

				<!-- ── Hero ───────────────────────────────────────────── -->
				<div class="gcw-hero">
					<div class="gcw-hero-left">
						<div class="gcw-hero-icon">
							<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<rect x="1" y="4" width="22" height="16" rx="3"/>
								<line x1="1" y1="10" x2="23" y2="10"/>
								<line x1="5" y1="15" x2="8" y2="15"/>
								<line x1="11" y1="15" x2="14" y2="15"/>
							</svg>
						</div>
						<div class="gcw-hero-text">
							<h1><?php esc_html_e( 'Gateway Conditioner for WooCommerce', 'gateway-conditioner-for-woocommerce' ); ?></h1>
							<p><?php esc_html_e( 'Hide or restrict payment methods at checkout using flexible IF → THEN rules. Rules are evaluated sequentially in real-time.', 'gateway-conditioner-for-woocommerce' ); ?></p>
						</div>
					</div>
					<div class="gcw-hero-right">
						<div class="gcw-stat">
							<span class="gcw-stat-num" id="gcw-stat-num"><?php echo esc_html( (string) $rule_count ); ?></span>
							<span class="gcw-stat-lbl"><?php esc_html_e( 'Active Rules', 'gateway-conditioner-for-woocommerce' ); ?></span>
						</div>
						<div class="gcw-stat">
							<span class="gcw-stat-num"><?php echo esc_html( (string) count( $cond_types ) ); ?></span>
							<span class="gcw-stat-lbl"><?php esc_html_e( 'Condition Types', 'gateway-conditioner-for-woocommerce' ); ?></span>
						</div>
					</div>
				</div>

				<?php if ( $saved ) : ?>
					<div class="gcw-notice">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
						<span><?php esc_html_e( 'Payment gateway rules saved successfully.', 'gateway-conditioner-for-woocommerce' ); ?></span>
					</div>
				<?php endif; ?>

				<!-- ── Two-column layout ──────────────────────────────── -->
				<div class="gcw-layout">

					<!-- Left: rules editor -->
					<div class="gcw-main">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
							<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

							<div class="gcw-section-header">
								<h2 class="gcw-section-title">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
									<?php esc_html_e( 'Payment Rules', 'gateway-conditioner-for-woocommerce' ); ?>
									<span class="gcw-badge" id="gcw-badge">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: number of rules */
												_n( '%d rule', '%d rules', $rule_count, 'gateway-conditioner-for-woocommerce' ),
												$rule_count
											)
										);
										?>
									</span>
								</h2>
							</div>

							<div id="gcw-rules-list">
								<?php if ( empty( $rules ) ) : ?>
									<div class="gcw-empty" id="gcw-empty-state">
										<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
										<p><?php esc_html_e( 'No rules yet. Click "Add Rule" to get started.', 'gateway-conditioner-for-woocommerce' ); ?></p>
									</div>
								<?php endif; ?>

								<?php
								foreach ( $rules as $i => $rule ) {
									$this->render_rule_card(
										$i,
										$rule,
										$gateways,
										$categories,
										$roles,
										$countries,
										$shipping_methods,
										$cond_types,
										$op_map,
										$op_labels
									);
								}
								?>
							</div>

							<div class="gcw-toolbar">
								<button type="button" id="gcw-add-btn" class="gcw-btn gcw-btn-outline">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
									<?php esc_html_e( 'Add Rule', 'gateway-conditioner-for-woocommerce' ); ?>
								</button>
								<button type="submit" class="gcw-btn gcw-btn-primary">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
									<?php esc_html_e( 'Save Rules', 'gateway-conditioner-for-woocommerce' ); ?>
								</button>
							</div>
						</form>
					</div><!-- .gcw-main -->

					<!-- Right: sidebar -->
					<div class="gcw-sidebar">

						<!-- Reference card -->
						<div class="gcw-card gcw-card-dark">
							<div class="gcw-card-head">
								<h3>
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
									<?php esc_html_e( 'Supported Conditions', 'gateway-conditioner-for-woocommerce' ); ?>
								</h3>
								<p><?php esc_html_e( 'Combine conditions with AND logic to create precise checkout rules.', 'gateway-conditioner-for-woocommerce' ); ?></p>
							</div>

							<div class="gcw-features-list">
								<?php
								$conditions_guide = array(
									array( 'c1', '#7dd3fc', '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>', __( 'Product Category', 'gateway-conditioner-for-woocommerce' ), __( 'Trigger by category — child categories included', 'gateway-conditioner-for-woocommerce' ) ),
									array( 'c2', '#6ee7b7', '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>', __( 'Specific Product', 'gateway-conditioner-for-woocommerce' ), __( 'Target individual products or variations', 'gateway-conditioner-for-woocommerce' ) ),
									array( 'c3', '#fcd34d', '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>', __( 'Cart Total', 'gateway-conditioner-for-woocommerce' ), __( 'Thresholds above, below, or equal to amount', 'gateway-conditioner-for-woocommerce' ) ),
									array( 'c4', '#c4b5fd', '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>', __( 'User Role', 'gateway-conditioner-for-woocommerce' ), __( 'Target logged-in roles or guest shoppers', 'gateway-conditioner-for-woocommerce' ) ),
									array( 'c5', '#fdba74', '<rect x="1" y="3" width="15" height="13" rx="2"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>', __( 'Shipping Method', 'gateway-conditioner-for-woocommerce' ), __( 'Trigger based on the selected shipping rate', 'gateway-conditioner-for-woocommerce' ) ),
									array( 'c6', '#f9a8d4', '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>', __( 'Billing Country', 'gateway-conditioner-for-woocommerce' ), __( 'Restrict gateways by customer billing country', 'gateway-conditioner-for-woocommerce' ) ),
									array( 'c7', '#86efac', '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/>', __( 'Order Quantity', 'gateway-conditioner-for-woocommerce' ), __( 'Evaluate based on total item quantity in cart', 'gateway-conditioner-for-woocommerce' ) ),
								);

								foreach ( $conditions_guide as $cg ) :
									?>
									<div class="gcw-feature-item">
										<div class="gcw-feature-icon <?php echo esc_attr( $cg[0] ); ?>">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?php echo esc_attr( $cg[1] ); ?>" stroke-width="2">
												<?php echo $cg[2]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG path ?>
											</svg>
										</div>
										<div class="gcw-feature-info">
											<strong><?php echo esc_html( $cg[3] ); ?></strong>
											<span><?php echo esc_html( $cg[4] ); ?></span>
										</div>
									</div>
								<?php endforeach; ?>
							</div>

							<div class="gcw-status-box">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
								<span><?php esc_html_e( 'Checkout monitor active & ready', 'gateway-conditioner-for-woocommerce' ); ?></span>
							</div>
						</div>

						<!-- Quick Recipes card -->
						<div class="gcw-card">
							<div class="gcw-card-head">
								<h3>
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0f2554" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
									<?php esc_html_e( 'Common Examples', 'gateway-conditioner-for-woocommerce' ); ?>
								</h3>
								<p><?php esc_html_e( 'Popular use-cases you can configure in seconds:', 'gateway-conditioner-for-woocommerce' ); ?></p>
							</div>
							<div class="gcw-card-body">
								<div class="gcw-recipe-item">
									<span class="gcw-recipe-title"><?php esc_html_e( 'COD for Physical Goods Only', 'gateway-conditioner-for-woocommerce' ); ?></span>
									<span class="gcw-recipe-desc"><?php esc_html_e( 'If Category is "Digital Downloads" → disable Cash on Delivery.', 'gateway-conditioner-for-woocommerce' ); ?></span>
								</div>
								<div class="gcw-recipe-item">
									<span class="gcw-recipe-title"><?php esc_html_e( 'High-Value Orders', 'gateway-conditioner-for-woocommerce' ); ?></span>
									<span class="gcw-recipe-desc"><?php esc_html_e( 'If Cart Total > 1000 → disable COD or Cheque payment.', 'gateway-conditioner-for-woocommerce' ); ?></span>
								</div>
								<div class="gcw-recipe-item">
									<span class="gcw-recipe-title"><?php esc_html_e( 'Wholesale / B2B Exclusive', 'gateway-conditioner-for-woocommerce' ); ?></span>
									<span class="gcw-recipe-desc"><?php esc_html_e( 'If User Role is not "Wholesale" → disable BACS Invoice payment.', 'gateway-conditioner-for-woocommerce' ); ?></span>
								</div>
							</div>
						</div>

					</div><!-- .gcw-sidebar -->

				</div><!-- .gcw-layout -->

			</div><!-- #gcw-outer -->
			</div><!-- #gcw-wrap -->
			<?php
		}

		/* =====================================================================
		 * RENDER SAVED RULE CARD
		 * =================================================================== */

		private function render_rule_card(
			$i,
			$rule,
			$gateways,
			$categories,
			$roles,
			$countries,
			$shipping_methods,
			$cond_types,
			$op_map,
			$op_labels
		) {
			$gw         = isset( $rule['gateway'] ) ? $rule['gateway'] : '';
			$conditions = isset( $rule['conditions'] ) ? (array) $rule['conditions'] : array();
			?>
			<div class="gcw-rule" data-index="<?php echo esc_attr( (string) (int) $i ); ?>">
				<div class="gcw-rule-topbar">
					<span class="gcw-rule-num">
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
						<span class="gcw-rule-num-badge"><?php echo esc_html( (string) ( (int) $i + 1 ) ); ?></span>
						<span class="gcw-rule-num-text">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: rule number */
									__( 'Rule #%d', 'gateway-conditioner-for-woocommerce' ),
									(int) $i + 1
								)
							);
							?>
						</span>
					</span>
					<button type="button" class="gcw-remove" title="<?php esc_attr_e( 'Remove rule', 'gateway-conditioner-for-woocommerce' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
					</button>
				</div>

				<div class="gcw-rule-body">
					<div class="gcw-conditions-wrap">
						<?php
						foreach ( $conditions as $ci => $cond ) :
							$ct  = isset( $cond['condition_type'] ) ? $cond['condition_type'] : 'category';
							$op  = isset( $cond['operator'] ) ? $cond['operator'] : 'is';
							$val = isset( $cond['value'] ) ? $cond['value'] : '';
							$ops = isset( $op_map[ $ct ] ) ? $op_map[ $ct ] : array( 'is', 'is_not' );
							?>
							<?php if ( $ci > 0 ) : ?>
								<div class="gcw-and-separator">
									<span><?php esc_html_e( 'AND', 'gateway-conditioner-for-woocommerce' ); ?></span>
								</div>
							<?php endif; ?>

							<div class="gcw-condition-row" data-rule="<?php echo esc_attr( (string) (int) $i ); ?>" data-cond="<?php echo esc_attr( (string) (int) $ci ); ?>">
								<div class="gcw-rule-grid">
									<div class="gcw-field">
										<label><?php esc_html_e( 'If…', 'gateway-conditioner-for-woocommerce' ); ?></label>
										<select name="<?php echo esc_attr( 'gcw_rules[' . (int) $i . '][conditions][' . (int) $ci . '][condition_type]' ); ?>" class="gcw-ct">
											<?php foreach ( $cond_types as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $ct, $key ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>

									<div class="gcw-field">
										<label><?php esc_html_e( 'Operator', 'gateway-conditioner-for-woocommerce' ); ?></label>
										<select name="<?php echo esc_attr( 'gcw_rules[' . (int) $i . '][conditions][' . (int) $ci . '][operator]' ); ?>" class="gcw-op">
											<?php foreach ( $ops as $ok ) : ?>
												<option value="<?php echo esc_attr( $ok ); ?>" <?php selected( $op, $ok ); ?>>
													<?php echo esc_html( isset( $op_labels[ $ok ] ) ? $op_labels[ $ok ] : $ok ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>

									<div class="gcw-field gcw-val-wrap">
										<label><?php esc_html_e( 'Value', 'gateway-conditioner-for-woocommerce' ); ?></label>
										<?php
										$this->render_value_input(
											$i,
											$ci,
											$ct,
											$val,
											$categories,
											$roles,
											$countries,
											$shipping_methods
										);
										?>
									</div>

									<?php if ( $ci > 0 ) : ?>
										<div class="gcw-field" style="justify-content:flex-end">
											<button type="button" class="gcw-remove-cond" title="<?php esc_attr_e( 'Remove condition', 'gateway-conditioner-for-woocommerce' ); ?>">
												<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
											</button>
										</div>
									<?php else : ?>
										<div></div>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<button type="button" class="gcw-add-cond-btn" data-rule="<?php echo esc_attr( (string) (int) $i ); ?>">
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
						<?php esc_html_e( 'Add AND Condition', 'gateway-conditioner-for-woocommerce' ); ?>
					</button>
				</div>

				<div class="gcw-then-row">
					<div class="gcw-then-arrow">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="5 12 12 19 19 12"/></svg>
					</div>
					<div class="gcw-field gcw-then-gw">
						<div class="gcw-then-label">
							<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 8 13.5 16.5 8 11 2 17"/><polyline points="16 8 22 8 22 14"/></svg>
							<?php esc_html_e( 'Then disable', 'gateway-conditioner-for-woocommerce' ); ?>
						</div>
						<select name="<?php echo esc_attr( 'gcw_rules[' . (int) $i . '][gateway]' ); ?>">
							<option value=""><?php esc_html_e( '— select gateway —', 'gateway-conditioner-for-woocommerce' ); ?></option>
							<?php foreach ( $gateways as $gid => $gtitle ) : ?>
								<option value="<?php echo esc_attr( $gid ); ?>" <?php selected( $gw, $gid ); ?>>
									<?php echo esc_html( $gtitle ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>
			<?php
		}

		private function render_value_input(
			$i,
			$ci,
			$ct,
			$val,
			$categories,
			$roles,
			$countries,
			$shipping_methods
		) {
			$name = 'gcw_rules[' . (int) $i . '][conditions][' . (int) $ci . '][value]';
			$vals = (array) $val;

			switch ( $ct ) {
				case 'category':
					echo '<select name="' . esc_attr( $name ) . '[]" multiple class="gcw-s2-multi">';
					foreach ( $categories as $slug => $label ) {
						$is_sel = in_array( $slug, $vals, true );
						echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $is_sel, true, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
					break;

				case 'product':
					echo '<select name="' . esc_attr( $name ) . '[]" multiple class="gcw-s2-product">';
					foreach ( $vals as $pid ) {
						$pid = absint( $pid );
						$p   = $pid ? wc_get_product( $pid ) : null;
						if ( $p ) {
							echo '<option value="' . esc_attr( (string) $pid ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
						}
					}
					echo '</select>';
					break;

				case 'user_role':
					$current = reset( $vals );
					echo '<select name="' . esc_attr( $name ) . '">';
					foreach ( $roles as $slug => $label ) {
						echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $current, $slug, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
					break;

				case 'country':
					$current = reset( $vals );
					echo '<select name="' . esc_attr( $name ) . '" class="gcw-s2-country">';
					foreach ( $countries as $code => $cname ) {
						echo '<option value="' . esc_attr( $code ) . '" ' . selected( $current, $code, false ) . '>' . esc_html( $cname ) . '</option>';
					}
					echo '</select>';
					break;

				case 'shipping_method':
					$current = reset( $vals );
					echo '<select name="' . esc_attr( $name ) . '" class="gcw-s2-ship">';
					echo '<option value="">' . esc_html__( '— select —', 'gateway-conditioner-for-woocommerce' ) . '</option>';
					foreach ( $shipping_methods as $sm ) {
						$sm_id   = isset( $sm['id'] ) ? $sm['id'] : '';
						$sm_text = isset( $sm['text'] ) ? $sm['text'] : $sm_id;
						echo '<option value="' . esc_attr( $sm_id ) . '" ' . selected( $current, $sm_id, false ) . '>' . esc_html( $sm_text ) . '</option>';
					}
					// If custom or legacy shipping method not in list, preserve option.
					if ( $current && ! in_array( $current, wp_list_pluck( $shipping_methods, 'id' ), true ) ) {
						echo '<option value="' . esc_attr( $current ) . '" selected="selected">' . esc_html( $current ) . '</option>';
					}
					echo '</select>';
					break;

				case 'cart_total':
					$num = reset( $vals );
					echo '<input type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $num ) . '" min="0" step="0.01" placeholder="0.00">';
					break;

				case 'quantity':
					$num = reset( $vals );
					echo '<input type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $num ) . '" min="1" step="1" placeholder="1">';
					break;

				default:
					$num = reset( $vals );
					echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $num ) . '">';
					break;
			}
		}

		/* =====================================================================
		 * FRONT-END GATEWAY FILTERING
		 * =================================================================== */

		public function filter_gateways( $available_gateways ) {
			if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
				return $available_gateways;
			}

			if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
				return $available_gateways;
			}

			$rules = $this->get_rules();
			if ( empty( $rules ) ) {
				return $available_gateways;
			}

			foreach ( $rules as $rule ) {
				$gw_id = isset( $rule['gateway'] ) ? $rule['gateway'] : '';
				if ( ! empty( $gw_id ) && isset( $available_gateways[ $gw_id ] ) && $this->evaluate( $rule ) ) {
					unset( $available_gateways[ $gw_id ] );
				}
			}

			return $available_gateways;
		}

		private function evaluate( $rule ) {
			if ( isset( $rule['conditions'] ) && is_array( $rule['conditions'] ) ) {
				$conditions = $rule['conditions'];
			} elseif ( isset( $rule['condition_type'] ) ) {
				$conditions = array( array(
					'condition_type' => $rule['condition_type'],
					'operator'       => isset( $rule['operator'] ) ? $rule['operator'] : 'is',
					'value'          => isset( $rule['value'] ) ? $rule['value'] : '',
				) );
			} else {
				return false;
			}

			if ( empty( $conditions ) ) {
				return false;
			}

			foreach ( $conditions as $cond ) {
				if ( ! $this->evaluate_condition( $cond ) ) {
					return false;
				}
			}

			return true;
		}

		private function evaluate_condition( $cond ) {
			$ct  = isset( $cond['condition_type'] ) ? $cond['condition_type'] : '';
			$op  = isset( $cond['operator'] ) ? $cond['operator'] : 'is';
			$val = isset( $cond['value'] ) ? $cond['value'] : '';

			switch ( $ct ) {
				case 'category':
					return $this->eval_category( (array) $val, $op );

				case 'product':
					return $this->eval_product( (array) $val, $op );

				case 'cart_total':
					$total = (float) WC()->cart->get_total( 'edit' );
					if ( $total <= 0 ) {
						$total = (float) WC()->cart->get_cart_contents_total();
					}
					return $this->eval_number( $total, $op, (float) $val );

				case 'user_role':
					return $this->eval_role( (string) $val, $op );

				case 'shipping_method':
					return $this->eval_shipping( (string) $val, $op );

				case 'country':
					return $this->eval_country( (string) $val, $op );

				case 'quantity':
					return $this->eval_number( (float) WC()->cart->get_cart_contents_count(), $op, (float) $val );
			}

			return false;
		}

		private function eval_category( $slugs, $op ) {
			$found = false;
			foreach ( WC()->cart->get_cart() as $item ) {
				$pid = absint( isset( $item['product_id'] ) ? $item['product_id'] : 0 );
				if ( $pid && array_intersect( $slugs, $this->product_cat_slugs( $pid ) ) ) {
					$found = true;
					break;
				}
			}
			return 'is_not' === $op ? ! $found : $found;
		}

		private function eval_product( $ids, $op ) {
			$ids   = array_map( 'intval', $ids );
			$found = false;
			foreach ( WC()->cart->get_cart() as $item ) {
				$parent_id    = absint( isset( $item['product_id'] ) ? $item['product_id'] : 0 );
				$variation_id = absint( isset( $item['variation_id'] ) ? $item['variation_id'] : 0 );

				if ( ( $parent_id && in_array( $parent_id, $ids, true ) ) || ( $variation_id && in_array( $variation_id, $ids, true ) ) ) {
					$found = true;
					break;
				}
			}
			return 'is_not' === $op ? ! $found : $found;
		}

		private function eval_number( $actual, $op, $compare ) {
			switch ( $op ) {
				case 'gt':
					return $actual > $compare;
				case 'gte':
					return $actual >= $compare;
				case 'lt':
					return $actual < $compare;
				case 'lte':
					return $actual <= $compare;
				case 'is':
					return abs( $actual - $compare ) < 0.001;
				case 'is_not':
					return abs( $actual - $compare ) >= 0.001;
			}
			return false;
		}

		private function eval_role( $value, $op ) {
			$has = 'guest' === $value ? ! is_user_logged_in() : in_array( $value, (array) wp_get_current_user()->roles, true );
			return 'is_not' === $op ? ! $has : $has;
		}

		private function eval_shipping( $value, $op ) {
			if ( ! WC()->cart->needs_shipping() ) {
				return false;
			}

			$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();
			$has    = false;

			foreach ( $chosen as $chosen_rate ) {
				if ( $chosen_rate === $value ) {
					$has = true;
					break;
				}
				// Also match base method (e.g. 'flat_rate' matches 'flat_rate:1').
				$parts = explode( ':', $chosen_rate );
				if ( ! empty( $parts[0] ) && $parts[0] === $value ) {
					$has = true;
					break;
				}
			}

			return 'is_not' === $op ? ! $has : $has;
		}

		private function eval_country( $value, $op ) {
			$country = '';
			if ( WC()->customer ) {
				$country = (string) WC()->customer->get_billing_country();
				if ( '' === $country ) {
					$country = (string) WC()->customer->get_shipping_country();
				}
			}

			$country = strtoupper( trim( $country ) );
			$has     = ( '' !== $country && $country === strtoupper( $value ) );

			return 'is_not' === $op ? ! $has : $has;
		}

		/* =====================================================================
		 * DATA HELPERS
		 * =================================================================== */

		public function get_rules() {
			$rules = (array) get_option( self::OPTION_KEY, array() );
			foreach ( $rules as $i => $rule ) {
				if ( isset( $rule['condition_type'] ) && ! isset( $rule['conditions'] ) ) {
					$rules[ $i ] = array(
						'conditions' => array( array(
							'condition_type' => $rule['condition_type'],
							'operator'       => isset( $rule['operator'] ) ? $rule['operator'] : 'is',
							'value'          => isset( $rule['value'] ) ? $rule['value'] : '',
						) ),
						'gateway'    => isset( $rule['gateway'] ) ? $rule['gateway'] : '',
					);
				}
			}
			return $rules;
		}

		/**
		 * Retrieve product category slugs including all ancestors, with per-request memoization.
		 *
		 * @param int $pid Product ID.
		 * @return array Array of category slugs.
		 */
		private function product_cat_slugs( $pid ) {
			if ( isset( $this->cat_slugs_cache[ $pid ] ) ) {
				return $this->cat_slugs_cache[ $pid ];
			}

			$slugs = array();
			$terms = get_the_terms( $pid, 'product_cat' );

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$slugs[] = $term->slug;
					foreach ( get_ancestors( $term->term_id, 'product_cat' ) as $anc_id ) {
						$anc = get_term( $anc_id, 'product_cat' );
						if ( $anc instanceof WP_Term ) {
							$slugs[] = $anc->slug;
						}
					}
				}
			}

			$this->cat_slugs_cache[ $pid ] = array_unique( $slugs );
			return $this->cat_slugs_cache[ $pid ];
		}

		private function get_gateway_options() {
			$out = array();
			if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
				foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) {
					$title    = $gw->get_title();
					$out[ $id ] = $title ? $title : $id;
				}
			}
			return $out;
		}

		private function get_category_options() {
			$out   = array();
			$terms = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			) );

			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$out[ $t->slug ] = $t->name;
				}
			}
			return $out;
		}

		private function get_role_options() {
			global $wp_roles;
			$out = array(
				'guest' => esc_html__( 'Guest (not logged in)', 'gateway-conditioner-for-woocommerce' ),
			);

			if ( ! empty( $wp_roles->roles ) && is_array( $wp_roles->roles ) ) {
				foreach ( $wp_roles->roles as $slug => $data ) {
					$out[ $slug ] = translate_user_role( $data['name'] );
				}
			}
			return $out;
		}

		private function get_country_options() {
			if ( function_exists( 'WC' ) && WC()->countries ) {
				return WC()->countries->get_countries();
			}
			return array();
		}

		public function get_shipping_methods_list() {
			$methods = array();
			if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
				return $methods;
			}

			foreach ( WC_Shipping_Zones::get_zones() as $zd ) {
				$zone = new WC_Shipping_Zone( $zd['zone_id'] );
				foreach ( $zone->get_shipping_methods( true ) as $inst ) {
					$methods[] = array(
						'id'   => $inst->get_rate_id(),
						'text' => $zd['zone_name'] . ' — ' . $inst->get_title(),
					);
				}
			}

			$z0 = new WC_Shipping_Zone( 0 );
			foreach ( $z0->get_shipping_methods( true ) as $inst ) {
				$methods[] = array(
					'id'   => $inst->get_rate_id(),
					'text' => esc_html__( 'Rest of World', 'gateway-conditioner-for-woocommerce' ) . ' — ' . $inst->get_title(),
				);
			}

			return $methods;
		}

		private function get_condition_types() {
			return array(
				'category'        => esc_html__( 'Product Category', 'gateway-conditioner-for-woocommerce' ),
				'product'         => esc_html__( 'Specific Product', 'gateway-conditioner-for-woocommerce' ),
				'cart_total'      => esc_html__( 'Cart Total', 'gateway-conditioner-for-woocommerce' ),
				'user_role'       => esc_html__( 'User Role', 'gateway-conditioner-for-woocommerce' ),
				'shipping_method' => esc_html__( 'Shipping Method', 'gateway-conditioner-for-woocommerce' ),
				'country'         => esc_html__( 'Billing Country', 'gateway-conditioner-for-woocommerce' ),
				'quantity'        => esc_html__( 'Order Quantity', 'gateway-conditioner-for-woocommerce' ),
			);
		}

		private function get_operators_map() {
			return array(
				'category'        => array( 'is', 'is_not' ),
				'product'         => array( 'is', 'is_not' ),
				'cart_total'      => array( 'gt', 'gte', 'lt', 'lte', 'is', 'is_not' ),
				'user_role'       => array( 'is', 'is_not' ),
				'shipping_method' => array( 'is', 'is_not' ),
				'country'         => array( 'is', 'is_not' ),
				'quantity'        => array( 'gt', 'gte', 'lt', 'lte', 'is', 'is_not' ),
			);
		}

		private function get_operator_labels() {
			return array(
				'is'     => esc_html__( 'is', 'gateway-conditioner-for-woocommerce' ),
				'is_not' => esc_html__( 'is not', 'gateway-conditioner-for-woocommerce' ),
				'gt'     => '>',
				'gte'    => '>=',
				'lt'     => '<',
				'lte'    => '<=',
			);
		}

	}

}

add_action( 'plugins_loaded', static function () {
	if ( ! defined( 'WC_VERSION' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Gateway Conditioner for WooCommerce requires WooCommerce to be installed and active.', 'gateway-conditioner-for-woocommerce' );
			echo '</p></div>';
		} );
		return;
	}
	GCW_Plugin::get_instance();
}, 20 );

// Add Settings link on Plugins page.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), static function ( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=gcw-settings' ) ) . '">' . esc_html__( 'Settings', 'gateway-conditioner-for-woocommerce' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
} );