<?php
/**
 * Deterministic perf catalog (test plan §4.2): N products, 85 % simple, 10 % variable with 3
 * variations, 5 % "messy" (no price, no image, short description, no category), plus ~3 other
 * wp_posts rows per product (attachments) so the posts table isn't product-only (dba §3).
 *
 * Usage: wp eval-file wp-content/plugins/x7velo/tests/perf/generate.php <count> [seed]
 *
 * Same count + seed = same catalog (names, prices, SKUs, GTINs, mix). WooCommerce must be active.
 *
 * @package X7velo
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.PrefixAllGlobals -- Test fixture script.
// phpcs:disable WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand, WordPress.WP.AlternativeFunctions.rand_mt_rand -- Seeded on purpose: same seed, same catalog.

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce must be active.' );
}
$count = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 2000;
$seed  = isset( $args[1] ) ? (int) $args[1] : 42;

global $wpdb;
$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'" );
if ( $existing > 0 ) {
	WP_CLI::error( "The site already has {$existing} products; reset the perf env first." );
}

if ( ! defined( 'WP_IMPORTING' ) ) {
	define( 'WP_IMPORTING', true );
}
wp_defer_term_counting( true );
wp_suspend_cache_addition( true );
mt_srand( $seed );

$started = microtime( true );

$upload = wp_upload_bits( 'x7velo-perf.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
$image  = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/png',
		'post_title'     => 'perf image',
		'post_status'    => 'inherit',
	),
	$upload['file']
);

$cats = array();
foreach ( array( 'Bikes', 'Parts', 'Apparel' ) as $name ) {
	$new_cat = wp_insert_term( $name, 'product_cat' );
	$cats[]  = is_wp_error( $new_cat ) ? (int) $new_cat->get_error_data( 'term_exists' ) : (int) $new_cat['term_id'];
}

$long = str_repeat( 'Hand-built in small batches, tested on real roads and backed by a two-year warranty. ', 3 );

$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( 'S', 'M', 'L' ) );
$attr->set_visible( true );
$attr->set_variation( true );

$kinds = array(
	'simple'   => 0,
	'variable' => 0,
	'messy'    => 0,
);
$wpdb->query( 'START TRANSACTION' );
for ( $i = 1; $i <= $count; $i++ ) {
	$roll  = mt_rand( 1, 100 );
	$kind  = $roll <= 85 ? 'simple' : ( $roll <= 95 ? 'variable' : 'messy' );
	$price = (string) mt_rand( 5, 900 ) . '.' . str_pad( (string) mt_rand( 0, 99 ), 2, '0', STR_PAD_LEFT );
	$gtin  = 70 >= mt_rand( 1, 100 ) ? sprintf( '40%011d', $i ) : '';
	++$kinds[ $kind ];

	if ( 'variable' === $kind ) {
		$p = new WC_Product_Variable();
		$p->set_attributes( array( $attr ) );
	} else {
		$p = new WC_Product_Simple();
	}
	$p->set_name( sprintf( 'Perf product %05d', $i ) );
	$p->set_status( 'publish' );
	$p->set_sku( sprintf( 'PERF-%05d', $i ) );
	if ( 'messy' === $kind ) {
		$p->set_short_description( 'Short.' );
	} else {
		$p->set_description( $long );
		$p->set_image_id( $image );
		$p->set_category_ids( array( $cats[ $i % 3 ] ) );
		$p->set_global_unique_id( $gtin );
		if ( 'simple' === $kind ) {
			$p->set_regular_price( $price );
		}
	}
	$product_id = $p->save();

	if ( 'variable' === $kind ) {
		foreach ( array( 'S', 'M', 'L' ) as $n => $size ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $product_id );
			$v->set_attributes( array( 'size' => $size ) );
			$v->set_regular_price( $price );
			$v->set_sku( sprintf( 'PERF-%05d-%s', $i, $size ) );
			$v->save();
		}
		WC_Product_Variable::sync( $product_id, false );
	}

	if ( 0 === $i % 500 ) {
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'START TRANSACTION' );
		WP_CLI::log( sprintf( '%d/%d products (%.1f s)', $i, $count, microtime( true ) - $started ) );
	}
}
$wpdb->query( 'COMMIT' );

// ~3 non-product rows per product, in multi-row inserts (attachments of the shared image).
$rows  = array();
$now   = current_time( 'mysql' );
$total = 3 * $count;
for ( $i = 1; $i <= $total; $i++ ) {
	$rows[] = $wpdb->prepare( "(1, %s, %s, '', %s, '', 'inherit', 'closed', 'closed', %s, %s, %s, 'attachment', 'image/png', '', '', '')", $now, $now, "perf filler {$i}", "perf-filler-{$i}", $now, $now );
	if ( 0 === $i % 1000 || $i === $total ) {
		$wpdb->query( "INSERT INTO {$wpdb->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, post_modified, post_modified_gmt, post_type, post_mime_type, to_ping, pinged, post_content_filtered) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Rows prepared above.
		$rows = array();
	}
}

wp_defer_term_counting( false );
wp_suspend_cache_addition( false );
wp_cache_flush();

WP_CLI::success(
	sprintf(
		'%d products (simple %d, variable %d with 3 variations each, messy %d), %d filler rows, seed %d, in %.1f s',
		$count,
		$kinds['simple'],
		$kinds['variable'],
		$kinds['messy'],
		$total,
		$seed,
		microtime( true ) - $started
	)
);
