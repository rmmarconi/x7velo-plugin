<?php
/**
 * "Northwind Ceramics" demo store for the WordPress.org screenshots (plugin/.wordpress-org/README.md, "Re-shooting").
 * Fictional store, no real people or customer data. A mostly complete catalog with a few typical gaps
 * (missing GTINs, two products without images, one thin description) so the score lands mid-range.
 *
 * Run with WooCommerce active: wp eval-file wp-content/x7velo-demo/seed-demo-store.php
 *
 * @package X7velo
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce must be active.' );
}
if ( get_option( 'x7velo_demo_seeded' ) ) {
	WP_CLI::success( 'demo store already seeded' );
	return;
}

// Store identity (fictional).
update_option( 'blogname', 'Northwind Ceramics' );
update_option( 'blogdescription', 'Handmade stoneware for everyday use' );
update_option( 'admin_email', 'hello@northwind-ceramics.test' );
update_option( 'woocommerce_email_from_address', 'hello@northwind-ceramics.test' );
update_option( 'woocommerce_email_from_name', 'Northwind Ceramics' );
update_option( 'woocommerce_store_address', '12 Harbour Lane' );
update_option( 'woocommerce_store_city', 'Portland' );
update_option( 'woocommerce_store_postcode', '97201' );
update_option( 'woocommerce_default_country', 'US:OR' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option(
	'woocommerce_onboarding_profile',
	array(
		'completed' => true,
		'skipped'   => true,
	)
);
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_allow_tracking', 'no' );

// Shipping: one zone for the US with flat rate.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'United States' );
$zone->add_location( 'US', 'country' );
$zone->save();
$zone->add_shipping_method( 'flat_rate' );

/**
 * Product image: a soft solid-colour circle generated locally (GD), one per glaze.
 *
 * @param string $slug File name.
 * @param array  $rgb  Glaze colour.
 * @return int Attachment ID.
 */
function x7velo_demo_image( string $slug, array $rgb ): int {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		$bits = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	} else {
		$im = imagecreatetruecolor( 600, 600 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 246, 243, 238 ) );
		imagefilledellipse( $im, 300, 320, 380, 380, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
		ob_start();
		imagepng( $im );
		$bits = (string) ob_get_clean();
	}
	$upload = wp_upload_bits( "{$slug}.png", null, $bits );
	if ( ! empty( $upload['error'] ) ) {
		WP_CLI::error( 'upload failed: ' . $upload['error'] );
	}
	return (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => ucwords( str_replace( '-', ' ', $slug ) ),
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
}

$cats = array();
foreach ( array( 'Mugs & Cups', 'Bowls', 'Plates', 'Vases', 'Teaware' ) as $name ) {
	$t             = wp_insert_term( $name, 'product_cat' );
	$cats[ $name ] = is_wp_error( $t ) ? (int) $t->get_error_data( 'term_exists' ) : (int) $t['term_id'];
}

$desc = static fn( string $what ) => "{$what} Thrown by hand in our Portland studio from mid-fire stoneware and glazed in small batches, so every piece is slightly different. Dishwasher and microwave safe. Each piece is signed on the base.";

// name, category, price, sale, sku, gtin (empty = missing), image rgb (null = no image), description.
$products = array(
	array( 'Speckled Oat Mug', 'Mugs & Cups', '28.00', '', 'NWC-MUG-OAT', '0860004271015', array( 214, 196, 168 ), $desc( 'A 12 oz everyday mug with a wide handle and a speckled oat glaze.' ) ),
	array( 'Ash Glaze Cereal Bowl', 'Bowls', '32.00', '', 'NWC-BWL-ASH', '0860004271022', array( 168, 170, 164 ), $desc( 'A deep 6 inch bowl for cereal, soup or noodles, in a soft ash glaze.' ) ),
	array( 'Linen White Dinner Plate', 'Plates', '36.00', '', 'NWC-PLT-LIN', '0860004271039', array( 236, 232, 222 ), $desc( 'A 10.5 inch dinner plate with a gently raised rim in a satin linen white glaze.' ) ),
	array( 'Moss Bud Vase', 'Vases', '24.00', '', 'NWC-VAS-MOSS', '0860004271046', array( 126, 140, 98 ), $desc( 'A small 5 inch bud vase for a single stem, in a glossy moss green glaze.' ) ),
	array( 'Hand-thrown Teapot', 'Teaware', '89.00', '79.00', 'NWC-TEA-POT', '0860004271053', array( 92, 104, 118 ), $desc( 'A 900 ml teapot with a built-in strainer and a slate blue glaze.' ) ),
	array( 'Oval Serving Platter', 'Plates', '64.00', '', 'NWC-PLT-OVAL', '', array( 198, 160, 122 ), $desc( 'A 14 inch oval platter for roasts and shared plates, in a warm amber glaze.' ) ),
	array( 'Espresso Cup Set of 2', 'Mugs & Cups', '38.00', '', 'NWC-CUP-ESP', '', array( 74, 60, 52 ), $desc( 'Two 3 oz espresso cups in a deep tenmoku glaze.' ) ),
	array( 'Ramen Bowl', 'Bowls', '42.00', '', 'NWC-BWL-RAM', '', array( 182, 108, 84 ), $desc( 'A generous 8 inch ramen bowl in a rust red glaze.' ) ),
	array( 'Tall Studio Vase', 'Vases', '72.00', '', 'NWC-VAS-TALL', '0860004271060', null, $desc( 'An 11 inch vase for branches and dried flowers, in a matte charcoal glaze.' ) ),
	array( 'Small Herb Planter', 'Vases', '26.00', '', 'NWC-PLN-HERB', '', null, $desc( 'A 4 inch planter with a drainage hole and matching saucer.' ) ),
	array( 'Salt Cellar', 'Teaware', '18.00', '', 'NWC-SALT', '0860004271077', array( 230, 222, 205 ), 'Small salt cellar.' ),
);

foreach ( $products as $row ) {
	list( $name, $x7velo_cat, $price, $sale, $sku, $gtin, $rgb, $description ) = $row;
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_regular_price( $price );
	if ( '' !== $sale ) {
		$p->set_sale_price( $sale );
	}
	$p->set_sku( $sku );
	if ( '' !== $gtin ) {
		$p->set_global_unique_id( $gtin );
	}
	$p->set_description( $description );
	$p->set_short_description( strtok( $description, '.' ) . '.' );
	if ( null !== $rgb ) {
		$p->set_image_id( x7velo_demo_image( sanitize_title( $name ), $rgb ) );
	}
	$p->set_category_ids( array( $cats[ $x7velo_cat ] ) );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 12 );
	$p->set_weight( '0.6' );
	$p->save();
}

// One variable product: the studio mug in three glazes.
$attr = new WC_Product_Attribute();
$attr->set_name( 'Glaze' );
$attr->set_options( array( 'Sand', 'Moss', 'Slate' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$v = new WC_Product_Variable();
$v->set_name( 'Studio Mug' );
$v->set_status( 'publish' );
$v->set_sku( 'NWC-MUG-STU' );
$v->set_description( $desc( 'Our signature 14 oz mug, available in three glazes.' ) );
$v->set_short_description( 'Our signature 14 oz mug, available in three glazes.' );
$v->set_image_id( x7velo_demo_image( 'studio-mug', array( 200, 184, 150 ) ) );
$v->set_category_ids( array( $cats['Mugs & Cups'] ) );
$v->set_attributes( array( $attr ) );
$v->set_weight( '0.5' );
$parent = $v->save();
foreach ( array( 'Sand', 'Moss', 'Slate' ) as $n => $glaze ) {
	$var = new WC_Product_Variation();
	$var->set_parent_id( $parent );
	$var->set_attributes( array( 'glaze' => $glaze ) );
	$var->set_regular_price( '30.00' );
	$var->set_sku( 'NWC-MUG-STU-' . strtoupper( $glaze ) );
	$var->set_global_unique_id( '086000427110' . $n );
	$var->set_stock_status( 'instock' );
	$var->save();
}
WC_Product_Variable::sync( $parent );

// Pages: returns policy (not yet chosen in x7velo), about, contact.
$returns = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Returns & Refunds',
		'post_content' => 'Changed your mind? You can return unused pieces within 30 days of delivery for a full refund. Email us and we will send you a prepaid label.',
	)
);
foreach ( array(
	'About Us' => 'Northwind Ceramics is a two-person pottery studio making stoneware for everyday use.',
	'Contact'  => 'Email hello@northwind-ceramics.test and we will reply within one business day.',
) as $x7velo_title => $content ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $x7velo_title,
			'post_content' => $content,
		)
	);
}
wp_delete_post( 1, true ); // "Hello world!".
wp_delete_post( 2, true ); // "Sample Page".

update_option( 'x7velo_demo_returns_page', (int) $returns, false );
update_option( 'x7velo_demo_seeded', 1, false );
WP_CLI::success( "demo store seeded (returns page {$returns})" );
