<?php
declare(strict_types=1);

namespace HTD\AISEO\Core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Lightweight dependency injection container.
 */
final class Container {
	/** @var array<string, callable> */
	private array $factories = [];

	/** @var array<string, object> */
	private array $instances = [];

	public function singleton(string $id, callable $factory): void {
		$this->factories[$id] = $factory;
	}

	public function get(string $id): object {
		if (isset($this->instances[$id])) {
			return $this->instances[$id];
		}
		if (!isset($this->factories[$id])) {
			throw new \RuntimeException("Service not registered in container: {$id}");
		}
		$instance = ($this->factories[$id])($this);
		$this->instances[$id] = $instance;
		return $instance;
	}

	public function has(string $id): bool {
		return isset($this->factories[$id]) || isset($this->instances[$id]);
	}
}
