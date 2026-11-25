<?php
declare(strict_types=1);

namespace HTD\AISEO\Prompt;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Immutable representation of a versioned HTD prompt template.
 */
final class PromptTemplate {
	/**
	 * @param string[] $requiredVariables
	 * @param string[] $optionalVariables
	 * @param array<string, mixed> $outputSchema
	 * @param array<string, mixed> $modelRequirements
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $taskType,
		public readonly string $version,
		public readonly string $language,
		public readonly string $systemInstructions,
		public readonly string $userTemplate,
		public readonly array $requiredVariables,
		public readonly array $optionalVariables,
		public readonly array $outputSchema,
		public readonly array $modelRequirements = ['json_output' => true],
		public readonly int $tokenBudget = 2048,
		public readonly string $status = 'active',
		public readonly string $createdAt = '',
		public readonly string $updatedAt = ''
	) {}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray(array $data): self {
		$now = gmdate('Y-m-d H:i:s');
		return new self(
			id: (string) ($data['id'] ?? ''),
			name: (string) ($data['name'] ?? ''),
			taskType: (string) ($data['task_type'] ?? 'general'),
			version: (string) ($data['version'] ?? '1.0.0'),
			language: (string) ($data['language'] ?? 'vi'),
			systemInstructions: (string) ($data['system_instructions'] ?? ''),
			userTemplate: (string) ($data['user_template'] ?? ''),
			requiredVariables: array_values(array_map('strval', (array) ($data['required_variables'] ?? []))),
			optionalVariables: array_values(array_map('strval', (array) ($data['optional_variables'] ?? []))),
			outputSchema: is_array($data['output_schema'] ?? null) ? $data['output_schema'] : [],
			modelRequirements: is_array($data['model_requirements'] ?? null) ? $data['model_requirements'] : ['json_output' => true],
			tokenBudget: max(128, (int) ($data['token_budget'] ?? 2048)),
			status: (isset($data['status']) && in_array($data['status'], ['active', 'disabled'], true)) ? (string) $data['status'] : 'active',
			createdAt: (string) ($data['created_at'] ?? $now),
			updatedAt: (string) ($data['updated_at'] ?? $now)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'id'                  => $this->id,
			'name'                => $this->name,
			'task_type'           => $this->taskType,
			'version'             => $this->version,
			'language'            => $this->language,
			'system_instructions' => $this->systemInstructions,
			'user_template'       => $this->userTemplate,
			'required_variables'  => $this->requiredVariables,
			'optional_variables'  => $this->optionalVariables,
			'output_schema'       => $this->outputSchema,
			'model_requirements'  => $this->modelRequirements,
			'token_budget'        => $this->tokenBudget,
			'status'              => $this->status,
			'created_at'          => $this->createdAt,
			'updated_at'          => $this->updatedAt,
		];
	}
}
