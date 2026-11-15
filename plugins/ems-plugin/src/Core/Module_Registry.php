<?php
namespace EMS\Core;

use EMS\Contracts\ModuleInterface;

class Module_Registry {

	/**
	 * Registered modules keyed by slug.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();

	/**
	 * Degraded modules with failure error message keyed by slug.
	 *
	 * @var array<string, string>
	 */
	private array $degraded_modules = array();

	/**
	 * Register a module instance.
	 *
	 * @param ModuleInterface $module
	 */
	public function register_module( ModuleInterface $module ): void {
		$this->modules[ $module->getSlug() ] = $module;
	}

	/**
	 * Boot all registered modules that are enabled, isolating failures.
	 */
	public function boot_all(): void {
		foreach ( $this->modules as $slug => $module ) {
			if ( ! Feature_Flags::is_enabled( $slug, $module->isAlwaysOn() ) ) {
				continue;
			}

			try {
				$module->register();
			} catch ( \Throwable $e ) {
				Audit_Logger::log( 'module_boot_failed:' . $slug );
				error_log( sprintf( '[EMS] Module register failed for %s: %s', $slug, $e->getMessage() ) );
				$this->degraded_modules[ $slug ] = $e->getMessage();
				continue;
			}

			try {
				$module->boot();
			} catch ( \Throwable $e ) {
				Audit_Logger::log( 'module_boot_failed:' . $slug );
				error_log( sprintf( '[EMS] Module boot failed for %s: %s', $slug, $e->getMessage() ) );
				$this->degraded_modules[ $slug ] = $e->getMessage();
			}
		}
	}

	/**
	 * Get all registered modules.
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function get_modules(): array {
		return $this->modules;
	}

	/**
	 * Get a registered module by slug.
	 *
	 * @param string $slug
	 * @return ModuleInterface|null
	 */
	public function get_module( string $slug ): ?ModuleInterface {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * Get degraded modules map (slug => error message).
	 *
	 * @return array<string, string>
	 */
	public function get_degraded_modules(): array {
		return $this->degraded_modules;
	}

	/**
	 * Check if a module is marked degraded.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public function is_degraded( string $slug ): bool {
		return isset( $this->degraded_modules[ $slug ] );
	}
}
