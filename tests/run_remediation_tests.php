<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use HTD\AISEO\AI\AIOrchestrator;
use HTD\AISEO\AI\ProviderConfiguration;
use HTD\AISEO\AI\ProviderRegistry;
use HTD\AISEO\AI\Providers\CustomOpenAICompatibleProvider;
use HTD\AISEO\Analytics\GA4Adapter;
use HTD\AISEO\Analytics\GSCAdapter;
use HTD\AISEO\Analytics\SEODataRepository;
use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Content\ContentDraftRepository;
use HTD\AISEO\Content\ContentQAEngine;
use HTD\AISEO\Content\ContentWorkflowEngine;
use HTD\AISEO\Core\Plugin;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Database\BackupRestoreService;
use HTD\AISEO\Database\Migrator;
use HTD\AISEO\Database\Schema;
use HTD\AISEO\Growth\OneClickAuditWorkflow;
use HTD\AISEO\Keyword\KeywordIntelligence;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Publishing\PublishingEngine;
use HTD\AISEO\Queue\CronScheduler;
use HTD\AISEO\Queue\QueueEngine;
use HTD\AISEO\Queue\WorkerEngine;
use HTD\AISEO\Security\ExternalRequestGuard;
use HTD\AISEO\Security\MutationGuard;
use HTD\AISEO\SEO\RankMathAdapter;
use HTD\AISEO\Settings\SettingsRegistry;
use HTD\AISEO\Token\TokenBudgetManager;

// Test-only WP HTTP, Cron, and Transient stubs for isolated CLI execution
$GLOBALS['htd_http_mock_handler'] = null;
$GLOBALS['htd_cron_events'] = [];
$GLOBALS['htd_transients'] = [];

function is_wp_error(mixed $thing): bool { return false; }
function wp_remote_request(string $url, array $args = []): array {
	if (is_callable($GLOBALS['htd_http_mock_handler'])) {
		return ($GLOBALS['htd_http_mock_handler'])($url, $args);
	}
	return ['response' => ['code' => 200], 'body' => '{}'];
}
function wp_remote_retrieve_body(array $res): string { return (string) ($res['body'] ?? ''); }
function wp_remote_retrieve_response_code(array $res): int { return (int) ($res['response']['code'] ?? 200); }
function wp_next_scheduled(string $hook): int|false { return $GLOBALS['htd_cron_events'][$hook]['ts'] ?? false; }
function wp_schedule_event(int $ts, string $rec, string $hook): bool { $GLOBALS['htd_cron_events'][$hook] = ['ts' => $ts, 'rec' => $rec]; return true; }
function wp_clear_scheduled_hook(string $hook): int { $c = isset($GLOBALS['htd_cron_events'][$hook]) ? 1 : 0; unset($GLOBALS['htd_cron_events'][$hook]); return $c; }
function get_transient(string $k): mixed { return $GLOBALS['htd_transients'][$k] ?? false; }
function set_transient(string $k, mixed $v, int $exp = 0): bool { $GLOBALS['htd_transients'][$k] = $v; return true; }
function delete_transient(string $k): bool { unset($GLOBALS['htd_transients'][$k]); return true; }
function wp_installing(): bool { return false; }

$results = ['pass' => 0, 'fail' => 0, 'cases' => []];

function rem_test(string $name, string $layer, callable $fn, array &$results): void {
	try {
		$fn();
		$results['pass']++;
		$results['cases'][] = ['name' => $name, 'layer' => $layer, 'status' => 'PASS'];
	} catch (\Throwable $e) {
		$results['fail']++;
		$results['cases'][] = ['name' => $name, 'layer' => $layer, 'status' => 'FAIL', 'error' => $e->getMessage()];
	}
}
function rem_assert(bool $cond, string $msg): void {
	if (!$cond) {
		throw new \RuntimeException("Assertion failed: {$msg}");
	}
}
function rem_throws(callable $fn, string $msg): void {
	try { $fn(); } catch (\Throwable) { return; }
	throw new \RuntimeException("Expected exception not thrown: {$msg}");
}

global $wpdb;
$wpdb = new TestWpdb('wp_');
(new Migrator($wpdb))->migrate();

// Pre-configure custom provider before booting Plugin::instance() to test container wiring
$provCfg = new ProviderConfiguration();
$provCfg->save('custom', [
	'enabled' => true,
	'base_url' => 'https://llm.htd-fixture.example.com/v1',
	'model' => 'htd-test-model',
	'input_per_1m' => 1.0,
	'output_per_1m' => 2.0,
]);

$plugin = Plugin::instance();
$c = $plugin->container();
$settings = $c->get(SettingsRegistry::class);
$settings->ensureDefaults();

// 1. TASK 01: Draft approval cannot publish or schedule
rem_test('REM_01_DraftApprovalCannotPublish', 'UNIT', function () use ($c, $settings): void {
	/** @var ContentWorkflowEngine $wf */
	$wf = $c->get(ContentWorkflowEngine::class);
	/** @var ApprovalEngine $approvals */
	$approvals = $c->get(ApprovalEngine::class);
	/** @var PublishingEngine $pub */
	$pub = $c->get(PublishingEngine::class);

	$res = $wf->generateDraft([
		'primary_keyword' => 'camera hanh trinh htd',
		'suggested_title' => 'Camera hanh trinh HTD C22',
		'meta_description' => str_repeat('Camera hanh trinh HTD C22 chinh hang. ', 3),
		'article_html' => '<h2>Danh gia</h2><p>Camera hanh trinh HTD C22.</p>',
	]);
	$draftId = (int) $res['draft_id'];
	$approvalId = (int) $res['approval_id'];
	$approvals->approve($approvalId, 1, 'Approved for draft only');

	$settings->update(['wp_read_only_mode' => false, 'allow_autopublish' => false]);
	$writes = 0;
	$writer = static function () use (&$writes): int { $writes++; return 999; };

	rem_throws(fn() => $pub->execute($draftId, $approvalId, 'publish', 'rem-pub-1', false, null, $writer), 'Draft approval must not authorize publish');
	rem_throws(fn() => $pub->execute($draftId, $approvalId, 'future', 'rem-pub-2', false, '2027-01-01 00:00:00', $writer), 'Draft approval must not authorize future');
	rem_assert($writes === 0, 'Mock writer was never called on rejected publish');

	$settings->update(['wp_read_only_mode' => true]);
}, $results);

// 2. TASK 02: Draft approval_id persists in DB
rem_test('REM_02_DraftApprovalIdPersistsInDb', 'UNIT', function () use ($c): void {
	/** @var ContentWorkflowEngine $wf */
	$wf = $c->get(ContentWorkflowEngine::class);
	/** @var ContentDraftRepository $repo */
	$repo = $c->get(ContentDraftRepository::class);

	$res = $wf->generateDraft([
		'primary_keyword' => 'android box o to htd',
		'suggested_title' => 'Android Box o to HTD D15',
		'meta_description' => str_repeat('Android Box o to HTD D15 chinh hang. ', 3),
		'article_html' => '<h2>Tinh nang</h2><p>Android Box HTD D15.</p>',
	]);
	$stored = $repo->find((int) $res['draft_id']);
	rem_assert($stored !== null, 'Draft exists in DB');
	rem_assert((int) ($stored['approval_id'] ?? 0) === (int) $res['approval_id'], 'Stored approval_id matches returned approval_id');
	rem_assert($stored['status'] === 'REVIEW_PENDING', 'Stored draft status is REVIEW_PENDING');

	$repo->updateDraftContent((int) $res['draft_id'], ['title' => 'Updated Title']);
	$afterEdit = $repo->find((int) $res['draft_id']);
	rem_assert($afterEdit['approval_id'] === null && $afterEdit['status'] === 'DRAFT', 'Editing draft clears prior approval');
}, $results);

// 3. TASK 03: Production container registers all 11 worker handlers
rem_test('REM_03_ProductionContainerWorkerDispatch', 'UNIT', function () use ($c): void {
	/** @var WorkerEngine $worker */
	$worker = $c->get(WorkerEngine::class);
	$registered = $worker->registeredJobTypes();
	foreach (WorkerEngine::JOB_TYPES as $jt) {
		rem_assert(in_array($jt, $registered, true), "Handler registered in production container for {$jt}");
	}
}, $results);

// 4. TASK 03: HTD_SEO_AUDIT job execution via production Plugin container
rem_test('REM_04_HtdSeoAuditJobExecution', 'UNIT', function () use ($c): void {
	/** @var OneClickAuditWorkflow $audit */
	$audit = $c->get(OneClickAuditWorkflow::class);
	/** @var WorkerEngine $worker */
	$worker = $c->get(WorkerEngine::class);

	$jobId = $audit->enqueueAuditJob('rem-audit-job-1');
	rem_assert($jobId > 0, 'Audit job enqueued');
	$res = $worker->runBatch('rem-worker-1', 5);
	rem_assert($res['completed'] >= 1 && $res['failed'] === 0, 'Audit job completed with 0 failures');
}, $results);

// 5. TASK 04: ContentWorkflowEngine AI workflow with HTTP fixture & BLOCKED_BY_CONFIGURATION
rem_test('REM_05_AiContentWorkflowHttpFixture', 'MOCK', function () use ($c, $settings): void {
	/** @var ContentWorkflowEngine $wf */
	$wf = $c->get(ContentWorkflowEngine::class);
	/** @var CredentialVault $vault */
	$vault = $c->get(CredentialVault::class);
	/** @var ExternalRequestGuard $guard */
	$guard = $c->get(ExternalRequestGuard::class);
	$guard->setDnsResolver(static fn(string $h): array => ['93.184.216.34']);

	$settings->update(['allow_external_ai_requests' => true]);
	$vault->deleteSecret('custom_provider_api_key');
	$blocked = $wf->runAiWorkflow(['primary_keyword' => 'camera htd', 'provider_id' => 'custom', 'skip_capability_check' => true]);
	rem_assert(($blocked['status'] ?? '') === 'BLOCKED_BY_CONFIGURATION', 'Missing credential returns BLOCKED_BY_CONFIGURATION');

	$vault->storeSecret('custom_provider_api_key', 'sk-custom-fixture-123');
	$GLOBALS['htd_http_mock_handler'] = static function (string $url, array $args): array {
		$body = json_decode((string) ($args['body'] ?? '{}'), true);
		$prompt = (string) ($body['messages'][1]['content'] ?? '');
		if (str_contains($prompt, 'Phân tích từ khóa')) {
			$json = ['primary_keyword' => 'camera htd', 'search_intent' => 'commercial_investigation', 'subtopics' => ['4K'], 'verification_status' => 'OK'];
		} elseif (str_contains($prompt, 'Lập dàn ý')) {
			$json = ['title' => 'Camera HTD C22 4K', 'headings' => ['H2 Giới thiệu', 'H2 Thông số'], 'faq_topics' => ['Bảo hành']];
		} elseif (str_contains($prompt, 'Viết bài chuẩn SEO')) {
			$json = ['title' => 'Camera HTD C22 4K', 'html_content' => '<h2>Giới thiệu</h2><p>Camera HTD C22 4K chính hãng.</p>', 'faq' => [], 'verification_flags' => []];
		} elseif (str_contains($prompt, 'Meta Title')) {
			$json = ['meta_title' => 'Camera HTD C22 4K Chính Hãng', 'char_count' => 30];
		} else {
			$json = ['meta_description' => str_repeat('Camera HTD C22 4K chính hãng sắc nét. ', 3), 'char_count' => 120];
		}
		return [
			'response' => ['code' => 200],
			'body' => (string) json_encode([
				'choices' => [['message' => ['content' => json_encode($json, JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']],
				'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 100],
			]),
		];
	};

	$done = $wf->runAiWorkflow(['primary_keyword' => 'camera htd', 'provider_id' => 'custom', 'skip_capability_check' => true]);
	rem_assert(($done['status_code'] ?? '') === 'COMPLETED' && !empty($done['draft_id']) && !empty($done['approval_id']), 'Full AI workflow generated draft & approval');

	$GLOBALS['htd_http_mock_handler'] = null;
	$settings->update(['allow_external_ai_requests' => false]);
}, $results);

// 6. TASK 06: Custom Provider Configuration applied in Plugin container
rem_test('REM_06_CustomProviderConfigurationAndAdapter', 'UNIT', function () use ($c): void {
	/** @var ProviderRegistry $reg */
	$reg = $c->get(ProviderRegistry::class);
	/** @var CustomOpenAICompatibleProvider $custom */
	$custom = $reg->get('custom');
	rem_assert($custom->getBaseUrl() === 'https://llm.htd-fixture.example.com/v1', 'Plugin container applied configured custom Base URL');

	/** @var ProviderConfiguration $cfg */
	$cfg = $c->get(ProviderConfiguration::class);
	rem_throws(fn() => $cfg->save('custom', ['base_url' => 'http://127.0.0.1/v1']), 'HTTP/loopback custom Base URL rejected');
	rem_throws(fn() => $cfg->save('custom', ['base_url' => 'https://user:pass@api.example.com/v1']), 'Userinfo in custom Base URL rejected');
}, $results);

// 7. TASK 08: Cron registration with settings & stale lease expiry protection
rem_test('REM_07_CronRegistrationAndLeaseExpiry', 'UNIT', function () use ($c, $settings, $wpdb): void {
	/** @var CronScheduler $cron */
	$cron = $c->get(CronScheduler::class);
	$settings->update(['allow_background_content_generation' => false]);
	$cron->syncWithSettings();
	rem_assert(wp_next_scheduled(CronScheduler::HOOK_PROCESS) === false, 'Cron cleared when disabled');

	$settings->update(['allow_background_content_generation' => true, 'emergency_stop' => false]);
	$cron->syncWithSettings();
	rem_assert(wp_next_scheduled(CronScheduler::HOOK_PROCESS) !== false, 'Cron scheduled when enabled');

	$settings->update(['allow_background_content_generation' => false]);
	$cron->syncWithSettings();
	rem_assert(wp_next_scheduled(CronScheduler::HOOK_PROCESS) === false, 'Cron cleared again');

	// Verify expired lease cannot be completed by stale worker
	/** @var QueueEngine $queue */
	$queue = $c->get(QueueEngine::class);
	$jid = $queue->enqueue('KEYWORD_ANALYSIS', ['keyword' => 'test'], 'rem-lease-1');
	$claimed = $queue->claimNext('stale-worker', 10);
	rem_assert($claimed !== null, 'Claimed job');
	$qTable = Schema::table($wpdb, 'queue');
	$wpdb->tables[$qTable][$jid]['lease_expires_at'] = gmdate('Y-m-d H:i:s', time() - 60);
	$res = $c->get(WorkerEngine::class)->runBatch('other-worker', 1);
	rem_assert($res['processed'] === 1, 'Expired lease recovered and processed by fresh worker');
}, $results);

// 8. TASK 07: Google GSC/GA4 sync wiring & CSV validation
rem_test('REM_08_GoogleSyncWiringAndCsvValidation', 'MOCK', function () use ($c, $settings): void {
	/** @var GSCAdapter $gsc */
	$gsc = $c->get(GSCAdapter::class);
	/** @var GA4Adapter $ga4 */
	$ga4 = $c->get(GA4Adapter::class);
	/** @var CredentialVault $vault */
	$vault = $c->get(CredentialVault::class);
	/** @var ExternalRequestGuard $guard */
	$guard = $c->get(ExternalRequestGuard::class);
	$guard->setDnsResolver(static fn(string $h): array => ['142.250.190.10']);

	rem_throws(fn() => $gsc->importCsvReport("foo,bar\n1,2", 'https://htd.net.vn/', 'queries', '28d'), 'Arbitrary CSV columns rejected');

	$settings->update(['allow_external_ai_requests' => true]);
	$vault->storeSecret(GSCAdapter::CRED_TOKENS, (string) json_encode(['access_token' => 'gsc-tok', 'refresh_token' => 'gsc-ref']));
	$vault->storeSecret(GA4Adapter::CRED_TOKENS, (string) json_encode(['access_token' => 'ga4-tok', 'refresh_token' => 'ga4-ref']));
	$GLOBALS['htd_http_mock_handler'] = static function (string $url): array {
		if (str_contains($url, 'searchconsole.googleapis.com')) {
			return ['response' => ['code' => 200], 'body' => (string) json_encode(['rows' => [['keys' => ['htd d15', 'https://htd.net.vn/d15/'], 'clicks' => 12, 'impressions' => 400, 'ctr' => 0.03, 'position' => 4.2]]])];
		}
		return ['response' => ['code' => 200], 'body' => (string) json_encode(['rows' => [['dimensionValues' => [['value' => '/d15/'], ['value' => 'Organic Search']], 'metricValues' => [['value' => '50'], ['value' => '60'], ['value' => '45'], ['value' => '0.75'], ['value' => '90'], ['value' => '80'], ['value' => '3']]]]])];
	};
	$gscRes = $gsc->syncSearchAnalytics('https://htd.net.vn/', '2026-10-01', '2026-10-28');
	$ga4Res = $ga4->syncReport('123456', '2026-10-01', '2026-10-28');
	rem_assert($gscRes['row_count'] === 1 && $gscRes['provenance'] === SEODataRepository::PROVENANCE_LIVE_VERIFIED, 'GSC sync persisted LIVE_VERIFIED');
	rem_assert($ga4Res['row_count'] === 1 && $ga4Res['provenance'] === SEODataRepository::PROVENANCE_LIVE_VERIFIED, 'GA4 sync persisted LIVE_VERIFIED');
	$gsc->disconnect();
	$ga4->disconnect();
	rem_assert($gsc->connectionStatus()['connected'] === false && $ga4->connectionStatus()['connected'] === false, 'Disconnect cleared tokens');
	$GLOBALS['htd_http_mock_handler'] = null;
	$settings->update(['allow_external_ai_requests' => false]);
}, $results);

// 9. TASK 09: DNS destination safety & rebinding protection
rem_test('REM_09_DnsDestinationSafety', 'UNIT', function () use ($c, $settings): void {
	/** @var ExternalRequestGuard $guard */
	$guard = $c->get(ExternalRequestGuard::class);
	$settings->update(['allow_external_ai_requests' => true]);
	rem_throws(fn() => $guard->validateUrl('https://user:pass@api.openai.com/v1/responses'), 'Userinfo blocked');
	rem_throws(fn() => $guard->validateUrl('https://api.openai.com:8443/v1/responses'), 'Unsafe port blocked');
	$guard->setDnsResolver(static fn(string $h): array => ['10.0.0.12']);
	rem_throws(fn() => $guard->validateUrl('https://api.openai.com/v1/responses', true), 'DNS resolving to private IPv4 blocked');
	$guard->setDnsResolver(static fn(string $h): array => ['::1']);
	rem_throws(fn() => $guard->validateUrl('https://api.openai.com/v1/responses', true), 'DNS resolving to IPv6 loopback blocked');
	$guard->setDnsResolver(null);
	$settings->update(['allow_external_ai_requests' => false]);
}, $results);

// 10. TASK 09: Atomic budget reservation & unknown pricing non-free
rem_test('REM_10_BudgetReservationAndUnknownPricing', 'UNIT', function () use ($c): void {
	/** @var TokenBudgetManager $tokens */
	$tokens = $c->get(TokenBudgetManager::class);
	$est = $tokens->estimateRequestCostUsd('unknown_provider', 'unknown-model', 1000, 1000);
	rem_assert($est > 0.0, 'Unknown pricing is NOT treated as free');

	$resId = $tokens->reserveBudget('custom', 1.5);
	rem_throws(fn() => $tokens->reserveBudget('custom', 1.0), 'Concurrent reservation exceeding $2.00 daily cap blocked');
	$tokens->reconcileReservation($resId, 'custom', 'htd-test-model', 'test', 100, 50, 0.01, false);
	$tokens->assertBudgetAvailable('custom', 1.5);
}, $results);

// 11. TASK 10: Backup/Restore failure detection & schema validation
rem_test('REM_11_BackupRestoreFailureAndSchemaValidation', 'UNIT', function () use ($wpdb): void {
	$svc = new BackupRestoreService($wpdb);
	rem_assert(count(BackupRestoreService::coveredTables()) === 13, 'Covered tables documented');
	$backup = $svc->exportBackup();
	$badSchema = $backup;
	$badSchema['schema_version'] = '99.0.0';
	$badSchema['checksum'] = hash('sha256', (string) json_encode($badSchema['tables'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	rem_throws(fn() => $svc->restoreBackup($badSchema), 'Incompatible schema version rejected');

	// Simulate DB insert failure wrapper
	$failingWpdb = new class($wpdb) {
		public string $prefix;
		public string $last_error = 'Simulated insert error';
		public function __construct(private object $inner) { $this->prefix = $inner->prefix; }
		public function prepare(string $q, mixed ...$a): string { return $this->inner->prepare($q, ...$a); }
		public function query(string $s): int|bool { return $this->inner->query($s); }
		public function get_var(string $s): mixed { return null; }
		public function insert(string $t, array $d): int|false { return false; }
	};
	$failSvc = new BackupRestoreService($failingWpdb);
	$backup['tables']['keywords'] = [['id' => 999, 'keyword' => 'fail test']];
	$backup['checksum'] = hash('sha256', (string) json_encode($backup['tables'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	rem_throws(fn() => $failSvc->restoreBackup($backup, false), 'Failed DB insert throws and aborts restore');
}, $results);

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($results['fail'] > 0 ? 1 : 0);
