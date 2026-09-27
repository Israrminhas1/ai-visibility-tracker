<?php
/**
 * Encrypts secrets (the API key) at rest.
 *
 * @package AIVT
 */

namespace AIVT;

defined( 'ABSPATH' ) || exit;

/**
 * AES-256-CBC + HMAC using a key derived from the site's secret salts.
 * If the salts change, stored values can no longer be decrypted and the
 * user simply re-enters the key.
 */
class Crypto {

	const CIPHER = 'aes-256-cbc';

	/**
	 * Encrypt a string.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv     = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::key( 'enc' ), OPENSSL_RAW_DATA, $iv );
		$mac    = hash_hmac( 'sha256', $iv . $cipher, self::key( 'mac' ), true );
		return 'v1:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a string. Returns '' when it cannot be decrypted.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		if ( 0 !== strpos( $stored, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw   = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$ivlen = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $raw || strlen( $raw ) <= $ivlen + 32 ) {
			return '';
		}
		$iv     = substr( $raw, 0, $ivlen );
		$mac    = substr( $raw, $ivlen, 32 );
		$cipher = substr( $raw, $ivlen + 32 );
		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, self::key( 'mac' ), true ), $mac ) ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, self::CIPHER, self::key( 'enc' ), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Derive a sub-key from the site salts.
	 *
	 * @param string $purpose 'enc' or 'mac'.
	 * @return string Raw 32-byte key.
	 */
	private static function key( $purpose ) {
		return hash_hmac( 'sha256', 'aivt-' . $purpose, wp_salt( 'secure_auth' ), true );
	}
}
