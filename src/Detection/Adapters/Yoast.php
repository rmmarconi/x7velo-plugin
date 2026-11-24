<?php
/**
 * Yoast SEO (free) adapter.
 *
 * @package X7velo
 */

namespace X7velo\Detection\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast SEO free (verified on 28.9, see docs/research/seo-coexistence-matrix.md).
 *
 * Emits no Product (Woo core's stays). llms.txt is a physical file it owns when its md5 equals
 * `wpseo_llms_txt_content_hash`. Its robots.txt block is added on the front end only, so it is
 * reconstructed here for admin-side evaluation.
 */
final class Yoast extends BaseAdapter {

	public const ID = 'yoast';

	/**
	 * Marker line of Yoast's robots.txt block.
	 */
	public const ROBOTS_MARKER = '# START YOAST BLOCK';

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
		return 'Yoast SEO';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return $this->env->defined( 'WPSEO_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function emits_organization(): bool {
		$wpseo = $this->array_option( 'wpseo' );
		if ( false === ( $wpseo['enable_schema'] ?? true ) ) {
			return false;
		}
		$titles = $this->array_option( 'wpseo_titles' );
		$type   = $titles['company_or_person'] ?? '';
		if ( 'company' === $type ) {
			return '' !== (string) ( $titles['company_name'] ?? '' )
				&& ( ! empty( $titles['company_logo_id'] ) || ! empty( $titles['company_logo'] ) );
		}
		return 'person' === $type && ! empty( $titles['company_or_person_user_id'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function llms_source(): ?array {
		$wpseo = $this->array_option( 'wpseo' );
		if ( true !== ( $wpseo['enable_llms_txt'] ?? false ) ) {
			return null;
		}
		return array(
			'kind' => 'physical',
			'path' => $this->env->root_path() . 'llms.txt',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path Absolute path of an existing llms.txt.
	 */
	public function owns_llms_file( string $path ): bool {
		$hash = (string) $this->env->option( 'wpseo_llms_txt_content_hash', '' );
		return '' !== $hash && $this->env->file_md5( $path ) === $hash;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sitemap(): ?array {
		$wpseo = $this->array_option( 'wpseo' );
		if ( false === (bool) ( $wpseo['enable_xml_sitemap'] ?? true ) ) {
			return null;
		}
		$titles = $this->array_option( 'wpseo_titles' );
		return array(
			'path'              => '/sitemap_index.xml',
			'includes_products' => true !== ( $titles['noindex-product'] ?? false ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 */
	public function sitemap_includes( string $post_type ): bool {
		$titles = $this->array_option( 'wpseo_titles' );
		return true !== ( $titles[ 'noindex-' . $post_type ] ?? false );
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
	 * Per post `_yoast_wpseo_meta-robots-noindex`: '1' noindex, '2' index; otherwise (default)
	 * the post type's "Show in search results" setting (`wpseo_titles['noindex-<type>']`).
	 *
	 * @param int $id Post ID.
	 */
	public function is_post_noindex( int $id ): bool {
		$value = (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === $value ) {
			return true;
		}
		if ( '2' === $value ) {
			return false;
		}
		$type = (string) get_post_type( $id );
		return '' !== $type && ! $this->sitemap_includes( $type );
	}

	/**
	 * {@inheritDoc}
	 */
	public function robots(): ?array {
		// Mirrors Robots_Txt_Integration::filter_robots() + Robots_Txt_Presenter (Yoast 28.9).
		$wpseo = $this->array_option( 'wpseo' );
		$star  = array();
		$other = array();
		if ( ! is_multisite() ) {
			if ( ! empty( $wpseo['deny_search_crawling'] ) ) {
				array_push( $star, '/?s=', '/page/*/?s=', '/search/' );
			}
			if ( ! empty( $wpseo['deny_wp_json_crawling'] ) ) {
				array_push( $star, '/wp-json/', '/?rest_route=' );
			}
			if ( ! empty( $wpseo['deny_adsbot_crawling'] ) ) {
				$other['AdsBot'] = array( '/' );
			}
		}

		$lines = array( self::ROBOTS_MARKER, '# ---------------------------' );
		if ( array() === $star ) {
			array_push( $lines, 'User-agent: *', 'Disallow:', '' );
		}
		$groups = array() === $star ? $other : array_merge( array( '*' => $star ), $other );
		foreach ( $groups as $agent => $paths ) {
			$lines[] = 'User-agent: ' . $agent;
			foreach ( $paths as $path ) {
				$lines[] = 'Disallow: ' . $path;
			}
			$lines[] = '';
		}
		if ( null !== $this->sitemap() ) {
			$lines[] = 'Sitemap: ' . home_url( '/sitemap_index.xml' );
		}
		$lines[] = '# ---------------------------';
		$lines[] = '# END YOAST BLOCK';

		return array(
			'mode'    => 'append',
			'content' => implode( "\n", $lines ),
			'marker'  => self::ROBOTS_MARKER,
			'strip'   => '`User-agent: \*[\r\n]+Disallow: /wp-admin/[\r\n]+Allow: /wp-admin/admin-ajax\.php[\r\n]+`',
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
				return 'admin.php?page=wpseo_page_settings#/site-features';
			case 'sitemap_products':
				return 'admin.php?page=wpseo_page_settings#/post-type/products';
			case 'llms':
				return 'admin.php?page=wpseo_page_settings#/llms-txt';
			case 'schema':
				return 'admin.php?page=wpseo_page_settings#/site-representation';
			case 'robots':
				return 'admin.php?page=wpseo_page_settings#/crawl-optimization';
		}
		return '';
	}
}
