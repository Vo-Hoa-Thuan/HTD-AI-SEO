<?php
declare(strict_types=1);

namespace HTD\AISEO\Media;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Strict image & remote media security validator (magic bytes, double extensions, SVG, SSRF, base64 limit).
 */
final class ImageValidator {
	public const MAX_BYTES          = 5242880; // 5 MB
	public const MAX_BASE64_CHARS   = 7340032; // ~5.2 MB encoded
	public const MAX_PIXELS         = 25000000; // 25 MP

	/** @var array<string, string> */
	private const ALLOWED_MIME_EXT = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	];

	public function validateBinary(string $bytes, string $filename): string {
		if (strlen($bytes) === 0 || strlen($bytes) > self::MAX_BYTES) {
			throw new \InvalidArgumentException('Image size is empty or exceeds 5 MB limit.');
		}
		if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
			throw new \InvalidArgumentException('Path traversal detected in filename.');
		}
		if (preg_match('/\.(php|phtml|phar|html|htm|js|svg|exe|sh)\./i', $filename) || preg_match('/\.(php|phtml|phar|html|htm|js|svg|exe|sh)$/i', $filename)) {
			throw new \InvalidArgumentException('Executable or active content extension rejected.');
		}

		// Reject embedded PHP or SVG/HTML payloads
		if (preg_match('/<\?php|<\?=|<svg\b|<script\b|<html\b/i', substr($bytes, 0, 4096))) {
			throw new \InvalidArgumentException('Active HTML/PHP/SVG content detected inside image payload.');
		}

		$detectedMime = $this->detectMagicMime($bytes);
		if ($detectedMime === null || !isset(self::ALLOWED_MIME_EXT[$detectedMime])) {
			throw new \InvalidArgumentException('Unsupported or invalid image magic bytes.');
		}

		if (function_exists('getimagesizefromstring')) {
			$info = @getimagesizefromstring($bytes);
			if (!is_array($info) || empty($info[0]) || empty($info[1])) {
				throw new \InvalidArgumentException('Invalid image dimensions.');
			}
			if (((int) $info[0] * (int) $info[1]) > self::MAX_PIXELS) {
				throw new \InvalidArgumentException('Image exceeds maximum pixel count (25 MP).');
			}
		}

		return $detectedMime;
	}

	public function validateBase64(string $b64, string $filename): string {
		if (strlen($b64) > self::MAX_BASE64_CHARS) {
			throw new \InvalidArgumentException('Base64 image payload exceeds limit.');
		}
		$clean = preg_replace('/^data:image\/[a-zA-Z0-9\+\-]+;base64,/', '', trim($b64)) ?? '';
		$bytes = base64_decode($clean, true);
		if ($bytes === false) {
			throw new \InvalidArgumentException('Invalid base64 image encoding.');
		}
		return $this->validateBinary($bytes, $filename);
	}

	public function validateRemoteImageUrl(string $url): void {
		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			throw new \InvalidArgumentException('Invalid remote image URL.');
		}
		if (strtolower((string) $parts['scheme']) !== 'https') {
			throw new \InvalidArgumentException('Remote image URL must use HTTPS.');
		}
		$host = strtolower((string) $parts['host']);
		if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || $host === 'softviet.vn' || str_ends_with($host, '.softviet.vn')) {
			throw new \InvalidArgumentException("Blocked remote image host: {$host}");
		}
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
				throw new \InvalidArgumentException("SSRF blocked for remote image IP: {$host}");
			}
		}
	}

	private function detectMagicMime(string $bytes): ?string {
		if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
			return 'image/png';
		}
		if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
			return 'image/jpeg';
		}
		if (str_starts_with($bytes, "GIF87a") || str_starts_with($bytes, "GIF89a")) {
			return 'image/gif';
		}
		if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
			return 'image/webp';
		}
		return null;
	}
}
