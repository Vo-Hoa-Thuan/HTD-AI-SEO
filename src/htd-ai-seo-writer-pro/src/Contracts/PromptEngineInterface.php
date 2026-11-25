<?php
declare(strict_types=1);

namespace HTD\AISEO\Contracts;

/**
 * Contract for HTD Local Prompt Engine.
 * @status NOT_IMPLEMENTED (Scheduled for Phase 03)
 */
interface PromptEngineInterface {
	public function buildPrompt(string $templateKey, array $variables = []): array;
}
