<?php
/**
 * templates/browse-products.php
 *
 * Loaded via AJAX (nexora_market_tab → 'browse').
 * Shows all active products NOT owned by the current user.
 * Uses NEXORA_MARKET_HELPER::get_products() for clean, joined queries.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$current_user_id = get_current_user_id();

$products = NEXORA_MARKET_HELPER::table_exists( $GLOBALS['wpdb']->prefix . 'nx_products' )
    ? NEXORA_MARKET_HELPER::get_products( $current_user_id, 40 )
    : [];

if ( empty( $products ) ) :
?>

<div class="market-empty">
    <div class="market-empty-illustration">
        <svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg" width="100" height="100">
            <rect x="10" y="20" width="36" height="28" rx="5" fill="#bfdbfe" stroke="#3b82f6" stroke-width="2"/>
            <path d="M20 20v-4a8 8 0 0116 0v4" stroke="#3b82f6" stroke-width="2" stroke-linecap="round"/>
            <circle cx="22" cy="30" r="2" fill="#2563eb"/>
            <circle cx="34" cy="30" r="2" fill="#2563eb"/>
            <path d="M22 37c1.2 2 8.8 2 10 0" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round"/>
            <path d="M44 12l1 3 3 1-3 1-1 3-1-3-3-1 3-1z" fill="#93c5fd"/>
            <path d="M8 10l.7 2 2 .7-2 .7L8 16l-.7-2.6-2-.7 2-.7z" fill="#bfdbfe"/>
        </svg>
    </div>
    <h4>No products yet</h4>
    <p>The marketplace is empty right now.<br>Be the first to list a product and start selling!</p>
    <div class="market-empty-actions">
        <button class="mk-btn mk-btn-primary market-tab-link" data-view="add-product">
            + Add First Product
        </button>
    </div>
</div>

<?php else : ?>

<div class="market-grid">

    <?php foreach ( $products as $product ) :
        $badge_class = esc_attr( $product['source_type'] ?? 'manual' );
        $badge_label = ucfirst( $badge_class );
        $owner_name  = esc_html( $product['owner_name'] ?? 'Vendor' );
        $price       = (float) $product['price'];
        $sale        = ! empty( $product['sale_price'] ) ? (float) $product['sale_price'] : null;
    ?>

    <div class="market-card" data-product-id="<?php echo esc_attr( $product['id'] ); ?>">

        <div class="market-image">
            <?php if ( ! empty( $product['image_url'] ) ) : ?>
                <img src="<?php echo esc_url( $product['image_url'] ); ?>"
                     alt="<?php echo esc_attr( $product['title'] ); ?>" />
            <?php endif; ?>
        </div>

        <div class="market-card-content">

            <span class="market-badge <?php echo $badge_class; ?>">
                <?php echo $badge_label; ?>
            </span>

            <h3><?php echo esc_html( $product['title'] ); ?></h3>

            <p class="market-price">
                <?php if ( $sale ) : ?>
                    <span style="font-size:13px; color:var(--mk-muted); text-decoration:line-through;">
                        ₹<?php echo number_format( $price, 2 ); ?>
                    </span>
                    ₹<?php echo number_format( $sale, 2 ); ?>
                <?php else : ?>
                    ₹<?php echo number_format( $price, 2 ); ?>
                <?php endif; ?>
            </p>

            <p class="market-owner">Sold by <?php echo $owner_name; ?></p>

            <button class="market-view-product"
                    data-id="<?php echo esc_attr( $product['id'] ); ?>">
                View Product
            </button>

        </div>

    </div>

    <?php endforeach; ?>

</div><!-- .market-grid -->

<?php endif; ?>

<style>
.market-empty-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    margin-top: 6px;
}
</style>
