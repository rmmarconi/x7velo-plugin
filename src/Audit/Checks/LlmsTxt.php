<?php
/**
 * CRAWL-03 llms-txt.
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
use X7velo\Detection\Detector;
use X7velo\Plugin;
use X7velo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Exactly one distinct llms.txt source (spec §5 CRAWL-03, §7.5).
 */
final class LlmsTxt implements Check, MessageRenderer {
	use BuildsResults {
		BuildsResults::make as private build;
	}

	/**
	 * Builds a result with `site_kind` added to its details (spec §15.2).
	 *
	 * @param string               $status      Status.
	 * @param string               $code        Code.
	 * @param array<string, mixed> $details     Details.
	 * @param string               $fixer       Fixer type.
	 * @param string               $guided_path Guided admin path.
	 * @param bool                 $critical    Whether a fail caps the score.
	 */
	protected function make( string $status, string $code, array $details = array(), string $fixer = Fixer::MANUAL, string $guided_path = '', bool $critical = false ): CheckResult {
		return $this->build( $status, $code, $details + array( 'site_kind' => Plugin::site_kind() ), $fixer, $guided_path, $critical );
	}

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
		return 'CRAWL-03';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): string {
		return Category::CRAWL;
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
		$sources = $this->detector->llms_sources( true === $this->settings->get( 'fix_llms_txt' ) );

		if ( array() === $sources ) {
			$capable = $this->detector->llms_capable();
			if ( array() !== $capable ) {
				$plugin = $capable[0];
				return $this->make(
					Status::FAIL,
					'no_llms_txt_plugin_available',
					array(
						'suggested_plugin' => $plugin->id(),
						'suggested_label'  => $plugin->label(),
						'secondary_fixer'  => Fixer::LLMS_TXT,
					),
					Fixer::GUIDED,
					$plugin->settings_path( 'llms' )
				);
			}
			return $this->make( Status::FAIL, 'no_llms_txt', array(), Fixer::LLMS_TXT );
		}

		if ( 1 === count( $sources ) ) {
			$source = $sources[0];
			if ( 'x7velo' === $source['id'] ) {
				return $this->make( Status::PASS, 'llms_txt_x7velo', array( 'source' => 'x7velo' ), Fixer::LLMS_TXT );
			}
			return $this->make(
				Status::PASS,
				'llms_txt_managed',
				array(
					'source'       => $source['id'],
					'source_label' => $source['label'],
				),
				Fixer::GUIDED
			);
		}

		$served = '';
		foreach ( $sources as $source ) {
			if ( $source['served'] ) {
				$served = $source['id'];
				break;
			}
		}
		return $this->make(
			Status::WARN,
			'llms_txt_multiple',
			array(
				'sources'       => array_column( $sources, 'id' ),
				'source_labels' => array_column( $sources, 'label' ),
				'served'        => $served,
			),
			Fixer::GUIDED
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $details Details.
	 */
	public function render_message( string $code, array $details ): string {
		$site = 'site' === ( $details['site_kind'] ?? '' );
		switch ( $code ) {
			case 'no_llms_txt_plugin_available':
				/* translators: %s: plugin name, e.g. "Yoast SEO". */
				return sprintf( __( 'You don’t have an llms.txt file. %s can create one for you: turn it on in its settings. x7velo can also create one.', 'x7velo' ), (string) ( $details['suggested_label'] ?? '' ) );
			case 'no_llms_txt':
				return $site
					? __( 'You don’t have an llms.txt file, a short summary of your site that AI assistants can read.', 'x7velo' )
					: __( 'You don’t have an llms.txt file, a short summary of your store that AI assistants can read.', 'x7velo' );
			case 'llms_txt_x7velo':
				return $site
					? __( 'Your site has an llms.txt file (from x7velo).', 'x7velo' )
					: __( 'Your store has an llms.txt file (from x7velo).', 'x7velo' );
			case 'llms_txt_managed':
				if ( 'file' === ( $details['source'] ?? '' ) ) {
					return $site
						? __( 'Your site has an llms.txt file (managed by a file on your server).', 'x7velo' )
						: __( 'Your store has an llms.txt file (managed by a file on your server).', 'x7velo' );
				}
				$label = (string) ( $details['source_label'] ?? '' );
				return $site
					/* translators: %s: plugin name, e.g. "Yoast SEO". */
					? sprintf( __( 'Your site has an llms.txt file (managed by %s).', 'x7velo' ), $label )
					/* translators: %s: plugin name, e.g. "Yoast SEO". */
					: sprintf( __( 'Your store has an llms.txt file (managed by %s).', 'x7velo' ), $label );
		}
		$ids    = array_values( (array) ( $details['sources'] ?? array() ) );
		$labels = array_values( (array) ( $details['source_labels'] ?? array() ) );
		$names  = array();
		foreach ( $ids as $i => $id ) {
			$names[] = 'file' === $id ? __( 'a file on your server', 'x7velo' ) : (string) ( $labels[ $i ] ?? $id );
		}
		$list = implode( ', ', $names );
		if ( 'file' === ( $details['served'] ?? '' ) ) {
			return $site
				/* translators: %s: comma-separated sources, e.g. "Rank Math, a file on your server". */
				? sprintf( __( 'Your site has more than one llms.txt (%s). The file on your server is the one visitors get; turn the others off.', 'x7velo' ), $list )
				/* translators: %s: comma-separated sources, e.g. "Rank Math, a file on your server". */
				: sprintf( __( 'Your store has more than one llms.txt (%s). The file on your server is the one visitors get; turn the others off.', 'x7velo' ), $list );
		}
		return $site
			/* translators: %s: comma-separated sources. */
			? sprintf( __( 'Your site has more than one llms.txt (%s). Keep one and turn the others off.', 'x7velo' ), $list )
			/* translators: %s: comma-separated sources. */
			: sprintf( __( 'Your store has more than one llms.txt (%s). Keep one and turn the others off.', 'x7velo' ), $list );
	}
}
