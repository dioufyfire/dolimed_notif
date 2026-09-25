<?php
namespace Relaxit\DolimedNotif;

class PayloadCipher
{
    private $key;
    public function __construct($hex)
    {
        if (!is_string($hex) || !preg_match('/\A[a-f0-9]{64}\z/i', $hex)) {
            throw new \RuntimeException('encryption_key_invalid');
        }
        $this->key = hex2bin($hex);
    }
    public function encrypt(array $value)
    {
        $json = json_encode($value);
        if ($json === false) {
            throw new \RuntimeException('payload_encoding_failed');
        }
        $strong = false;
        $iv = openssl_random_pseudo_bytes(16, $strong);
        if ($iv === false || !$strong) {
            throw new \RuntimeException('random_failed');
        }
        $body = openssl_encrypt($json, 'aes-256-cbc', hash_hmac('sha256', 'encrypt', $this->key, true), OPENSSL_RAW_DATA, $iv);
        if ($body === false) {
            throw new \RuntimeException('encryption_failed');
        }
        $data = $iv.$body;
        return base64_encode(hash_hmac('sha256', $data, hash_hmac('sha256', 'authenticate', $this->key, true), true).$data);
    }
    public function decrypt($encoded)
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 64) {
            throw new \RuntimeException('payload_invalid');
        }
        $data = substr($raw, 32);
        $mac = hash_hmac('sha256', $data, hash_hmac('sha256', 'authenticate', $this->key, true), true);
        if (!hash_equals(substr($raw, 0, 32), $mac)) {
            throw new \RuntimeException('payload_integrity_failed');
        }
        $plain = openssl_decrypt(substr($data, 16), 'aes-256-cbc', hash_hmac('sha256', 'encrypt', $this->key, true), OPENSSL_RAW_DATA, substr($data, 0, 16));
        $value = $plain === false ? null : json_decode($plain, true);
        if (!is_array($value)) {
            throw new \RuntimeException('payload_invalid');
        }
        return $value;
    }
}
