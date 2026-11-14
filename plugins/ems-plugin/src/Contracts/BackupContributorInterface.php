<?php
namespace EMS\Contracts;

interface BackupContributorInterface {
	/**
	 * @return array<string> List of custom database table names (without prefix).
	 */
	public function getBackupTables(): array;

	/**
	 * @return array<string> List of custom post types.
	 */
	public function getBackupPostTypes(): array;

	/**
	 * @return array<string> List of WordPress option names.
	 */
	public function getBackupOptionNames(): array;

	/**
	 * @return array<string, mixed>
	 */
	public function exportData(): array;

	/**
	 * @param array<string, mixed> $data
	 * @return bool
	 */
	public function importData( array $data ): bool;
}
