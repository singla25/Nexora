<?php
/**
 * templates/add-product.php
 *
 * Three upload methods: Manual Entry / CSV Upload / API Import.
 * Method card click reveals matching form.
 * All submissions handled by market.js → class-market-ajax.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="market-panel">

    <h2>Add Product</h2>

    <!-- ── Method picker ──────────────────────────────────── -->
    <div class="market-upload-options">

        <div class="upload-card active" data-method="manual">
            <span class="upload-icon">✏️</span>
            <h3>Manual Entry</h3>
            <p>Fill out a form to list a single product</p>
        </div>

        <div class="upload-card" data-method="csv">
            <span class="upload-icon">📄</span>
            <h3>CSV Upload</h3>
            <p>Bulk import products from a spreadsheet file</p>
        </div>

        <div class="upload-card" data-method="api">
            <span class="upload-icon">🔗</span>
            <h3>API Connect</h3>
            <p>Link your business API to auto-import products</p>
        </div>

    </div>

    <!-- ══════════════════════════════════════════════════════
         MANUAL FORM
    ══════════════════════════════════════════════════════ -->
    <div class="market-upload-form-area" id="market-form-manual">

        <div class="market-form">

            <div class="market-form-row">
                <div class="market-field">
                    <label for="mk-title">Product Title <span style="color:var(--mk-danger)">*</span></label>
                    <input type="text" id="mk-title" placeholder="e.g. Wireless Headphones Pro" />
                </div>
                <div class="market-field">
                    <label for="mk-type">Product Type</label>
                    <select id="mk-type">
                        <option value="simple">Physical</option>
                        <option value="digital">Digital / Download</option>
                        <option value="service">Service</option>
                    </select>
                </div>
            </div>

            <div class="market-form-row">
                <div class="market-field">
                    <label for="mk-price">Price (₹) <span style="color:var(--mk-danger)">*</span></label>
                    <input type="number" id="mk-price" placeholder="0.00" min="0" step="0.01" />
                </div>
                <div class="market-field">
                    <label for="mk-sale-price">Sale Price (₹) <span style="color:var(--mk-muted); font-weight:400;">optional</span></label>
                    <input type="number" id="mk-sale-price" placeholder="Leave blank if not on sale" min="0" step="0.01" />
                </div>
            </div>

            <div class="market-form-row">
                <div class="market-field">
                    <label for="mk-stock">Stock Quantity</label>
                    <input type="number" id="mk-stock" placeholder="0" min="0" />
                </div>
                <div class="market-field">
                    <label for="mk-sku">SKU <span style="color:var(--mk-muted); font-weight:400;">optional</span></label>
                    <input type="text" id="mk-sku" placeholder="e.g. WHP-001" />
                </div>
            </div>

            <div class="market-form-row">
                <div class="market-field">
                    <label for="mk-category">Category</label>
                    <input type="text" id="mk-category" placeholder="e.g. Electronics" />
                </div>
                <div class="market-field">
                    <label for="mk-tags">Tags <span style="color:var(--mk-muted); font-weight:400;">comma separated</span></label>
                    <input type="text" id="mk-tags" placeholder="e.g. wireless, audio, gadget" />
                </div>
            </div>

            <div class="market-field">
                <label for="mk-short-desc">Short Description</label>
                <textarea id="mk-short-desc" rows="2" placeholder="One-line summary shown on product cards…"></textarea>
            </div>

            <div class="market-field">
                <label for="mk-desc">Full Description</label>
                <textarea id="mk-desc" rows="5" placeholder="Detailed product description, features, specs…"></textarea>
            </div>

            <!-- Hidden image ID — populated by WP media uploader (future) -->
            <input type="hidden" id="mk-image-id" value="" />

            <div>
                <button id="market-manual-submit" class="mk-btn mk-btn-primary">
                    Add Product
                </button>
            </div>

        </div>

    </div><!-- #market-form-manual -->

    <!-- ══════════════════════════════════════════════════════
         CSV FORM
    ══════════════════════════════════════════════════════ -->
    <div class="market-upload-form-area" id="market-form-csv" style="display:none;">

        <div class="market-csv-drop">
            <span class="csv-icon">📁</span>
            <p><strong>Click to choose a CSV file</strong> or drag &amp; drop here</p>
            <p class="csv-chosen-name" style="color:var(--mk-accent); font-weight:600; margin-top:6px;"></p>
        </div>

        <input type="file" id="market-csv-input" accept=".csv" style="display:none;" />

        <!-- Column spec -->
        <div class="market-csv-spec">
            <strong>Required columns:</strong> <code>title</code>, <code>price</code><br>
            <strong>Optional:</strong> <code>sale_price</code>, <code>stock_qty</code>, <code>sku</code>,
            <code>category</code>, <code>tags</code>, <code>product_type</code>,
            <code>description</code>, <code>short_desc</code>, <code>image_url</code>
        </div>

        <div style="margin-top:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <button id="market-csv-submit" class="mk-btn mk-btn-primary">
                Upload &amp; Import
            </button>
            <a href="#" id="market-csv-template" class="mk-btn mk-btn-ghost">
                ⬇ Download Sample CSV
            </a>
        </div>

    </div><!-- #market-form-csv -->

    <!-- ══════════════════════════════════════════════════════
         API FORM
    ══════════════════════════════════════════════════════ -->
    <div class="market-upload-form-area" id="market-form-api" style="display:none;">

        <div class="market-api-form">

            <div class="market-field">
                <label for="mk-api-label">Connection Name</label>
                <input type="text" id="mk-api-label" placeholder="e.g. My Shopify Store" />
            </div>

            <div class="market-field">
                <label for="mk-api-endpoint">
                    API Endpoint URL <span style="color:var(--mk-danger)">*</span>
                </label>
                <input type="url" id="mk-api-endpoint"
                       placeholder="https://your-store.com/api/v1/products" />
            </div>

            <div class="market-field">
                <label for="mk-api-key">API Key / Bearer Token</label>
                <input type="text" id="mk-api-key" placeholder="sk-xxxxxxxxxxxxxxxx"
                       autocomplete="off" />
                <span style="font-size:12px; color:var(--mk-muted); margin-top:4px; display:block;">
                    🔒 Stored encrypted. Never shown again after saving.
                </span>
            </div>

            <div class="market-field">
                <label for="mk-sync-method">Sync Method</label>
                <select id="mk-sync-method">
                    <option value="cron">⏱ Cron — we poll your API on a schedule</option>
                    <option value="webhook">⚡ Webhook — your system pushes updates to us</option>
                    <option value="both">Both — cron as fallback + webhook for real-time</option>
                </select>
            </div>

            <div class="market-field" id="mk-webhook-secret-wrap" style="display:none;">
                <label for="mk-webhook-secret">Webhook Secret</label>
                <input type="text" id="mk-webhook-secret"
                       placeholder="Shared HMAC secret for signature verification"
                       autocomplete="off" />
                <span style="font-size:12px; color:var(--mk-muted); margin-top:4px; display:block;">
                    Your webhook endpoint URL:
                    <code style="background:var(--mk-bg); padding:2px 6px; border-radius:4px; font-size:11px;">
                        <?php echo esc_url( rest_url( 'nexora/v1/webhook/' ) ); ?>{source_id}
                    </code>
                </span>
            </div>

            <div>
                <button id="market-api-submit" class="mk-btn mk-btn-primary">
                    Connect &amp; Import
                </button>
            </div>

        </div>

    </div><!-- #market-form-api -->

</div><!-- .market-panel -->

<style>
/* CSV spec box */
.market-csv-spec {
    margin-top: 16px;
    padding: 12px 16px;
    background: var(--mk-bg);
    border: 1px solid var(--mk-border);
    border-radius: var(--mk-radius);
    font-size: 13px;
    line-height: 1.8;
    color: var(--mk-muted);
}
.market-csv-spec code {
    background: #fff;
    border: 1px solid var(--mk-border);
    border-radius: 4px;
    padding: 1px 5px;
    font-size: 12px;
    color: var(--mk-accent);
}
</style>

<script>
jQuery( document ).ready( function ( $ ) {

    /* Show/hide webhook secret field based on sync method choice */
    $( document ).on( 'change', '#mk-sync-method', function () {
        const val = $( this ).val();
        $( '#mk-webhook-secret-wrap' ).toggle( val === 'webhook' || val === 'both' );
    } );

} );
</script>
