<?php
declare(strict_types=1);

namespace HTD\AISEO\Core;

use HTD\AISEO\Admin\AdminMenu;
use HTD\AISEO\AI\AIOrchestrator;
use HTD\AISEO\AI\ModelRegistry;
use HTD\AISEO\AI\OutputValidator;
use HTD\AISEO\AI\ProviderConfiguration;
use HTD\AISEO\AI\ProviderRegistry;
use HTD\AISEO\AI\Providers\ClaudeProvider;
use HTD\AISEO\AI\Providers\CustomOpenAICompatibleProvider;
use HTD\AISEO\AI\Providers\DeepSeekProvider;
use HTD\AISEO\AI\Providers\GeminiProvider;
use HTD\AISEO\AI\Providers\GrokProvider;
use HTD\AISEO\AI\Providers\OpenAIProvider;
use HTD\AISEO\Analytics\GA4Adapter;
use HTD\AISEO\Analytics\GSCAdapter;
use HTD\AISEO\Analytics\SEODataRepository;
use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Content\ContentDraftRepository;
use HTD\AISEO\Content\ContentQAEngine;
use HTD\AISEO\Content\ContentWorkflowEngine;
use HTD\AISEO\Content\NewUpdateDecisionEngine;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Database\Migrator;
use HTD\AISEO\Growth\CategorySEOEngine;
use HTD\AISEO\Growth\CompetitorAndAISearchEngine;
use HTD\AISEO\Growth\GrowthAnalyticsEngine;
use HTD\AISEO\Growth\OneClickAuditWorkflow;
use HTD\AISEO\Growth\OpportunityAndRoadmapEngine;
use HTD\AISEO\Growth\TechnicalAuditAndTrafficGuard;
use HTD\AISEO\Keyword\KeywordIntelligence;
use HTD\AISEO\Keyword\KeywordRepository;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Media\ImageGenerationService;
use HTD\AISEO\Media\ImageValidator;
use HTD\AISEO\Media\MediaLibraryReader;
use HTD\AISEO\Media\MediaProposalService;
use HTD\AISEO\Prompt\PromptRegistry;
use HTD\AISEO\Prompt\PromptRenderer;
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
use HTD\AISEO\Token\TokenBudgetManager;
use HTD\AISEO\WooCommerce\MasterProductRepository;
use HTD\AISEO\WooCommerce\ProductEngine;
use HTD\AISEO\WooCommerce\WooCommerceAdapter;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Main plugin coordinator and service container wiring.
 */
final class Plugin {
	private static ?self $instance = null;
	private Container $container;
	private ModuleRegistry $modules;
	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
		$this->modules   = new ModuleRegistry();
		$this->registerServices();
		$this->registerModules();
	}

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function registerServices(): void {
		$this->container->singleton(Migrator::class, static function (): Migrator {
			global $wpdb;
			return new Migrator($wpdb);
		});
		$this->container->singleton(SettingsRegistry::class, static function (): SettingsRegistry {
			return new SettingsRegistry();
		});
		$this->container->singleton(Logger::class, static function (): Logger {
			global $wpdb;
			return new Logger($wpdb);
		});
		$this->container->singleton(CredentialVault::class, static function (): CredentialVault {
			return new CredentialVault();
		});
		$this->container->singleton(ExternalRequestGuard::class, function (Container $c): ExternalRequestGuard {
			return new ExternalRequestGuard(
				$c->get(SettingsRegistry::class),
				$c->get(Logger::class)
			);
		});
		$this->container->singleton(ApprovalEngine::class, function (Container $c): ApprovalEngine {
			global $wpdb;
			return new ApprovalEngine($wpdb, $c->get(Logger::class));
		});
		$this->container->singleton(MutationGuard::class, function (Container $c): MutationGuard {
			return new MutationGuard(
				$c->get(SettingsRegistry::class),
				$c->get(ApprovalEngine::class),
				$c->get(Logger::class)
			);
		});
		$this->container->singleton(QueueEngine::class, function (Container $c): QueueEngine {
			global $wpdb;
			return new QueueEngine($wpdb, $c->get(SettingsRegistry::class), $c->get(Logger::class));
		});
		$this->container->singleton(HealthCheck::class, function (Container $c): HealthCheck {
			return new HealthCheck(
				$this->modules,
				$c->get(SettingsRegistry::class),
				$c->get(Migrator::class),
				$c->get(QueueEngine::class)
			);
		});
		$this->container->singleton(ModelRegistry::class, static fn(): ModelRegistry => new ModelRegistry());
		$this->container->singleton(ProviderConfiguration::class, static fn(): ProviderConfiguration => new ProviderConfiguration());
		$this->container->singleton(ProviderRegistry::class, static function (Container $c): ProviderRegistry {
			$reg = new ProviderRegistry();
			$guard = $c->get(ExternalRequestGuard::class);
			$models = $c->get(ModelRegistry::class);
			$reg->register(new OpenAIProvider($guard, $models));
			$reg->register(new GeminiProvider($guard, $models));
			$reg->register(new ClaudeProvider($guard, $models));
			$reg->register(new DeepSeekProvider($guard, $models));
			$reg->register(new GrokProvider($guard, $models));
			$customCfg = $c->get(ProviderConfiguration::class)->get('custom');
			$customBaseUrl = trim((string) ($customCfg['base_url'] ?? ''));
			$customProvider = new CustomOpenAICompatibleProvider($guard, $models);
			if ($customBaseUrl !== '') {
				try {
					$customProvider->setBaseUrl($customBaseUrl);
				} catch (\Throwable) {
					// Keep unconfigured if stored URL fails validation
				}
			}
			$reg->register($customProvider);
			return $reg;
		});
		$this->container->singleton(PromptRenderer::class, static fn(): PromptRenderer => new PromptRenderer());
		$this->container->singleton(PromptRegistry::class, static fn(Container $c): PromptRegistry => new PromptRegistry($c->get(PromptRenderer::class)));
		$this->container->singleton(BrandProfile::class, static fn(): BrandProfile => new BrandProfile());
		$this->container->singleton(TokenBudgetManager::class, static function (Container $c): TokenBudgetManager {
			global $wpdb;
			return new TokenBudgetManager(
				$c->get(ProviderConfiguration::class),
				$c->get(ModelRegistry::class),
				$wpdb
			);
		});
		$this->container->singleton(OutputValidator::class, static fn(): OutputValidator => new OutputValidator());
		$this->container->singleton(AIOrchestrator::class, static function (Container $c): AIOrchestrator {
			return new AIOrchestrator(
				$c->get(SettingsRegistry::class),
				$c->get(ProviderRegistry::class),
				$c->get(ProviderConfiguration::class),
				$c->get(CredentialVault::class),
				$c->get(PromptRegistry::class),
				$c->get(PromptRenderer::class),
				$c->get(BrandProfile::class),
				$c->get(TokenBudgetManager::class),
				$c->get(OutputValidator::class),
				$c->get(Logger::class)
			);
		});
		$this->container->singleton(KeywordRepository::class, static function (Container $c): KeywordRepository {
			global $wpdb;
			return new KeywordRepository($wpdb, $c->get(Logger::class));
		});
		$this->container->singleton(KeywordIntelligence::class, static function (): KeywordIntelligence {
			global $wpdb;
			return new KeywordIntelligence($wpdb);
		});
		$this->container->singleton(ContentDraftRepository::class, static function (Container $c): ContentDraftRepository {
			global $wpdb;
			return new ContentDraftRepository($wpdb, $c->get(Logger::class));
		});
		$this->container->singleton(ContentQAEngine::class, static fn(Container $c): ContentQAEngine => new ContentQAEngine($c->get(BrandProfile::class)));
		$this->container->singleton(ContentWorkflowEngine::class, static function (Container $c): ContentWorkflowEngine {
			return new ContentWorkflowEngine(
				$c->get(ContentDraftRepository::class),
				$c->get(ContentQAEngine::class),
				$c->get(ApprovalEngine::class),
				$c->get(KeywordIntelligence::class),
				$c->get(AIOrchestrator::class),
				$c->get(ProviderConfiguration::class),
				$c->get(CredentialVault::class),
				$c->get(SettingsRegistry::class)
			);
		});
		$this->container->singleton(NewUpdateDecisionEngine::class, static fn(): NewUpdateDecisionEngine => new NewUpdateDecisionEngine());
		$this->container->singleton(RewriteEngine::class, static function (Container $c): RewriteEngine {
			global $wpdb;
			return new RewriteEngine($wpdb, $c->get(ContentDraftRepository::class), $c->get(ApprovalEngine::class), $c->get(Logger::class));
		});
		$this->container->singleton(ImageValidator::class, static fn(): ImageValidator => new ImageValidator());
		$this->container->singleton(MediaProposalService::class, static function (Container $c): MediaProposalService {
			global $wpdb;
			return new MediaProposalService($wpdb, $c->get(ImageValidator::class), $c->get(ProviderRegistry::class), $c->get(Logger::class));
		});
		$this->container->singleton(InternalLinkProposalEngine::class, static fn(Container $c): InternalLinkProposalEngine => new InternalLinkProposalEngine($c->get(BrandProfile::class)));
		$this->container->singleton(ImageGenerationService::class, static function (Container $c): ImageGenerationService {
			return new ImageGenerationService(
				$c->get(SettingsRegistry::class),
				$c->get(ProviderRegistry::class),
				$c->get(CredentialVault::class),
				$c->get(ExternalRequestGuard::class),
				$c->get(ImageValidator::class),
				$c->get(Logger::class),
				$c->get(TokenBudgetManager::class),
				$c->get(MediaProposalService::class)
			);
		});
		$this->container->singleton(MediaLibraryReader::class, static fn(): MediaLibraryReader => new MediaLibraryReader());
		$this->container->singleton(MasterProductRepository::class, static function (Container $c): MasterProductRepository {
			global $wpdb;
			return new MasterProductRepository($wpdb, $c->get(Logger::class));
		});
		$this->container->singleton(WooCommerceAdapter::class, static fn(): WooCommerceAdapter => new WooCommerceAdapter());
		$this->container->singleton(ProductEngine::class, static function (Container $c): ProductEngine {
			global $wpdb;
			return new ProductEngine(
				$wpdb,
				$c->get(WooCommerceAdapter::class),
				$c->get(MasterProductRepository::class),
				$c->get(ApprovalEngine::class),
				$c->get(MutationGuard::class),
				$c->get(Logger::class)
			);
		});
		$this->container->singleton(RankMathAdapter::class, static fn(): RankMathAdapter => new RankMathAdapter());
		$this->container->singleton(SeoEnhancer::class, static fn(): SeoEnhancer => new SeoEnhancer());
		$this->container->singleton(PublishingEngine::class, static function (Container $c): PublishingEngine {
			global $wpdb;
			return new PublishingEngine(
				$wpdb,
				$c->get(ContentDraftRepository::class),
				$c->get(ApprovalEngine::class),
				$c->get(MutationGuard::class),
				$c->get(RankMathAdapter::class),
				$c->get(Logger::class)
			);
		});
		$this->container->singleton(InternalLinkEngine::class, static function (Container $c): InternalLinkEngine {
			global $wpdb;
			return new InternalLinkEngine(
				$wpdb,
				$c->get(BrandProfile::class),
				$c->get(InternalLinkProposalEngine::class),
				$c->get(ApprovalEngine::class),
				$c->get(MutationGuard::class),
				$c->get(Logger::class)
			);
		});
		$this->container->singleton(WorkerEngine::class, static function (Container $c): WorkerEngine {
			$worker = new WorkerEngine($c->get(QueueEngine::class), $c->get(SettingsRegistry::class), $c->get(Logger::class));
			$worker->registerHandler('KEYWORD_ANALYSIS', static function (array $payload) use ($c): array {
				$kw = trim((string) ($payload['keyword'] ?? $payload['primary_keyword'] ?? ''));
				if ($kw === '') {
					throw new \InvalidArgumentException('KEYWORD_ANALYSIS requires keyword.');
				}
				$intel = $c->get(KeywordIntelligence::class);
				$repo = $c->get(KeywordRepository::class);
				$res = $intel->classifyIntentHeuristic($kw);
				$intentSrc = (string) ($res['source'] ?? 'HEURISTIC');
				$kwId = (int) ($payload['keyword_id'] ?? 0);
				if ($kwId > 0) {
					$repo->update($kwId, ['search_intent' => $res['intent'], 'intent_source' => $intentSrc]);
				} else {
					$existing = $repo->findByKeyword($kw);
					if ($existing !== null) {
						$kwId = (int) $existing['id'];
						$repo->update($kwId, ['search_intent' => $res['intent'], 'intent_source' => $intentSrc]);
					} else {
						$kwId = $repo->add(['keyword' => $kw, 'search_intent' => $res['intent'], 'intent_source' => $intentSrc]);
					}
				}
				return ['status' => 'COMPLETED', 'keyword_id' => $kwId, 'intent' => $res['intent'], 'intent_source' => $intentSrc];
			});
			$worker->registerHandler('KEYWORD_CLUSTER', static function (array $payload) use ($c): array {
				$keywords = is_array($payload['keywords'] ?? null) ? $payload['keywords'] : [];
				$clusters = $c->get(KeywordIntelligence::class)->clusterByTopic($keywords);
				$repo = $c->get(KeywordRepository::class);
				foreach ($clusters as $group => $items) {
					foreach ($items as $kwText) {
						$existing = $repo->findByKeyword((string) $kwText);
						if ($existing !== null) {
							$repo->update((int) $existing['id'], ['keyword_group' => (string) $group]);
						}
					}
				}
				if (function_exists('update_option')) {
					update_option('htd_ai_seo_last_clusters', $clusters, false);
				}
				return ['status' => 'COMPLETED', 'cluster_count' => count($clusters), 'clusters' => $clusters];
			});
			$worker->registerHandler('CONTENT_OUTLINE', static function (array $payload) use ($c): array {
				$providerId = (string) ($payload['provider_id'] ?? $c->get(SettingsRegistry::class)->get('active_ai_provider', 'openai'));
				$kw = trim((string) ($payload['keyword'] ?? $payload['primary_keyword'] ?? ''));
				$aiRes = $c->get(AIOrchestrator::class)->executeTask($providerId, 'content_outline', [
					'keyword'            => $kw,
					'secondary_keywords' => implode(', ', (array) ($payload['secondary_keywords'] ?? [])),
					'heading_depth'      => (string) ($payload['heading_depth'] ?? 'H2,H3'),
				], true);
				$outline = $aiRes['validated'];
				if (function_exists('get_option') && function_exists('update_option')) {
					$outlines = get_option('htd_ai_seo_outlines', []);
					if (!is_array($outlines)) {
						$outlines = [];
					}
					$outlines[$kw !== '' ? $kw : ('outline_' . time())] = $outline;
					update_option('htd_ai_seo_outlines', array_slice($outlines, -50, null, true), false);
				}
				return ['status' => 'COMPLETED', 'outline' => $outline];
			});
			$worker->registerHandler('CONTENT_GENERATION', static function (array $payload) use ($c): array {
				$payload['skip_capability_check'] = true;
				return $c->get(ContentWorkflowEngine::class)->runAiWorkflow($payload);
			});
			$worker->registerHandler('CONTENT_REWRITE', static function (array $payload) use ($c): array {
				$orig = (array) ($payload['original_post'] ?? []);
				$proposed = (array) ($payload['proposed'] ?? []);
				if ($proposed === [] && !empty($orig['html'])) {
					$providerId = (string) ($payload['provider_id'] ?? $c->get(SettingsRegistry::class)->get('active_ai_provider', 'openai'));
					$aiRes = $c->get(AIOrchestrator::class)->executeTask($providerId, 'article_rewrite', [
						'keyword'          => (string) ($payload['keyword'] ?? ($orig['title'] ?? '')),
						'existing_content' => (string) $orig['html'],
						'target_url'       => (string) ($orig['url'] ?? ''),
					], true);
					$proposed = [
						'title' => (string) ($aiRes['validated']['title'] ?? ($orig['title'] ?? '')),
						'html'  => (string) ($aiRes['validated']['html_content'] ?? ''),
					];
				}
				$res = $c->get(RewriteEngine::class)->proposeRewrite($orig, $proposed, (int) ($payload['actor_id'] ?? 0));
				return array_merge(['status' => 'COMPLETED'], $res);
			});
			$worker->registerHandler('PRODUCT_CONTENT', static function (array $payload) use ($c): array {
				$wcProd = (array) ($payload['wc_product'] ?? []);
				$proposed = (array) ($payload['proposed'] ?? []);
				if ($proposed === [] && !empty($wcProd['name'])) {
					$providerId = (string) ($payload['provider_id'] ?? $c->get(SettingsRegistry::class)->get('active_ai_provider', 'openai'));
					$specs = (string) json_encode($payload['verified_specs'] ?? ($wcProd['attributes'] ?? []), JSON_UNESCAPED_UNICODE);
					$descRes = $c->get(AIOrchestrator::class)->executeTask($providerId, 'product_description', [
						'product_name'   => (string) $wcProd['name'],
						'verified_specs' => $specs,
						'category_url'   => (string) ($payload['category_url'] ?? ''),
					], true);
					$shortRes = $c->get(AIOrchestrator::class)->executeTask($providerId, 'product_short_desc', [
						'product_name'   => (string) $wcProd['name'],
						'verified_specs' => $specs,
					], true);
					$proposed = [
						'description'       => (string) ($descRes['validated']['html_description'] ?? ''),
						'short_description' => (string) ($shortRes['validated']['short_description'] ?? ''),
					];
				}
				$res = $c->get(ProductEngine::class)->proposeProductUpdate($wcProd, $proposed, (int) ($payload['actor_id'] ?? 0));
				return array_merge(['status' => 'COMPLETED'], $res);
			});
			$worker->registerHandler('IMAGE_GENERATION', static function (array $payload) use ($c): array {
				$res = $c->get(ImageGenerationService::class)->generateImage(
					(string) ($payload['provider_id'] ?? 'openai'),
					(string) ($payload['prompt'] ?? ''),
					(string) ($payload['size'] ?? '1024x1024'),
					(int) ($payload['draft_id'] ?? 0),
					(string) ($payload['alt_text'] ?? '')
				);
				return [
					'status'       => 'COMPLETED',
					'proposal_id'  => $res['proposal_id'],
					'file_path'    => $res['file_path'],
					'url'          => $res['url'],
					'bytes_sha256' => $res['bytes_sha256'],
				];
			});
			$worker->registerHandler('CONTENT_QA', static function (array $payload) use ($c): array {
				$qaRes = $c->get(ContentQAEngine::class)->evaluate($payload);
				return ['status' => 'COMPLETED', 'qa' => $qaRes];
			});
			$worker->registerHandler('INTERNAL_LINK_ANALYSIS', static function (array $payload) use ($c): array {
				$postId = max(1, (int) ($payload['post_id'] ?? ($payload['draft_id'] ?? 1)));
				$kw = trim((string) ($payload['keyword'] ?? $payload['primary_keyword'] ?? ''));
				$sourceUrl = trim((string) ($payload['source_url'] ?? 'https://htd.net.vn/'));
				$linkEngine = $c->get(InternalLinkEngine::class);
				$res = $linkEngine->createProposal($postId, $sourceUrl, $kw !== '' ? $kw : 'HTD', (int) ($payload['max_links'] ?? 3), (int) ($payload['actor_id'] ?? 0));
				return array_merge(['status' => 'COMPLETED'], $res);
			});
			$worker->registerHandler('PUBLISH_APPROVED_CONTENT', static function (array $payload) use ($c): array {
				$res = $c->get(PublishingEngine::class)->execute(
					(int) ($payload['draft_id'] ?? 0),
					(int) ($payload['approval_id'] ?? 0),
					(string) ($payload['target_status'] ?? 'draft'),
					(string) ($payload['idempotency_key'] ?? ''),
					!empty($payload['is_auto_publish']),
					isset($payload['scheduled_date_gmt']) ? (string) $payload['scheduled_date_gmt'] : null
				);
				return array_merge(['status' => 'COMPLETED'], $res);
			});
			$worker->registerHandler('HTD_SEO_AUDIT', static function (array $payload) use ($c): array {
				return $c->get(OneClickAuditWorkflow::class)->runAudit(
					(array) ($payload['category_snapshots'] ?? []),
					(array) ($payload['page_snapshots'] ?? [])
				);
			});
			return $worker;
		});
		$this->container->singleton(CronScheduler::class, static function (Container $c): CronScheduler {
			return new CronScheduler($c->get(WorkerEngine::class), $c->get(SettingsRegistry::class), $c->get(Logger::class));
		});
		$this->container->singleton(SEODataRepository::class, static function (Container $c): SEODataRepository {
			global $wpdb;
			return new SEODataRepository($wpdb, $c->get(Logger::class));
		});
		$this->container->singleton(GSCAdapter::class, static function (Container $c): GSCAdapter {
			return new GSCAdapter($c->get(ExternalRequestGuard::class), $c->get(CredentialVault::class), $c->get(SEODataRepository::class));
		});
		$this->container->singleton(GA4Adapter::class, static function (Container $c): GA4Adapter {
			return new GA4Adapter($c->get(ExternalRequestGuard::class), $c->get(CredentialVault::class), $c->get(SEODataRepository::class));
		});
		$this->container->singleton(CategorySEOEngine::class, static function (Container $c): CategorySEOEngine {
			global $wpdb;
			return new CategorySEOEngine($wpdb, $c->get(BrandProfile::class), $c->get(MasterProductRepository::class), $c->get(ApprovalEngine::class), $c->get(Logger::class));
		});
		$this->container->singleton(OpportunityAndRoadmapEngine::class, static function (): OpportunityAndRoadmapEngine {
			global $wpdb;
			return new OpportunityAndRoadmapEngine($wpdb);
		});
		$this->container->singleton(CompetitorAndAISearchEngine::class, static function (): CompetitorAndAISearchEngine {
			global $wpdb;
			return new CompetitorAndAISearchEngine($wpdb);
		});
		$this->container->singleton(TechnicalAuditAndTrafficGuard::class, static fn(): TechnicalAuditAndTrafficGuard => new TechnicalAuditAndTrafficGuard());
		$this->container->singleton(GrowthAnalyticsEngine::class, static function (): GrowthAnalyticsEngine {
			global $wpdb;
			return new GrowthAnalyticsEngine($wpdb);
		});
		$this->container->singleton(OneClickAuditWorkflow::class, static function (Container $c): OneClickAuditWorkflow {
			return new OneClickAuditWorkflow(
				$c->get(SettingsRegistry::class),
				$c->get(QueueEngine::class),
				$c->get(SEODataRepository::class),
				$c->get(CategorySEOEngine::class),
				$c->get(OpportunityAndRoadmapEngine::class),
				$c->get(TechnicalAuditAndTrafficGuard::class),
				$c->get(Logger::class),
				$c->get(KeywordIntelligence::class),
				$c->get(CompetitorAndAISearchEngine::class),
				$c->get(GrowthAnalyticsEngine::class),
				$c->get(InternalLinkProposalEngine::class)
			);
		});
		$this->container->singleton(AdminMenu::class, function (Container $c): AdminMenu {
			return new AdminMenu($c, $this->modules);
		});
	}

	private function registerModules(): void {
		$this->modules->register('core', 'ACTIVE', 'PHASE_02');
		$this->modules->register('database', 'ACTIVE', 'PHASE_02', ['core']);
		$this->modules->register('settings', 'ACTIVE', 'PHASE_02', ['core']);
		$this->modules->register('logging', 'ACTIVE', 'PHASE_02', ['database']);
		$this->modules->register('credentials', 'ACTIVE', 'PHASE_02', ['settings']);
		$this->modules->register('http_guard', 'ACTIVE', 'PHASE_02', ['settings', 'logging']);
		$this->modules->register('approval', 'ACTIVE', 'PHASE_02', ['database', 'logging']);
		$this->modules->register('mutation_guard', 'ACTIVE', 'PHASE_02', ['settings', 'approval', 'logging']);
		$this->modules->register('queue', 'ACTIVE', 'PHASE_02', ['database', 'settings', 'logging']);
		$this->modules->register('admin_ui', 'ACTIVE', 'PHASE_02', ['core', 'settings', 'logging', 'approval']);
		$this->modules->register('ai_providers', 'ACTIVE', 'PHASE_03', ['credentials', 'http_guard']);
		$this->modules->register('prompt_engine', 'ACTIVE', 'PHASE_03', ['settings']);
		$this->modules->register('token_engine', 'ACTIVE', 'PHASE_03', ['ai_providers']);
		$this->modules->register('keyword_engine', 'ACTIVE', 'PHASE_04', ['database', 'ai_providers']);
		$this->modules->register('content_engine', 'ACTIVE', 'PHASE_04', ['prompt_engine', 'ai_providers', 'mutation_guard']);
		$this->modules->register('rewrite_engine', 'ACTIVE', 'PHASE_04', ['content_engine', 'approval']);
		$this->modules->register('media_engine', 'ACTIVE', 'PHASE_04', ['database', 'ai_providers']);
		$this->modules->register('woocommerce_seo', 'ACTIVE', 'PHASE_05', ['content_engine', 'mutation_guard']);
		$this->modules->register('publishing', 'ACTIVE', 'PHASE_05', ['content_engine', 'mutation_guard']);
		$this->modules->register('internal_links', 'ACTIVE', 'PHASE_05', ['content_engine', 'mutation_guard']);
		$this->modules->register('cron_scheduler', 'ACTIVE', 'PHASE_05', ['queue', 'settings']);
		$this->modules->register('htd_seo_extensions', 'ACTIVE', 'PHASE_06', ['woocommerce_seo', 'cron_scheduler']);
	}

	public function boot(): void {
		if ($this->booted) {
			return;
		}
		$this->booted = true;
		$this->modules->resolveOrder();

		if (function_exists('load_plugin_textdomain') && defined('HTD_AI_SEO_BASENAME')) {
			load_plugin_textdomain('htd-ai-seo-writer-pro', false, dirname(HTD_AI_SEO_BASENAME) . '/languages');
		}

		$this->migrator()->migrateIfNeeded();
		$cron = $this->container->get(CronScheduler::class);
		$cron->register();
		$cron->syncWithSettings();

		if (function_exists('is_admin') && is_admin()) {
			$this->container->get(AdminMenu::class)->register();
		}
	}

	public function container(): Container {
		return $this->container;
	}

	public function modules(): ModuleRegistry {
		return $this->modules;
	}

	public function migrator(): Migrator {
		return $this->container->get(Migrator::class);
	}

	public function settings(): SettingsRegistry {
		return $this->container->get(SettingsRegistry::class);
	}
}
