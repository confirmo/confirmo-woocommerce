<?php

/**
 * Standard Webhooks v1a verification, and the JWKS it verifies against.
 *
 * Split out because `verify()` is pure — bytes in, boolean out — and is the one
 * thing standing between a stranger's POST and a subscription's status.
 */
class WC_Confirmo_Subscribe_Signature
{
    const JWKS_TRANSIENT = 'confirmo_subscribe_jwks';
    const JWKS_TTL = 300;
    const JWKS_TIMEOUT = 8;

    /** Signed message is "{id}.{timestamp}.{body}"; tokens are prefixed "v1a,". */
    const PREFIX = 'v1a,';
    const SIGNATURE_BYTES = 64;
    const KEY_BYTES = 32;

    /**
     * @param array<int, string> $keys raw 32-byte Ed25519 public keys
     */
    public static function verify(string $id, string $timestamp, string $body, string $header, array $keys): bool
    {
        $signedMessage = $id . '.' . $timestamp . '.' . $body;

        foreach (explode(' ', $header) as $token) {
            if (strncmp($token, self::PREFIX, strlen(self::PREFIX)) !== 0) {
                continue;
            }

            $signature = base64_decode(substr($token, strlen(self::PREFIX)), true);
            if ($signature === false || strlen($signature) !== self::SIGNATURE_BYTES) {
                continue;
            }

            foreach ($keys as $key) {
                if (sodium_crypto_sign_verify_detached($signature, $signedMessage, $key)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Empty when the keys could not be read, which the caller must answer with a
     * 503 rather than a 400 — a dispatcher treats 4xx as permanent.
     *
     * @return array<int, string> raw 32-byte Ed25519 public keys
     */
    public static function publicKeys(bool $ignoreCache = false): array
    {
        $document = $ignoreCache ? false : get_transient(self::JWKS_TRANSIENT);
        $cached = is_array($document);

        if (!$cached) {
            $document = self::fetch();
            if ($document === null) {
                return [];
            }
        }

        $keys = self::ed25519Keys($document);

        if ($keys === []) {
            WC_Confirmo_Subscribe_Log::error('the JWKS carried no usable Ed25519 key');
            // Evicted, or a document with nothing usable in it would be re-read
            // on every delivery until the transient expired.
            delete_transient(self::JWKS_TRANSIENT);
            return [];
        }

        // Caching an empty or error document would keep every delivery failing
        // for the life of the transient.
        set_transient(self::JWKS_TRANSIENT, $document, self::JWKS_TTL);

        return $keys;
    }

    /**
     * Verifies against the cached keys, and on failure once more against freshly
     * fetched ones.
     *
     * Confirmo rotates its signing key, and for the life of the cached copy
     * every delivery signed with the new one failed against the old. Since a
     * failed signature is answered with a 400, which a dispatcher treats as
     * permanent, a rotation cost the store every event in that window — and a
     * lost payment event is a cycle of revenue.
     *
     * Returns false only when the signature matches neither, which is when it is
     * genuinely not Confirmo's.
     */
    public static function verifyAllowingRotation(string $id, string $timestamp, string $body, string $header): bool
    {
        $keys = self::publicKeys();
        if ($keys === []) {
            return false;
        }

        if (self::verify($id, $timestamp, $body, $header, $keys)) {
            return true;
        }

        $rotated = self::publicKeys(true);
        if ($rotated === [] || $rotated === $keys) {
            return false;
        }

        WC_Confirmo_Subscribe_Log::error('signature did not match the cached JWKS; retrying against a freshly fetched one');

        return self::verify($id, $timestamp, $body, $header, $rotated);
    }

    /**
     * @return array<string, mixed>|null null when the document could not be read
     */
    private static function fetch(): ?array
    {
        $base = defined('CONFIRMO_API_URL') ? CONFIRMO_API_URL : 'https://api.confirmo.com';
        $response = wp_remote_get($base . '/.well-known/jwks.json', ['timeout' => self::JWKS_TIMEOUT]);

        if (is_wp_error($response)) {
            WC_Confirmo_Subscribe_Log::error('could not fetch the JWKS: ' . $response->get_error_message());
            return null;
        }

        $document = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($document)) {
            WC_Confirmo_Subscribe_Log::error('JWKS response was not JSON (HTTP ' . (int) wp_remote_retrieve_response_code($response) . ')');
            return null;
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<int, string>
     */
    private static function ed25519Keys(array $document): array
    {
        $keys = [];

        foreach (($document['keys'] ?? []) as $jwk) {
            if (!is_array($jwk)) {
                continue;
            }
            if (($jwk['kty'] ?? '') !== 'OKP' || ($jwk['crv'] ?? '') !== 'Ed25519' || !isset($jwk['x'])) {
                continue;
            }

            $raw = self::base64UrlDecode((string) $jwk['x']);
            if ($raw !== false && strlen($raw) === self::KEY_BYTES) {
                $keys[] = $raw;
            }
        }

        return $keys;
    }

    /**
     * @return string|false
     */
    private static function base64UrlDecode(string $input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($input, '-_', '+/'), true);
    }
}
