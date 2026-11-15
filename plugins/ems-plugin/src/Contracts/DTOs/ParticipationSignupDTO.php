<?php
declare(strict_types=1);

namespace EMS\Contracts\DTOs;

class ParticipationSignupDTO {
	/**
	 * @param int                  $formId
	 * @param int                  $entryId
	 * @param string               $scoutId
	 * @param string               $fullName
	 * @param string               $email
	 * @param array<string, mixed> $preferences
	 * @param string               $submittedAt
	 */
	public function __construct(
		public readonly int $formId,
		public readonly int $entryId,
		public readonly string $scoutId,
		public readonly string $fullName,
		public readonly string $email,
		public readonly array $preferences = [],
		public readonly string $submittedAt = ''
	) {}

	/**
	 * @return array{formId: int, entryId: int, scoutId: string, fullName: string, email: string, preferences: array<string, mixed>, submittedAt: string}
	 */
	public function toArray(): array {
		return [
			'formId'      => $this->formId,
			'entryId'     => $this->entryId,
			'scoutId'     => $this->scoutId,
			'fullName'    => $this->fullName,
			'email'       => $this->email,
			'preferences' => $this->preferences,
			'submittedAt' => $this->submittedAt,
		];
	}
}
