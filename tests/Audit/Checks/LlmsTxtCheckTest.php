<?php
/**
 * CRAWL-03 tests.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Audit\Checks;

use WP_UnitTestCase;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Checks\LlmsTxt;
use X7velo\Audit\Fixer;
use X7velo\Audit\Status;
use X7velo\Detection\Detector;
use X7velo\Settings;
use X7velo\Tests\Detection\FakeEnvironment;
use X7velo\Tests\Detection\SeoFixtures;

/**
 * Pass / warn / fail and the Guided-first rule.
 */
final class LlmsTxtCheckTest extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'permalink_structure', '/%postname%/' );
	}

	/**
	 * Runs CRAWL-03.
	 *
	 * @param FakeEnvironment $env Env.
	 */
	private function run_with( FakeEnvironment $env ): CheckResult {
		return ( new LlmsTxt( new Detector( $env ), new Settings() ) )->run();
	}

	/**
	 * No source and no capable plugin → fail with x7velo's fixer.
	 */
	public function test_fails_without_source(): void {
		$result = $this->run_with( new FakeEnvironment() );
		$this->assertSame( Status::FAIL, $result->status() );
		$this->assertSame( Fixer::LLMS_TXT, $result->fixer() );
	}

	/**
	 * Yoast active, llms off → Guided "turn on Yoast's", x7velo secondary.
	 */
	public function test_guided_first_with_capable_plugin(): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		$result = $this->run_with( ( new FakeEnvironment() )->with( 'yoast' ) );
		$this->assertSame( 'no_llms_txt_plugin_available', $result->code() );
		$this->assertSame( Fixer::GUIDED, $result->fixer() );
		$this->assertSame( 'admin.php?page=wpseo_page_settings#/llms-txt', $result->guided_path() );
		$this->assertSame( 'llms-txt', $result->details()['secondary_fixer'] );
		$this->assertStringContainsString( 'Yoast SEO', $result->message() );
	}

	/**
	 * AC54: managed by Yoast; x7velo on alone passes "from x7velo".
	 */
	public function test_passes_managed_and_own(): void {
		SeoFixtures::load( SeoFixtures::YOAST );
		SeoFixtures::merge( 'wpseo', array( 'enable_llms_txt' => true ) );
		$result = $this->run_with( ( new FakeEnvironment() )->with( 'yoast' ) );
		$this->assertSame( 'llms_txt_managed', $result->code() );
		$this->assertStringContainsString( 'managed by Yoast SEO', $result->message() );

		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$this->assertSame( 'llms_txt_managed', $this->run_with( ( new FakeEnvironment() )->with( 'yoast' ) )->code(), 'x7velo paused, not a 2nd source.' );
		$this->assertSame( 'llms_txt_x7velo', $this->run_with( new FakeEnvironment() )->code() );
	}

	/**
	 * AC57: Rank Math + unattributed file → warn naming the file as served.
	 */
	public function test_multiple_sources_warn(): void {
		SeoFixtures::load( SeoFixtures::RANK_MATH );
		SeoFixtures::merge( 'rank_math_modules', array( 'llms-txt' ) );
		$env                          = ( new FakeEnvironment() )->with( 'rank-math' );
		$env->files['/site/llms.txt'] = '# x';
		$result                       = $this->run_with( $env );
		$this->assertSame( Status::WARN, $result->status() );
		$this->assertSame( 'file', $result->details()['served'] );
		$this->assertStringContainsString( 'Rank Math, a file on your server', $result->message() );
		$this->assertStringContainsString( 'is the one visitors get', $result->message() );
	}
}
