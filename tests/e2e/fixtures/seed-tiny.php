<?php
/**
 * "tiny" store for the e2e smoke (test plan §4.2): 3 simple products and 1 variable product with
 * 3 variations, all complete (price, image, description, category, SKU, GTIN), plus a published
 * returns page. Deterministic and idempotent (skips when the marker option exists).
 *
 * Run with WooCommerce active: wp eval-file wp-content/plugins/x7velo/tests/e2e/fixtures/seed-tiny.php
 *
 * @package X7velo
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce must be active to seed the tiny store.' );
}
if ( get_option( 'e2e_x7velo_seeded' ) ) {
	WP_CLI::success( 'tiny store already seeded' );
	return;
}

// One small image shared by every product (a 1×1 PNG written to uploads; no outbound HTTP).
$upload = wp_upload_bits( 'x7velo-e2e.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
if ( ! empty( $upload['error'] ) ) {
	WP_CLI::error( 'upload failed: ' . $upload['error'] );
}
$image_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/png',
		'post_title'     => 'x7velo e2e image',
		'post_status'    => 'inherit',
	),
	$upload['file']
);

$new_cat   = wp_insert_term( 'Bikes', 'product_cat' );
$bikes_cat = is_wp_error( $new_cat ) ? (int) $new_cat->get_error_data( 'term_exists' ) : (int) $new_cat['term_id'];

$description = 'A sturdy everyday product used by the x7velo end-to-end tests. It has a real description long enough for the description check, with materials, sizes and care instructions spelled out.';

for ( $i = 1; $i <= 3; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( "E2E simple product {$i}" );
	$p->set_status( 'publish' );
	$p->set_regular_price( (string) ( 10 * $i ) . '.00' );
	$p->set_sku( "E2E-S-{$i}" );
	$p->set_global_unique_id( '400638133393' . $i );
	$p->set_description( $description );
	$p->set_short_description( 'Short description.' );
	$p->set_image_id( $image_id );
	$p->set_category_ids( array( $bikes_cat ) );
	$p->set_stock_status( 'instock' );
	$p->save();
}

$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( 'S', 'M', 'L' ) );
$attr->set_visible( true );
$attr->set_variation( true );

$v = new WC_Product_Variable();
$v->set_name( 'E2E variable product' );
$v->set_status( 'publish' );
$v->set_sku( 'E2E-V' );
$v->set_description( $description );
$v->set_image_id( $image_id );
$v->set_category_ids( array( $bikes_cat ) );
$v->set_attributes( array( $attr ) );
$parent = $v->save();
foreach ( array( 'S', 'M', 'L' ) as $n => $size ) {
	$var = new WC_Product_Variation();
	$var->set_parent_id( $parent );
	$var->set_attributes( array( 'size' => $size ) );
	$var->set_regular_price( (string) ( 20 + $n ) . '.00' );
	$var->set_sku( "E2E-V-{$size}" );
	$var->set_global_unique_id( '400638133400' . $n );
	$var->set_stock_status( 'instock' );
	$var->save();
}
WC_Product_Variable::sync( $parent );

$returns = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Returns and refunds',
		'post_content' => 'You can return any item within 30 days of delivery.',
	)
);

update_option( 'e2e_x7velo_returns_page', (int) $returns, false );
update_option( 'e2e_x7velo_seeded', 1, false );
WP_CLI::success( "tiny store seeded (returns page {$returns})" );
