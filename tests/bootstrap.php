<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (is_file(dirname(__DIR__).'/.env')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Throw-away RSA key pair for signing JWTs in tests (real keys: `php bin/console lexik:jwt:generate-keypair`).
$keyDir = dirname(__DIR__).'/var/jwt-test';
if (!is_file($keyDir.'/private.pem') || !is_file($keyDir.'/public.pem')) {
    if (!is_dir($keyDir)) {
        mkdir($keyDir, 0700, true);
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem, 'test_passphrase');
    file_put_contents($keyDir.'/private.pem', $privatePem);
    file_put_contents($keyDir.'/public.pem', openssl_pkey_get_details($key)['key']);
}
