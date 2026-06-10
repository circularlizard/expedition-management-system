<?php
$f6 = 'temp/ems-training-report-2026-06-10 (6).csv';
$f7 = 'temp/ems-training-report-2026-06-10 (7).csv';

$h6 = fopen($f6, 'r');
$h7 = fopen($f7, 'r');

$headers6 = fgetcsv($h6);
$headers7 = fgetcsv($h7);

$data6 = [];
while ($row = fgetcsv($h6)) { $data6[$row[1]] = $row; }
$data7 = [];
while ($row = fgetcsv($h7)) { $data7[$row[1]] = $row; }

echo "Comparing file (6) with (7)...\n";
foreach ($data7 as $email => $row7) {
    if (!isset($data6[$email])) {
        echo "New user in (7): $email\n";
        continue;
    }
    $row6 = $data6[$email];
    for ($i = 2; $i < count($row7); $i++) {
        if ($row6[$i] !== $row7[$i]) {
            echo "Change for $email in {$headers7[$i]}: (6)='{$row6[$i]}' -> (7)='{$row7[$i]}'\n";
        }
    }
}
