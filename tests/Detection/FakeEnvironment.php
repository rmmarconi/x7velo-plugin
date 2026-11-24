<?php
/**
 * Test double for Detection\Environment.
 *
 * @package X7velo
 */

namespace X7velo\Tests\Detection;

use X7velo\Detection\Environment;

/**
 * Simulates SEO plugins' constants/classes/active plugins and local files in memory. The real
 * plugins can't be loaded in the unit test environment (and constants can't be undefined in one
 * process), so adapters are tested against this seam plus real option values (captured fixtures).
 */
class FakeEnvironment extends Environment {

	/**
	 * Constants, name => value.
	 *
	 * @var array<string, mixed>
	 */
	public array $constants = array();

	/**
	 * Class names that "exist".
	 *
	 * @var string[]
	 */
	public array $classes = array( 'WooCommerce' );

	/**
	 * Files, absolute path => contents.
	 *
	 * @var array<string, string>
	 */
	public array $files = array();

	/**
	 * Active plugin basenames.
	 *
	 * @var string[]
	 */
	public array $plugins = array();

	/**
	 * Rank Math registration invalid override (null = derive from constants/options).
	 *
	 * @var bool|null
	 */
	public ?bool $rank_math_invalid = null;

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Name.
	 */
	public function defined( string $name ): bool {
		return array_key_exists( $name, $this->constants );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Name.
	 */
	public function constant( string $name ) {
		return $this->constants[ $name ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name Name.
	 */
	public function class_exists( string $name ): bool {
		return in_array( $name, $this->classes, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $basename Basename.
	 */
	public function plugin_active( string $basename ): bool {
		return in_array( $basename, $this->plugins, true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function active_plugins(): array {
		$plugins = $this->plugins;
		sort( $plugins );
		return $plugins;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path Path.
	 */
	public function file_exists( string $path ): bool {
		return isset( $this->files[ $path ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path      Path.
	 * @param int    $max_bytes Max bytes.
	 */
	public function read_file( string $path, int $max_bytes = self::MAX_READ_BYTES ): ?string {
		return isset( $this->files[ $path ] ) ? substr( $this->files[ $path ], 0, $max_bytes ) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path Path.
	 */
	public function file_md5( string $path ): ?string {
		return isset( $this->files[ $path ] ) ? md5( $this->files[ $path ] ) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function root_path(): string {
		return '/site/';
	}

	/**
	 * {@inheritDoc}
	 */
	public function content_parent_path(): string {
		return '/site/';
	}

	/**
	 * {@inheritDoc}
	 */
	public function rank_math_registration_invalid(): bool {
		return $this->rank_math_invalid ?? parent::rank_math_registration_invalid();
	}

	/**
	 * Simulates an SEO plugin being active (constant + basename).
	 *
	 * @param string $plugin yoast | rank-math | aioseo | seopress | yoast-woo.
	 * @return $this
	 */
	public function with( string $plugin ): self {
		$map                                   = array(
			'yoast'     => array( 'WPSEO_VERSION', '28.9', 'wordpress-seo/wp-seo.php' ),
			'rank-math' => array( 'RANK_MATH_VERSION', '1.0.282', 'seo-by-rank-math/rank-math.php' ),
			'aioseo'    => array( 'AIOSEO_VERSION', '5.0.5.1', 'all-in-one-seo-pack/all_in_one_seo_pack.php' ),
			'seopress'  => array( 'SEOPRESS_VERSION', '10.4', 'wp-seopress/seopress.php' ),
			'yoast-woo' => array( 'WPSEO_WOO_VERSION', '16.0', 'wpseo-woocommerce/wpseo-woocommerce.php' ),
		);
		list( $constant, $version, $basename ) = $map[ $plugin ];
		$this->constants[ $constant ]          = $version;
		$this->plugins[]                       = $basename;
		return $this;
	}
}
