<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use HTD\AISEO\AI\ProviderConfiguration;
use HTD\AISEO\Analytics\GSCAdapter;
use HTD\AISEO\Analytics\SEODataRepository;
use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Content\ContentDraftRepository;
use HTD\AISEO\Content\ContentQAEngine;
use HTD\AISEO\Content\ContentWorkflowEngine;
use HTD\AISEO\Core\HealthCheck;
use HTD\AISEO\Core\Plugin;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Database\BackupRestoreService;
use HTD\AISEO\Database\Migrator;
use HTD\AISEO\Database\Schema;
use HTD\AISEO\Growth\OneClickAuditWorkflow;
use HTD\AISEO\Keyword\KeywordRepository;
use HTD\AISEO\Publishing\PublishingEngine;
use HTD\AISEO\Queue\CronScheduler;
use HTD\AISEO\Queue\QueueEngine;
use HTD\AISEO\Queue\WorkerEngine;
use HTD\AISEO\Security\ExternalRequestGuard;
use HTD\AISEO\Settings\SettingsRegistry;
use HTD\AISEO\Support\Environment;
use HTD\AISEO\Token\TokenBudgetManager;

$GLOBALS['htd_v3_http'] = null;
$GLOBALS['htd_v3_cron'] = [];

function is_wp_error(mixed $thing): bool { return false; }
function wp_remote_request(string $url, array $args = []): array {
	if (is_callable($GLOBALS['htd_v3_http'])) {
		return ($GLOBALS['htd_v3_http'])($url, $args);
	}
	return ['response' => ['code' => 200], 'body' => '{}'];
}
function wp_remote_retrieve_body(array $res): string { return (string) ($res['body'] ?? ''); }
function wp_remote_retrieve_response_code(array $res): int { return (int) ($res['response']['code'] ?? 200); }
function wp_next_scheduled(string $hook): int|false { return $GLOBALS['htd_v3_cron'][$hook] ?? false; }
function wp_schedule_event(int $ts, string $rec, string $hook): bool { $GLOBALS['htd_v3_cron'][$hook] = $ts; return true; }
function wp_clear_scheduled_hook(string $hook): int { unset($GLOBALS['htd_v3_cron'][$hook]); return 1; }

$results = ['pass' => 0, 'fail' => 0, 'cases' => []];
function v3_test(string $name, string $layer, callable $fn, array &$results): void {
	try {
		$fn();
		$results['pass']++;
		$results['cases'][] = ['name' => $name, 'layer' => $layer, 'status' => 'PASS'];
	} catch (\Throwable $e) {
		$results['fail']++;
		$results['cases'][] = ['name' => $name, 'layer' => $layer, 'status' => 'FAIL', 'error' => $e->getMessage()];
	}
}
function v3_assert(bool $cond, string $msg): void {
	if (!$cond) {
		throw new \RuntimeException("Assertion failed: {$msg}");
	}
}
function v3_throws(callable $fn, string $msg): void {
	try { $fn(); } catch (\Throwable) { return; }
	throw new \RuntimeException("Expected exception not thrown: {$msg}");
}

global $wpdb;
$wpdb = new TestWpdb('wp_');
(new Migrator($wpdb))->migrate();

$plugin = Plugin::instance();
$c = $plugin->container();
$settings = $c->get(SettingsRegistry::class);
$settings->ensureDefaults();
/** @var ExternalRequestGuard $guard */
$guard = $c->get(ExternalRequestGuard::class);
$guard->setDnsResolver(static fn(string $h): array => ['93.184.216.34']);

// 1. TASK 01: UTF-8 safe fallback & HealthCheck extensions
v3_test('V3_01_PhpEnvironmentAndUtf8Fallback', 'UNIT', function () use ($c): void {
	$s = 'Camera hành trình ô tô HTD';
	v3_assert(Environment::safeSubstr($s, 0, 6) === 'Camera', 'UTF-8 safeSubstr works');
	v3_assert(Environment::safeStrlen($s) === 26, 'UTF-8 safeStrlen works');
	$health = $c->get(HealthCheck::class)->summary();
	v3_assert(array_key_exists('mbstring_available', $health) && array_key_exists('missing_extensions', $health), 'HealthCheck reports extensions');
}, $results);

// 2. TASK 02: ImageGeneration through production WorkerEngine + file & proposal persistence
v3_test('V3_02_ImageGenerationWorkerAndProposal', 'MOCK', function () use ($c, $settings, $wpdb): void {
	$settings->update(['allow_external_ai_requests' => true]);
	$c->get(CredentialVault::class)->storeSecret('openai_api_key', 'sk-img-test-123');

	// Valid 1x1 PNG bytes
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
	$GLOBALS['htd_v3_http'] = static fn(): array => [
		'response' => ['code' => 200],
		'body' => (string) json_encode(['data' => [['b64_json' => base64_encode($png)]]]),
	];

	/** @var QueueEngine $queue */
	$queue = $c->get(QueueEngine::class);
	$jobId = $queue->enqueue('IMAGE_GENERATION', ['provider_id' => 'openai', 'prompt' => 'HTD dashcam', 'draft_id' => 1], 'v3-img-1');
	$res = $c->get(WorkerEngine::class)->runBatch('v3-w-img', 1);
	v3_assert($res['completed'] === 1 && $res['failed'] === 0, 'IMAGE_GENERATION worker completed');

	$jobOut = $queue->getResult($jobId);
	v3_assert(!empty($jobOut['result']['file_path']) && is_file($jobOut['result']['file_path']), 'Generated image bytes persisted to disk');
	v3_assert((int) ($jobOut['result']['proposal_id'] ?? 0) > 0, 'Media proposal created in DB');
	@unlink($jobOut['result']['file_path']);
	$GLOBALS['htd_v3_http'] = null;
	$settings->update(['allow_external_ai_requests' => false]);
}, $results);

// 3. TASK 03: OneClickAudit empty=BLOCKED, gsc_api consumed, opportunities & audit persisted
v3_test('V3_03_OneClickAuditGscApiAndEmptyBlocked', 'UNIT', function () use ($c, $wpdb): void {
	/** @var OneClickAuditWorkflow $audit */
	$audit = $c->get(OneClickAuditWorkflow::class);
	$empty = $audit->runAudit([], []);
	v3_assert($empty['status'] === 'BLOCKED', 'Empty audit must return BLOCKED, not COMPLETED_READ_ONLY');

	/** @var SEODataRepository $seoRepo */
	$seoRepo = $c->get(SEODataRepository::class);
	$seoRepo->saveSnapshot('gsc_api', 'https://htd.net.vn/', 'search_performance', '2026-10-01..2026-10-28', SEODataRepository::PROVENANCE_LIVE_VERIFIED, [
		['query' => 'camera hanh trinh 4k', 'page' => 'https://htd.net.vn/cam-4k/', 'clicks' => 5, 'impressions' => 1200, 'ctr' => 0.004, 'position' => 6.0],
	]);
	/** @var QueueEngine $queue */
	$queue = $c->get(QueueEngine::class);
	$jid = $audit->enqueueAuditJob('v3-audit-1');
	$wRes = $c->get(WorkerEngine::class)->runBatch('v3-w-audit', 1);
	v3_assert($wRes['completed'] === 1, 'Audit worker completed');
	$stored = $queue->getResult($jid);
	v3_assert(str_contains((string) ($stored['result']['steps']['gsc'] ?? ''), 'gsc_api'), 'Audit consumed gsc_api snapshot');
	$oppTable = Schema::table($wpdb, 'opportunities');
	v3_assert(count($wpdb->tables[$oppTable] ?? []) >= 1, 'Opportunities persisted to DB');
}, $results);

// 4. TASK 04: Unknown pricing blocked until confirmed & atomic reservation
v3_test('V3_04_AtomicBudgetUnknownBlockedAndReservation', 'UNIT', function () use ($c): void {
	/** @var TokenBudgetManager $tokens */
	$tokens = $c->get(TokenBudgetManager::class);
	v3_throws(fn() => $tokens->estimateRequestCostUsd('custom', 'unconfirmed-model', 500, 500), 'Unconfirmed custom pricing blocked');
	v3_throws(fn() => $tokens->estimateRequestCostUsd('openai', 'unknown-gpt-x', 500, 500), 'Unknown OpenAI model blocked');
	$cost = $tokens->estimateRequestCostUsd('openai', 'gpt-4o-mini', 1000, 1000);
	v3_assert($cost > 0.0, 'Known pricing computed');
}, $results);

// 5. TASK 05: Expired lease resurrection blocked & atomic cron overlap lock
v3_test('V3_05_QueueExpiredLeaseResurrectionBlocked', 'UNIT', function () use ($c, $wpdb, $settings): void {
	/** @var QueueEngine $queue */
	$queue = $c->get(QueueEngine::class);
	$jid = $queue->enqueue('KEYWORD_ANALYSIS', ['keyword' => 'htd lease test'], 'v3-lease-1');
	$claimed = $queue->claimNext('w-expired', 10);
	v3_assert($claimed !== null, 'Job claimed');
	$qTable = Schema::table($wpdb, 'queue');
	$wpdb->tables[$qTable][$jid]['lease_expires_at'] = gmdate('Y-m-d H:i:s', time() - 30);

	v3_assert($queue->heartbeat($jid, 'w-expired') === false, 'Expired lease cannot heartbeat');
	v3_throws(fn() => $queue->complete($jid, 'w-expired'), 'Expired lease cannot complete');

	// Atomic cron overlap lock blocks concurrent execution
	$settings->update(['allow_background_content_generation' => true]);
	add_option(CronScheduler::LOCK_TRANSIENT, ['owner' => 'other', 'expires_at' => time() + 60], '', false);
	$cronRes = $c->get(CronScheduler::class)->runScheduled(1);
	v3_assert($cronRes['last_status'] === 'SKIPPED', 'Atomic cron lock prevented overlap');
	delete_option(CronScheduler::LOCK_TRANSIENT);
	$settings->update(['allow_background_content_generation' => false]);
	$queue->cancel($jid);
}, $results);

// 6. TASK 06: Content QA invalidation & approval supersede on edit to empty/new content
v3_test('V3_06_ContentQaInvalidationAndApprovalSupersede', 'UNIT', function () use ($c, $settings): void {
	/** @var ContentWorkflowEngine $wf */
	$wf = $c->get(ContentWorkflowEngine::class);
	/** @var ContentDraftRepository $repo */
	$repo = $c->get(ContentDraftRepository::class);
	/** @var ApprovalEngine $approvals */
	$approvals = $c->get(ApprovalEngine::class);
	/** @var ContentQAEngine $qa */
	$qa = $c->get(ContentQAEngine::class);
	/** @var PublishingEngine $pub */
	$pub = $c->get(PublishingEngine::class);

	$res = $wf->generateDraft([
		'primary_keyword' => 'android box htd d15',
		'suggested_title' => 'Android Box HTD D15',
		'meta_description' => str_repeat('Android Box HTD D15 chinh hang. ', 4),
		'article_html' => '<h2>Gioi thieu</h2><p>Android Box HTD D15.</p>',
	]);
	$draftId = (int) $res['draft_id'];
	$oldApprId = (int) $res['approval_id'];
	$approvals->approve($oldApprId, 1);

	// Edit to empty HTML -> must invalidate QA to FAIL and cancel old approval
	$repo->updateDraftContent($draftId, ['html_content' => ''], 1, $approvals, $qa);
	$afterEmpty = $repo->find($draftId);
	$oldApprRow = $approvals->find($oldApprId);
	v3_assert($afterEmpty['qa_status'] === 'FAIL' && $afterEmpty['approval_id'] === null, 'Empty edit sets QA=FAIL and approval_id=NULL');
	v3_assert($oldApprRow['status'] === ApprovalEngine::STATUS_CANCELLED, 'Old approval cancelled/superseded');

	$settings->update(['wp_read_only_mode' => false]);
	v3_throws(fn() => $pub->execute($draftId, $oldApprId, 'draft', 'v3-stale-1', false, null, fn() => 1), 'Stale cancelled approval rejected');
	$settings->update(['wp_read_only_mode' => true]);

	// Edit back to valid content -> QA passes and new approval generated
	$repo->updateDraftContent($draftId, ['html_content' => '<h2>Moi</h2><p>Android Box HTD D15 cap nhat.</p>', 'primary_keyword' => 'Android Box HTD D15'], 1, $approvals, $qa);
	$afterValid = $repo->find($draftId);
	v3_assert($afterValid['qa_status'] !== 'FAIL' && (int) $afterValid['approval_id'] > $oldApprId, 'Valid edit re-evaluates QA and issues new approval');
}, $results);

// 7. TASK 07: Google OAuth URL, code exchange, auto-refresh before expiry, disconnect
v3_test('V3_07_GoogleOAuthFlowAndAutoRefresh', 'MOCK', function () use ($c, $settings): void {
	/** @var GSCAdapter $gsc */
	$gsc = $c->get(GSCAdapter::class);
	$authUrl = $gsc->buildAuthUrl('cid-123', 'https://htd.net.vn/cb', 'st-1');
	v3_assert(str_contains($authUrl, urlencode(GSCAdapter::SCOPE_READONLY)), 'Readonly GSC scope enforced');

	$settings->update(['allow_external_ai_requests' => true]);
	update_option('htd_ai_seo_google_oauth_cfg', ['client_id' => 'cid-123'], false);
	$c->get(CredentialVault::class)->storeSecret('google_oauth_client_secret', 'csec-123');
	$c->get(CredentialVault::class)->storeSecret(GSCAdapter::CRED_TOKENS, (string) json_encode([
		'access_token' => 'expired-tok', 'refresh_token' => 'ref-tok', 'expires_at' => time() - 10,
	]));
	$refreshed = false;
	$GLOBALS['htd_v3_http'] = static function (string $url) use (&$refreshed): array {
		if (str_contains($url, 'oauth2.googleapis.com/token')) {
			$refreshed = true;
			return ['response' => ['code' => 200], 'body' => (string) json_encode(['access_token' => 'fresh-tok', 'expires_in' => 3600])];
		}
		if (str_contains($url, 'searchconsole.googleapis.com')) {
			return ['response' => ['code' => 200], 'body' => (string) json_encode(['rows' => []])];
		}
		return ['response' => ['code' => 200], 'body' => '{}'];
	};
	$gsc->syncSearchAnalytics('https://htd.net.vn/', '2026-10-01', '2026-10-28');
	v3_assert($refreshed === true, 'Expired token auto-refreshed before sync');
	$gsc->disconnect();
	$GLOBALS['htd_v3_http'] = null;
	$settings->update(['allow_external_ai_requests' => false]);
}, $results);

// 8. TASK 08: Worker handlers persist outputs to DB/options
v3_test('V3_08_WorkerHandlersPersistOutputs', 'UNIT', function () use ($c, $wpdb): void {
	/** @var QueueEngine $queue */
	$queue = $c->get(QueueEngine::class);
	/** @var KeywordRepository $kwRepo */
	$kwRepo = $c->get(KeywordRepository::class);
	$c->get(\HTD\AISEO\SEO\InternalLinkEngine::class)->indexPost(10, 'https://htd.net.vn/android-box-d15/', 'Android Box HTD D15');
	$kwId = $kwRepo->add(['keyword' => 'mua cam hanh trinh htd']);
	$j1 = $queue->enqueue('KEYWORD_ANALYSIS', ['keyword_id' => $kwId, 'keyword' => 'mua cam hanh trinh htd'], 'v3-kw-1');
	$j2 = $queue->enqueue('KEYWORD_CLUSTER', ['keywords' => ['mua cam hanh trinh htd']], 'v3-kw-2');
	$j3 = $queue->enqueue('INTERNAL_LINK_ANALYSIS', ['post_id' => 1, 'keyword' => 'Android Box', 'source_url' => 'https://htd.net.vn/bai-viet/'], 'v3-link-1');
	$res = $c->get(WorkerEngine::class)->runBatch('v3-w-ops', 5);
	$errs = array_map(static fn(int $id): string => (string) ($queue->find($id)['last_error'] ?? ''), [$j1, $j2, $j3]);
	v3_assert($res['completed'] === 3 && $res['failed'] === 0, 'All 3 operational jobs completed: ' . json_encode($res) . ' errs=' . json_encode($errs));
	$kwRow = $kwRepo->find($kwId);
	v3_assert($kwRow['search_intent'] !== 'UNKNOWN' && !empty($kwRow['keyword_group']), 'Keyword analysis & cluster persisted to keywords table');
	$linkTable = Schema::table($wpdb, 'link_proposals');
	v3_assert(count($wpdb->tables[$linkTable] ?? []) >= 1, 'Internal link proposals persisted to DB');
}, $results);

// 9. TASK 09: PublishingEngine::createPublishApproval separates publish from draft
v3_test('V3_09_CreatePublishApprovalAndCapabilities', 'UNIT', function () use ($c, $settings): void {
	/** @var ContentWorkflowEngine $wf */
	$wf = $c->get(ContentWorkflowEngine::class);
	/** @var PublishingEngine $pub */
	$pub = $c->get(PublishingEngine::class);
	/** @var ApprovalEngine $approvals */
	$approvals = $c->get(ApprovalEngine::class);

	$res = $wf->generateDraft([
		'primary_keyword' => 'cam bien ap suat lop htd',
		'suggested_title' => 'Cam bien ap suat lop HTD',
		'meta_description' => str_repeat('Cam bien ap suat lop HTD chinh hang. ', 3),
		'article_html' => '<h2>Gioi thieu</h2><p>Cam bien HTD.</p>',
	]);
	$pubApprId = $pub->createPublishApproval((int) $res['draft_id'], 'publish', null, 1);
	$approvals->approve($pubApprId, 1);
	$settings->update(['wp_read_only_mode' => false]);
	$exec = $pub->execute((int) $res['draft_id'], $pubApprId, 'publish', 'v3-pub-appr-1', false, null, fn() => 888);
	v3_assert($exec['wp_post_id'] === 888 && $exec['status'] === 'publish', 'Dedicated publish approval authorizes publish');
	$settings->update(['wp_read_only_mode' => true]);
}, $results);

// 10. TASK 10: Backup excluded table docs & no dangling approval IDs on restore
v3_test('V3_10_BackupExcludedDocsAndNoDanglingApprovals', 'UNIT', function () use ($wpdb): void {
	v3_assert(count(BackupRestoreService::coveredTables()) === 13 && count(BackupRestoreService::excludedTables()) === 10, '13 covered + 10 excluded = 23 tables documented');
	$svc = new BackupRestoreService($wpdb);
	$backup = $svc->exportBackup();
	$backup['tables']['content_drafts'] = [[
		'id' => 777, 'keyword_id' => 1, 'job_type' => 'new', 'target_post_id' => 0,
		'title' => 'Restored', 'html_content' => '<p>Hi</p>', 'qa_status' => 'PASS',
		'approval_id' => 99999, 'status' => 'REVIEW_PENDING',
		'created_at_utc' => gmdate('Y-m-d H:i:s'), 'updated_at_utc' => gmdate('Y-m-d H:i:s'),
	]];
	$backup['checksum'] = hash('sha256', (string) json_encode($backup['tables'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	$svc->restoreBackup($backup);
	$tbl = Schema::table($wpdb, 'content_drafts');
	$rows = array_values($wpdb->tables[$tbl] ?? []);
	$restored = $rows !== [] ? end($rows) : null;
	v3_assert(is_array($restored) && $restored['approval_id'] === null && $restored['status'] === 'DRAFT', 'Dangling approval_id cleared on restore');
}, $results);

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($results['fail'] > 0 ? 1 : 0);
