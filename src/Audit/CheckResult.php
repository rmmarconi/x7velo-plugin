<?php
/**
 * Check result value object.
 *
 * @package X7velo
 */

namespace X7velo\Audit;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable outcome of one check.
 *
 * Details may only hold counts, percentages, flags and up to MAX_EXAMPLES product IDs
 * (spec §4 "No PII"). The constructor enforces the shape and size caps (plain-text message
 * ≤ 1,000 chars; ≤ 20 detail keys; strings sanitised and ≤ 200 chars; lists ≤ 10 scalars;
 * examples ≤ 5 IDs) so a check cannot bloat or pollute the stored audit. Messages and details
 * must never contain personal data or interpolated option values (store name, email, address).
 */
final class CheckResult {

	/**
	 * Maximum number of example product IDs kept per finding.
	 */
	public const MAX_EXAMPLES = 5;

	/**
	 * Size caps that keep the stored record bounded whatever third-party checks return.
	 */
	public const MAX_MESSAGE_CHARS = 1000;
	public const MAX_DETAIL_KEYS   = 20;
	public const MAX_STRING_CHARS  = 200;
	public const MAX_LIST_ITEMS    = 10;
	public const MAX_CODE_CHARS    = 64;

	/**
	 * Status (Status constants).
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Machine-readable message code, e.g. "missing_price".
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Plain-language, translated message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Counts, percentages, flags and example IDs.
	 *
	 * @var array<string, mixed>
	 */
	private array $details;

	/**
	 * Fixer type (Fixer constants).
	 *
	 * @var string
	 */
	private string $fixer;

	/**
	 * Admin path (relative to admin_url()) for guided fixes, or ''.
	 *
	 * @var string
	 */
	private string $guided_path;

	/**
	 * Whether this result triggers the score cap (spec §8).
	 *
	 * @var bool
	 */
	private bool $critical;

	/**
	 * Constructor.
	 *
	 * @param string               $status      One of Status::all().
	 * @param string               $code        Machine-readable code ([a-z0-9_]).
	 * @param string               $message     Plain-language message (already translated).
	 * @param array<string, mixed> $details     Counts/percentages/flags; "examples" = product IDs.
	 * @param string               $fixer       One of Fixer::all().
	 * @param string               $guided_path Admin path relative to admin_url(), e.g. "options-reading.php".
	 * @param bool                 $critical    Whether the result caps the score at 20.
	 *
	 * @throws InvalidArgumentException When status or fixer is unknown.
	 */
	public function __construct(
		string $status,
		string $code,
		string $message,
		array $details = array(),
		string $fixer = Fixer::MANUAL,
		string $guided_path = '',
		bool $critical = false
	) {
		if ( ! in_array( $status, Status::all(), true ) ) {
			throw new InvalidArgumentException( 'Unknown check status.' );
		}
		if ( ! in_array( $fixer, Fixer::all(), true ) ) {
			throw new InvalidArgumentException( 'Unknown fixer type.' );
		}

		$this->status      = $status;
		$this->code        = substr( sanitize_key( $code ), 0, self::MAX_CODE_CHARS );
		$this->message     = self::truncate( wp_strip_all_tags( $message ), self::MAX_MESSAGE_CHARS );
		$this->details     = self::sanitize_details( $details );
		$this->fixer       = $fixer;
		$this->guided_path = ltrim( $guided_path, '/' );
		$this->critical    = $critical && Status::FAIL === $status;
	}

	/**
	 * Result for a check that threw.
	 */
	public static function error(): self {
		return new self(
			Status::ERROR,
			'check_error',
			__( 'We couldn’t check this. Try running the audit again.', 'x7velo' )
		);
	}

	/**
	 * Status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Message code.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Plain-language message.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Details.
	 *
	 * @return array<string, mixed>
	 */
	public function details(): array {
		return $this->details;
	}

	/**
	 * Fixer type.
	 */
	public function fixer(): string {
		return $this->fixer;
	}

	/**
	 * Guided admin path ('' if none).
	 */
	public function guided_path(): string {
		return $this->guided_path;
	}

	/**
	 * Whether the score is capped by this result.
	 */
	public function is_critical(): bool {
		return $this->critical;
	}

	/**
	 * JSON-serialisable representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'status'      => $this->status,
			'code'        => $this->code,
			'message'     => $this->message,
			'details'     => $this->details,
			'fixer'       => $this->fixer,
			'guided_path' => $this->guided_path,
			'critical'    => $this->critical,
		);
	}

	/**
	 * Keeps only scalars (or short lists of scalars), caps keys, strings and example IDs.
	 *
	 * @param array<string, mixed> $details Raw details.
	 * @return array<string, mixed>
	 */
	private static function sanitize_details( array $details ): array {
		$clean = array();
		foreach ( $details as $key => $value ) {
			if ( count( $clean ) >= self::MAX_DETAIL_KEYS ) {
				break;
			}
			$key = substr( sanitize_key( (string) $key ), 0, self::MAX_CODE_CHARS );
			if ( '' === $key || isset( $clean[ $key ] ) ) {
				continue;
			}
			if ( 'examples' === $key ) {
				$ids           = array_filter( array_map( 'absint', array_slice( (array) $value, 0, 100 ) ) );
				$clean[ $key ] = array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_EXAMPLES );
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = self::sanitize_scalar( $value );
			} elseif ( is_array( $value ) ) {
				$list          = array_slice( array_values( array_filter( $value, 'is_scalar' ) ), 0, self::MAX_LIST_ITEMS );
				$clean[ $key ] = array_map( static fn( $item ) => self::sanitize_scalar( $item ), $list );
			}
		}
		return $clean;
	}

	/**
	 * Sanitises one scalar: strings become short plain text; numbers/bools/null pass through.
	 *
	 * @param mixed $value Scalar or null.
	 * @return mixed
	 */
	private static function sanitize_scalar( $value ) {
		if ( is_string( $value ) ) {
			return self::truncate( sanitize_text_field( $value ), self::MAX_STRING_CHARS );
		}
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Truncates to a number of characters (multibyte-safe).
	 *
	 * @param string $text  Text.
	 * @param int    $chars Maximum characters.
	 */
	private static function truncate( string $text, int $chars ): string {
		return mb_strlen( $text ) > $chars ? mb_substr( $text, 0, $chars ) : $text;
	}
}
