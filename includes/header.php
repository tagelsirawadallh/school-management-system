
<?php
$pageTitle = $pageTitle ?? 'نظام إدارة المدرسة';
$hideSidebar = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php';

$schoolName = 'مدرسة الملاذ';
$schoolLogo = '';
$themeColors = [
    'primary' => '#155e6b',
    'accent' => '#c49a5a',
    'background' => '#f3f6f8'
];

$isThemeColor = static function ($color): bool {
    return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
};

$mixThemeColor = static function (string $color, int $amount): string {
    $hex = ltrim($color, '#');
    $channels = [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    ];
    $channels = array_map(
        static fn (int $channel): int => (int) round(
            $channel + ((255 - $channel) * $amount / 100)
        ),
        $channels
    );

    return sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]);
};

$darkenThemeColor = static function (string $color, int $amount): string {
    $hex = ltrim($color, '#');
    $channels = [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    ];
    $channels = array_map(
        static fn (int $channel): int => (int) round($channel * (100 - $amount) / 100),
        $channels
    );

    return sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]);
};

/*
 * قراءة دور المستخدم الحالي.
 * يجب أن تكون الجلسة قد بدأت عبر auth.php.
 */
$currentRole = (int) ($_SESSION['role_id'] ?? 0);

/*
 * روابط القائمة المسموحة لكل دور:
 * 1 مدير النظام
 * 2 مدير المدرسة
 * 3 المحاسب
 * 4 المعلم
 * 5 الطالب
 * 6 ولي الأمر
 */
$menuPermissions = [
    1 => ['all'],

    2 => [
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
        'settings.php',
        'change_password.php'
    ],

    3 => [
        'index.php',
        'fees.php',
        'payments.php',
        'expenses.php',
        'reports.php',
        'change_password.php'
    ],

    4 => [
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
        'change_password.php'
    ],

    5 => [
        'index.php',
        'timetable.php',
        'grades.php',
        'homework.php',
        'announcements.php',
        'notifications.php',
        'messages.php',
        'change_password.php'
    ],

    6 => [
        'index.php',
        'reports.php',
        'fees.php',
        'payments.php',
        'announcements.php',
        'notifications.php',
        'messages.php',
        'change_password.php'
    ]
];

/*
 * إخفاء الروابط غير المسموحة من القائمة.
 */
$allowedMenu = $menuPermissions[$currentRole] ?? [];

function showMenuLink(string $page): bool
{
    global $allowedMenu;

    return in_array('all', $allowedMenu, true)
        || in_array($page, $allowedMenu, true);
}

/*
 * قراءة اسم المدرسة والشعار.
 */
try {
    if (isset($pdo)) {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'app_theme_settings'");
        if ($tableCheck->fetchColumn()) {
            $themeStmt = $pdo->query("
                SELECT color_primary, color_accent, color_background
                FROM app_theme_settings
                WHERE id = 1
                LIMIT 1
            ");
            $savedTheme = $themeStmt->fetch();

            if ($savedTheme) {
                foreach ($themeColors as $key => $default) {
                    $savedColor = $savedTheme['color_' . $key] ?? null;
                    if ($isThemeColor($savedColor)) {
                        $themeColors[$key] = strtolower($savedColor);
                    }
                }
            }
        }
    }
} catch (PDOException $e) {
    error_log('Theme colors could not be loaded: ' . $e->getMessage());
}

try {
    if (isset($pdo)) {
        $stmt = $pdo->query(
            "SELECT school_name, logo_path
             FROM school_settings
             ORDER BY id ASC
             LIMIT 1"
        );

        $school = $stmt->fetch();

        if ($school) {
            if (!empty($school['school_name'])) {
                $schoolName = $school['school_name'];
            }

            if (!empty($school['logo_path'])) {
                $logoFile = __DIR__ . '/../' . $school['logo_path'];

                if (is_file($logoFile)) {
                    $schoolLogo = $school['logo_path'];
                }
            }
        }
    }
} catch (PDOException $e) {
    error_log('School branding could not be loaded: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --primary: <?= htmlspecialchars($themeColors['primary'], ENT_QUOTES, 'UTF-8') ?>;
            --primary-dark: <?= htmlspecialchars($darkenThemeColor($themeColors['primary'], 20), ENT_QUOTES, 'UTF-8') ?>;
            --primary-soft: <?= htmlspecialchars($mixThemeColor($themeColors['primary'], 90), ENT_QUOTES, 'UTF-8') ?>;
            --primary-rgb: <?= hexdec(substr($themeColors['primary'], 1, 2)) ?>, <?= hexdec(substr($themeColors['primary'], 3, 2)) ?>, <?= hexdec(substr($themeColors['primary'], 5, 2)) ?>;
            --primary-dark-rgb: <?= hexdec(substr($darkenThemeColor($themeColors['primary'], 20), 1, 2)) ?>, <?= hexdec(substr($darkenThemeColor($themeColors['primary'], 20), 3, 2)) ?>, <?= hexdec(substr($darkenThemeColor($themeColors['primary'], 20), 5, 2)) ?>;
            --accent: <?= htmlspecialchars($themeColors['accent'], ENT_QUOTES, 'UTF-8') ?>;
            --bg: <?= htmlspecialchars($themeColors['background'], ENT_QUOTES, 'UTF-8') ?>;
            --panel: #ffffff;
            --panel-2: #f8fafb;
            --muted: #647681;
            --text: #24343b;
            --border: #dce5e8;
            --success: #198754;
            --shadow: 0 10px 28px rgba(25, 50, 60, 0.07);
            --bs-primary: var(--primary);
            --bs-primary-rgb: <?= hexdec(substr($themeColors['primary'], 1, 2)) ?>, <?= hexdec(substr($themeColors['primary'], 3, 2)) ?>, <?= hexdec(substr($themeColors['primary'], 5, 2)) ?>;
            --bs-link-color: var(--primary);
            --bs-link-color-rgb: <?= hexdec(substr($themeColors['primary'], 1, 2)) ?>, <?= hexdec(substr($themeColors['primary'], 3, 2)) ?>, <?= hexdec(substr($themeColors['primary'], 5, 2)) ?>;
            --bs-link-hover-color: var(--primary-dark);
            --bs-link-hover-color-rgb: <?= hexdec(substr($darkenThemeColor($themeColors['primary'], 20), 1, 2)) ?>, <?= hexdec(substr($darkenThemeColor($themeColors['primary'], 20), 3, 2)) ?>, <?= hexdec(substr($darkenThemeColor($themeColors['primary'], 20), 5, 2)) ?>;
            --bs-body-color: var(--text);
            --bs-body-bg: var(--bg);
            --bs-border-color: var(--border);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background: var(--bg);
            font-family: Tahoma, Arial, sans-serif;
            color: var(--text);
            margin: 0;
            min-height: 100vh;
        }

        .btn-primary {
            --bs-btn-bg: var(--primary);
            --bs-btn-border-color: var(--primary);
            --bs-btn-hover-bg: var(--primary-dark);
            --bs-btn-hover-border-color: var(--primary-dark);
            --bs-btn-active-bg: var(--primary-dark);
            --bs-btn-active-border-color: var(--primary-dark);
            --bs-btn-disabled-bg: var(--primary);
            --bs-btn-disabled-border-color: var(--primary);
        }

        .btn-outline-primary {
            --bs-btn-color: var(--primary);
            --bs-btn-border-color: var(--primary);
            --bs-btn-hover-bg: var(--primary);
            --bs-btn-hover-border-color: var(--primary);
            --bs-btn-active-bg: var(--primary-dark);
            --bs-btn-active-border-color: var(--primary-dark);
        }

        .text-primary {
            color: var(--primary) !important;
        }

        .bg-primary {
            background-color: var(--primary) !important;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-rgb), 0.16);
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .table {
            --bs-table-color: var(--text);
            --bs-table-border-color: var(--border);
        }

        .topbar {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 6px 20px rgba(var(--primary-dark-rgb), 0.2);
            position: sticky;
            top: 0;
            z-index: 20;
        }

        .school-logo {
            width: 54px;
            height: 54px;
            object-fit: contain;
            background: white;
            border-radius: 12px;
            padding: 5px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.12);
        }

        .school-name {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .container-fluid {
            padding: 18px 18px 30px;
        }

        .row {
            margin: 0;
        }

        .sidebar {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(4px);
            min-height: calc(100vh - 82px);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px 12px;
            box-shadow: var(--shadow);
            margin-bottom: 16px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #34464e;
            text-decoration: none;
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 6px;
            transition: all 0.2s ease;
            font-size: 14px;
            font-weight: 500;
            line-height: 1.5;
        }

        .sidebar a:hover {
            background: var(--primary-soft);
            color: var(--primary);
            transform: translateX(-2px);
        }

        .sidebar a.active {
            background: linear-gradient(135deg, var(--primary-soft) 0%, #dcebed 100%);
            color: var(--primary);
            font-weight: 700;
            box-shadow: inset 0 0 0 1px rgba(var(--primary-rgb), 0.08);
        }

        .sidebar .logout-link {
            margin-top: 8px;
            color: #b91c1c;
        }

        .sidebar .logout-link:hover {
            background: #fff1f2;
            color: #b91c1c;
        }

        .sidebar-section {
            color: #48627d;
            font-size: 12px;
            font-weight: 700;
            padding: 0 12px;
            margin-top: 20px;
            margin-bottom: 10px;
            letter-spacing: 0.3px;
        }

        .sidebar-group > .sidebar-section {
            list-style: none;
            padding: 12px 14px;
            margin: 8px 0;
            border-right: 3px solid var(--accent);
            cursor: pointer;
            border-radius: 12px;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .sidebar-group > .sidebar-section:hover,
        .sidebar-group[open] > .sidebar-section {
            background: var(--primary-soft);
            color: var(--primary);
        }
        .sidebar-group > .sidebar-section::after {
    content: "\25C2"; /* أو استخدم ">" أو رمز سهم آخر */
    float: left;
    font-size: 25px;
    line-height: 1;
    transition: transform 0.2s ease; /* إضافة تأثير دوران ناعم */
}

/* الأيقونة عند الفتح: سهم يشير للأسفل */
.sidebar-group[open] > .sidebar-section::after {
    content: "\25BE"; /* رمز السهم المتجه للأسفل */
}
       /* .sidebar-group > .sidebar-section::after {
            content: "+";
            float: left;
            font-size: 16px;
            line-height: 1;     
        }

        .sidebar-group[open] > .sidebar-section::after {
            content: "\2212";
        }*/

        .sidebar-group > .sidebar-section::-webkit-details-marker {
            display: none;
        }

        .sidebar-links {
            padding-right: 10px;
            border-right: 2px solid var(--border);
            margin: 0 8px 12px 0;
        }

        .content {
            padding: 10px 12px 0 8px;
            min-width: 0;
        }

        main.content > :not(.alert) {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: var(--shadow);
        }

        @media (max-width: 991px) {
            .sidebar {
                min-height: auto;
                border-radius: 16px;
            }
        }

        @media (max-width: 767px) {
            .container-fluid {
                padding: 12px 10px 20px;
            }

            .sidebar {
                min-height: auto;
                border-left: none;
                border-bottom: 1px solid var(--border);
                max-height: 55vh;
                overflow-y: auto;
                padding: 12px 8px;
            }

            .content {
                padding: 10px 2px 0;
            }

            .topbar {
                padding: 12px 16px;
            }

            .school-logo {
                width: 44px;
                height: 44px;
            }

            .school-name {
                font-size: 17px;
            }
        }
    </style>

      
</head>

<body>

<header class="topbar">
    <?php if ($schoolLogo !== ''): ?>
        <img
            src="<?= htmlspecialchars($schoolLogo, ENT_QUOTES, 'UTF-8') ?>"
            alt="شعار المدرسة"
            class="school-logo"
        >
    <?php endif; ?>

    <strong class="school-name">
        <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?>
    </strong>
</header>

<div class="container-fluid">
    <div class="row">

        <?php if (!$hideSidebar): ?>
        <aside class="col-md-3 col-lg-2 sidebar">

            <?php if (showMenuLink('index.php')): ?>
                <a href="index.php">🏠 الرئيسية</a>
            <?php endif; ?>

            <?php if (
                showMenuLink('students.php') ||
                showMenuLink('guardians.php') ||
                showMenuLink('attendance.php') ||
                showMenuLink('behavior.php')
            ): ?>
                <details class="sidebar-group">
                    <summary class="sidebar-section">الطلاب وشؤونهم</summary>
                    <div class="sidebar-links">
                        <?php if (showMenuLink('students.php')): ?>
                            <a href="students.php">👨‍🎓 الطلاب</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('guardians.php')): ?>
                            <a href="guardians.php">👨‍👩‍👧 أولياء الأمور</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('attendance.php')): ?>
                            <a href="attendance.php">📋 الحضور والغياب</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('behavior.php')): ?>
                            <a href="behavior.php">⭐ سلوك الطلاب</a>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if (
                showMenuLink('teachers.php') ||
                showMenuLink('classrooms.php') ||
                showMenuLink('subjects.php') ||
                showMenuLink('academic_years.php') ||
                showMenuLink('teaching_assignments.php') ||
                showMenuLink('timetable.php')
            ): ?>
                <details class="sidebar-group">
                    <summary class="sidebar-section">تنظيم العملية التعليمية</summary>
                    <div class="sidebar-links">
                        <?php if (showMenuLink('teachers.php')): ?>
                            <a href="teachers.php">👨‍🏫 المعلمون</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('classrooms.php')): ?>
                            <a href="classrooms.php">🏫 الفصول الدراسية</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('subjects.php')): ?>
                            <a href="subjects.php">📚 المواد الدراسية</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('academic_years.php')): ?>
                            <a href="academic_years.php">📅 السنوات الدراسية</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('teaching_assignments.php')): ?>
                            <a href="teaching_assignments.php">🧑‍🏫 التكليفات التدريسية</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('timetable.php')): ?>
                            <a href="timetable.php">🗓️ الجدول الدراسي</a>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if (
                showMenuLink('exams.php') ||
                showMenuLink('grades.php') ||
                showMenuLink('homework.php') ||
                showMenuLink('homework_submissions.php') ||
                showMenuLink('reports.php')
            ): ?>
                <details class="sidebar-group">
                    <summary class="sidebar-section">الاختبارات والواجبات</summary>
                    <div class="sidebar-links">
                        <?php if (showMenuLink('exams.php')): ?>
                            <a href="exams.php">📝 الامتحانات</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('grades.php')): ?>
                            <a href="grades.php">💯 الدرجات</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('homework.php')): ?>
                            <a href="homework.php">📖 الواجبات</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('homework_submissions.php')): ?>
                            <a href="homework_submissions.php">📥 تسليم الواجبات</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('reports.php')): ?>
                            <a href="reports.php">📊 التقارير والنتائج</a>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if (
                showMenuLink('fees.php') ||
                showMenuLink('payments.php') ||
                showMenuLink('expenses.php')
            ): ?>
                <details class="sidebar-group">
                    <summary class="sidebar-section">الحسابات المالية</summary>
                    <div class="sidebar-links">
                        <?php if (showMenuLink('fees.php')): ?>
                            <a href="fees.php">💰 الرسوم الدراسية</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('payments.php')): ?>
                            <a href="payments.php">💳 المدفوعات والإيصالات</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('expenses.php')): ?>
                            <a href="expenses.php">🧾 المصروفات</a>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if (
                showMenuLink('users.php') ||
                showMenuLink('announcements.php') ||
                showMenuLink('notifications.php') ||
                showMenuLink('messages.php') ||
                showMenuLink('audit_logs.php') ||
                showMenuLink('settings.php') ||
                showMenuLink('theme_settings.php')
            ): ?>
                <details class="sidebar-group">
                    <summary class="sidebar-section">الإدارة والتواصل</summary>
                    <div class="sidebar-links">
                        <?php if (showMenuLink('users.php')): ?>
                            <a href="users.php">👥 المستخدمون</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('announcements.php')): ?>
                            <a href="announcements.php">📢 الإعلانات</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('notifications.php')): ?>
                            <a href="notifications.php">🔔 الإشعارات</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('messages.php')): ?>
                            <a href="messages.php">✉️ الرسائل</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('audit_logs.php')): ?>
                            <a href="audit_logs.php">🛡️ سجل العمليات</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('settings.php')): ?>
                            <a href="settings.php">⚙️ إعدادات المدرسة</a>
                        <?php endif; ?>

                        <?php if (showMenuLink('theme_settings.php')): ?>
                            <a href="theme_settings.php">🎨 ألوان التطبيق</a>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>

            <?php if (showMenuLink('change_password.php')): ?>
                <details class="sidebar-group">
                    <summary class="sidebar-section">الحساب الشخصي</summary>
                    <div class="sidebar-links">
                        <a href="change_password.php">🔑 تغيير كلمة المرور</a>
                    </div>
                </details>
            <?php endif; ?>

            <a href="logout.php" class="logout-link">🚪 تسجيل الخروج</a>

        </aside>
        <?php endif; ?>

        <main class="<?= $hideSidebar ? 'col-12' : 'col-md-9 col-lg-10' ?> content">

          