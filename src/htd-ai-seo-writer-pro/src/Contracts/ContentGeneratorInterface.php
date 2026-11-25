<?php
declare(strict_types=1);

namespace HTD\AISEO\Contracts;

/**
 * Contract for HTD Content & Rewrite Engines.
 * @status NOT_IMPLEMENTED (Scheduled for Phase 04)
 */
interface ContentGeneratorInterface {
	public function generateDraft(array $jobPayload): array;
}
