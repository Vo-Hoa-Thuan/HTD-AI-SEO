<?php
declare(strict_types=1);

namespace HTD\AISEO\Credentials;

use HTD\AISEO\Support\Environment;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * AES-256-GCM encrypted credential vault with versioned payloads and fail-closed crypto checks.
 */
final class CredentialVault {
	public const OPTION_NAME      = 'htd_ai_seo_credentials';
	public const KEY_VER_OPTION   = 'htd_ai_seo_credentials_key_version';
	private const PAYLOAD_VERSION = 'v1';

	private string $masterSecret;

	public function __construct(?string $masterSecret = null) {
		if (!Environment::hasOpenSsl() || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
			throw new \RuntimeException('OpenSSL AES-256-GCM is required for HTD Credential Vault. Fail-closed.');
		}
		if ($masterSecret !== null) {
			$this->masterSecret = $masterSecret;
		} else {
			$auth   = function_exists('wp_salt') ? wp_salt('auth') : '';
			$secure = function_exists('wp_salt') ? wp_salt('secure_auth') : '';
			if ($auth === '' && $secure === '') {
				throw new \RuntimeException('WordPress auth salts unavailable for key derivation.');
			}
			$this->masterSecret = $auth . '|' . $secure;
		}
	}

	private function deriveKey(int $keyVersion): string {
		return hash_hmac('sha256', "htd-ai-seo-vault-key-v{$keyVersion}", $this->masterSecret, true);
	}

	public function currentKeyVersion(): int {
		if (function_exists('get_option')) {
			return max(1, (int) get_option(self::KEY_VER_OPTION, 1));
		}
		return 1;
	}

	public function seal(string $plainSecret, ?int $keyVersion = null): string {
		if ($plainSecret === '') {
			throw new \InvalidArgumentException('Cannot seal an empty secret.');
		}
		$ver = $keyVersion ?? $this->currentKeyVersion();
		$iv = random_bytes(12);
		$tag = '';
		$aad = self::PAYLOAD_VERSION . ':' . $ver;
		$cipher = openssl_encrypt(
			$plainSecret,
			'aes-256-gcm',
			$this->deriveKey($ver),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$aad
		);
		if ($cipher === false || strlen($tag) !== 16) {
			throw new \RuntimeException('Credential encryption failed.');
		}
		return self::PAYLOAD_VERSION . ':' . $ver . ':' . base64_encode($iv . $tag . $cipher);
	}

	public function open(string $sealedPayload): string {
		$parts = explode(':', $sealedPayload, 3);
		if (count($parts) !== 3 || $parts[0] !== self::PAYLOAD_VERSION) {
			throw new \RuntimeException('Unsupported or corrupted credential payload format.');
		}
		$ver = (int) $parts[1];
		$raw = base64_decode($parts[2], true);
		if ($raw === false || strlen($raw) < 29) {
			throw new \RuntimeException('Corrupted credential ciphertext.');
		}
		$iv = substr($raw, 0, 12);
		$tag = substr($raw, 12, 16);
		$cipher = substr($raw, 28);
		$aad = self::PAYLOAD_VERSION . ':' . $ver;

		$plain = openssl_decrypt(
			$cipher,
			'aes-256-gcm',
			$this->deriveKey($ver),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$aad
		);
		if ($plain === false) {
			throw new \RuntimeException('Credential decryption or authentication tag verification failed.');
		}
		return $plain;
	}

	public function storeSecret(string $name, string $plainSecret): void {
		$name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name) ?? '';
		if ($name === '') {
			throw new \InvalidArgumentException('Invalid credential identifier.');
		}
		$store = $this->readRawStore();
		$ver = $this->currentKeyVersion();
		$store[$name] = [
			'sealed'     => $this->seal($plainSecret, $ver),
			'masked'     => self::mask($plainSecret),
			'key_ver'    => $ver,
			'updated_at' => gmdate('Y-m-d H:i:s'),
		];
		$this->writeRawStore($store);
	}

	public function getSecret(string $name): ?string {
		$store = $this->readRawStore();
		if (!isset($store[$name]['sealed'])) {
			return null;
		}
		return $this->open((string) $store[$name]['sealed']);
	}

	public function deleteSecret(string $name): void {
		$store = $this->readRawStore();
		if (isset($store[$name])) {
			unset($store[$name]);
			$this->writeRawStore($store);
		}
	}

	/**
	 * Rotate all stored secrets to a new key version.
	 */
	public function rotateAll(int $newKeyVersion): int {
		$store = $this->readRawStore();
		$rotated = 0;
		foreach ($store as $name => $entry) {
			if (empty($entry['sealed'])) {
				continue;
			}
			$plain = $this->open((string) $entry['sealed']);
			$store[$name]['sealed'] = $this->seal($plain, $newKeyVersion);
			$store[$name]['key_ver'] = $newKeyVersion;
			$store[$name]['updated_at'] = gmdate('Y-m-d H:i:s');
			$rotated++;
		}
		if (function_exists('update_option')) {
			update_option(self::KEY_VER_OPTION, $newKeyVersion, false);
		}
		$this->writeRawStore($store);
		return $rotated;
	}

	/**
	 * Return metadata with masked values only (never plaintext).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function listMasked(): array {
		$store = $this->readRawStore();
		$out = [];
		foreach ($store as $name => $entry) {
			$out[$name] = [
				'masked'     => (string) ($entry['masked'] ?? '****'),
				'key_ver'    => (int) ($entry['key_ver'] ?? 1),
				'updated_at' => (string) ($entry['updated_at'] ?? ''),
			];
		}
		return $out;
	}

	public static function mask(string $secret): string {
		$len = strlen($secret);
		if ($len <= 8) {
			return str_repeat('*', max(4, $len));
		}
		return substr($secret, 0, 4) . '***' . substr($secret, -4);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function readRawStore(): array {
		$raw = function_exists('get_option') ? get_option(self::OPTION_NAME, []) : [];
		return is_array($raw) ? $raw : [];
	}

	/**
	 * @param array<string, array<string, mixed>> $store
	 */
	private function writeRawStore(array $store): void {
		if (function_exists('update_option')) {
			update_option(self::OPTION_NAME, $store, false);
		}
	}
}
