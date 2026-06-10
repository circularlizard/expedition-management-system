<?php
$f8 = 'temp/ems-training-report-2026-06-10 (8).csv';
if (!file_exists($f8)) die("File (8) missing\n");

$h8 = fopen($f8, 'r');
$headers = fgetcsv($h8);
$users_to_check = [
    'natasha.strachan@icloud.com' => '5. First Aid eLearning',
    'stellagraham2009@gmail.com' => '5. First Aid eLearning'
];

echo "Checking specific fixes in report (8)...\n";
while ($row = fgetcsv($h8)) {
    $email = strtolower(trim($row[1]));
    if (isset($users_to_check[$email])) {
        $course = $users_to_check[$email];
        $course_idx = array_search($course, $headers);
        if ($course_idx !== false) {
            echo "- $email: $course = '{$row[$course_idx]}'\n";
        }
    }
}
fclose($h8);
