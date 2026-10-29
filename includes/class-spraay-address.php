<?php
/**
 * EVM address validation (EIP-55 checksums) with a pure-PHP Keccak-256.
 *
 * PHP's hash( 'sha3-256' ) is the NIST SHA-3 variant, which pads differently
 * from the Keccak-256 used by Ethereum, so it cannot be used for checksums.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers for EVM addresses.
 */
class Spraay_Address {

	/**
	 * Keccak-f[1600] round constants as [high 32 bits, low 32 bits].
	 *
	 * Stored as halves because several constants exceed PHP_INT_MAX and would
	 * otherwise be parsed as floats.
	 *
	 * @var int[][]
	 */
	private static $round_constants = array(
		array( 0x00000000, 0x00000001 ), array( 0x00000000, 0x00008082 ),
		array( 0x80000000, 0x0000808A ), array( 0x80000000, 0x80008000 ),
		array( 0x00000000, 0x0000808B ), array( 0x00000000, 0x80000001 ),
		array( 0x80000000, 0x80008081 ), array( 0x80000000, 0x00008009 ),
		array( 0x00000000, 0x0000008A ), array( 0x00000000, 0x00000088 ),
		array( 0x00000000, 0x80008009 ), array( 0x00000000, 0x8000000A ),
		array( 0x00000000, 0x8000808B ), array( 0x80000000, 0x0000008B ),
		array( 0x80000000, 0x00008089 ), array( 0x80000000, 0x00008003 ),
		array( 0x80000000, 0x00008002 ), array( 0x80000000, 0x00000080 ),
		array( 0x00000000, 0x0000800A ), array( 0x80000000, 0x8000000A ),
		array( 0x80000000, 0x80008081 ), array( 0x80000000, 0x00008080 ),
		array( 0x00000000, 0x80000001 ), array( 0x80000000, 0x80008008 ),
	);

	/**
	 * Rotation offsets indexed by lane (x + 5y).
	 *
	 * @var int[]
	 */
	private static $rotations = array( 0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39, 41, 45, 15, 21, 8, 18, 2, 61, 56, 14 );

	/**
	 * Whether a string is a valid EVM address.
	 *
	 * All-lowercase and all-uppercase addresses carry no checksum and are
	 * accepted; mixed-case addresses must match their EIP-55 checksum.
	 *
	 * @param string $address Candidate address.
	 * @return bool
	 */
	public static function is_valid( $address ) {
		if ( ! is_string( $address ) || ! preg_match( '/^0x[0-9a-fA-F]{40}$/', $address ) ) {
			return false;
		}

		$hex = substr( $address, 2 );
		if ( strtolower( $hex ) === $hex || strtoupper( $hex ) === $hex ) {
			return true;
		}

		return self::to_checksum( $address ) === $address;
	}

	/**
	 * Convert an address to its EIP-55 checksummed form.
	 *
	 * @param string $address 0x-prefixed 40 hex character address.
	 * @return string Checksummed address, or empty string if malformed.
	 */
	public static function to_checksum( $address ) {
		if ( ! is_string( $address ) || ! preg_match( '/^0x[0-9a-fA-F]{40}$/', $address ) ) {
			return '';
		}

		$lower = strtolower( substr( $address, 2 ) );
		$hash  = self::keccak256( $lower );
		$out   = '0x';

		for ( $i = 0; $i < 40; $i++ ) {
			$char = $lower[ $i ];
			$out .= ( ctype_alpha( $char ) && hexdec( $hash[ $i ] ) >= 8 ) ? strtoupper( $char ) : $char;
		}

		return $out;
	}

	/**
	 * Keccak-256 hash (Ethereum variant) of a binary string.
	 *
	 * Requires 64-bit PHP.
	 *
	 * @param string $message Input bytes.
	 * @return string Lowercase hex digest.
	 */
	public static function keccak256( $message ) {
		$rate = 136;

		// Keccak padding: 0x01 ... 0x80 (SHA-3 would use 0x06).
		$message .= "\x01";
		$pad_len  = $rate - ( strlen( $message ) % $rate );
		if ( $pad_len < $rate ) {
			$message .= str_repeat( "\x00", $pad_len );
		}
		$message[ strlen( $message ) - 1 ] = chr( ord( $message[ strlen( $message ) - 1 ] ) | 0x80 );

		$state = array_fill( 0, 25, 0 );
		$total = strlen( $message );

		for ( $offset = 0; $offset < $total; $offset += $rate ) {
			for ( $i = 0; $i < $rate / 8; $i++ ) {
				$words       = unpack( 'V2', substr( $message, $offset + $i * 8, 8 ) );
				$state[ $i ] ^= ( $words[2] << 32 ) | $words[1];
			}
			self::keccak_f( $state );
		}

		$digest = '';
		for ( $i = 0; $i < 4; $i++ ) {
			$digest .= pack( 'V2', $state[ $i ] & 0xFFFFFFFF, ( $state[ $i ] >> 32 ) & 0xFFFFFFFF );
		}

		return bin2hex( $digest );
	}

	/**
	 * Keccak-f[1600] permutation over 25 signed 64-bit lanes.
	 *
	 * @param int[] $a State, modified in place.
	 */
	private static function keccak_f( array &$a ) {
		$b = array_fill( 0, 25, 0 );
		$c = array_fill( 0, 5, 0 );

		for ( $round = 0; $round < 24; $round++ ) {
			// Theta.
			for ( $x = 0; $x < 5; $x++ ) {
				$c[ $x ] = $a[ $x ] ^ $a[ $x + 5 ] ^ $a[ $x + 10 ] ^ $a[ $x + 15 ] ^ $a[ $x + 20 ];
			}
			for ( $x = 0; $x < 5; $x++ ) {
				$d = $c[ ( $x + 4 ) % 5 ] ^ self::rotl( $c[ ( $x + 1 ) % 5 ], 1 );
				for ( $y = 0; $y < 25; $y += 5 ) {
					$a[ $x + $y ] ^= $d;
				}
			}

			// Rho and pi.
			for ( $x = 0; $x < 5; $x++ ) {
				for ( $y = 0; $y < 5; $y++ ) {
					$b[ $y + 5 * ( ( 2 * $x + 3 * $y ) % 5 ) ] = self::rotl( $a[ $x + 5 * $y ], self::$rotations[ $x + 5 * $y ] );
				}
			}

			// Chi.
			for ( $y = 0; $y < 25; $y += 5 ) {
				for ( $x = 0; $x < 5; $x++ ) {
					$a[ $x + $y ] = $b[ $x + $y ] ^ ( ~$b[ ( $x + 1 ) % 5 + $y ] & $b[ ( $x + 2 ) % 5 + $y ] );
				}
			}

			// Iota.
			$a[0] ^= ( self::$round_constants[ $round ][0] << 32 ) | self::$round_constants[ $round ][1];
		}
	}

	/**
	 * Rotate a 64-bit lane left, compensating for PHP's arithmetic right shift.
	 *
	 * @param int $value Lane.
	 * @param int $n     Bits, 0-63.
	 * @return int
	 */
	private static function rotl( $value, $n ) {
		if ( 0 === $n ) {
			return $value;
		}
		return ( $value << $n ) | ( ( $value >> ( 64 - $n ) ) & ( ( 1 << $n ) - 1 ) );
	}
}
