<?php
/**
 * Turns the demo store into a Brazilian one for the pt_BR screenshots (screenshot-N-pt_BR.png):
 * Portuguese product and page names, a São Paulo address, BRL, a shipping zone for Brazil and
 * Brazilian date/time formats. Fictional data only. Idempotent.
 *
 * Run after seed-demo-store.php: wp eval-file wp-content/x7velo-demo/localize-pt_BR.php
 *
 * @package X7velo
 */

$x7velo_names = array(
	'NWC-MUG-OAT'  => 'Caneca Aveia Pontilhada',
	'NWC-BWL-ASH'  => 'Tigela de Cereal Esmalte Cinza',
	'NWC-PLT-LIN'  => 'Prato Raso Branco Linho',
	'NWC-VAS-MOSS' => 'Vaso Botão Verde Musgo',
	'NWC-TEA-POT'  => 'Bule Torneado à Mão',
	'NWC-PLT-OVAL' => 'Travessa Oval',
	'NWC-CUP-ESP'  => 'Jogo com 2 Xícaras de Café',
	'NWC-BWL-RAM'  => 'Tigela para Lámen',
	'NWC-VAS-TALL' => 'Vaso Alto Ateliê',
	'NWC-PLN-HERB' => 'Vasinho para Temperos',
	'NWC-SALT'     => 'Saleiro',
	'NWC-MUG-STU'  => 'Caneca Ateliê',
);
foreach ( $x7velo_names as $sku => $name ) {
	$x7velo_id = wc_get_product_id_by_sku( $sku );
	if ( $x7velo_id ) {
		$p = wc_get_product( $x7velo_id );
		$p->set_name( $name );
		$p->save();
	}
}

$returns = (int) get_option( 'x7velo_demo_returns_page' );
wp_update_post(
	array(
		'ID'           => $returns,
		'post_title'   => 'Trocas e Devoluções',
		'post_content' => 'Mudou de ideia? Você pode devolver peças sem uso em até 30 dias após o recebimento e recebe o valor de volta. Escreva para nós e enviamos uma etiqueta de postagem.',
	)
);

update_option( 'blogdescription', 'Cerâmica artesanal para o dia a dia' );
update_option( 'woocommerce_store_address', 'Rua das Oficinas, 120' );
update_option( 'woocommerce_store_city', 'São Paulo' );
update_option( 'woocommerce_store_postcode', '05417-000' );
update_option( 'woocommerce_default_country', 'BR:SP' );
update_option( 'woocommerce_currency', 'BRL' );
update_option( 'timezone_string', 'America/Sao_Paulo' );
update_option( 'date_format', 'j \d\e F \d\e Y' );
update_option( 'time_format', 'H:i' );

$has_br = false;
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
	foreach ( $z['zone_locations'] as $loc ) {
		$has_br = $has_br || 'BR' === $loc->code;
	}
}
if ( ! $has_br ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'Brasil' );
	$zone->add_location( 'BR', 'country' );
	$zone->save();
	$zone->add_shipping_method( 'flat_rate' );
}

$user = get_user_by( 'login', 'admin' );
wp_update_user(
	array(
		'ID'           => $user->ID,
		'display_name' => 'Dona da Loja',
		'first_name'   => 'Dona',
		'last_name'    => 'da Loja',
		'nickname'     => 'Dona da Loja',
	)
);

WP_CLI::success( 'demo store localized to pt_BR' );
