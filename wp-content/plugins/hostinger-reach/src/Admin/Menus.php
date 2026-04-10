<?php

namespace Hostinger\Reach\Admin;

use Hostinger\WpMenuManager\Menus as WpMenu;

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

class Menus {

    public function init(): void {
        add_action( 'admin_menu', array( $this, 'add_main_menu_page' ), 2 );
        add_filter( 'hostinger_menu_subpages', array( $this, 'add_sub_menu_page' ), 20 );
        add_filter( 'hostinger_admin_menu_bar_items', array( $this, 'add_admin_bar_items' ), 110 );
    }

    public function add_main_menu_page(): void {
        add_menu_page(
            $this->get_title(),
            $this->get_title(),
            'manage_options',
            'hostinger-reach',
            array( $this, 'render_plugin_content' ),
            $this->get_icon(),
            1.5
        );
    }

    public function add_sub_menu_page( array $submenus ): array {
        $submenus[] = array(
            'page_title' => $this->get_title(),
            'menu_title' => __( 'Reach', 'hostinger-reach' ),
            'capability' => 'manage_options',
            'menu_slug'  => 'hostinger-reach',
            'callback'   => array( $this, 'render_plugin_content' ),
            'menu_order' => 10,
        );

        return $submenus;
    }

    public function add_admin_bar_items( array $menu_items ): array {
        if ( ! current_user_can( 'manage_options' ) ) {
            return $menu_items;
        }

        $menu_items[] = array(
            'id'    => 'hostinger-reach',
            'title' => esc_html( $this->get_title() ),
            'href'  => self::get_reach_admin_url(),
        );

        return $menu_items;
    }

    public function render_plugin_content(): void {
        echo wp_kses_post( WpMenu::renderMenuNavigation() );

        ?>
        <div id="hostinger-reach-app" class="hostinger-reach-app"></div>
        <?php
    }

    public static function get_reach_admin_url(): string {
        return admin_url( 'admin.php?page=hostinger-reach' );
    }

    private function get_title(): string {
        return __( 'Hostinger Reach', 'hostinger-reach' );
    }

    private function get_icon(): string {
        return plugin_dir_url( dirname( __DIR__ ) ) . 'frontend/vue/assets/images/icons/reach-menu-logo.svg';
    }
}
