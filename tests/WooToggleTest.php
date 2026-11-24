<?php
/**
 * WooCommerce turned off and on again (spec §15.4, AC 119–121).
 *
 * Needs WooCommerce loaded: "deactivated" is simulated per request with the
 * `x7velo_woocommerce_active` filter (the same switch Plugin::is_woocommerce_active() reads).
 *
 * @package X7velo
 */

namespace X7velo\Tests;

use WP_UnitTestCase;
use X7velo\Audit\AuditService;
use X7velo\Audit\AuditStore;
use X7velo\Capabilities;
use X7velo\Fixers\Feed\FeedEndpoint;
use X7velo\Fixers\JsonLd\JsonLdFixer;
use X7velo\Fixers\LlmsTxt\LlmsTxtBuilder;
use X7velo\Fixers\Robots\RobotsFixer;
use X7velo\Notices\NoticeState;
use X7velo\Settings;

/**
 * Store mode ↔ site mode without owner action; settings never change.
 */
final class WooToggleTest extends WP_UnitTestCase {

	/**
	 * Requires WooCommerce; a store with all four fixers on.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		$this->set_permalink_structure( '/%postname%/' );
		update_option( 'blog_public', '1' );
		update_option( 'woocommerce_coming_soon', 'no' );
		foreach ( array(
			'shop'      => 'shop',
			'cart'      => 'cart',
			'checkout'  => 'checkout',
			'myaccount' => 'my-account',
		) as $key => $slug ) {
			update_option(
				'woocommerce_' . $key . '_page_id',
				self::factory()->post->create(
					array(
						'post_type'   => 'page',
						'post_status' => 'publish',
						'post_name'   => $slug,
						'post_title'  => ucfirst( $slug ),
					)
				)
			);
		}
		delete_option( NoticeState::OPTION );
		( new Settings() )->update(
			array(
				'fix_jsonld'        => true,
				'fix_feed'          => true,
				'fix_llms_txt'      => true,
				'fix_robots_search' => true,
			)
		);
	}

	/**
	 * Switches the simulated WooCommerce state.
	 *
	 * @param bool $active Active.
	 */
	private function woo( bool $active ): void {
		remove_all_filters( 'x7velo_woocommerce_active' );
		if ( ! $active ) {
			add_filter( 'x7velo_woocommerce_active', '__return_false' );
		}
	}

	/**
	 * Robots output with an owner rule that blocks everyone (so the block is emitted).
	 */
	private function robots(): string {
		add_filter( 'robots_txt', array( $this, 'block_everyone' ), 20 );
		$out = (string) apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", '1' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		remove_filter( 'robots_txt', array( $this, 'block_everyone' ), 20 );
		return $out;
	}

	/**
	 * Owner rule.
	 */
	public function block_everyone(): string {
		return "User-agent: *\nDisallow: /\n";
	}

	/**
	 * AC 119 + 120 (W16, W17): off → store outputs stop, llms.txt posts mode, no Woo paths, Tools
	 * menu; on → everything back; settings byte-identical; no conflict notice queued.
	 */
	public function test_ac119_ac120_toggle(): void {
		$stored   = get_option( Settings::OPTION );
		$jsonld   = new JsonLdFixer( new Settings() );
		$feed     = new FeedEndpoint( new Settings() );
		$cart     = (string) wp_parse_url( (string) wc_get_page_permalink( 'cart' ), PHP_URL_PATH );
		$baseline = NoticeState::get();

		$this->assertTrue( $jsonld->enabled() );
		$this->assertTrue( $feed->available() );
		$this->assertStringContainsString( 'Disallow: ' . $cart, $this->robots() );
		$this->assertStringNotContainsString( '## Key pages', LlmsTxtBuilder::build( ( new Settings() )->all() ) );

		$this->woo( false );
		$this->assertFalse( $jsonld->enabled(), 'No JSON-LD additions.' );
		$this->assertStringNotContainsString( 'Disallow: ' . $cart, $this->robots() );
		$this->assertStringContainsString( '## Key pages', LlmsTxtBuilder::build( ( new Settings() )->all() ) );
		$this->assertStringNotContainsString( 'Online store', LlmsTxtBuilder::build( ( new Settings() )->all() ) );
		$this->assertSame( 'manage_options', Capabilities::manage() );
		$this->go_to( home_url( '/?x7velo_feed=1' ) );
		$feed->template_redirect(); // Returns: no feed, no exit.
		NoticeState::evaluate( ( new Settings() )->all() );
		$this->assertSame( $stored, get_option( Settings::OPTION ), 'Settings untouched (W16).' );

		$this->woo( true );
		$this->assertTrue( $jsonld->enabled() );
		$this->assertTrue( $feed->available() );
		$this->assertStringContainsString( 'Disallow: ' . $cart, $this->robots() );
		$this->assertStringNotContainsString( '## Key pages', LlmsTxtBuilder::build( ( new Settings() )->all() ) );
		$this->assertSame( 'manage_woocommerce', Capabilities::manage() );
		NoticeState::evaluate( ( new Settings() )->all() );
		$this->assertSame( $baseline['queue'], NoticeState::get()['queue'], 'No conflict notice queued (W17).' );
		$this->assertSame( $stored, get_option( Settings::OPTION ), 'Settings untouched (W17).' );
	}

	/**
	 * AC 121 (W18), server side: the record says which mode produced it, so the page can re-run.
	 */
	public function test_ac121_record_mode(): void {
		$service = new AuditService( new AuditStore(), new Settings(), X7VELO_VERSION );
		$this->assertTrue( $service->run()['woocommerce_active'] );
		$this->woo( false );
		$record = $service->run();
		$this->assertFalse( $record['woocommerce_active'] );
		$this->assertNotSame( AuditService::STATE_NO_PRODUCTS, $record['state'] );
		$this->assertFalse( $service->present( $service->last() )['woocommerce_active'] );
	}

	/**
	 * The robots private paths follow the mode.
	 */
	public function test_private_paths_follow_mode(): void {
		$this->assertNotEmpty( RobotsFixer::private_paths() );
		$this->woo( false );
		$this->assertSame( array(), RobotsFixer::private_paths() );
	}
}
