<?php

/**
 * Plugin Name: Smart Payment Gateway Control for WooCommerce Pro
 * Plugin URI:        https://github.com/fahadkhalid211/Smart-Payment-Gateway-Control-Pro
 * Description:       Conditionally disable WooCommerce payment methods based on product, category, cart total, user role, shipping method, country, or order quantity.
 * Version:           2.2.0
 * Author:            Fahad Khalid
 * Author URI:        https://linktr.ee/fahadkhalid211
 * Text Domain:       smart-payment-gateway-control-pro
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Tested up to:      6.9
 * Requires Plugins:  woocommerce
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 *
 * @package SmartPaymentGatewayControlPro
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// ── Uninstall cleanup ─────────────────────────────────────────────────────────
register_uninstall_hook( __FILE__, 'spgc_uninstall_cleanup' );
if ( !function_exists( 'spgc_uninstall_cleanup' ) ) {
    function spgc_uninstall_cleanup() {
        delete_option( 'spgc_rules' );
    }
}
    // ── HPOS compatibility ────────────────────────────────────────────────────
    add_action( 'before_woocommerce_init', static function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    } );
    // ── Main plugin class ─────────────────────────────────────────────────────
    if ( !class_exists( 'SPGC_Plugin' ) ) {
        final class SPGC_Plugin {
            const OPTION_KEY = 'spgc_rules';

            const MENU_SLUG = 'spgc-settings';

            const NONCE_NAME = 'spgc_nonce';

            const NONCE_ACTION = 'spgc_save_rules';

            const VERSION = '2.2.0';

            private static $instance = null;

            public static function get_instance() {
                if ( null === self::$instance ) {
                    self::$instance = new self();
                }
                return self::$instance;
            }

            private function __construct() {
                add_action( 'admin_menu', array($this, 'register_menu') );
                add_action( 'admin_enqueue_scripts', array($this, 'enqueue_assets') );
                add_filter( 'woocommerce_available_payment_gateways', array($this, 'filter_gateways') );
                add_action( 'wp_ajax_spgc_search_products', array($this, 'ajax_products') );
                add_action( 'wp_ajax_spgc_search_shipping', array($this, 'ajax_shipping') );
            }

            /* =====================================================================
             * PRO CHECK
             * ===================================================================
             * Unconditionally returns true — all features are permanently unlocked.
             * =================================================================== */
            public static function is_pro() {
                return true;
            }

            /**
             * Returns the settings URL.
             *
             * @return string
             */
            public static function get_upgrade_url() {
                return admin_url( 'admin.php?page=spgc-settings' );
            }

            /* =====================================================================
             * MENU & ASSETS
             * =================================================================== */
            public function register_menu() {
                add_submenu_page(
                    'woocommerce',
                    esc_html__( 'Payment Rules', 'smart-payment-gateway-control-pro' ),
                    '<span class="spgc-menu-item"><span class="dashicons dashicons-shield" style="font-size:16px;line-height:1.4;color:#7dd3fc;margin-right:4px;vertical-align:middle"></span>' . esc_html__( 'Payment Rules', 'smart-payment-gateway-control-pro' ) . '</span>',
                    'manage_woocommerce',
                    self::MENU_SLUG,
                    array($this, 'render_page')
                );
            }

            public function enqueue_assets( $hook ) {
                if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
                    return;
                }
                wp_enqueue_script( 'select2' );
                wp_enqueue_script( 'jquery' );
                wp_enqueue_style(
                    'spgc-admin',
                    plugin_dir_url( __FILE__ ) . 'css/spgc-admin.css',
                    array(),
                    self::VERSION
                );
                $css = '
			/* ── Reset & base ─────────────────────────── */
			#spgc-wrap *{box-sizing:border-box}
			#spgc-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}

			/* ── Layout shell ─────────────────────────── */
			#spgc-outer{max-width:1200px;padding:0 0 40px}
			.spgc-layout{display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start}
			@media(max-width:1024px){.spgc-layout{grid-template-columns:1fr}}

			/* ── Hero banner ──────────────────────────── */
			.spgc-hero{background:linear-gradient(135deg,#0f2554 0%,#1a3a7a 60%,#1e4799 100%);border-radius:16px;padding:28px 32px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;position:relative;overflow:hidden}
			.spgc-hero::before{content:"";position:absolute;top:-40px;right:-40px;width:200px;height:200px;background:rgba(255,255,255,.04);border-radius:50%}
			.spgc-hero::after{content:"";position:absolute;bottom:-60px;right:60px;width:140px;height:140px;background:rgba(255,255,255,.03);border-radius:50%}
			.spgc-hero-left{display:flex;align-items:center;gap:18px;position:relative;z-index:1}
			.spgc-hero-icon{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:14px;width:56px;height:56px;display:flex;align-items:center;justify-content:center;flex-shrink:0;backdrop-filter:blur(4px)}
			.spgc-hero-text h1{margin:0 0 4px;font-size:1.45rem;font-weight:800;color:#fff;letter-spacing:-.01em}
			.spgc-hero-text p{margin:0;font-size:.825rem;color:rgba(255,255,255,.65);max-width:440px;line-height:1.5}
			.spgc-hero-right{display:flex;align-items:center;gap:12px;position:relative;z-index:1;flex-wrap:wrap}
			.spgc-stat{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:12px;padding:10px 18px;text-align:center;backdrop-filter:blur(4px)}
			.spgc-stat-num{font-size:1.6rem;font-weight:800;color:#fff;line-height:1;display:block}
			.spgc-stat-lbl{font-size:.7rem;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.06em;margin-top:2px;display:block}

			/* ── Notices ──────────────────────────────── */
			.spgc-notice{display:flex;align-items:center;gap:10px;background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:12px 18px;border-radius:10px;margin-bottom:20px;font-size:.875rem;font-weight:500}
			.spgc-notice svg{flex-shrink:0}

			/* ── Limit banner ─────────────────────────── */
			.spgc-limit-banner{display:flex;align-items:center;gap:12px;background:#fffbeb;border:1.5px solid #fcd34d;border-radius:12px;padding:14px 18px;margin-bottom:16px}
			.spgc-limit-banner svg{flex-shrink:0;color:#f59e0b}
			.spgc-limit-banner-text{flex:1}
			.spgc-limit-banner-text strong{display:block;font-size:.85rem;font-weight:700;color:#92400e;margin-bottom:2px}
			.spgc-limit-banner-text span{font-size:.78rem;color:#b45309}
			.spgc-limit-banner a{display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border-radius:8px;padding:7px 14px;font-size:.78rem;font-weight:700;text-decoration:none;white-space:nowrap;transition:all .15s}
			.spgc-limit-banner a:hover{background:linear-gradient(135deg,#d97706,#b45309);color:#fff}

			/* ── Sidebar ──────────────────────────────── */
			.spgc-sidebar{}
			.spgc-features-card{background:#0f2554;border-radius:14px;overflow:hidden;position:sticky;top:32px}
			.spgc-features-head{padding:18px 20px 14px;border-bottom:1px solid rgba(255,255,255,.1)}
			.spgc-features-head h3{margin:0 0 2px;font-size:.95rem;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px}
			.spgc-features-head p{margin:0;font-size:.75rem;color:rgba(255,255,255,.5);line-height:1.4}

			/* ── Plan pills ───────────────────────────── */
			.spgc-plan-pill{display:inline-flex;align-items:center;gap:4px;font-size:.62rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;padding:2px 9px;border-radius:20px;line-height:1.6}
			.spgc-plan-pill.free{background:rgba(125,211,252,.18);color:#7dd3fc;border:1px solid rgba(125,211,252,.3)}
			.spgc-plan-pill.pro{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none}

			/* ── Feature section labels ───────────────── */
			.spgc-feat-divider{padding:8px 20px 4px;font-size:.64rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.3)}

			/* ── Feature items ────────────────────────── */
			.spgc-features-list{padding:4px 0}
			.spgc-feature-item{display:flex;align-items:flex-start;gap:12px;padding:10px 20px;transition:background .15s;cursor:default}
			.spgc-feature-item:hover{background:rgba(255,255,255,.05)}
			.spgc-feature-item.is-pro-item{opacity:.72}
			.spgc-feature-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
			.spgc-feature-icon.c1{background:rgba(125,211,252,.15)}
			.spgc-feature-icon.c2{background:rgba(167,243,208,.15)}
			.spgc-feature-icon.c3{background:rgba(253,224,132,.15)}
			.spgc-feature-icon.c4{background:rgba(196,181,253,.15)}
			.spgc-feature-icon.c5{background:rgba(251,191,136,.15)}
			.spgc-feature-icon.c6{background:rgba(249,168,212,.15)}
			.spgc-feature-icon.c7{background:rgba(134,239,172,.15)}
			.spgc-feature-icon.c8{background:rgba(125,211,252,.1)}
			.spgc-feature-info{flex:1;min-width:0}
			.spgc-feature-info strong{display:block;font-size:.8rem;font-weight:600;color:#fff;line-height:1.3}
			.spgc-feature-info span{font-size:.72rem;color:rgba(255,255,255,.45);line-height:1.4;display:block;margin-top:1px}
			.spgc-feature-badge{flex-shrink:0;margin-top:2px}

			/* ── Upgrade CTA ──────────────────────────── */
			.spgc-upgrade-cta{padding:16px 20px;border-top:1px solid rgba(255,255,255,.1);}
			.spgc-upgrade-btn{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:11px 16px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:10px;font-size:.85rem;font-weight:700;cursor:pointer;text-decoration:none;letter-spacing:.01em;transition:all .15s;line-height:1}
			.spgc-upgrade-btn:hover{background:linear-gradient(135deg,#d97706,#b45309);color:#fff;box-shadow:0 4px 14px rgba(245,158,11,.4)}
			.spgc-upgrade-note{margin:8px 0 0;font-size:.68rem;color:rgba(255,255,255,.8);text-align:center;line-height:1.5}

			/* ── Trial note ───────────────────────────── */
			.spgc-trial-note{padding:8px 20px 14px;text-align:center}
			.spgc-trial-note p{margin:0;font-size:.68rem;color:rgba(255,255,255,.9);line-height:1.5}

			/* ── Pro active indicator ─────────────────── */
			.spgc-pro-active{display:flex;align-items:center;gap:8px;padding:14px 20px;border-top:1px solid rgba(255,255,255,.08);background:rgba(110,231,183,.06)}
			.spgc-pro-active svg{flex-shrink:0;color:#6ee7b7}
			.spgc-pro-active span{font-size:.78rem;font-weight:600;color:#6ee7b7}

			/* ── Main content area ────────────────────── */
			.spgc-main{}
			.spgc-section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px}
			.spgc-section-title{font-size:.95rem;font-weight:700;color:#0f2554;display:flex;align-items:center;gap:7px;margin:0}
			.spgc-section-title svg{color:#7c3aed}
			.spgc-badge{background:#0f2554;color:#7dd3fc;border-radius:20px;font-size:.68rem;font-weight:700;padding:3px 11px;letter-spacing:.04em;border:1px solid rgba(125,211,252,.3)}

			/* ── Empty state ──────────────────────────── */
			.spgc-empty{text-align:center;padding:48px 24px;border:2px dashed #dde3f0;border-radius:14px;color:#94a3b8;margin-bottom:12px;background:#fafbff}
			.spgc-empty p{margin:10px 0 0;font-size:.875rem}

			/* ── Rule card ────────────────────────────── */
			.spgc-rule{background:#fff;border:1.5px solid #e8edf5;border-radius:14px;padding:0;margin-bottom:12px;box-shadow:0 2px 8px rgba(15,37,84,.06);transition:box-shadow .2s,border-color .2s;overflow:hidden}
			.spgc-rule:hover{box-shadow:0 6px 24px rgba(15,37,84,.1);border-color:#c7d7f0}
			.spgc-rule-topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:linear-gradient(135deg,#f8faff,#f1f5fd);border-bottom:1px solid #edf1fa}
			.spgc-conditions-wrap{padding:18px 18px 0}
			.spgc-rule-num{font-size:.68rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#0f2554;display:flex;align-items:center;gap:6px}
			.spgc-rule-num-badge{background:#0f2554;color:#fff;border-radius:6px;padding:2px 8px;font-size:.65rem}
			.spgc-rule-body{padding:18px 18px 0}
			.spgc-remove{background:none;border:none;cursor:pointer;color:#cbd5e1;padding:5px;border-radius:7px;display:flex;align-items:center;transition:color .15s,background .15s}
			.spgc-remove:hover{color:#ef4444;background:#fef2f2}

			/* ── Fields ───────────────────────────────── */
			.spgc-field{display:flex;flex-direction:column;gap:5px}
			.spgc-field label{font-size:.67rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b}
			.spgc-field select,.spgc-field input[type="number"]{height:40px;padding:0 12px;border:1.5px solid #dde3ef;border-radius:9px;font-size:.875rem;background:#fff;color:#1e293b;width:100%;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%2394a3b8\' stroke-width=\'2.5\'%3E%3Cpath d=\'m6 9 6 6 6-6\'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:32px;transition:border-color .15s,box-shadow .15s}
			.spgc-field input[type="number"]{background-image:none;padding-right:12px}
			.spgc-field select:focus,.spgc-field input[type="number"]:focus{border-color:#1a3a7a;outline:none;box-shadow:0 0 0 3px rgba(15,37,84,.1)}

			/* ── AND separator ────────────────────────── */
			.spgc-and-separator{display:flex;align-items:center;gap:8px;margin:8px 0}
			.spgc-and-separator span{background:#0f2554;color:#7dd3fc;font-size:.65rem;font-weight:800;letter-spacing:.1em;padding:2px 10px;border-radius:20px;text-transform:uppercase}
			.spgc-and-separator::before,.spgc-and-separator::after{content:"";flex:1;height:1px;background:#e8edf5}
			.spgc-condition-row{margin-bottom:4px}
			.spgc-rule-grid{display:grid;grid-template-columns:minmax(160px,1fr) 120px minmax(180px,1.4fr) 36px;gap:10px;align-items:end}
			@media(max-width:860px){.spgc-rule-grid{grid-template-columns:1fr 1fr;gap:10px}}
			.spgc-remove-cond{background:none;border:none;cursor:pointer;color:#cbd5e1;padding:5px 6px;border-radius:7px;display:flex;align-items:center;transition:color .15s,background .15s;margin-bottom:4px}
			.spgc-remove-cond:hover{color:#ef4444;background:#fef2f2}

			/* ── Add condition btn ────────────────────── */
			.spgc-add-cond-btn{display:inline-flex;align-items:center;gap:5px;margin:10px;padding:5px 13px;border:1.5px dashed #93b4d8;border-radius:8px;background:none;color:#1a3a7a;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s}
			.spgc-add-cond-btn:hover{background:#eef3fc;border-color:#1a3a7a}

			/* ── Then row ─────────────────────────────── */
			.spgc-then-row{display:flex;align-items:center;gap:14px;margin-top:14px;padding:14px 18px;background:linear-gradient(135deg,#f8faff,#eef3fc);border-top:1px solid #e8edf5}
			.spgc-then-arrow{color:#94a3b8;flex-shrink:0}
			.spgc-then-gw{flex:1}
			.spgc-then-label{display:inline-flex;align-items:center;gap:5px;background:#0f2554;color:#7dd3fc;font-size:.67rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:3px 10px;border-radius:20px;margin-bottom:5px}

			/* ── Toolbar ──────────────────────────────── */
			.spgc-toolbar{display:flex;gap:10px;margin-top:20px;align-items:center;padding-top:20px;border-top:1.5px solid #e8edf5;flex-wrap:wrap}
			.spgc-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:10px;font-size:.875rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;text-decoration:none}
			.spgc-btn-outline{background:#fff;color:#0f2554;border:1.5px solid #c0cfe8}
			.spgc-btn-outline:hover{background:#f0f5ff;border-color:#0f2554;color:#0f2554}
			.spgc-btn-outline[disabled],.spgc-btn-outline.is-disabled{opacity:.5;cursor:not-allowed;pointer-events:none}
			.spgc-btn-primary{background:linear-gradient(135deg,#0f2554 0%,#1a3a7a 100%);color:#fff;box-shadow:0 3px 10px rgba(15,37,84,.3)}
			.spgc-btn-primary:hover{background:linear-gradient(135deg,#0c1e44,#152f63);box-shadow:0 5px 16px rgba(15,37,84,.4);color:#fff}
			.spgc-btn-upgrade{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;box-shadow:0 3px 10px rgba(245,158,11,.3)}
			.spgc-btn-upgrade:hover{background:linear-gradient(135deg,#d97706,#b45309);color:#fff;box-shadow:0 5px 16px rgba(245,158,11,.4)}

			/* ── Pro-locked option style ──────────────── */
			.spgc-ct option.is-pro-opt{color:#94a3b8}

			/* ── Select2 overrides ────────────────────── */
			.spgc-field .select2-container{width:100%!important}
			.spgc-field .select2-container--default .select2-selection--single,.spgc-field .select2-container--default .select2-selection--multiple{height:auto!important;min-height:40px!important;border:1.5px solid #dde3ef!important;border-radius:9px!important;background:#fff!important;padding:2px 6px!important}
			.spgc-field .select2-container--default.select2-container--focus .select2-selection--single,.spgc-field .select2-container--default.select2-container--focus .select2-selection--multiple{border-color:#1a3a7a!important;box-shadow:0 0 0 3px rgba(15,37,84,.1)!important;outline:none!important}
			.spgc-field .select2-container--default .select2-selection--single .select2-selection__rendered{line-height:36px!important;padding-left:6px!important;color:#1e293b!important;font-size:.875rem!important}
			.spgc-field .select2-container--default .select2-selection--single .select2-selection__arrow{height:38px!important;right:6px!important}
			.spgc-field .select2-container--default .select2-selection--multiple .select2-selection__choice{background:#0f2554!important;border:none!important;color:#7dd3fc!important;border-radius:6px!important;padding:2px 8px!important;font-size:.75rem!important;margin:3px 3px 3px 0!important}
			.spgc-field .select2-container--default .select2-selection--multiple .select2-selection__choice__remove{color:rgba(125,211,252,.6)!important;margin-right:4px!important}
			.spgc-field .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover{color:#7dd3fc!important}
			.spgc-field .select2-container--default .select2-selection--multiple .select2-search__field{font-size:.875rem!important;margin-top:4px!important}
			.select2-dropdown{border:1.5px solid #dde3ef!important;border-radius:12px!important;box-shadow:0 10px 30px rgba(15,37,84,.12)!important;overflow:hidden}
			.select2-results__option{font-size:.875rem!important;padding:9px 14px!important}
			.select2-container--default .select2-results__option--highlighted{background:#0f2554!important}
			';
                wp_add_inline_style( 'spgc-admin', $css );
                $is_pro = self::is_pro();
                $rules = $this->get_rules();
                $upgrade_url = self::get_upgrade_url();
                $js_data = 'var SPGC = ' . wp_json_encode( array(
                    'idx'           => count( $rules ),
                    'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                    'security'      => wp_create_nonce( 'spgc_ajax' ),
                    'opMap'         => $this->get_operators_map(),
                    'opLabels'      => $this->get_operator_labels(),
                    'gateways'      => $this->get_gateway_options(),
                    'categories'    => $this->get_category_options(),
                    'roles'         => $this->get_role_options(),
                    'countries'     => $this->get_country_options(),
                    'condTypes'     => $this->get_condition_types(),
                    'freeCondTypes' => array_keys( $this->get_condition_types() ),
                    'freeRuleLimit' => 999999,
                    'isPro'         => true,
                    'upgradeUrl'    => $upgrade_url,
                    'i18n'          => array(
                        'rule'         => esc_html__( 'Rule', 'smart-payment-gateway-control-pro' ),
                        'rules'        => esc_html__( 'rules', 'smart-payment-gateway-control-pro' ),
                        'ifLabel'      => esc_html__( 'If…', 'smart-payment-gateway-control-pro' ),
                        'operator'     => esc_html__( 'Operator', 'smart-payment-gateway-control-pro' ),
                        'value'        => esc_html__( 'Value', 'smart-payment-gateway-control-pro' ),
                        'thenDisable'  => esc_html__( 'Then disable', 'smart-payment-gateway-control-pro' ),
                        'remove'       => esc_html__( 'Remove rule', 'smart-payment-gateway-control-pro' ),
                        'removeCond'   => esc_html__( 'Remove condition', 'smart-payment-gateway-control-pro' ),
                        'andLabel'     => esc_html__( 'AND', 'smart-payment-gateway-control-pro' ),
                        'addCond'      => esc_html__( 'Add AND Condition', 'smart-payment-gateway-control-pro' ),
                        'noRules'      => esc_html__( 'No rules yet. Click "Add Rule" to get started.', 'smart-payment-gateway-control-pro' ),
                        'select'       => esc_html__( '— select —', 'smart-payment-gateway-control-pro' ),
                        'searchProd'   => esc_html__( 'Search products…', 'smart-payment-gateway-control-pro' ),
                        'searchShip'   => esc_html__( 'Search shipping methods…', 'smart-payment-gateway-control-pro' ),
                        'badge'        => esc_html__( 'rules', 'smart-payment-gateway-control-pro' ),
                        'limitReached' => '',
                        'upgradeCta'   => '',
                        'proSuffix'    => '',
                    ),
                ) ) . ';';
                wp_enqueue_script(
                    'spgc-admin',
                    plugin_dir_url( __FILE__ ) . 'js/spgc-admin.js',
                    array('jquery', 'select2'),
                    self::VERSION,
                    true
                );
                wp_add_inline_script( 'spgc-admin', $js_data, 'before' );
            }

            /* =====================================================================
             * AJAX
             * =================================================================== */
            public function ajax_products() {
                check_ajax_referer( 'spgc_ajax', 'security' );
                if ( !current_user_can( 'manage_woocommerce' ) ) {
                    wp_send_json_error( null, 403 );
                }
                $q = ( isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '' );
                $query = new WP_Query(array(
                    'post_type'      => array('product', 'product_variation'),
                    'post_status'    => 'publish',
                    's'              => $q,
                    'posts_per_page' => 20,
                    'fields'         => 'ids',
                ));
                $results = array();
                foreach ( $query->posts as $id ) {
                    $p = wc_get_product( $id );
                    if ( $p ) {
                        $results[] = array(
                            'id'   => $id,
                            'text' => wp_strip_all_tags( $p->get_formatted_name() ),
                        );
                    }
                }
                wp_send_json( array(
                    'results' => $results,
                ) );
            }

            public function ajax_shipping() {
                check_ajax_referer( 'spgc_ajax', 'security' );
                if ( !current_user_can( 'manage_woocommerce' ) ) {
                    wp_send_json_error( null, 403 );
                }
                $methods = array();
                foreach ( WC_Shipping_Zones::get_zones() as $zd ) {
                    $zone = new WC_Shipping_Zone($zd['zone_id']);
                    foreach ( $zone->get_shipping_methods( true ) as $inst ) {
                        $methods[] = array(
                            'id'   => $inst->get_rate_id(),
                            'text' => $zd['zone_name'] . ' — ' . $inst->get_title(),
                        );
                    }
                }
                $z0 = new WC_Shipping_Zone(0);
                foreach ( $z0->get_shipping_methods( true ) as $inst ) {
                    $methods[] = array(
                        'id'   => $inst->get_rate_id(),
                        'text' => esc_html__( 'Rest of World', 'smart-payment-gateway-control-pro' ) . ' — ' . $inst->get_title(),
                    );
                }
                wp_send_json( array(
                    'results' => $methods,
                ) );
            }

            /* =====================================================================
             * ADMIN PAGE
             * =================================================================== */
            public function render_page() {
                if ( !current_user_can( 'manage_woocommerce' ) ) {
                    wp_die( esc_html__( 'No permission.', 'smart-payment-gateway-control-pro' ) );
                }
                $saved = false;
                if ( isset( $_POST[self::NONCE_NAME] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[self::NONCE_NAME] ) ), self::NONCE_ACTION ) ) {
                    $this->save_rules();
                    $saved = true;
                }
                $is_pro = true;
                $upgrade_url = self::get_upgrade_url();
                $rules = $this->get_rules();
                $rule_count = count( $rules );
                $at_limit = false;
                $gateways = $this->get_gateway_options();
                $categories = $this->get_category_options();
                $roles = $this->get_role_options();
                $countries = $this->get_country_options();
                $cond_types = $this->get_condition_types();
                $op_map = $this->get_operators_map();
                $op_labels = $this->get_operator_labels();
                ?>
			<div class="wrap" id="spgc-wrap">
			<div id="spgc-outer">

				<!-- ── Hero ───────────────────────────────────────────── -->
				<div class="spgc-hero">
					<div class="spgc-hero-left">
						<div class="spgc-hero-icon">
							<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<rect x="1" y="4" width="22" height="16" rx="3"/>
								<line x1="1" y1="10" x2="23" y2="10"/>
								<line x1="5" y1="15" x2="8" y2="15"/>
								<line x1="11" y1="15" x2="14" y2="15"/>
							</svg>
						</div>
						<div class="spgc-hero-text">
							<h1><?php 
                esc_html_e( 'Smart Payment Gateway Control', 'smart-payment-gateway-control-pro' );
                ?></h1>
							<p><?php 
                esc_html_e( 'Hide payment methods at checkout using flexible IF &#8594; THEN rules. Rules are evaluated in order — full control, zero code.', 'smart-payment-gateway-control-pro' );
                ?></p>
						</div>
					</div>
					<div class="spgc-hero-right">
						<div class="spgc-stat">
							<span class="spgc-stat-num" id="spgc-stat-num"><?php 
                echo esc_html( $rule_count );
                ?></span>
							<span class="spgc-stat-lbl"><?php 
                esc_html_e( 'Active Rules', 'smart-payment-gateway-control-pro' );
                ?></span>
						</div>
						<div class="spgc-stat">
							<span class="spgc-stat-num">7</span>
							<span class="spgc-stat-lbl"><?php 
                esc_html_e( 'Condition Types', 'smart-payment-gateway-control-pro' );
                ?></span>
						</div>
					</div>
				</div>

				<?php 
                if ( $saved ) {
                    ?>
				<div class="spgc-notice">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
					<?php 
                    esc_html_e( 'Rules saved successfully.', 'smart-payment-gateway-control-pro' );
                    ?>
				</div>
				<?php 
                }
                ?>

				<!-- ── Two-column layout ──────────────────────────────── -->
				<div class="spgc-layout">

					<!-- Left: rules editor -->
					<div class="spgc-main">
						<form method="post">
							<?php 
                wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
                ?>



							<div class="spgc-section-header">
								<h2 class="spgc-section-title">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
									<?php 
                esc_html_e( 'Payment Rules', 'smart-payment-gateway-control-pro' );
                ?>
									<span class="spgc-badge" id="spgc-badge">
										<?php 
                echo esc_html( $rule_count . ' ' . __( 'rules', 'smart-payment-gateway-control-pro' ) );
                ?>
									</span>
								</h2>
							</div>

							<div id="spgc-rules-list">
								<?php 
                if ( empty( $rules ) ) {
                    ?>
									<div class="spgc-empty" id="spgc-empty-state">
										<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#c0cfe8" stroke-width="1.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
										<p><?php 
                    esc_html_e( 'No rules yet. Click "Add Rule" to get started.', 'smart-payment-gateway-control-pro' );
                    ?></p>
									</div>
								<?php 
                }
                ?>

								<?php 
                foreach ( $rules as $i => $rule ) {
                    ?>
									<?php 
                    $this->render_rule_card(
                        $i,
                        $rule,
                        $gateways,
                        $categories,
                        $roles,
                        $countries,
                        $cond_types,
                        $op_map,
                        $op_labels
                    );
                    ?>
								<?php 
                }
                ?>
							</div>

							<div class="spgc-toolbar">
								<button type="button" id="spgc-add-btn" class="spgc-btn spgc-btn-outline">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
									<?php 
                esc_html_e( 'Add Rule', 'smart-payment-gateway-control-pro' );
                ?>
								</button>
								<button type="submit" class="spgc-btn spgc-btn-primary">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
									<?php 
                esc_html_e( 'Save Rules', 'smart-payment-gateway-control-pro' );
                ?>
								</button>
							</div>
						</form>
					</div><!-- .spgc-main -->

					<!-- Right: sidebar -->
					<div class="spgc-sidebar">
						<div class="spgc-features-card">

							<div class="spgc-features-head">
								<h3>
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
									<?php 
                esc_html_e( 'Features & Analytics', 'smart-payment-gateway-control-pro' );
                ?>
								</h3>
								<p>
									<?php 
                esc_html_e( 'All features active — unlimited rules & all 7 conditions.', 'smart-payment-gateway-control-pro' );
                ?>
								</p>
							</div>

							<div class="spgc-features-list">

								<?php 
                $all_features = array(
                    array(
                        'c1',
                        '#7dd3fc',
                        '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
                        'Product Category',
                        'Trigger by category — sub-categories included'
                    ),
                    array(
                        'c2',
                        '#6ee7b7',
                        '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>',
                        'Specific Product',
                        'Target individual products or variations'
                    ),
                    array(
                        'c3',
                        '#fcd34d',
                        '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
                        'Cart Total',
                        'Rules above, below, or equal to an order value'
                    ),
                    array(
                        'c4',
                        '#c4b5fd',
                        '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
                        'User Role',
                        'Target logged-in roles or guest customers'
                    ),
                    array(
                        'c5',
                        '#fdba74',
                        '<rect x="1" y="3" width="15" height="13" rx="2"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
                        'Shipping Method',
                        'Trigger based on the chosen shipping method'
                    ),
                    array(
                        'c6',
                        '#f9a8d4',
                        '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
                        'Billing Country',
                        'Restrict payment options by country'
                    ),
                    array(
                        'c7',
                        '#86efac',
                        '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/>',
                        'Order Quantity',
                        'Apply rules based on total item count'
                    ),
                    array(
                        'c8',
                        '#7dd3fc',
                        '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
                        'Unlimited Rules',
                        'No cap — create as many rules as you need'
                    )
                );
                foreach ( $all_features as $f ) {
                    ?>
								<div class="spgc-feature-item">
									<div class="spgc-feature-icon <?php 
                    echo esc_attr( $f[0] );
                    ?>">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?php 
                    echo esc_attr( $f[1] );
                    ?>" stroke-width="2"><?php 
                    echo $f[2];
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG path data
                    ?></svg>
									</div>
									<div class="spgc-feature-info">
										<strong><?php 
                    echo esc_html( __( $f[3], 'smart-payment-gateway-control-pro' ) );
                    ?></strong>
										<span><?php 
                    echo esc_html( __( $f[4], 'smart-payment-gateway-control-pro' ) );
                    ?></span>
									</div>
									<div class="spgc-feature-badge"><span class="spgc-plan-pill pro"><?php 
                    esc_html_e( 'Pro', 'smart-payment-gateway-control-pro' );
                    ?></span></div>
								</div>
								<?php 
                }
                ?>

							</div><!-- .spgc-features-list -->

							<!-- Pro active state -->
							<div class="spgc-pro-active">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
								<span><?php 
                    esc_html_e( 'Pro version active — all features unlocked', 'smart-payment-gateway-control-pro' );
                    ?></span>
							</div>

						</div><!-- .spgc-features-card -->
					</div><!-- .spgc-sidebar -->

				</div><!-- .spgc-layout -->

			</div><!-- #spgc-outer -->
			</div><!-- #spgc-wrap -->
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
                $cond_types,
                $op_map,
                $op_labels
            ) {
                $gw = ( isset( $rule['gateway'] ) ? $rule['gateway'] : '' );
                $conditions = ( isset( $rule['conditions'] ) ? $rule['conditions'] : array() );
                $is_pro = self::is_pro();
                ?>
			<div class="spgc-rule" data-index="<?php 
                echo esc_attr( (string) (int) $i );
                ?>">
				<div class="spgc-rule-topbar">
					<span class="spgc-rule-num">
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
						<span class="spgc-rule-num-badge"><?php 
                echo esc_html( (int) $i + 1 );
                ?></span>
						<?php 
                echo esc_html( sprintf( 
                    /* translators: %d: rule number */
                    __( 'Rule #%d', 'smart-payment-gateway-control-pro' ),
                    (int) $i + 1
                 ) );
                ?>
					</span>
					<button type="button" class="spgc-remove" title="<?php 
                esc_attr_e( 'Remove rule', 'smart-payment-gateway-control-pro' );
                ?>">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
					</button>
				</div>

				<div class="spgc-rule-body">
					<div class="spgc-conditions-wrap">
						<?php 
                foreach ( $conditions as $ci => $cond ) {
                    ?>
							<?php 
                    $ct = ( isset( $cond['condition_type'] ) ? $cond['condition_type'] : 'category' );
                    $op = ( isset( $cond['operator'] ) ? $cond['operator'] : 'is' );
                    $val = ( isset( $cond['value'] ) ? $cond['value'] : '' );
                    $ops = ( isset( $op_map[$ct] ) ? $op_map[$ct] : array('is', 'is_not') );
                    ?>
							<?php 
                    if ( $ci > 0 ) {
                        ?>
								<div class="spgc-and-separator">
									<span><?php 
                        esc_html_e( 'AND', 'smart-payment-gateway-control-pro' );
                        ?></span>
								</div>
							<?php 
                    }
                    ?>
							<div class="spgc-condition-row"
								data-rule="<?php 
                    echo esc_attr( (string) (int) $i );
                    ?>"
								data-cond="<?php 
                    echo esc_attr( (string) (int) $ci );
                    ?>">
								<div class="spgc-rule-grid">
									<div class="spgc-field">
										<label><?php 
                    esc_html_e( 'If&#8230;', 'smart-payment-gateway-control-pro' );
                    ?></label>
										<select name="<?php 
                    echo esc_attr( 'spgc_rules[' . (int) $i . '][conditions][' . (int) $ci . '][condition_type]' );
                    ?>" class="spgc-ct">
											<?php 
                    foreach ( $cond_types as $key => $label ) {
                        ?>
												<option value="<?php 
                        echo esc_attr( $key );
                        ?>"
													<?php 
                        selected( $ct, $key );
                        ?>
												><?php 
                        echo esc_html( $label );
                        ?></option>
											<?php 
                    }
                    ?>
										</select>
									</div>
									<div class="spgc-field">
										<label><?php 
                    esc_html_e( 'Operator', 'smart-payment-gateway-control-pro' );
                    ?></label>
										<select name="<?php 
                    echo esc_attr( 'spgc_rules[' . (int) $i . '][conditions][' . (int) $ci . '][operator]' );
                    ?>" class="spgc-op">
											<?php 
                    foreach ( $ops as $ok ) {
                        ?>
												<option value="<?php 
                        echo esc_attr( $ok );
                        ?>" <?php 
                        selected( $op, $ok );
                        ?>>
													<?php 
                        echo esc_html( ( isset( $op_labels[$ok] ) ? $op_labels[$ok] : $ok ) );
                        ?>
												</option>
											<?php 
                    }
                    ?>
										</select>
									</div>
									<div class="spgc-field spgc-val-wrap">
										<label><?php 
                    esc_html_e( 'Value', 'smart-payment-gateway-control-pro' );
                    ?></label>
										<?php 
                    $this->render_value_input(
                        $i,
                        $ci,
                        $ct,
                        $val,
                        $categories,
                        $roles,
                        $countries
                    );
                    ?>
									</div>
									<?php 
                    if ( $ci > 0 ) {
                        ?>
										<div class="spgc-field" style="justify-content:flex-end">
											<button type="button" class="spgc-remove-cond" title="<?php 
                        esc_attr_e( 'Remove condition', 'smart-payment-gateway-control-pro' );
                        ?>">
												<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
											</button>
										</div>
									<?php 
                    } else {
                        ?>
										<div></div>
									<?php 
                    }
                    ?>
								</div>
							</div>
						<?php 
                }
                ?>
					</div>

					<button type="button" class="spgc-add-cond-btn" data-rule="<?php 
                echo esc_attr( (string) (int) $i );
                ?>">
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
						<?php 
                esc_html_e( 'Add AND Condition', 'smart-payment-gateway-control-pro' );
                ?>
					</button>
				</div>

				<div class="spgc-then-row">
					<div class="spgc-then-arrow">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7dd3fc" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="5 12 12 19 19 12"/></svg>
					</div>
					<div class="spgc-field spgc-then-gw">
						<div class="spgc-then-label">
							<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 8 13.5 16.5 8 11 2 17"/><polyline points="16 8 22 8 22 14"/></svg>
							<?php 
                esc_html_e( 'Then disable', 'smart-payment-gateway-control-pro' );
                ?>
						</div>
						<select name="<?php 
                echo esc_attr( 'spgc_rules[' . (int) $i . '][gateway]' );
                ?>">
							<option value=""><?php 
                esc_html_e( '&#8212; select gateway &#8212;', 'smart-payment-gateway-control-pro' );
                ?></option>
							<?php 
                foreach ( $gateways as $gid => $gtitle ) {
                    ?>
								<option value="<?php 
                    echo esc_attr( $gid );
                    ?>" <?php 
                    selected( $gw, $gid );
                    ?>><?php 
                    echo esc_html( $gtitle );
                    ?></option>
							<?php 
                }
                ?>
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
                $countries
            ) {
                $name = 'spgc_rules[' . (int) $i . '][conditions][' . (int) $ci . '][value]';
                $vals = (array) $val;
                switch ( $ct ) {
                    case 'category':
                        echo '<select name="' . esc_attr( $name ) . '[]" multiple class="spgc-s2-multi">';
                        foreach ( $categories as $slug => $label ) {
                            $selected = ( in_array( $slug, $vals, true ) ? ' selected="selected"' : '' );
                            echo '<option value="' . esc_attr( $slug ) . '"' . esc_attr( $selected ) . '>' . esc_html( $label ) . '</option>';
                        }
                        echo '</select>';
                        break;
                    case 'product':
                        echo '<select name="' . esc_attr( $name ) . '[]" multiple class="spgc-s2-product">';
                        foreach ( $vals as $pid ) {
                            $pid = absint( $pid );
                            $p = ( $pid ? wc_get_product( $pid ) : null );
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
                            $selected = ( $current === $slug ? ' selected="selected"' : '' );
                            echo '<option value="' . esc_attr( $slug ) . '"' . esc_attr( $selected ) . '>' . esc_html( $label ) . '</option>';
                        }
                        echo '</select>';
                        break;
                    case 'country':
                        $current = reset( $vals );
                        echo '<select name="' . esc_attr( $name ) . '" class="spgc-s2-country">';
                        foreach ( $countries as $code => $cname ) {
                            $selected = ( $current === $code ? ' selected="selected"' : '' );
                            echo '<option value="' . esc_attr( $code ) . '"' . esc_attr( $selected ) . '>' . esc_html( $cname ) . '</option>';
                        }
                        echo '</select>';
                        break;
                    case 'shipping_method':
                        $saved_val = reset( $vals );
                        echo '<select name="' . esc_attr( $name ) . '" class="spgc-s2-ship">';
                        if ( $saved_val ) {
                            echo '<option value="' . esc_attr( $saved_val ) . '" selected="selected">' . esc_html( $saved_val ) . '</option>';
                        }
                        echo '</select>';
                        break;
                    case 'cart_total':
                    case 'quantity':
                    default:
                        $num = reset( $vals );
                        echo '<input type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $num ) . '" min="0" step="0.01" placeholder="0">';
                        break;
                }
            }

            /* =====================================================================
             * SAVE RULES — server-side freemium enforcement
             * =================================================================== */
            private function save_rules() {
                if ( !isset( $_POST[self::NONCE_NAME] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[self::NONCE_NAME] ) ), self::NONCE_ACTION ) ) {
                    return;
                }
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $post_rules = ( isset( $_POST['spgc_rules'] ) ? map_deep( wp_unslash( $_POST['spgc_rules'] ), 'sanitize_text_field' ) : array() );
                if ( empty( $post_rules ) || !is_array( $post_rules ) ) {
                    update_option( self::OPTION_KEY, array(), false );
                    return;
                }
                $is_pro = self::is_pro();
                $raw = (array) $post_rules;
                $allowed_types = array_keys( $this->get_condition_types() );
                $allowed_ops = array(
                    'is',
                    'is_not',
                    'gt',
                    'gte',
                    'lt',
                    'lte'
                );
                $clean = array();
                $rule_count = 0;
                foreach ( $raw as $row ) {
                    if ( !is_array( $row ) ) {
                        continue;
                    }
                    $gw = sanitize_key( ( isset( $row['gateway'] ) ? $row['gateway'] : '' ) );
                    if ( !$gw ) {
                        continue;
                    }
                    $raw_conds = array();
                    if ( !empty( $row['conditions'] ) && is_array( $row['conditions'] ) ) {
                        $raw_conds = $row['conditions'];
                    } elseif ( isset( $row['condition_type'] ) ) {
                        // Legacy v1.x single-condition rows.
                        $raw_conds = array(array(
                            'condition_type' => ( isset( $row['condition_type'] ) ? $row['condition_type'] : '' ),
                            'operator'       => ( isset( $row['operator'] ) ? $row['operator'] : 'is' ),
                            'value'          => ( isset( $row['value'] ) ? $row['value'] : '' ),
                        ));
                    }
                    $clean_conds = array();
                    foreach ( $raw_conds as $cond ) {
                        $ct = sanitize_key( ( isset( $cond['condition_type'] ) ? $cond['condition_type'] : '' ) );
                        $op = sanitize_key( ( isset( $cond['operator'] ) ? $cond['operator'] : 'is' ) );
                        if ( !in_array( $ct, $allowed_types, true ) || !in_array( $op, $allowed_ops, true ) ) {
                            continue;
                        }
                        $value = $this->sanitize_value( $ct, ( isset( $cond['value'] ) ? $cond['value'] : '' ) );
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
                    $rule_count++;
                }
                update_option( self::OPTION_KEY, $clean, false );
            }

            private function sanitize_value( $type, $value ) {
                switch ( $type ) {
                    case 'cart_total':
                    case 'quantity':
                        $v = ( is_array( $value ) ? reset( $value ) : $value );
                        return (string) abs( (float) $v );
                    case 'product':
                        return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
                    case 'category':
                        return array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ) ) );
                    case 'user_role':
                        $v = ( is_array( $value ) ? reset( $value ) : $value );
                        return sanitize_key( $v );
                    case 'shipping_method':
                        $v = ( is_array( $value ) ? reset( $value ) : $value );
                        return sanitize_text_field( $v );
                    case 'country':
                        $v = ( is_array( $value ) ? reset( $value ) : $value );
                        $v = strtoupper( sanitize_text_field( $v ) );
                        return ( 2 === strlen( $v ) ? $v : '' );
                    default:
                        $v = ( is_array( $value ) ? reset( $value ) : $value );
                        return sanitize_text_field( $v );
                }
            }

            /* =====================================================================
             * FRONT-END GATEWAY FILTERING
             * =================================================================== */
            public function filter_gateways( $available_gateways ) {
                if ( is_admin() && !defined( 'DOING_AJAX' ) ) {
                    return $available_gateways;
                }
                if ( !function_exists( 'WC' ) || !WC()->cart ) {
                    return $available_gateways;
                }
                $rules = $this->get_rules();
                foreach ( $rules as $rule ) {
                    if ( isset( $available_gateways[$rule['gateway']] ) && $this->evaluate( $rule ) ) {
                        unset($available_gateways[$rule['gateway']]);
                    }
                }
                return $available_gateways;
            }

            private function evaluate( $rule ) {
                if ( isset( $rule['conditions'] ) && is_array( $rule['conditions'] ) ) {
                    $conditions = $rule['conditions'];
                } elseif ( isset( $rule['condition_type'] ) ) {
                    $conditions = array(array(
                        'condition_type' => $rule['condition_type'],
                        'operator'       => ( isset( $rule['operator'] ) ? $rule['operator'] : 'is' ),
                        'value'          => ( isset( $rule['value'] ) ? $rule['value'] : '' ),
                    ));
                } else {
                    return false;
                }
                foreach ( $conditions as $cond ) {
                    if ( !$this->evaluate_condition( $cond ) ) {
                        return false;
                    }
                }
                return !empty( $conditions );
            }

            private function evaluate_condition( $cond ) {
                $ct = ( isset( $cond['condition_type'] ) ? $cond['condition_type'] : '' );
                $op = ( isset( $cond['operator'] ) ? $cond['operator'] : 'is' );
                $val = ( isset( $cond['value'] ) ? $cond['value'] : '' );
                switch ( $ct ) {
                    case 'category':
                        return $this->eval_category( (array) $val, $op );
                    case 'product':
                        return $this->eval_product( (array) $val, $op );
                    case 'cart_total':
                        return $this->eval_number( (float) WC()->cart->get_cart_contents_total(), $op, (float) $val );
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
                    $pid = absint( ( isset( $item['product_id'] ) ? $item['product_id'] : 0 ) );
                    if ( $pid && array_intersect( $slugs, $this->product_cat_slugs( $pid ) ) ) {
                        $found = true;
                        break;
                    }
                }
                return ( 'is_not' === $op ? !$found : $found );
            }

            private function eval_product( $ids, $op ) {
                $ids = array_map( 'intval', $ids );
                $found = false;
                foreach ( WC()->cart->get_cart() as $item ) {
                    if ( in_array( (int) $item['product_id'], $ids, true ) ) {
                        $found = true;
                        break;
                    }
                }
                return ( 'is_not' === $op ? !$found : $found );
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
                $has = ( 'guest' === $value ? !is_user_logged_in() : in_array( $value, (array) wp_get_current_user()->roles, true ) );
                return ( 'is_not' === $op ? !$has : $has );
            }

            private function eval_shipping( $value, $op ) {
                $chosen = ( WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array() );
                $has = in_array( $value, $chosen, true );
                return ( 'is_not' === $op ? !$has : $has );
            }

            private function eval_country( $value, $op ) {
                $country = ( WC()->customer ? strtoupper( (string) WC()->customer->get_billing_country() ) : '' );
                $has = $country === strtoupper( $value );
                return ( 'is_not' === $op ? !$has : $has );
            }

            /* =====================================================================
             * DATA HELPERS
             * =================================================================== */
            public function get_rules() {
                $rules = (array) get_option( self::OPTION_KEY, array() );
                foreach ( $rules as $i => $rule ) {
                    if ( isset( $rule['condition_type'] ) && !isset( $rule['conditions'] ) ) {
                        $rules[$i] = array(
                            'conditions' => array(array(
                                'condition_type' => $rule['condition_type'],
                                'operator'       => ( isset( $rule['operator'] ) ? $rule['operator'] : 'is' ),
                                'value'          => ( isset( $rule['value'] ) ? $rule['value'] : '' ),
                            )),
                            'gateway'    => ( isset( $rule['gateway'] ) ? $rule['gateway'] : '' ),
                        );
                    }
                }
                return $rules;
            }

            private function product_cat_slugs( $pid ) {
                $slugs = array();
                $terms = get_the_terms( $pid, 'product_cat' );
                if ( !is_array( $terms ) ) {
                    return $slugs;
                }
                foreach ( $terms as $term ) {
                    $slugs[] = $term->slug;
                    foreach ( get_ancestors( $term->term_id, 'product_cat' ) as $anc_id ) {
                        $anc = get_term( $anc_id, 'product_cat' );
                        if ( $anc instanceof WP_Term ) {
                            $slugs[] = $anc->slug;
                        }
                    }
                }
                return array_unique( $slugs );
            }

            private function get_gateway_options() {
                $out = array();
                foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) {
                    $out[$id] = ( $gw->get_title() ? $gw->get_title() : $id );
                }
                return $out;
            }

            private function get_category_options() {
                $out = array();
                $terms = get_terms( array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                    'orderby'    => 'name',
                ) );
                if ( is_array( $terms ) ) {
                    foreach ( $terms as $t ) {
                        $out[$t->slug] = $t->name;
                    }
                }
                return $out;
            }

            private function get_role_options() {
                global $wp_roles;
                $out = array(
                    'guest' => esc_html__( 'Guest (not logged in)', 'smart-payment-gateway-control-pro' ),
                );
                foreach ( $wp_roles->roles as $slug => $data ) {
                    $out[$slug] = translate_user_role( $data['name'] );
                }
                return $out;
            }

            private function get_country_options() {
                return ( WC()->countries ? WC()->countries->get_countries() : array() );
            }

            private function get_condition_types() {
                return array(
                    'category'        => esc_html__( 'Product Category', 'smart-payment-gateway-control-pro' ),
                    'product'         => esc_html__( 'Specific Product', 'smart-payment-gateway-control-pro' ),
                    'cart_total'      => esc_html__( 'Cart Total', 'smart-payment-gateway-control-pro' ),
                    'user_role'       => esc_html__( 'User Role', 'smart-payment-gateway-control-pro' ),
                    'shipping_method' => esc_html__( 'Shipping Method', 'smart-payment-gateway-control-pro' ),
                    'country'         => esc_html__( 'Billing Country', 'smart-payment-gateway-control-pro' ),
                    'quantity'        => esc_html__( 'Order Quantity', 'smart-payment-gateway-control-pro' ),
                );
            }

            private function get_operators_map() {
                return array(
                    'category'        => array('is', 'is_not'),
                    'product'         => array('is', 'is_not'),
                    'cart_total'      => array(
                        'gt',
                        'gte',
                        'lt',
                        'lte',
                        'is',
                        'is_not'
                    ),
                    'user_role'       => array('is', 'is_not'),
                    'shipping_method' => array('is', 'is_not'),
                    'country'         => array('is', 'is_not'),
                    'quantity'        => array(
                        'gt',
                        'gte',
                        'lt',
                        'lte',
                        'is',
                        'is_not'
                    ),
                );
            }

            private function get_operator_labels() {
                return array(
                    'is'     => esc_html__( 'is', 'smart-payment-gateway-control-pro' ),
                    'is_not' => esc_html__( 'is not', 'smart-payment-gateway-control-pro' ),
                    'gt'     => '>',
                    'gte'    => '>=',
                    'lt'     => '<',
                    'lte'    => '<=',
                );
            }

        }

    }
    // class_exists
    add_action( 'plugins_loaded', static function () {
        if ( !defined( 'WC_VERSION' ) ) {
            add_action( 'admin_notices', static function () {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__( 'Smart Payment Gateway Control for WooCommerce requires WooCommerce to be installed and active.', 'smart-payment-gateway-control-pro' );
                echo '</p></div>';
            } );
            return;
        }
        SPGC_Plugin::get_instance();
    }, 20 );
    // Add Settings link on Plugins page.
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=spgc-settings' ) ) . '">' . esc_html__( 'Settings', 'smart-payment-gateway-control-pro' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    } );