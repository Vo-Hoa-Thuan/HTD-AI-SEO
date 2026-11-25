<?php
declare(strict_types=1);

namespace HTD\AISEO\AI;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Standard AI provider/model capabilities.
 */
final class Capability {
	public const TEXT_GENERATION   = 'TEXT_GENERATION';
	public const STRUCTURED_OUTPUT = 'STRUCTURED_OUTPUT';
	public const JSON_OUTPUT       = 'JSON_OUTPUT';
	public const IMAGE_GENERATION  = 'IMAGE_GENERATION';
	public const VISION_INPUT      = 'VISION_INPUT';
	public const STREAMING         = 'STREAMING';
	public const TOKEN_USAGE       = 'TOKEN_USAGE';
	public const MODEL_DISCOVERY   = 'MODEL_DISCOVERY';
}
