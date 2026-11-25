<?php
declare(strict_types=1);

namespace HTD\AISEO\Prompt;

use HTD\AISEO\Contracts\PromptEngineInterface;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Local Prompt Registry with CRUD, versioning, restore, and validated JSON import/export.
 */
final class PromptRegistry implements PromptEngineInterface {
	public const OPTION_NAME  = 'htd_ai_seo_prompts';
	public const HISTORY_OPT  = 'htd_ai_seo_prompt_history';

	public function __construct(private readonly ?PromptRenderer $renderer = null) {}

	/**
	 * @return array<string, PromptTemplate>
	 */
	public function all(): array {
		$defaults = DefaultPromptLibrary::all();
		$stored = function_exists('get_option') ? get_option(self::OPTION_NAME, []) : [];
		if (!is_array($stored)) {
			return $defaults;
		}
		foreach ($stored as $id => $raw) {
			if (is_array($raw)) {
				$defaults[(string) $id] = PromptTemplate::fromArray($raw);
			}
		}
		return $defaults;
	}

	public function get(string $id): PromptTemplate {
		$all = $this->all();
		if (!isset($all[$id])) {
			throw new \RuntimeException("Prompt template '{$id}' not found.");
		}
		return $all[$id];
	}

	public function save(PromptTemplate $tpl): void {
		PromptSafetyGuard::assertSafeTemplate($tpl);
		$stored = function_exists('get_option') ? get_option(self::OPTION_NAME, []) : [];
		if (!is_array($stored)) {
			$stored = [];
		}

		// Record previous version in history if it existed
		$all = $this->all();
		if (isset($all[$tpl->id])) {
			$this->pushHistory($all[$tpl->id]);
		}

		$data = $tpl->toArray();
		$data['updated_at'] = gmdate('Y-m-d H:i:s');
		$stored[$tpl->id] = $data;
		if (function_exists('update_option')) {
			update_option(self::OPTION_NAME, $stored, false);
		}
	}

	public function duplicate(string $sourceId, string $newId, string $newName): PromptTemplate {
		$src = $this->get($sourceId)->toArray();
		$src['id'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $newId) ?? '';
		if ($src['id'] === '') {
			throw new \InvalidArgumentException('Invalid new template ID.');
		}
		$src['name'] = $newName;
		$src['version'] = '1.0.0';
		$src['created_at'] = gmdate('Y-m-d H:i:s');
		$tpl = PromptTemplate::fromArray($src);
		$this->save($tpl);
		return $tpl;
	}

	public function setStatus(string $id, string $status): void {
		if (!in_array($status, ['active', 'disabled'], true)) {
			throw new \InvalidArgumentException('Invalid status.');
		}
		$data = $this->get($id)->toArray();
		$data['status'] = $status;
		$this->save(PromptTemplate::fromArray($data));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function history(string $id): array {
		$allHist = function_exists('get_option') ? get_option(self::HISTORY_OPT, []) : [];
		return is_array($allHist[$id] ?? null) ? $allHist[$id] : [];
	}

	public function restoreVersion(string $id, string $version): PromptTemplate {
		foreach ($this->history($id) as $entry) {
			if (($entry['version'] ?? '') === $version) {
				$tpl = PromptTemplate::fromArray($entry);
				$this->save($tpl);
				return $tpl;
			}
		}
		throw new \RuntimeException("Version '{$version}' not found in history for '{$id}'.");
	}

	public function exportJson(string $id): string {
		return (string) json_encode($this->get($id)->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public function importJson(string $json): PromptTemplate {
		$data = json_decode($json, true);
		if (!is_array($data) || empty($data['id']) || empty($data['system_instructions']) || empty($data['user_template'])) {
			throw new \InvalidArgumentException('Invalid prompt template JSON.');
		}
		$tpl = PromptTemplate::fromArray($data);
		$this->save($tpl);
		return $tpl;
	}

	public function buildPrompt(string $templateKey, array $variables = []): array {
		$tpl = $this->get($templateKey);
		if ($tpl->status !== 'active') {
			throw new \RuntimeException("Prompt template '{$templateKey}' is disabled.");
		}
		$renderer = $this->renderer ?? new PromptRenderer();
		return $renderer->render($tpl, $variables);
	}

	private function pushHistory(PromptTemplate $tpl): void {
		$allHist = function_exists('get_option') ? get_option(self::HISTORY_OPT, []) : [];
		if (!is_array($allHist)) {
			$allHist = [];
		}
		$list = is_array($allHist[$tpl->id] ?? null) ? $allHist[$tpl->id] : [];
		array_unshift($list, $tpl->toArray());
		$allHist[$tpl->id] = array_slice($list, 0, 10);
		if (function_exists('update_option')) {
			update_option(self::HISTORY_OPT, $allHist, false);
		}
	}
}
