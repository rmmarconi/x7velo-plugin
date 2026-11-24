<?php
/**
 * CrawlerAccess tests (pure).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Robots;

use PHPUnit\Framework\TestCase;
use X7velo\Robots\CrawlerAccess;
use X7velo\Robots\RobotsTxt;

/**
 * Per-token evaluation over sample paths, with the Applebot → Googlebot fallback (spec §6.2.4).
 */
final class CrawlerAccessTest extends TestCase {

	private const PATHS = array( '/', '/shop/', '/product/a/' );

	/**
	 * Evaluates one token.
	 *
	 * @param string $robots robots.txt.
	 * @param string $token  Token.
	 * @return array<string, mixed>
	 */
	private function eval_token( string $robots, string $token ): array {
		return ( new CrawlerAccess( RobotsTxt::parse( $robots ) ) )->evaluate( $token, self::PATHS );
	}

	/**
	 * AC30: Googlebot named; Applebot (unnamed) follows Googlebot's group, flagged as fallback.
	 */
	public function test_owner_named_and_fallback_listed_as_guided(): void {
		$robots = "User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow:";

		$google = $this->eval_token( $robots, 'Googlebot' );
		$this->assertTrue( $google['named'] );
		$this->assertTrue( $google['blocked_root'] );

		$apple = $this->eval_token( $robots, 'Applebot' );
		$this->assertFalse( $apple['named'] );
		$this->assertSame( 'googlebot', $apple['via_fallback'] );
		$this->assertTrue( $apple['blocked'] );

		$bing = $this->eval_token( $robots, 'Bingbot' );
		$this->assertFalse( $bing['blocked'] );
	}

	/**
	 * Applebot with its own group ignores the fallback; without a Googlebot group it uses `*`.
	 */
	public function test_fallback_only_when_unnamed_and_target_named(): void {
		$own = $this->eval_token( "User-agent: Googlebot\nDisallow: /\n\nUser-agent: Applebot\nAllow: /", 'Applebot' );
		$this->assertTrue( $own['named'] );
		$this->assertSame( '', $own['via_fallback'] );
		$this->assertFalse( $own['blocked'] );

		$star = $this->eval_token( "User-agent: *\nDisallow: /", 'Applebot' );
		$this->assertSame( '', $star['via_fallback'] );
		$this->assertTrue( $star['blocked_root'] );
	}

	/**
	 * Amzn-SearchBot unnamed is treated as `*` (no fallback configured).
	 */
	public function test_amzn_searchbot_uses_star(): void {
		$result = $this->eval_token( "User-agent: Amazonbot\nDisallow: /\n\nUser-agent: *\nAllow: /", 'Amzn-SearchBot' );
		$this->assertFalse( $result['blocked'] );
		$this->assertFalse( $result['named'] );
	}

	/**
	 * Blocked only from a product path: blocked but not blocked_root.
	 */
	public function test_partial_block(): void {
		$result = $this->eval_token( "User-agent: *\nDisallow: /product/", 'OAI-SearchBot' );
		$this->assertTrue( $result['blocked'] );
		$this->assertFalse( $result['blocked_root'] );
		$this->assertSame( array( '/product/a/' ), $result['blocked_paths'] );
	}

	/**
	 * Custom fallbacks are honoured case-insensitively; invalid entries are dropped.
	 */
	public function test_custom_fallbacks(): void {
		$access = new CrawlerAccess(
			RobotsTxt::parse( "User-agent: Bingbot\nDisallow: /" ),
			array(
				'DuckAssistBot' => 'BINGBOT',
				0               => 'x',
			)
		);
		$this->assertSame( 'bingbot', $access->evaluate( 'DuckAssistBot', array( '/' ) )['via_fallback'] );
		$this->assertCount( 2, $access->evaluate_all( array( 'DuckAssistBot', 'GPTBot' ), array( '/' ) ) );
	}
}
