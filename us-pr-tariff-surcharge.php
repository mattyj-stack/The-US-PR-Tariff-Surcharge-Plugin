<?php
/**
 * Plugin Name: US/PR Tariff Surcharge for WooCommerce
 * Description: Applies a configurable tariff surcharge for orders shipping to specified US/PR destinations with modal acknowledgement and inline notices.
 * Version: 1.0.0
 * Author: OpenAI Assistant
 * Text Domain: us-pr-tariff-surcharge
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'US_PR_Tariff_Surcharge' ) ) {
    class US_PR_Tariff_Surcharge {
        const VERSION = '1.0.0';
        const OPTION_PREFIX = 'wc_us_pr_tariff_surcharge_';
        const SESSION_KEY = 'us_pr_tariff_surcharge_data';

        /**
         * Constructor.
         */
        public function __construct() {
            add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
            add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
            add_action( 'woocommerce_settings_tabs_tariff_surcharge', array( $this, 'render_settings' ) );
            add_action( 'woocommerce_update_options_tariff_surcharge', array( $this, 'save_settings' ) );
            add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_tariff_fee' ), 30, 1 );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
            add_action( 'woocommerce_before_cart_totals', array( $this, 'render_inline_notice_cart' ) );
            add_action( 'woocommerce_before_checkout_form', array( $this, 'render_inline_notice_checkout' ), 5 );
            add_action( 'woocommerce_checkout_create_order', array( $this, 'store_order_meta' ), 20, 2 );
            add_action( 'woocommerce_checkout_create_order_fee_item', array( $this, 'store_fee_item_meta' ), 10, 4 );
        }

        /**
         * Load translation files.
         */
        public function load_textdomain() {
            load_plugin_textdomain( 'us-pr-tariff-surcharge', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        }

        /**
         * Add the Tariff Surcharge tab to WooCommerce settings.
         *
         * @param array $tabs Tabs array.
         *
         * @return array
         */
        public function add_settings_tab( $tabs ) {
            $tabs['tariff_surcharge'] = __( 'Tariff Surcharge', 'us-pr-tariff-surcharge' );
            return $tabs;
        }

        /**
         * Output settings.
         */
        public function render_settings() {
            woocommerce_admin_fields( $this->get_settings_fields() );
        }

        /**
         * Save settings.
         */
        public function save_settings() {
            woocommerce_update_options( $this->get_settings_fields() );
        }

        /**
         * Get plugin settings.
         *
         * @return array
         */
        protected function get_settings_fields() {
            $countries      = new WC_Countries();
            $country_list   = $countries->get_countries();
            $country_states = array();

            foreach ( $countries->get_states() as $country_code => $states ) {
                foreach ( $states as $state_code => $state_name ) {
                    $country_states[ $country_code . ':' . $state_code ] = sprintf( '%1$s — %2$s', $country_list[ $country_code ] ?? $country_code, $state_name );
                }
            }

            $location_options = array_merge( $country_list, $country_states );

            $product_categories = get_terms(
                array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                )
            );

            $category_options = array();
            if ( ! is_wp_error( $product_categories ) ) {
                foreach ( $product_categories as $category ) {
                    $category_options[ $category->term_id ] = $category->name;
                }
            }

            $tax_options = array( '' => __( 'Standard', 'woocommerce' ) );
            foreach ( WC_Tax::get_tax_classes() as $class_name ) {
                $slug = sanitize_title( $class_name );
                if ( ! isset( $tax_options[ $slug ] ) ) {
                    $tax_options[ $slug ] = $class_name;
                }
            }

            return array(
                'section_title' => array(
                    'title' => __( 'Tariff Surcharge Settings', 'us-pr-tariff-surcharge' ),
                    'type'  => 'title',
                    'desc'  => __( 'Configure the tariff surcharge that applies to orders shipping to the United States, Puerto Rico, or additional destinations.', 'us-pr-tariff-surcharge' ),
                    'id'    => self::OPTION_PREFIX . 'title',
                ),
                self::OPTION_PREFIX . 'enabled' => array(
                    'title'   => __( 'Enable tariff surcharge', 'us-pr-tariff-surcharge' ),
                    'type'    => 'checkbox',
                    'default' => 'yes',
                    'desc'    => __( 'Enable applying the tariff surcharge on eligible orders.', 'us-pr-tariff-surcharge' ),
                ),
                self::OPTION_PREFIX . 'locations' => array(
                    'title'    => __( 'Countries & regions', 'us-pr-tariff-surcharge' ),
                    'type'     => 'multiselect',
                    'class'    => 'wc-enhanced-select',
                    'css'      => 'min-width:350px;',
                    'default'  => array( 'US', 'PR', 'US:PR' ),
                    'desc'     => __( 'Select countries or specific states/regions that should trigger the tariff surcharge.', 'us-pr-tariff-surcharge' ),
                    'options'  => $location_options,
                    'desc_tip' => true,
                ),
                self::OPTION_PREFIX . 'rate' => array(
                    'title'             => __( 'Tariff rate (%)', 'us-pr-tariff-surcharge' ),
                    'type'              => 'number',
                    'default'           => '10',
                    'custom_attributes' => array(
                        'step' => '0.01',
                        'min'  => '0',
                    ),
                    'desc' => __( 'Percentage applied to the eligible parts value.', 'us-pr-tariff-surcharge' ),
                ),
                self::OPTION_PREFIX . 'categories' => array(
                    'title'    => __( 'Eligible parts categories', 'us-pr-tariff-surcharge' ),
                    'type'     => 'multiselect',
                    'class'    => 'wc-enhanced-select',
                    'css'      => 'min-width:350px;',
                    'options'  => $category_options,
                    'desc'     => __( 'Select the product categories counted as parts. Leave empty to include all categories.', 'us-pr-tariff-surcharge' ),
                    'desc_tip' => true,
                ),
                self::OPTION_PREFIX . 'exclude_virtual' => array(
                    'title'   => __( 'Exclude virtual products', 'us-pr-tariff-surcharge' ),
                    'type'    => 'checkbox',
                    'default' => 'yes',
                ),
                self::OPTION_PREFIX . 'exclude_downloadable' => array(
                    'title'   => __( 'Exclude downloadable products', 'us-pr-tariff-surcharge' ),
                    'type'    => 'checkbox',
                    'default' => 'yes',
                ),
                self::OPTION_PREFIX . 'base_type' => array(
                    'title'   => __( 'Base for percentage', 'us-pr-tariff-surcharge' ),
                    'type'    => 'radio',
                    'options' => array(
                        'after_discounts'  => __( 'After item discounts (default)', 'us-pr-tariff-surcharge' ),
                        'before_discounts' => __( 'Before item discounts', 'us-pr-tariff-surcharge' ),
                    ),
                    'default' => 'after_discounts',
                ),
                self::OPTION_PREFIX . 'taxable' => array(
                    'title'   => __( 'Surcharge is taxable', 'us-pr-tariff-surcharge' ),
                    'type'    => 'checkbox',
                    'default' => 'no',
                ),
                self::OPTION_PREFIX . 'tax_class' => array(
                    'title'   => __( 'Tax class', 'us-pr-tariff-surcharge' ),
                    'type'    => 'select',
                    'class'   => 'wc-enhanced-select',
                    'options' => $tax_options,
                    'default' => '',
                    'desc'    => __( 'Choose which tax class applies if the surcharge is taxable.', 'us-pr-tariff-surcharge' ),
                ),
                self::OPTION_PREFIX . 'fee_label' => array(
                    'title'   => __( 'Fee label', 'us-pr-tariff-surcharge' ),
                    'type'    => 'text',
                    'default' => __( 'US Tariff (%rate%% of parts)', 'us-pr-tariff-surcharge' ),
                    'desc'    => __( 'Use %rate% as a placeholder for the tariff percentage.', 'us-pr-tariff-surcharge' ),
                ),
                self::OPTION_PREFIX . 'enable_popup_cart' => array(
                    'title'   => __( 'Enable cart modal notice', 'us-pr-tariff-surcharge' ),
                    'type'    => 'checkbox',
                    'default' => 'yes',
                ),
                self::OPTION_PREFIX . 'enable_popup_checkout' => array(
                    'title'   => __( 'Enable checkout modal notice', 'us-pr-tariff-surcharge' ),
                    'type'    => 'checkbox',
                    'default' => 'no',
                ),
                self::OPTION_PREFIX . 'modal_title' => array(
                    'title'   => __( 'Modal title', 'us-pr-tariff-surcharge' ),
                    'type'    => 'text',
                    'default' => __( 'Tariff surcharge applies', 'us-pr-tariff-surcharge' ),
                ),
                self::OPTION_PREFIX . 'modal_body' => array(
                    'title'   => __( 'Modal message', 'us-pr-tariff-surcharge' ),
                    'type'    => 'textarea',
                    'css'     => 'min-height:120px;',
                    'default' => __( 'Orders shipping to the United States or Puerto Rico include a 10% tariff on eligible parts.', 'us-pr-tariff-surcharge' ),
                ),
                self::OPTION_PREFIX . 'require_acknowledgement' => array(
                    'title'   => __( 'Require acknowledgement checkbox', 'us-pr-tariff-surcharge' ),
                    'type'    => 'checkbox',
                    'default' => 'no',
                    'desc'    => __( 'Customers must acknowledge the surcharge before proceeding.', 'us-pr-tariff-surcharge' ),
                ),
                self::OPTION_PREFIX . 'acknowledgement_label' => array(
                    'title'   => __( 'Acknowledgement label', 'us-pr-tariff-surcharge' ),
                    'type'    => 'text',
                    'default' => __( 'I understand a 10% tariff will be added.', 'us-pr-tariff-surcharge' ),
                ),
                self::OPTION_PREFIX . 'inline_notice' => array(
                    'title'   => __( 'Inline notice text', 'us-pr-tariff-surcharge' ),
                    'type'    => 'textarea',
                    'default' => __( 'A US tariff surcharge of %rate%% of eligible parts will apply to this order.', 'us-pr-tariff-surcharge' ),
                ),
                self::OPTION_PREFIX . 'log_calculations' => array(
                    'title'   => __( 'Log calculation details', 'us-pr-tariff-surcharge' ),
                    'type'    => 'checkbox',
                    'default' => 'no',
                    'desc'    => __( 'Adds calculation diagnostics as order notes when enabled.', 'us-pr-tariff-surcharge' ),
                ),
                self::OPTION_PREFIX . 'section_end' => array(
                    'type' => 'sectionend',
                    'id'   => self::OPTION_PREFIX . 'section_end',
                ),
            );
        }

        /**
         * Retrieve an option value.
         *
         * @param string $key     Option suffix.
         * @param mixed  $default Default value.
         *
         * @return mixed
         */
        protected function get_option( $key, $default = null ) {
            $value = get_option( self::OPTION_PREFIX . $key, $default );
            if ( is_array( $default ) ) {
                $value = array_filter( (array) $value, static function ( $entry ) {
                    return '' !== (string) $entry;
                } );
            }
            return $value;
        }

        /**
         * Determine if surcharge should apply based on customer location.
         *
         * @param string $country Country code.
         * @param string $state   State code.
         *
         * @return bool
         */
        protected function location_triggers_surcharge( $country, $state ) {
            $locations = $this->get_option( 'locations', array( 'US', 'PR', 'US:PR' ) );
            if ( empty( $locations ) ) {
                return false;
            }

            $country = strtoupper( (string) $country );
            $state   = strtoupper( (string) $state );

            foreach ( $locations as $location ) {
                $location = strtoupper( $location );
                if ( false !== strpos( $location, ':' ) ) {
                    list( $loc_country, $loc_state ) = array_pad( explode( ':', $location ), 2, '' );
                    if ( $country === $loc_country && ( '' === $loc_state || $state === $loc_state ) ) {
                        return true;
                    }
                } else {
                    if ( $country === $location ) {
                        return true;
                    }
                }
            }

            return false;
        }

        /**
         * Apply the tariff fee on the cart.
         *
         * @param WC_Cart $cart Cart instance.
         */
        public function apply_tariff_fee( $cart ) {
            if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
                return;
            }

            if ( ! $cart ) {
                return;
            }

            if ( 'yes' !== $this->get_option( 'enabled', 'yes' ) ) {
                $this->remove_existing_fee( $cart );
                $this->clear_session();
                return;
            }

            $customer = WC()->customer;
            if ( ! $customer ) {
                return;
            }

            $shipping_country = $customer->get_shipping_country();
            $shipping_state   = $customer->get_shipping_state();

            if ( empty( $shipping_country ) ) {
                $shipping_country = $customer->get_billing_country();
            }
            if ( empty( $shipping_state ) ) {
                $shipping_state = $customer->get_billing_state();
            }

            if ( ! $this->location_triggers_surcharge( $shipping_country, $shipping_state ) ) {
                $this->remove_existing_fee( $cart );
                $this->clear_session();
                return;
            }

            $eligible_total = $this->calculate_eligible_parts_total( $cart );
            $rate           = (float) $this->get_option( 'rate', 10 );

            if ( $eligible_total <= 0 || $rate <= 0 ) {
                $this->remove_existing_fee( $cart );
                $this->clear_session();
                return;
            }

            $amount = $this->round_price( $eligible_total * ( $rate / 100 ) );

            $label_template = $this->get_option( 'fee_label', __( 'US Tariff (%rate%% of parts)', 'us-pr-tariff-surcharge' ) );
            $label          = str_replace( '%RATE%', $rate, str_replace( '%rate%', $rate, $label_template ) );

            $taxable  = 'yes' === $this->get_option( 'taxable', 'no' );
            $tax_class = '';
            if ( $taxable ) {
                $tax_class_option = $this->get_option( 'tax_class', '' );
                $tax_class        = '' === $tax_class_option ? '' : $tax_class_option;
            }

            $this->remove_existing_fee( $cart );
            $this->add_fee_to_cart( $cart, $label, $amount, $taxable, $tax_class );

            $session_data = array(
                'eligible_total' => $eligible_total,
                'rate'           => $rate,
                'amount'         => $amount,
                'country'        => $shipping_country,
                'state'          => $shipping_state,
            );
            WC()->session->set( self::SESSION_KEY, $session_data );
        }

        /**
         * Calculate the eligible parts total for the surcharge.
         *
         * @param WC_Cart $cart Cart instance.
         *
         * @return float
         */
        protected function calculate_eligible_parts_total( $cart ) {
            $items = $cart->get_cart();
            if ( empty( $items ) ) {
                return 0.0;
            }

            $selected_categories = $this->get_option( 'categories', array() );
            $has_category_filter = is_array( $selected_categories ) && ! empty( $selected_categories );
            $exclude_virtual     = 'yes' === $this->get_option( 'exclude_virtual', 'yes' );
            $exclude_downloadable = 'yes' === $this->get_option( 'exclude_downloadable', 'yes' );
            $base_type           = $this->get_option( 'base_type', 'after_discounts' );

            $total = 0.0;

            foreach ( $items as $item ) {
                if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
                    continue;
                }

                /** @var WC_Product $product */
                $product = $item['data'];

                if ( $exclude_virtual && $product->is_virtual() ) {
                    continue;
                }

                if ( $exclude_downloadable && $product->is_downloadable() ) {
                    continue;
                }

                if ( $has_category_filter ) {
                    $product_cats = wc_get_product_term_ids( $product->get_id(), 'product_cat' );
                    if ( empty( array_intersect( $product_cats, array_map( 'intval', $selected_categories ) ) ) ) {
                        continue;
                    }
                }

                $line_total = 'before_discounts' === $base_type ? (float) ( $item['line_subtotal'] ?? 0 ) : (float) ( $item['line_total'] ?? 0 );

                $total += $line_total;
            }

            return max( 0.0, $total );
        }

        /**
         * Enqueue front-end assets.
         */
        public function enqueue_assets() {
            if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
                return;
            }

            $is_cart     = $this->is_cart_context();
            $is_checkout = $this->is_checkout_context();

            if ( ! $is_cart && ! $is_checkout ) {
                return;
            }

            $show_modal = $this->should_show_modal();

            wp_register_style( 'us-pr-tariff-surcharge', plugins_url( 'assets/css/tariff-surcharge.css', __FILE__ ), array(), self::VERSION );
            wp_register_script( 'us-pr-tariff-surcharge', plugins_url( 'assets/js/tariff-surcharge.js', __FILE__ ), array( 'jquery' ), self::VERSION, true );

            $data = array(
                'showModal'            => $show_modal,
                'modalTitle'           => wp_kses_post( $this->format_rate_placeholder( $this->get_option( 'modal_title', __( 'Tariff surcharge applies', 'us-pr-tariff-surcharge' ) ) ) ),
                'modalBody'            => wp_kses_post( $this->format_rate_placeholder( $this->get_option( 'modal_body', __( 'Orders shipping to the United States or Puerto Rico include a 10% tariff on eligible parts.', 'us-pr-tariff-surcharge' ) ) ) ),
                'requireAck'           => 'yes' === $this->get_option( 'require_acknowledgement', 'no' ),
                'ackLabel'             => wp_kses_post( $this->format_rate_placeholder( $this->get_option( 'acknowledgement_label', __( 'I understand a 10% tariff will be added.', 'us-pr-tariff-surcharge' ) ) ) ),
                'inlineNotice'         => wp_kses_post( $this->format_rate_placeholder( $this->get_option( 'inline_notice', __( 'A US tariff surcharge of %rate%% of eligible parts will apply to this order.', 'us-pr-tariff-surcharge' ) ) ) ),
                'isCart'               => $is_cart,
                'isCheckout'           => $is_checkout,
                'enableModalCart'      => 'yes' === $this->get_option( 'enable_popup_cart', 'yes' ),
                'enableModalCheckout'  => 'yes' === $this->get_option( 'enable_popup_checkout', 'no' ),
                'rateDisplay'          => $this->get_option( 'rate', 10 ),
                'destinationKey'       => $this->get_destination_key(),
                'sessionAckKey'        => 'us_pr_tariff_ack',
            );

            wp_localize_script( 'us-pr-tariff-surcharge', 'wcTariffSurchargeData', $data );
            wp_localize_script( 'us-pr-tariff-surcharge', 'wcTariffModalL10n', array(
                'ok' => __( 'OK', 'us-pr-tariff-surcharge' ),
            ) );
            wp_enqueue_style( 'us-pr-tariff-surcharge' );
            wp_enqueue_script( 'us-pr-tariff-surcharge' );
        }

        /**
         * Format strings containing %rate% placeholder.
         *
         * @param string $value Value.
         *
         * @return string
         */
        protected function format_rate_placeholder( $value ) {
            $rate = $this->get_option( 'rate', 10 );
            return str_replace( '%RATE%', $rate, str_replace( '%rate%', $rate, $value ) );
        }

        /**
         * Round a monetary amount using WooCommerce precision.
         *
         * @param float $amount Amount to round.
         *
         * @return float
         */
        protected function round_price( $amount ) {
            $decimals = 2;

            if ( function_exists( 'wc_get_price_decimals' ) ) {
                $decimals = wc_get_price_decimals();
            } elseif ( function_exists( 'wc_get_rounding_precision' ) ) {
                $decimals = wc_get_rounding_precision();
            }

            if ( function_exists( 'wc_round' ) ) {
                return (float) wc_round( $amount, $decimals );
            }

            if ( function_exists( 'wc_format_decimal' ) ) {
                return (float) wc_format_decimal( $amount, $decimals );
            }

            return round( (float) $amount, (int) $decimals );
        }

        /**
         * Render inline notice on cart page.
         */
        public function render_inline_notice_cart() {
            if ( ! $this->is_cart_context() ) {
                return;
            }

            if ( ! $this->has_active_session() ) {
                return;
            }

            $notice = $this->format_rate_placeholder( $this->get_option( 'inline_notice', __( 'A US tariff surcharge of %rate%% of eligible parts will apply to this order.', 'us-pr-tariff-surcharge' ) ) );
            if ( empty( $notice ) ) {
                return;
            }

            echo wp_kses_post( sprintf( '<div class="us-pr-tariff-inline-notice">%s</div>', $notice ) );
        }

        /**
         * Render inline notice on checkout page.
         */
        public function render_inline_notice_checkout() {
            if ( ! $this->is_checkout_context() ) {
                return;
            }

            if ( ! $this->has_active_session() ) {
                return;
            }

            $notice = $this->format_rate_placeholder( $this->get_option( 'inline_notice', __( 'A US tariff surcharge of %rate%% of eligible parts will apply to this order.', 'us-pr-tariff-surcharge' ) ) );
            if ( empty( $notice ) ) {
                return;
            }

            echo wp_kses_post( sprintf( '<div class="us-pr-tariff-inline-notice">%s</div>', $notice ) );
        }

        /**
         * Determine whether to show modal.
         *
         * @return bool
         */
        protected function should_show_modal() {
            if ( ! $this->has_active_session() ) {
                return false;
            }

            if ( $this->is_cart_context() && 'yes' !== $this->get_option( 'enable_popup_cart', 'yes' ) ) {
                return false;
            }

            if ( $this->is_checkout_context() && 'yes' !== $this->get_option( 'enable_popup_checkout', 'no' ) ) {
                return false;
            }

            return true;
        }

        /**
         * Determine whether cart conditional tags can be used safely.
         *
         * @return bool
         */
        protected function conditionals_ready() {
            if ( is_admin() && ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) ) {
                return false;
            }

            if ( did_action( 'wp' ) || doing_action( 'wp' ) ) {
                return true;
            }

            if ( did_action( 'woocommerce_init' ) || doing_action( 'woocommerce_init' ) ) {
                return true;
            }

            return false;
        }

        /**
         * Check whether current request is for cart context.
         *
         * @return bool
         */
        protected function is_cart_context() {
            if ( ! function_exists( 'is_cart' ) || ! $this->conditionals_ready() ) {
                return false;
            }

            return is_cart();
        }

        /**
         * Check whether current request is for checkout context.
         *
         * @return bool
         */
        protected function is_checkout_context() {
            if ( ! function_exists( 'is_checkout' ) || ! $this->conditionals_ready() ) {
                return false;
            }

            return is_checkout();
        }

        /**
         * Determine whether inline notice should be shown.
         *
         * @param string $toggle Toggle key.
         *
         * @return bool
         */
        protected function has_active_session() {
            $session_data = WC()->session ? WC()->session->get( self::SESSION_KEY ) : null;
            return ! empty( $session_data );
        }

        /**
         * Create destination key for persistence.
         *
         * @return string
         */
        protected function get_destination_key() {
            $customer = WC()->customer;
            if ( ! $customer ) {
                return '';
            }
            $country = $customer->get_shipping_country();
            $state   = $customer->get_shipping_state();
            if ( empty( $country ) ) {
                $country = $customer->get_billing_country();
            }
            if ( empty( $state ) ) {
                $state = $customer->get_billing_state();
            }
            return strtoupper( $country . '-' . $state );
        }

        /**
         * Store meta information on the order.
         *
         * @param WC_Order $order Order object.
         * @param array    $data  Posted data.
         */
        public function store_order_meta( $order, $data ) {
            $session_data = WC()->session ? WC()->session->get( self::SESSION_KEY ) : null;
            if ( empty( $session_data ) ) {
                return;
            }

            $order->update_meta_data( '_us_pr_tariff_country', $session_data['country'] ?? '' );
            $order->update_meta_data( '_us_pr_tariff_state', $session_data['state'] ?? '' );
            $order->update_meta_data( '_us_pr_tariff_base', $session_data['eligible_total'] ?? 0 );
            $order->update_meta_data( '_us_pr_tariff_rate', $session_data['rate'] ?? 0 );
            $order->update_meta_data( '_us_pr_tariff_amount', $session_data['amount'] ?? 0 );
            $order->update_meta_data( '_us_pr_tariff_version', self::VERSION );

            if ( 'yes' === $this->get_option( 'log_calculations', 'no' ) ) {
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: country code, 2: state code, 3: base amount, 4: rate, 5: surcharge amount */
                        __( 'Tariff surcharge applied for %1$s-%2$s. Base: %3$s, Rate: %4$s%%, Amount: %5$s.', 'us-pr-tariff-surcharge' ),
                        strtoupper( $session_data['country'] ?? '' ),
                        strtoupper( $session_data['state'] ?? '' ),
                        wc_price( $session_data['eligible_total'] ?? 0, array( 'currency' => $order->get_currency() ) ),
                        wc_format_decimal( $session_data['rate'] ?? 0, 2 ),
                        wc_price( $session_data['amount'] ?? 0, array( 'currency' => $order->get_currency() ) )
                    )
                );
            }
        }

        /**
         * Store fee item meta for refunds and order item visibility.
         *
         * @param WC_Order_Item_Fee $item  Fee item.
         * @param string            $fee_key Fee key.
         * @param array             $fee_data Fee data.
         * @param WC_Order          $order Order object.
         */
        public function store_fee_item_meta( $item, $fee_key, $fee_data, $order ) {
            if ( 'us_pr_tariff_surcharge' !== ( $fee_data['id'] ?? '' ) ) {
                return;
            }

            $session_data = WC()->session ? WC()->session->get( self::SESSION_KEY ) : null;
            if ( empty( $session_data ) ) {
                return;
            }

            $item->add_meta_data( __( 'Tariff base', 'us-pr-tariff-surcharge' ), wc_format_decimal( $session_data['eligible_total'], wc_get_price_decimals() ), true );
            $item->add_meta_data( __( 'Tariff rate', 'us-pr-tariff-surcharge' ), $session_data['rate'], true );
        }

        /**
         * Remove existing tariff fee from the cart to keep calculations idempotent.
         *
         * @param WC_Cart $cart Cart instance.
         */
        protected function remove_existing_fee( $cart ) {
            foreach ( $cart->fees_api()->get_fees() as $fee_key => $fee ) {
                if ( isset( $fee->id ) && 'us_pr_tariff_surcharge' === $fee->id ) {
                    $cart->fees_api()->remove_fee( $fee_key );
                }
            }
        }

        /**
         * Add the configured tariff fee to the cart.
         *
         * @param WC_Cart $cart Cart instance.
         * @param string  $label Fee label.
         * @param float   $amount Fee amount.
         * @param bool    $taxable Whether taxable.
         * @param string  $tax_class Tax class slug.
         */
        protected function add_fee_to_cart( $cart, $label, $amount, $taxable, $tax_class ) {
            if ( ! class_exists( 'WC_Cart_Fee' ) ) {
                include_once WC_ABSPATH . 'includes/class-wc-cart-fee.php';
            }
            $fee            = new WC_Cart_Fee();
            $fee->name      = $label;
            $fee->amount    = $amount;
            $fee->taxable   = (bool) $taxable;
            $fee->tax_class = $tax_class;
            $fee->id        = 'us_pr_tariff_surcharge';
            $cart->fees_api()->add_fee( $fee );
        }

        /**
         * Clear stored session data.
         */
        protected function clear_session() {
            if ( WC()->session ) {
                WC()->session->set( self::SESSION_KEY, null );
            }
        }
    }

    new US_PR_Tariff_Surcharge();
}
