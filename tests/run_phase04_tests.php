<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use HTD\AISEO\AI\ModelRegistry;
use HTD\AISEO\AI\ProviderRegistry;
use HTD\AISEO\AI\Providers\ClaudeProvider;
use HTD\AISEO\AI\Providers\OpenAIProvider;
use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Content\ContentDraftRepository;
use HTD\AISEO\Content\ContentQAEngine;
use HTD\AISEO\Content\ContentWorkflowEngine;
use HTD\AISEO\Content\NewUpdateDecisionEngine;
use HTD\AISEO\Database\Migrator;
use HTD\AISEO\Keyword\KeywordIntelligence;
use HTD\AISEO\Keyword\KeywordRepository;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Media\ImageValidator;
use HTD\AISEO\Media\MediaProposalService;
use HTD\AISEO\Queue\QueueEngine;
use HTD\AISEO\Rewrite\RewriteEngine;
use HTD\AISEO\Security\ExternalRequestGuard;
use HTD\AISEO\SEO\InternalLinkProposalEngine;
use HTD\AISEO\Settings\SettingsRegistry;

$results = ['pass' => 0, 'fail' => 0, 'cases' => []];

function p4_test(string $name, callable $fn, array &$results): void {
	try {
		$fn();
		$results['pass']++;
		$results['cases'][] = ['name' => $name, 'status' => 'PASS'];
	} catch (\Throwable $e) {
		$results['fail']++;
		$results['cases'][] = ['name' => $name, 'status' => 'FAIL', 'error' => $e->getMessage()];
	}
}

function p4_assert(bool $cond, string $msg): void {
	if (!$cond) {
		throw new \RuntimeException("Assertion failed: {$msg}");
	}
}

function p4_throws(callable $fn, string $msg): void {
	try {
		$fn();
	} catch (\Throwable) {
		return;
	}
	throw new \RuntimeException("Expected exception not thrown: {$msg}");
}

$wpdb = new TestWpdb('wp_');
$migrator = new Migrator($wpdb);
$migrator->migrate();

$settings = new SettingsRegistry();
$settings->ensureDefaults();
$logger = new Logger($wpdb);
$brand = new BrandProfile();
$approvals = new ApprovalEngine($wpdb, $logger);
$httpGuard = new ExternalRequestGuard($settings, $logger);
$models = new ModelRegistry();
$providers = new ProviderRegistry();
$providers->register(new OpenAIProvider($httpGuard, $models));
$providers->register(new ClaudeProvider($httpGuard, $models));

// 1. DB v0.4.0 Migration & Rollback
p4_test('DB4_01_V040MigrationAndRollback', function () use ($migrator): void {
	p4_assert($migrator->currentVersion() === Migrator::TARGET_VERSION, 'DB version matches target');
	p4_assert($migrator->rollbackVersion('0.4.0') === true, 'Rollback v0.4.0 succeeded');
	p4_assert($migrator->currentVersion() === '0.2.0', 'Rolled back to 0.2.0');
	p4_assert($migrator->migrate() === true, 'Re-migrated to target');
}, $results);

// 2. Keyword Engine: CRUD, Duplicate, Search, Bulk, CSV Import/Export
$kwRepo = new KeywordRepository($wpdb, $logger);
p4_test('KW_01_CrudDuplicateSearchBulkAndCsv', function () use ($kwRepo): void {
	$id1 = $kwRepo->add(['keyword' => 'camera hành trình ô tô HTD', 'keyword_group' => 'camera']);
	p4_throws(fn() => $kwRepo->add(['keyword' => 'camera hành trình ô tô HTD']), 'Duplicate keyword rejected');

	$csvRes = $kwRepo->importCsv("keyword,secondary,group,priority,url\nandroid box ô tô HTD,box carplay,android-box,2,https://htd.net.vn/danh-muc-san-pham/android-box-o-to/\n=cmd(),x,bad,5,");
	p4_assert($csvRes['added'] === 2, 'CSV imported 2 rows');

	$found = $kwRepo->list(10, 0, null, 'android box');
	p4_assert(count($found) === 1, 'Search filter works');

	$paused = $kwRepo->bulkAction([$id1], 'pause');
	p4_assert($paused === 1 && $kwRepo->find($id1)['status'] === 'pause', 'Bulk pause works');

	$csvOut = $kwRepo->exportCsv();
	p4_assert(str_contains($csvOut, "'=cmd()"), 'CSV formula injection escaped');
}, $results);

// 3. Keyword Intelligence: Intent, Clustering, Cannibalization, No Fake Metrics
$kwIntel = new KeywordIntelligence($wpdb);
p4_test('KW_02_IntentClusterAndCannibalization', function () use ($kwIntel): void {
	$intent = $kwIntel->classifyIntentHeuristic('mua camera hành trình ô tô HTD');
	p4_assert($intent['intent'] === 'commercial_investigation' && $intent['metrics']['search_volume'] === 'NOT_AVAILABLE', 'No fabricated volume/CPC');

	$clusters = $kwIntel->clusterByTopic(['camera hành trình C22', 'android box D15']);
	p4_assert(isset($clusters['camera-hanh-trinh-o-to']) && isset($clusters['android-box-o-to']), 'Clustered HTD topics');

	$kwIntel->saveMapping(1, 'https://htd.net.vn/san-pham/d15/', 101, 'd15', 'commercial');
	$cannibal = $kwIntel->detectCannibalization('https://htd.net.vn/san-pham/d15/', 2);
	p4_assert($cannibal === 'POSSIBLE_CANNIBALIZATION', 'Cannibalization detected');
}, $results);

// 4. Content Engine, QA, Export, MASTER PRODUCT HTD
$draftRepo = new ContentDraftRepository($wpdb, $logger);
$qaEngine = new ContentQAEngine($brand);
$contentEngine = new ContentWorkflowEngine($draftRepo, $qaEngine, $approvals, $kwIntel);

p4_test('CONT_01_WorkflowQaApprovalAndExport', function () use ($contentEngine, $draftRepo): void {
	$res = $contentEngine->generateDraft([
		'keyword_id'       => 1,
		'primary_keyword'  => 'camera hành trình ô tô HTD',
		'suggested_title'  => 'Camera hành trình ô tô HTD chính hãng',
		'meta_description' => str_repeat('Camera hành trình ô tô HTD sắc nét hỗ trợ lái xe an toàn trên mọi cung đường Việt Nam. ', 2),
		'article_html'     => '<h2>Giới thiệu</h2><p>Camera hành trình ô tô HTD ghi hình 4K.</p><script>alert(1)</script>',
		'master_product'   => ['resolution' => '4K'],
		'site_product'     => ['resolution' => '1080p'],
	]);
	p4_assert(!empty($res['draft_id']) && !empty($res['approval_id']), 'Draft & approval created');
	p4_assert(!str_contains($res['article_html'], '<script'), 'Unsafe HTML sanitized');
	p4_assert(!empty($res['verification_warnings']), 'MASTER PRODUCT conflict flagged');
	p4_assert($res['qa_status'] === 'NEEDS_VERIFICATION', 'QA status reflects verification warning');

	$md = $draftRepo->exportMarkdown((int) $res['draft_id']);
	p4_assert(str_contains($md, '## Giới thiệu'), 'Markdown export works');
}, $results);

// 5. NEW/UPDATE Decision Engine
p4_test('DEC_01_NewUpdateCannibalization', function (): void {
	$dec = new NewUpdateDecisionEngine();
	$posts = [
		['id' => 10, 'title' => 'Android Box ô tô HTD D15', 'url' => 'https://htd.net.vn/d15/', 'intent' => 'commercial_investigation'],
	];
	$u = $dec->decide('android box d15', 'https://htd.net.vn/d15/', 'commercial_investigation', $posts);
	p4_assert($u['decision'] === NewUpdateDecisionEngine::DECISION_UPDATE, 'Prefers UPDATE on URL match');
	$n = $dec->decide('cảm biến áp suất lốp', '', 'informational', $posts);
	p4_assert($n['decision'] === NewUpdateDecisionEngine::DECISION_NEW, 'Returns NEW when no match');
}, $results);

// 6. Rewrite Engine: Snapshot, Diff, Preservation, Block Tampering
$rewriteEngine = new RewriteEngine($wpdb, $draftRepo, $approvals, $logger);
p4_test('REW_01_SnapshotDiffAndPreservation', function () use ($rewriteEngine): void {
	$orig = [
		'id' => 55, 'url' => 'https://htd.net.vn/bai-viet/', 'slug' => 'bai-viet',
		'canonical' => 'https://htd.net.vn/bai-viet/', 'title' => 'Bài cũ',
		'html' => '<h2>Phần 1</h2><p>Nội dung <a href="https://htd.net.vn/a">A</a></p>',
	];
	p4_throws(fn() => $rewriteEngine->proposeRewrite($orig, ['slug' => 'slug-moi', 'html' => '<p>x</p>']), 'Slug change blocked');
	p4_throws(fn() => $rewriteEngine->proposeRewrite($orig, ['price' => 999, 'html' => '<p>x</p>']), 'Price change blocked');

	$res = $rewriteEngine->proposeRewrite($orig, [
		'title' => 'Bài mới tối ưu',
		'html'  => '<h2>Phần 1</h2><h2>Phần 2</h2><p>Nội dung <a href="https://htd.net.vn/a">A</a></p>',
	]);
	p4_assert($res['preserved']['slug'] === 'bai-viet' && $res['risk_level'] === RewriteEngine::RISK_MEDIUM, 'Preserved slug and computed diff');
}, $results);

// 7. Media & Video Validation
$imgVal = new ImageValidator();
$mediaSvc = new MediaProposalService($wpdb, $imgVal, $providers, $logger);
p4_test('MED_01_ImageSecurityAndVideoEmbed', function () use ($imgVal, $mediaSvc): void {
	// Valid 1x1 PNG bytes
	$png1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
	p4_assert($imgVal->validateBinary($png1x1, 'camera.png') === 'image/png', 'Valid PNG accepted');

	p4_throws(fn() => $imgVal->validateBinary('<?php system($_GET["c"]); ?>', 'shell.php.jpg'), 'PHP double extension rejected');
	p4_throws(fn() => $imgVal->validateBinary('<svg onload=alert(1)>', 'xss.svg'), 'Malicious SVG rejected');
	p4_throws(fn() => $imgVal->validateRemoteImageUrl('https://127.0.0.1/a.png'), 'SSRF loopback rejected');
	p4_throws(fn() => $imgVal->validateRemoteImageUrl('https://softviet.vn/a.png'), 'SoftViet image URL rejected');
	p4_throws(fn() => $mediaSvc->assertImageProviderCapable('claude'), 'Claude rejected for IMAGE_GENERATION');

	$vid = $mediaSvc->proposeVideoEmbed(1, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Hướng dẫn lắp đặt');
	p4_assert(str_contains($vid['embed'], '[embed]https://www.youtube.com'), 'Safe YouTube embed generated');
	p4_throws(fn() => $mediaSvc->proposeVideoEmbed(1, 'https://evil.example.com/v.mp4', 'Bad'), 'Non-allowlisted video rejected');
}, $results);

// 8. Internal Link Proposal Engine & Batch Queue Boundary
p4_test('LINK_01_InternalLinksAndBatchBoundary', function () use ($brand, $wpdb, $settings, $logger): void {
	$linker = new InternalLinkProposalEngine($brand);
	$links = $linker->propose('camera hành trình ô tô', 'https://htd.net.vn/bai-viet-hien-tai/', [
		['url' => 'https://htd.net.vn/bai-viet-hien-tai/', 'title' => 'camera hành trình ô tô'],
		['url' => 'https://htd.net.vn/camera-c22/', 'title' => 'Camera hành trình ô tô C22'],
	]);
	foreach ($links as $l) {
		p4_assert($l['url'] !== 'https://htd.net.vn/bai-viet-hien-tai/', 'Self-link prevented');
	}
	p4_assert(count($links) >= 2, 'Priority HTD URLs and matching posts proposed');

	$queue = new QueueEngine($wpdb, $settings, $logger);
	$jid = $queue->enqueue('content_batch', ['keyword_id' => 1], 'batch-kw-1');
	$settings->update(['emergency_stop' => true]);
	p4_assert($queue->claimNext('w1') === null, 'Emergency stop halts batch worker');
	$settings->update(['emergency_stop' => false]);
	p4_assert($queue->cancel($jid) === true, 'Batch job cancelled cleanly');
}, $results);

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($results['fail'] > 0 ? 1 : 0);
