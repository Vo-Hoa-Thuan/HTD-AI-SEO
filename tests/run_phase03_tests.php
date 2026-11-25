<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use HTD\AISEO\AI\AIError;
use HTD\AISEO\AI\AIException;
use HTD\AISEO\AI\AIOrchestrator;
use HTD\AISEO\AI\AIRequest;
use HTD\AISEO\AI\Capability;
use HTD\AISEO\AI\ModelRegistry;
use HTD\AISEO\AI\OutputValidator;
use HTD\AISEO\AI\ProviderConfiguration;
use HTD\AISEO\AI\ProviderRegistry;
use HTD\AISEO\AI\Providers\ClaudeProvider;
use HTD\AISEO\AI\Providers\DeepSeekProvider;
use HTD\AISEO\AI\Providers\GeminiProvider;
use HTD\AISEO\AI\Providers\GrokProvider;
use HTD\AISEO\AI\Providers\OpenAIProvider;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Database\Migrator;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Prompt\DefaultPromptLibrary;
use HTD\AISEO\Prompt\PromptRegistry;
use HTD\AISEO\Prompt\PromptRenderer;
use HTD\AISEO\Prompt\PromptTemplate;
use HTD\AISEO\Security\ExternalRequestGuard;
use HTD\AISEO\Settings\SettingsRegistry;
use HTD\AISEO\Token\TokenBudgetManager;

$results = ['pass' => 0, 'fail' => 0, 'cases' => []];

function p3_test(string $name, callable $fn, array &$results): void {
	try {
		$fn();
		$results['pass']++;
		$results['cases'][] = ['name' => $name, 'status' => 'PASS'];
	} catch (\Throwable $e) {
		$results['fail']++;
		$results['cases'][] = ['name' => $name, 'status' => 'FAIL', 'error' => $e->getMessage()];
	}
}

function p3_assert(bool $cond, string $msg): void {
	if (!$cond) {
		throw new \RuntimeException("Assertion failed: {$msg}");
	}
}

function p3_throws(callable $fn, string $msg): void {
	try {
		$fn();
	} catch (\Throwable) {
		return;
	}
	throw new \RuntimeException("Expected exception not thrown: {$msg}");
}

$wpdb = new TestWpdb('wp_');
(new Migrator($wpdb))->migrate();
$settings = new SettingsRegistry();
$settings->ensureDefaults();
$logger = new Logger($wpdb);
$httpGuard = new ExternalRequestGuard($settings, $logger);
$models = new ModelRegistry();

// 1. AI Providers: Request building, auth headers, response & usage parsing, error normalization
p3_test('AI_01_AllFiveAdaptersBuildAndParseMocks', function () use ($httpGuard, $models): void {
	$openai = new OpenAIProvider($httpGuard, $models);
	$req = new AIRequest('openai', 'gpt-4o-mini', [['role' => 'user', 'content' => 'hi']]);
	$http = $openai->buildHttpPayload($req, 'sk-test-key');
	p3_assert($http['url'] === 'https://api.openai.com/v1/chat/completions', 'OpenAI URL');
	p3_assert($http['headers']['Authorization'] === 'Bearer sk-test-key', 'OpenAI auth header');
	$res = $openai->parseHttpResponse(200, json_encode([
		'choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']],
		'usage'   => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
	]), $req);
	p3_assert($res->parsedJson['ok'] === true && $res->usage->totalTokens === 150, 'OpenAI parsed response & usage');

	$gemini = new GeminiProvider($httpGuard, $models);
	$gReq = new AIRequest('gemini', 'gemini-2.5-flash', [['role' => 'system', 'content' => 'sys'], ['role' => 'user', 'content' => 'hi']]);
	$gHttp = $gemini->buildHttpPayload($gReq, 'AIzaTestKey');
	p3_assert(str_contains($gHttp['url'], 'generativelanguage.googleapis.com') && $gHttp['headers']['x-goog-api-key'] === 'AIzaTestKey', 'Gemini URL & header');
	$gRes = $gemini->parseHttpResponse(200, json_encode([
		'candidates'    => [['content' => ['parts' => [['text' => '{"intent":"commercial"}']]], 'finishReason' => 'STOP']],
		'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 10, 'totalTokenCount' => 30],
	]), $gReq);
	p3_assert($gRes->parsedJson['intent'] === 'commercial' && $gRes->usage->totalTokens === 30, 'Gemini parsed');

	$claude = new ClaudeProvider($httpGuard, $models);
	$cReq = new AIRequest('claude', 'claude-sonnet-4-20250514', [['role' => 'user', 'content' => 'hi']]);
	$cHttp = $claude->buildHttpPayload($cReq, 'sk-ant-test');
	p3_assert($cHttp['headers']['x-api-key'] === 'sk-ant-test', 'Claude header');
	$cRes = $claude->parseHttpResponse(200, json_encode([
		'content' => [['text' => '{"score":90}']],
		'usage'   => ['input_tokens' => 15, 'output_tokens' => 5],
	]), $cReq);
	p3_assert($cRes->parsedJson['score'] === 90 && $cRes->usage->totalTokens === 20, 'Claude parsed');

	$deepseek = new DeepSeekProvider($httpGuard, $models);
	$dReq = new AIRequest('deepseek', 'deepseek-chat', [['role' => 'user', 'content' => 'hi']]);
	p3_assert($deepseek->buildHttpPayload($dReq, 'ds-key')['url'] === 'https://api.deepseek.com/chat/completions', 'DeepSeek URL');

	$grok = new GrokProvider($httpGuard, $models);
	$xReq = new AIRequest('grok', 'grok-3-mini', [['role' => 'user', 'content' => 'hi']]);
	p3_assert($grok->buildHttpPayload($xReq, 'xai-key')['url'] === 'https://api.x.ai/v1/chat/completions', 'Grok URL');
}, $results);

p3_test('AI_02_ErrorNormalizationAndCapabilityCheck', function () use ($httpGuard, $models): void {
	$openai = new OpenAIProvider($httpGuard, $models);
	$req = new AIRequest('openai', 'gpt-4o-mini', [['role' => 'user', 'content' => 'hi']]);
	try {
		$openai->parseHttpResponse(401, '{"error":"invalid_api_key"}', $req);
		p3_assert(false, 'Should throw');
	} catch (AIException $e) {
		p3_assert($e->errorCode === AIError::INVALID_CREDENTIAL, '401 maps to INVALID_CREDENTIAL');
	}
	try {
		$openai->parseHttpResponse(429, '{"error":"rate limit"}', $req);
		p3_assert(false, 'Should throw');
	} catch (AIException $e) {
		p3_assert($e->errorCode === AIError::RATE_LIMITED, '429 maps to RATE_LIMITED');
	}
	$claude = new ClaudeProvider($httpGuard, $models);
	$imgReq = new AIRequest('claude', 'claude-sonnet-4-20250514', [['role' => 'user', 'content' => 'img']], requiredCapabilities: [Capability::IMAGE_GENERATION]);
	try {
		$claude->send($imgReq, 'sk-ant-test');
		p3_assert(false, 'Should throw');
	} catch (AIException $e) {
		p3_assert($e->errorCode === AIError::UNSUPPORTED_CAPABILITY, 'Unsupported capability rejected');
	}
}, $results);

// 2. Local Prompt Engine & 20 Templates
p3_test('PROMPT_01_Default20TemplatesAndRendering', function (): void {
	$all = DefaultPromptLibrary::all();
	p3_assert(count($all) === 20, '20 default HTD prompt templates exist');
	$reg = new PromptRegistry(new PromptRenderer());
	$msgs = $reg->buildPrompt('keyword_analysis', ['keyword' => 'camera hành trình ô tô HTD']);
	p3_assert(count($msgs) === 2 && str_contains($msgs[1]['content'], '<untrusted_data field="keyword">'), 'Untrusted data wrapped');
	p3_throws(fn() => $reg->buildPrompt('keyword_analysis', []), 'Missing required variable rejected');
}, $results);

p3_test('PROMPT_02_SafetyGuardVersioningImportExport', function (): void {
	$reg = new PromptRegistry(new PromptRenderer());
	$bad = PromptTemplate::fromArray([
		'id' => 'bad', 'name' => 'Bad', 'task_type' => 't',
		'system_instructions' => '<?php echo 1; ?>', 'user_template' => 'hi',
	]);
	p3_throws(fn() => $reg->save($bad), 'PHP in prompt template rejected');

	$dup = $reg->duplicate('meta_title', 'meta_title_v2', 'Meta Title V2');
	p3_assert($dup->id === 'meta_title_v2', 'Duplicate succeeded');
	$edited = $dup->toArray();
	$edited['version'] = '2.0.0';
	$reg->save(PromptTemplate::fromArray($edited));
	$restored = $reg->restoreVersion('meta_title_v2', '1.0.0');
	p3_assert($restored->version === '1.0.0', 'Version restored');

	$json = $reg->exportJson('meta_title_v2');
	$imported = $reg->importJson($json);
	p3_assert($imported->id === 'meta_title_v2', 'JSON export/import roundtrip');
}, $results);

// 3. Brand Profile
p3_test('BRAND_01_DefaultsAndConfig', function (): void {
	$brand = new BrandProfile();
	$b = $brand->get();
	p3_assert($b['brand_name'] === 'HTD Việt Nam' && count($b['priority_urls']) === 2, 'Brand defaults present');
	p3_assert(str_contains($brand->toPromptContext(), 'Camera hành trình ô tô'), 'Prompt context includes category');
}, $results);

// 4. Token Budget Manager
p3_test('TOKEN_01_BudgetTrimmingAndCaching', function (): void {
	$cfg = new ProviderConfiguration();
	$cfg->save('openai', ['enabled' => true, 'daily_budget_usd' => 0.01]);
	$tm = new TokenBudgetManager($cfg);
	$trimmed = $tm->trimContext(['alpha', 'alpha', 'beta', str_repeat('x', 500)], 20);
	p3_assert($trimmed === ['alpha', 'beta'], 'Context deduplicated and trimmed to budget');

	$tm->recordUsage('openai', 'gpt-4o-mini', 'meta_title', 1000, 500, 0.02, true, false);
	p3_throws(fn() => $tm->assertBudgetAvailable('openai'), 'Daily budget exceeded enforced');
}, $results);

// 5. Output Validator
p3_test('VAL_01_SchemaAndHtmlSanitization', function (): void {
	$val = new OutputValidator();
	$res = new \HTD\AISEO\AI\AIResponse(
		'openai', 'gpt-4o-mini', '{}',
		['title' => 'Camera HTD', 'html_content' => '<h2>OK</h2><script>alert(1)</script>'],
		new \HTD\AISEO\AI\UsageMetadata(10, 10, 20)
	);
	$clean = $val->validate($res, ['title' => 'string', 'html_content' => 'string']);
	p3_assert(!str_contains($clean['html_content'], '<script'), 'HTML sanitized');

	$badRes = new \HTD\AISEO\AI\AIResponse(
		'openai', 'gpt-4o-mini', '{}',
		['title' => 123],
		new \HTD\AISEO\AI\UsageMetadata(10, 10, 20)
	);
	p3_throws(fn() => $val->validate($badRes, ['title' => 'string']), 'Type mismatch rejected');
}, $results);

// 6. AIOrchestrator end-to-end mock guardrails
p3_test('ORCH_01_BlocksWhenExternalDisabledOrEmergencyStop', function () use ($settings, $wpdb, $logger): void {
	$vault = new CredentialVault();
	$cfg = new ProviderConfiguration();
	$cfg->save('openai', ['enabled' => true, 'daily_budget_usd' => 10.0]);
	$reg = new ProviderRegistry();
	$orch = new AIOrchestrator(
		$settings, $reg, $cfg, $vault,
		new PromptRegistry(new PromptRenderer()), new PromptRenderer(),
		new BrandProfile(), new TokenBudgetManager($cfg), new OutputValidator(), $logger
	);
	p3_throws(fn() => $orch->executeTask('openai', 'meta_title', ['keyword' => 'k', 'topic' => 't'], true), 'Blocked when allow_external_ai_requests=false');
}, $results);

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($results['fail'] > 0 ? 1 : 0);
