<?php
/**
 * marketplace/templates/my-products.php
 *
 * Shows all products owned by the current logged-in user.
 * Inline edit (price / stock) and delete are handled by market.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id  = get_current_user_id();
$products = NEXORA_MARKET_HELPER::get_my_products( $user_id );
?>

<div class="market-panel">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <h2 style="margin:0; border:none; padding:0;">My Products</h2>
        <button class="mk-btn mk-btn-primary market-tab-link" data-view="add-product">
            + Add Product
        </button>
    </div>

    <?php if ( empty( $products ) ) : ?>

        <div class="market-empty">
            <div class="market-empty-illustration">
                <svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Box -->
                    <rect x="10" y="24" width="36" height="26" rx="4" fill="#dbeafe" stroke="#3b82f6" stroke-width="2"/>
                    <path d="M10 30h36" stroke="#3b82f6" stroke-width="1.5"/>
                    <!-- Flaps -->
                    <path d="M10 24l8-10h20l8 10" stroke="#3b82f6" stroke-width="2" stroke-linejoin="round" fill="#eff6ff"/>
                    <path d="M28 14v10" stroke="#3b82f6" stroke-width="1.5" stroke-dasharray="2 2"/>
                    <!-- Plus badge -->
                    <circle cx="42" cy="14" r="8" fill="#2563eb"/>
                    <path d="M42 10v8M38 14h8" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <h4>No products listed</h4>
            <p>You haven't added any products yet. Start selling by listing your first product.</p>
            <button class="mk-btn mk-btn-primary market-tab-link" data-view="add-product">
                + Add Your First Product
            </button>
        </div>

    <?php else : ?>

        <table class="market-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>Price (₹)</th>
                    <th>Stock</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>

                <?php foreach ( $products as $p ) :
                    $status_class = in_array( $p['status'], [ 'active', 'pending', 'inactive' ], true )
                        ? $p['status'] : 'active';
                    $added = date( 'd M Y', strtotime( $p['created_at'] ) );
                ?>

                <tr data-product-id="<?php echo esc_attr( $p['id'] ); ?>">

                    <td style="color:var(--mk-muted); font-size:12px;">
                        #<?php echo esc_html( $p['id'] ); ?>
                    </td>

                    <td style="font-weight:600;">
                        <?php echo esc_html( $p['title'] ); ?>
                    </td>

                    <td class="mk-price-cell">
                        ₹<?php echo number_format( (float) $p['price'], 2 ); ?>
                    </td>

                    <td class="mk-stock-cell">
                        <?php echo (int) $p['stock_qty']; ?>
                    </td>

                    <td>
                        <span class="market-badge <?php echo esc_attr( $p['source_type'] ); ?>">
                            <?php echo esc_html( ucfirst( $p['source_type'] ) ); ?>
                        </span>
                    </td>

                    <td>
                        <span class="mk-status <?php echo esc_attr( $status_class ); ?>">
                            <?php echo esc_html( ucfirst( $p['status'] ) ); ?>
                        </span>
                    </td>

                    <td style="color:var(--mk-muted); font-size:12.5px;">
                        <?php echo esc_html( $added ); ?>
                    </td>

                    <td>
                        <button class="mk-btn mk-btn-ghost mk-edit-btn">Edit</button>
                        <button class="mk-btn mk-btn-ghost mk-save-btn" style="display:none;">Save</button>
                        <button class="mk-btn mk-btn-ghost mk-cancel-btn" style="display:none;">Cancel</button>
                        <button class="mk-btn mk-btn-danger mk-delete-btn">Remove</button>
                    </td>

                </tr>

                <?php endforeach; ?>

            </tbody>
        </table>

    <?php endif; ?>

</div>

<script>
/* Tab-link shortcut buttons inside panels */
jQuery( document ).on( 'click', '.market-tab-link', function() {
    var view = jQuery( this ).data( 'view' );
    jQuery( '.market-tab[data-view="' + view + '"]' ).trigger( 'click' );
} );
</script>
