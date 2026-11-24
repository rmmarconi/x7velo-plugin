<?php
/**
 * SEOPress (free) adapter.
 *
 * @package X7velo
 */

namespace X7velo\Detection\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * SEOPress free (verified on 10.4). Emits no Product and has no llms.txt (both Pro). It disables
 * WP core sitemaps on the front end only, so the provider is read from its options.
 */
final class Seopress extends BaseAdapter {

	public const ID = 'seopress';

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
		return 'SEOPress';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_active(): bool {
		return $this->env->defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function emits_organization(): bool {
		$social = $this->array_option( 'seopress_social_option_name' );
		$type   = $social['seopress_social_knowledge_type'] ?? '';
		return is_string( $type ) && '' !== $type && 'none' !== strtolower( $type );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sitemap(): ?array {
		$toggle  = $this->array_option( 'seopress_toggle' );
		$options = $this->array_option( 'seopress_xml_sitemap_option_name' );
		if ( '1' !== (string) ( $toggle['toggle-xml-sitemap'] ?? '' ) || '1' !== (string) ( $options['seopress_xml_sitemap_general_enable'] ?? '' ) ) {
			return null;
		}
		$types  = $options['seopress_xml_sitemap_post_types_list'] ?? array();
		$titles = $this->array_option( 'seopress_titles_option_name' );
		$single = $titles['seopress_titles_single_titles']['product'] ?? array();
		return array(
			'path'              => '/sitemaps.xml',
			'includes_products' => is_array( $types ) && '1' === (string) ( $types['product']['include'] ?? '' )
				&& ! ( is_array( $single ) && ! empty( $single['noindex'] ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 */
	public function sitemap_includes( string $post_type ): bool {
		$options = $this->array_option( 'seopress_xml_sitemap_option_name' );
		$types   = $options['seopress_xml_sitemap_post_types_list'] ?? array();
		return is_array( $types ) && '1' === (string) ( $types[ $post_type ]['include'] ?? '' );
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
	 * Per post `_seopress_robots_index` = 'yes' (SEOPress stores "noindex" that way), or the
	 * post type's noindex (`seopress_titles_single_titles[<type>]['noindex']`), or the global
	 * `seopress_titles_noindex`.
	 *
	 * @param int $id Post ID.
	 */
	public function is_post_noindex( int $id ): bool {
		if ( 'yes' === (string) get_post_meta( $id, '_seopress_robots_index', true ) ) {
			return true;
		}
		$titles = $this->array_option( 'seopress_titles_option_name' );
		if ( ! empty( $titles['seopress_titles_noindex'] ) ) {
			return true;
		}
		$type   = (string) get_post_type( $id );
		$single = '' !== $type ? ( $titles['seopress_titles_single_titles'][ $type ] ?? array() ) : array();
		return is_array( $single ) && ! empty( $single['noindex'] );
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
				return 'admin.php?page=seopress-xml-sitemap';
			case 'schema':
				return 'admin.php?page=seopress-social';
		}
		return '';
	}
}
