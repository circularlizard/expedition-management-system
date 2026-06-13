<?php
namespace EMS\Tests\Unit\Core;

use EMS\Core\Encryption;
use EMS\Tests\EMSTestCase;

class EncryptionTest extends EMSTestCase {

    protected function setUp(): void {
        parent::setUp();
        if ( ! defined( 'AUTH_KEY' ) ) {
            define( 'AUTH_KEY', 'test-auth-key' );
        }
        if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
            define( 'SECURE_AUTH_KEY', 'test-secure-auth-key' );
        }
    }

    public function test_encrypt_decrypt(): void {
        $original  = 'secret-data';
        $encrypted = Encryption::encrypt( $original );

        $this->assertNotEquals( $original, $encrypted );
        $this->assertNotEmpty( $encrypted );

        $decrypted = Encryption::decrypt( $encrypted );
        $this->assertEquals( $original, $decrypted );
    }

    public function test_decrypt_invalid_data(): void {
        $this->assertFalse( Encryption::decrypt( 'invalid-data' ) );
    }
}
