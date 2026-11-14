<?php
declare(strict_types=1);

namespace EMS\Contracts;

use EMS\Contracts\DTOs\TrainingStatusDTO;

interface TrainingComplianceInterface {
	public function getExplorerCourseProgress( int $scoutId ): TrainingStatusDTO;
}
