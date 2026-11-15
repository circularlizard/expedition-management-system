<?php
namespace EMS\Contracts;

interface ModuleInterface {
	public function getSlug(): string;
	public function isAlwaysOn(): bool;
	public function register(): void;
	public function boot(): void;
}
