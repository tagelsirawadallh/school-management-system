
<?php
$pageTitle = $pageTitle ?? 'نظام إدارة المدرسة';

$schoolName = 'مدرسة الملاذ';
$schoolLogo = '';

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
    error_log($e->getMessage());
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
            --primary: #0d5c8c;
            --primary-dark: #0a4a74;
            --primary-soft: #eaf4fb;
            --accent: #f59e0b;
            --bg: #eef4f8;
            --panel: #ffffff;
            --panel-2: #f8fbff;
            --muted: #64748b;
            --text: #1f2937;
            --border: #dfeaf3;
            --success: #16a34a;
            --shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background: linear-gradient(180deg, #edf5fa 0%, #f7f9fc 100%);
            font-family: Tahoma, Arial, sans-serif;
            color: var(--text);
            margin: 0;
            min-height: 100vh;
        }

        .topbar {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 6px 20px rgba(13, 92, 140, 0.22);
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
            color: #334155;
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
            background: linear-gradient(135deg, var(--primary-soft) 0%, #dfefff 100%);
            color: var(--primary);
            font-weight: 700;
            box-shadow: inset 0 0 0 1px rgba(13, 92, 140, 0.07);
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

        main.content > * {
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
                showMenuLink('settings.php')
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

        <main class="col-md-9 col-lg-10 content">

          