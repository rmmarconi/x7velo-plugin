<?php
/**
 * Detection-aware fixer states.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Detection;

use WP_UnitTestCase;
use X7velo\Detection\Detector;
use X7velo\Detection\FixerStates;
use X7velo\Detection\SchemaSample;

/**
 * States managed / paused / cannot_run, never stored.
 */
final class FixerStatesTest extends WP_UnitTestCase {

	/**
	 * JSON-LD: Woo only → augment; Rank Math → managed / paused; Woo removed → emit.
	 */
	public function test_jsonld(): void {
		delete_option( SchemaSample::OPTION );
		$row = FixerStates::jsonld( new Detector( new FakeEnvironment() ), array( 'state' => 'off' ), false );
		$this->assertSame( 'augment', $row['mode'] );

		SeoFixtures::load( SeoFixtures::RANK_MATH );
		$env = ( new FakeEnvironment() )->with( 'rank-math' );
		$this->assertSame( 'managed', FixerStates::jsonld( new Detector( $env ), array( 'state' => 'off' ), false )['state'] );
		$paused = FixerStates::jsonld( new Detector( $env ), array( 'state' => 'on' ), true );
		$this->assertSame( 'paused', $paused['state'] );
		$this->assertSame( 'Rank Math', $paused['managed_by'] );

		$detector = new Detector( new FakeEnvironment() );
		SchemaSample::store( false, $detector->schema_fingerprint() );
		$this->assertSame( 'emit', FixerStates::jsonld( $detector, array( 'state' => 'off' ), false )['mode'] );

		$yoast_woo = ( new FakeEnvironment() )->with( 'yoast-woo' );
		$this->assertSame( 'managed', FixerStates::jsonld( new Detector( $yoast_woo ), array( 'state' => 'off' ), false )['state'] );
	}

	/**
	 * The llms.txt area: managed by Yoast; paused when ours is on; cannot_run with plain permalinks.
	 */
	public function test_llms(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertSame( 'off', FixerStates::llms( new Detector( new FakeEnvironment() ), array( 'state' => 'off' ), false )['state'] );

		SeoFixtures::load( SeoFixtures::YOAST );
		SeoFixtures::merge( 'wpseo', array( 'enable_llms_txt' => true ) );
		$env = ( new FakeEnvironment() )->with( 'yoast' );
		$row = FixerStates::llms( new Detector( $env ), array( 'state' => 'on' ), true );
		$this->assertSame( 'paused', $row['state'] );
		$this->assertSame( 'Yoast SEO', $row['managed_by'] );
		$this->assertStringContainsString( 'wpseo_page_settings', $row['url'] );

		update_option( 'permalink_structure', '' );
		$this->assertSame( 'cannot_run', FixerStates::llms( new Detector( new FakeEnvironment() ), array( 'state' => 'off' ), false )['state'] );
	}

	/**
	 * Robots: cannot_run for physical file, private site, subfolder.
	 */
	public function test_robots(): void {
		update_option( 'blog_public', '1' );
		$this->assertSame( 'off', FixerStates::robots( new Detector( new FakeEnvironment() ), array( 'state' => 'off' ) )['state'] );

		$env                            = new FakeEnvironment();
		$env->files['/site/robots.txt'] = "User-agent: *\n";
		$this->assertSame( 'physical_robots_file', FixerStates::robots( new Detector( $env ), array( 'state' => 'off' ) )['reason'] );

		update_option( 'blog_public', '0' );
		$this->assertSame( 'search_engines_discouraged', FixerStates::robots( new Detector( new FakeEnvironment() ), array( 'state' => 'on' ) )['reason'] );

		add_filter( 'home_url', static fn() => 'https://example.com/shop', 99 );
		$this->assertSame( 'subfolder_install', FixerStates::robots( new Detector( new FakeEnvironment() ), array( 'state' => 'off' ) )['reason'] );
	}

	/**
	 * AC69: computing states never writes the settings option.
	 */
	public function test_never_stores(): void {
		$writes = 0;
		add_action(
			'update_option_x7velo_settings',
			static function () use ( &$writes ) {
				++$writes;
			}
		);
		FixerStates::filter(
			array(
				'jsonld'   => array( 'state' => 'on' ),
				'llms_txt' => array( 'state' => 'on' ),
				'feed'     => array( 'state' => 'off' ),
				'robots'   => array( 'state' => 'on' ),
			),
			array( 'fix_jsonld' => true )
		);
		$this->assertSame( 0, $writes );
	}
}
