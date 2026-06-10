<?php
require_once('wordpress/wp-load.php');
global $wpdb;

$email = 'natasha.strachan@icloud.com';
$user = get_user_by('email', $email);
if (!$user) die("User not found\n");
$uid = $user->ID;

echo "User ID: $uid ($email)\n";

$course_id = 0;
$courses = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'courses' AND post_title LIKE '%First Aid%'");
foreach ($courses as $c) {
    echo "Found Course: {$c->post_title} (ID {$c->ID})\n";
    $course_id = $c->ID;
}

if (!$course_id) die("Course not found\n");

// Re-run Step 3 logic for this user/course
$c_ph = $course_id;
$content_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT c.ID as content_id, c.post_type as content_type, t.post_parent as course_id
     FROM {$wpdb->posts} c
     JOIN {$wpdb->posts} t ON c.post_parent = t.ID
     WHERE t.post_type   = 'topics'
     AND   c.post_status IN ('publish', 'private')
     AND   c.post_type   IN ('tutor_lesson', 'lesson', 'tutor_quiz', 'tutor_assignments')
     AND   t.post_parent = %d
     UNION
     SELECT c.ID as content_id, c.post_type as content_type, c.post_parent as course_id
     FROM {$wpdb->posts} c
     WHERE c.post_type   IN ('tutor_lesson', 'lesson', 'tutor_quiz', 'tutor_assignments')
     AND   c.post_status IN ('publish', 'private')
     AND   c.post_parent = %d",
    $course_id, $course_id
));

$lessons = [];
$assignments = [];
foreach ($content_rows as $row) {
    if ($row->content_type === 'tutor_lesson' || $row->content_type === 'lesson') $lessons[] = $row->content_id;
    else if ($row->content_type === 'tutor_assignments') $assignments[] = $row->content_id;
}

echo "Total Content: " . count($content_rows) . "\n";
echo "- Lessons: " . implode(',', $lessons) . "\n";
echo "- Assignments: " . implode(',', $assignments) . "\n";

// Check completion
$done = 0;
foreach ($lessons as $lid) {
    $meta = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $uid, "_tutor_completed_lesson_id_$lid"));
    if ($meta) { echo "Lesson $lid DONE\n"; $done++; }
    else echo "Lesson $lid NOT DONE\n";
}
foreach ($assignments as $aid) {
    $meta = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $uid, "_tutor_completed_lesson_id_$aid"));
    if ($meta) { echo "Assignment $aid DONE\n"; $done++; }
    else {
        echo "Assignment $aid NOT DONE via meta\n";
        // Check usage table
        $cb_table = $wpdb->prefix . 'tutor_cb_content_usage';
        $usage = $wpdb->get_var($wpdb->prepare("SELECT usage_id FROM $cb_table WHERE user_id = %d AND content_id = %d", $uid, $aid));
        if ($usage) { echo "Assignment $aid DONE via usage table\n"; $done++; }
        else echo "Assignment $aid NOT DONE via usage table\n";
    }
}

echo "Final: $done / " . count($content_rows) . "\n";
