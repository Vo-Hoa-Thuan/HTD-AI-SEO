<?php
declare(strict_types=1);

namespace HTD\AISEO\Admin;

use HTD\AISEO\AI\ProviderConfiguration;
use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Content\ContentDraftRepository;
use HTD\AISEO\Core\Container;
use HTD\AISEO\Core\HealthCheck;
use HTD\AISEO\Core\ModuleRegistry;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Keyword\KeywordRepository;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Prompt\PromptRegistry;
use HTD\AISEO\Security\RequestVerifier;
use HTD\AISEO\Security\Sanitizer;
use HTD\AISEO\Settings\SettingsRegistry;
use HTD\AISEO\Support\Capabilities;
use HTD\AISEO\Token\TokenBudgetManager;

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
			['htd-ai-seo-keywords', 'Keywords & Audit', $cap, [$this, 'renderKeywords']],
			['htd-ai-seo-drafts', 'Content Drafts & QA', $cap, [$this, 'renderDrafts']],
			['htd-ai-seo-rewrite', 'Rewrite Posts', $cap, [$this, 'renderRewrite']],
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
		$this->renderTemplate('ai-playground', ['settings' => $settings]);
	}

	public function handleSaveProvider(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_save_provider', Capabilities::MANAGE_SETTINGS);
		$providerId = Sanitizer::sanitizeIdentifier((string) wp_unslash($_POST['provider_id'] ?? ''));
		if ($providerId !== '') {
			$this->container->get(ProviderConfiguration::class)->save($providerId, [
				'enabled'          => !empty($_POST['enabled']),
				'model'            => wp_unslash($_POST['model'] ?? ''),
				'max_tokens'       => $_POST['max_tokens'] ?? 2048,
				'timeout'          => $_POST['timeout'] ?? 45,
				'daily_budget_usd' => $_POST['daily_budget_usd'] ?? 5.0,
			]);
			$this->container->get(Logger::class)->log('ai_providers', 'provider_updated', 'AUDIT', 'OK', ['provider' => $providerId]);
		}
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-ai-providers&updated=1'));
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
		$this->container->get(Logger::class)->log('settings', 'settings_updated', 'AUDIT', 'OK');
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-settings&updated=1'));
		exit;
	}

	public function handleToggleEmergency(): void {
		RequestVerifier::verifyAdminAction('htd_ai_seo_toggle_emergency', Capabilities::MANAGE);
		$registry = $this->container->get(SettingsRegistry::class);
		$current = (bool) $registry->get('emergency_stop', false);
		$registry->update(['emergency_stop' => !$current]);
		$this->container->get(Logger::class)->log('security', 'emergency_stop_toggled', 'SECURITY', 'OK', [
			'emergency_stop' => !$current,
		]);
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-dashboard&updated=1'));
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
		wp_safe_redirect(admin_url('admin.php?page=htd-ai-seo-approvals&updated=1'));
		exit;
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
		extract($vars, EXTR_SKIP);
		include $file;
	}
}
