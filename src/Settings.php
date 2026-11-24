<?php
/**
 * Plugin settings.
 *
 * @package X7velo
 */

namespace X7velo;

use WP_Error;
use X7velo\Data\AiCrawlers;

defined( 'ABSPATH' ) || exit;

/**
 * Small, flat, autoloaded settings array in option `x7velo_settings` (owner intent only;
 * nothing derived at runtime is stored, and every fixer defaults to off).
 */
final class Settings {

	public const OPTION = 'x7velo_settings';

	/**
	 * Boolean keys.
	 *
	 * @var string[]
	 */
	private const BOOL_KEYS = array(
		'own_brand_products',
		'fix_jsonld',
		'fix_llms_txt',
		'fix_feed',
		'fix_robots_search',
		'fix_robots_block_training',
		'block_google_extended',
	);

	/**
	 * Allowed return_policy values ('' = not answered).
	 *
	 * @var string[]
	 */
	public const RETURN_POLICIES = array( '', 'finite', 'unlimited', 'none' );

	public const MAX_RETURN_DAYS   = 365;
	public const MAX_DELIVERY_DAYS = 60;

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'returns_page_id'           => 0,
			'own_brand_products'        => false,
			'fix_jsonld'                => false,
			'fix_llms_txt'              => false,
			'fix_feed'                  => false,
			'fix_robots_search'         => false,
			'fix_robots_block_training' => false,
			'block_google_extended'     => false,
			'block_experimental'        => array(),
			'return_policy'             => '',
			'return_days'               => null,
			'delivery_days_min'         => null,
			'delivery_days_max'         => null,
		);
	}

	/**
	 * JSON schema of every key (REST args; unknown keys are rejected by the controller).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		$schema = array(
			'returns_page_id'    => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Published page with the returns policy (0 = none).', 'x7velo' ),
			),
			'block_experimental' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'maxItems'    => 50,
				'description' => __( 'Experimental crawler tokens to block (must be marked experimental in the bundled list).', 'x7velo' ),
			),
			'return_policy'      => array(
				'type' => 'string',
				'enum' => self::RETURN_POLICIES,
			),
			'return_days'        => array(
				'type'        => array( 'integer', 'null' ),
				'minimum'     => 1,
				'maximum'     => self::MAX_RETURN_DAYS,
				'description' => __( 'Return window in days (null = not set).', 'x7velo' ),
			),
			'delivery_days_min'  => array(
				'type'        => array( 'integer', 'null' ),
				'minimum'     => 0,
				'maximum'     => self::MAX_DELIVERY_DAYS,
				'description' => __( 'Shortest usual delivery time in days; 0 = same day (null = not set).', 'x7velo' ),
			),
			'delivery_days_max'  => array(
				'type'        => array( 'integer', 'null' ),
				'minimum'     => 0,
				'maximum'     => self::MAX_DELIVERY_DAYS,
				'description' => __( 'Longest usual delivery time in days (null = not set).', 'x7velo' ),
			),
		);
		foreach ( self::BOOL_KEYS as $key ) {
			$schema[ $key ] = array( 'type' => 'boolean' );
		}
		return $schema;
	}

	/**
	 * Validates a merged settings array strictly (write path). Returns the sanitised array, or a
	 * WP_Error (code rest_invalid_param) naming the first invalid key.
	 *
	 * @param array<string, mixed> $merged         Current settings merged with the patch.
	 * @param string|null          $crawlers_file  Crawler data file (tests); null = bundled file.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function validate( array $merged, ?string $crawlers_file = null ) {
		$page = absint( $merged['returns_page_id'] ?? 0 );
		if ( $page > 0 && ( 'page' !== get_post_type( $page ) || 'publish' !== get_post_status( $page ) ) ) {
			return self::invalid( 'returns_page_id', __( 'Choose a published page.', 'x7velo' ) );
		}

		$days = $merged['return_days'] ?? null;
		if ( null !== $days && ( ! is_int( $days ) || $days < 1 || $days > self::MAX_RETURN_DAYS ) ) {
			return self::invalid( 'return_days', __( 'Enter a return window between 1 and 365 days.', 'x7velo' ) );
		}
		if ( 'finite' === ( $merged['return_policy'] ?? '' ) && null === $days ) {
			return self::invalid( 'return_days', __( 'Enter a return window between 1 and 365 days.', 'x7velo' ) );
		}

		foreach ( array( 'delivery_days_min', 'delivery_days_max' ) as $key ) {
			$value = $merged[ $key ] ?? null;
			if ( null !== $value && ( ! is_int( $value ) || $value < 0 || $value > self::MAX_DELIVERY_DAYS ) ) {
				return self::invalid( $key, __( 'Enter a number of days between 0 and 60.', 'x7velo' ) );
			}
		}
		$min = $merged['delivery_days_min'] ?? null;
		$max = $merged['delivery_days_max'] ?? null;
		if ( null !== $min && null !== $max && $min > $max ) {
			return self::invalid( 'delivery_days_min', __( 'The shortest delivery time can’t be longer than the longest.', 'x7velo' ) );
		}

		$allowed = array_map( 'strtolower', AiCrawlers::experimental_tokens( $crawlers_file ) );
		foreach ( (array) ( $merged['block_experimental'] ?? array() ) as $token ) {
			if ( ! is_string( $token ) || ! in_array( strtolower( trim( $token ) ), $allowed, true ) ) {
				return self::invalid( 'block_experimental', __( 'Only crawlers marked experimental can be listed here.', 'x7velo' ) );
			}
		}

		return self::sanitize( $merged, $crawlers_file );
	}

	/**
	 * A 400 error for one parameter.
	 *
	 * @param string $key     Parameter.
	 * @param string $message Message.
	 */
	private static function invalid( string $key, string $message ): WP_Error {
		return new WP_Error(
			'rest_invalid_param',
			/* translators: %s: parameter name. */
			sprintf( __( 'Invalid parameter: %s', 'x7velo' ), $key ),
			array(
				'status' => 400,
				'params' => array( $key => $message ),
			)
		);
	}

	/**
	 * All settings, sanitised and merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed Null for unknown keys.
	 */
	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Updates some settings (shallow merge; lists are replaced whole). Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $values New values.
	 */
	public function update( array $values ): void {
		update_option( self::OPTION, self::sanitize( array_merge( $this->all(), $values ) ), true );
	}

	/**
	 * Writes the defaults if the option does not exist yet (activation and upgrade).
	 */
	public static function add_defaults(): void {
		add_option( self::OPTION, self::defaults(), '', true );
	}

	/**
	 * Sanitises a settings array to the known keys and types.
	 *
	 * `block_experimental` keeps only tokens of entries marked experimental in
	 * data/ai-crawlers.json (case-insensitive match, stored as written in the file, de-duplicated),
	 * so a bot removed from the file stops being used without a migration.
	 *
	 * @param array<string, mixed> $values         Raw values.
	 * @param string|null          $crawlers_file  Crawler data file (tests); null = bundled file.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $values, ?string $crawlers_file = null ): array {
		$values = array_merge( self::defaults(), $values );
		$clean  = array( 'returns_page_id' => absint( $values['returns_page_id'] ) );

		foreach ( self::BOOL_KEYS as $key ) {
			$clean[ $key ] = rest_sanitize_boolean( $values[ $key ] );
		}

		$allowed = array();
		foreach ( AiCrawlers::experimental_tokens( $crawlers_file ) as $token ) {
			$allowed[ strtolower( $token ) ] = $token;
		}
		$blocked = array();
		foreach ( (array) $values['block_experimental'] as $token ) {
			$key = is_string( $token ) ? strtolower( trim( $token ) ) : '';
			if ( isset( $allowed[ $key ] ) ) {
				$blocked[ $key ] = $allowed[ $key ];
			}
		}
		$clean['block_experimental'] = array_values( $blocked );

		$clean['return_policy']     = in_array( $values['return_policy'], self::RETURN_POLICIES, true ) ? $values['return_policy'] : '';
		$clean['return_days']       = self::nullable_int( $values['return_days'], 1, self::MAX_RETURN_DAYS );
		$clean['delivery_days_min'] = self::nullable_int( $values['delivery_days_min'], 0, self::MAX_DELIVERY_DAYS );
		$clean['delivery_days_max'] = self::nullable_int( $values['delivery_days_max'], 0, self::MAX_DELIVERY_DAYS );

		return $clean;
	}

	/**
	 * Null ("not set") or an integer clamped to [$min, $max]. Non-numeric values become null.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 */
	private static function nullable_int( $value, int $min, int $max ): ?int {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return max( $min, min( $max, (int) $value ) );
	}
}
