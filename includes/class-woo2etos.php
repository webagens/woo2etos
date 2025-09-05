<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Woo2Etos {

    private static $instance = null;
    private $opts = array();

    public static function instance(){
        if ( self::$instance === null ){
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct(){
        // Default options
        $defaults = array(
            'enabled'        => false, // master kill switch
            'auto_hooks'     => false, // trigger on product save/import
            'cron_15'        => false, // fallback check every 15 minutes
            'batch_size'     => 100,
        );
        $opts = get_option( WOO2ETOS_AT_OPTION, array() );
        $this->opts = wp_parse_args( $opts, $defaults );

        // Admin UI
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_post_woo2etos_at_run', array( $this, 'handle_run' ) );

        // Conditional hooks
        if ( $this->opts['enabled'] && $this->opts['auto_hooks'] ){
            add_action( 'woocommerce_after_product_object_save', array( $this, 'maybe_queue_sync' ), 10, 1 );
            add_action( 'woocommerce_rest_insert_product', array( $this, 'rest_inserted' ), 10, 3 );
            add_action( 'woocommerce_product_import_inserted_product_object', array( $this, 'import_inserted' ), 10, 2 );
        }
        add_action( 'init', array( $this, 'register_recurring_sync' ) );

        // Worker
        add_action( 'woo2etos_sync_product', array( $this, 'worker_sync_product' ), 10, 3 );
        add_action( 'woo2etos_collect_products', array( $this, 'collect_products_and_terms' ), 10, 3 );
        add_action( 'woo2etos_run_summary', array( $this, 'run_summary' ) );
    }

    public function register_recurring_sync() {
        if ( $this->opts['enabled'] && $this->opts['cron_15'] 
             && function_exists( 'as_schedule_recurring_action' ) ) {
            if ( false === as_next_scheduled_action( 'woo2etos_sync_recent' ) ) {
                as_schedule_recurring_action( time() + 60, 900, 'woo2etos_sync_recent' );
            }
            add_action( 'woo2etos_sync_recent', array( $this, 'sync_recent' ) );
        }
    }

    /** Admin page */
    public function admin_menu(){
        add_submenu_page(
            'woocommerce',
            'Woo2Etos',
            'Woo2Etos',
            'manage_woocommerce',
            WOO2ETOS_AT_SLUG,
            array( $this, 'render_page' )
        );
    }

    /** Render settings/tools page */
    public function render_page(){
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

        // Save options if posted
        if ( isset($_POST['woo2etos_save']) && check_admin_referer( 'woo2etos_at_save', 'woo2etos_nonce' ) ){
            $this->opts['enabled']    = isset($_POST['enabled']);
            $this->opts['auto_hooks'] = isset($_POST['auto_hooks']);
            $this->opts['cron_15']    = isset($_POST['cron_15']);
            $this->opts['batch_size'] = max(10, intval($_POST['batch_size'] ?? 100));
            update_option( WOO2ETOS_AT_OPTION, $this->opts );
            echo '<div class="notice notice-success"><p>Impostazioni salvate.</p></div>';
        }

        // Ensure attribute button
        if ( isset($_POST['ensure_attr']) && check_admin_referer( 'woo2etos_at_attr', 'woo2etos_nonce2' ) ){
            $result = $this->ensure_attribute();
            if ( is_wp_error($result) ){
                echo '<div class="notice notice-error"><p>Errore: '.esc_html($result->get_error_message()).'</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Attributo controllato/creato correttamente.</p></div>';
            }
        }

        // Flush empty terms
        if ( isset( $_POST['flush_empty'] ) && check_admin_referer( 'woo2etos_at_flush', 'woo2etos_nonce4' ) ){
            $removed = $this->flush_empty_terms();
            echo '<div class="notice notice-success"><p>Rimossi ' . intval( $removed ) . ' termini vuoti.</p></div>';
        }

        // Show start notice if this request triggered the run
        $start_notice = get_transient( 'woo2etos_run_notice' );
        delete_transient( 'woo2etos_run_notice' );
        if ( $start_notice ) {
            echo '<div class="notice notice-info"><p>' . esc_html( $start_notice ) . '</p></div>';
        }

        // Retrieve run result from transient if available
        $run_result = get_transient( 'woo2etos_at_run' );
        delete_transient( 'woo2etos_at_run' );
        if ( $run_result ){
            echo '<div class="notice notice-success"><p>Sincronizzazione completata: ' . intval( $run_result['products'] ) . ' prodotti, ' . intval( $run_result['new_terms'] ) . ' nuovi termini, ' . intval( $run_result['links'] ) . ' associazioni.</p></div>';
        }

        // Retrieve dry-run result from transient if available
        $dry_result = get_transient( 'woo2etos_at_dryrun' );
        delete_transient( 'woo2etos_at_dryrun' );

        ?>
        <div class="wrap">
            <h1>Woo2Etos</h1>
            <h2>Aggregatore degli attributi Taglie per il gestionale Etos</h2>
            <p>Questo plugin crea e aggiorna un attributo aggregatore chiamato <strong>Taglia</strong> (slug: <code><?php echo esc_html(WOO2ETOS_AT_TAX_SLUG); ?></code>) che unifica i valori provenienti dagli attributi Etos che contengono “taglia” nel loro nome.</p>
            <p>Non tocca le varianti: l'attributo aggregato è informativo, non di variazione.</p>
            <p>Nessuna azione automatica viene innescata finché non abiliti i toggle.</p>

            <form method="post">
                <?php wp_nonce_field( 'woo2etos_at_save', 'woo2etos_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Kill switch generale</th>
                        <td><label><input type="checkbox" name="enabled" <?php checked( $this->opts['enabled'] ); ?>> Abilita il plugin</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Aggiornamento automatico (hook)</th>
                        <td><label><input type="checkbox" name="auto_hooks" <?php checked( $this->opts['auto_hooks'] ); ?>> Accoda sincronizzazione quando un prodotto viene salvato/importato</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Ricontrollo ogni 15 minuti</th>
                        <td><label><input type="checkbox" name="cron_15" <?php checked( $this->opts['cron_15'] ); ?>> Fallback con Action Scheduler (solo prodotti modificati di recente)</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Batch size</th>
                        <td><input type="number" name="batch_size" value="<?php echo esc_attr( $this->opts['batch_size'] ); ?>" min="10" step="10"></td>
                    </tr>
                </table>
                <p><button class="button button-primary" name="woo2etos_save" value="1">Salva impostazioni</button></p>
            </form>

            <hr>
            <h2>Strumenti</h2>
            <form method="post" style="display:inline-block;margin-right:10px;">
                <?php wp_nonce_field( 'woo2etos_at_attr', 'woo2etos_nonce2' ); ?>
                <button class="button" name="ensure_attr" value="1">Verifica / Crea attributo “Taglia" (<?php echo esc_html(WOO2ETOS_AT_TAX_SLUG); ?>)</button>
            </form>

            <form method="post" style="display:inline-block;margin-right:10px;">
                <?php wp_nonce_field( 'woo2etos_at_flush', 'woo2etos_nonce4' ); ?>
                <button class="button" name="flush_empty" value="1">Rimuovi termini vuoti</button>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
                <?php wp_nonce_field( 'woo2etos_at_run', 'woo2etos_nonce3' ); ?>
                <input type="hidden" name="action" value="woo2etos_at_run">
                <input type="hidden" name="do_run" value="0">
                <button class="button">Dry-run (anteprima)</button>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;margin-left:10px;">
                <?php wp_nonce_field( 'woo2etos_at_run', 'woo2etos_nonce3' ); ?>
                <input type="hidden" name="action" value="woo2etos_at_run">
                <input type="hidden" name="do_run" value="1">
                <button class="button button-primary">Esegui sincronizzazione ora</button>
            </form>

            <?php if ( $dry_result ): ?>
                <div style="margin-top:20px;">
                    <h3>Anteprima</h3>
                    <p>Prodotti toccati: <strong><?php echo intval($dry_result['products']); ?></strong></p>
                    <p>Nuovi termini da creare nell'aggregatore: <strong><?php echo intval($dry_result['new_terms']); ?></strong></p>
                    <p>Totale associazioni prodotto→termine previste: <strong><?php echo intval($dry_result['links']); ?></strong></p>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /** Ensure aggregated attribute exists (no auto on activation) */
    private function ensure_attribute(){
        if ( ! function_exists('wc_get_attribute_taxonomies') ){
            return new WP_Error('nowc', 'WooCommerce non trovato.');
        }
        // Check if attribute definition exists
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
        $exists = $wpdb->get_var( $wpdb->prepare("SELECT attribute_id FROM $table WHERE attribute_name = %s", WOO2ETOS_AT_TAX_SLUG ) );
        if ( ! $exists ){
            $args = array(
                'name'         => 'Taglia',
                'slug'         => WOO2ETOS_AT_TAX_SLUG,
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            );
            if ( function_exists('wc_create_attribute') ){
                $attr_id = wc_create_attribute( $args );
                if ( is_wp_error($attr_id) ){
                    return $attr_id;
                }
                // register taxonomies again
                if ( function_exists('wc_register_attribute_taxonomies') ){
                    wc_register_attribute_taxonomies();
                }
            }
        }
        return true;
    }

    /** Remove empty terms from aggregated taxonomy */
    public function flush_empty_terms(){
        $removed = 0;
        $terms = get_terms( array(
            'taxonomy'   => WOO2ETOS_AT_TAX,
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $terms ) ) {
            return 0;
        }
        foreach ( $terms as $term ) {
            if ( intval( $term->count ) !== 0 ) {
                continue;
            }
            $res = wp_delete_term( $term->term_id, WOO2ETOS_AT_TAX );
            if ( ! is_wp_error( $res ) ) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Handle dry-run or run via admin-post */
    public function handle_run(){
        if ( ! current_user_can('manage_woocommerce') ) wp_die('no');
        check_admin_referer( 'woo2etos_at_run', 'woo2etos_nonce3' );
        $do_run = isset($_POST['do_run']) && $_POST['do_run'] === '1';
        if ( $do_run ){
            if ( class_exists( 'WC_Admin_Notices' ) ) {
                WC_Admin_Notices::remove_notice( 'woo2etos_run_start' );
                WC_Admin_Notices::add_custom_notice( 'woo2etos_run_start', 'Woo2Etos: Scansione avviata…' );
                foreach ( array( 'save_notices', 'save_admin_notices', 'save' ) as $m ) {
                    if ( method_exists( 'WC_Admin_Notices', $m ) ) {
                        call_user_func( array( 'WC_Admin_Notices', $m ) );
                        break;
                    }
                }
            }

            // Fallback notice for the initiating page
            set_transient( 'woo2etos_run_notice', 'Scansione avviata…', 60 );

            if ( function_exists( 'as_enqueue_async_action' ) ) {
                as_enqueue_async_action( 'woo2etos_run_summary' );
            } else {
                $this->run_summary();
            }

            wp_safe_redirect( admin_url( 'admin.php?page=' . WOO2ETOS_AT_SLUG ) );
            exit;
        } else {
            $res = $this->collect_products_and_terms( true, 0, 1 );
            $summary = array(
                'products'  => count( $res['products'] ),
                'new_terms' => count( $res['new_terms'] ),
                'links'     => $res['associations'],
            );
            // render on the same page by reloading POST on render_page
            // We'll store a transient with the last dry-run summary for display
            set_transient( 'woo2etos_at_dryrun', $summary, 60 );
            // Redirect back to page to show results
            wp_safe_redirect( admin_url( 'admin.php?page=' . WOO2ETOS_AT_SLUG ) );
            exit;
        }
    }

    /** Compute summary then kick off full sync */
    public function run_summary(){
        $this->ensure_attribute();

        $summary = $this->collect_products_and_terms( true, 0, 1 );
        $final = array(
            'products'  => count( $summary['products'] ),
            'new_terms' => count( $summary['new_terms'] ),
            'links'     => $summary['associations'],
        );
        update_option( 'woo2etos_run_final', $final );
        update_option( 'woo2etos_run_pending', $final['products'] );
        if ( class_exists( 'WC_Admin_Notices' ) ) {
            $message = sprintf(
                'Woo2Etos: Sincronizzazione iniziata: %d prodotti, %d nuovi termini, %d associazioni.',
                $final['products'],
                $final['new_terms'],
                $final['links']
            );
            WC_Admin_Notices::remove_notice( 'woo2etos_run_start' );
            WC_Admin_Notices::add_custom_notice( 'woo2etos_run_start', $message );
            foreach ( array( 'save_notices', 'save_admin_notices', 'save' ) as $m ) {
                if ( method_exists( 'WC_Admin_Notices', $m ) ) {
                    call_user_func( array( 'WC_Admin_Notices', $m ) );
                    break;
                }
            }
        }

        $this->collect_products_and_terms( false, 0, 1 );
    }

    /** Process a single page of products */
    private function collect_products_page( $page = 1, $dry = true, $since = 0 ){
        $batch = max( 10, intval( $this->opts['batch_size'] ) );

        $args = array(
            'status'   => array( 'publish', 'private' ),
            'limit'    => $batch,
            'paginate' => true,
            'return'   => 'ids',
            'page'     => $page,
        );
        if ( $since > 0 ) {
            $args['date_query'] = array(
                array(
                    'column' => 'post_modified_gmt',
                    'after'  => gmdate( 'Y-m-d H:i:s', $since ),
                ),
            );
        }

        $result = wc_get_products( $args );
        $ids    = $result->products ?? array();

        $products = array();
        $links    = 0;
        $all_terms = array();

        if ( empty( $ids ) ) {
            return array(
                'products'     => array(),
                'new_terms'    => array(),
                'associations' => 0,
                'has_more'     => false,
            );
        }

        foreach ( $ids as $pid ) {
            $terms = $this->collect_size_terms_for_product( $pid );
            if ( empty( $terms ) ) {
                continue;
            }
            $products[] = $pid;
            foreach ( $terms as $t ) {
                $all_terms[ $t ] = true;
            }
            $links += count( $terms );

            if ( ! $dry ) {
                $hash = $this->source_hash( $terms );
                if ( function_exists( 'as_enqueue_async_action' ) ) {
                    as_enqueue_async_action( 'woo2etos_sync_product', array(
                        'product_id' => $pid,
                        'terms'      => $terms,
                        'hash'       => $hash,
                    ) );
                } else {
                    $this->worker_sync_product( $pid, $terms, $hash );
                }
            }
        }

        $new_terms = array();
        foreach ( array_keys( $all_terms ) as $name ) {
            if ( ! term_exists( $name, WOO2ETOS_AT_TAX ) ) {
                $new_terms[ $name ] = true;
            }
        }

        return array(
            'products'     => $products,
            'new_terms'    => $new_terms,
            'associations' => $links,
            'has_more'     => ! empty( $ids ),
        );
    }

    /** Find products and for each compute terms; optionally schedule jobs */
    public function collect_products_and_terms( $dry = true, $since = 0, $page = 1 ){
        if ( $dry ) {
            $products = array();
            $all_terms = array();
            $links = 0;
            do {
                $res = $this->collect_products_page( $page, true, $since );
                $products = array_merge( $products, $res['products'] );
                foreach ( $res['new_terms'] as $t => $_ ) {
                    $all_terms[ $t ] = true;
                }
                $links += $res['associations'];
                $page++;
            } while ( $res['has_more'] );

            return array(
                'products'     => $products,
                'new_terms'    => $all_terms,
                'associations' => $links,
            );
        }

        $res = $this->collect_products_page( $page, false, $since );
        if ( $res['has_more'] && function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'woo2etos_collect_products', array( false, $since, $page + 1 ) );
        } else {
            delete_option( 'woo2etos_run_summary' );
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                $final = get_option( 'woo2etos_run_final', array() );
                if ( $final ) {
                    error_log( sprintf( '[Woo2Etos] run queued: %d prodotti, %d nuovi termini, %d associazioni', $final['products'], $final['new_terms'], $final['links'] ) );
                }

            }
        }

        return $res;
    }

    /** Hook wrappers */
    public function rest_inserted( $post, $request, $creating ){
        $this->maybe_queue_sync( wc_get_product( $post->ID ) );
    }
    public function import_inserted( $product, $data ){
        $this->maybe_queue_sync( $product );
    }

    /** Decide if a product needs syncing (diff by hash) */
    public function maybe_queue_sync( $product ){
        if ( is_numeric( $product ) ) $product = wc_get_product( $product );
        if ( ! $product || ! $this->opts['enabled'] ) return;

        $pid   = $product->get_id();
        if ( get_transient( 'woo2etos_sync_lock_' . $pid ) ) return;

        $terms = $this->collect_size_terms_for_product( $pid );
        $hash  = $this->source_hash( $terms );
        $old   = get_post_meta( $pid, WOO2ETOS_AT_META_HASH, true );
        if ( $hash === $old ) return;

        set_transient( 'woo2etos_sync_lock_' . $pid, 1, 60 );
        if ( function_exists('as_enqueue_async_action') ){
            as_enqueue_async_action( 'woo2etos_sync_product', array(
                'product_id' => $pid,
                'terms'      => $terms,
                'hash'       => $hash,
            ));
        } else {
            $this->worker_sync_product( $pid, $terms, $hash );
        }

        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            if ( count( $terms ) <= 20 ) {
                $msg = sprintf(
                    '[Woo2Etos] product %d queued with terms: %s',
                    $pid,
                    implode( ', ', $terms )
                );
                error_log( $msg );
            }
        }
    }

    /** Worker: create terms & attach attribute (visible=0, variation=0) */
    public function worker_sync_product( $pid, $terms = array(), $hash = null ){
        $pid   = intval( $pid );
        $terms = array_map( 'strval', (array) $terms );
        $hash  = $hash ?? $this->source_hash( $terms );

        // Safety: avoid recursion
        remove_action( 'woocommerce_after_product_object_save', array( $this, 'maybe_queue_sync' ), 10 );

        $this->ensure_attribute();

        // Create missing terms in aggregator
        foreach( $terms as $name ){
            if ( ! term_exists( $name, WOO2ETOS_AT_TAX ) ){
                wp_insert_term( $name, WOO2ETOS_AT_TAX );
            }
        }
        // Assign to product
        wp_set_object_terms( $pid, $terms, WOO2ETOS_AT_TAX, false );

        // Ensure attribute exists on product (informational)
        $product = wc_get_product( $pid );
        if ( $product ){
            $attrs = $product->get_attributes();

            // Build attribute object
            $aggreg = new WC_Product_Attribute();
            $aggreg->set_id( wc_attribute_taxonomy_id_by_name( WOO2ETOS_AT_TAX ) );
            $aggreg->set_name( WOO2ETOS_AT_TAX );
            // map names to term IDs
            $term_ids = array();
            foreach( $terms as $name ){
                $t = get_term_by( 'name', $name, WOO2ETOS_AT_TAX );
                if ( $t ) $term_ids[] = intval($t->term_id);
            }
            $aggreg->set_options( $term_ids );
            $aggreg->set_visible( false );
            $aggreg->set_variation( false ); // NON di variazione, no impatto sulle varianti

            $attrs[ WOO2ETOS_AT_TAX ] = $aggreg;
            $product->set_attributes( $attrs );
            $product->save();
        }

        update_post_meta( $pid, WOO2ETOS_AT_META_HASH, $hash );
        delete_transient( 'woo2etos_sync_lock_' . $pid );

        // Reattach hook
        add_action( 'woocommerce_after_product_object_save', array( $this, 'maybe_queue_sync' ), 10, 1 );

        // Track pending jobs for manual run
        $pending = (int) get_option( 'woo2etos_run_pending', 0 );
        if ( $pending > 0 ) {
            $pending--;
            if ( $pending > 0 ) {
                update_option( 'woo2etos_run_pending', $pending );
            } else {
                delete_option( 'woo2etos_run_pending' );
                $final = get_option( 'woo2etos_run_final', array() );
                if ( $final ) {
                    if ( class_exists( 'WC_Admin_Notices' ) ) {
                        $message = sprintf(
                            'Woo2Etos: Sincronizzazione completata: %d prodotti, %d nuovi termini, %d associazioni.',
                            $final['products'],
                            $final['new_terms'],
                            $final['links']
                        );
                        WC_Admin_Notices::remove_notice( 'woo2etos_run_start' );
                        WC_Admin_Notices::remove_notice( 'woo2etos_run_success' );
                        WC_Admin_Notices::add_custom_notice( 'woo2etos_run_success', $message );
                        WC_Admin_Notices::save_notices();
                    }
                    set_transient( 'woo2etos_at_run', $final, 60 );
                }
                delete_option( 'woo2etos_run_final' );
            }
        }
    }

    /** Fallback scheduled task: sync recently modified products */
    public function sync_recent(){
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[Woo2Etos] cron 15min trigger' );
        }

        $last = (int) get_option( 'woo2etos_sync_recent_ts', 0 );
        update_option( 'woo2etos_sync_recent_ts', time() );

        $this->collect_products_and_terms( false, $last, 1 );
    }

    /** Build diff hash from source size-like attributes (excluding aggregator) */
    private function source_hash( $terms ){
        if ( empty($terms) ) return '';
        $flat = implode('|', $terms);
        return md5( $flat );
    }

    /** Collect taglia-like terms attached to a product, excluding the aggregator */
    private function collect_size_terms_for_product( $product_id ){
        $product = wc_get_product( $product_id );
        if ( ! $product ) return array();

        $terms = array();

        // 1) Taxonomy attributes pa_* attached to product
        $taxonomies = wc_get_attribute_taxonomies();
        if ( $taxonomies ){
            foreach( $taxonomies as $tax ){
                $tax_name = wc_attribute_taxonomy_name( $tax->attribute_name ); // e.g. pa_ab-taglia
                $label    = strtolower( (string)$tax->attribute_label );
                $aname    = strtolower( (string)$tax->attribute_name );

                $is_size_like = ( false !== strpos( $aname, 'taglia' ) ) || ( false !== strpos( $label, 'taglia' ) ) || ( false !== strpos( $tax_name, 'taglia' ) );
                if ( ! $is_size_like ) continue;
                if ( $tax_name === WOO2ETOS_AT_TAX ) continue; // exclude aggregator

                $t = wp_get_object_terms( $product_id, $tax_name, array('fields'=>'names') );
                if ( ! is_wp_error($t) && $t ){
                    $terms = array_merge( $terms, $t );
                }
            }
        }

        // 2) If variable, inspect variations attributes (attribute_{taxonomy})
        if ( $product->is_type('variable') ){
            $children = $product->get_children();
            foreach( $children as $vid ){
                $v = wc_get_product( $vid );
                if ( ! $v ) continue;
                $vattrs = $v->get_attributes(); // array( 'pa_xxx' => 'term-slug' or string )
                foreach( $vattrs as $k => $val ){
                    $k_low = strtolower( $k );
                    if ( false === strpos( $k_low, 'taglia' ) ) continue;
                    if ( $k_low === WOO2ETOS_AT_TAX ) continue;

                    if ( is_array($val) ){
                        foreach( $val as $slug ){
                            $term = get_term_by( 'slug', $slug, $k );
                            if ( $term ) $terms[] = $term->name;
                        }
                    } else {
                        $term = get_term_by( 'slug', (string)$val, $k );
                        if ( $term ) $terms[] = $term->name;
                    }
                }
            }
        }

        // Normalize / dedup
        $terms = array_map( function($v){
            $v = trim( (string)$v );
            $v = preg_replace( '/\s+/', ' ', $v );
            return $v;
        }, $terms );
        $terms = array_filter( $terms, function($v){ return $v !== ''; } );
        $terms = array_values( array_unique( $terms ) );
        sort( $terms, SORT_NATURAL | SORT_FLAG_CASE );

        return $terms;
    }
}
