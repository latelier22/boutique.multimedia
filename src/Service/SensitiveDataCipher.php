<?php

namespace App\Service;

class SensitiveDataCipher
{
    private string $key;

    public function __construct(string $appSecret)
    {
        $this->key = sodium_crypto_generichash(
            $appSecret,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }

    public function encrypt(array $data): string
    {
        $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(?string $payload): ?array
    {
        if (!$payload) {
            return null;
        }

        $raw = base64_decode($payload, true);
        if ($raw === false) {
            return null;
        }

        $nonceSize = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $nonce = substr($raw, 0, $nonceSize);
        $cipher = substr($raw, $nonceSize);

        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plaintext === false) {
            return null;
        }

        return json_decode($plaintext, true);
    }
}