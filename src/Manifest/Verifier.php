<?php

declare(strict_types=1);

namespace Sarcio\Shim\Manifest;

/**
 * Checks a signed patch against the workspace's published Ed25519 signing key,
 * over the same canonical form the control plane signed. The key is the value
 * `GET /api/sites/{key}/signing-key` returns (a base64-wrapped SPKI PEM), and
 * it can be swapped at runtime so a rotation is picked up on the next pull.
 * With no key set, or without the sodium functions, nothing verifies: a
 * patch that cannot be verified is a patch that does not apply.
 */
final class Verifier
{
    /** The fixed DER prefix of an Ed25519 SubjectPublicKeyInfo (RFC 8410). */
    private const SPKI_PREFIX = '302a300506032b6570032100';

    private string $publicKey = '';
    private string $keyId = '';

    /**
     * Adopt a key. An empty string keeps the current key, so a failed refresh
     * never disables a verifier that already works; an unparseable key is
     * refused the same way.
     */
    public function setKey(string $publicKeyB64): bool
    {
        if ($publicKeyB64 === '') {
            return false;
        }
        $raw = self::rawKey($publicKeyB64);
        if ($raw === null) {
            return false;
        }
        $this->publicKey = $raw;
        $this->keyId = $publicKeyB64;
        return true;
    }

    /** The key as it was given, to notice a rotation without re-parsing. */
    public function keyId(): string
    {
        return $this->keyId;
    }

    public function enabled(): bool
    {
        return $this->publicKey !== '' && function_exists('sodium_crypto_sign_verify_detached');
    }

    /** @param array{manifest?:mixed,signature?:mixed} $signed */
    public function verify(array $signed): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $manifest = $signed['manifest'] ?? null;
        $signature = $signed['signature'] ?? null;
        if (!is_array($manifest) || !is_string($signature)) {
            return false;
        }
        $sig = base64_decode($signature, true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($sig, Canonical::stringify($manifest), $this->publicKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /** The 32 raw key bytes out of base64(PEM(SPKI DER)), or null if it is not that. */
    private static function rawKey(string $publicKeyB64): ?string
    {
        $pem = base64_decode($publicKeyB64, true);
        if ($pem === false) {
            return null;
        }
        if (!preg_match('/-----BEGIN PUBLIC KEY-----(.*?)-----END PUBLIC KEY-----/s', $pem, $m)) {
            return null;
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
        if ($der === false || strlen($der) !== 44 || !str_starts_with(bin2hex($der), self::SPKI_PREFIX)) {
            return null;
        }
        return substr($der, 12);
    }
}
