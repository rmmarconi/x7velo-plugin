<?php
/**
 * Product feed fixer tests (spec §8.3, AC53, AC61–64, QA G1/G2).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Fixers;

use DOMDocument;
use DOMElement;
use DOMXPath;
use WC_Customer;
use WC_Product_Variation;
use WC_Tax;
use WP_UnitTestCase;
use X7velo\Detection\Detector;
use X7velo\Fixers\Feed\FeedEndpoint;
use X7velo\Fixers\Feed\FeedWriter;
use X7velo\Fixers\PublicOutput;
use X7velo\FixerStatus;
use X7velo\NamedLock;
use X7velo\Settings;
use X7velo\Tests\Detection\FakeEnvironment;
use X7velo\Tests\Helpers\Products;

/**
 * RSS shape, field mapping, eligibility, escaping, HTTP caching and memory.
 */
final class FeedFixerTest extends WP_UnitTestCase {

	/**
	 * WooCommerce customer before the test (pin_context() replaces it).
	 *
	 * @var mixed
	 */
	private $customer = null;

	/**
	 * Second DB connection used to hold named locks (another request).
	 *
	 * @var \wpdb|null
	 */
	private ?\wpdb $other = null;

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'woocommerce_price_num_decimals', 2 );
		update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
		update_option( 'woocommerce_coming_soon', 'no' );
		delete_option( Settings::OPTION );
		$this->customer = WC()->customer;
		set_query_var( FeedEndpoint::QUERY_VAR, '' );
	}

	/**
	 * Tear down: restore the customer, release locks held by the second connection.
	 */
	public function tear_down(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			WC()->customer = $this->customer;
		}
		if ( null !== $this->other ) {
			$this->other->get_var( $this->other->prepare( 'SELECT RELEASE_LOCK( %s )', FeedEndpoint::lock_name() ) );
			$this->other->close();
			$this->other = null;
		}
		set_query_var( FeedEndpoint::QUERY_VAR, '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Another request's DB connection.
	 */
	private function other_connection(): \wpdb {
		if ( null === $this->other ) {
			$this->other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		}
		return $this->other;
	}

	/**
	 * A canonical GET for the feed through dispatch().
	 *
	 * @param array<string, mixed> $request Overrides.
	 * @return array<string, mixed>
	 */
	private static function get( array $request = array() ): array {
		set_query_var( FeedEndpoint::QUERY_VAR, '1' );
		return ( new FeedEndpoint( new Settings() ) )->dispatch(
			array_merge(
				array(
					'method'        => 'GET',
					'if_none_match' => '',
					'query_keys'    => array( FeedEndpoint::QUERY_VAR ),
					'path'          => (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
				),
				$request
			)
		);
	}

	/**
	 * Body as the endpoint streams it (after pin_context()).
	 *
	 * @param FeedEndpoint $endpoint Endpoint.
	 */
	private static function streamed( FeedEndpoint $endpoint ): string {
		ob_start();
		$endpoint->stream( null );
		return (string) ob_get_clean();
	}

	/**
	 * Feed body as a string.
	 */
	private static function feed(): string {
		$body = '';
		( new FeedWriter() )->write(
			static function ( string $chunk ) use ( &$body ): void {
				$body .= $chunk;
			}
		);
		return $body;
	}

	/**
	 * Parses the feed strictly (fails on any XML error).
	 *
	 * @param string $xml Feed.
	 */
	private function parse( string $xml ): DOMXPath {
		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$ok       = $doc->loadXML( $xml );
		$errors   = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$this->assertTrue( $ok, 'Feed is well-formed XML.' );
		$this->assertSame( array(), $errors );
		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'g', FeedWriter::GOOGLE_NS );
		return $xpath;
	}

	/**
	 * Items keyed by g:id: tag => value.
	 *
	 * @param DOMXPath $xpath Parsed feed.
	 * @return array<string, array<string, string>>
	 */
	private static function items( DOMXPath $xpath ): array {
		$items = array();
		foreach ( $xpath->query( '/rss/channel/item' ) as $item ) {
			$fields = array();
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			foreach ( $item->childNodes as $child ) {
				if ( $child instanceof DOMElement ) {
					$fields[ $child->nodeName ] = $child->textContent;
				}
			}
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$items[ $fields['g:id'] ] = $fields;
		}
		return $items;
	}

	/**
	 * Google Merchant required fields for every item.
	 *
	 * @param array<string, string> $item Item fields.
	 */
	private function assert_google_required( array $item ): void {
		foreach ( array( 'g:id', 'title', 'description', 'link', 'g:price', 'g:availability', 'g:condition' ) as $tag ) {
			$this->assertArrayHasKey( $tag, $item, $tag );
			$this->assertNotSame( '', trim( $item[ $tag ] ), $tag );
		}
		$this->assertMatchesRegularExpression( '/^\d+(\.\d+)? [A-Z]{3}$/', $item['g:price'] );
		$this->assertContains( $item['g:availability'], array( 'in_stock', 'out_of_stock', 'backorder', 'preorder' ) );
		$this->assertSame( 'new', $item['g:condition'] );
		$this->assertNotFalse( filter_var( $item['link'], FILTER_VALIDATE_URL ) );
		$this->assertLessThanOrEqual( 150, mb_strlen( $item['title'] ) );
		$this->assertLessThanOrEqual( 5000, mb_strlen( $item['description'] ) );
		if ( isset( $item['g:image_link'] ) ) {
			$this->assertNotFalse( filter_var( $item['g:image_link'], FILTER_VALIDATE_URL ) );
		}
		$this->assertTrue( isset( $item['g:gtin'] ) || isset( $item['g:mpn'] ) || isset( $item['g:brand'] ) || 'no' === ( $item['g:identifier_exists'] ?? '' ), 'Identifiers or identifier_exists=no.' );
	}

	/**
	 * AC61: RSS 2.0 + g: namespace; one item per simple product and per published variation.
	 */
	public function test_rss_shape(): void {
		$simple   = Products::complete( array( 'regular_price' => '19.9' ) );
		$second   = Products::complete();
		$parent   = Products::variable( array( '10', '12.5', '15' ) );
		$children = wc_get_product( $parent )->get_children();
		$disabled = wc_get_product( $children[2] );
		$disabled->set_status( 'private' );
		$disabled->save();

		$xml   = self::feed();
		$xpath = $this->parse( $xml );
		$this->assertSame( '2.0', $xpath->query( '/rss/@version' )->item( 0 )->nodeValue );
		$this->assertStringContainsString( 'xmlns:g="http://base.google.com/ns/1.0"', $xml );
		$this->assertSame( 1, $xpath->query( '/rss/channel/title' )->length );
		$this->assertSame( 1, $xpath->query( '/rss/channel/link' )->length );

		$items = self::items( $xpath );
		$this->assertEqualsCanonicalizing( array_map( 'strval', array( $simple, $second, $children[0], $children[1] ) ), array_keys( $items ) );
		foreach ( $items as $item ) {
			$this->assert_google_required( $item );
		}
		$this->assertSame( '19.90 EUR', $items[ $simple ]['g:price'] );
		$this->assertSame( get_permalink( $simple ), $items[ $simple ]['link'] );
		$this->assertSame( 'Shirts', $items[ $simple ]['g:product_type'] );
		$this->assertArrayNotHasKey( 'g:item_group_id', $items[ $simple ] );
		$uncategorized = Products::complete( array( 'category' => false ) );
		$this->assertArrayNotHasKey( 'g:product_type', self::items( $this->parse( self::feed() ) )[ $uncategorized ] );

		$variation = $items[ $children[1] ];
		$this->assertSame( (string) $parent, $variation['g:item_group_id'] );
		$this->assertSame( '12.50 EUR', $variation['g:price'] );
		$this->assertSame( wc_get_product( $children[1] )->get_permalink(), $variation['link'] );
		$this->assertArrayHasKey( 'g:image_link', $variation, 'Falls back to the parent image.' );
	}

	/**
	 * AC62: GTIN + brand; neither → identifier_exists=no; SKU only → g:mpn.
	 */
	public function test_identifiers(): void {
		$both    = Products::complete();
		$neither = Products::complete(
			array(
				'gtin'  => '',
				'brand' => '',
				'sku'   => '',
			)
		);
		$sku     = Products::complete(
			array(
				'gtin'  => '',
				'brand' => '',
				'sku'   => 'MPN-1',
			)
		);
		$items   = self::items( $this->parse( self::feed() ) );

		$this->assertSame( wc_get_product( $both )->get_global_unique_id(), $items[ $both ]['g:gtin'] );
		$this->assertSame( 'Acme', $items[ $both ]['g:brand'] );
		$this->assertArrayNotHasKey( 'g:identifier_exists', $items[ $both ] );
		$this->assertArrayNotHasKey( 'g:mpn', $items[ $both ] );

		$this->assertSame( 'no', $items[ $neither ]['g:identifier_exists'] );
		$this->assertArrayNotHasKey( 'g:gtin', $items[ $neither ] );
		$this->assertArrayNotHasKey( 'g:brand', $items[ $neither ] );

		$this->assertSame( 'MPN-1', $items[ $sku ]['g:mpn'] );
		$this->assertSame( 'no', $items[ $sku ]['g:identifier_exists'] );
	}

	/**
	 * Sale price, availability mapping and hidden out-of-stock items.
	 */
	public function test_sale_price_and_availability(): void {
		$sale = wc_get_product( Products::complete( array( 'regular_price' => '20' ) ) );
		$sale->set_sale_price( '15' );
		$sale->save();
		$out = wc_get_product( Products::complete() );
		$out->set_stock_status( 'outofstock' );
		$out->save();
		$back = wc_get_product( Products::complete() );
		$back->set_stock_status( 'onbackorder' );
		$back->save();

		$items = self::items( $this->parse( self::feed() ) );
		$this->assertSame( '20.00 EUR', $items[ $sale->get_id() ]['g:price'] );
		$this->assertSame( '15.00 EUR', $items[ $sale->get_id() ]['g:sale_price'] );
		$this->assertSame( 'out_of_stock', $items[ $out->get_id() ]['g:availability'] );
		$this->assertSame( 'backorder', $items[ $back->get_id() ]['g:availability'] );

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$this->assertArrayNotHasKey( (string) $out->get_id(), self::items( $this->parse( self::feed() ) ) );
	}

	/**
	 * AC53: password-protected, private, draft, catalog-hidden and unpriced products are absent,
	 * as are grouped/external types.
	 */
	public function test_excludes_non_public(): void {
		$visible  = Products::complete();
		$private  = Products::complete( array( 'status' => 'private' ) );
		$draft    = Products::complete( array( 'status' => 'draft' ) );
		$no_price = Products::complete( array( 'regular_price' => '' ) );
		$hidden   = wc_get_product( Products::complete() );
		$hidden->set_catalog_visibility( 'hidden' );
		$hidden->save();
		$search_only = wc_get_product( Products::complete() );
		$search_only->set_catalog_visibility( 'search' );
		$search_only->save();
		$password = Products::complete();
		wp_update_post(
			array(
				'ID'            => $password,
				'post_password' => 'secret',
			)
		);
		$external = Products::complete( array( 'type' => 'external' ) );
		$unpriced = Products::variable( array( '', '' ) );

		$ids = array_map( 'strval', array_keys( self::items( $this->parse( self::feed() ) ) ) );
		$this->assertContains( (string) $visible, $ids );
		$this->assertContains( (string) $search_only->get_id(), $ids, 'Visibility "search" is not hidden.' );
		foreach ( array( $private, $draft, $no_price, $hidden->get_id(), $password, $external ) as $id ) {
			$this->assertNotContains( (string) $id, $ids );
		}
		foreach ( wc_get_product( $unpriced )->get_children() as $child ) {
			$this->assertNotContains( (string) $child, $ids );
		}
	}

	/**
	 * QA G2: messy text stays well-formed and round-trips; no injected elements.
	 */
	public function test_messy_text_is_escaped(): void {
		$name    = "Tom & Jerry <script>alert(1)</script> ]]> \"q\" 'a' 😀 مرحبا\n#heading \x01\x08 end";
		$product = wc_get_product( Products::complete() );
		$product->set_name( $name );
		$product->set_description( '<p>Para &amp; <b>bold</b></p> <![CDATA[ x ]]> </item><item><g:id>666</g:id>' . str_repeat( 'long ', 1200 ) );
		$product->save();

		$xpath = $this->parse( self::feed() );
		$items = self::items( $xpath );
		$this->assertCount( 1, $items );
		$item = $items[ $product->get_id() ];
		$this->assertStringContainsString( 'Tom & Jerry', $item['title'] );
		$this->assertStringContainsString( '😀 مرحبا', $item['title'] );
		$this->assertStringNotContainsString( "\x01", $item['title'] );
		$this->assertStringContainsString( 'Para & bold', $item['description'] );
		$this->assertLessThanOrEqual( 5000, mb_strlen( $item['description'] ) );
		$this->assertSame( 0, $xpath->query( '//script' )->length );
		$this->assertSame( 1, $xpath->query( '//item' )->length );
		$this->assert_google_required( $item );
	}

	/**
	 * AC64: 404 when the toggle is off (and in coming-soon mode).
	 */
	public function test_404_when_off(): void {
		$endpoint = new FeedEndpoint( new Settings() );
		$this->assertSame( 404, $endpoint->respond( '' )['status'] );
		$this->assertSame( 404, self::get()['status'] );

		( new Settings() )->update( array( 'fix_feed' => true ) );
		$this->assertSame( 200, $endpoint->respond( '' )['status'] );
		$ok = self::get();
		$this->assertSame( 200, $ok['status'] );
		$ok['lock'] instanceof NamedLock && $ok['lock']->release();

		update_option( 'woocommerce_coming_soon', 'yes' );
		$this->assertSame( 404, $endpoint->respond( '' )['status'] );
		$this->assertSame( 404, self::get()['status'] );
		$this->assertSame( 404, self::get( array( 'query_keys' => array( FeedEndpoint::QUERY_VAR, 'utm_source' ) ) )['status'], 'Off/coming soon: 404 before any redirect.' );
	}

	/**
	 * M3: coming-soon and the x7velo_public_output_allowed filter → 404 and cannot_run.
	 */
	public function test_public_output_gate(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		$this->assertSame( 'on', FixerStatus::compute( ( new Settings() )->all() )['feed']['state'] );

		update_option( 'woocommerce_coming_soon', 'yes' );
		$row = FixerStatus::compute( ( new Settings() )->all() )['feed'];
		$this->assertSame( 'cannot_run', $row['state'] );
		$this->assertSame( PublicOutput::REASON_COMING_SOON, $row['reason'] );
		$this->assertArrayNotHasKey( 'url', $row, 'The feed card reads url as the feed address.' );
		$this->assertTrue( ( new Settings() )->get( 'fix_feed' ), 'Stored toggle untouched.' );

		update_option( 'woocommerce_coming_soon', 'no' );
		add_filter( PublicOutput::FILTER, '__return_false' );
		$this->assertSame( 404, self::get()['status'] );
		$this->assertSame( PublicOutput::REASON_FILTERED, FixerStatus::compute( ( new Settings() )->all() )['feed']['reason'] );
		remove_filter( PublicOutput::FILTER, '__return_false' );

		// blog_public does not gate the feed.
		update_option( 'blog_public', '0' );
		$ok = self::get();
		$this->assertSame( 200, $ok['status'] );
		$ok['lock'] instanceof NamedLock && $ok['lock']->release();
		update_option( 'blog_public', '1' );
	}

	/**
	 * H1 (2): any other query argument or path → 301 to the canonical URL; only the feed's own
	 * argument on the home path is served.
	 */
	public function test_non_canonical_requests_redirect(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		foreach ( array(
			array( 'query_keys' => array( FeedEndpoint::QUERY_VAR, 'cb' ) ),
			array( 'query_keys' => array( 'utm_source', FeedEndpoint::QUERY_VAR ) ),
			array( 'path' => $home . 'shop/' ),
			array( 'path' => $home . 'index.php' ),
		) as $request ) {
			$response = self::get( $request );
			$this->assertSame( 301, $response['status'], (string) wp_json_encode( $request ) );
			$this->assertSame( FeedEndpoint::url(), $response['location'] );
			$this->assertFalse( $response['stream'] );
			$this->assertNull( $response['lock'] );
		}

		$this->assertFalse( FeedEndpoint::needs_redirect( array( FeedEndpoint::QUERY_VAR ), $home ) );
		$this->assertFalse( FeedEndpoint::needs_redirect( array( FeedEndpoint::QUERY_VAR ), '' ) );
		$this->assertTrue( FeedEndpoint::needs_redirect( array( FeedEndpoint::QUERY_VAR, 'x7velo_feed2' ), $home ) );

		// A value other than "1" is not the feed.
		set_query_var( FeedEndpoint::QUERY_VAR, '2' );
		$this->assertSame(
			404,
			( new FeedEndpoint( new Settings() ) )->dispatch(
				array(
					'query_keys' => array( FeedEndpoint::QUERY_VAR ),
					'path'       => $home,
				)
			)['status']
		);
	}

	/**
	 * H1 (1), QA G1-style: two concurrent uncached requests → one 200 (holds the lock) and one
	 * 503 with Retry-After; the 304 and HEAD paths never touch the lock.
	 */
	public function test_single_flight_503(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		Products::complete();
		$other = $this->other_connection();

		// Request A (this connection) starts generating.
		$first = self::get();
		$this->assertSame( 200, $first['status'] );
		$this->assertTrue( $first['stream'] );
		$this->assertInstanceOf( NamedLock::class, $first['lock'] );
		$this->assertTrue( $first['lock']->held() );
		// Request B (another connection) can't get the lock while A streams.
		$this->assertSame( '0', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK( %s, 0 )', FeedEndpoint::lock_name() ) ) );
		$first['lock']->release();

		// Now B holds it: A's next uncached request is 503, no body, Retry-After.
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK( %s, 0 )', FeedEndpoint::lock_name() ) ) );
		$busy = self::get();
		$this->assertSame( 503, $busy['status'] );
		$this->assertFalse( $busy['stream'] );
		$this->assertNull( $busy['lock'] );
		$this->assertSame( '120', $busy['headers']['Retry-After'] );
		$this->assertSame( 'no-store', $busy['headers']['Cache-Control'] );
		$this->assertArrayNotHasKey( 'ETag', $busy['headers'] );

		// Lock-free paths: a matching conditional GET is still 304; HEAD is 200 without a body.
		$etag = ( new FeedEndpoint( new Settings() ) )->validators()['etag'];
		$this->assertSame( 304, self::get( array( 'if_none_match' => $etag ) )['status'] );
		$head = self::get( array( 'method' => 'HEAD' ) );
		$this->assertSame( 200, $head['status'] );
		$this->assertFalse( $head['stream'] );

		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK( %s )', FeedEndpoint::lock_name() ) );
		$again = self::get();
		$this->assertSame( 200, $again['status'], 'Free again once the generator finished.' );
		$again['lock']->release();
		$this->assertStringStartsWith( 'x7velo_feed_', FeedEndpoint::lock_name() );
		$this->assertLessThanOrEqual( 64, strlen( FeedEndpoint::lock_name() ) );
	}

	/**
	 * GET_LOCK NULL (unsupported) → generated unlocked and the action fires.
	 */
	public function test_lock_unavailable_runs_unlocked(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		$fired = 0;
		add_action(
			'x7velo_feed_lock_unavailable',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		$null     = static fn( $sql ) => false !== strpos( (string) $sql, 'GET_LOCK' ) ? 'SELECT NULL' : $sql;
		add_filter( 'query', $null );
		$response = self::get();
		remove_filter( 'query', $null );

		$this->assertSame( 200, $response['status'] );
		$this->assertTrue( $response['stream'] );
		$this->assertNull( $response['lock'] );
		$this->assertSame( 1, $fired );
	}

	/**
	 * M1: a logged-in customer session located outside the base country, with tax display
	 * "incl" and role-based prices, gets byte-identical output and the same ETag as a logged-out
	 * request.
	 */
	public function test_context_pinned_identical_bytes_and_etag(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', 'incl' );
		update_option( 'woocommerce_tax_based_on', 'billing' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'US',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'US',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'DE',
				'tax_rate'          => '20.0000',
				'tax_rate_name'     => 'DE',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 2,
				'tax_rate_class'    => '',
			)
		);
		( new Settings() )->update( array( 'fix_feed' => true ) );
		$id = Products::complete( array( 'regular_price' => '100' ) );
		// Role-based pricing plugin stand-in: half price for logged-in users.
		$role_price = static fn( $price ) => is_user_logged_in() && '' !== (string) $price ? (string) ( (float) $price / 2 ) : $price;
		add_filter( 'woocommerce_product_get_price', $role_price );
		add_filter( 'woocommerce_product_get_regular_price', $role_price );

		// Request A: logged-in customer in Germany, VAT-exempt flag off, session customer.
		$user = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $user );
		$customer = new WC_Customer( 0, false );
		$customer->set_billing_location( 'DE', '', '10115', 'Berlin' );
		$customer->set_shipping_location( 'DE', '', '10115', 'Berlin' );
		WC()->customer = $customer;
		$unpinned      = self::feed();
		$this->assertStringContainsString( '<g:price>60.00 USD</g:price>', $unpinned, 'Sanity: the visitor context changes prices when not pinned.' );

		$a = new FeedEndpoint( new Settings() );
		$a->pin_context();
		$this->assertSame( 0, get_current_user_id() );
		$etag_a = $a->validators()['etag'];
		$body_a = self::streamed( $a );

		// Request B: logged out, default customer.
		wp_set_current_user( 0 );
		WC()->customer = new WC_Customer( 0, false );
		$b             = new FeedEndpoint( new Settings() );
		$b->pin_context();
		$etag_b = $b->validators()['etag'];
		$body_b = self::streamed( $b );

		$this->assertStringContainsString( '<g:price>110.00 USD</g:price>', $body_a, 'Base-country tax, no role price.' );
		$this->assertSame( $body_b, $body_a );
		$this->assertSame( $etag_b, $etag_a );
		$this->assertSame( array( 'US', 'CA', (string) WC()->countries->get_base_postcode(), (string) WC()->countries->get_base_city() ), WC()->customer->get_taxable_address() );
		$this->assertStringContainsString( '<g:id>' . $id . '</g:id>', $body_a );
	}

	/**
	 * M1: a known multi-currency / multilingual plugin makes the feed private.
	 */
	public function test_private_cache_with_multicurrency_plugin(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		$this->assertSame( FeedEndpoint::CACHE_PUBLIC, ( new FeedEndpoint( new Settings(), static fn(): Detector => new Detector( new FakeEnvironment() ) ) )->respond( '' )['headers']['Cache-Control'] );

		foreach ( array(
			array( 'classes', 'WOOCS' ),
			array( 'constants', 'ICL_SITEPRESS_VERSION' ),
			array( 'constants', 'WCML_VERSION' ),
			array( 'constants', 'POLYLANG_VERSION' ),
			array( 'classes', 'Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher' ),
			array( 'constants', 'WOOMULTI_CURRENCY_F_VERSION' ),
		) as $signal ) {
			$env = new FakeEnvironment();
			if ( 'classes' === $signal[0] ) {
				$env->classes[] = $signal[1];
			} else {
				$env->constants[ $signal[1] ] = '1.0';
			}
			$endpoint = new FeedEndpoint( new Settings(), static fn(): Detector => new Detector( $env ) );
			$this->assertSame( FeedEndpoint::CACHE_PRIVATE, $endpoint->respond( '' )['headers']['Cache-Control'], $signal[1] );
			$etag = $endpoint->validators()['etag'];
			$this->assertSame( FeedEndpoint::CACHE_PRIVATE, $endpoint->respond( $etag )['headers']['Cache-Control'], $signal[1] . ' (304)' );
		}
	}

	/**
	 * AC61 headers.
	 */
	public function test_headers(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		Products::complete();
		$response = ( new FeedEndpoint( new Settings() ) )->respond( '' );
		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'application/xml; charset=utf-8', $response['headers']['Content-Type'] );
		$this->assertSame( 'noindex', $response['headers']['X-Robots-Tag'] );
		$this->assertSame( 'nosniff', $response['headers']['X-Content-Type-Options'] );
		$this->assertSame( 'public, max-age=3600, s-maxage=3600, stale-while-revalidate=86400, stale-if-error=86400', $response['headers']['Cache-Control'] );
		$this->assertMatchesRegularExpression( '/^"x7v-[a-f0-9]{32}"$/', $response['headers']['ETag'] );
		$this->assertMatchesRegularExpression( '/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $response['headers']['Last-Modified'] );
		$this->assertSame( home_url( '/' ) . '?x7velo_feed=1', FeedEndpoint::url() );
	}

	/**
	 * QA G1: matching If-None-Match → 304 with caching headers, ≤ 5 queries, no product loaded;
	 * AC63: a product saved after the first response changes the ETag (200 again).
	 */
	public function test_etag_304_and_revalidation(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		$id = Products::complete();
		global $wpdb;
		// Backdate so the next save has a later modification time.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.
			$wpdb->posts,
			array(
				'post_modified'     => '2020-01-01 00:00:00',
				'post_modified_gmt' => '2020-01-01 00:00:00',
			),
			array( 'post_type' => 'product' )
		);
		clean_post_cache( $id );

		$endpoint = new FeedEndpoint( new Settings() );
		$first    = $endpoint->respond( '' );
		$etag     = $first['headers']['ETag'];

		$loads   = 0;
		$counter = static function ( $class_name ) use ( &$loads ) {
			++$loads;
			return $class_name;
		};
		add_filter( 'woocommerce_product_class', $counter );
		$queries = $wpdb->num_queries;
		$cached  = $endpoint->respond( $etag );
		$used    = $wpdb->num_queries - $queries;
		remove_filter( 'woocommerce_product_class', $counter );

		$this->assertSame( 304, $cached['status'] );
		$this->assertLessThanOrEqual( 5, $used );
		$this->assertSame( 0, $loads, 'No product loaded on the 304 path.' );
		$this->assertSame( FeedEndpoint::CACHE_PUBLIC, $cached['headers']['Cache-Control'] );
		$this->assertSame( 'nosniff', $cached['headers']['X-Content-Type-Options'] );
		$this->assertArrayHasKey( 'Last-Modified', $cached['headers'], 'Last-Modified is still sent.' );
		$this->assertSame( $etag, $cached['headers']['ETag'] );
		$this->assertArrayNotHasKey( 'Content-Type', $cached['headers'] );

		// Weak / list / compression-suffixed forms match too; another tag doesn't.
		$bare = trim( $etag, '"' );
		$this->assertSame( 304, $endpoint->respond( 'W/' . $etag )['status'] );
		$this->assertSame( 304, $endpoint->respond( '"nope", "' . $bare . '-gzip"' )['status'] );
		$this->assertSame( 200, $endpoint->respond( '"nope"' )['status'] );

		// L1: If-Modified-Since alone never gives a 304 (only the ETag decides).
		$ims = self::get( array( 'if_modified_since' => $first['headers']['Last-Modified'] ) );
		$this->assertSame( 200, $ims['status'] );
		$ims['lock'] instanceof NamedLock && $ims['lock']->release();

		// Saving a product changes the validators.
		$product = wc_get_product( $id );
		$product->set_regular_price( '42' );
		$product->save();
		$after = $endpoint->respond( $etag, '' );
		$this->assertSame( 200, $after['status'] );
		$this->assertNotSame( $etag, $after['headers']['ETag'] );
		$this->assertStringContainsString( '<g:price>42.00 EUR</g:price>', self::feed() );

		// Feed-relevant settings change the ETag.
		$etag2 = $after['headers']['ETag'];
		update_option( 'woocommerce_currency', 'USD' );
		$this->assertNotSame( $etag2, $endpoint->validators()['etag'] );
	}

	/**
	 * L1: tax rate changes change the ETag; without changes it is stable across requests (no
	 * per-request cache prefix in it).
	 */
	public function test_etag_tracks_tax_rates(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		Products::complete();
		$endpoint = new FeedEndpoint( new Settings() );
		$etag     = $endpoint->validators()['etag'];
		wp_cache_flush();
		$this->assertSame( $etag, $endpoint->validators()['etag'], 'Stable without a persistent object cache.' );

		$rate      = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'FR',
				'tax_rate'         => '20.0000',
				'tax_rate_name'    => 'TVA',
				'tax_rate_class'   => '',
			)
		);
		$with_rate = $endpoint->validators()['etag'];
		$this->assertNotSame( $etag, $with_rate );

		WC_Tax::_update_tax_rate( $rate, array( 'tax_rate' => '5.5000' ) );
		$updated = $endpoint->validators()['etag'];
		$this->assertNotSame( $with_rate, $updated );

		WC_Tax::_update_tax_rate_postcodes( $rate, '75001' );
		$this->assertNotSame( $updated, $endpoint->validators()['etag'], 'Rate locations count too.' );

		update_option( 'woocommerce_calc_taxes', 'no' );
		$this->assertSame( '', FeedEndpoint::tax_fingerprint() );
	}

	/**
	 * AC63: the body is never stored in an option or transient.
	 */
	public function test_body_never_stored(): void {
		Products::complete();
		self::feed();
		global $wpdb;
		$hits = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE '%<item>%' OR option_value LIKE '%<rss%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test assertion.
		$this->assertSame( array(), $hits );
	}

	/**
	 * AC63 (unit scale): memory after each batch stays flat. 1,000 lean products = 5 batches;
	 * retained memory grows < 1.5 MB from batch 1 to batch 5. The 2k vs 10k peak comparison runs
	 * on the VM (see report / QA perf).
	 */
	public function test_memory_flat_across_batches(): void {
		$cat = Products::category();
		for ( $i = 0; $i < 1000; $i++ ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'product',
					'post_status'  => 'publish',
					'post_title'   => 'Bulk ' . $i,
					'post_content' => str_repeat( 'Lorem ipsum dolor sit amet. ', 20 ),
				)
			);
			update_post_meta( $id, '_price', '9.99' );
			update_post_meta( $id, '_regular_price', '9.99' );
			update_post_meta( $id, '_stock_status', 'instock' );
			update_post_meta( $id, '_sku', 'BULK-' . $i );
			wp_set_object_terms( $id, 'simple', 'product_type' );
			wp_set_object_terms( $id, array( $cat ), 'product_cat' );
		}

		$memory = array();
		$probe  = static function ( $items, $bytes ) use ( &$memory ): void {
			$memory[] = $bytes;
		};
		add_action( 'x7velo_feed_batch_done', $probe, 10, 2 );
		$count = ( new FeedWriter() )->write( static function ( string $chunk ): void {} );
		remove_action( 'x7velo_feed_batch_done', $probe, 10 );

		$this->assertSame( 1000, $count );
		$this->assertCount( 5, $memory );
		$this->assertLessThan( 1.5 * MB_IN_BYTES, end( $memory ) - $memory[0], 'Retained memory grows < 1.5 MB over 4 batches.' );
	}

	/**
	 * Security v2.3 N1: the context (current user, customer) is pinned only on the 200/304/HEAD path,
	 * never for a 404 or 301.
	 */
	public function test_n1_context_pinned_only_when_serving(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$customer = WC()->customer;

		// Feed off → 404: nothing pinned (dispatch and the real template_redirect path).
		$this->assertSame( 404, self::get()['status'] );
		$this->assertSame( $user, get_current_user_id() );
		$this->go_to( home_url( '/?x7velo_feed=1' ) );
		( new FeedEndpoint( new Settings() ) )->template_redirect();
		$this->assertSame( $user, get_current_user_id(), 'Current user unchanged during the 404 render.' );
		$this->assertSame( $customer, WC()->customer );

		// 301: nothing pinned either.
		( new Settings() )->update( array( 'fix_feed' => true ) );
		wp_set_current_user( $user );
		$this->assertSame( 301, self::get( array( 'query_keys' => array( FeedEndpoint::QUERY_VAR, 'cb' ) ) )['status'] );
		$this->assertSame( $user, get_current_user_id() );

		// HEAD (no stream): pinned.
		$this->assertSame( 200, self::get( array( 'method' => 'HEAD' ) )['status'] );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Security v2.3 N2: a language parameter in home_url() (WPML/Polylang) is part of the canonical URL,
	 * so the canonical request is served (no 301 loop); non-ASCII home paths compare decoded.
	 */
	public function test_n2_no_redirect_loop(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		$lang = static function ( $url, $path ) {
			return '/' === $path ? add_query_arg( 'lang', 'fr', $url ) : $url;
		};
		add_filter( 'home_url', $lang, 10, 2 );
		$this->assertStringContainsString( 'lang=fr', FeedEndpoint::url() );
		$response = self::get( array( 'query_keys' => array( 'lang', FeedEndpoint::QUERY_VAR ) ) );
		$this->assertSame( 200, $response['status'] );
		if ( $response['lock'] instanceof NamedLock ) {
			$response['lock']->release();
		}
		$this->assertSame( 301, self::get( array( 'query_keys' => array( 'lang', 'utm_source', FeedEndpoint::QUERY_VAR ) ) )['status'] );
		remove_filter( 'home_url', $lang, 10 );

		$accent = static function ( $url, $path ) {
			return '/' === $path ? 'http://example.org/café/' : $url;
		};
		add_filter( 'home_url', $accent, 10, 2 );
		$this->assertFalse( FeedEndpoint::needs_redirect( array( FeedEndpoint::QUERY_VAR ), '/caf%C3%A9/' ) );
		$this->assertFalse( FeedEndpoint::needs_redirect( array( FeedEndpoint::QUERY_VAR ), '/café/' ) );
		$this->assertTrue( FeedEndpoint::needs_redirect( array( FeedEndpoint::QUERY_VAR ), '/cafe/' ) );
		remove_filter( 'home_url', $accent, 10 );
	}

	/**
	 * Security v2.3 N5: after the wall-clock deadline the feed ends cleanly (closed XML) and says so.
	 */
	public function test_n5_deadline_ends_cleanly(): void {
		( new Settings() )->update( array( 'fix_feed' => true ) );
		Products::complete();
		$this->assertSame( FeedEndpoint::DEADLINE_SECONDS, FeedEndpoint::deadline_seconds() );
		add_filter( 'x7velo_feed_deadline', static fn() => 0 );
		$this->assertSame( 1, FeedEndpoint::deadline_seconds(), 'At least one second.' );
		$slow = static function (): void {
			usleep( 1100000 );
		};
		add_action( 'x7velo_feed_batch_done', $slow );
		$body = self::streamed( new FeedEndpoint( new Settings() ) );
		$this->assertSame( 1, did_action( 'x7velo_feed_deadline_reached' ) );
		$this->assertStringEndsWith( "</channel>\n</rss>\n", $body );
		$this->assertNotFalse( simplexml_load_string( $body ) );

		// A writer callback returning false stops after that batch, XML still closed.
		$batches = 0;
		$out     = '';
		( new FeedWriter() )->write(
			static function ( string $chunk ) use ( &$out ): void {
				$out .= $chunk;
			},
			static function () use ( &$batches ): bool {
				++$batches;
				return false;
			}
		);
		$this->assertSame( 1, $batches );
		$this->assertStringEndsWith( "</rss>\n", $out );
	}

	/**
	 * Variation classes come from WooCommerce CRUD.
	 */
	public function test_variation_items_use_crud(): void {
		$parent = Products::variable( array( '5' ) );
		$child  = wc_get_product( $parent )->get_children()[0];
		$this->assertInstanceOf( WC_Product_Variation::class, wc_get_product( $child ) );
		$items = self::items( $this->parse( self::feed() ) );
		$this->assertSame( '5.00 EUR', $items[ $child ]['g:price'] );
	}
}
