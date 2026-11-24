<?php
/**
 * VIS-02 https.
 *
 * @package X7velo
 */

namespace X7velo\Audit\Checks;

use X7velo\Audit\Category;
use X7velo\Audit\Check;
use X7velo\Audit\CheckResult;
use X7velo\Audit\Fixer;
use X7velo\Audit\MessageRenderer;
use X7velo\Audit\Status;
use X7velo\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Both the site address and WordPress address must use https.
 */
final class Https implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'VIS-02';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::VISIBILITY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 5;
	}

	/**
	 * {@inheritDoc}
	 */
	public function run(): CheckResult {
		$details = array(
			'home_https'    => 'https' === wp_parse_url( (string) get_option( 'home' ), PHP_URL_SCHEME ),
			'siteurl_https' => 'https' === wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_SCHEME ),
			'site_kind'     => Plugin::site_kind(),
		);
		if ( $details['home_https'] && $details['siteurl_https'] ) {
			return $this->make( Status::PASS, 'https_on', $details, Fixer::MANUAL );
		}
		return $this->make( Status::FAIL, 'https_off', $details, Fixer::MANUAL );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		if ( 'site' === ( $details['site_kind'] ?? '' ) ) {
			if ( 'https_off' === $code ) {
				return __( 'Your site address starts with http://, not https://. Many assistants and browsers treat that as unsafe. Ask your host to turn on a free SSL certificate.', 'x7velo' );
			}
			return __( 'Your site address uses https://.', 'x7velo' );
		}
		if ( 'https_off' === $code ) {
			return __( 'Your store address starts with http://, not https://. Many assistants and browsers treat that as unsafe. Ask your host to turn on a free SSL certificate.', 'x7velo' );
		}
		return __( 'Your store address uses https://.', 'x7velo' );
	}
}
