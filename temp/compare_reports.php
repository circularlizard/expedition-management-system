<?php
$ems_file = 'temp/ems-training-report-2026-06-10 (6).csv';
$tutor_file = 'temp/tutorlms-students.csv';

if (!file_exists($ems_file) || !file_exists($tutor_file)) {
    die("Files missing\n");
}

$ems_handle = fopen($ems_file, 'r');
$tutor_handle = fopen($tutor_file, 'r');

$ems_headers = fgetcsv($ems_handle);
// Column 0: Name, 1: Email, 2+: Courses
$courses = array_slice($ems_headers, 2);

$ems_data = [];
while (($row = fgetcsv($ems_handle)) !== false) {
    if (count($row) < 2) continue;
    $email = strtolower(trim($row[1]));
    $statuses = array_slice($row, 2);
    $ems_data[$email] = [
        'name' => $row[0],
        'statuses' => array_combine($courses, $statuses)
    ];
}
fclose($ems_handle);

$tutor_headers = fgetcsv($tutor_handle);
// register_date,display_name,email,enrolled_course,course_progress,...
$tutor_data = [];
while (($row = fgetcsv($tutor_handle)) !== false) {
    if (count($row) < 4) continue;
    $email = strtolower(trim($row[2]));
    $course = trim($row[3]);
    $progress = (int) str_replace('%', '', $row[4]);
    $tutor_data[$email][$course] = $progress;
}
fclose($tutor_handle);

echo "Comparing " . count($ems_data) . " EMS users with " . count($tutor_data) . " TutorLMS users...\n\n";

$mismatches = [];

foreach ($ems_data as $email => $data) {
    foreach ($data['statuses'] as $course => $ems_status) {
        $tutor_progress = $tutor_data[$email][$course] ?? null;

        $mismatch = false;
        $reason = "";

        if ($ems_status === 'Complete') {
            if ($tutor_progress === null) {
                $mismatch = true;
                $reason = "EMS says Complete, but NOT ENROLLED in TutorLMS";
            } elseif ($tutor_progress < 100) {
                $mismatch = true;
                $reason = "EMS says Complete, but TutorLMS says $tutor_progress%";
            }
        } elseif ($ems_status === 'In Progress') {
            if ($tutor_progress === null) {
                $mismatch = true;
                $reason = "EMS says In Progress, but NOT ENROLLED in TutorLMS";
            } elseif ($tutor_progress === 100) {
                $mismatch = true;
                $reason = "EMS says In Progress, but TutorLMS says 100%";
            }
        } elseif ($ems_status === 'Not Enrolled') {
            if ($tutor_progress !== null) {
                $mismatch = true;
                $reason = "EMS says Not Enrolled, but TutorLMS says $tutor_progress%";
            }
        }

        if ($mismatch) {
            $mismatches[] = [
                'name' => $data['name'],
                'email' => $email,
                'course' => $course,
                'ems' => $ems_status,
                'tutor' => $tutor_progress === null ? "N/A" : $tutor_progress . "%",
                'reason' => $reason
            ];
        }
    }
}

if (empty($mismatches)) {
    echo "No mismatches found!\n";
} else {
    echo "Found " . count($mismatches) . " mismatches:\n";
    printf("%-20s | %-30s | %-15s | %-10s | %s\n", "Name", "Course", "EMS", "Tutor", "Reason");
    echo str_repeat("-", 100) . "\n";
    foreach ($mismatches as $m) {
        printf("%-20s | %-30s | %-15s | %-10s | %s\n", 
            substr($m['name'], 0, 20), 
            substr($m['course'], 0, 30), 
            $m['ems'], 
            $m['tutor'], 
            $m['reason']
        );
    }
}
