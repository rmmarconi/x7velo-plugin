<?php
/**
 * JSON-LD fixer tests (spec §8.1, AC45–50, QA G3/G4).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Fixers;

use ReflectionProperty;
use WC_Shipping_Zone;
use WP_UnitTestCase;
use X7velo\Detection\Detector;
use X7velo\Detection\FixerStates;
use X7velo\Detection\SchemaSample;
use X7velo\FixerStatus;
use X7velo\Fixers\JsonLd\Additions;
use X7velo\Fixers\JsonLd\JsonLdFixer;
use X7velo\Settings;
use X7velo\Tests\Detection\FakeEnvironment;
use X7velo\Tests\Detection\SeoFixtures;
use X7velo\Tests\Helpers\Products;

/**
 * Augment / emit / managed per configuration; exactly one Product node; escaping.
 */
final class JsonLdFixerTest extends WP_UnitTestCase {

	/**
	 * Fixers created in a test (detached in tear_down).
	 *
	 * @var JsonLdFixer[]
	 */
	private array $fixers = array();

	/**
	 * Setup: Woo store in Brazil, BRL, fixer settings cleared.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		update_option( 'woocommerce_default_country', 'BR:SP' );
		update_option( 'woocommerce_currency', 'BRL' );
		update_option( 'woocommerce_ship_to_countries', '' );
		update_option( 'woocommerce_allowed_countries', 'all' );
		delete_option( SchemaSample::OPTION );
		delete_option( Settings::OPTION );
		self::reset_structured_data();
		self::unhook_plugin_instance();
		\WC_Cache_Helper::invalidate_cache_group( 'shipping_zones' );
	}

	/**
	 * Tear down: detach our filter, re-hook Woo's output.
	 */
	public function tear_down(): void {
		foreach ( $this->fixers as $fixer ) {
			$fixer->detach();
		}
		if ( function_exists( 'WC' ) && is_object( WC()->structured_data ) && false === has_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ) ) ) {
			add_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );
		}
		self::reset_structured_data();
		\WC_Cache_Helper::invalidate_cache_group( 'shipping_zones' );
		parent::tear_down();
	}

	/**
	 * Removes the plugin's own JsonLdFixer callbacks (registered at boot, live for the whole test
	 * process) so each test drives a fresh fixer. WP_UnitTestCase restores hooks after each test.
	 */
	private static function unhook_plugin_instance(): void {
		global $wp_filter;
		foreach ( array( 'wp', 'wp_footer' ) as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof JsonLdFixer ) {
						remove_action( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * Empties WC_Structured_Data's collected nodes (it is a per-request singleton).
	 */
	private static function reset_structured_data(): void {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->structured_data ) ) {
			return;
		}
		$prop = new ReflectionProperty( WC()->structured_data, '_data' );
		$prop->setAccessible( true );
		$prop->setValue( WC()->structured_data, array() );
	}

	/**
	 * A fixer for a simulated environment.
	 *
	 * @param FakeEnvironment|null $env Env (null = Woo only).
	 */
	private function fixer( ?FakeEnvironment $env = null ): JsonLdFixer {
		$env            = $env ?? new FakeEnvironment();
		$fixer          = new JsonLdFixer( new Settings(), static fn(): Detector => new Detector( $env ) );
		$this->fixers[] = $fixer;
		return $fixer;
	}

	/**
	 * Saves owner settings.
	 *
	 * @param array<string, mixed> $values Values.
	 */
	private static function settings( array $values ): void {
		( new Settings() )->update( array_merge( array( 'fix_jsonld' => true ), $values ) );
	}

	/**
	 * A published returns page.
	 *
	 * @param string $status Post status.
	 */
	private function returns_page( string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => $status,
				'post_title'  => 'Returns',
			)
		);
	}

	/**
	 * A Brazil shipping zone with one method.
	 *
	 * @param string               $method   Method ID.
	 * @param array<string, mixed> $settings Instance settings.
	 * @param string               $country  Zone country.
	 */
	private static function zone( string $method, array $settings, string $country = 'BR' ): WC_Shipping_Zone {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Zone ' . $country );
		$zone->add_location( $country, 'country' );
		$zone->save();
		self::add_method( $zone, $method, $settings );
		return $zone;
	}

	/**
	 * Adds an enabled method to a zone.
	 *
	 * @param WC_Shipping_Zone     $zone     Zone.
	 * @param string               $method   Method ID.
	 * @param array<string, mixed> $settings Instance settings.
	 */
	private static function add_method( WC_Shipping_Zone $zone, string $method, array $settings ): void {
		$instance = $zone->add_shipping_method( $method );
		update_option( 'woocommerce_' . $method . '_' . $instance . '_settings', array_merge( array( 'enabled' => 'yes' ), $settings ) );
		\WC_Cache_Helper::invalidate_cache_group( 'shipping_zones' );
	}

	/**
	 * Generates Woo's Product data for a product page and returns Woo's footer output.
	 *
	 * @param int         $product_id Product ID.
	 * @param JsonLdFixer $fixer      Fixer (its `wp` callback runs first).
	 */
	private function render_woo_footer( int $product_id, JsonLdFixer $fixer ): string {
		$this->go_to( get_permalink( $product_id ) );
		$this->assertTrue( is_product() );
		$fixer->on_wp();
		WC()->structured_data->generate_product_data( wc_get_product( $product_id ) );
		ob_start();
		if ( false !== has_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ) ) ) {
			WC()->structured_data->output_structured_data();
		}
		$fixer->on_footer();
		return (string) ob_get_clean();
	}

	/**
	 * JSON-LD script payloads in HTML.
	 *
	 * @param string $html HTML.
	 * @return array<int, array<mixed>>
	 */
	private static function scripts( string $html ): array {
		preg_match_all( '#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m );
		$out = array();
		foreach ( $m[1] as $json ) {
			$data  = json_decode( html_entity_decode( $json, ENT_NOQUOTES, 'UTF-8' ), true );
			$out[] = is_array( $data ) ? $data : array();
		}
		return $out;
	}

	/**
	 * Top-level nodes (unwrapping @graph) of all scripts.
	 *
	 * @param string $html HTML.
	 * @return array<int, array<mixed>>
	 */
	private static function nodes( string $html ): array {
		$nodes = array();
		foreach ( self::scripts( $html ) as $data ) {
			foreach ( isset( $data['@graph'] ) ? $data['@graph'] : array( $data ) as $node ) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}

	/**
	 * Product nodes in HTML.
	 *
	 * @param string $html HTML.
	 * @return array<int, array<mixed>>
	 */
	private static function products( string $html ): array {
		return array_values( array_filter( self::nodes( $html ), static fn( $n ) => is_array( $n ) && JsonLdFixer::is_type( $n, 'Product' ) ) );
	}

	/**
	 * All `@type` values anywhere in a structure.
	 *
	 * @param mixed $data Data.
	 * @return string[]
	 */
	private static function all_types( $data ): array {
		if ( ! is_array( $data ) ) {
			return array();
		}
		$types = array();
		if ( isset( $data['@type'] ) ) {
			$types = array_merge( $types, (array) $data['@type'] );
		}
		foreach ( $data as $value ) {
			$types = array_merge( $types, self::all_types( $value ) );
		}
		return $types;
	}

	/**
	 * Google's required MerchantReturnPolicy properties.
	 *
	 * @param array<string, mixed> $policy Policy node.
	 */
	private function assert_google_return_policy( array $policy ): void {
		$this->assertSame( 'MerchantReturnPolicy', $policy['@type'] );
		$this->assertMatchesRegularExpression( '/^[A-Z]{2}$/', $policy['applicableCountry'] );
		$this->assertContains(
			$policy['returnPolicyCategory'],
			array(
				'https://schema.org/MerchantReturnFiniteReturnWindow',
				'https://schema.org/MerchantReturnUnlimitedWindow',
				'https://schema.org/MerchantReturnNotPermitted',
			)
		);
		if ( 'https://schema.org/MerchantReturnFiniteReturnWindow' === $policy['returnPolicyCategory'] ) {
			$this->assertIsInt( $policy['merchantReturnDays'] );
			$this->assertGreaterThan( 0, $policy['merchantReturnDays'] );
		} else {
			$this->assertArrayNotHasKey( 'merchantReturnDays', $policy );
		}
	}

	/**
	 * Google's required OfferShippingDetails properties.
	 *
	 * @param array<string, mixed> $details Shipping details node.
	 */
	private function assert_google_shipping_details( array $details ): void {
		$this->assertSame( 'OfferShippingDetails', $details['@type'] );
		$this->assertSame( 'MonetaryAmount', $details['shippingRate']['@type'] );
		$this->assertIsNumeric( $details['shippingRate']['value'] );
		$this->assertMatchesRegularExpression( '/^[A-Z]{3}$/', $details['shippingRate']['currency'] );
		$this->assertSame( 'DefinedRegion', $details['shippingDestination']['@type'] );
		$this->assertMatchesRegularExpression( '/^[A-Z]{2}$/', $details['shippingDestination']['addressCountry'] );
		$transit = $details['deliveryTime']['transitTime'];
		$this->assertSame( 'ShippingDeliveryTime', $details['deliveryTime']['@type'] );
		$this->assertSame( 'QuantitativeValue', $transit['@type'] );
		$this->assertSame( 'DAY', $transit['unitCode'] );
		$this->assertLessThanOrEqual( $transit['maxValue'], $transit['minValue'] );
	}

	/**
	 * AC45 + AC51 (unit half): Woo only, finite 30 days → one Product whose offer has the policy.
	 */
	public function test_augment_adds_return_policy(): void {
		$page = $this->returns_page();
		self::settings(
			array(
				'returns_page_id' => $page,
				'return_policy'   => 'finite',
				'return_days'     => 30,
			)
		);
		$id   = Products::complete();
		$html = $this->render_woo_footer( $id, $this->fixer() );

		$products = self::products( $html );
		$this->assertCount( 1, $products, 'Exactly one Product node.' );
		$this->assertStringNotContainsString( 'x7velo-schema', $html, 'Augment mode prints no script of its own.' );
		$policy = $products[0]['offers'][0]['hasMerchantReturnPolicy'];
		$this->assert_google_return_policy( $policy );
		$this->assertSame( 'BR', $policy['applicableCountry'] );
		$this->assertSame( 'https://schema.org/MerchantReturnFiniteReturnWindow', $policy['returnPolicyCategory'] );
		$this->assertSame( 30, $policy['merchantReturnDays'] );
		$this->assertSame( get_permalink( $page ), $policy['merchantReturnLink'] );
		// Woo's own brand and gtin are untouched.
		$this->assertSame( 'Acme', $products[0]['brand']['name'] );
		$this->assertArrayHasKey( 'gtin', $products[0] );
	}

	/**
	 * Unlimited and no-returns categories carry no day count.
	 */
	public function test_return_policy_categories(): void {
		$page = $this->returns_page();
		foreach ( array(
			'unlimited' => 'https://schema.org/MerchantReturnUnlimitedWindow',
			'none'      => 'https://schema.org/MerchantReturnNotPermitted',
		) as $policy => $category ) {
			$node = ( new Additions(
				Settings::sanitize(
					array(
						'returns_page_id' => $page,
						'return_policy'   => $policy,
					)
				)
			) )->return_policy();
			$this->assertNotNull( $node );
			$this->assert_google_return_policy( $node );
			$this->assertSame( $category, $node['returnPolicyCategory'] );
		}
	}

	/**
	 * Data for AC46.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int|null}>
	 */
	public function incomplete_returns(): array {
		return array(
			'policy unset'   => array( 'publish', '', 30 ),
			'no page'        => array( '', 'finite', 30 ),
			'draft page'     => array( 'draft', 'finite', 30 ),
			'finite no days' => array( 'publish', 'finite', null ),
		);
	}

	/**
	 * AC46: no hasMerchantReturnPolicy unless page published and policy answered.
	 *
	 * @dataProvider incomplete_returns
	 *
	 * @param string   $page_status Page status ('' = no page).
	 * @param string   $policy      return_policy.
	 * @param int|null $days        return_days.
	 */
	public function test_no_return_policy_when_incomplete( string $page_status, string $policy, ?int $days ): void {
		$settings  = Settings::sanitize(
			array(
				'fix_jsonld'      => true,
				'returns_page_id' => '' === $page_status ? 0 : $this->returns_page( $page_status ),
				'return_policy'   => $policy,
				'return_days'     => $days,
			)
		);
		$additions = new Additions( $settings );
		$this->assertNull( $additions->return_policy() );

		$markup = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer' ) ),
		);
		$this->assertSame( $markup, $additions->augment( $markup, wc_get_product( Products::complete() ) ) );
	}

	/**
	 * AC47: delivery 2–5 days + base-country flat rate 10 → shippingDetails.
	 */
	public function test_shipping_details_flat_rate(): void {
		self::zone( 'flat_rate', array( 'cost' => '10' ) );
		self::settings(
			array(
				'delivery_days_min' => 2,
				'delivery_days_max' => 5,
			)
		);
		$html     = $this->render_woo_footer( Products::complete(), $this->fixer() );
		$products = self::products( $html );
		$this->assertCount( 1, $products );
		$details = $products[0]['offers'][0]['shippingDetails'];
		$this->assert_google_shipping_details( $details );
		$this->assertSame( 10.0, (float) $details['shippingRate']['value'] );
		$this->assertSame( 'BRL', $details['shippingRate']['currency'] );
		$this->assertSame( 'BR', $details['shippingDestination']['addressCountry'] );
		$this->assertSame( 2, $details['deliveryTime']['transitTime']['minValue'] );
		$this->assertSame( 5, $details['deliveryTime']['transitTime']['maxValue'] );
		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $products[0]['offers'][0] );
	}

	/**
	 * Free shipping without a minimum is a plain 0 rate and wins over a flat rate.
	 */
	public function test_free_shipping_without_minimum_is_zero(): void {
		$zone = self::zone( 'flat_rate', array( 'cost' => '10' ) );
		self::add_method( $zone, 'free_shipping', array( 'requires' => '' ) );

		$details = ( new Additions(
			Settings::sanitize(
				array(
					'delivery_days_min' => 1,
					'delivery_days_max' => 3,
				)
			)
		) )->shipping_details( wc_get_product( Products::complete() ) );
		$this->assertNotNull( $details );
		$this->assertSame( 0.0, (float) $details['shippingRate']['value'] );
	}

	/**
	 * Data for AC47 second half.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: int|null, 3: bool, 4: string}>
	 */
	public function omitted_shipping(): array {
		return array(
			'formula cost'      => array( 'flat_rate', array( 'cost' => '[qty] * 2' ), 5, false, 'BR' ),
			'class cost'        => array(
				'flat_rate',
				array(
					'cost'         => '10',
					'class_cost_7' => '3',
				),
				5,
				false,
				'BR',
			),
			'no class cost set' => array(
				'flat_rate',
				array(
					'cost'          => '10',
					'no_class_cost' => '2',
				),
				5,
				false,
				'BR',
			),
			'free with minimum' => array(
				'free_shipping',
				array(
					'requires'   => 'min_amount',
					'min_amount' => '100',
				),
				5,
				false,
				'BR',
			),
			'no delivery days'  => array( 'flat_rate', array( 'cost' => '10' ), null, false, 'BR' ),
			'virtual product'   => array( 'flat_rate', array( 'cost' => '10' ), 5, true, 'BR' ),
			'zone not base'     => array( 'flat_rate', array( 'cost' => '10' ), 5, false, 'PT' ),
			'local pickup only' => array( 'local_pickup', array( 'cost' => '0' ), 5, false, 'BR' ),
		);
	}

	/**
	 * AC47: formula/class costs, minimum amounts, missing days, virtual products → omitted.
	 *
	 * @dataProvider omitted_shipping
	 *
	 * @param string               $method   Method.
	 * @param array<string, mixed> $settings Method settings.
	 * @param int|null             $max_days delivery_days_max (min = 1 when set).
	 * @param bool                 $virtual  Virtual product.
	 * @param string               $country  Zone country.
	 */
	public function test_shipping_details_omitted( string $method, array $settings, ?int $max_days, bool $virtual, string $country ): void {
		self::zone( $method, $settings, $country );
		$additions = new Additions(
			Settings::sanitize(
				array(
					'delivery_days_min' => null === $max_days ? null : 1,
					'delivery_days_max' => $max_days,
				)
			)
		);
		$this->assertNull( $additions->shipping_details( wc_get_product( Products::complete( array( 'virtual' => $virtual ) ) ) ) );
	}

	/**
	 * Variable products: Woo's single offer (AggregateOffer or Offer) gets both parts.
	 */
	public function test_variable_product_offer_is_augmented(): void {
		self::zone( 'flat_rate', array( 'cost' => '10' ) );
		self::settings(
			array(
				'returns_page_id'   => $this->returns_page(),
				'return_policy'     => 'unlimited',
				'delivery_days_min' => 0,
				'delivery_days_max' => 2,
			)
		);
		$html     = $this->render_woo_footer( Products::variable( array( '10', '20' ) ), $this->fixer() );
		$products = self::products( $html );
		$this->assertCount( 1, $products );
		$offer = $products[0]['offers'][0];
		$this->assertSame( 'AggregateOffer', $offer['@type'] );
		$this->assert_google_return_policy( $offer['hasMerchantReturnPolicy'] );
		$this->assert_google_shipping_details( $offer['shippingDetails'] );
	}

	/**
	 * AC48 (unit half) / AC18: Woo + Yoast free → augment, filter attached.
	 */
	public function test_yoast_free_augments(): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		self::settings( array() );
		$fixer = $this->fixer( ( new FakeEnvironment() )->with( 'yoast' ) );
		$this->assertSame( 'woocommerce', $fixer->outcome() );
		$this->assertTrue( $fixer->attach() );
		$this->assertSame( JsonLdFixer::PRODUCT_FILTER_PRIORITY, has_filter( JsonLdFixer::PRODUCT_FILTER, array( $fixer->additions(), 'augment' ) ) );
	}

	/**
	 * AC49: Rank Math registered manages Product → nothing attached, nothing printed, 409 on PATCH
	 * is covered by SettingsControllerTest; card is managed.
	 */
	public function test_no_filter_attached_when_rank_math_manages(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		self::settings( array() );
		$env   = ( new FakeEnvironment() )->with( 'rank-math' );
		$fixer = $this->fixer( $env );

		$this->assertSame( 'managed', $fixer->outcome() );
		$this->assertFalse( $fixer->attach() );
		$this->assertFalse( has_filter( JsonLdFixer::PRODUCT_FILTER, array( $fixer->additions(), 'augment' ) ) );

		$id = Products::complete();
		$this->go_to( get_permalink( $id ) );
		$fixer->on_wp();
		$this->assertFalse( has_filter( JsonLdFixer::PRODUCT_FILTER, array( $fixer->additions(), 'augment' ) ) );
		$this->assertSame( '', $fixer->emit_markup( wc_get_product( $id ) ) );
		$this->assertSame( 'managed', FixerStates::jsonld( new Detector( $env ), array( 'state' => 'off' ), false )['state'] );
	}

	/**
	 * Emit mode (nobody emits): one Product node from x7velo, with the parts, and no
	 * Organization/WebSite anywhere (QA G3).
	 */
	public function test_emit_mode_prints_one_product_node(): void {
		self::zone( 'flat_rate', array( 'cost' => '10' ) );
		self::settings(
			array(
				'returns_page_id'   => $this->returns_page(),
				'return_policy'     => 'finite',
				'return_days'       => 14,
				'delivery_days_min' => 2,
				'delivery_days_max' => 5,
			)
		);
		$env = new FakeEnvironment();
		SchemaSample::store( false, ( new Detector( $env ) )->schema_fingerprint() );
		remove_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );

		$fixer = $this->fixer( $env );
		$this->assertSame( 'x7velo', $fixer->outcome() );
		$html = $this->render_woo_footer( Products::complete(), $fixer );

		$this->assertSame( 1, substr_count( $html, 'class="x7velo-schema"' ) );
		$products = self::products( $html );
		$this->assertCount( 1, $products );
		$this->assertSame( 'https://schema.org/', self::scripts( $html )[0]['@context'] );
		$offer = $products[0]['offers'][0];
		$this->assert_google_return_policy( $offer['hasMerchantReturnPolicy'] );
		$this->assert_google_shipping_details( $offer['shippingDetails'] );
		$this->assertArrayNotHasKey( 'seller', $offer );
		$types = self::all_types( self::scripts( $html ) );
		$this->assertNotContains( 'Organization', $types );
		$this->assertNotContains( 'WebSite', $types );
	}

	/**
	 * QA G3 for augment: our additions add only MerchantReturnPolicy / OfferShippingDetails
	 * (and their value types), never Organization or WebSite.
	 */
	public function test_augment_adds_no_organization_or_website(): void {
		self::zone( 'flat_rate', array( 'cost' => '10' ) );
		$additions = new Additions(
			Settings::sanitize(
				array(
					'returns_page_id'   => $this->returns_page(),
					'return_policy'     => 'finite',
					'return_days'       => 30,
					'delivery_days_min' => 1,
					'delivery_days_max' => 2,
				)
			)
		);
		$before    = array(
			'@type'  => 'Product',
			'offers' => array( array( '@type' => 'Offer' ) ),
		);
		$after     = $additions->augment( $before, wc_get_product( Products::complete() ) );
		$added     = array_diff( self::all_types( $after ), self::all_types( $before ) );
		$this->assertEqualsCanonicalizing(
			array( 'MerchantReturnPolicy', 'OfferShippingDetails', 'MonetaryAmount', 'DefinedRegion', 'ShippingDeliveryTime', 'QuantitativeValue' ),
			array_values( array_unique( $added ) )
		);
		// Idempotent and never overwrites.
		$this->assertSame( $after, $additions->augment( $after, wc_get_product( Products::complete() ) ) );
	}

	/**
	 * AC50: emit on, Woo's output hooked again → x7velo prints nothing (Woo's single node stays;
	 * the fixer is back in augment mode). An adapter claiming Product → nothing, state paused,
	 * stored toggle untouched.
	 */
	public function test_emit_mode_steps_aside(): void {
		self::settings( array() );
		$env = new FakeEnvironment();
		SchemaSample::store( false, ( new Detector( $env ) )->schema_fingerprint() );
		$id = Products::complete();

		// Woo hooked (sample stale): never a second node.
		$fixer = $this->fixer( $env );
		$html  = $this->render_woo_footer( $id, $fixer );
		$this->assertSame( '', $fixer->emit_markup( wc_get_product( $id ) ) );
		$this->assertCount( 1, self::products( $html ) );
		$this->assertStringNotContainsString( 'x7velo-schema', $html );

		// Another plugin claims Product → paused.
		remove_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );
		$claiming = ( new FakeEnvironment() )->with( 'yoast-woo' );
		SchemaSample::store( false, ( new Detector( $claiming ) )->schema_fingerprint() );
		$fixer = $this->fixer( $claiming );
		$this->assertSame( '', $fixer->emit_markup( wc_get_product( $id ) ) );
		$this->assertFalse( $fixer->attach() );
		$row = FixerStates::jsonld( new Detector( $claiming ), array( 'state' => 'on' ), true );
		$this->assertSame( 'paused', $row['state'] );
		$this->assertTrue( ( new Settings() )->get( 'fix_jsonld' ) );
	}

	/**
	 * M2: emit mode never prints a Product for a password-protected or unpublished product.
	 * Documents that WooCommerce's generate_product_data() itself checks neither (11.3.1): it
	 * builds the node; only its template skips calling it on protected pages.
	 */
	public function test_emit_mode_skips_password_protected_and_unpublished(): void {
		self::settings( array() );
		$env = new FakeEnvironment();
		SchemaSample::store( false, ( new Detector( $env ) )->schema_fingerprint() );
		remove_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );
		$fixer = $this->fixer( $env );
		$this->assertSame( 'x7velo', $fixer->outcome() );

		$id = Products::complete();
		wp_update_post(
			array(
				'ID'            => $id,
				'post_password' => 'secret',
			)
		);
		$this->go_to( get_permalink( $id ) );
		$this->assertTrue( is_product() );
		$this->assertTrue( post_password_required( $id ) );
		ob_start();
		$fixer->on_footer();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'x7velo-schema', $html );
		$this->assertSame( array(), self::products( $html ) );
		$this->assertSame( '', $fixer->emit_markup( wc_get_product( $id ) ) );
		$this->assertFalse( JsonLdFixer::is_public_product( $id ) );

		// v2.3 N6: still excluded for a visitor who entered the password (AC 99 literal).
		add_filter( 'post_password_required', '__return_false' );
		$this->assertFalse( post_password_required( $id ) );
		$this->assertFalse( JsonLdFixer::is_public_product( $id ) );
		remove_filter( 'post_password_required', '__return_false' );

		// WooCommerce's generator doesn't check the password (documented in JsonLdFixer).
		self::reset_structured_data();
		WC()->structured_data->generate_product_data( wc_get_product( $id ) );
		$this->assertNotNull( JsonLdFixer::find_product_node( (array) WC()->structured_data->get_data(), wc_get_product( $id ) ) );
		self::reset_structured_data();

		// Same product without the password: printed (the guard is what made the difference).
		wp_update_post(
			array(
				'ID'            => $id,
				'post_password' => '',
			)
		);
		$this->assertStringContainsString( 'class="x7velo-schema"', $fixer->emit_markup( wc_get_product( $id ) ) );

		// Private, draft and pending products: nothing.
		foreach ( array( 'private', 'draft', 'pending' ) as $status ) {
			self::reset_structured_data();
			$other = Products::complete( array( 'status' => $status ) );
			$this->assertSame( '', $fixer->emit_markup( wc_get_product( $other ) ), $status );
		}
	}

	/**
	 * QA G2: a product title with </script> can't close our script element.
	 */
	public function test_emit_output_is_escaped(): void {
		self::settings( array() );
		$env = new FakeEnvironment();
		SchemaSample::store( false, ( new Detector( $env ) )->schema_fingerprint() );
		remove_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );

		$id      = Products::complete();
		$product = wc_get_product( $id );
		$product->set_name( 'Evil </script><script>alert(1)</script> & "quoted" \'single\' <b>' );
		$product->set_short_description( 'Line </script> <!-- x --> ]]>' );
		$product->save();

		$html = $this->render_woo_footer( $id, $this->fixer( $env ) );
		$this->assertSame( 1, substr_count( strtolower( $html ), '</script' ), 'Only our own closing tag.' );
		$this->assertSame( 1, substr_count( strtolower( $html ), '<script' ) );
		$this->assertStringNotContainsString( '<!--', $html );
		$this->assertCount( 1, self::products( $html ) );

		$tag = JsonLdFixer::script_tag(
			array(
				'@type' => 'Product',
				'name'  => '</script><script>alert(1)</script>',
			)
		);
		$this->assertSame( 1, substr_count( $tag, '</script>' ) );
		$this->assertStringContainsString( '</script>', $tag );
		preg_match( '#<script[^>]*>(.*)</script>#s', $tag, $m );
		$this->assertSame( '</script><script>alert(1)</script>', json_decode( $m[1], true )['name'] );
	}

	/**
	 * QA G4: with the toggle off (default), nothing is attached and nothing is printed.
	 */
	public function test_off_by_default_changes_nothing(): void {
		Settings::add_defaults();
		$this->assertFalse( ( new Settings() )->get( 'fix_jsonld' ) );
		$fixer = $this->fixer();
		$id    = Products::complete();

		$this->go_to( get_permalink( $id ) );
		$fixer->on_wp();
		$this->assertFalse( has_filter( JsonLdFixer::PRODUCT_FILTER, array( $fixer->additions(), 'augment' ) ) );
		ob_start();
		$fixer->on_footer();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Fixes card parts: `included` / `not_yet` from the same code as the markup; sanitiser keeps
	 * only known parts and values.
	 */
	public function test_fixer_status_parts(): void {
		$none = FixerStatus::compute( ( new Settings() )->all() );
		$this->assertSame(
			array(
				'returns'  => 'not_yet',
				'shipping' => 'not_yet',
			),
			$none['jsonld']['parts']
		);

		self::zone( 'flat_rate', array( 'cost' => '10' ) );
		self::settings(
			array(
				'returns_page_id'   => $this->returns_page(),
				'return_policy'     => 'none',
				'delivery_days_min' => 1,
				'delivery_days_max' => 4,
			)
		);
		$all = FixerStatus::compute( ( new Settings() )->all() );
		$this->assertSame(
			array(
				'returns'  => 'included',
				'shipping' => 'included',
			),
			$all['jsonld']['parts']
		);
		$this->assertSame( 'augment', $all['jsonld']['mode'] );
		$this->assertArrayNotHasKey( 'parts', $all['feed'] );

		$hostile = static function ( $status ) {
			$status['jsonld']['parts'] = array(
				'returns'  => '<b>yes</b>',
				'shipping' => 'included',
				'extra'    => 'included',
			);
			$status['feed']['parts']   = array( 'returns' => 'included' );
			return $status;
		};
		add_filter( 'x7velo_fixer_status', $hostile, 99 );
		$dirty = FixerStatus::compute( ( new Settings() )->all() );
		remove_filter( 'x7velo_fixer_status', $hostile, 99 );
		$this->assertSame( array( 'shipping' => 'included' ), $dirty['jsonld']['parts'] );
		$this->assertArrayNotHasKey( 'parts', $dirty['feed'] );
	}

	/**
	 * Delivery days are only used when both are set.
	 */
	public function test_parts_need_both_delivery_days(): void {
		self::zone( 'flat_rate', array( 'cost' => '10' ) );
		$parts = ( new Additions( Settings::sanitize( array( 'delivery_days_max' => 4 ) ) ) )->parts();
		$this->assertFalse( $parts['shipping'] );
	}
}
