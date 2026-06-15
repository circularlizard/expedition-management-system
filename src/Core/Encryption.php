<?php
namespace EMS\Core;

/**
 * Handles encryption and decryption of sensitive data.
 *
 * Uses AES-256-CBC with a key derived from WordPress AUTH_KEY and SECURE_AUTH_KEY.
 */
class Encryption {

    /**
     * Encrypts a string.
     *
     * @param string $data The data to encrypt.
     * @return string|false The encrypted data as a base64 encoded string, or false on failure.
     */
    public static function encrypt( string $data ): string|false {
        $key = self::get_key();
        if ( ! $key ) {
            return false;
        }

        $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
        $iv        = openssl_random_pseudo_bytes( $iv_length );
        $encrypted = openssl_encrypt( $data, 'aes-256-cbc', $key, 0, $iv );

        if ( false === $encrypted ) {
            return false;
        }

        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypts a string.
     *
     * @param string $data The base64 encoded encrypted data.
     * @return string|false The decrypted data, or false on failure.
     */
    public static function decrypt( string $data ): string|false {
        $key = self::get_key();
        if ( ! $key ) {
            return false;
        }

        $decoded = base64_decode( $data );
        if ( false === $decoded ) {
            return false;
        }

        $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
        if ( strlen( $decoded ) <= $iv_length ) {
            return false;
        }

        $iv        = substr( $decoded, 0, $iv_length );
        $encrypted = substr( $decoded, $iv_length );

        return @openssl_decrypt( $encrypted, 'aes-256-cbc', $key, 0, $iv );
    }

    /**
     * Derives an encryption key from WP constants.
     *
     * @return string|false The 32-byte key, or false if constants are missing.
     */
    private static function get_key(): string|false {
        if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
            return false;
        }

        // Use a combination of both keys and hash them to get a 32-byte key for AES-256
        return hash_hmac( 'sha256', AUTH_KEY, SECURE_AUTH_KEY, true );
    }
}
