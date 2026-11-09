
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

/*
 * صلاحيات أدوار نظام المدرسة.
 * الأرقام مطابقة للأدوار الموجودة في جدول roles.
 */

$permissions = [
    'system_admin' => [
        'index.php',
        'admin_dashboard.php',
        'students.php',
        'guardians.php',
        'teachers.php',
        'classrooms.php',
        'subjects.php',
        'academic_years.php',
        'teaching_assignments.php',
        'timetable.php',
        'attendance.php',
        'exams.php',
        'grades.php',
        'behavior.php',
        'homework.php',
        'homework_submissions.php',
        'reports.php',
        'fees.php',
        'payments.php',
        'expenses.php',
        'users.php',
        'announcements.php',
        'notifications.php',
        'messages.php',
        'audit_logs.php',
        'settings.php',
        'theme_settings.php',
        'change_password.php',
    ],

    'school_admin' => [
        'index.php',
        'students.php',
        'guardians.php',
        'teachers.php',
        'classrooms.php',
        'subjects.php',
        'academic_years.php',
        'teaching_assignments.php',
        'timetable.php',
        'attendance.php',
        'exams.php',
        'grades.php',
        'behavior.php',
        'homework.php',
        'homework_submissions.php',
        'reports.php',
        'fees.php',
        'payments.php',
        'expenses.php',
        'users.php',
        'announcements.php',
        'notifications.php',
        'messages.php',
        'change_password.php',
    ],

    'accountant' => [
        'index.php',
        'fees.php',
        'payments.php',
        'expenses.php',
        'reports.php',
        'change_password.php',
    ],

    'teacher' => [
        'index.php',
        'students.php',
        'classrooms.php',
        'subjects.php',
        'timetable.php',
        'attendance.php',
        'exams.php',
        'grades.php',
        'behavior.php',
        'homework.php',
        'homework_submissions.php',
        'announcements.php',
        'notifications.php',
        'messages.php',
        'change_password.php',
    ],

    'student' => [
        'index.php',
        'timetable.php',
        'grades.php',
        'homework.php',
        'announcements.php',
        'notifications.php',
        'messages.php',
        'change_password.php',
    ],

    'guardian' => [
        'index.php',
        'reports.php',
        'fees.php',
        'payments.php',
        'announcements.php',
        'notifications.php',
        'messages.php',
        'change_password.php',
    ],
];

/* تحديد الدور من قاعدة البيانات */
$stmt = $pdo->prepare(
    'SELECT role_name FROM roles WHERE id = ? LIMIT 1'
);
$stmt->execute([(int) $_SESSION['role_id']]);
$roleName = $stmt->fetchColumn();

/* تحديد الصفحة الحالية */
$currentPage = basename(
    parse_url($_SERVER['SCRIPT_NAME'], PHP_URL_PATH)
);

/* التحقق من صلاحية الوصول */
if (
    !$roleName ||
    !isset($permissions[$roleName]) ||
    !in_array($currentPage, $permissions[$roleName], true)
) {
    http_response_code(403);
    exit('عذراً، ليست لديك صلاحية للوصول إلى هذه الصفحة.');
}

/* دالة التحقق من صلاحيات الصفحات */
if (!function_exists('canAccess')) {
    function canAccess(string $page): bool
    {
        global $permissions, $roleName;

        return isset($permissions[$roleName]) &&
            in_array($page, $permissions[$roleName], true);
    }
}
