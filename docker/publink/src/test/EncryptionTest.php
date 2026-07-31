<?php

namespace Biblhertz\Publink\test;

use PHPUnit\Framework\TestCase;
use Biblhertz\Publink\utilities\Encryption;

/********************************************************************/
/* ENCRYPTION TESTS                                                  */
/********************************************************************/

class EncryptionTest extends TestCase
{
    private Encryption $enc;

    protected function setUp(): void
    {
        $this->enc = new Encryption('aes-256-cbc', 'super-secret-test-key');
    }

    // --- encrypt() ---

    public function testEncryptReturnsNonEmptyString(): void
    {
        $result = $this->enc->encrypt('hello world');
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testEncryptOutputHasFourParts(): void
    {
        $result = $this->enc->encrypt('test payload');
        $parts = explode('::', $result);
        $this->assertCount(4, $parts, 'Encrypted output must be ciphertext::iv::salt::hmac');
    }

    public function testEncryptProducesUniqueOutputEachCall(): void
    {
        $a = $this->enc->encrypt('same plaintext');
        $b = $this->enc->encrypt('same plaintext');
        $this->assertNotSame($a, $b, 'Each encrypt call should use a fresh random IV and salt');
    }

    // --- decrypt() ---

    public function testDecryptRoundTrip(): void
    {
        $plaintext = 'The quick brown fox';
        $ciphertext = $this->enc->encrypt($plaintext);
        $this->assertSame($plaintext, $this->enc->decrypt($ciphertext));
    }

    public function testDecryptEmptyString(): void
    {
        $ciphertext = $this->enc->encrypt('');
        $this->assertSame('', $this->enc->decrypt($ciphertext));
    }

    public function testDecryptReturnsFalseForInvalidFormat(): void
    {
        $this->assertFalse($this->enc->decrypt('not-valid-data'));
    }

    public function testDecryptReturnsFalseForTooFewParts(): void
    {
        $this->assertFalse($this->enc->decrypt('part1::part2::part3'));
    }

    public function testDecryptReturnsFalseForTamperedHmac(): void
    {
        $ciphertext = $this->enc->encrypt('sensitive data');
        // Replace the HMAC (4th part) with garbage
        $parts    = explode('::', $ciphertext, 4);
        $parts[3] = str_repeat('0', strlen($parts[3]));
        $tampered = implode('::', $parts);
        $this->assertFalse($this->enc->decrypt($tampered));
    }

    public function testDecryptReturnsFalseForWrongKey(): void
    {
        $ciphertext = $this->enc->encrypt('secret');
        $other      = new Encryption('aes-256-cbc', 'completely-different-key');
        $this->assertFalse($other->decrypt($ciphertext));
    }

    // --- Different cipher ---

    public function testEncryptDecryptWithAes128(): void
    {
        $enc128    = new Encryption('aes-128-cbc', 'another-key');
        $plaintext = 'Using 128-bit cipher';
        $this->assertSame($plaintext, $enc128->decrypt($enc128->encrypt($plaintext)));
    }
}
