<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use HTD\AISEO\AI\AIRequest;
use HTD\AISEO\AI\ModelRegistry;
use HTD\AISEO\AI\Providers\CustomOpenAICompatibleProvider;
use HTD\AISEO\Analytics\GA4Adapter;
use HTD\AISEO\Analytics\GSCAdapter;
use HTD\AISEO\Analytics\SEODataRepository;
use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Database\Migrator;
use HTD\AISEO\Growth\CategorySEOEngine;
use HTD\AISEO\Growth\CompetitorAndAISearchEngine;
use HTD\AISEO\Growth\GrowthAnalyticsEngine;
use HTD\AISEO\Growth\OneClickAuditWorkflow;
use HTD\AISEO\Growth\OpportunityAndRoadmapEngine;
use HTD\AISEO\Growth\TechnicalAuditAndTrafficGuard;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Queue\QueueEngine;
use HTD\AISEO\Security\ExternalRequestGuard;
use HTD\AISEO\Settings\SettingsRegistry;
use HTD\AISEO\WooCommerce\MasterProductRepository;

$results = ['pass' => 0, 'fail' => 0, 'cases' => []];

function p6_test(string $name, callable $fn, array &$results): void {
	try {
		$fn();
		$results['pass']++;
		$results['cases'][] = ['name' => $name, 'status' => 'PASS'];
	} catch (\Throwable $e) {
		$results['fail']++;
		$results['cases'][] = ['name' => $name, 'status' => 'FAIL', 'error' => $e->getMessage()];
	}
}

function p6_assert(bool $cond, string $msg): void {
	if (!$cond) {
		throw new \RuntimeException("Assertion failed: {$msg}");
	}
}

function p6_throws(callable $fn, string $msg): void {
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
$vault = new CredentialVault();
$models = new ModelRegistry();

// 1. DB v0.6.0 Migration & Rollback
p6_test('DB6_01_V060MigrationAndRollback', function () use ($migrator): void {
	p6_assert($migrator->currentVersion() === '0.6.0', 'DB version is 0.6.0');
	p6_assert($migrator->rollbackVersion('0.6.0') === true, 'Rollback 0.6.0 -> 0.5.0');
	p6_assert($migrator->currentVersion() === '0.5.0', 'Version is 0.5.0');
	p6_assert($migrator->migrate() === true, 'Re-migrated to 0.6.0');
}, $results);

// 2. FEAT_002: Custom Base URL (HTTPS, SoftViet block, SSRF block, isolated cred)
p6_test('FEAT002_CustomBaseUrlSecurityAndParsing', function () use ($httpGuard, $models, $settings): void {
	$custom = new CustomOpenAICompatibleProvider($httpGuard, $models);
	p6_throws(fn() => $custom->setBaseUrl('http://api.custom.com/v1'), 'HTTP rejected');
	p6_throws(fn() => $custom->setBaseUrl('https://ai.softviet.vn/v1'), 'SoftViet rejected');

	$custom->setBaseUrl('https://llm.htd-internal.example.com/v1');
	$req = new AIRequest('custom', 'custom-model', [['role' => 'user', 'content' => 'hi']]);
	$http = $custom->buildHttpPayload($req, 'custom-isolated-key');
	p6_assert($http['url'] === 'https://llm.htd-internal.example.com/v1/chat/completions', 'Custom endpoint built');
	p6_assert($http['headers']['Authorization'] === 'Bearer custom-isolated-key', 'Uses isolated key');

	$settings->update(['allow_external_ai_requests' => true]);
	$httpGuard->validateUrl($http['url']);
	p6_throws(fn() => $httpGuard->validateUrl('https://169.254.169.254/latest/meta-data/'), 'Metadata SSRF still blocked');
	$settings->update(['allow_external_ai_requests' => false]);
}, $results);

// 3. GSC Adapter & CSV Report Import
$seoRepo = new SEODataRepository($wpdb, $logger);
$gsc = new GSCAdapter($httpGuard, $vault, $seoRepo);
p6_test('GSC_01_ApiContractAndCsvImport', function () use ($gsc): void {
	$payload = $gsc->buildSearchAnalyticsPayload('https://htd.net.vn/', '2026-10-01', '2026-10-28', ['query', 'page'], 500, 0, 'tok');
	p6_assert(str_contains($payload['url'], 'searchconsole.googleapis.com'), 'Official GSC URL');
	$parsed = $gsc->parseSearchAnalyticsResponse(200, json_encode([
		'rows' => [['keys' => ['camera hành trình ô tô', 'https://htd.net.vn/danh-muc-san-pham/camera-hanh-trinh-o-to/'], 'clicks' => 45, 'impressions' => 3000, 'ctr' => 0.015, 'position' => 6.2]],
	]));
	p6_assert(count($parsed) === 1 && $parsed[0]['impressions'] === 3000, 'GSC response parsed');
	p6_throws(fn() => $gsc->parseSearchAnalyticsResponse(429, '{}'), '429 quota error handled');

	$imp = $gsc->importCsvReport("query,page,clicks,impressions,ctr,position\ncamera hành trình ô tô,https://htd.net.vn/danh-muc-san-pham/camera-hanh-trinh-o-to/,20,2500,0.008,5.4", 'https://htd.net.vn/', 'queries', '28d');
	p6_assert($imp['row_count'] === 1, 'GSC CSV imported');
}, $results);

// 4. GA4 Adapter
$ga4 = new GA4Adapter($httpGuard, $vault, $seoRepo);
p6_test('GA4_01_ApiContractAndParsing', function () use ($ga4): void {
	$payload = $ga4->buildRunReportPayload('123456789', '2026-10-01', '2026-10-28', 'tok');
	p6_assert(str_contains($payload['url'], 'analyticsdata.googleapis.com/v1beta/properties/123456789:runReport'), 'Official GA4 URL');
	$rows = $ga4->parseRunReportResponse(200, json_encode([
		'rows' => [['dimensionValues' => [['value' => '/android-box/'], ['value' => 'Organic Search']], 'metricValues' => [['value' => '100'], ['value' => '120'], ['value' => '80'], ['value' => '0.666'], ['value' => '200'], ['value' => '95.5'], ['value' => '5']]]],
	]));
	p6_assert($rows[0]['sessions'] === 120 && $rows[0]['key_events'] === 5, 'GA4 metrics parsed');
}, $results);

// 5. Category SEO Engine
$masterRepo = new MasterProductRepository($wpdb, $logger);
$catSeo = new CategorySEOEngine($wpdb, $brand, $masterRepo, $approvals, $logger);
p6_test('CAT_01_CategoryAuditAndProposals', function () use ($catSeo): void {
	$res = $catSeo->auditAndPropose([
		'slug'             => 'camera-hanh-trinh-o-to',
		'url'              => 'https://htd.net.vn/danh-muc-san-pham/camera-hanh-trinh-o-to/',
		'meta_description' => '',
		'intro_html'       => '',
		'faq_count'        => 0,
		'canonical'        => 'https://htd.net.vn/danh-muc-san-pham/camera-hanh-trinh-o-to/',
	]);
	p6_assert(!empty($res['approval_id']) && count($res['proposals']) === 3, 'Category proposals & approval created');
}, $results);

// 6. Content Opportunities & 6-Column Roadmap Export
$oppEngine = new OpportunityAndRoadmapEngine($wpdb);
p6_test('OPP_01_DetectionAnd6ColumnRoadmap', function () use ($oppEngine): void {
	$insuff = $oppEngine->detectFromGsc([]);
	p6_assert($insuff[0]['opportunity_type'] === 'INSUFFICIENT_DATA', 'Empty GSC returns INSUFFICIENT_DATA');

	$opps = $oppEngine->detectFromGsc([
		['query' => 'camera hành trình 4K', 'page' => 'https://htd.net.vn/cam-4k/', 'clicks' => 10, 'impressions' => 1500, 'ctr' => 0.0067, 'position' => 6.0],
	]);
	p6_assert($opps[0]['priority'] === 'P0' && $opps[0]['opportunity_type'] === 'HIGH_IMPRESSIONS_LOW_CTR', 'P0 CTR opportunity detected');

	$csv = $oppEngine->exportRoadmapCsv($opps, 'HTD Team', 30);
	$header = str_getcsv(explode("\n", trim($csv))[0]);
	p6_assert($header === ['TỚI NGÀY', 'NVPT', 'LOẠI BÀI ĐĂNG', 'TODO', 'LINK CONTENT', 'SOURCE'], 'Exact 6-column roadmap export verified');
}, $results);

// 7. Competitor & AI Search Observation
$compAio = new CompetitorAndAISearchEngine($wpdb);
p6_test('AIO_01_CompetitorAndAISearchObservation', function () use ($compAio): void {
	$comp = $compAio->recordCompetitorObservation('android box ô tô', 'https://htd.net.vn/android-box/', 'https://competitor.example.com/box/', ['title' => 'A', 'headings' => ['H2 A'], 'faq_count' => 1], ['title' => 'B', 'headings' => ['H2 A', 'H2 So sánh'], 'faq_count' => 3]);
	p6_assert(in_array('h2 so sánh', $comp['observation']['topic_gaps'], true), 'Topic gap detected');

	$aioId = $compAio->recordAISearchObservation('camera hành trình ô tô', 'camera hành trình ô tô loại nào tốt', 'OBSERVED', ['https://htd.net.vn/danh-muc-san-pham/camera-hanh-trinh-o-to/']);
	p6_assert($aioId > 0, 'Factual AI Search observation saved');

	$ready = $compAio->auditReadiness(['article_html' => '<h2>OK</h2>', 'faq' => [['q' => 'x']], 'internal_link_proposals' => [['url' => 'u']], 'source_references' => ['HTD']]);
	p6_assert($ready['readiness_ratio'] === 1.0, 'Rule-based readiness computed');
}, $results);

// 8. Technical Audit, Traffic Protection, Growth Analytics & One-Click Audit
$techGuard = new TechnicalAuditAndTrafficGuard();
$growth = new GrowthAnalyticsEngine($wpdb);
$queue = new QueueEngine($wpdb, $settings, $logger);
$oneClick = new OneClickAuditWorkflow($settings, $queue, $seoRepo, $catSeo, $oppEngine, $techGuard, $logger);

p6_test('GROWTH_01_TechTrafficProtectionAndOneClickAudit', function () use ($techGuard, $growth, $oneClick, $settings): void {
	$prot = $techGuard->checkTrafficProtection('https://htd.net.vn/huong-dan-xem-youtube-tren-android-auto-cap-nhat-2024/');
	p6_assert($prot['protected'] === true, 'Protected high-traffic HTD URL');

	$delta = $growth->comparePeriods(['clicks' => 100, 'impressions' => 2000, 'sessions' => 120], ['clicks' => 150, 'impressions' => 2500, 'sessions' => 170]);
	p6_assert($delta['clicks_delta'] === 50 && str_contains($delta['causality_note'], 'CORRELATION_ONLY'), 'Growth comparison includes causality disclaimer');

	$audit = $oneClick->runAudit(
		[['slug' => 'android-box-o-to', 'url' => 'https://htd.net.vn/danh-muc-san-pham/android-box-o-to/']],
		[['url' => 'https://htd.net.vn/404-test/', 'http_status' => 404, 'title' => 'Missing']]
	);
	p6_assert($audit['status'] === 'COMPLETED_READ_ONLY' && str_starts_with($audit['steps']['ga4'], 'BLOCKED_BY_CONFIGURATION'), 'One-click audit respects unconfigured sources');

	$settings->update(['emergency_stop' => true]);
	$blocked = $oneClick->runAudit();
	p6_assert($blocked['status'] === 'BLOCKED_BY_EMERGENCY_STOP', 'Emergency Stop blocks one-click audit');
	$settings->update(['emergency_stop' => false]);
}, $results);

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($results['fail'] > 0 ? 1 : 0);
