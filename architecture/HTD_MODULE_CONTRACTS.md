# HTD MODULE CONTRACTS

- `AiProviderInterface`: `completeJson(array $messages, array $options): AiResponse` (enforces timeout, retry/backoff, token budget, cost calculation).
- `PromptEngineInterface`: `buildArticlePrompt(KeywordJob $job, array $context): array` (100% local templates).
- `ApprovalGateInterface`: `stageForApproval(string $entityType, int $targetId, array $payload): int` (blocks direct LIVE writes when `REQUIRE_HUMAN_APPROVAL=true` or `WP_READ_ONLY_MODE=true`).
- `CredentialVaultInterface`: `seal(string $key, array $secret): void`, `open(string $key): array`.
- `QueueLockInterface`: `acquire(int $jobId, int $ttlSeconds): ?string`, `release(int $jobId, string $token): void`.
