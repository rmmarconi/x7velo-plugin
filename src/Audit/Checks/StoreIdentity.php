<?php
/**
 * STORE-01 identity.
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

defined( 'ABSPATH' ) || exit;

/**
 * Site title, store address + country, currency and a contact email.
 *
 * Fail = country or currency missing. Warn = address (or title/email) missing. Pass = all set.
 * Only presence flags are stored, never the values themselves.
 */
final class StoreIdentity implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'STORE-01';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::STORE;
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
		$country_option = (string) get_option( 'woocommerce_default_country', '' );
		$country        = strtok( $country_option, ':' );
		$email          = (string) get_option( 'woocommerce_email_from_address', '' );
		if ( ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email', '' );
		}

		$present = array(
			'has_site_title' => '' !== trim( (string) get_option( 'blogname', '' ) ),
			'has_address'    => '' !== trim( (string) get_option( 'woocommerce_store_address', '' ) )
				&& '' !== trim( (string) get_option( 'woocommerce_store_city', '' ) ),
			'has_country'    => false !== $country && '' !== trim( $country ),
			'has_currency'   => '' !== trim( (string) get_option( 'woocommerce_currency', '' ) ),
			'has_email'      => (bool) is_email( $email ),
		);
		$path    = 'admin.php?page=wc-settings&tab=general';

		if ( ! $present['has_country'] || ! $present['has_currency'] ) {
			return $this->make(
				Status::FAIL,
				'store_country_or_currency_missing',
				$present,
				Fixer::GUIDED,
				$path
			);
		}

		if ( ! $present['has_address'] ) {
			return $this->make(
				Status::WARN,
				'store_address_missing',
				$present,
				Fixer::GUIDED,
				$path
			);
		}

		if ( ! $present['has_site_title'] || ! $present['has_email'] ) {
			return $this->make(
				Status::WARN,
				'store_title_or_email_missing',
				$present,
				Fixer::GUIDED,
				$present['has_site_title'] ? 'admin.php?page=wc-settings&tab=email' : 'options-general.php'
			);
		}

		return $this->make(
			Status::PASS,
			'store_identity_complete',
			$present,
			Fixer::GUIDED,
			$path
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		switch ( $code ) {
			case 'store_country_or_currency_missing':
				return __( 'Your store has no country or currency set. Assistants trust stores that say who they are and where they ship from. Set them in WooCommerce → Settings → General.', 'x7velo' );
			case 'store_address_missing':
				return __( 'Assistants trust stores that say who they are and where they ship from. Add your store address in WooCommerce → Settings → General.', 'x7velo' );
			case 'store_title_or_email_missing':
				return __( 'Your site title or contact email is missing. Add a site title in Settings → General and a sender email in WooCommerce → Settings → Emails.', 'x7velo' );
			case 'store_identity_complete':
				return __( 'Your store name, address, country, currency and contact email are set.', 'x7velo' );
		}
		return '';
	}
}
