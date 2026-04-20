<?php

namespace Hostinger\Reach\Blocks;


use Hostinger\Reach\Integrations\Reach\ReachFormIntegration;

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

class SubscriptionFormBlock extends Block {
    public string $name = 'subscription';

    public function data(): array {
        return array(
            'endpoint'     => esc_url_raw( rest_url() ) . 'hostinger-reach/v1/contact',
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'translations' => array(
                'thanks' => __( 'Thanks for subscribing.', 'hostinger-reach' ),
                'error'  => __( 'Something went wrong. Please try again.', 'hostinger-reach' ),
            ),
        );
    }

    public function autoloader(): void {
        if ( ! is_admin() || empty( $_GET['hostinger_reach_add_block'] ) ) {
            return;
        }

        if ( $this->functions->block_file_exists( "$this->name-autoloader.js" ) === false ) {
            return;
        }

        $handler = parent::get_block_name() . '-autoloader';

        wp_enqueue_script(
            $handler,
            $this->functions->get_blocks_url() . "$this->name-autoloader.js",
            array( parent::get_block_name() . '-editor' ),
            filemtime( $this->functions->get_block_file_name( "$this->name-autoloader.js" ) ),
            array( 'in_footer' => true )
        );
    }

    public function render( array $attributes ): bool|string {
        ob_start();
        $this->render_block_html( $attributes );

        return ob_get_clean();
    }

    public static function render_block_html( array $attributes, ?string $plugin = null ): void {
        $form_id      = $attributes['formId'] ?? '';
        $show_name    = $attributes['showName'] ?? false;
        $show_surname = $attributes['showSurname'] ?? false;
        $contact_list = $attributes['contactList'] ?? '';
        $tags         = $attributes['tags'] ?? array();
        $layout       = $attributes['layout'] ?? 'default';
        $is_inline    = $layout === 'inline';
        $plugin       = $plugin ?? ReachFormIntegration::INTEGRATION_NAME;
        ?>
        <div class="hostinger-reach-block-subscription-form-wrapper">

            <form id="<?php echo esc_attr( $form_id ); ?>" class="hostinger-reach-block-subscription-form">
                <input type="hidden" name="group" value="<?php echo esc_attr( $contact_list ); ?>">
                <input type="hidden" name="id" value="<?php echo esc_attr( $form_id ); ?>">
                <input type="hidden" name="tags" value="<?php echo esc_attr( implode( ',', $tags ) ); ?>">
                <input type="hidden" name="metadata.plugin" value="<?php echo esc_attr( $plugin ); ?>">

                <div
                    class="hostinger-reach-block-form-fields <?php echo esc_attr( $is_inline ? 'hostinger-reach-block-form-fields--inline' : '' ); ?>">
                    <div class="hostinger-reach-block-form-field">
                        <label
                            for="<?php echo esc_attr( $form_id ); ?>-email"><?php esc_html_e( 'Email', 'hostinger-reach' ); ?>
                            <span class="required">*</span></label>
                        <input type="email" id="<?php echo esc_attr( $form_id ); ?>-email" name="email" required>
                    </div>

                    <?php if ( $show_name ) : ?>
                        <div class="hostinger-reach-block-form-field">
                            <label
                                for="<?php echo esc_attr( $form_id ); ?>-name"><?php esc_html_e( 'Name', 'hostinger-reach' ); ?></label>
                            <input type="text" id="<?php echo esc_attr( $form_id ); ?>-name" name="name">
                        </div>
                    <?php endif; ?>

                    <?php if ( $show_surname ) : ?>
                        <div class="hostinger-reach-block-form-field">
                            <label
                                for="<?php echo esc_attr( $form_id ); ?>-surname"><?php esc_html_e( 'Surname', 'hostinger-reach' ); ?></label>
                            <input type="text" id="<?php echo esc_attr( $form_id ); ?>-surname" name="surname">
                        </div>
                    <?php endif; ?>

                    <button
                        type="submit"
                        class="hostinger-reach-block-submit has-light-color has-color-3-background-color has-text-color has-background has-link-color">
                        <?php esc_html_e( 'Subscribe', 'hostinger-reach' ); ?>
                    </button>
                </div>

                <div class="reach-subscription-message" style="display: none;">
                    <div class="reach-subscription-message__icon" style="display: none;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M21.0455 5.95463C21.4848 6.39397 21.4848 7.10628 21.0455 7.54562L11.5076 17.0835C10.9511 17.64 10.0489 17.64 9.49237 17.0835L5.2045 12.7956C4.76517 12.3563 4.76517 11.644 5.2045 11.2046C5.64384 10.7653 6.35616 10.7653 6.7955 11.2046L10.5 14.9091L19.4545 5.95463C19.8938 5.51529 20.6062 5.51529 21.0455 5.95463Z" fill="#18181A"/>
                        </svg>
                    </div>
                    <div class="reach-subscription-message__text"></div>
                </div>
            </form>
        </div>
        <?php
    }
}
