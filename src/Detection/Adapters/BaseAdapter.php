<?php
/**
 * Shared adapter defaults.
 *
 * @package X7velo
 */

namespace X7velo\Detection\Adapters;

use X7velo\Detection\Adapter;
use X7velo\Detection\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults: emits nothing, owns nothing. Subclasses override what their plugin does.
 */
abstract class BaseAdapter implements Adapter {

	/**
	 * Signal source.
	 *
	 * @var Environment
	 */
	protected Environment $env;

	/**
	 * Constructor.
	 *
	 * @param Environment|null $env Signal source (tests pass a fake).
	 */
	public function __construct( ?Environment $env = null ) {
		$this->env = $env ?? new Environment();
	}

	/**
	 * {@inheritDoc}
	 */
	public function emits_product(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function removes_woo_product(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function emits_organization(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function llms_source(): ?array {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path Absolute path of an existing llms.txt.
	 */
	public function owns_llms_file( string $path ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Interface default.
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sitemap(): ?array {
		return null;
	}

	/**
	 * Whether this plugin's sitemap includes a non-product post type (`post` / `page`, site mode,
	 * spec §15.2). Only asked when sitemap() isn't null. Default: included.
	 *
	 * @param string $post_type Post type.
	 */
	public function sitemap_includes( string $post_type ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Default.
		return true;
	}

	/**
	 * Loads whatever is_post_noindex() needs for these posts in as few queries as possible (post
	 * meta cache, or the plugin's own table), so a list of posts costs no N+1 queries. Default:
	 * nothing to load.
	 *
	 * @param int[] $ids Post IDs.
	 */
	public function prime_noindex( array $ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Default.
	}

	/**
	 * Whether this plugin marks the post `noindex` (its own per-post setting, else its post-type
	 * default). Used to keep noindex content out of x7velo's llms.txt. Default: not noindex.
	 *
	 * Unlike the other methods this reads per-post data (post meta or the plugin's own table),
	 * not only options; call prime_noindex() first for a list of posts.
	 *
	 * @param int $id Post ID.
	 */
	public function is_post_noindex( int $id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Default.
		return false;
	}

	/**
	 * Primes the post meta cache for these posts (one query for all not yet cached).
	 *
	 * @param int[] $ids Post IDs.
	 */
	protected function prime_post_meta( array $ids ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() !== $ids ) {
			update_meta_cache( 'post', $ids );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function robots(): ?array {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $area Area.
	 */
	public function settings_path( string $area ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Interface default.
		return '';
	}

	/**
	 * Array option (non-arrays read as empty).
	 *
	 * @param string $name Option name.
	 * @return array<mixed>
	 */
	protected function array_option( string $name ): array {
		$value = $this->env->option( $name, array() );
		return is_array( $value ) ? $value : array();
	}
}
