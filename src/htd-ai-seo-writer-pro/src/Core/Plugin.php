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
use HTD\AISEO\AI\Providers\DeepSeekProvider;
use HTD\AISEO\AI\Providers\GeminiProvider;
use HTD\AISEO\AI\Providers\GrokProvider;
use HTD\AISEO\AI\Providers\OpenAIProvider;
use HTD\AISEO\Approval\ApprovalEngine;
use HTD\AISEO\Brand\BrandProfile;
use HTD\AISEO\Credentials\CredentialVault;
use HTD\AISEO\Database\Migrator;
use HTD\AISEO\Logging\Logger;
use HTD\AISEO\Prompt\PromptRegistry;
use HTD\AISEO\Prompt\PromptRenderer;
use HTD\AISEO\Queue\QueueEngine;
use HTD\AISEO\Security\ExternalRequestGuard;
use HTD\AISEO\Security\MutationGuard;
use HTD\AISEO\Settings\SettingsRegistry;
use HTD\AISEO\Token\TokenBudgetManager;

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
			return $reg;
		});
		$this->container->singleton(PromptRenderer::class, static fn(): PromptRenderer => new PromptRenderer());
		$this->container->singleton(PromptRegistry::class, static fn(Container $c): PromptRegistry => new PromptRegistry($c->get(PromptRenderer::class)));
		$this->container->singleton(BrandProfile::class, static fn(): BrandProfile => new BrandProfile());
		$this->container->singleton(TokenBudgetManager::class, static fn(Container $c): TokenBudgetManager => new TokenBudgetManager($c->get(ProviderConfiguration::class)));
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
		$this->modules->register('keyword_engine', 'NOT_IMPLEMENTED', 'PHASE_04', ['database', 'ai_providers'], 'COMING_IN_PHASE_04');
		$this->modules->register('content_engine', 'NOT_IMPLEMENTED', 'PHASE_04', ['prompt_engine', 'ai_providers', 'mutation_guard'], 'COMING_IN_PHASE_04');
		$this->modules->register('woocommerce_seo', 'NOT_IMPLEMENTED', 'PHASE_05', ['content_engine'], 'COMING_IN_PHASE_05');
		$this->modules->register('htd_seo_extensions', 'NOT_IMPLEMENTED', 'PHASE_06', ['woocommerce_seo'], 'COMING_IN_PHASE_06');
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
