<?php
/**
 * marketplace/templates/marketplace-shell.php
 *
 * The persistent wrapper rendered once by NEXORA_MARKET_CORE::render_marketplace().
 * The .market-dynamic-body starts with a loading state;
 * market.js fires the AJAX call on DOMContentLoaded to load the browse view.
 *
 * $role is set by class-market-core.php before this include.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="nexora-marketplace">

    <!-- Header -->
    <div class="market-top">

        <div class="market-heading">
            <h3 class="market-home-btn">Marketplace</h3>
            <span>Browse and manage marketplace products</span>
        </div>

        <!-- Tabs -->
        <div class="market-tabs">

            <button class="market-tab active" data-view="browse">Browse</button>
            <button class="market-tab" data-view="add-product">Add Product</button>
            <button class="market-tab" data-view="my-products">My Products</button>
            <button class="market-tab" data-view="orders">Orders</button>
            <button class="market-tab" data-view="earnings">Earnings</button>
            <button class="market-tab" data-view="history">History</button>

        </div>

    </div>

    <!-- Dynamic Body — loaded via AJAX on first paint -->
    <div class="market-dynamic-body">
        <div class="market-loading">
            <div class="market-spinner"></div>
            <span>Loading…</span>
        </div>
    </div>

</div>

<script>
/**
 * Kick off the browse view immediately after the shell renders.
 * Runs once; after this, tab clicks handle everything.
 */
jQuery( document ).ready( function ( $ ) {
    if ( typeof nexora_market === 'undefined' ) return;

    $.ajax( {
        url  : nexora_market.ajax_url,
        type : 'POST',
        data : {
            action : 'nexora_market_tab',
            nonce  : nexora_market.nonce,
            type   : 'browse',
        },
        success: function ( res ) {
            if ( res.success && res.data && res.data.html ) {
                $( '.market-dynamic-body' ).html( res.data.html );
            }
        },
    } );
} );
</script>
