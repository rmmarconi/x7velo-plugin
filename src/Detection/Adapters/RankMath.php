<?php
/**
 * Rank Math SEO (free) adapter.
 *
 * @package X7velo
 */

namespace X7velo\Detection\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Rank Math free (verified on 1.0.282). Treated as absent unless its registration is valid
 * (connected or skipped): an unregistered install is inert on the front end.
 *
 * With the `rich-snippet` module on and products defaulting to the Product snippet it removes
 * Woo core's JSON-LD on product pages and emits its own Product. Its robots.txt editor content
 * replaces the whole output on the front end only (when the site is public).
 */
final class RankMath extends BaseAdapter {

	public const ID = 'rank-math';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Rank Math';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return $this->env->defined( 'RANK_MATH_VERSION' ) && ! $this->env->rank_math_registration_invalid();
	}

	/**
	 * Enabled module IDs.
	 *
	 * @return string[]
	 */
	public function modules(): array {
		return array_values( array_filter( $this->array_option( 'rank_math_modules' ), 'is_string' ) );
	}

	/**
	 * Whether a module is enabled.
	 *
	 * @param string $module Module ID.
	 */
	private function module( string $module ): bool {
		return in_array( $module, $this->modules(), true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function emits_product(): bool {
		$titles = $this->array_option( 'rank-math-options-titles' );
		return $this->module( 'rich-snippet' ) && 'product' === ( $titles['pt_product_default_rich_snippet'] ?? '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function removes_woo_product(): bool {
		return $this->emits_product();
	}

	/**
	 * {@inheritDoc}
	 */
	public function emits_organization(): bool {
		return $this->module( 'rich-snippet' );
	}

	/**
	 * Taxonomy Rank Math reads the Product brand from ('' = unset, so no brand in its schema).
	 */
	public function product_brand_taxonomy(): string {
		$general = $this->array_option( 'rank-math-options-general' );
		return is_string( $general['product_brand'] ?? null ) ? $general['product_brand'] : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function llms_source(): ?array {
		return $this->module( 'llms-txt' ) ? array( 'kind' => 'dynamic' ) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sitemap(): ?array {
		if ( ! $this->module( 'sitemap' ) ) {
			return null;
		}
		$sitemap = $this->array_option( 'rank-math-options-sitemap' );
		$titles  = $this->array_option( 'rank-math-options-titles' );
		return array(
			'path'              => '/sitemap_index.xml',
			'includes_products' => 'on' === ( $sitemap['pt_product_sitemap'] ?? '' )
				&& ! in_array( 'noindex', (array) ( $titles['pt_product_robots'] ?? array() ), true ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 */
	public function sitemap_includes( string $post_type ): bool {
		$sitemap = $this->array_option( 'rank-math-options-sitemap' );
		$titles  = $this->array_option( 'rank-math-options-titles' );
		return 'on' === ( $sitemap[ 'pt_' . $post_type . '_sitemap' ] ?? '' )
			&& ! in_array( 'noindex', (array) ( $titles[ 'pt_' . $post_type . '_robots' ] ?? array() ), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $ids Post IDs.
	 */
	public function prime_noindex( array $ids ): void {
		$this->prime_post_meta( $ids );
	}

	/**
	 * Per post `rank_math_robots` (array) when set; otherwise the post type's robots
	 * (`pt_<type>_robots`) and the global robots (`robots_global`). Any `noindex` wins, so a
	 * stale post-type value errs on the side of leaving the post out.
	 *
	 * @param int $id Post ID.
	 */
	public function is_post_noindex( int $id ): bool {
		$meta = get_post_meta( $id, 'rank_math_robots', true );
		if ( is_array( $meta ) && array() !== $meta ) {
			return in_array( 'noindex', $meta, true );
		}
		$titles = $this->array_option( 'rank-math-options-titles' );
		$type   = (string) get_post_type( $id );
		return ( '' !== $type && in_array( 'noindex', (array) ( $titles[ 'pt_' . $type . '_robots' ] ?? array() ), true ) )
			|| in_array( 'noindex', (array) ( $titles['robots_global'] ?? array() ), true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function robots(): ?array {
		$general = $this->array_option( 'rank-math-options-general' );
		$content = is_string( $general['robots_txt_content'] ?? null ) ? $general['robots_txt_content'] : '';
		// Rank Math\Robots_Txt::robots_txt() leaves the output alone when the site is not public.
		if ( '' === trim( $content ) || '0' === (string) $this->env->option( 'blog_public', '1' ) ) {
			return null;
		}
		return array(
			'mode'    => 'replace',
			'content' => $content,
			'marker'  => '',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $area Area.
	 */
	public function settings_path( string $area ): string {
		switch ( $area ) {
			case 'sitemap':
			case 'sitemap_products':
				return 'admin.php?page=rank-math-options-sitemap';
			case 'schema':
				return 'admin.php?page=rank-math-options-titles';
			case 'brand':
			case 'llms':
			case 'robots':
				return 'admin.php?page=rank-math-options-general';
		}
		return '';
	}
}
