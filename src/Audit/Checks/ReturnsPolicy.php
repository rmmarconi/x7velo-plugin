<?php
/**
 * STORE-02 returns-policy.
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
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pass = a published returns page is chosen in x7velo settings. Warn = only the WooCommerce
 * terms page exists. Fail = neither. A matching page title is suggested but never auto-selected.
 */
final class ReturnsPolicy implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * Title fragments that suggest a returns policy page (EN, PT, ES, FR, DE, NL, IT).
	 */
	private const TITLE_PATTERN = '/(return|refund|devolu[cç]|troca|reembols|rembours|retour|widerruf|r[üu]ckgabe|retourn|resi\b|reso\b)/iu';

	/**
	 * Admin path where the owner picks the page (x7velo settings screen).
	 */
	public const SETTINGS_PATH = 'admin.php?page=x7velo';

	/**
	 * Most pages considered for the suggestion, and how many are loaded per query.
	 */
	private const SUGGEST_LIMIT = 500;
	private const SUGGEST_CHUNK = 50;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'STORE-02';
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
		$returns_page = (int) $this->settings->get( 'returns_page_id' );
		if ( self::is_published_page( $returns_page ) ) {
			return $this->make(
				Status::PASS,
				'returns_page_set',
				array( 'returns_page_id' => $returns_page ),
				Fixer::GUIDED,
				self::SETTINGS_PATH
			);
		}

		$details = array( 'suggested_page_id' => self::suggest_page() );
		$terms   = function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : (int) get_option( 'woocommerce_terms_page_id', 0 );

		if ( self::is_published_page( $terms ) ) {
			$details['terms_page_id'] = $terms;
			return $this->make(
				Status::WARN,
				'only_terms_page',
				$details,
				Fixer::GUIDED,
				self::SETTINGS_PATH
			);
		}

		return $this->make(
			Status::FAIL,
			'no_returns_page',
			$details,
			Fixer::GUIDED,
			self::SETTINGS_PATH
		);
	}

	/**
	 * Whether an ID is a published page.
	 *
	 * @param int $page_id Page ID.
	 */
	private static function is_published_page( int $page_id ): bool {
		return $page_id > 0 && 'page' === get_post_type( $page_id ) && 'publish' === get_post_status( $page_id );
	}

	/**
	 * Lowest-ID published page whose title looks like a returns/refund policy, or 0.
	 *
	 * Bounded: looks at the first SUGGEST_LIMIT published pages by ID, loading them
	 * SUGGEST_CHUNK at a time (one query per chunk) and stopping at the first match.
	 * WordPress has no title-only API without SQL, so each chunk loads full page rows.
	 */
	public static function suggest_page(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => self::SUGGEST_LIMIT,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'cache_results'  => false,
			)
		);

		foreach ( array_chunk( array_map( 'intval', $ids ), self::SUGGEST_CHUNK ) as $chunk ) {
			$pages = get_posts(
				array(
					'post_type'              => 'page',
					'post_status'            => 'publish',
					'post__in'               => $chunk,
					'orderby'                => 'post__in',
					'posts_per_page'         => count( $chunk ),
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $pages as $page ) {
				if ( preg_match( self::TITLE_PATTERN, get_the_title( $page ) ) ) {
					return (int) $page->ID;
				}
			}
		}
		return 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		switch ( $code ) {
			case 'returns_page_set':
				return __( 'Your returns policy page is set.', 'x7velo' );
			case 'only_terms_page':
				return __( 'You have a terms page, but no returns policy page is selected. Shoppers and assistants look for a returns policy before recommending a store. Pick your returns page, or create one.', 'x7velo' );
			case 'no_returns_page':
				return __( 'Shoppers and assistants look for a returns policy before recommending a store. Pick your returns page, or create one.', 'x7velo' );
		}
		return '';
	}
}
