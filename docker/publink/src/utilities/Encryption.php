<?php
namespace Biblhertz\Publink\utilities;


/**
 * A class to handle secure encryption and decryption of arbitrary data
 *
 * Note that this is not just straight encryption.  It also has a few other
 *  features in it to make the encrypted data far more secure.  Note that any
 *  other implementations used to decrypt data will have to do the same exact
 *  operations.
 *
 * Security Benefits:
 *
 * - PBKDF2 key stretching with per-message salt
 * - Fresh random IV per encryption
 * - Encrypt-then-MAC (HMAC-SHA256) authentication
 * - Separate encryption and authentication keys derived from one master key
 *
 */
class Encryption {

    /**
     * @var string $cipher The openssl cipher to use for this instance
     */
    protected $cipher = '';

    /**
     * @var string $password The raw password/key material supplied at construction
     */
    protected $password = '';

    /** Number of PBKDF2 iterations for key stretching */
    const PBKDF2_ITERATIONS = 100000;

    /** PBKDF2 / HMAC hash algorithm */
    const PBKDF2_ALGO = 'sha256';

    /** Derived key length in bytes (256-bit) */
    const KEY_LENGTH = 32;

    /** Salt length in bytes */
    const SALT_LENGTH = 16;

    /**
     * Constructor!
     *
     * @param string $cipher The openssl cipher to use for this instance
     * @param string $key    The secret key or password
     */
    public function __construct($cipher, $key) {
        $this->cipher   = $cipher;
        $this->password = $key;
    }

    /**
     * Decrypt the data with the provided key
     *
     * Verifies the HMAC before decrypting to prevent ciphertext tampering.
     * Re-derives keys from the salt embedded in the ciphertext payload.
     *
     * @param string $data The encrypted data to decrypt
     *
     * @return string|false The decrypted string, or false on failure / tampered data
     */
    public function decrypt($data) {
        $parts = explode('::', $data, 4);
        if (count($parts) !== 4) {
            return false;
        }
        [$cdata, $iv_hex, $salt_hex, $hmac] = $parts;

        [$enc_key, $hmac_key] = $this->deriveKeys(hex2bin($salt_hex));

        $expected = hash_hmac('sha256', $cdata . '::' . $iv_hex . '::' . $salt_hex, $hmac_key);
        if (!hash_equals($expected, $hmac)) {
            return false;
        }

        $result = openssl_decrypt($cdata, $this->cipher, $enc_key, 0, hex2bin($iv_hex));
        return ($result === false) ? false : $result;
    }

    /**
     * Encrypt the supplied data
     *
     * Generates a fresh random salt and IV for every call, derives keys via
     * PBKDF2, encrypts, then appends an HMAC (Encrypt-then-MAC).
     *
     * @param string $data The data to encrypt
     *
     * @return string The encrypted payload: ciphertext::iv::salt::hmac
     */
    public function encrypt($data) {
        $salt = openssl_random_pseudo_bytes(self::SALT_LENGTH);
        $iv   = openssl_random_pseudo_bytes(openssl_cipher_iv_length($this->cipher));

        [$enc_key, $hmac_key] = $this->deriveKeys($salt);

        $ciphertext = openssl_encrypt($data, $this->cipher, $enc_key, 0, $iv);
        $iv_hex     = bin2hex($iv);
        $salt_hex   = bin2hex($salt);
        $hmac       = hash_hmac('sha256', $ciphertext . '::' . $iv_hex . '::' . $salt_hex, $hmac_key);

        return $ciphertext . '::' . $iv_hex . '::' . $salt_hex . '::' . $hmac;
    }

    /**
     * Derive separate encryption and authentication keys from the password and salt.
     *
     * Runs PBKDF2 once to produce a master key, then splits it into two
     * independent keys via HMAC domain separation — keeping the expensive
     * PBKDF2 call to a single execution per operation.
     *
     * @param string $salt Raw binary salt
     *
     * @return array{string, string} [enc_key, hmac_key]
     */
    protected function deriveKeys($salt) {
        $master   = hash_pbkdf2(self::PBKDF2_ALGO, $this->password, $salt, self::PBKDF2_ITERATIONS, self::KEY_LENGTH, true);
        $enc_key  = hash_hmac('sha256', 'enc', $master, true);
        $hmac_key = hash_hmac('sha256', 'mac', $master, true);
        return [$enc_key, $hmac_key];
    }
}
