/**
 * marketplace/assets/market.js
 *
 * Handles all frontend interactions:
 *   - Tab navigation (AJAX view swap)
 *   - Browse → single product view → Add to Cart
 *   - Add Product: Manual form, CSV upload, API import
 *   - My Products: inline edit (price / stock), delete
 *
 * Depends on:  jQuery,  nexora_market  (wp_localize_script)
 *   nexora_market.ajax_url
 *   nexora_market.nonce
 *   nexora_market.cart_url
 *   nexora_market.currency_sym
 */

jQuery( document ).ready( function ( $ ) {

    if ( typeof nexora_market === 'undefined' ) {
        console.warn( '[Nexora Market] nexora_market config not found.' );
        return;
    }

    const M = nexora_market;

    /* =========================================================
       PRIVATE HELPERS
    ========================================================= */

    /** Show a spinner inside .market-dynamic-body */
    function showLoading() {
        $( '.market-dynamic-body' ).html(
            '<div class="market-loading">' +
                '<div class="market-spinner"></div>' +
                '<span>Loading…</span>' +
            '</div>'
        );
    }

    /** Prepend a notice inside $container */
    function showNotice( $container, type, message ) {
        const $notice = $( '<div class="market-notice ' + type + '">' + message + '</div>' );
        $container.prepend( $notice );
        setTimeout( () => $notice.fadeOut( 400, () => $notice.remove() ), 4000 );
    }

    /** Generic AJAX POST — returns a Promise */
    function ajaxPost( action, data, $btn, loadingText ) {

        if ( $btn && loadingText ) {
            $btn.prop( 'disabled', true )
                .data( 'original-text', $btn.text() )
                .text( loadingText );
        }

        return $.ajax( {
            url  : M.ajax_url,
            type : 'POST',
            data : Object.assign( { action, nonce: M.nonce }, data ),
        } ).always( () => {
            if ( $btn && loadingText ) {
                $btn.prop( 'disabled', false ).text( $btn.data( 'original-text' ) || $btn.text() );
            }
        } );
    }

    /* =========================================================
       TAB NAVIGATION
    ========================================================= */

    /** Load any view into .market-dynamic-body */
    function loadView( view, extraData ) {

        showLoading();

        $.ajax( {
            url  : M.ajax_url,
            type : 'POST',
            data : Object.assign( { action: 'nexora_market_tab', nonce: M.nonce, type: view }, extraData ),
            success( res ) {
                if ( res.success && res.data && res.data.html ) {
                    $( '.market-dynamic-body' ).html( res.data.html );
                    initViewBindings( view );
                } else {
                    $( '.market-dynamic-body' ).html(
                        '<div class="market-notice error">Could not load view. Please try again.</div>'
                    );
                }
            },
            error() {
                $( '.market-dynamic-body' ).html(
                    '<div class="market-notice error">Network error. Please refresh the page.</div>'
                );
            },
        } );
    }

    /* Tab button click */
    $( document ).on( 'click', '.market-tab', function () {
        const $tab = $( this );
        if ( $tab.hasClass( 'active' ) ) return;

        $( '.market-tab' ).removeClass( 'active' );
        $tab.addClass( 'active' );

        loadView( $tab.data( 'view' ) );
    } );

    /* Clicking the heading always returns to browse */
    $( document ).on( 'click', '.market-home-btn', function () {
        $( '.market-tab' ).removeClass( 'active' );
        $( '.market-tab[data-view="browse"]' ).addClass( 'active' );
        loadView( 'browse' );
    } );

    /* Shortcut buttons inside panels (e.g. "+ Add Product" in My Products) */
    $( document ).on( 'click', '.market-tab-link', function () {
        const view = $( this ).data( 'view' );
        $( '.market-tab' ).removeClass( 'active' );
        $( '.market-tab[data-view="' + view + '"]' ).addClass( 'active' );
        loadView( view );
    } );

    /* =========================================================
       INIT BINDINGS PER VIEW
    ========================================================= */

    function initViewBindings( view ) {
        const $body = $( '.market-dynamic-body' );

        if ( view === 'browse' )      initBrowse( $body );
        if ( view === 'add-product' ) initAddProduct( $body );
        if ( view === 'my-products' ) initMyProducts( $body );
    }

    /* =========================================================
       BROWSE VIEW — product card click → single product
    ========================================================= */

    function initBrowse( $body ) {

        $body.on( 'click', '.market-view-product', function () {

            const productId = $( this ).closest( '.market-card' ).data( 'product-id' )
                           || $( this ).data( 'id' );

            showLoading();

            $.ajax( {
                url  : M.ajax_url,
                type : 'POST',
                data : {
                    action     : 'nexora_market_single_product',
                    nonce      : M.nonce,
                    product_id : productId,
                },
                success( res ) {
                    if ( res.success && res.data && res.data.html ) {
                        $( '.market-dynamic-body' ).html( res.data.html );
                        initSingleProduct();
                    } else {
                        $( '.market-dynamic-body' ).html(
                            '<div class="market-notice error">Could not load product.</div>'
                        );
                    }
                },
                error() {
                    $( '.market-dynamic-body' ).html(
                        '<div class="market-notice error">Network error.</div>'
                    );
                },
            } );
        } );
    }

    /* =========================================================
       SINGLE PRODUCT VIEW — back button + Add to Cart
    ========================================================= */

    function initSingleProduct() {

        const $body = $( '.market-dynamic-body' );

        /* Back to browse */
        $body.on( 'click', '.market-single-back', function () {
            $( '.market-tab' ).removeClass( 'active' );
            $( '.market-tab[data-view="browse"]' ).addClass( 'active' );
            loadView( 'browse' );
        } );

        /* Add to Cart */
        $body.on( 'click', '#market-add-to-cart', function () {

            const $btn      = $( this );
            const productId = $btn.data( 'product-id' );
            const qty       = parseInt( $body.find( '#market-qty' ).val() || 1, 10 );

            $btn.prop( 'disabled', true ).text( 'Adding…' );

            $.ajax( {
                url  : M.ajax_url,
                type : 'POST',
                data : {
                    action     : 'nexora_market_add_to_cart',
                    nonce      : M.nonce,
                    product_id : productId,
                    qty        : qty,
                },
                success( res ) {
                    $btn.prop( 'disabled', false ).text( 'Add to Cart' );

                    if ( res.success ) {
                        showNotice( $body, 'success',
                            'Added to cart! <a href="' + ( M.cart_url || '#' ) + '">View Cart →</a>'
                        );
                        /* Update WC mini-cart count if fragment refresh available */
                        $( document.body ).trigger( 'wc_fragment_refresh' );
                    } else {
                        showNotice( $body, 'error', res.data?.message || 'Could not add to cart.' );
                    }
                },
                error() {
                    $btn.prop( 'disabled', false ).text( 'Add to Cart' );
                    showNotice( $body, 'error', 'Network error.' );
                },
            } );
        } );
    }

    /* =========================================================
       ADD PRODUCT — method picker + three forms
    ========================================================= */

    function initAddProduct( $body ) {

        /* ── Method card toggle ───────────────────────────── */
        $body.on( 'click', '.upload-card', function () {
            $body.find( '.upload-card' ).removeClass( 'active' );
            $( this ).addClass( 'active' );

            const method = $( this ).data( 'method' );
            $body.find( '.market-upload-form-area' ).hide();
            $body.find( '#market-form-' + method ).show();
        } );

        /* ── Manual form ──────────────────────────────────── */
        $body.on( 'click', '#market-manual-submit', function () {
            submitManualProduct( $body, $( this ) );
        } );

        /* ── CSV: open file picker on drop-zone click ─────── */
        $body.on( 'click', '.market-csv-drop', function ( e ) {
            if ( $( e.target ).is( 'input' ) ) return;
            $body.find( '#market-csv-input' ).trigger( 'click' );
        } );

        $body.on( 'change', '#market-csv-input', function () {
            const name = this.files[0] ? this.files[0].name : '';
            $body.find( '.csv-chosen-name' ).text( name );
        } );

        /* CSV drag-over styles */
        $body.on( 'dragover', '.market-csv-drop', function ( e ) {
            e.preventDefault();
            $( this ).addClass( 'dragover' );
        } );
        $body.on( 'dragleave drop', '.market-csv-drop', function () {
            $( this ).removeClass( 'dragover' );
        } );
        $body.on( 'drop', '.market-csv-drop', function ( e ) {
            e.preventDefault();
            const files = e.originalEvent.dataTransfer.files;
            if ( files.length ) {
                $body.find( '#market-csv-input' )[0].files = files;
                $body.find( '.csv-chosen-name' ).text( files[0].name );
            }
        } );

        /* ── CSV submit ───────────────────────────────────── */
        $body.on( 'click', '#market-csv-submit', function () {
            submitCsvImport( $body, $( this ) );
        } );

        /* ── CSV template download ────────────────────────── */
        $body.on( 'click', '#market-csv-template', function ( e ) {
            e.preventDefault();
            window.location.href = M.ajax_url + '?action=nexora_market_csv_template&nonce=' + M.nonce;
        } );

        /* ── API import submit ────────────────────────────── */
        $body.on( 'click', '#market-api-submit', function () {
            submitApiImport( $body, $( this ) );
        } );
    }

    /* ── Manual product submit ──────────────────────────────── */
    function submitManualProduct( $body, $btn ) {

        const title = $body.find( '#mk-title' ).val().trim();
        const price = parseFloat( $body.find( '#mk-price' ).val() );

        if ( ! title ) {
            return showNotice( $body, 'error', 'Product title is required.' );
        }
        if ( ! price || price <= 0 ) {
            return showNotice( $body, 'error', 'Price must be greater than 0.' );
        }

        ajaxPost(
            'nexora_market_add_manual',
            {
                title        : title,
                price        : price,
                sale_price   : $body.find( '#mk-sale-price' ).val(),
                stock_qty    : $body.find( '#mk-stock' ).val()    || 0,
                description  : $body.find( '#mk-desc' ).val()     || '',
                short_desc   : $body.find( '#mk-short-desc' ).val() || '',
                category     : $body.find( '#mk-category' ).val() || '',
                tags         : $body.find( '#mk-tags' ).val()     || '',
                sku          : $body.find( '#mk-sku' ).val()      || '',
                product_type : $body.find( '#mk-type' ).val()     || 'simple',
                image_id     : $body.find( '#mk-image-id' ).val() || 0,
            },
            $btn,
            'Saving…'
        ).then( res => {
            if ( res.success ) {
                showNotice( $body, 'success', '✓ Product added successfully!' );
                $body.find( '#mk-title, #mk-price, #mk-sale-price, #mk-stock, #mk-desc, #mk-short-desc, #mk-sku, #mk-tags, #mk-category' ).val( '' );
            } else {
                showNotice( $body, 'error', res.data?.message || 'Error saving product.' );
            }
        } ).catch( () => showNotice( $body, 'error', 'Network error.' ) );
    }

    /* ── CSV import submit ──────────────────────────────────── */
    function submitCsvImport( $body, $btn ) {

        const fileInput = $body.find( '#market-csv-input' )[0];

        if ( ! fileInput || ! fileInput.files.length ) {
            return showNotice( $body, 'error', 'Please select a CSV file first.' );
        }

        const formData = new FormData();
        formData.append( 'action',   'nexora_market_csv_import' );
        formData.append( 'nonce',    M.nonce );
        formData.append( 'csv_file', fileInput.files[0] );

        $btn.prop( 'disabled', true ).text( 'Importing…' );

        $.ajax( {
            url         : M.ajax_url,
            type        : 'POST',
            data        : formData,
            processData : false,
            contentType : false,
            success( res ) {
                $btn.prop( 'disabled', false ).text( 'Upload CSV' );
                if ( res.success ) {
                    const d = res.data;
                    showNotice( $body, 'success',
                        `✓ ${d.imported} product(s) imported` +
                        ( d.skipped ? `, ${d.skipped} row(s) skipped.` : '.' )
                    );
                    $body.find( '.csv-chosen-name' ).text( '' );
                    fileInput.value = '';
                } else {
                    showNotice( $body, 'error', res.data?.message || 'Import failed.' );
                }
            },
            error() {
                $btn.prop( 'disabled', false ).text( 'Upload CSV' );
                showNotice( $body, 'error', 'Network error.' );
            },
        } );
    }

    /* ── API import submit ──────────────────────────────────── */
    function submitApiImport( $body, $btn ) {

        const endpoint = $body.find( '#mk-api-endpoint' ).val().trim();

        if ( ! endpoint ) {
            return showNotice( $body, 'error', 'API Endpoint URL is required.' );
        }

        ajaxPost(
            'nexora_market_api_import',
            {
                label          : $body.find( '#mk-api-label' ).val()      || 'My API Store',
                endpoint_url   : endpoint,
                api_key        : $body.find( '#mk-api-key' ).val()        || '',
                sync_method    : $body.find( '#mk-sync-method' ).val()    || 'cron',
                webhook_secret : $body.find( '#mk-webhook-secret' ).val() || '',
            },
            $btn,
            'Connecting…'
        ).then( res => {
            if ( res.success ) {
                const synced = res.data?.synced || 0;
                showNotice( $body, 'success',
                    `✓ API connected. ${synced} product(s) imported. Sync is now active.`
                );
                $body.find( '#mk-api-endpoint, #mk-api-key, #mk-webhook-secret' ).val( '' );
            } else {
                showNotice( $body, 'error', res.data?.message || 'Connection failed.' );
            }
        } ).catch( () => showNotice( $body, 'error', 'Network error.' ) );
    }

    /* =========================================================
       MY PRODUCTS — inline edit + delete
    ========================================================= */

    function initMyProducts( $body ) {

        /* Edit → switch cells to inputs */
        $body.on( 'click', '.mk-edit-btn', function () {

            const $row  = $( this ).closest( 'tr' );
            const price = $row.find( '.mk-price-cell' ).text().replace( /[^\d.]/g, '' ).trim();
            const stock = $row.find( '.mk-stock-cell' ).text().trim();

            $row.find( '.mk-price-cell' ).html(
                '<input class="market-edit-row" type="number" min="0" step="0.01" value="' + price + '" />'
            );
            $row.find( '.mk-stock-cell' ).html(
                '<input class="market-edit-row" type="number" min="0" value="' + stock + '" />'
            );

            $( this ).hide();
            $row.find( '.mk-delete-btn' ).hide();
            $row.find( '.mk-save-btn, .mk-cancel-btn' ).show();
        } );

        /* Cancel → reload view */
        $body.on( 'click', '.mk-cancel-btn', function () {
            loadView( 'my-products' );
        } );

        /* Save */
        $body.on( 'click', '.mk-save-btn', function () {

            const $row      = $( this ).closest( 'tr' );
            const productId = $row.data( 'product-id' );
            const price     = $row.find( '.mk-price-cell input' ).val();
            const stock     = $row.find( '.mk-stock-cell input' ).val();
            const $btn      = $( this );

            ajaxPost(
                'nexora_market_update_product',
                { product_id: productId, price, stock_qty: stock },
                $btn,
                'Saving…'
            ).then( res => {
                if ( res.success ) {
                    loadView( 'my-products' );
                } else {
                    alert( res.data?.message || 'Could not save.' );
                }
            } ).catch( () => alert( 'Network error.' ) );
        } );

        /* Delete */
        $body.on( 'click', '.mk-delete-btn', function () {

            if ( ! confirm( 'Remove this product from the marketplace?' ) ) return;

            const $row      = $( this ).closest( 'tr' );
            const productId = $row.data( 'product-id' );

            ajaxPost( 'nexora_market_delete_product', { product_id: productId } )
                .then( res => {
                    if ( res.success ) {
                        $row.fadeOut( 220, function () { $( this ).remove(); } );
                    } else {
                        alert( res.data?.message || 'Could not delete.' );
                    }
                } )
                .catch( () => alert( 'Network error.' ) );
        } );
    }

} );
