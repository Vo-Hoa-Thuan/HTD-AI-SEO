<?php
declare(strict_types=1);

namespace HTD\AISEO\Contracts;

/**
 * Contract for AI Provider adapters.
 * @status NOT_IMPLEMENTED (Scheduled for Phase 03)
 */
interface AiProviderInterface {
	public function getProviderId(): string;
	public function completeJson(array $messages, array $options = []): array;
}
