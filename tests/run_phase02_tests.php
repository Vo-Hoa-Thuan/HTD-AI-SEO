<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Core\HealthCheck;
use HTD\AISEO\Core\ModuleRegistry;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Database\Migrator;
use HTD\AISEO\Database\Schema;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Queue\QueueEngine;
use HTD\AISEO\Security\ExternalRequestGuard;
use HTD\AISEO\Security\MutationGuard;
use HTD\AISEO\Security\Sanitizer;
use HTD\AISEO\Settings\SettingsRegistry;

$results = ['pass' => 0, 'fail' => 0, 'cases' => []];

function test_case(string $name, callable $fn, array &$results): void {
	try {
		$fn();
		$results['pass']++;
		$results['cases'][] = ['name' => $name, 'status' => 'PASS'];
	} catch (\Throwable $e) {
		$results['fail']++;
		$results['cases'][] = ['name' => $name, 'status' => 'FAIL', 'error' => $e->getMessage()];
	}
}

function assert_true(bool $cond, string $msg): void {
	if (!$cond) {
		throw new \RuntimeException("Assertion failed: {$msg}");
	}
}

function assert_throws(callable $fn, string $msg): void {
	try {
		$fn();
	} catch (\Throwable) {
		return;
	}
	throw new \RuntimeException("Expected exception not thrown: {$msg}");
}

// 1. Database Migrator tests (custom prefix, fresh install, idempotency, data preservation, rollback)
$wpdb = new TestWpdb('htd_custom_');
$migrator = new Migrator($wpdb);

test_case('DB_01_FreshMigration_CustomPrefix', function () use ($migrator, $wpdb): void {
	assert_true($migrator->migrate() === true, 'migrate() returned true');
	assert_true($migrator->currentVersion() === '0.2.0', 'DB version is 0.2.0');
	$migRow = $wpdb->get_row("SELECT * FROM htd_custom_htd_ai_seo_migrations WHERE version = '0.2.0'");
	assert_true($migRow !== null && $migRow['status'] === 'applied', 'Migration recorded as applied');
}, $results);

test_case('DB_02_IdempotentReMigration_PreservesData', function () use ($migrator, $wpdb): void {
	$logger = new Logger($wpdb);
	$logger->log('test', 'preserve_check', 'INFO', 'OK', ['k' => 'v']);
	$migrator->migrate();
	$rows = $logger->query(10);
	assert_true(count($rows) === 1, 'Existing log row preserved across repeated migration');
}, $results);

test_case('DB_03_RollbackAndReMigrate', function () use ($migrator): void {
	assert_true($migrator->rollbackVersion('0.2.0') === true, 'Rollback succeeded');
	assert_true($migrator->currentVersion() === '0.0.0', 'Version reset to 0.0.0');
	assert_true($migrator->migrate() === true, 'Re-migration after rollback succeeded');
}, $results);

// 2. Settings Registry safe defaults & sanitization
$settings = new SettingsRegistry();
$settings->ensureDefaults();

test_case('SET_01_SafeDefaultsEnforced', function () use ($settings): void {
	$all = $settings->all();
	assert_true($all['wp_read_only_mode'] === true, 'wp_read_only_mode=true');
	assert_true($all['allow_autopublish'] === false, 'allow_autopublish=false');
	assert_true($all['allow_product_update'] === false, 'allow_product_update=false');
	assert_true($all['allow_remote_update'] === false, 'allow_remote_update=false');
	assert_true($all['allow_external_telemetry'] === false, 'allow_external_telemetry=false');
	assert_true($all['require_human_approval'] === true, 'require_human_approval=true');
	assert_true($all['allow_background_content_generation'] === false, 'allow_background_content_generation=false');
	assert_true($all['allow_external_ai_requests'] === false, 'allow_external_ai_requests=false');
	assert_true($all['emergency_stop'] === false, 'emergency_stop=false');
}, $results);

test_case('SET_02_TypedSanitization', function () use ($settings): void {
	$clean = $settings->sanitize(['log_retention_days' => 9999, 'active_ai_provider' => 'invalid_provider']);
	assert_true($clean['log_retention_days'] === 365, 'int clamped to max 365');
	assert_true($clean['active_ai_provider'] === 'none', 'invalid enum falls back to default');
}, $results);

// 3. Credential Vault (AES-256-GCM if openssl loaded, or fail-closed verification)
if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
	$vault = new CredentialVault();
	test_case('VAULT_01_SealOpenAndMask', function () use ($vault): void {
		$vault->storeSecret('openai_key', 'sk-test-1234567890abcdef');
		$plain = $vault->getSecret('openai_key');
		assert_true($plain === 'sk-test-1234567890abcdef', 'Decrypted secret matches');
		$masked = $vault->listMasked();
		assert_true(isset($masked['openai_key']) && !str_contains($masked['openai_key']['masked'], '1234567890'), 'Masked display hides secret');
		assert_true(!str_contains(json_encode($GLOBALS['htd_test_options']['htd_ai_seo_credentials']), '1234567890'), 'No plaintext in stored option');
	}, $results);

	test_case('VAULT_02_TamperRejectionAndKeyRotation', function () use ($vault): void {
		$sealed = $vault->seal('sk-rotate-secret-9999', 1);
		$tampered = substr($sealed, 0, -4) . 'AAAA';
		assert_throws(fn() => $vault->open($tampered), 'Tampered ciphertext must fail authentication tag');
		$rotated = $vault->rotateAll(2);
		assert_true($rotated >= 1, 'Rotated stored secrets');
		assert_true($vault->getSecret('openai_key') === 'sk-test-1234567890abcdef', 'Secret still decrypts after rotation');
	}, $results);
} else {
	test_case('VAULT_01_FailClosedWithoutOpenSSL', function (): void {
		assert_throws(fn() => new CredentialVault(), 'CredentialVault must fail closed when OpenSSL AES-256-GCM is unavailable');
	}, $results);
}

// 4. External Request Guard (Default deny, SSRF, SoftViet block, allowlist)
$logger = new Logger($wpdb);
$httpGuard = new ExternalRequestGuard($settings, $logger);

test_case('HTTP_01_DefaultDenyAndSoftVietBlocked', function () use ($httpGuard, $settings): void {
	assert_throws(fn() => $httpGuard->validateUrl('https://api.openai.com/v1/chat/completions'), 'Blocked when allow_external_ai_requests=false');
	$settings->update(['allow_external_ai_requests' => true]);
	$httpGuard->validateUrl('https://api.openai.com/v1/chat/completions'); // Should pass
	assert_throws(fn() => $httpGuard->validateUrl('https://softviet.vn/wp-json/sv-ai-writer/v1/license/check'), 'SoftViet must always be blocked');
	assert_throws(fn() => $httpGuard->validateUrl('http://api.openai.com/v1/chat/completions'), 'Non-HTTPS must be blocked');
	assert_throws(fn() => $httpGuard->validateUrl('https://127.0.0.1/admin'), 'Loopback IP SSRF blocked');
	assert_throws(fn() => $httpGuard->validateUrl('https://169.254.169.254/latest/meta-data/'), 'Metadata IP SSRF blocked');
	assert_throws(fn() => $httpGuard->validateUrl('https://localhost/test'), 'Localhost SSRF blocked');
	assert_throws(fn() => $httpGuard->validateUrl('https://evil.example.com/steal'), 'Unlisted domain blocked');
	$settings->update(['allow_external_ai_requests' => false]);
}, $results);

// 5. Approval Engine & Mutation Guard
$approvals = new ApprovalEngine($wpdb, $logger);
$mutationGuard = new MutationGuard($settings, $approvals, $logger);

test_case('APPR_01_MutationGuardEnforcesReadOnlyAndApproval', function () use ($mutationGuard, $approvals, $settings): void {
	$payload = ['post_title' => 'Camera hanh trinh HTD', 'content' => '<p>Test</p>'];
	assert_throws(fn() => $mutationGuard->assertCanMutate('publish_post', 'post', 10, $payload, null), 'Blocked in READ_ONLY mode');

	$settings->update(['wp_read_only_mode' => false]);
	assert_throws(fn() => $mutationGuard->assertCanMutate('publish_post', 'post', 10, $payload, null), 'Blocked without approval_id');

	$aid = $approvals->createRequest('publish_post', 'post', 10, $payload, 1);
	assert_throws(fn() => $mutationGuard->assertCanMutate('publish_post', 'post', 10, $payload, $aid), 'Blocked while REVIEW_PENDING');

	$approvals->approve($aid, 1, 'LGTM');
	// Tamper payload
	assert_throws(fn() => $mutationGuard->assertCanMutate('publish_post', 'post', 10, ['post_title' => 'Tampered'], $aid), 'Blocked on payload hash mismatch');

	// Valid execution & double-execution prevention
	$mutationGuard->assertCanMutate('publish_post', 'post', 10, $payload, $aid);
	assert_true($approvals->claimForExecution($aid, 'publish_post', 'post', 10, $payload), 'Claimed for execution');
	assert_throws(fn() => $approvals->claimForExecution($aid, 'publish_post', 'post', 10, $payload), 'Double execution prevented');
	$approvals->markExecuted($aid, 'receipt-001');
	$settings->update(['wp_read_only_mode' => true]);
}, $results);

// 6. Logging & Redaction
test_case('LOG_01_SecretRedaction', function () use ($logger): void {
	$logger->log('ai', 'test_call', 'INFO', 'OK', [
		'api_key'       => 'sk-secret-1234567890',
		'authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9.abcdef',
		'note'          => 'Gemini key AIzaSyD1234567890123456789012345',
	]);
	$rows = $logger->query(1, 0, 'INFO', 'ai');
	assert_true(count($rows) === 1, 'Log row found');
	$ctx = $rows[0]['context_json'];
	assert_true(!str_contains($ctx, 'sk-secret-1234567890'), 'OpenAI key redacted');
	assert_true(!str_contains($ctx, 'eyJhbGciOiJIUzI1NiJ9'), 'Bearer token redacted');
	assert_true(!str_contains($ctx, 'AIzaSyD1234567890'), 'Gemini key redacted');
}, $results);

// 7. Queue Engine (Idempotency, Lease, Concurrent Claim, Retry/Dead-letter, Emergency Stop)
$queue = new QueueEngine($wpdb, $settings, $logger);

test_case('QUEUE_01_IdempotencyAndConcurrentClaim', function () use ($queue): void {
	$id1 = $queue->enqueue('test_job', ['x' => 1], 'idem-key-1', 5, 2);
	$id2 = $queue->enqueue('test_job', ['x' => 1], 'idem-key-1', 5, 2);
	assert_true($id1 === $id2, 'Duplicate idempotency_key returns same job ID');

	$claimed1 = $queue->claimNext('worker-A', 60);
	assert_true($claimed1 !== null && (int) $claimed1['id'] === $id1, 'Worker A claimed job');
	$claimed2 = $queue->claimNext('worker-B', 60);
	assert_true($claimed2 === null, 'Worker B cannot claim already-locked job');

	assert_true($queue->heartbeat($id1, 'worker-A', 120), 'Heartbeat succeeded');
	$st1 = $queue->fail($id1, 'worker-A', 'Transient error', 0);
	assert_true($st1 === QueueEngine::STATUS_FAILED, 'First failure sets FAILED for retry');

	$claimed3 = $queue->claimNext('worker-B', 60);
	assert_true($claimed3 !== null && (int) $claimed3['id'] === $id1, 'Worker B claimed retry');
	$st2 = $queue->fail($id1, 'worker-B', 'Permanent error', 0);
	assert_true($st2 === QueueEngine::STATUS_DEAD_LETTER, 'Max attempts transitions to DEAD_LETTER');
}, $results);

test_case('QUEUE_02_EmergencyStopBlocksEnqueueAndClaim', function () use ($queue, $settings): void {
	$settings->update(['emergency_stop' => true]);
	assert_throws(fn() => $queue->enqueue('test_job', ['x' => 2], 'idem-key-2'), 'Emergency stop blocks enqueue');
	assert_true($queue->claimNext('worker-A') === null, 'Emergency stop blocks claim');
	$settings->update(['emergency_stop' => false]);
}, $results);

// 8. HTML Sanitizer (SEC_004)
test_case('SEC_01_AiHtmlSanitizer', function (): void {
	$dirty = '<h2>Title</h2><script>alert(1)</script><p onclick="evil()">Text</p><a href="javascript:alert(2)">Link</a><iframe src="x"></iframe>';
	$clean = Sanitizer::sanitizeAiHtml($dirty);
	assert_true(!str_contains($clean, '<script'), 'Script stripped');
	assert_true(!str_contains($clean, '<iframe'), 'Iframe stripped');
	assert_true(!str_contains($clean, 'onclick'), 'Event handler stripped');
	assert_true(!str_contains($clean, 'javascript:'), 'javascript: URI stripped');
}, $results);

// 9. Core ModuleRegistry & HealthCheck
test_case('CORE_01_ModuleRegistryAndHealthCheck', function () use ($settings, $migrator, $queue): void {
	$reg = new ModuleRegistry();
	$reg->register('core', 'ACTIVE', 'PHASE_02');
	$reg->register('db', 'ACTIVE', 'PHASE_02', ['core']);
	$order = $reg->resolveOrder();
	assert_true($order === ['core', 'db'], 'Dependency order resolved');
	$hc = new HealthCheck($reg, $settings, $migrator, $queue);
	$summary = $hc->summary();
	assert_true($summary['wp_read_only_mode'] === true && $summary['db_schema_version'] === '0.2.0', 'HealthCheck returns real metrics');
}, $results);

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($results['fail'] > 0 ? 1 : 0);
