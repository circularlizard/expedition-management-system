<?php
declare(strict_types=1);

namespace EMS\Contracts\DTOs;

class ExpeditionPreferenceDTO {
	/**
	 * @param int                  $expeditionId
	 * @param string               $dietaryRequirements
	 * @param string               $medicalNotes
	 * @param array<string, mixed> $additionalData
	 */
	public function __construct(
		public readonly int $expeditionId,
		public readonly string $dietaryRequirements = '',
		public readonly string $medicalNotes = '',
		public readonly array $additionalData = []
	) {}

	/**
	 * @return array{expeditionId: int, dietaryRequirements: string, medicalNotes: string, additionalData: array<string, mixed>}
	 */
	public function toArray(): array {
		return [
			'expeditionId'        => $this->expeditionId,
			'dietaryRequirements' => $this->dietaryRequirements,
			'medicalNotes'        => $this->medicalNotes,
			'additionalData'      => $this->additionalData,
		];
	}
}
