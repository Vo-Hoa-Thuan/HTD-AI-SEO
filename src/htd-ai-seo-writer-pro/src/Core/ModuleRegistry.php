<?php
declare(strict_types=1);

namespace HTD\AISEO\Core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Tracks plugin modules, dependencies, and implementation phases.
 */
final class ModuleRegistry {
	/** @var array<string, array{status: string, phase: string, dependencies: string[], note: string}> */
	private array $modules = [];

	/**
	 * @param string[] $dependencies
	 */
	public function register(string $name, string $status, string $phase, array $dependencies = [], string $note = ''): void {
		$this->modules[$name] = [
			'status'       => $status,
			'phase'        => $phase,
			'dependencies' => $dependencies,
			'note'         => $note,
		];
	}

	/**
	 * @return array<string, array{status: string, phase: string, dependencies: string[], note: string}>
	 */
	public function all(): array {
		return $this->modules;
	}

	public function resolveOrder(): array {
		$resolved = [];
		$visiting = [];

		$visit = function (string $name) use (&$visit, &$resolved, &$visiting): void {
			if (isset($resolved[$name])) {
				return;
			}
			if (isset($visiting[$name])) {
				throw new \RuntimeException("Circular module dependency detected: {$name}");
			}
			$visiting[$name] = true;
			$deps = $this->modules[$name]['dependencies'] ?? [];
			foreach ($deps as $dep) {
				if (!isset($this->modules[$dep])) {
					throw new \RuntimeException("Missing dependency '{$dep}' required by '{$name}'");
				}
				$visit($dep);
			}
			unset($visiting[$name]);
			$resolved[$name] = true;
		};

		foreach (array_keys($this->modules) as $name) {
			$visit($name);
		}
		return array_keys($resolved);
	}
}
