<?php
/**
 * Crawl fixers module: robots fixer, llms.txt fixer, activation/conflict notices.
 *
 * @package X7velo
 */

namespace X7velo\Fixers;

use X7velo\Fixers\LlmsTxt\LlmsTxtEndpoint;
use X7velo\Fixers\Robots\RobotsFixer;
use X7velo\Notices\NoticeState;
use X7velo\Notices\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Wiring only. Call CrawlModule::register() once from Plugin::boot() (WooCommerce active),
 * CrawlModule::activate() from Plugin::activate() and CrawlModule::deactivate() from
 * Plugin::deactivate() (those run without boot, so they work even when WooCommerce is off).
 * Nothing is applied on activation: every toggle defaults to off; activation only arms the
 * one-time notice.
 */
final class CrawlModule {

	/**
	 * Hooks the fixers and notices.
	 */
	public static function register(): void {
		RobotsFixer::register();
		LlmsTxtEndpoint::register();
		Notices::register();
		add_filter( 'x7velo_notice_sources', array( LlmsTxtEndpoint::class, 'notice_source' ), 10, 2 );
	}

	/**
	 * Activation: arms the one-time activation notice (Plugins screen).
	 */
	public static function activate(): void {
		NoticeState::activated();
	}

	/**
	 * Deactivation: removes the llms.txt rewrite rule and flushes; data is kept.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		LlmsTxtEndpoint::deactivate( $network_wide );
	}
}
