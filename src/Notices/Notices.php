<?php
/**
 * Activation and conflict notices (spec §8, §9; design §1.1, §2.4, §3.9).
 *
 * @package X7velo
 */

namespace X7velo\Notices;

use X7velo\Admin\AdminPage;
use X7velo\Capabilities;
use X7velo\Plugin;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Where notices appear (nowhere else):
 *
 * - Plugins screen: the one-time activation notice (until the user dismisses it or opens AI
 *   Readiness; per user) and each conflict notice once ("taken over" / "active again", with
 *   "See details" and, for "active again", "Turn it off" linking to the Fixes tab — no toggling
 *   in place, so the normal confirm dialog still applies).
 * - AI Readiness: the same conflict notices once, via `x7velo_admin_page_notices` (the React app
 *   renders them; its "Turn it off" uses the REST settings route with the REST nonce).
 *
 * Dismissal: admin-ajax `x7velo_dismiss_notice`, nonce + Capabilities::manage(), stored per user in
 * user meta `x7velo_dismissed_notices` (bounded list; deleted on uninstall).
 */
final class Notices {

	public const AJAX_ACTION   = 'x7velo_dismiss_notice';
	public const USER_META     = 'x7velo_dismissed_notices';
	public const MAX_DISMISSED = 20;

	/**
	 * Hooks the notices.
	 */
	public static function register(): void {
		add_action( 'load-plugins.php', array( self::class, 'evaluate' ) );
		add_action( 'admin_notices', array( self::class, 'render_plugins_notices' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'ajax_dismiss' ) );
		add_filter( 'x7velo_admin_page_notices', array( self::class, 'page_notices' ) );
	}

	/**
	 * Records conflict transitions (admin page loads only; never decides output).
	 */
	public static function evaluate(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			return;
		}
		NoticeState::evaluate( ( new Settings() )->all() );
	}

	/**
	 * `x7velo_admin_page_notices` callback (AI Readiness page load): evaluates, then hands the
	 * page each pending conflict notice once. Opening the page also retires the activation
	 * notice for this user (design §1.1).
	 *
	 * @param mixed $notices Notices.
	 * @return mixed
	 */
	public static function page_notices( $notices ) {
		if ( ! is_array( $notices ) || ! Capabilities::current_user_can_manage() ) {
			return $notices;
		}
		self::evaluate();
		$activation = NoticeState::activation_id();
		if ( '' !== $activation ) {
			self::dismiss_for_user( get_current_user_id(), $activation );
		}
		foreach ( NoticeState::take( 'page' ) as $entry ) {
			$notices[] = array(
				'id'     => $entry['id'],
				'type'   => $entry['type'],
				'area'   => $entry['area'],
				'source' => $entry['source'],
			);
		}
		return $notices;
	}

	/**
	 * Whether the current screen is the Plugins screen.
	 */
	private static function on_plugins_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen && in_array( $screen->id, array( 'plugins', 'plugins-network' ), true );
	}

	/**
	 * Dismiss handler script on the Plugins screen.
	 *
	 * @param mixed $hook_suffix Screen hook suffix.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( 'plugins.php' !== $hook_suffix || ! Capabilities::current_user_can_manage() ) {
			return;
		}
		$script = 'document.addEventListener("click",function(e){var b=e.target&&e.target.closest?e.target.closest(".x7velo-notice .notice-dismiss"):null;if(!b){return;}var n=b.closest(".x7velo-notice");if(!n||!window.ajaxurl){return;}var d=new FormData();d.append("action",' . wp_json_encode( self::AJAX_ACTION ) . ');d.append("id",n.getAttribute("data-x7velo-notice")||"");d.append("_ajax_nonce",n.getAttribute("data-x7velo-nonce")||"");window.fetch(window.ajaxurl,{method:"POST",credentials:"same-origin",body:d});});';
		wp_add_inline_script( 'common', $script );
	}

	/**
	 * Plugins screen notices.
	 */
	public static function render_plugins_notices(): void {
		if ( ! self::on_plugins_screen() || ! Capabilities::current_user_can_manage() ) {
			return;
		}
		$user  = get_current_user_id();
		$nonce = wp_create_nonce( self::AJAX_ACTION );

		$activation = NoticeState::activation_id();
		if ( '' !== $activation && ! self::is_dismissed( $user, $activation ) ) {
			$store = Plugin::is_woocommerce_active();
			self::print_notice(
				$activation,
				$nonce,
				$store
					? esc_html__( 'x7velo is active. See how ready your store is for AI assistants.', 'x7velo' )
					: esc_html__( 'x7velo is active. See how ready your site is for AI assistants.', 'x7velo' ),
				array(
					array(
						'url'     => AdminPage::url(),
						'label'   => $store ? __( 'Check my store', 'x7velo' ) : __( 'Check my site', 'x7velo' ),
						'primary' => true,
					),
				)
			);
		}

		foreach ( NoticeState::take( 'plugins' ) as $entry ) {
			$links = array(
				array(
					'url'     => AdminPage::url(),
					'label'   => __( 'See details', 'x7velo' ),
					'primary' => false,
				),
			);
			if ( 'resumed' === $entry['type'] ) {
				$links[] = array(
					'url'     => AdminPage::url( 'fixes' ),
					'label'   => __( 'Turn it off', 'x7velo' ),
					'primary' => false,
				);
			}
			self::print_notice( $entry['id'], $nonce, esc_html( self::conflict_text( $entry ) ), $links );
		}
	}

	/**
	 * Plain-language text of a conflict notice (same copy as the dashboard, design §3.9).
	 *
	 * @param array{type: string, area: string, source: string} $entry Notice.
	 */
	public static function conflict_text( array $entry ): string {
		$name = self::source_name( $entry['source'] );
		$llms = 'llms_txt' === $entry['area'];
		if ( 'resumed' === $entry['type'] ) {
			$text = $llms
				/* translators: %s: plugin name, e.g. "Yoast SEO". */
				? sprintf( __( '%s no longer creates your llms.txt, so x7velo’s llms.txt is active again.', 'x7velo' ), $name )
				/* translators: %s: plugin name, e.g. "Rank Math". */
				: sprintf( __( '%s no longer adds product details, so x7velo’s product details are active again.', 'x7velo' ), $name );
			return $text . ' ' . __( 'You turned it on earlier, so it came back on its own.', 'x7velo' );
		}
		if ( $llms && 'site' === Plugin::site_kind() ) {
			/* translators: %s: plugin name, e.g. "Yoast SEO". */
			return sprintf( __( '%s now creates your llms.txt, so x7velo paused its own. Your site has one llms.txt, as it should. You don’t need to do anything.', 'x7velo' ), $name );
		}
		return $llms
			/* translators: %s: plugin name, e.g. "Yoast SEO". */
			? sprintf( __( '%s now creates your llms.txt, so x7velo paused its own. Your store has one llms.txt, as it should. You don’t need to do anything.', 'x7velo' ), $name )
			/* translators: %1$s: plugin name, e.g. "Rank Math". */
			: sprintf( __( '%1$s now adds product details to your pages, so x7velo paused its own. Your products still have product details, just from %1$s.', 'x7velo' ), $name );
	}

	/**
	 * Display name of a source ID (adapter IDs; anything else is already a label).
	 *
	 * @param string $source Source ID or label.
	 */
	public static function source_name( string $source ): string {
		$names = array(
			'yoast'                 => 'Yoast SEO',
			'yoast-woocommerce-seo' => 'Yoast WooCommerce SEO',
			'rank-math'             => 'Rank Math',
			'aioseo'                => 'All in One SEO',
			'seopress'              => 'SEOPress',
			'file'                  => __( 'A file on your server', 'x7velo' ),
		);
		if ( isset( $names[ $source ] ) ) {
			return $names[ $source ];
		}
		return '' === $source ? __( 'Another plugin', 'x7velo' ) : $source;
	}

	/**
	 * Prints one dismissible notice. `$message` must already be escaped.
	 *
	 * @param string                                                       $id      Notice ID.
	 * @param string                                                       $nonce   Dismiss nonce.
	 * @param string                                                       $message Escaped message.
	 * @param array<int, array{url: string, label: string, primary: bool}> $links   Action links.
	 */
	private static function print_notice( string $id, string $nonce, string $message, array $links ): void {
		printf(
			'<div class="notice notice-info is-dismissible x7velo-notice" data-x7velo-notice="%1$s" data-x7velo-nonce="%2$s"><p>%3$s</p>',
			esc_attr( $id ),
			esc_attr( $nonce ),
			$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller (esc_html).
		);
		if ( array() !== $links ) {
			echo '<p>';
			foreach ( $links as $link ) {
				printf(
					'<a class="%1$s" href="%2$s">%3$s</a> ',
					esc_attr( $link['primary'] ? 'button button-primary' : 'button button-secondary' ),
					esc_url( $link['url'] ),
					esc_html( $link['label'] )
				);
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * AJAX: dismiss a notice for the current user.
	 */
	public static function ajax_dismiss(): void {
		check_ajax_referer( self::AJAX_ACTION );
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( null, 403 );
		}
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		if ( '' === $id || strlen( $id ) > 100 ) {
			wp_send_json_error( null, 400 );
		}
		self::dismiss_for_user( get_current_user_id(), $id );
		wp_send_json_success();
	}

	/**
	 * Adds a notice ID to a user's dismissed list (bounded, newest kept).
	 *
	 * @param int    $user User ID.
	 * @param string $id   Notice ID.
	 */
	public static function dismiss_for_user( int $user, string $id ): void {
		if ( $user <= 0 || self::is_dismissed( $user, $id ) ) {
			return;
		}
		$list   = self::dismissed( $user );
		$list[] = $id;
		update_user_meta( $user, self::USER_META, array_slice( $list, -self::MAX_DISMISSED ) );
	}

	/**
	 * Whether a user dismissed a notice.
	 *
	 * @param int    $user User ID.
	 * @param string $id   Notice ID.
	 */
	public static function is_dismissed( int $user, string $id ): bool {
		return in_array( $id, self::dismissed( $user ), true );
	}

	/**
	 * A user's dismissed notice IDs.
	 *
	 * @param int $user User ID.
	 * @return string[]
	 */
	private static function dismissed( int $user ): array {
		$list = get_user_meta( $user, self::USER_META, true );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
	}
}
