<?php
/**
 * templates/single-product.php
 *
 * Loaded via AJAX (nexora_market_single_product action).
 * $product  — ARRAY_A row from nx_products, set by class-market-ajax.php
 *             before this file is included.
 *
 * Shows: image gallery, title, price, seller info, description,
 *        stock status, Add to Cart (via WooCommerce), back button.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( empty( $product ) ) {
    echo '<div class="market-notice error">Product not found.</div>';
    return;
}

/* ── Helpers ──────────────────────────────────────────────────── */
$owner     = get_userdata( (int) $product['owner_user_id'] );
$owner_name= $owner ? esc_html( $owner->display_name ) : 'Vendor';
$avatar    = get_avatar_url( (int) $product['owner_user_id'], [ 'size' => 48 ] );

$price     = (float) $product['price'];
$sale      = ! empty( $product['sale_price'] ) ? (float) $product['sale_price'] : null;
$display_price = $sale ?? $price;
$saving    = $sale ? round( ( ( $price - $sale ) / $price ) * 100 ) : 0;

$stock     = (int) $product['stock_qty'];
$in_stock  = $stock > 0;

$type_label = ucfirst( str_replace( '_', ' ', $product['product_type'] ?? 'simple' ) );
$source_label = ucfirst( $product['source_type'] ?? 'manual' );

/* Gallery URLs */
$gallery = [];
if ( ! empty( $product['image_url'] ) ) {
    $gallery[] = $product['image_url'];
}
if ( ! empty( $product['gallery'] ) ) {
    $extra = json_decode( $product['gallery'], true );
    if ( is_array( $extra ) ) {
        $gallery = array_unique( array_merge( $gallery, $extra ) );
    }
}

$wc_id      = (int) ( $product['wc_product_id'] ?? 0 );
$can_buy    = $wc_id && $in_stock && get_current_user_id() !== (int) $product['owner_user_id'];
?>

<div class="market-panel market-single-product">

    <!-- Back button -->
    <button class="mk-btn mk-btn-ghost market-single-back" style="margin-bottom:18px;">
        ← Back to Marketplace
    </button>

    <div class="market-single-layout">

        <!-- ── LEFT: image gallery ──────────────────────── -->
        <div class="market-single-gallery-col">

            <?php if ( ! empty( $gallery ) ) : ?>
                <div class="market-single-main-img-wrap">
                    <img id="mk-main-img"
                         class="market-single-main-img"
                         src="<?php echo esc_url( $gallery[0] ); ?>"
                         alt="<?php echo esc_attr( $product['title'] ); ?>" />
                </div>

                <?php if ( count( $gallery ) > 1 ) : ?>
                <div class="market-single-thumbs">
                    <?php foreach ( $gallery as $i => $img_url ) : ?>
                        <img class="market-single-thumb <?php echo $i === 0 ? 'active' : ''; ?>"
                             src="<?php echo esc_url( $img_url ); ?>"
                             alt="<?php echo esc_attr( $product['title'] ); ?> — image <?php echo $i + 1; ?>"
                             data-full="<?php echo esc_url( $img_url ); ?>" />
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            <?php else : ?>
                <div class="market-single-img-placeholder">🖼️</div>
            <?php endif; ?>

        </div>

        <!-- ── RIGHT: product details ───────────────────── -->
        <div class="market-single-info-col">

            <!-- Badges row -->
            <div class="market-single-badges">
                <span class="market-badge <?php echo esc_attr( $product['source_type'] ); ?>">
                    <?php echo esc_html( $source_label ); ?>
                </span>
                <?php if ( $product['category'] ) : ?>
                    <span class="market-badge">
                        <?php echo esc_html( $product['category'] ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( ! $in_stock ) : ?>
                    <span class="mk-status inactive">Out of Stock</span>
                <?php elseif ( $stock <= 5 ) : ?>
                    <span class="mk-status pending">Only <?php echo $stock; ?> left</span>
                <?php else : ?>
                    <span class="mk-status active">In Stock</span>
                <?php endif; ?>
            </div>

            <!-- Title -->
            <h2 class="market-single-title">
                <?php echo esc_html( $product['title'] ); ?>
            </h2>

            <!-- SKU -->
            <?php if ( ! empty( $product['sku'] ) ) : ?>
                <p class="market-single-sku">SKU: <?php echo esc_html( $product['sku'] ); ?></p>
            <?php endif; ?>

            <!-- Price -->
            <div class="market-single-price-row">
                <span class="market-single-price">
                    ₹<?php echo number_format( $display_price, 2 ); ?>
                </span>

                <?php if ( $sale ) : ?>
                    <span class="market-single-original-price">
                        ₹<?php echo number_format( $price, 2 ); ?>
                    </span>
                    <span class="market-single-saving">
                        <?php echo $saving; ?>% off
                    </span>
                <?php endif; ?>
            </div>

            <!-- Short description -->
            <?php if ( ! empty( $product['short_description'] ) ) : ?>
                <p class="market-single-short-desc">
                    <?php echo wp_kses_post( $product['short_description'] ); ?>
                </p>
            <?php endif; ?>

            <!-- Seller strip -->
            <div class="market-single-seller">
                <img class="market-single-seller-avatar"
                     src="<?php echo esc_url( $avatar ); ?>"
                     alt="<?php echo esc_attr( $owner_name ); ?>" />
                <div>
                    <div class="market-single-seller-label">Sold by</div>
                    <div class="market-single-seller-name"><?php echo $owner_name; ?></div>
                </div>
            </div>

            <!-- Add to Cart -->
            <?php if ( $can_buy ) : ?>
                <div class="market-single-cta">
                    <input type="number"
                           id="market-qty"
                           value="1"
                           min="1"
                           max="<?php echo esc_attr( $stock ); ?>"
                           class="market-qty-input"
                           aria-label="Quantity" />

                    <button id="market-add-to-cart"
                            class="mk-btn mk-btn-primary market-add-to-cart-btn"
                            data-product-id="<?php echo esc_attr( $product['id'] ); ?>">
                        🛒 Add to Cart
                    </button>
                </div>

            <?php elseif ( get_current_user_id() === (int) $product['owner_user_id'] ) : ?>
                <div class="market-notice info" style="margin-top:16px;">
                    This is your product. Buyers can add it to their cart.
                </div>

            <?php elseif ( ! $in_stock ) : ?>
                <button class="mk-btn mk-btn-ghost" disabled style="margin-top:16px; cursor:not-allowed;">
                    Out of Stock
                </button>

            <?php else : ?>
                <div class="market-notice info" style="margin-top:16px;">
                    Please <a href="<?php echo esc_url( wp_login_url() ); ?>">log in</a> to purchase.
                </div>
            <?php endif; ?>

        </div><!-- .market-single-info-col -->

    </div><!-- .market-single-layout -->

    <!-- ── Full description ───────────────────────────────── -->
    <?php if ( ! empty( $product['description'] ) ) : ?>
        <div class="market-single-full-desc">
            <h3>Description</h3>
            <div class="market-single-desc-body">
                <?php echo wp_kses_post( $product['description'] ); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── Product meta table ────────────────────────────── -->
    <div class="market-single-meta-table">
        <h3>Product Details</h3>
        <table class="market-table">
            <tbody>
                <tr>
                    <th style="width:160px;">Type</th>
                    <td><?php echo esc_html( $type_label ); ?></td>
                </tr>
                <tr>
                    <th>Source</th>
                    <td><?php echo esc_html( $source_label ); ?></td>
                </tr>
                <?php if ( ! empty( $product['category'] ) ) : ?>
                <tr>
                    <th>Category</th>
                    <td><?php echo esc_html( $product['category'] ); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ( ! empty( $product['tags'] ) ) : ?>
                <tr>
                    <th>Tags</th>
                    <td><?php echo esc_html( $product['tags'] ); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Stock</th>
                    <td><?php echo $in_stock ? esc_html( $stock ) . ' units available' : 'Out of stock'; ?></td>
                </tr>
                <?php if ( ! empty( $product['sku'] ) ) : ?>
                <tr>
                    <th>SKU</th>
                    <td><?php echo esc_html( $product['sku'] ); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Listed</th>
                    <td><?php echo esc_html( date( 'd M Y', strtotime( $product['created_at'] ) ) ); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

</div><!-- .market-single-product -->

<style>
/* ── Single product layout ───────────────────────────── */
.market-single-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
    margin-bottom: 32px;
}

.market-single-main-img-wrap {
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid var(--mk-border);
    background: var(--mk-bg);
}

.market-single-main-img {
    width: 100%;
    height: 320px;
    object-fit: cover;
    display: block;
    transition: 0.2s ease;
}

.market-single-img-placeholder {
    width: 100%;
    height: 320px;
    background: var(--mk-bg);
    border: 1px solid var(--mk-border);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 64px;
}

.market-single-thumbs {
    display: flex;
    gap: 8px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.market-single-thumb {
    width: 60px;
    height: 60px;
    border-radius: 6px;
    object-fit: cover;
    border: 2px solid var(--mk-border);
    cursor: pointer;
    transition: 0.15s;
}

.market-single-thumb:hover,
.market-single-thumb.active {
    border-color: var(--mk-accent);
}

/* Info column */
.market-single-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 12px;
    align-items: center;
}

.market-single-title {
    font-size: 22px;
    font-weight: 700;
    color: var(--mk-text);
    margin: 0 0 6px;
    line-height: 1.3;
}

.market-single-sku {
    font-size: 12px;
    color: var(--mk-muted);
    margin: 0 0 14px;
}

.market-single-price-row {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}

.market-single-price {
    font-size: 28px;
    font-weight: 800;
    color: var(--mk-accent);
    line-height: 1;
}

.market-single-original-price {
    font-size: 16px;
    color: var(--mk-muted);
    text-decoration: line-through;
}

.market-single-saving {
    font-size: 13px;
    font-weight: 600;
    background: #dcfce7;
    color: var(--mk-success);
    padding: 3px 8px;
    border-radius: 20px;
}

.market-single-short-desc {
    font-size: 14px;
    color: var(--mk-muted);
    line-height: 1.65;
    margin: 0 0 18px;
}

.market-single-seller {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--mk-bg);
    border: 1px solid var(--mk-border);
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 20px;
}

.market-single-seller-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: 2px solid var(--mk-border);
    object-fit: cover;
}

.market-single-seller-label {
    font-size: 11px;
    color: var(--mk-muted);
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 600;
}

.market-single-seller-name {
    font-size: 14px;
    font-weight: 700;
    color: var(--mk-text);
}

/* CTA */
.market-single-cta {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.market-qty-input {
    width: 72px;
    padding: 9px 12px;
    border: 1px solid var(--mk-border);
    border-radius: 7px;
    font-size: 14px;
    text-align: center;
    color: var(--mk-text);
    background: #fff;
}

.market-add-to-cart-btn {
    flex: 1;
    padding: 11px 20px;
    font-size: 15px;
    font-weight: 600;
}

/* Description + meta sections */
.market-single-full-desc,
.market-single-meta-table {
    border-top: 1px solid var(--mk-border);
    padding-top: 24px;
    margin-top: 24px;
}

.market-single-full-desc h3,
.market-single-meta-table h3 {
    font-size: 15px;
    font-weight: 700;
    margin: 0 0 14px;
    color: var(--mk-text);
}

.market-single-desc-body {
    font-size: 14px;
    color: var(--mk-muted);
    line-height: 1.7;
}

.market-single-meta-table .market-table th {
    color: var(--mk-muted);
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
}

/* Responsive */
@media (max-width: 640px) {
    .market-single-layout {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    .market-single-main-img,
    .market-single-img-placeholder {
        height: 220px;
    }
    .market-single-title { font-size: 18px; }
    .market-single-price { font-size: 22px; }
}
</style>

<script>
jQuery( document ).ready( function ( $ ) {

    /* Thumbnail click — swap main image */
    $( document ).on( 'click', '.market-single-thumb', function () {
        $( '.market-single-thumb' ).removeClass( 'active' );
        $( this ).addClass( 'active' );
        $( '#mk-main-img' ).attr( 'src', $( this ).data( 'full' ) );
    } );

} );
</script>
