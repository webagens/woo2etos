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

        // Retrieve run result from transient if available
        $run_result = get_transient( 'woo2etos_at_run' );
        delete_transient( 'woo2etos_at_run' );
        if ( $run_result ){
            echo '<div class="notice notice-success"><p>Sincronizzazione avviata: ' . intval( $run_result['products'] ) . ' prodotti messi in coda.</p></div>';
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

        $this->ensure_attribute();

        if ( $do_run ){
            $res = $this->collect_products_and_terms( false );
            $summary = array(
                'products'  => count( $res['products'] ),
                'new_terms' => count( $res['new_terms'] ),
                'links'     => $res['associations'],
            );
            set_transient( 'woo2etos_at_run', $summary, 60 );
            error_log( sprintf( '[Woo2Etos] run queued: %d prodotti, %d nuovi termini, %d associazioni', $summary['products'], $summary['new_terms'], $summary['links'] ) );
            wp_safe_redirect( add_query_arg( array('page'=>WOO2ETOS_AT_SLUG, 'done'=>'1'), admin_url('admin.php') ) );
            exit;
        } else {
            $res = $this->collect_products_and_terms( true );
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

    /** Find products and for each compute terms; optionally schedule jobs */
    private function collect_products_and_terms( $dry = true, $since = 0 ){
        $batch = max( 10, intval( $this->opts['batch_size'] ) );

        $args = array(
            'status' => array( 'publish', 'private' ),
            'limit'  => -1,
            'return' => 'ids',
        );
        $all_ids = wc_get_products( $args );
        $products = array();
        $product_terms = array();
        $links = 0;
        $all_terms = array();

        foreach ( $all_ids as $pid ) {
            if ( $since ) {
                $modified = get_post_modified_time( 'U', true, $pid );
                if ( ! $modified || $modified <= $since ) {
                    continue;
                }
            }
            $terms = $this->collect_size_terms_for_product( $pid );
            if ( empty( $terms ) ) {
                continue;
            }
            $products[] = $pid;
            $product_terms[ $pid ] = $terms;
            foreach ( $terms as $t ) {
                $all_terms[ $t ] = true;
            }
        }

        $new_terms = array();
        foreach ( array_keys( $all_terms ) as $name ) {
            if ( ! term_exists( $name, WOO2ETOS_AT_TAX ) ) {
                $new_terms[ $name ] = true;
            }
        }

        if ( ! $dry ) {
            $count = 0;
            foreach ( $products as $pid ) {
                $terms = $product_terms[ $pid ];
                $links += count( $terms );
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
                $count++;
                if ( $count % $batch == 0 ) {
                    // small pause to be gentle
                }
            }
        } else {
            foreach ( $products as $pid ) {
                $terms = $product_terms[ $pid ];
                $links += count( $terms );
            }
        }

        return array(
            'products'     => $products,
            'new_terms'    => $new_terms,
            'associations' => $links,
        );
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
    }

    /** Fallback scheduled task: sync recently modified products */
    public function sync_recent(){
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[Woo2Etos] cron 15min trigger' );
        }

        $last = (int) get_option( 'woo2etos_sync_recent_ts', 0 );
        update_option( 'woo2etos_sync_recent_ts', time() );

        $res = $this->collect_products_and_terms( false, $last );
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            $msg = sprintf(
                '[Woo2Etos] run queued: %d prodotti, %d nuovi termini, %d associazioni',
                count( $res['products'] ),
                count( $res['new_terms'] ),
                $res['associations']
            );
            // Dettagli aggiuntivi se pochi elementi
            if ( count( $res['products'] ) <= 20 ) {
                $msg .= ' | prodotti: ' . implode( ', ', $res['products'] );
                $msg .= ' | termini: ' . implode( ', ', array_keys( $res['new_terms'] ) );
            }
            error_log( $msg );
        }
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
