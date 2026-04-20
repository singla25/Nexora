<?php

namespace Hostinger\Reach\Integrations\Elementor;

use Elementor\Core\Documents_Manager;
use Elementor\Plugin as ElementorPlugin;
use Elementor\Utils;
use Elementor\Widget_Base;
use ElementorPro\Modules\Forms\Classes\Form_Record;
use ElementorPro\Modules\Forms\Submissions\Database\Entities\Form_Snapshot;
use ElementorPro\Modules\Forms\Submissions\Database\Query;
use ElementorPro\Modules\Forms\Submissions\Database\Repositories\Form_Snapshot_Repository;
use Exception;
use Hostinger\Reach\Dto\PluginData;
use Hostinger\Reach\Dto\ReachContact;
use Hostinger\Reach\Functions;
use Hostinger\Reach\Integrations\IntegrationInterface;
use Hostinger\Reach\Integrations\IntegrationWithForms;
use WP_Post;

if ( ! DEFINED( 'ABSPATH' ) ) {
    exit;
}

class ElementorIntegration extends IntegrationWithForms implements IntegrationInterface {

    public const INTEGRATION_NAME  = 'elementor';
    public const AUTOLOAD_META_KEY = 'hostinger_reach_add_elementor_widget';
    protected SubscriptionFormElementorWidget $widget;

    public function init(): void {
        parent::init();
        add_action( 'hostinger_reach_integration_activated', array( $this, 'on_integration_activated' ) );
        add_action( 'wp_insert_post', array( $this, 'flag_new_elementor_post' ), 10, 3 );
        add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'maybe_insert_reach_widget' ) );
    }

    public function active_integration_hooks(): void {
        add_action( 'elementor/widgets/register', array( $this, 'register_new_widgets' ) );
        add_action( 'transition_post_status', array( $this, 'handle_transition_post_status' ), 10, 3 );
        add_filter( 'hostinger_reach_get_group', array( $this, 'filter_hostinger_reach_get_group' ), 10, 2 );
        add_action( 'elementor_pro/forms/new_record', array( $this, 'handle_elementor_pro_new_record' ) );
    }

    public function on_integration_activated( string $integration_name ): void {
        if ( $integration_name === self::INTEGRATION_NAME ) {
            $this->set_elementor_onboarding();
            $this->init_existing_forms();
        }
    }

    public function handle_transition_post_status( string $new_status, string $old_status, WP_Post $post ): void {
        if ( $new_status === 'publish' ) {
            $this->set_forms( $post );
            $this->maybe_unset_forms( $post );
        } elseif ( $old_status === 'publish' ) {
            $this->unset_all_forms( $post );
        }
    }

    public function register_new_widgets(): void {
        ElementorPlugin::instance()->widgets_manager->register( $this->get_widget() );
    }

    public function filter_hostinger_reach_get_group( string $group, string $form_id ): string {
        if ( ! empty( $group ) || ! $this->is_elementor_form_id( $form_id ) ) {
            return $group;
        }

        try {
            $form = $this->form_repository->get( $form_id );
            $post = $form['post'] ?? null;
            if ( $post ) {
                return $post['post_title'] ?? self::INTEGRATION_NAME;
            }
        } catch ( Exception $e ) {
            return self::INTEGRATION_NAME;
        }

        return self::INTEGRATION_NAME;
    }

    public static function get_name(): string {
        return self::INTEGRATION_NAME;
    }

    public function get_form_ids( WP_Post $post ): array {
        return array_merge( $this->get_elementor_form_ids_from_content( $post->post_content ), $this->get_elementor_form_ids_from_actions() );
    }

    public function get_plugin_data(): PluginData {
        if ( class_exists( 'Elementor\Core\Documents_Manager' ) ) {
            $add_form_url = add_query_arg(
                array(
                    self::AUTOLOAD_META_KEY => '1',
                ),
                Documents_Manager::get_create_new_post_url()
            );
        } else {
            $add_form_url = '';
        }

        return PluginData::from_array(
            array(
                'id'                  => self::INTEGRATION_NAME,
                'folder'              => 'elementor',
                'file'                => 'elementor.php',
                'admin_url'           => 'admin.php?page=elementor',
                'add_form_url'        => $add_form_url,
                'edit_url'            => 'post.php?post={post_id}&action=elementor',
                'url'                 => 'https://wordpress.org/plugins/elementor/',
                'download_url'        => 'https://downloads.wordpress.org/plugin/elementor.zip',
                'title'               => __( 'Elementor', 'hostinger-reach' ),
                'icon'                => null,
                'is_view_form_hidden' => false,
                'is_edit_form_hidden' => false,
                'can_toggle_forms'    => true,
                'import_enabled'      => $this->is_import_supported(),
            )
        );
    }

    public function handle_elementor_pro_new_record( Form_Record $record ): void {
        $email = $this->find_email( $record->get_formatted_data() );
        if ( $email ) {
            $form                 = $this->get_form_by_request();
            $form_repository_item = $this->form_repository->get( $form->id ?? '' );
            if ( empty( $form_repository_item ) || $form_repository_item['is_active'] === false ) {
                return;
            }

            do_action(
                'hostinger_reach_submit',
                array(
                    'group'    => ! empty( $form ) ? $form->name : self::INTEGRATION_NAME,
                    'email'    => $email,
                    'metadata' => array(
                        'plugin'  => self::INTEGRATION_NAME . '-pro',
                        'form_id' => ! empty( $form ) ? $form->id : null,
                    ),
                )
            );
        }
    }

    public function flag_new_elementor_post( int $post_id, WP_Post $post, bool $update ): void {
        if ( $update ) {
            return;
        }

        if ( empty( $_GET[ self::AUTOLOAD_META_KEY ] ) ) {
            return;
        }

        update_post_meta( $post_id, self::AUTOLOAD_META_KEY, '1' );
    }

    public function maybe_insert_reach_widget(): void {
        if ( ! class_exists( 'Elementor\Utils' ) || ! class_exists( 'Elementor\Plugin' ) ) {
            return;
        }

        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return;
        }

        $should_add_widget = get_post_meta( $post_id, self::AUTOLOAD_META_KEY, true ) === '1' || ! empty( $_GET['hostinger_reach_add_block'] );
        if ( ! $should_add_widget ) {
            return;
        }

        $document = ElementorPlugin::instance()->documents->get( $post_id );
        if ( ! $document ) {
            return;
        }

        $elements = $document->get_elements_data();
        if ( ! empty( $elements ) ) {
            return;
        }

        $widget_data = array(
            array(
                'id'       => Utils::generate_random_string(),
                'elType'   => 'section',
                'settings' => array(),
                'elements' => array(
                    array(
                        'id'       => Utils::generate_random_string(),
                        'elType'   => 'column',
                        'settings' => array(
                            '_column_size' => 100,
                        ),
                        'elements' => array(
                            array(
                                'id'         => Utils::generate_random_string(),
                                'elType'     => 'widget',
                                'widgetType' => SubscriptionFormElementorWidget::WIDGET_NAME,
                                'settings'   => array(
                                    'formId'      => SubscriptionFormElementorWidget::FORM_ID_PREFIX . random_int( 1, PHP_INT_MAX ),
                                    'showName'    => 0,
                                    'showSurname' => 0,
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        );

        $document->save( array( 'elements' => $widget_data ) );
        delete_post_meta( $post_id, self::AUTOLOAD_META_KEY );
    }

    public function is_import_supported(): bool {
        return class_exists( 'ElementorPro\Modules\Forms\Submissions\Database\Repositories\Form_Snapshot_Repository' )
                && class_exists( 'ElementorPro\Modules\Forms\Submissions\Database\Query' );
    }

    public function get_import_summary(): array {
        $summary = array();

        if ( ! $this->is_import_supported() ) {
            return $summary;
        }

        $forms = Form_Snapshot_Repository::instance()->all();
        $query = Query::get_instance();

        foreach ( $forms as $form ) {
            $form_id = $form->id ?? null;
            $post_id = $form->post_id ?? null;

            if ( ! $form_id || ! $post_id ) {
                continue;
            }

            $counts = $query->count_submissions_by_status(
                array(
                    'form' => array(
                        'value' => $post_id . '_' . $form_id,
                    ),
                )
            );

            $summary[ $form_id ] = array(
                'title'    => $form->name ?? $form_id,
                'contacts' => (int) $counts->get( 'read', 0 ) + (int) $counts->get( 'unread', 0 ),
            );
        }

        return $summary;
    }

    public function get_contacts( mixed $form_id = null, ?int $limit = 100, ?int $offset = 0 ): array {
        if ( ! $this->is_import_supported() ) {
            return array();
        }

        if ( empty( $form_id ) ) {
            return array();
        }

        try {
            $form = $this->form_repository->get( $form_id );
        } catch ( Exception $e ) {
            return array();
        }

        $post_id = $form['post']['ID'] ?? null;
        $page    = (int) floor( $offset / $limit ) + 1;
        $result  = Query::get_instance()->get_submissions(
            array(
                'page'             => $page,
                'per_page'         => $limit,
                'filters'          => array(
                    'form'   => array(
                        'value' => $post_id . '_' . $form_id,
                    ),
                    'status' => array(
                        'value' => 'all',
                    ),
                ),
                'with_meta'        => true,
                'with_form_fields' => false,
            )
        );

        $submissions = $result['data'] ?? array();
        $entries     = array();

        foreach ( $submissions as $submission ) {
            $email = $this->find_email( array( $submission['main']['value'] ?? '' ) );

            if ( ! $email ) {
                $email = $this->find_email( array_column( $submission['values'], 'value' ) );
            }

            if ( ! $email ) {
                continue;
            }

            $contact = new ReachContact(
                $email,
                '',
                '',
                array(
                    'plugin'  => self::INTEGRATION_NAME,
                    'form_id' => $form_id,
                    'group'   => $form->name ?? self::INTEGRATION_NAME,
                )
            );

            $entries[] = $contact->to_array();
        }

        return $entries;
    }

    public function init_existing_forms(): void {
        $post_ids = $this->get_elementor_posts();
        foreach ( $post_ids as $post_id ) {
            $this->update_forms_from_post( $post_id );
        }
    }

    public function set_elementor_onboarding(): void {
        update_option( 'elementor_onboarded', 1 );
    }

    private function get_widget(): Widget_Base {
        return new SubscriptionFormElementorWidget();
    }

    private function update_forms_from_post( int $post_id ): void {
        $form_ids = $this->get_forms_from_post_id( $post_id );
        $this->update_form_repository( $form_ids, $post_id );
    }

    private function set_forms( WP_Post $post ): void {
        $elementor_reach_forms = $this->get_elementor_form_ids_from_content( $post->post_content );
        $elementor_pro_forms   = $this->get_elementor_form_ids_from_actions();
        $form_ids              = array_merge( $elementor_reach_forms, $elementor_pro_forms );

        $this->update_form_repository( $form_ids, $post->ID );
    }

    private function update_form_repository( array $form_ids, int $post_id ): void {
        if ( empty( $form_ids ) ) {
            update_option( Functions::HOSTINGER_REACH_HAS_FORMS, true );
        }
        foreach ( $form_ids as $form_id ) {
            $form = array(
                'form_id' => $form_id,
                'type'    => self::INTEGRATION_NAME,
            );

            if ( $this->form_repository->exists( $form_id ) ) {
                $this->form_repository->update( $form );
            } else {
                $this->form_repository->insert( array_merge( $form, array( 'post_id' => $post_id ) ) );
            }
        }
    }

    private function get_elementor_form_ids_from_content( string $content ): array {
        $form_ids = array();
        $pattern  = '/<form id="' . SubscriptionFormElementorWidget::FORM_ID_PREFIX . '(\d+)"/';
        preg_match_all( $pattern, $content, $matches );
        foreach ( $matches[1] as $form_id ) {
            $form_ids[] = SubscriptionFormElementorWidget::FORM_ID_PREFIX . $form_id;
        }

        return $form_ids;
    }

    private function get_elementor_form_ids_from_actions(): array {
        $form_ids = array();
        $actions  = json_decode( wp_unslash( $_POST['actions'] ?? '' ), true );
        $status   = $actions['save_builder']['data']['status'] ?? 'draft';
        $elements = $actions['save_builder']['data']['elements'] ?? array();

        if ( ! empty( $elements ) && $status === 'publish' ) {
            foreach ( $elements as $element ) {
                $form_ids = array_merge( $form_ids, $this->find_form_widget( $element ) );
            }
        }

        return $form_ids;
    }

    private function find_form_widget( array $element ): array {
        $form_ids = array();

        if ( isset( $element['widgetType'] ) && $element['widgetType'] === 'form' && isset( $element['id'] ) ) {
            $form_ids[] = $element['id'];
        }

        if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
            foreach ( $element['elements'] as $nested_element ) {
                $form_ids = array_merge( $form_ids, $this->find_form_widget( $nested_element ) );
            }
        }

        return $form_ids;
    }

    private function is_elementor_form_id( string $form_id ): bool {
        return str_starts_with( $form_id, SubscriptionFormElementorWidget::FORM_ID_PREFIX );
    }

    private function find_email( array $data ): string {
        foreach ( $data as $value ) {
            $sanitized_value = sanitize_email( $value );
            if ( filter_var( $sanitized_value, FILTER_VALIDATE_EMAIL ) ) {
                return $value;
            }
        }

        return '';
    }

    private function get_form_by_request(): ?Form_Snapshot {
        $post_id = sanitize_text_field( $_POST['post_id'] ?? '' );
        $form_id = sanitize_text_field( $_POST['form_id'] ?? '' );

        return Form_Snapshot_Repository::instance()->find( $post_id, $form_id );
    }

    private function get_forms_from_post_id( int $post_id ): array {
        $form_ids           = array();
        $elementor_metadata = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! is_string( $elementor_metadata ) || $elementor_metadata === '' ) {
            return $form_ids;
        }

        $elementor_metadata = json_decode( $elementor_metadata, true );
        if ( ! is_array( $elementor_metadata ) ) {
            return $form_ids;
        }

        foreach ( $elementor_metadata as $element ) {
            $form_ids = array_merge( $form_ids, $this->find_form_widget( $element ) );
        }

        return $form_ids;
    }

    private function get_elementor_posts(): array {
        $args = array(
            'post_type'      => array( 'page', 'post', 'product', 'elementor_library' ),
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_elementor_data',
                    'value'   => '"widgetType":"form"',
                    'compare' => 'LIKE',
                ),
            ),
        );

        $post_ids = get_posts( $args );

        return $post_ids;
    }
}
