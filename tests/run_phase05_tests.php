<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use HTD\AISEO\AI\ModelRegistry;
use HTD\AISEO\AI\ProviderRegistry;
use HTD\AISEO\AI\Providers\OpenAIProvider;
use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Content\ContentDraftRepository;
use HTD\AISEO\Content\ContentQAEngine;
use HTD\AISEO\Content\ContentWorkflowEngine;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Database\Migrator;
use HTD\AISEO\Keyword\KeywordIntelligence;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Media\ImageGenerationService;
use HTD\AISEO\Media\ImageValidator;
use HTD\AISEO\Media\MediaLibraryReader;
use HTD\AISEO\Publishing\PublishingEngine;
use HTD\AISEO\Queue\CronScheduler;
use HTD\AISEO\Queue\QueueEngine;
use HTD\AISEO\Queue\WorkerEngine;
use HTD\AISEO\Rewrite\RewriteEngine;
use HTD\AISEO\Security\ExternalRequestGuard;
use HTD\AISEO\Security\MutationGuard;
use HTD\AISEO\SEO\InternalLinkEngine;
use HTD\AISEO\SEO\InternalLinkProposalEngine;
use HTD\AISEO\SEO\RankMathAdapter;
use HTD\AISEO\SEO\SeoEnhancer;
use HTD\AISEO\Settings\SettingsRegistry;
use HTD\AISEO\WooCommerce\MasterProductRepository;
use HTD\AISEO\WooCommerce\ProductEngine;
use HTD\AISEO\WooCommerce\WooCommerceAdapter;

$results = ['pass' => 0, 'fail' => 0, 'cases' => []];

function p5_test(string $name, callable $fn, array &$results): void {
	try {
		$fn();
		$results['pass']++;
		$results['cases'][] = ['name' => $name, 'status' => 'PASS'];
	} catch (\Throwable $e) {
		$results['fail']++;
		$results['cases'][] = ['name' => $name, 'status' => 'FAIL', 'error' => $e->getMessage()];
	}
}

function p5_assert(bool $cond, string $msg): void {
	if (!$cond) {
		throw new \RuntimeException("Assertion failed: {$msg}");
	}
}

function p5_throws(callable $fn, string $msg): void {
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
$guard = new MutationGuard($settings, $approvals, $logger);
$httpGuard = new ExternalRequestGuard($settings, $logger);
$models = new ModelRegistry();
$providers = new ProviderRegistry();
$providers->register(new OpenAIProvider($httpGuard, $models));
$vault = new CredentialVault();

// SCENARIO G: Upgrade 0.4.0 -> 0.5.0 & Rollback
p5_test('DB5_01_V050MigrationAndRollback', function () use ($migrator): void {
	p5_assert($migrator->currentVersion() === '0.5.0', 'DB version is 0.5.0');
	p5_assert($migrator->rollbackVersion('0.5.0') === true, 'Rollback 0.5.0 -> 0.4.0');
	p5_assert($migrator->currentVersion() === '0.4.0', 'Version is 0.4.0');
	p5_assert($migrator->migrate() === true, 'Re-upgrade to 0.5.0');
}, $results);

// SCENARIO A: Keyword -> Content -> QA -> Approval -> Idempotent Publishing (with test-double writer)
$draftRepo = new ContentDraftRepository($wpdb, $logger);
$qaEngine = new ContentQAEngine($brand);
$kwIntel = new KeywordIntelligence($wpdb);
$contentEngine = new ContentWorkflowEngine($draftRepo, $qaEngine, $approvals, $kwIntel);
$rankMath = new RankMathAdapter();
$pubEngine = new PublishingEngine($wpdb, $draftRepo, $approvals, $guard, $rankMath, $logger);

p5_test('PUB_01_ScenarioA_SafePublishingAndIdempotency', function () use ($contentEngine, $approvals, $pubEngine, $settings): void {
	$res = $contentEngine->generateDraft([
		'keyword_id'       => 1,
		'primary_keyword'  => 'camera hành trình ô tô HTD',
		'suggested_title'  => 'Camera hành trình ô tô HTD C22',
		'meta_description' => str_repeat('Camera hành trình ô tô HTD C22 ghi hình 4K siêu nét, hỗ trợ cảnh báo giao thông an toàn tại Việt Nam. ', 2),
		'article_html'     => '<h2>Đánh giá</h2><p>Camera hành trình ô tô HTD C22.</p>',
	]);
	$draftId = (int) $res['draft_id'];
	$approvalId = (int) $res['approval_id'];

	// Blocked while WP_READ_ONLY_MODE=true
	p5_throws(fn() => $pubEngine->execute($draftId, $approvalId, 'draft', 'idem-1', false, null, fn() => 999), 'Blocked by READ_ONLY');

	$settings->update(['wp_read_only_mode' => false]);
	$approvals->approve($approvalId, 1, 'Approved for draft');

	$writes = 0;
	$writer = static function (array $args) use (&$writes): int {
		$writes++;
		return 501;
	};
	$pub1 = $pubEngine->execute($draftId, $approvalId, 'draft', 'idem-pub-1', false, null, $writer);
	p5_assert($pub1['wp_post_id'] === 501 && $writes === 1, 'Published draft via test writer');

	// Idempotent replay does NOT write twice
	$pub2 = $pubEngine->execute($draftId, $approvalId, 'draft', 'idem-pub-1', false, null, $writer);
	p5_assert($pub2['idempotent_replay'] === true && $writes === 1, 'Idempotent replay prevented duplicate post');

	$settings->update(['wp_read_only_mode' => true]);
}, $results);

// SCENARIO B: Rewrite -> Snapshot -> Diff -> Approval -> Update -> URL preserved
$rewriteEngine = new RewriteEngine($wpdb, $draftRepo, $approvals, $logger);
p5_test('REW_02_ScenarioB_RewritePublishPreservesSlug', function () use ($rewriteEngine, $approvals, $pubEngine, $settings): void {
	$orig = [
		'id' => 77, 'url' => 'https://htd.net.vn/android-box-d15/', 'slug' => 'android-box-d15',
		'canonical' => 'https://htd.net.vn/android-box-d15/', 'title' => 'Android Box D15',
		'html' => '<h2>Cũ</h2><p>Nội dung cũ</p>',
	];
	$prop = $rewriteEngine->proposeRewrite($orig, ['title' => 'Android Box D15 Pro', 'html' => '<h2>Cũ</h2><h2>Mới</h2><p>Nội dung mới</p>']);
	$approvals->approve((int) $prop['approval_id'], 1);
	$settings->update(['wp_read_only_mode' => false]);

	$capturedSlug = '';
	$pub = $pubEngine->execute((int) $prop['draft_id'], (int) $prop['approval_id'], 'publish', 'idem-rew-77', false, null, static function (array $args) use (&$capturedSlug): int {
		$capturedSlug = $args['post_name'];
		return (int) $args['ID'];
	});
	p5_assert($pub['wp_post_id'] === 77 && $capturedSlug === 'android-box-d15', 'Preserved Post ID & Slug');
	$settings->update(['wp_read_only_mode' => true]);
}, $results);

// SCENARIO C: WooCommerce Product Engine + MASTER PRODUCT + Concurrent Modification Guard
$masterRepo = new MasterProductRepository($wpdb, $logger);
$wcAdapter = new WooCommerceAdapter();
$prodEngine = new ProductEngine($wpdb, $wcAdapter, $masterRepo, $approvals, $guard, $logger);

p5_test('WC_01_ScenarioC_ProductProtectionAndConcurrentHash', function () use ($masterRepo, $prodEngine, $approvals, $settings): void {
	$masterRepo->importJson(json_encode([
		['sku' => 'HTD-D15', 'model' => 'D15 Rocket', 'price' => '6500000', 'cpu' => 'Snapdragon 6125'],
	]));
	$wcProd = [
		'id' => 201, 'sku' => 'HTD-D15', 'regular_price' => '6200000',
		'stock_status' => 'instock', 'description' => 'Mô tả cũ', 'short_description' => 'Ngắn',
	];

	// Protected field rejection
	p5_throws(fn() => $prodEngine->proposeUpdate($wcProd, ['regular_price' => '100']), 'Protected price field rejected');

	$prop = $prodEngine->proposeUpdate($wcProd, ['description' => '<h2>Mô tả mới</h2><p>Chuẩn HTD</p>']);
	p5_assert(!empty($prop['conflicts']) && $prop['conflicts'][0]['field'] === 'price', 'MASTER PRODUCT price conflict detected');

	$approvals->approve((int) $prop['approval_id'], 1);
	$settings->update(['wp_read_only_mode' => false, 'allow_product_update' => true]);

	// Concurrent modification: price changed in WC after approval -> must abort!
	$modifiedWcProd = $wcProd;
	$modifiedWcProd['regular_price'] = '6900000';
	p5_throws(fn() => $prodEngine->executeApprovedUpdate((int) $prop['proposal_id'], $modifiedWcProd), 'Concurrent modification detected and blocked');

	$settings->update(['wp_read_only_mode' => true, 'allow_product_update' => false]);
}, $results);

// SCENARIO D: Rank Math, SeoEnhancer, and Internal Link Engine
$linkProposer = new InternalLinkProposalEngine($brand);
$linkEngine = new InternalLinkEngine($wpdb, $brand, $linkProposer, $approvals, $guard, $logger);
p5_test('SEO_01_ScenarioD_RankMathEnhancerAndInternalLinks', function () use ($rankMath, $linkEngine, $approvals, $settings): void {
	p5_assert($rankMath->isAvailable() === false, 'Rank Math gracefully unavailable without fatal');
	$enh = new SeoEnhancer();
	$an = $enh->analyze(['primary_keyword' => 'camera hành trình', 'suggested_title' => 'Camera hành trình HTD', 'meta_description' => str_repeat('a', 145), 'article_html' => '<h2>H2</h2>']);
	p5_assert($an['checks']['keyword_in_title'] === true && $an['checks']['has_h2_headings'] === true, 'SeoEnhancer checks pass');

	$linkEngine->indexPost(10, 'https://htd.net.vn/huong-dan-youtube-android-auto/', 'Hướng dẫn xem YouTube trên Android Auto', 'post', ['android-box'], []);
	$linkEngine->indexPost(11, 'https://htd.net.vn/android-box-d15/', 'Android Box D15', 'product', ['android-box'], []);
	p5_assert(count($linkEngine->detectOrphanContent()) === 2, 'Orphan content detected');

	$prop = $linkEngine->createProposal(10, 'https://htd.net.vn/huong-dan-youtube-android-auto/', 'android box');
	p5_assert(!empty($prop['suggestions']), 'Internal links proposed');

	$approvals->approve((int) $prop['approval_id'], 1);
	$settings->update(['wp_read_only_mode' => false]);
	$written = false;
	$ok = $linkEngine->executeApproved(10, (int) $prop['approval_id'], $prop['proposal_ids'], $prop['suggestions'], static function () use (&$written): void {
		$written = true;
	});
	p5_assert($ok && $written, 'Approved internal links executed via MutationGuard');
	$settings->update(['wp_read_only_mode' => true]);
}, $results);

// SCENARIO E & F: Media/AI Image Contract + WorkerEngine + CronScheduler + Emergency Stop
p5_test('AUTO_01_ScenarioEF_MediaContractWorkerAndCronSafety', function () use ($wpdb, $settings, $providers, $vault, $httpGuard, $logger): void {
	$imgSvc = new ImageGenerationService($settings, $providers, $vault, $httpGuard, new ImageValidator(), $logger);
	$payload = $imgSvc->buildOpenAiImagePayload('Camera hanh trinh HTD tren xe hoi', 'sk-test');
	p5_assert($payload['url'] === 'https://api.openai.com/v1/images/generations', 'Official OpenAI image endpoint');
	$pngB64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
	$parsed = $imgSvc->parseAndValidateImageResponse(json_encode(['data' => [['b64_json' => $pngB64]]]));
	p5_assert($parsed['mime'] === 'image/png', 'Parsed & validated AI image response');

	$lib = new MediaLibraryReader();
	$found = $lib->search('HTD', 10, [['id' => 1, 'title' => 'HTD C22', 'url' => 'https://htd.net.vn/c22.png', 'alt' => 'C22', 'mime' => 'image/png']]);
	p5_assert(count($found) === 1, 'MediaLibraryReader fixture search works');

	$queue = new QueueEngine($wpdb, $settings, $logger);
	$worker = new WorkerEngine($queue, $settings, $logger);
	$ran = 0;
	$worker->registerHandler('CONTENT_QA', static function () use (&$ran): void { $ran++; });
	$queue->enqueue('CONTENT_QA', ['draft_id' => 1], 'qa-job-1');

	// Cron skips when allow_background_content_generation=false
	$cron = new CronScheduler($worker, $settings, $logger);
	$st1 = $cron->runScheduled(2);
	p5_assert($st1['last_status'] === 'SKIPPED', 'Cron skipped by default safe setting');

	// Enable background, then test Emergency Stop blocks it
	$settings->update(['allow_background_content_generation' => true, 'emergency_stop' => true]);
	$st2 = $cron->runScheduled(2);
	p5_assert($st2['last_status'] === 'BLOCKED' && $ran === 0, 'Emergency Stop blocked cron execution');

	// Disable emergency stop -> worker processes job
	$settings->update(['emergency_stop' => false]);
	$st3 = $cron->runScheduled(2);
	p5_assert($st3['last_status'] === 'OK' && $ran === 1, 'Cron processed 1 job safely');
	$settings->update(['allow_background_content_generation' => false]);
}, $results);

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($results['fail'] > 0 ? 1 : 0);
