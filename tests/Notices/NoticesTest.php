<?php
/**
 * Plugins-screen notices, dismissal, page hand-off, uninstall (spec §9; AC79, AC86, AC91).
 *
 * @package X7velo
 */

namespace X7velo\Tests\Notices;

use WPAjaxDieContinueException;
use WPAjaxDieStopException;
use WP_Ajax_UnitTestCase;
use X7velo\Notices\NoticeState;
use X7velo\Notices\Notices;
use X7velo\Plugin;
use X7velo\Settings;

/**
 * Rendering and the dismiss endpoint. Extends the AJAX test case for its wp_die handling (the
 * tests are not in the excluded "ajax" group).
 */
final class NoticesTest extends WP_Ajax_UnitTestCase {

	/**
	 * Clean state; admin with manage_woocommerce.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( NoticeState::OPTION );
		delete_option( Settings::OPTION );
		if ( ! has_action( 'wp_ajax_' . Notices::AJAX_ACTION ) ) {
			add_action( 'wp_ajax_' . Notices::AJAX_ACTION, array( Notices::class, 'ajax_dismiss' ) ); // Not booted without WooCommerce.
		}
		$this->_setRole( 'administrator' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_get_current_user()->add_cap( 'manage_woocommerce' );
		}
	}

	/**
	 * Renders the Plugins-screen notices.
	 *
	 * @param string $screen Screen ID.
	 */
	private function render( string $screen = 'plugins' ): string {
		set_current_screen( $screen );
		ob_start();
		Notices::render_plugins_notices();
		return (string) ob_get_clean();
	}

	/**
	 * AC79: activation arms one notice, Plugins screen only, until dismissed by that user.
	 */
	public function test_activation_notice_plugins_screen_only(): void {
		Plugin::activate();
		$kind = \X7velo\Plugin::is_woocommerce_active() ? 'store' : 'site';
		$this->assertStringContainsString( 'x7velo is active. See how ready your ' . $kind . ' is for AI assistants.', $this->render() );
		$this->assertStringContainsString( 'Check my ' . $kind, $this->render() );
		$this->assertSame( '', $this->render( 'dashboard' ) );
		$this->assertSame( '', $this->render( 'edit-product' ) );
		$this->assertSame( array(), ( new Settings() )->get( 'block_experimental' ), 'Nothing applied on activation.' );
		$this->assertFalse( ( new Settings() )->get( 'fix_robots_search' ) );
	}

	/**
	 * Dismiss persists for that user; a second admin still sees it.
	 */
	public function test_dismiss_per_user(): void {
		Plugin::activate();
		$id = NoticeState::activation_id();

		$_POST['_ajax_nonce'] = wp_create_nonce( Notices::AJAX_ACTION );
		$_POST['id']          = $id;
		try {
			$this->_handleAjax( Notices::AJAX_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$this->assertTrue( json_decode( $this->_last_response, true )['success'] );
		$this->assertSame( '', $this->render() );

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );
		wp_get_current_user()->add_cap( 'manage_woocommerce' );
		$this->assertStringContainsString( 'x7velo is active', $this->render() );
	}

	/**
	 * Dismiss needs a valid nonce and manage_woocommerce.
	 */
	public function test_dismiss_requires_nonce_and_capability(): void {
		$_POST['_ajax_nonce'] = 'bad';
		$_POST['id']          = 'activation-1';
		try {
			$this->_handleAjax( Notices::AJAX_ACTION );
			$this->fail( 'Expected a die.' );
		} catch ( WPAjaxDieStopException $e ) {
			$this->assertSame( '-1', $e->getMessage() );
		}

		$this->_setRole( 'subscriber' );
		$_POST['_ajax_nonce'] = wp_create_nonce( Notices::AJAX_ACTION );
		$this->_last_response = '';
		try {
			$this->_handleAjax( Notices::AJAX_ACTION );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$this->assertFalse( json_decode( $this->_last_response, true )['success'] );
		$this->assertSame( '', (string) get_user_meta( get_current_user_id(), Notices::USER_META, true ) );
	}

	/**
	 * Opening AI Readiness retires the activation notice for that user (design §1.1).
	 */
	public function test_page_visit_retires_activation_notice(): void {
		Plugin::activate();
		Notices::page_notices( array() );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * AC86: conflict notice once on the Plugins screen, escaped, with See details / Turn it off.
	 */
	public function test_conflict_notices_once_on_plugins_screen(): void {
		NoticeState::record_conflict( 'llms_txt', 'yoast' );
		$html = $this->render();
		$this->assertStringContainsString( 'Yoast SEO now creates your llms.txt, so x7velo paused its own.', $html );
		$this->assertStringContainsString( 'See details', $html );
		$this->assertStringNotContainsString( 'Turn it off', $html );
		$this->assertSame( '', $this->render(), 'Once.' );
		$this->assertSame( '', $this->render( 'dashboard' ) );

		NoticeState::record_conflict( 'llms_txt', '' );
		$html = $this->render();
		$this->assertStringContainsString( 'Yoast SEO no longer creates your llms.txt, so x7velo’s llms.txt is active again.', $html );
		$this->assertStringContainsString( 'tab=fixes', $html );
		$this->assertStringContainsString( 'Turn it off', $html );

		NoticeState::record_conflict( 'jsonld', '<b>Evil</b> Plugin' );
		$this->assertStringNotContainsString( '<b>', $this->render() );
	}

	/**
	 * Users without manage_woocommerce see nothing and evaluate nothing.
	 */
	public function test_no_notices_without_capability(): void {
		Plugin::activate();
		NoticeState::record_conflict( 'llms_txt', 'yoast' );
		$this->_setRole( 'editor' );
		$this->assertSame( '', $this->render() );
		$this->assertSame( array(), Notices::page_notices( array() ) );
		$this->assertCount( 1, NoticeState::pending( 'plugins' ), 'Still pending for an admin.' );
	}

	/**
	 * AC91: uninstall removes the notice option and every user's dismissals.
	 */
	public function test_uninstall_removes_state_and_dismissals(): void {
		Plugin::activate();
		Notices::dismiss_for_user( get_current_user_id(), 'activation-1' );
		$other = self::factory()->user->create();
		Notices::dismiss_for_user( $other, 'activation-1' );
		set_transient( 'x7velo_rewrite_sync', 'on', 60 );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'x7velo/x7velo.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( NoticeState::OPTION ) );
		$this->assertFalse( get_transient( 'x7velo_rewrite_sync' ) );
		$this->assertSame( '', get_user_meta( get_current_user_id(), Notices::USER_META, true ) );
		$this->assertSame( '', get_user_meta( $other, Notices::USER_META, true ) );
	}
}
