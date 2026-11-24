<?php
/**
 * Notice state machine (spec §8; AC58, AC86).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Notices;

use WP_UnitTestCase;
use X7velo\Notices\NoticeState;
use X7velo\Settings;

/**
 * Once per transition, once per surface, no writes without a transition, autoload off.
 */
final class NoticeStateTest extends WP_UnitTestCase {

	/**
	 * Clean state.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( NoticeState::OPTION );
		delete_option( Settings::OPTION );
	}

	/**
	 * Counts writes to the option.
	 *
	 * @param int $writes Counter.
	 */
	private function count_writes( int &$writes ): void {
		$counter = static function ( $value ) use ( &$writes ) {
			++$writes;
			return $value;
		};
		add_filter( 'pre_update_option_' . NoticeState::OPTION, $counter );
		add_action(
			'add_option_' . NoticeState::OPTION,
			static function () use ( &$writes ) { // phpcs:ignore Universal.FunctionDeclarations.NoLongClosures -- Short test closure.
				++$writes;
			}
		);
	}

	/**
	 * Taken over → resumed → taken over by another plugin; each queued once for both surfaces.
	 */
	public function test_transitions(): void {
		$this->assertNull( NoticeState::record_conflict( 'llms_txt', '' ), 'No conflict, nothing recorded.' );
		$this->assertFalse( get_option( NoticeState::OPTION ), 'No write without a transition.' );

		$taken = NoticeState::record_conflict( 'llms_txt', 'yoast' );
		$this->assertSame( 'taken_over', $taken['type'] );
		$this->assertSame( 'yoast', $taken['source'] );
		$this->assertNull( NoticeState::record_conflict( 'llms_txt', 'yoast' ), 'Same source: once.' );

		$this->assertCount( 1, NoticeState::take( 'page' ) );
		$this->assertSame( array(), NoticeState::take( 'page' ) );

		$resumed = NoticeState::record_conflict( 'llms_txt', '' );
		$this->assertSame( 'resumed', $resumed['type'] );
		$this->assertSame( 'yoast', $resumed['source'], 'Names the plugin that stopped.' );
		$pending = NoticeState::pending( 'plugins' );
		$this->assertCount( 1, $pending, 'Superseded taken_over notice dropped.' );
		$this->assertSame( 'resumed', $pending[0]['type'] );

		$this->assertSame( 'taken_over', NoticeState::record_conflict( 'llms_txt', 'rank-math' )['type'] );
		$this->assertSame( 'taken_over', NoticeState::record_conflict( 'llms_txt', 'aioseo' )['type'], 'A different owner is a new transition.' );
	}

	/**
	 * Taken over and back before anyone looked: nothing to say.
	 */
	public function test_unseen_round_trip_cancels(): void {
		NoticeState::record_conflict( 'jsonld', 'Rank Math' );
		$this->assertNull( NoticeState::record_conflict( 'jsonld', '' ) );
		$this->assertSame( array(), NoticeState::pending( 'page' ) );
		$this->assertSame( array(), NoticeState::pending( 'plugins' ) );
		$this->assertArrayNotHasKey( 'jsonld', NoticeState::get()['areas'] );
	}

	/**
	 * Areas are independent (generic for track H).
	 */
	public function test_areas_independent(): void {
		if ( ! \X7velo\Plugin::is_woocommerce_active() ) {
			$this->markTestSkipped( 'The jsonld area is frozen without WooCommerce (covered by SiteModeTest).' );
		}
		NoticeState::record_conflict( 'llms_txt', 'yoast' );
		NoticeState::record_conflict( 'jsonld', 'Rank Math' );
		$this->assertCount( 2, NoticeState::take( 'page' ) );
		NoticeState::record_off( 'jsonld' );
		$this->assertSame( array( 'llms_txt' => 'yoast' ), NoticeState::get()['areas'] );
		$this->assertCount( 1, NoticeState::take( 'plugins' ), 'Turning jsonld off dropped its pending notice.' );
	}

	/**
	 * Toggle off: no resumed notice later.
	 */
	public function test_off_forgets_source(): void {
		NoticeState::record_conflict( 'llms_txt', 'yoast' );
		NoticeState::take( 'page' );
		NoticeState::take( 'plugins' );
		NoticeState::record_off( 'llms_txt' );
		$this->assertNull( NoticeState::record_conflict( 'llms_txt', '' ) );
	}

	/**
	 * Evaluate falls back to the derived fixer status for areas no callback reports (jsonld).
	 */
	public function test_evaluate_fallback_uses_fixer_status(): void {
		if ( ! \X7velo\Plugin::is_woocommerce_active() ) {
			$this->markTestSkipped( 'The jsonld area is frozen without WooCommerce (covered by SiteModeTest).' );
		}
		$paused = static function ( $status ) {
			$status['jsonld'] = array(
				'state'      => 'paused',
				'managed_by' => 'Rank Math',
			);
			return $status;
		};
		add_filter( 'x7velo_fixer_status', $paused, 99 );
		NoticeState::evaluate( array( 'fix_jsonld' => true ) );
		$pending = NoticeState::pending( 'page' );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'jsonld', $pending[0]['area'] );
		$this->assertSame( 'Rank Math', $pending[0]['source'] );

		remove_filter( 'x7velo_fixer_status', $paused, 99 );
		NoticeState::take( 'page' );
		NoticeState::take( 'plugins' );
		NoticeState::evaluate( array( 'fix_jsonld' => true ) );
		$this->assertSame( 'resumed', NoticeState::pending( 'page' )[0]['type'] ?? null );
	}

	/**
	 * Steady state: evaluating on every admin page load writes nothing.
	 */
	public function test_no_writes_in_steady_state(): void {
		NoticeState::record_conflict( 'jsonld', 'Rank Math' );
		$writes = 0;
		$this->count_writes( $writes );
		for ( $i = 0; $i < 5; $i++ ) {
			NoticeState::record_conflict( 'jsonld', 'Rank Math' );
		}
		NoticeState::record_off( 'feed' );
		$this->assertSame( 0, $writes );

		NoticeState::evaluate( array() ); // Everything off: settles once.
		$writes = 0;
		for ( $i = 0; $i < 5; $i++ ) {
			NoticeState::evaluate( array() );
		}
		$this->assertSame( 0, $writes );
	}

	/**
	 * Autoload off; bounded queue; activation id changes per activation.
	 */
	public function test_storage(): void {
		global $wpdb;
		for ( $i = 0; $i < 30; $i++ ) {
			NoticeState::record_conflict( 'area' . $i, 'p' );
		}
		$this->assertCount( NoticeState::MAX_QUEUE, NoticeState::get()['queue'] );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", NoticeState::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'no', 'off' ) );

		$this->assertSame( '', NoticeState::activation_id() );
		NoticeState::activated();
		$this->assertMatchesRegularExpression( '/^activation-\d+$/', NoticeState::activation_id() );
	}

	/**
	 * Stored state never changes owner settings (AC69).
	 */
	public function test_never_touches_settings(): void {
		( new Settings() )->update( array( 'fix_llms_txt' => true ) );
		$before = get_option( Settings::OPTION );
		NoticeState::evaluate( ( new Settings() )->all() );
		NoticeState::record_conflict( 'llms_txt', 'yoast' );
		NoticeState::record_conflict( 'llms_txt', '' );
		$this->assertSame( $before, get_option( Settings::OPTION ) );
	}
}
