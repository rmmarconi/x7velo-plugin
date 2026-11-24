<?php
/**
 * CRAWL-01 tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit\Checks;

use WP_UnitTestCase;
use X7velo\Audit\Checks\AiSearchCrawlers;
use X7velo\Audit\Fixer;
use X7velo\Audit\Status;
use X7velo\Detection\Detector;
use X7velo\Detection\RobotsRules;
use X7velo\Tests\Detection\FakeEnvironment;

/**
 * Evaluated against the bundled data file (17 scored tokens).
 */
final class AiSearchCrawlersCheckTest extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
		update_option( 'blog_public', '1' );
	}

	/**
	 * Runs CRAWL-01 against a physical robots.txt (or the filter output when null).
	 *
	 * @param string|null $robots Physical robots.txt content.
	 */
	private function run_with( ?string $robots ): \X7velo\Audit\CheckResult {
		$env = new FakeEnvironment();
		if ( null !== $robots ) {
			$env->files['/site/robots.txt'] = $robots;
		}
		return ( new AiSearchCrawlers( new RobotsRules( new Detector( $env ) ) ) )->run();
	}

	/**
	 * Default WP + Woo rules pass; fixer Robots when no physical file.
	 */
	public function test_default_rules_pass(): void {
		$result = $this->run_with( null );
		$this->assertSame( Status::PASS, $result->status() );
		$this->assertSame( 17, $result->details()['scored_count'] );
		$this->assertSame( 'filter', $result->details()['source'] );
		$this->assertSame( Fixer::ROBOTS, $result->fixer() );
	}

	/**
	 * AC10: `*` Disallow / → critical fail.
	 */
	public function test_full_block_is_critical(): void {
		$result = $this->run_with( "User-agent: *\nDisallow: /\n" );
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( 'all_search_crawlers_blocked', $result->code() );
		$this->assertTrue( $result->is_critical() );
		$this->assertSame( 17, $result->details()['blocked_count'] );
		$this->assertCount( 10, $result->details()['blocked'], 'List capped at 10.' );
		$this->assertSame( $result->message(), ( new AiSearchCrawlers( new RobotsRules( new Detector( new FakeEnvironment() ) ) ) )->render_message( $result->code(), $result->details() ) );
		$this->assertSame( Fixer::GUIDED, $result->fixer(), 'Physical file → copy-paste / Guided.' );
	}

	/**
	 * AC10 through the filter (no physical file): fixer offered.
	 */
	public function test_full_block_via_filter_offers_robots_fixer(): void {
		add_filter( 'robots_txt', static fn() => "User-agent: *\nDisallow: /\n", 1000 );
		$result = $this->run_with( null );
		$this->assertTrue( $result->is_critical() );
		$this->assertSame( Fixer::ROBOTS, $result->fixer() );
		$this->assertSame( 17, $result->details()['fixable_count'] );
	}

	/**
	 * AC11: blocking only GPTBot and Google-Extended passes (training tokens never score).
	 */
	public function test_training_only_block_passes(): void {
		$result = $this->run_with( "User-agent: GPTBot\nUser-agent: Google-Extended\nDisallow: /\n\nUser-agent: *\nDisallow:\n" );
		$this->assertSame( Status::PASS, $result->status() );
		$this->assertSame( 2, $result->details()['training_blocked_count'] );
		$this->assertStringContainsString( 'training', $result->message() );
	}

	/**
	 * AC12: `*` Disallow / + OAI-SearchBot Allow / → warn (not critical), lists blocked tokens.
	 */
	public function test_partial_block_warns(): void {
		$result = $this->run_with( "User-agent: *\nDisallow: /\n\nUser-agent: OAI-SearchBot\nAllow: /\n" );
		$this->assertSame( Status::WARN, $result->status() );
		$this->assertFalse( $result->is_critical() );
		$this->assertSame( 16, $result->details()['blocked_count'] );
		$this->assertNotContains( 'OAI-SearchBot', $result->details()['blocked'] );
		$this->assertStringContainsString( 'ChatGPT-User', $result->message() );
		$this->assertStringContainsString( '6 more', $result->message() );
		$this->assertSame( $result->message(), ( new AiSearchCrawlers( new RobotsRules( new Detector( new FakeEnvironment() ) ) ) )->render_message( $result->code(), $result->details() ), 'Run-time text equals text rendered from the stored record.' );
		$this->assertContains( 'ChatGPT-User', $result->details()['may_still_visit'] );
	}

	/**
	 * AC30: owner-named Googlebot + Applebot fallback are listed as owner choices, not fixable.
	 */
	public function test_owner_named_and_fallback_listed_as_guided(): void {
		$result = $this->run_with( "User-agent: Googlebot\nDisallow: /\n" );
		$this->assertSame( Status::WARN, $result->status() );
		$this->assertSame( array( 'Googlebot', 'Applebot' ), $result->details()['blocked'] );
		$this->assertSame( array( 'Googlebot', 'Applebot' ), $result->details()['owner_named'] );
		$this->assertSame( 0, $result->details()['fixable_count'] );
		$this->assertSame( Fixer::GUIDED, $result->fixer() );
	}

	/**
	 * Blocked only from the shop path still warns (sample paths).
	 */
	public function test_shop_path_block_warns(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'loja',
				'post_status' => 'publish',
			)
		);
		update_option( 'woocommerce_shop_page_id', $page );
		$shop = AiSearchCrawlers::path_of( (string) wc_get_page_permalink( 'shop' ) );
		$this->assertContains( $shop, AiSearchCrawlers::sample_paths() );
		$this->assertNotSame( '/', $shop );
		$result = $this->run_with( "User-agent: *\nDisallow: {$shop}\n" );
		$this->assertSame( Status::WARN, $result->status() );
	}

	/**
	 * AC39: training options (x7velo block) never change status.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_training_blocks(): array {
		return array(
			'none'            => array( '' ),
			'training 8'      => array( "User-agent: GPTBot\nUser-agent: ClaudeBot\nUser-agent: Applebot-Extended\nUser-agent: MistralAI-Training\nUser-agent: CCBot\nUser-agent: __usage_ai-training\nUser-agent: meta-externalagent\nUser-agent: Amazonbot\nDisallow: /" ),
			'google-extended' => array( "User-agent: Google-Extended\nDisallow: /" ),
			'bytespider'      => array( "User-agent: Bytespider\nDisallow: /" ),
		);
	}

	/**
	 * AC39.
	 *
	 * @dataProvider provide_training_blocks
	 *
	 * @param string $block x7velo block.
	 */
	public function test_training_options_never_change_score( string $block ): void {
		add_filter( 'x7velo_robots_block', static fn() => $block );
		$result = $this->run_with( null );
		$this->assertSame( Status::PASS, $result->status() );
		$this->assertSame( 'search_crawlers_allowed', $result->code() );
	}

	/**
	 * Our allow block makes a blocked store pass on re-run.
	 */
	public function test_x7velo_block_counts(): void {
		add_filter( 'robots_txt', static fn() => "User-agent: *\nDisallow: /\n", 1000 );
		add_filter( 'x7velo_robots_block', static fn() => "User-agent: OAI-SearchBot\nUser-agent: Googlebot\nAllow: /" );
		$result = $this->run_with( null );
		$this->assertSame( Status::WARN, $result->status() );
		$this->assertTrue( $result->details()['x7velo_block'] );
		// OAI-SearchBot and Googlebot named; Applebot follows the Googlebot group (fallback).
		$this->assertSame( 14, $result->details()['blocked_count'] );
	}

	/**
	 * AC42: blog_public = 0 → critical fail, Guided to VIS-01.
	 */
	public function test_blog_public_zero_blocked_by_search_visibility(): void {
		update_option( 'blog_public', '0' );
		$result = $this->run_with( null );
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( 'blocked_by_search_visibility', $result->code() );
		$this->assertTrue( $result->is_critical() );
		$this->assertSame( 'options-reading.php', $result->guided_path() );
	}

	/**
	 * AC44: subfolder install → not applicable.
	 */
	public function test_subfolder_install_not_applicable(): void {
		add_filter( 'home_url', static fn() => 'https://example.com/shop', 99 );
		$this->assertSame( Status::NOT_APPLICABLE, $this->run_with( "User-agent: *\nDisallow: /" )->status() );
	}

	/**
	 * Broken data file → the check throws (engine shows "Couldn't check").
	 */
	public function test_missing_data_throws(): void {
		$this->expectException( \RuntimeException::class );
		( new AiSearchCrawlers( new RobotsRules( new Detector( new FakeEnvironment() ) ), '/nonexistent.json' ) )->run();
	}

	/**
	 * Path helper keeps queries.
	 */
	public function test_path_of(): void {
		$this->assertSame( '/', AiSearchCrawlers::path_of( 'https://a.example' ) );
		$this->assertSame( '/?product=x', AiSearchCrawlers::path_of( 'https://a.example/?product=x' ) );
		$this->assertSame( '/loja/', AiSearchCrawlers::path_of( 'https://a.example/loja/' ) );
	}
}
