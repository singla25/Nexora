<?php

namespace Hostinger\Reach\Integrations\WooCommerce;

use Automattic\WooCommerce\Internal\Admin\Onboarding\OnboardingProfile;
use Hostinger\Reach\Api\Webhooks\Handlers\CartAbandoned;
use Hostinger\Reach\Api\Webhooks\Handlers\OrderPurchased;
use Hostinger\Reach\Integrations\IntegrationInterface;
use Hostinger\Reach\Integrations\IntegrationWithForms;
use Hostinger\Reach\Dto\PluginData;
use Hostinger\Reach\Repositories\FormRepository;
use Hostinger\Reach\Utils\PhoneNumberFormatter;
use stdClass;
use WC_Order;
use WP_Post;
use WP_REST_Request;

class WooCommerceIntegration extends IntegrationWithForms implements IntegrationInterface {
    public FormRepository $form_repository;

    private PhoneNumberFormatter $phone_number_formatter;

    public const INTEGRATION_NAME         = 'woocommerce';
    public const OPTIN_KEY                = 'hostinger_reach_optin';
    public const ORDER_META_OPTIN_KEY     = '_wc_other/hostinger-reach/newsletter-optin';
    public const BILLING_PHONE_META_KEY   = 'billing_phone';
    public const BILLING_COUNTRY_META_KEY = 'billing_country';

    public function __construct( FormRepository $form_repository, PhoneNumberFormatter $phone_number_formatter ) {
        parent::__construct( $form_repository );
        $this->phone_number_formatter = $phone_number_formatter;
    }

    public static function get_name(): string {
        return self::INTEGRATION_NAME;
    }

    public function init(): void {
        parent::init();
        add_action( 'hostinger_reach_integration_activated', array( $this, 'set_woocommerce_onboarding_skipped' ) );
    }

    public function active_integration_hooks(): void {
        $this->add_form();
        $this->add_automations();
        if ( $this->form_repository->is_form_active( self::INTEGRATION_NAME ) ) {
            $this->init_optin_actions();

            add_action( 'woocommerce_thankyou', array( $this, 'subscribe_customer_to_hostinger_reach' ) );
            add_action( 'woocommerce_checkout_order_processed', array( $this, 'handle_optin' ) );
            add_action( 'hostinger_reach_contact_submitted', array( $this, 'handle_submission' ) );
        }
    }

    public function init_optin_actions(): void {
        if ( did_action( 'woocommerce_blocks_loaded' ) ) {
            $this->register_checkout_blocks_field();
        } else {
            add_action( 'woocommerce_blocks_loaded', array( $this, 'register_checkout_blocks_field' ) );
        }

        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            array(
                $this,
                'handle_checkout_blocks_optin',
            ),
            10,
            2
        );
        add_action( 'woocommerce_checkout_after_terms_and_conditions', array( $this, 'add_optin_checkbox' ) );
    }


    public function register_checkout_blocks_field(): void {
        if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
            return;
        }

        woocommerce_register_additional_checkout_field(
            array(
                'id'                         => 'hostinger-reach/newsletter-optin',
                'label'                      => __( 'Subscribe to our newsletter', 'hostinger-reach' ),
                'location'                   => 'contact',
                'type'                       => 'checkbox',
                'required'                   => false,
                'attributes'                 => array(
                    'data-custom' => 'hostinger-reach-optin',
                ),
                'show_in_order_confirmation' => true,
            )
        );
    }

    public function add_optin_checkbox(): void {
        $this->load_template();
    }

    public function handle_optin( int $oder_id ): void {
        $this->set_opted_in( isset( $_POST[ self::OPTIN_KEY ] ), $oder_id );
    }

    public function handle_checkout_blocks_optin( WC_Order $order, WP_REST_Request $request ): void {
        $newsletter_optin = false;

        if ( ! $newsletter_optin && isset( $request['additional_fields']['hostinger-reach/newsletter-optin'] ) ) {
            $newsletter_optin = (bool) $request['additional_fields']['hostinger-reach/newsletter-optin'];
        }

        $this->set_opted_in( $newsletter_optin, $order->get_id() );
    }

    public function add_form(): void {
        $checkout_page_id = null;

        if ( method_exists( $this, 'wc_get_page_id' ) ) {
            $checkout_page_id = wc_get_page_id( 'checkout' );
        }

        if ( ! $this->form_repository->exists( self::INTEGRATION_NAME ) ) {
            $this->form_repository->insert(
                array(
                    'form_id'    => self::INTEGRATION_NAME,
                    'type'       => self::INTEGRATION_NAME,
                    'post_id'    => $checkout_page_id,
                    'form_title' => __( 'Checkout', 'hostinger-reach' ),
                )
            );
        }
    }

    public function add_automations(): void {
        $this->add_automation( OrderPurchased::WEBHOOK_NAME, __( 'Purchases', 'hostinger-reach' ) );
        $this->add_automation( CartAbandoned::WEBHOOK_NAME, __( 'Abandoned Carts', 'hostinger-reach' ) );
    }

    public function add_automation( string $automation_name, string $title ): void {
        if ( ! $this->form_repository->exists( $automation_name ) ) {
            $this->form_repository->insert(
                array(
                    'form_id'    => $automation_name,
                    'form_title' => $title,
                    'type'       => self::INTEGRATION_NAME,
                    'post_id'    => null,
                )
            );
        }
    }

    public function set_woocommerce_onboarding_skipped( string $integration_name ): void {
        if ( $integration_name === self::INTEGRATION_NAME && class_exists( 'Automattic\WooCommerce\Internal\Admin\Onboarding\OnboardingProfile' ) ) {
            update_option( OnboardingProfile::DATA_OPTION, array( 'skipped' => true ) );
        }
    }

    public function handle_submission( array $data ): void {
        $data['form_id'] = self::INTEGRATION_NAME;
        $this->form_repository->submit( $data );
    }

    public function subscribe_customer_to_hostinger_reach( int $order_id ): void {
        if ( ! $this->is_opted_in( $order_id ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $email   = $order->get_billing_email();
        $name    = $order->get_billing_first_name();
        $surname = $order->get_billing_last_name();
        $phone   = $this->phone_number_formatter->to_e164( $order->get_billing_phone(), $order->get_billing_country() );
        if ( $email ) {
            do_action(
                'hostinger_reach_submit',
                array(
                    'group'    => self::INTEGRATION_NAME,
                    'email'    => $email,
                    'name'     => $name,
                    'surname'  => $surname,
                    'phone'    => $phone,
                    'metadata' => array(
                        'plugin' => self::INTEGRATION_NAME,
                    ),
                )
            );
        }
    }

    public function get_plugin_data(): PluginData {
        return PluginData::from_array(
            array(
                'id'                  => self::INTEGRATION_NAME,
                'type'                => self::HOSTINGER_INTEGRATION_TYPE_ECOMMERCE,
                'folder'              => 'woocommerce',
                'file'                => 'woocommerce.php',
                'admin_url'           => 'admin.php?page=wc-admin',
                'add_form_url'        => null,
                'edit_url'            => null,
                'url'                 => 'https://wordpress.org/plugins/woocommerce/',
                'download_url'        => 'https://downloads.wordpress.org/plugin/woocommerce.zip',
                'title'               => __( 'WooCommerce', 'hostinger-reach' ),
                'is_edit_form_hidden' => true,
                'is_active'           => class_exists( 'WooCommerce' ),
                'import_enabled'      => true,
            )
        );
    }

    public function get_form_ids( WP_Post $post ): array {
        return array();
    }

    public function get_import_summary(): array {
        if ( ! $this->is_woo_customer_data_available() ) {
            return array();
        }

        return array(
            wc_get_page_id( 'checkout' ) => array(
                'title'    => __( 'WooCommerce Customers', 'hostinger-reach' ),
                'contacts' => $this->get_contacts_count(),
            ),
        );
    }

    public function get_contacts( mixed $form_id = null, ?int $limit = 100, ?int $offset = 0 ): array {
        if ( ! $this->is_woo_customer_data_available() ) {
            return array();
        }

        global $wpdb;
        $table = $this->get_woocommerce_customer_table();
        $sql   = "SELECT email, first_name, last_name, user_id, customer_id, country FROM {$table}";

        if ( $limit > 0 ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', $limit );
        }

        if ( $offset > 0 ) {
            $sql .= $wpdb->prepare( ' OFFSET %d', $offset );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table name is built from the database prefix; the limit and offset go through prepare().
        $customers       = $wpdb->get_results( $sql );
        $billing_details = $this->get_billing_details_by_user_id( $customers );

        return array_map(
            function ( stdClass $customer ) use ( $billing_details ): array {
                $user_id = (int) ( $customer->user_id ?? 0 );

                return array(
                    'email'    => $customer->email ?? '',
                    'name'     => $customer->first_name ?? '',
                    'surname'  => $customer->last_name ?? '',
                    'phone'    => $this->phone_number_formatter->to_e164(
                        $billing_details[ $user_id ]['phone'] ?? '',
                        $billing_details[ $user_id ]['country'] ?? ''
                    ),
                    'metadata' => array(
                        'form_id' => wc_get_page_id( 'checkout' ),
                        'plugin'  => self::INTEGRATION_NAME,
                        'group'   => self::INTEGRATION_NAME,
                    ),
                );
            },
            $customers
        );
    }

    public function get_contacts_count(): int {
        global $wpdb;
        if ( ! $this->is_woo_customer_data_available() ) {
            return 0;
        }

        $table = $this->get_woocommerce_customer_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the database prefix.
        return $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ?? 0;
    }

    private function set_opted_in( bool $opted_in, mixed $oder_id ): void {
        $customer_id = get_current_user_id();
        $order       = wc_get_order( $oder_id );

        $value = $opted_in ? 'yes' : 'no';

        if ( $order ) {
            $order->update_meta_data( self::ORDER_META_OPTIN_KEY, $value );
            $order->save();
        }

        if ( $customer_id > 0 ) {
            update_user_meta( $customer_id, self::OPTIN_KEY, $value );
        }
    }

    private function is_opted_in( mixed $oder_id = false ): bool {
        $order = wc_get_order( $oder_id );

        if ( $order ) {
            $order_optin = $order->get_meta( self::ORDER_META_OPTIN_KEY, true );

            if ( $order_optin !== '' ) {
                return $order_optin === 'yes';
            }
        }

        $customer_id = get_current_user_id();

        if ( $customer_id > 0 ) {
            return get_user_meta( $customer_id, self::OPTIN_KEY, true ) === 'yes';
        }

        return false;
    }

    private function get_billing_details_by_user_id( array $customers ): array {
        $account_holders = array_filter(
            $customers,
            function ( stdClass $customer ): bool {
                return (int) ( $customer->user_id ?? 0 ) > 0;
            }
        );

        $billing_details = $this->get_profile_billing_details( $account_holders );

        $without_a_phone = array_filter(
            $account_holders,
            function ( stdClass $customer ) use ( $billing_details ): bool {
                return $billing_details[ (int) $customer->user_id ]['phone'] === '';
            }
        );

        return array_replace( $billing_details, $this->get_latest_order_billing_details( $without_a_phone ) );
    }

    private function get_profile_billing_details( array $account_holders ): array {
        update_meta_cache( 'user', wp_list_pluck( $account_holders, 'user_id' ) );

        $billing_details = array();

        foreach ( $account_holders as $customer ) {
            $user_id = (int) $customer->user_id;
            $country = (string) get_user_meta( $user_id, self::BILLING_COUNTRY_META_KEY, true );

            $billing_details[ $user_id ] = array(
                'phone'   => (string) get_user_meta( $user_id, self::BILLING_PHONE_META_KEY, true ),
                'country' => $country ?: (string) ( $customer->country ?? '' ),
            );
        }

        return $billing_details;
    }

    private function get_latest_order_billing_details( array $customers ): array {
        $customers_by_lookup_id = array();

        foreach ( $customers as $customer ) {
            $lookup_customer_id = (int) ( $customer->customer_id ?? 0 );

            if ( $lookup_customer_id > 0 ) {
                $customers_by_lookup_id[ $lookup_customer_id ] = $customer;
            }
        }

        $billing_details = array();

        foreach ( $this->get_latest_order_ids( array_keys( $customers_by_lookup_id ) ) as $lookup_customer_id => $order_id ) {
            $order = wc_get_order( $order_id );
            $phone = $order instanceof WC_Order ? (string) $order->get_billing_phone() : '';

            if ( $phone === '' ) {
                continue;
            }

            $customer = $customers_by_lookup_id[ $lookup_customer_id ];
            $country  = (string) $order->get_billing_country();

            $billing_details[ (int) $customer->user_id ] = array(
                'phone'   => $phone,
                'country' => $country ?: (string) ( $customer->country ?? '' ),
            );
        }

        return $billing_details;
    }

    private function get_latest_order_ids( array $lookup_customer_ids ): array {
        $table = $this->get_woocommerce_order_stats_table();

        if ( empty( $lookup_customer_ids ) || ! $this->table_exists( $table ) ) {
            return array();
        }

        global $wpdb;

        $placeholders = implode( ', ', array_fill( 0, count( $lookup_customer_ids ), '%d' ) );

        $sql = "SELECT orders.customer_id, MAX(orders.order_id) AS order_id
                FROM %i AS orders
                INNER JOIN (
                    SELECT customer_id, MAX(date_created) AS latest_date
                    FROM %i
                    WHERE parent_id = 0 AND customer_id IN ( {$placeholders} )
                    GROUP BY customer_id
                ) AS latest ON latest.customer_id = orders.customer_id AND latest.latest_date = orders.date_created
                WHERE orders.parent_id = 0
                GROUP BY orders.customer_id";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only the placeholder list is interpolated; the table names and the customer IDs go through prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $table, $table, ...$lookup_customer_ids ) );

        $order_ids = array();

        foreach ( $rows as $row ) {
            $order_ids[ (int) $row->customer_id ] = (int) $row->order_id;
        }

        return $order_ids;
    }

    private function get_woocommerce_customer_table(): string {
        global $wpdb;
        return "{$wpdb->prefix}wc_customer_lookup";
    }

    private function get_woocommerce_order_stats_table(): string {
        global $wpdb;
        return "{$wpdb->prefix}wc_order_stats";
    }

    private function is_woo_customer_data_available(): bool {
        return function_exists( 'wc_get_page_id' ) && $this->table_exists( $this->get_woocommerce_customer_table() );
    }

    private function table_exists( string $table ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the database prefix.
        return $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table;
    }

    private function load_template(): void {
        $template      = 'optin-checkbox.php';
        $template_path = 'hostinger-reach/';
        $default_path  = HOSTINGER_REACH_PLUGIN_DIR . 'templates/';

        // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Extracting variables for use in template.
        extract( array( 'is_opted_in' => $this->is_opted_in() ) );

        $located = wc_locate_template( $template, $template_path, $default_path );
        if ( file_exists( $located ) ) {
            include $located;
        }
    }
}
