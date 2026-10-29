<?php
/**
 * Exact decimal math for token amounts.
 *
 * Amounts are handled as strings or integer base units (USDC has 6 decimals),
 * never floats, so totals always reconcile with what is sent on-chain.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static amount helpers.
 */
class Spraay_Amount {

	/**
	 * Decimal places stored in the database and used by USDC.
	 */
	const DECIMALS = 6;

	/**
	 * Spraay protocol fee in basis points (0.3%).
	 */
	const FEE_BPS = 30;

	/**
	 * Normalize user input to a fixed 6-decimal string.
	 *
	 * @param mixed $value Raw input such as "12", "12.5", "1,000.25".
	 * @return string|false Normalized string, or false if not a non-negative
	 *                      number with at most 6 decimal places.
	 */
	public static function normalize( $value ) {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}

		$value = str_replace( array( ',', ' ' ), '', trim( $value ) );
		if ( ! preg_match( '/^(\d{1,12})(?:\.(\d{0,' . self::DECIMALS . '}))?$/', $value, $m ) ) {
			return false;
		}

		$whole    = ltrim( $m[1], '0' );
		$fraction = isset( $m[2] ) ? $m[2] : '';
		return ( '' === $whole ? '0' : $whole ) . '.' . str_pad( $fraction, self::DECIMALS, '0' );
	}

	/**
	 * Convert a decimal string to integer base units.
	 *
	 * @param string $value Decimal amount.
	 * @return int Base units, 0 if invalid.
	 */
	public static function to_units( $value ) {
		$normalized = self::normalize( (string) $value );
		if ( false === $normalized ) {
			return 0;
		}
		list( $whole, $fraction ) = explode( '.', $normalized );
		return (int) $whole * ( 10 ** self::DECIMALS ) + (int) $fraction;
	}

	/**
	 * Convert integer base units to a 6-decimal string.
	 *
	 * @param int $units Base units.
	 * @return string
	 */
	public static function from_units( $units ) {
		$units = max( 0, (int) $units );
		$scale = 10 ** self::DECIMALS;
		return intdiv( $units, $scale ) . '.' . str_pad( (string) ( $units % $scale ), self::DECIMALS, '0', STR_PAD_LEFT );
	}

	/**
	 * Local fee preview in base units, rounded up. The gateway estimate is
	 * authoritative; this is only shown before an estimate exists.
	 *
	 * @param int $units Payout total in base units.
	 * @return int
	 */
	public static function fee_units( $units ) {
		return intdiv( (int) $units * self::FEE_BPS + 9999, 10000 );
	}

	/**
	 * Human-readable amount, trimmed to 2-6 decimals.
	 *
	 * @param string $value Decimal amount.
	 * @return string
	 */
	public static function format( $value ) {
		$normalized = self::normalize( (string) $value );
		if ( false === $normalized ) {
			return '0.00';
		}
		list( $whole, $fraction ) = explode( '.', $normalized );
		$fraction = rtrim( $fraction, '0' );
		$fraction = str_pad( $fraction, 2, '0' );
		return number_format_i18n( (int) $whole ) . self::decimal_point() . $fraction;
	}

	/**
	 * Locale decimal point.
	 *
	 * @return string
	 */
	private static function decimal_point() {
		global $wp_locale;
		return ( $wp_locale && isset( $wp_locale->number_format['decimal_point'] ) ) ? $wp_locale->number_format['decimal_point'] : '.';
	}
}
