<?php
declare(strict_types=1);

namespace HTD\AISEO\Admin;

use HTD\AISEO\AI\ProviderConfiguration;
use HTD\AISEO\Analytics\SEODataRepository;
use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Content\ContentDraftRepository;
use HTD\AISEO\Core\Container;
use HTD\AISEO\Core\HealthCheck;
use HTD\AISEO\Core\ModuleRegistry;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Database\Schema;
use HTD\AISEO\Growth\OneClickAuditWorkflow;
use HTD\AISEO\Keyword\KeywordRepository;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Prompt\PromptRegistry;
use HTD\AISEO\Queue\CronScheduler;
use HTD\AISEO\Queue\QueueEngine;
use HTD\AISEO\Security\RequestVerifier;
use HTD\AISEO\Security\Sanitizer;
use HTD\AISEO\SEO\RankMathAdapter;
use HTD\AISEO\Settings\SettingsRegistry;
use HTD\AISEO\Support\Capabilities;
use HTD\AISEO\Token\TokenBudgetManager;
use HTD\AISEO\WooCommerce\WooCommerceAdapter;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WordPress Admin UI controller for Phase 02 screens.
 */
final class AdminMenu {
	public function __construct(
		private readonly Container $container,
		private readonly ModuleRegistry $modules
	) {}

	public function register(): void {
		if (!function_exists('add_action')) {
			return;
		}
		add_action('admin_menu', [$this, 'addMenus']);
		add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
		add_action('admin_post_htd_ai_seo_save_settings', [$this, 'handleSaveSettings']);
		add_action('admin_post_htd_ai_seo_toggle_emergency', [$this, 'handleToggleEmergency']);
		add_action('admin_post_htd_ai_seo_save_credential', [$this, 'handleSaveCredential']);
		add_action('admin_post_htd_ai_seo_delete_credential', [$this, 'handleDeleteCredential']);
		add_action('admin_post_htd_ai_seo_approval_decision', [$this, 'handleApprovalDecision']);
		add_action('admin_post_htd_ai_seo_save_provider', [$this, 'handleSaveProvider']);
		add_action('admin_post_htd_ai_seo_save_brand', [$this, 'handleSaveBrand']);
		add_action('admin_post_htd_ai_seo_add_keyword', [$this, 'handleAddKeyword']);
		add_action('admin_post_htd_ai_seo_bulk_keywords', [$this, 'handleBulkKeywords']);
		add_action('admin_post_htd_ai_seo_run_audit', [$this, 'handleRunAudit']);
		add_action('admin_post_htd_ai_seo_playground_run', [$this, 'handlePlaygroundRun']);
		add_action('admin_post_htd_ai_seo_generate_article', [$this, 'handleGenerateArticle']);
		add_action('admin_post_htd_ai_seo_bulk_generate', [$this, 'handleBulkGenerate']);
		add_action('admin_post_htd_ai_seo_edit_draft', [$this, 'handleEditDraft']);
		add_action('admin_post_htd_ai_seo_export_draft', [$this, 'handleExportDraft']);
		add_action('admin_post_htd_ai_seo_propose_rewrite', [$this, 'handleProposeRewrite']);
		add_action('admin_post_htd_ai_seo_product_content', [$this, 'handleProductContent']);
		add_action('admin_post_htd_ai_seo_save_prompt', [$this, 'handleSavePrompt']);
		add_action('admin_post_htd_ai_seo_media_action', [$this, 'handleMediaAction']);
		add_action('admin_post_htd_ai_seo_internal_links', [$this, 'handleInternalLinks']);
		add_action('admin_post_htd_ai_seo_execute_approval', [$this, 'handleExecuteApproval']);
		add_action('admin_post_htd_ai_seo_queue_action', [$this, 'handleQueueAction']);
		add_action('admin_post_htd_ai_seo_google_sync', [$this, 'handleGoogleSync']);
		add_action('admin_post_htd_ai_seo_gsc_import', [$this, 'handleGscImport']);
	}

	public function addMenus(): void {
		$cap = Capabilities::MANAGE;
		add_menu_page(
			'HTD AI SEO Writer Pro',
			'HTD AI SEO PRO',
			$cap,
			'htd-ai-seo-dashboard',
			[$this, 'renderDashboard'],
			'dashicons-shield-alt',
			58
		);

		$submenus = [
			['htd-ai-seo-dashboard', 'Dashboard', $cap, [$this, 'renderDashboard']],
			['htd-ai-seo-growth', 'HTD Growth Center', $cap, [$this, 'renderGrowthCenter']],
			['htd-ai-seo-keywords', 'Keywords & Audit', $cap, [$this, 'renderKeywords']],
			['htd-ai-seo-drafts', 'Content Drafts & QA', $cap, [$this, 'renderDrafts']],
			['htd-ai-seo-rewrite', 'Rewrite Posts', $cap, [$this, 'renderRewrite']],
			['htd-ai-seo-products', 'Products (WooCommerce)', $cap, [$this, 'renderProducts']],
			['htd-ai-seo-operations', 'Operations & Cron', $cap, [$this, 'renderOperations']],
			['htd-ai-seo-settings', 'Settings', Capabilities::MANAGE_SETTINGS, [$this, 'renderSettings']],
			['htd-ai-seo-ai-providers', 'AI Providers', Capabilities::MANAGE_SETTINGS, [$this, 'renderAiProviders']],
			['htd-ai-seo-prompts', 'Prompt Library', $cap, [$this, 'renderPrompts']],
			['htd-ai-seo-brand', 'Brand Profile', Capabilities::MANAGE_SETTINGS, [$this, 'renderBrandProfile']],
			['htd-ai-seo-ai-usage', 'AI Usage', $cap, [$this, 'renderAiUsage']],
			['htd-ai-seo-playground', 'AI Playground', $cap, [$this, 'renderAiPlayground']],
			['htd-ai-seo-health', 'System Health', $cap, [$this, 'renderHealth']],
			['htd-ai-seo-security', 'Security', $cap, [$this, 'renderSecurity']],
			['htd-ai-seo-logs', 'Audit Logs', Capabilities::VIEW_LOGS, [$this, 'renderLogs']],
			['htd-ai-seo-approvals', 'Approvals', Capabilities::REVIEW, [$this, 'renderApprovals']],
			['htd-ai-seo-credentials', 'Credentials', Capabilities::MANAGE_CREDENTIALS, [$this, 'renderCredentials']],
		];

		foreach ($submenus as [$slug, $title, $reqCap, $cb]) {
			add_submenu_page('htd-ai-seo-dashboard', $title, $title, $reqCap, $slug, $cb);
		}
	}

	public function enqueueAssets(string $hook): void {
		if (!str_contains($hook, 'htd-ai-seo')) {
			return;
		}
		wp_enqueue_style('htd-ai-seo-admin', HTD_AI_SEO_URL . 'assets/css/admin.css', [], HTD_AI_SEO_VERSION);
		wp_enqueue_script('htd-ai-seo-admin', HTD_AI_SEO_URL . 'assets/js/admin.js', [], HTD_AI_SEO_VERSION, true);
	}

	public function renderDashboard(): void {
		$health = $this->container->get(HealthCheck::class)->summary();
		$this->renderTemplate('dashboard', ['health' => $health]);
	}

	public function renderSettings(): void {
		$settings = $this->container->get(SettingsRegistry::class)->all();
		$schema = SettingsRegistry::schema();
		$this->renderTemplate('settings', ['settings' => $settings, 'schema' => $schema]);
	}

	public function renderHealth(): void {
		$health = $this->container->get(HealthCheck::class)->summary();
		$this->renderTemplate('health', ['health' => $health]);
	}

	public function renderSecurity(): void {
		$settings = $this->container->get(SettingsRegistry::class)->all();
		$this->renderTemplate('security', ['settings' => $settings]);
	}

	public function renderLogs(): void {
		$severity = isset($_GET['severity']) ? Sanitizer::sanitizeIdentifier((string) wp_unslash($_GET['severity'])) : null;
		$logs = $this->container->get(Logger::class)->query(50, 0, $severity);
		$this->renderTemplate('logs', ['logs' => $logs, 'severity' => $severity]);
	}

	public function renderApprovals(): void {
		$approvals = $this->container->get(ApprovalEngine::class)->listRecent(50);
		$this->renderTemplate('approvals', ['approvals' => $approvals]);
	}

	public function renderCredentials(): void {
		$vaultError = null;
		$credentials = [];
		try {
			$credentials = $this->container->get(CredentialVault::class)->listMasked();
		} catch (\Throwable $e) {
			$vaultError = $e->getMessage();
		}
		$this->renderTemplate('credentials', ['credentials' => $credentials, 'vaultError' => $vaultError]);
	}

	public function renderAiProviders(): void {
		$configs = $this->container->get(ProviderConfiguration::class)->all();
		$maskedCreds = [];
		try {
			$maskedCreds = $this->container->get(CredentialVault::class)->listMasked();
		} catch (\Throwable) {}
		$this->renderTemplate('ai-providers', ['configs' => $configs, 'maskedCreds' => $maskedCreds]);
	}

	public function renderPrompts(): void {
		$prompts = $this->container->get(PromptRegistry::class)->all();
		$this->renderTemplate('prompts', ['prompts' => $prompts]);
	}

	public function renderBrandProfile(): void {
		$brand = $this->container->get(BrandProfile::class)->get();
		$this->renderTemplate('brand-profile', ['brand' => $brand]);
	}

	public function renderAiUsage(): void {
		$usage = $this->container->get(TokenBudgetManager::class)->usageSummary();
		$this->renderTemplate('ai-usage', ['usage' => $usage]);
	}

	public function renderAiPlayground(): void {
		$settings = $this->container->get(SettingsRegistry::class)->all();
		$prompts = $this->container->get(PromptRegistry::class)->all();
		$configs = $this->container->get(ProviderConfiguration::class)->all();
		$lastResult = function_exists('get_transient') ? get_transient('htd_ai_seo_playground_res_' . get_current_user_id()) : null;
		$this->renderTemplate('ai-playground', [
			'settings'   => $settings,
			'prompts'    => $prompts,
			'configs'    => $configs,
			'lastResult' => $lastResult,
		]);
	}

	public function handleSaveProvider(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_save_provider', Capabilities::MANAGE_SETTINGS);
		$providerId = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['provider_id'] ?? ''));
		if ($providerId !== '') {
			try {
				$cfgData = [
					'enabled'            => !empty($_POST['enabled']),
					'model'              => wp_unslash($_POST['model'] ?? ''),
					'max_tokens'         => $_POST['max_tokens'] ?? 2048,
					'timeout'            => $_POST['timeout'] ?? 45,
					'daily_budget_usd'   => $_POST['daily_budget_usd'] ?? 5.0,
					'monthly_budget_usd' => $_POST['monthly_budget_usd'] ?? 50.0,
				];
				if ($providerId === 'custom') {
					$cfgData['base_url'] = trim((string) wp_unslash($_POST['base_url'] ?? ''));
					$cfgData['input_per_1m'] = $_POST['input_per_1m'] ?? 0.0;
					$cfgData['output_per_1m'] = $_POST['output_per_1m'] ?? 0.0;
				}
				$this->container->get(ProviderConfiguration::class)->save($providerId, $cfgData);
				if ($providerId === 'custom' && !empty($_POST['test_connection'])) {
					$custom = $this->container->get(\HTD\AISEO\AI\ProviderRegistry::class)->get('custom');
					if ($custom instanceof \HTD\AISEO\AI\Providers\CustomOpenAICompatibleProvider && $cfgData['base_url'] !== '') {
						$custom->setBaseUrl($cfgData['base_url']);
						$key = $this->container->get(CredentialVault::class)->getSecret('custom_provider_api_key') ?? '';
						$testRes = $custom->testConnection($key, (string) $cfgData['model']);
						$this->setNotice($testRes['ok'] ? 'success' : 'error', 'Custom connection test: ' . ($testRes['ok'] ? 'OK (' . $testRes['latency_ms'] . 'ms)' : 'Failed'));
					}
				} else {
					$this->setNotice('success', "Provider {$providerId} saved.");
				}
				$this->container->get(Logger::class)->log('ai_providers', 'provider_updated', 'AUDIT', 'OK', ['provider' => $providerId]);
			} catch (\Throwable $e) {
				$this->setNotice('error', $e->getMessage());
			}
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-ai-providers'));
		exit;
	}

	public function handleSaveBrand(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_save_brand', Capabilities::MANAGE_SETTINGS);
		$this->container->get(BrandProfile::class)->save(wp_unslash($_POST));
		$this->container->get(Logger::class)->log('brand', 'brand_updated', 'AUDIT', 'OK');
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-brand&updated=1'));
		exit;
	}

	public function renderKeywords(): void {
		$search = isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : null;
		$status = isset($_GET['status']) ? Sanitizer::sanitizeIdentifier((string) wp_unslash($_GET['status'])) : null;
		$keywords = $this->container->get(KeywordRepository::class)->list(50, 0, $status, $search);
		$this->renderTemplate('keywords', ['keywords' => $keywords, 'search' => $search, 'status' => $status]);
	}

	public function renderDrafts(): void {
		$drafts = $this->container->get(ContentDraftRepository::class)->listRecent(50);
		$this->renderTemplate('content-drafts', ['drafts' => $drafts]);
	}

	public function renderRewrite(): void {
		$drafts = array_values(array_filter(
			$this->container->get(ContentDraftRepository::class)->listRecent(50),
			static fn(array $d): bool => ($d['job_type'] ?? '') === 'rewrite'
		));
		$this->renderTemplate('rewrite-posts', ['drafts' => $drafts]);
	}

	public function renderProducts(): void {
		$wc = $this->container->get(WooCommerceAdapter::class);
		$available = $wc->isAvailable();
		$products = $available ? $wc->listProducts(30) : [];
		$this->renderTemplate('products', ['available' => $available, 'products' => $products]);
	}

	public function renderOperations(): void {
		$queue = $this->container->get(QueueEngine::class)->countsByStatus();
		$jobs = $this->container->get(QueueEngine::class)->listRecent(25);
		$cron = $this->container->get(CronScheduler::class)->state();
		$settings = $this->container->get(SettingsRegistry::class)->all();
		$rankMath = $this->container->get(RankMathAdapter::class)->isAvailable();
		$this->renderTemplate('operations', [
			'queue'    => $queue,
			'jobs'     => $jobs,
			'cron'     => $cron,
			'settings' => $settings,
			'rankMath' => $rankMath,
		]);
	}

	public function handleAddKeyword(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_add_keyword', Capabilities::MANAGE);
		$kw = sanitize_text_field((string) wp_unslash($_POST['keyword'] ?? ''));
		if ($kw !== '') {
			$this->container->get(KeywordRepository::class)->add([
				'keyword'       => $kw,
				'keyword_group' => sanitize_text_field((string) wp_unslash($_POST['keyword_group'] ?? '')),
				'priority'      => absint($_POST['priority'] ?? 5),
				'target_url'    => esc_url_raw((string) wp_unslash($_POST['target_url'] ?? '')),
			]);
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-keywords&updated=1'));
		exit;
	}

	public function handleBulkKeywords(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_bulk_keywords', Capabilities::MANAGE);
		$ids = isset($_POST['keyword_ids']) && is_array($_POST['keyword_ids']) ? array_map('absint', $_POST['keyword_ids']) : [];
		$act = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['bulk_action'] ?? ''));
		if ($ids !== [] && $act !== '') {
			$this->container->get(KeywordRepository::class)->bulkAction($ids, $act);
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-keywords&updated=1'));
		exit;
	}

	public function renderGrowthCenter(): void {
		global $wpdb;
		$repo = $this->container->get(SEODataRepository::class);
		$gscCount = count($repo->latestBySource('gsc_import', 50));
		$ga4Count = count($repo->latestBySource('ga4', 50));
		$oppTable = Schema::table($wpdb, 'opportunities');
		$opps = $wpdb->get_results("SELECT * FROM {$oppTable} ORDER BY priority ASC, id DESC LIMIT 50", ARRAY_A) ?: [];
		$this->renderTemplate('growth-center', [
			'gscCount'      => $gscCount,
			'ga4Count'      => $ga4Count,
			'settings'      => $this->container->get(SettingsRegistry::class)->all(),
			'brand'         => $this->container->get(BrandProfile::class)->get(),
			'opportunities' => $opps,
		]);
	}

	public function handleRunAudit(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_run_audit', Capabilities::MANAGE);
		$this->container->get(OneClickAuditWorkflow::class)->enqueueAuditJob();
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-growth&audit_queued=1'));
		exit;
	}

	public function handleSaveSettings(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_save_settings', Capabilities::MANAGE_SETTINGS);
		$raw = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
		$input = [];
		foreach (SettingsRegistry::schema() as $key => $def) {
			if ($def['type'] === 'bool') {
				$input[$key] = !empty($raw[$key]);
			} elseif (isset($raw[$key])) {
				$input[$key] = $raw[$key];
			}
		}
		$this->container->get(SettingsRegistry::class)->update($input);
		$this->container->get(CronScheduler::class)->syncWithSettings();
		$this->container->get(Logger::class)->log('settings', 'settings_updated', 'AUDIT', 'OK');
		$this->setNotice('success', 'Settings saved and cron schedule synchronized.');
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-settings'));
		exit;
	}

	public function handleToggleEmergency(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_toggle_emergency', Capabilities::MANAGE);
		$registry = $this->container->get(SettingsRegistry::class);
		$current = (bool) $registry->get('emergency_stop', false);
		$registry->update(['emergency_stop' => !$current]);
		$this->container->get(CronScheduler::class)->syncWithSettings();
		$this->container->get(Logger::class)->log('security', 'emergency_stop_toggled', 'SECURITY', 'OK', [
			'emergency_stop' => !$current,
		]);
		$this->setNotice('warning', 'Emergency Stop is now ' . (!$current ? 'ACTIVE' : 'INACTIVE') . '.');
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-dashboard'));
		exit;
	}

	public function handleSaveCredential(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_save_credential', Capabilities::MANAGE_CREDENTIALS);
		$name = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['cred_name'] ?? ''));
		$secret = trim((string) wp_unslash($_POST['cred_secret'] ?? ''));
		if ($name !== '' && $secret !== '') {
			$this->container->get(CredentialVault::class)->storeSecret($name, $secret);
			$this->container->get(Logger::class)->log('credentials', 'secret_stored', 'SECURITY', 'OK', ['cred_name' => $name]);
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-credentials&updated=1'));
		exit;
	}

	public function handleDeleteCredential(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_delete_credential', Capabilities::MANAGE_CREDENTIALS);
		$name = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['cred_name'] ?? ''));
		if ($name !== '') {
			$this->container->get(CredentialVault::class)->deleteSecret($name);
			$this->container->get(Logger::class)->log('credentials', 'secret_deleted', 'SECURITY', 'OK', ['cred_name' => $name]);
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-credentials&deleted=1'));
		exit;
	}

	public function handleApprovalDecision(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_approval_decision', Capabilities::APPROVE);
		$id = isset($_POST['approval_id']) ? absint($_POST['approval_id']) : 0;
		$decision = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['decision'] ?? ''));
		$note = sanitize_text_field((string) wp_unslash($_POST['note'] ?? ''));
		$userId = get_current_user_id();
		$engine = $this->container->get(ApprovalEngine::class);
		if ($id > 0 && $decision === 'approve') {
			$engine->approve($id, $userId, $note);
		} elseif ($id > 0 && $decision === 'reject') {
			$engine->reject($id, $userId, $note);
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-approvals'));
		exit;
	}

	public function handlePlaygroundRun(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_playground_run', Capabilities::MANAGE);
		$templateId = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['template_id'] ?? 'seo_article'));
		$providerId = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['provider_id'] ?? 'openai'));
		$keyword = sanitize_text_field((string) wp_unslash($_POST['keyword'] ?? ''));
		$mode = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['mode'] ?? 'preview'));
		try {
			$tpl = $this->container->get(PromptRegistry::class)->get($templateId);
			$vars = ['keyword' => $keyword, 'outline' => 'H2 Giới thiệu', 'topic' => $keyword, 'summary' => $keyword];
			if ($mode === 'live') {
				$res = $this->container->get(\HTD\AISEO\AI\AIOrchestrator::class)->executeTask($providerId, $templateId, $vars, false, false);
				$out = ['mode' => 'live', 'provider' => $providerId, 'text' => $res['response']->rawText, 'tokens' => $res['response']->usage->totalTokens];
			} else {
				$rendered = $this->container->get(\HTD\AISEO\Prompt\PromptRenderer::class)->render($tpl, $vars, $this->container->get(BrandProfile::class)->toPromptContext());
				$out = ['mode' => 'preview', 'provider' => $providerId, 'messages' => $rendered];
			}
			if (function_exists('set_transient')) {
				set_transient('htd_ai_seo_playground_res_' . get_current_user_id(), $out, 300);
			}
			$this->setNotice('success', 'Playground executed (' . $out['mode'] . ').');
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-playground'));
		exit;
	}

	public function handleGenerateArticle(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_generate_article', Capabilities::MANAGE);
		$keyword = sanitize_text_field((string) wp_unslash($_POST['primary_keyword'] ?? ''));
		$providerId = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['provider_id'] ?? ''));
		try {
			$res = $this->container->get(\HTD\AISEO\Content\ContentWorkflowEngine::class)->runAiWorkflow([
				'primary_keyword' => $keyword,
				'provider_id'     => $providerId,
				'actor_id'        => get_current_user_id(),
			]);
			if (($res['status'] ?? '') === 'BLOCKED_BY_CONFIGURATION') {
				$this->setNotice('error', 'Blocked by configuration: ' . ($res['reason'] ?? 'Missing provider or credentials'));
			} else {
				$this->setNotice('success', 'Draft #' . ($res['draft_id'] ?? 0) . ' generated and queued for approval.');
			}
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-drafts'));
		exit;
	}

	public function handleBulkGenerate(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_bulk_generate', Capabilities::MANAGE);
		$raw = trim((string) wp_unslash($_POST['keywords_bulk'] ?? ''));
		$lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
		$queued = 0;
		try {
			$queue = $this->container->get(QueueEngine::class);
			foreach ($lines as $kw) {
				$queue->enqueue('CONTENT_GENERATION', ['primary_keyword' => sanitize_text_field($kw), 'actor_id' => get_current_user_id()], 'bulk-gen-' . sha1($kw . gmdate('YmdH')));
				$queued++;
			}
			$this->setNotice('success', "Enqueued {$queued} content generation job(s).");
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-drafts'));
		exit;
	}

	public function handleEditDraft(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_edit_draft', Capabilities::MANAGE);
		$draftId = absint($_POST['draft_id'] ?? 0);
		try {
			$this->container->get(ContentDraftRepository::class)->updateDraftContent($draftId, [
				'title'            => sanitize_text_field((string) wp_unslash($_POST['title'] ?? '')),
				'meta_title'       => sanitize_text_field((string) wp_unslash($_POST['meta_title'] ?? '')),
				'meta_description' => sanitize_text_field((string) wp_unslash($_POST['meta_description'] ?? '')),
				'html_content'     => (string) wp_unslash($_POST['html_content'] ?? ''),
			], get_current_user_id());
			$this->setNotice('success', "Draft #{$draftId} updated (prior approval cleared; re-approval required).");
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-drafts'));
		exit;
	}

	public function handleExportDraft(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_export_draft', Capabilities::MANAGE);
		$draftId = absint($_POST['draft_id'] ?? 0);
		$format = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['format'] ?? 'html'));
		$repo = $this->container->get(ContentDraftRepository::class);
		$content = $format === 'markdown' ? $repo->exportMarkdown($draftId) : $repo->exportHtml($draftId);
		$ext = $format === 'markdown' ? 'md' : 'html';
		header('Content-Type: text/plain; charset=utf-8');
		header('Content-Disposition: attachment; filename="htd-draft-' . $draftId . '.' . $ext . '"');
		echo $content;
		exit;
	}

	public function handleProposeRewrite(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_propose_rewrite', Capabilities::MANAGE);
		$postId = absint($_POST['post_id'] ?? 0);
		try {
			$post = function_exists('get_post') ? get_post($postId) : null;
			if (!$post) {
				throw new \RuntimeException("WordPress post #{$postId} not found.");
			}
			$orig = [
				'id'        => (int) $post->ID,
				'url'       => function_exists('get_permalink') ? (string) get_permalink($post->ID) : '',
				'slug'      => (string) $post->post_name,
				'canonical' => function_exists('get_permalink') ? (string) get_permalink($post->ID) : '',
				'title'     => (string) $post->post_title,
				'html'      => (string) $post->post_content,
			];
			$res = $this->container->get(\HTD\AISEO\Rewrite\RewriteEngine::class)->proposeRewrite($orig, [
				'title' => sanitize_text_field((string) wp_unslash($_POST['proposed_title'] ?? $orig['title'])),
				'html'  => (string) wp_unslash($_POST['proposed_html'] ?? $orig['html']),
			], get_current_user_id());
			$this->setNotice('success', "Rewrite proposal Draft #{$res['draft_id']} (Approval #{$res['approval_id']}) created.");
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-rewrite'));
		exit;
	}

	public function handleProductContent(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_product_content', Capabilities::MANAGE);
		$productId = absint($_POST['product_id'] ?? 0);
		try {
			$wc = $this->container->get(WooCommerceAdapter::class);
			$prod = $wc->getProduct($productId);
			if ($prod === null) {
				throw new \RuntimeException("WooCommerce product #{$productId} not found.");
			}
			$res = $this->container->get(\HTD\AISEO\WooCommerce\ProductEngine::class)->proposeProductUpdate($prod, [
				'description'       => (string) wp_unslash($_POST['description'] ?? ''),
				'short_description' => (string) wp_unslash($_POST['short_description'] ?? ''),
			], get_current_user_id());
			$this->setNotice('success', "Product content proposal Approval #{$res['approval_id']} created.");
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-products'));
		exit;
	}

	public function handleSavePrompt(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_save_prompt', Capabilities::MANAGE);
		$id = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['prompt_id'] ?? ''));
		try {
			$reg = $this->container->get(PromptRegistry::class);
			$data = $reg->get($id)->toArray();
			$data['user_template'] = (string) wp_unslash($_POST['user_template'] ?? $data['user_template']);
			$parts = explode('.', (string) $data['version']);
			$parts[2] = (string) (((int) ($parts[2] ?? 0)) + 1);
			$data['version'] = implode('.', $parts);
			$reg->save(\HTD\AISEO\Prompt\PromptTemplate::fromArray($data));
			$this->setNotice('success', "Prompt '{$id}' saved as v{$data['version']}.");
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-prompts'));
		exit;
	}

	public function handleMediaAction(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_media_action', Capabilities::MANAGE);
		$prompt = sanitize_text_field((string) wp_unslash($_POST['image_prompt'] ?? ''));
		$providerId = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['provider_id'] ?? 'openai'));
		try {
			$res = $this->container->get(\HTD\AISEO\Media\ImageGenerationService::class)->generateImage($providerId, $prompt);
			$this->setNotice('success', 'Image generated: ' . ($res['url'] ?? 'OK'));
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-drafts'));
		exit;
	}

	public function handleInternalLinks(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_internal_links', Capabilities::MANAGE);
		$draftId = absint($_POST['draft_id'] ?? 0);
		try {
			$draft = $this->container->get(ContentDraftRepository::class)->find($draftId);
			if ($draft === null) {
				throw new \RuntimeException("Draft #{$draftId} not found.");
			}
			$links = $this->container->get(\HTD\AISEO\SEO\InternalLinkProposalEngine::class)->propose(
				(string) $draft['html_content'],
				(string) $draft['title'],
				[]
			);
			$this->setNotice('success', 'Proposed ' . count($links) . ' internal link(s) for Draft #' . $draftId . '.');
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-drafts'));
		exit;
	}

	public function handleExecuteApproval(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_execute_approval', Capabilities::APPROVE);
		$approvalId = absint($_POST['approval_id'] ?? 0);
		$draftId = absint($_POST['draft_id'] ?? 0);
		$targetStatus = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['target_status'] ?? 'draft'));
		try {
			$res = $this->container->get(\HTD\AISEO\Publishing\PublishingEngine::class)->execute($draftId, $approvalId, $targetStatus);
			$this->setNotice('success', 'Executed WP Post #' . ($res['wp_post_id'] ?? 0) . ' (' . ($res['status'] ?? $targetStatus) . ').');
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-approvals'));
		exit;
	}

	public function handleQueueAction(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_queue_action', Capabilities::MANAGE);
		$op = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['queue_op'] ?? ''));
		$jobId = absint($_POST['job_id'] ?? 0);
		try {
			$queue = $this->container->get(QueueEngine::class);
			if ($op === 'pause') {
				$this->container->get(SettingsRegistry::class)->update(['emergency_stop' => true]);
				$this->container->get(CronScheduler::class)->syncWithSettings();
				$this->setNotice('warning', 'Queue paused (EMERGENCY_STOP enabled).');
			} elseif ($op === 'resume') {
				$this->container->get(SettingsRegistry::class)->update(['emergency_stop' => false]);
				$this->container->get(CronScheduler::class)->syncWithSettings();
				$this->setNotice('success', 'Queue resumed.');
			} elseif ($op === 'retry' && $jobId > 0) {
				$this->setNotice($queue->retry($jobId) ? 'success' : 'error', "Retry job #{$jobId}.");
			} elseif ($op === 'cancel' && $jobId > 0) {
				$this->setNotice($queue->cancel($jobId) ? 'success' : 'error', "Cancel job #{$jobId}.");
			} elseif ($op === 'run_cron') {
				$state = $this->container->get(CronScheduler::class)->runScheduled(2);
				$this->setNotice('info', 'Cron run: ' . ($state['last_status'] ?? '') . ' - ' . ($state['last_message'] ?? ''));
			}
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-operations'));
		exit;
	}

	public function handleGoogleSync(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_google_sync', Capabilities::MANAGE);
		$service = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['service'] ?? 'gsc'));
		$op = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['op'] ?? 'sync'));
		$prop = trim((string) wp_unslash($_POST['property'] ?? ''));
		try {
			if ($service === 'ga4') {
				$ga4 = $this->container->get(\HTD\AISEO\Analytics\GA4Adapter::class);
				if ($op === 'disconnect') {
					$ga4->disconnect();
					$this->setNotice('info', 'GA4 disconnected.');
				} else {
					$res = $ga4->syncReport($prop);
					$this->setNotice('success', "GA4 synced {$res['row_count']} rows (snapshot #{$res['snapshot_id']}).");
				}
			} else {
				$gsc = $this->container->get(\HTD\AISEO\Analytics\GSCAdapter::class);
				if ($op === 'disconnect') {
					$gsc->disconnect();
					$this->setNotice('info', 'GSC disconnected.');
				} else {
					$res = $gsc->syncSearchAnalytics($prop);
					$this->setNotice('success', "GSC synced {$res['row_count']} rows (snapshot #{$res['snapshot_id']}).");
				}
			}
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-growth'));
		exit;
	}

	public function handleGscImport(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_gsc_import', Capabilities::MANAGE);
		$property = trim((string) wp_unslash($_POST['property'] ?? ''));
		$reportType = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['report_type'] ?? 'queries'));
		$dateRange = sanitize_text_field((string) wp_unslash($_POST['date_range'] ?? '28d'));
		$csv = (string) wp_unslash($_POST['csv_content'] ?? '');
		try {
			$res = $this->container->get(\HTD\AISEO\Analytics\GSCAdapter::class)->importCsvReport($csv, $property, $reportType, $dateRange);
			$this->setNotice('success', "Imported {$res['row_count']} GSC rows (snapshot #{$res['snapshot_id']}, provenance: {$res['provenance']}).");
		} catch (\Throwable $e) {
			$this->setNotice('error', $e->getMessage());
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-growth'));
		exit;
	}

	private function setNotice(string $type, string $message): void {
		if (function_exists('set_transient') && function_exists('get_current_user_id')) {
			set_transient('htd_ai_seo_notice_' . get_current_user_id(), ['type' => $type, 'message' => $message], 120);
		}
	}

	/**
	 * @param array<string, mixed> $vars
	 */
	private function renderTemplate(string $name, array $vars = []): void {
		$file = HTD_AI_SEO_PATH . 'templates/' . $name . '.php';
		if (!is_file($file)) {
			echo '<div class="wrap"><h1>Template not found</h1></div>';
			return;
		}
		if (function_exists('get_transient') && function_exists('get_current_user_id')) {
			$notice = get_transient('htd_ai_seo_notice_' . get_current_user_id());
			if (is_array($notice) && !empty($notice['message'])) {
				delete_transient('htd_ai_seo_notice_' . get_current_user_id());
				$cls = ($notice['type'] ?? 'info') === 'error' ? 'notice-error' : (($notice['type'] ?? '') === 'warning' ? 'notice-warning' : 'notice-success');
				echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . esc_html((string) $notice['message']) . '</p></div>';
			}
		}
		extract($vars, EXTR_SKIP);
		include $file;
	}
}
