<?php
/**
 * Robots block builder goldens (QA plan §3.1 layer 1, §3.3).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Fixers\Robots;

use WP_UnitTestCase;
use X7velo\Data\AiCrawlers;
use X7velo\Fixers\Robots\RobotsBlock;
use X7velo\Robots\CrawlerAccess;
use X7velo\Robots\RobotsTxt;
use X7velo\Settings;

/**
 * Each case: tests/fixtures/robots/cases/<id>/{owner.txt, site.json, settings.json,
 * expected-block.txt}. `{{HOME}}` stands for the site origin. An empty expected file = no block.
 *
 * Regenerate: UPDATE_GOLDENS=1 composer test -- --filter RobotsBlockGoldenTest (review the diff).
 *
 * Besides byte equality, every case checks what the block achieves (owner rules + block parsed
 * together): un-named scored tokens can fetch the sample paths and not the private paths, owner-
 * named tokens keep their verdict, selected training tokens are blocked from "/".
 */
final class RobotsBlockGoldenTest extends WP_UnitTestCase {

	private const HOME = 'https://shop.example';

	/**
	 * Case directory.
	 */
	private static function dir(): string {
		return dirname( __DIR__, 2 ) . '/fixtures/robots/cases';
	}

	/**
	 * Case IDs.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function cases(): array {
		$out = array();
		foreach ( (array) scandir( self::dir() ) as $id ) {
			if ( is_string( $id ) && '.' !== $id[0] && is_dir( self::dir() . '/' . $id ) ) {
				$out[ $id ] = array( $id );
			}
		}
		return $out;
	}

	/**
	 * Reads one fixture file with {{HOME}} expanded.
	 *
	 * @param string $id   Case.
	 * @param string $file File.
	 */
	private static function read( string $id, string $file ): string {
		return str_replace( '{{HOME}}', self::HOME, (string) file_get_contents( self::dir() . '/' . $id . '/' . $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
	}

	/**
	 * Golden + invariants.
	 *
	 * @dataProvider cases
	 *
	 * @param string $id Case.
	 */
	public function test_golden( string $id ): void {
		$owner    = self::read( $id, 'owner.txt' );
		$site     = json_decode( self::read( $id, 'site.json' ), true );
		$settings = Settings::sanitize( (array) json_decode( self::read( $id, 'settings.json' ), true ) );
		$agents   = AiCrawlers::agents();

		$built  = RobotsBlock::build( $owner, $settings, $agents, $site );
		$actual = str_replace( self::HOME, '{{HOME}}', $built['block'] );

		$expected_file = self::dir() . '/' . $id . '/expected-block.txt';
		if ( getenv( 'UPDATE_GOLDENS' ) ) {
			file_put_contents( $expected_file, $actual ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Golden update (test only).
		}
		$this->assertSame( (string) file_get_contents( $expected_file ), $actual, "Golden {$id}" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.

		$this->assert_invariants( $owner, $built, $settings, $agents, $site );
	}

	/**
	 * What the block must achieve.
	 *
	 * @param string                           $owner    Owner rules.
	 * @param array<string, string>            $built    Builder output.
	 * @param array<string, mixed>             $settings Settings.
	 * @param array<int, array<string, mixed>> $agents   Crawler entries.
	 * @param array<string, mixed>             $site     Site facts.
	 */
	private function assert_invariants( string $owner, array $built, array $settings, array $agents, array $site ): void {
		$block = $built['block'];
		if ( '' === $block ) {
			$this->assertSame( '', $built['allow'] . $built['training'] . $built['sitemap'] );
		} else {
			$this->assertStringStartsWith( RobotsBlock::BEGIN . "\n", $block );
			$this->assertStringEndsWith( "\n" . RobotsBlock::END . "\n", $block );
			$this->assertDoesNotMatchRegularExpression( '/^Disallow: \/\*?$/m', $built['allow'], 'No blanket Disallow in the allow group.' );
		}
		$lines = array_filter( explode( "\n", $built['allow'] ) );
		$this->assertSame( count( $lines ), count( array_unique( $lines ) ), 'Duplicate lines collapsed.' );

		$before = new CrawlerAccess( RobotsTxt::parse( $owner ) );
		$after  = new CrawlerAccess( RobotsTxt::parse( rtrim( $owner ) . "\n\n" . $block ) );
		$paths  = $site['sample_paths'];
		$named  = RobotsTxt::parse( $owner );

		foreach ( $agents as $agent ) {
			$token = $agent['token'];
			if ( true !== ( $agent['affects_score'] ?? false ) ) {
				continue;
			}
			$was = $before->evaluate( $token, $paths );
			$now = $after->evaluate( $token, $paths );
			if ( $was['named'] || '' !== $was['via_fallback'] ) {
				$this->assertSame( $was['blocked_paths'], $now['blocked_paths'], "{$token}: owner rule stands." );
				$this->assertStringNotContainsString( 'User-agent: ' . $token . "\n", $built['allow'] );
				continue;
			}
			if ( ! empty( $settings['fix_robots_search'] ) && $was['blocked'] ) {
				$this->assertFalse( $now['blocked'], "{$token} may fetch every sample path after the block." );
				foreach ( array_merge( (array) $site['private_paths'], array( $site['admin_path'] ) ) as $private ) {
					$this->assertTrue( $after->evaluate( $token, array( $private ) )['blocked'], "{$token} kept out of {$private}." );
				}
				$this->assertTrue( RobotsTxt::parse( rtrim( $owner ) . "\n\n" . $block )->is_allowed( $token, $site['ajax_path'] ) );
			}
			if ( ! $was['blocked'] ) {
				$this->assertFalse( $now['blocked'], "{$token} not blocked by the block." );
			}
		}

		foreach ( RobotsBlock::training_tokens( $settings, $agents ) as $token ) {
			if ( ! $named->names_agent( $token ) ) {
				$this->assertFalse( RobotsTxt::parse( rtrim( $owner ) . "\n\n" . $block )->is_allowed( $token, '/' ), "{$token} blocked." );
			}
		}

		$sitemaps = RobotsTxt::parse( rtrim( $owner ) . "\n\n" . $block )->sitemaps();
		$this->assertSame( count( $sitemaps ), count( array_unique( array_map( 'strtolower', $sitemaps ) ) ), 'At most one Sitemap line per URL.' );
	}

	/**
	 * AC36: the default training set, literally and as derived from the data file.
	 */
	public function test_training_tokens_match_data_file(): void {
		$tokens = RobotsBlock::training_tokens( array( 'fix_robots_block_training' => true ), AiCrawlers::agents() );
		$this->assertSame(
			array( 'GPTBot', 'ClaudeBot', 'Applebot-Extended', 'meta-externalagent', 'Amazonbot', 'MistralAI-Training', 'CCBot', '__usage_ai-training' ),
			$tokens,
			'If the data file changed, update this list (spec §6.1) and the goldens.'
		);
		$derived = array();
		foreach ( AiCrawlers::agents() as $agent ) {
			if ( 'training' === ( $agent['purpose'] ?? '' ) && true !== ( $agent['experimental'] ?? false ) && 0 !== strcasecmp( $agent['token'], 'Google-Extended' ) ) {
				$derived[] = $agent['token'];
			}
		}
		$this->assertSame( $derived, $tokens );
		$this->assertNotContains( 'Google-Extended', $tokens );
		$this->assertNotContains( 'Bytespider', $tokens );
	}

	/**
	 * AC70 (output half): an experimental token not in the data file is never emitted.
	 */
	public function test_unknown_experimental_token_ignored(): void {
		$tokens = RobotsBlock::training_tokens( array( 'block_experimental' => array( 'GPTBot', 'NotABot' ) ), AiCrawlers::agents() );
		$this->assertSame( array(), $tokens );
	}

	/**
	 * Owner disallow extraction: only `*` groups, verbatim, blanket lines dropped, unsafe values
	 * normalised.
	 */
	public function test_star_disallows(): void {
		$owner = "Disallow: /orphan/\nUser-agent: Googlebot\nDisallow: /g/\n\nUser-agent: *\nDisallow: /\nDisallow: /*\nDisallow: /a/ # comment\nDisallow:\nDisallow: private\nAllow: /x/\nUser-agent: Bingbot\nUser-agent: *\nDisallow: /b/\n";
		$this->assertSame( array( '/a/', '/private', '/b/' ), RobotsBlock::star_disallows( $owner ) );
	}

	/**
	 * The safety regexes anchor at the true end of the string (`\z`), so a trailing newline
	 * can't slip through `$`.
	 */
	public function test_safety_regexes_reject_trailing_newline(): void {
		$safe = new \ReflectionMethod( RobotsBlock::class, 'is_safe_path' );
		$safe->setAccessible( true );
		$this->assertTrue( $safe->invoke( null, '/cart/' ) );
		$this->assertFalse( $safe->invoke( null, "/cart/\n" ) );

		$line = new \ReflectionMethod( RobotsBlock::class, 'sitemap_line' );
		$line->setAccessible( true );
		$owner = RobotsTxt::parse( '' );
		$this->assertSame( 'Sitemap: https://shop.example/wp-sitemap.xml', $line->invoke( null, $owner, array( 'sitemap_url' => 'https://shop.example/wp-sitemap.xml' ) ) );
		$this->assertSame( '', $line->invoke( null, $owner, array( 'sitemap_url' => "https://shop.example/wp-sitemap.xml\n" ) ) );
	}
}
