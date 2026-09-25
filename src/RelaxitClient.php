<?php

namespace Relaxit\DolimedNotif;

/** Shared transport, deliberately independent from Dolibarr and compatible with PHP 5.6 syntax. */
class RelaxitClient
{
    private $url;
    private $key;
    private $transport;

    public function __construct($url, $key, $transport = null)
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if (!$parts || !isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Une URL HTTPS sans identifiants ni paramètres est requise.');
        }
        if (!is_string($key) || !preg_match('/\A[\x21-\x7e]+\z/', $key)) {
            throw new \InvalidArgumentException('Clé API absente ou invalide.');
        }
        if ($transport !== null && !is_callable($transport)) {
            throw new \InvalidArgumentException('Transport invalide.');
        }
        $this->url = rtrim($url, '/');
        $this->key = $key;
        $this->transport = $transport;
    }

    /** Read-only check. Caller must compare tenant.code and application before enabling its worker. */
    public function identity()
    {
        return $this->request('GET', '/api/v1/me');
    }

    /** Call from a worker after the source transaction has committed, never inside a creation trigger. */
    public function submit(array $payload, $idempotencyKey)
    {
        if (!is_string($idempotencyKey) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Clé idempotence invalide.');
        }
        $json = json_encode($payload);
        if ($json === false) {
            throw new \InvalidArgumentException('Le contenu doit être encodable en JSON UTF-8.');
        }
        return $this->request('POST', '/api/v1/notifications', $json, $idempotencyKey);
    }

    public function status($id)
    {
        if (!is_string($id) || !preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $id)) {
            throw new \InvalidArgumentException('Référence RelaxIT invalide.');
        }
        return $this->request('GET', '/api/v1/notifications/'.$id);
    }

    private function request($method, $path, $body = null, $idempotencyKey = null)
    {
        $headers = array('Authorization: Bearer '.$this->key, 'Accept: application/json');
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Idempotency-Key: '.$idempotencyKey;
        }
        $response = $this->transport !== null
            ? call_user_func($this->transport, $method, $this->url.$path, $headers, $body)
            : $this->curl($method, $this->url.$path, $headers, $body);
        $code = (int) $response['code'];
        $data = json_decode($response['body'], true);
        $ok = in_array($code, array(200, 202), true) && is_array($data);
        if ($ok && $path === '/api/v1/me') {
            $ok = isset($data['tenant']['code'], $data['application'])
                && is_string($data['tenant']['code']) && is_string($data['application']);
        } elseif ($ok) {
            $ok = isset($data['data']['id'], $data['data']['status'])
                && is_string($data['data']['id'])
                && preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $data['data']['id'])
                && in_array($data['data']['status'], array('pending', 'scheduled', 'queued', 'awaiting_provider',
                    'blocked', 'sending', 'submitted', 'failed', 'delivery_unknown', 'sent', 'delivered', 'read'), true);
            if ($ok && $method === 'GET') {
                $ok = substr($path, -26) === $data['data']['id'];
            }
        }
        // An uncertain POST must only be retried with the same saved body AND idempotency key.
        $retryable = $code === 0 || $code === 408 || $code === 429 || $code >= 500
            || (in_array($code, array(200, 202), true) && !$ok);
        return array(
            'ok' => (bool) $ok,
            'http_status' => $code,
            'retryable' => $retryable,
            'retry_after' => isset($response['retry_after']) ? $response['retry_after'] : null,
            'error' => $ok ? null : ($code === 0 ? 'transport_error' : 'http_'.$code),
            'data' => $ok ? $data : null
        );
    }

    private function curl($method, $url, array $headers, $body)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('Extension PHP cURL requise.');
        }
        $handle = curl_init($url);
        $retryAfter = null;
        $responseBody = '';
        curl_setopt_array($handle, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$retryAfter) {
                if (stripos($line, 'Retry-After:') === 0) {
                    $value = trim(substr($line, 12));
                    if (ctype_digit($value)) {
                        $retryAfter = min(86400, (int) $value);
                    } else {
                        $date = strtotime($value);
                        $retryAfter = $date === false ? null : min(86400, max(0, $date - time()));
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$responseBody) {
                if (strlen($responseBody) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            }
        ));
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $success = curl_exec($handle);
        $code = $success === false ? 0 : (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        // Never expose cURL diagnostic text or upstream error bodies, which may contain secrets.
        return array('code' => $code, 'body' => $success === false ? '' : $responseBody, 'retry_after' => $retryAfter);
    }
}
