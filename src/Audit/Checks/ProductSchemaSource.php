<?php
/**
 * SCHEMA-01 single-product-schema-source.
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
use X7velo\Detection\Adapters\RankMath;
use X7velo\Detection\Detector;
use X7velo\Detection\SchemaSample;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Exactly one effective Product JSON-LD emitter (spec §5 SCHEMA-01, §7.2, §7.3).
 *
 * Decides from adapter options plus the cached front-end runtime sample; never reads hook
 * state itself (in wp-admin/CLI Woo's output always looks hooked).
 */
final class ProductSchemaSource implements Check, MessageRenderer {
	use BuildsResults;

	/**
	 * Detector.
	 *
	 * @var Detector
	 */
	private Detector $detector;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Detector $detector Detector.
	 * @param Settings $settings Settings.
	 */
	public function __construct( Detector $detector, Settings $settings ) {
		$this->detector = $detector;
		$this->settings = $settings;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'SCHEMA-01';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::SCHEMA;
	}

	/**
	 * {@inheritDoc}
	 */
	public function weight(): int {
		return 10;
	}

	/**
	 * Decision shared by the check and the JSON-LD fixer status (spec §7.3).
	 *
	 * @param Detector $detector   Detector.
	 * @param bool     $fix_jsonld Whether x7velo's JSON-LD toggle is on.
	 * @return array{outcome: string, source: string, label: string, path: string, runtime: string}
	 *         outcome: woocommerce | managed | duplicate | unverified | x7velo | none.
	 */
	public static function decide( Detector $detector, bool $fix_jsonld ): array {
		$emitters = $detector->product_emitters();
		$sample   = SchemaSample::read( $detector->schema_fingerprint() );
		$runtime  = null === $sample ? 'none' : ( $sample['woo_hooked'] ? 'woo_hooked' : 'woo_removed' );
		$base     = array(
			'source'  => 'woocommerce',
			'label'   => 'WooCommerce',
			'path'    => '',
			'runtime' => $runtime,
		);

		// Duplicate: Woo still prints at render and a non-Rank-Math emitter claims Product.
		if ( 'woo_hooked' === $runtime ) {
			foreach ( $emitters as $adapter ) {
				if ( RankMath::ID !== $adapter->id() ) {
					return array_merge(
						$base,
						array(
							'outcome' => 'duplicate',
							'source'  => $adapter->id(),
							'label'   => $adapter->label(),
							'path'    => $adapter->settings_path( 'schema' ),
						)
					);
				}
			}
		}

		if ( array() !== $emitters ) {
			$adapter = $detector->woo_product_removed_by() ?? $emitters[0];
			return array_merge(
				$base,
				array(
					'outcome' => 'managed',
					'source'  => $adapter->id(),
					'label'   => $adapter->label(),
					'path'    => $adapter->settings_path( 'schema' ),
				)
			);
		}

		if ( 'woo_removed' !== $runtime ) {
			return array_merge( $base, array( 'outcome' => 'woocommerce' ) );
		}

		$known = $detector->known_schema_plugins_active();
		if ( array() !== $known ) {
			return array_merge(
				$base,
				array(
					'outcome' => 'unverified',
					'source'  => $known[0],
					'label'   => $known[0],
				)
			);
		}

		return array_merge(
			$base,
			$fix_jsonld
				? array(
					'outcome' => 'x7velo',
					'source'  => 'x7velo',
					'label'   => 'x7velo',
				)
				: array(
					'outcome' => 'none',
					'source'  => '',
					'label'   => '',
				)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function run(): CheckResult {
		$d = self::decide( $this->detector, true === $this->settings->get( 'fix_jsonld' ) );

		switch ( $d['outcome'] ) {
			case 'duplicate':
				return $this->make(
					Status::WARN,
					'duplicate_product_schema',
					array(
						'source'       => $d['source'],
						'source_label' => $d['label'],
						'runtime'      => $d['runtime'],
						'jsonld_mode'  => 'managed',
					),
					Fixer::GUIDED,
					$d['path']
				);

			case 'managed':
				$details   = array(
					'source'       => $d['source'],
					'source_label' => $d['label'],
					'runtime'      => $d['runtime'],
					'jsonld_mode'  => 'managed',
				);
				$rank_math = $this->detector->get( RankMath::ID );
				if ( RankMath::ID === $d['source'] && $rank_math instanceof RankMath && '' === $rank_math->product_brand_taxonomy() && self::brand_terms_in_use() ) {
					$details['brand_hint'] = true;
					return $this->make( Status::PASS, 'managed_by_rank_math_brand_hint', $details, Fixer::GUIDED, $rank_math->settings_path( 'brand' ) );
				}
				return $this->make( Status::PASS, 'product_schema_managed', $details, Fixer::GUIDED, $d['path'] );

			case 'unverified':
				return $this->make(
					Status::PASS,
					'managed_by_unverified_plugin',
					array(
						'source'       => $d['source'],
						'source_label' => $d['label'],
						'runtime'      => $d['runtime'],
						'jsonld_mode'  => 'managed',
					),
					Fixer::GUIDED
				);

			case 'x7velo':
				return $this->make(
					Status::PASS,
					'product_schema_x7velo',
					array(
						'source'      => 'x7velo',
						'runtime'     => $d['runtime'],
						'jsonld_mode' => 'emit',
					),
					Fixer::JSON_LD
				);

			case 'none':
				return $this->make(
					Status::FAIL,
					'no_product_schema',
					array(
						'runtime'     => $d['runtime'],
						'jsonld_mode' => 'emit',
					),
					Fixer::JSON_LD
				);
		}

		return $this->make(
			Status::PASS,
			'product_schema_woocommerce',
			array(
				'source'      => 'woocommerce',
				'runtime'     => $d['runtime'],
				'jsonld_mode' => 'augment',
			),
			Fixer::JSON_LD
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		$label = (string) ( $details['source_label'] ?? '' );
		switch ( $code ) {
			case 'duplicate_product_schema':
				/* translators: %s: plugin name, e.g. "Yoast WooCommerce SEO". */
				return sprintf( __( 'Product details are added twice, by WooCommerce and %s. Turn off product schema in one of them.', 'x7velo' ), $label );
			case 'managed_by_rank_math_brand_hint':
				return __( 'Your product details come from Rank Math. Your products have brands, but Rank Math doesn’t include them: set Rank Math → General Settings → WooCommerce → Brand to “Product brands”.', 'x7velo' );
			case 'product_schema_managed':
				/* translators: %s: plugin name, e.g. "Rank Math". */
				return sprintf( __( 'Your product pages include product details AI assistants can read (managed by %s).', 'x7velo' ), $label );
			case 'managed_by_unverified_plugin':
				/* translators: %s: plugin name, e.g. "Schema Pro". */
				return sprintf( __( 'Your product details appear to come from %s. x7velo can’t confirm its output, so it leaves product schema alone.', 'x7velo' ), $label );
			case 'product_schema_x7velo':
				return __( 'Your product pages include product details AI assistants can read (from x7velo).', 'x7velo' );
			case 'no_product_schema':
				return __( 'Your product pages don’t include product details AI assistants can read. Another plugin seems to remove WooCommerce’s product schema without adding its own.', 'x7velo' );
		}
		return __( 'Your product pages include product details AI assistants can read (from WooCommerce).', 'x7velo' );
	}

	/**
	 * Whether WooCommerce core brand terms are assigned to any product.
	 */
	private static function brand_terms_in_use(): bool {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return false;
		}
		$count = wp_count_terms(
			array(
				'taxonomy'   => 'product_brand',
				'hide_empty' => true,
			)
		);
		return is_numeric( $count ) && (int) $count > 0;
	}
}
