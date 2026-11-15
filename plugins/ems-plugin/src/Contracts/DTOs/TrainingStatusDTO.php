<?php
declare(strict_types=1);

namespace EMS\Contracts\DTOs;

class TrainingStatusDTO {
	public function __construct(
		public readonly bool $isComplete,
		public readonly string $status, // 'completed', 'in_progress', 'not_started', 'unavailable'
		public readonly int $completedModules = 0,
		public readonly int $totalModules = 0,
		public readonly ?string $lastCompletedDate = null
	) {}

	public static function unavailable(): self {
		return new self(
			isComplete: false,
			status: 'unavailable',
			completedModules: 0,
			totalModules: 0,
			lastCompletedDate: null
		);
	}

	/**
	 * @return array{isComplete: bool, status: string, completedModules: int, totalModules: int, lastCompletedDate: ?string}
	 */
	public function toArray(): array {
		return [
			'isComplete'        => $this->isComplete,
			'status'            => $this->status,
			'completedModules'  => $this->completedModules,
			'totalModules'      => $this->totalModules,
			'lastCompletedDate' => $this->lastCompletedDate,
		];
	}
}
