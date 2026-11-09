
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$roleId = (int) $_SESSION['role_id'];
$userId = (int) $_SESSION['user_id'];

$roleNames = [
    1 => 'مدير النظام',
    2 => 'مدير المدرسة',
    3 => 'المحاسب',
    4 => 'المعلم',
    5 => 'الطالب',
    6 => 'ولي الأمر'
];

$roleName = $roleNames[$roleId] ?? 'مستخدم';
$pageTitle = 'لوحة التحكم - ' . $roleName;

$stats = [];
$recentGrades = [];
$guardianChildren = [];
$dashboardError = false;

try {
    // مدير النظام ومدير المدرسة
    if ($roleId === 1 || $roleId === 2) {
        $stats['إجمالي الطلاب'] = (int) $pdo
            ->query("SELECT COUNT(*) FROM students")
            ->fetchColumn();

        $stats['إجمالي المعلمين'] = (int) $pdo
            ->query("SELECT COUNT(*) FROM teachers")
            ->fetchColumn();

        $stats['إجمالي الفصول'] = (int) $pdo
            ->query("SELECT COUNT(*) FROM classrooms")
            ->fetchColumn();

        $stats['إجمالي الامتحانات'] = (int) $pdo
            ->query("SELECT COUNT(*) FROM exams")
            ->fetchColumn();
    }

    // المحاسب
    elseif ($roleId === 3) {
        $stats['إجمالي الرسوم'] = (float) $pdo->query("
            SELECT COALESCE(SUM(total_amount), 0)
            FROM student_fees
            WHERE status <> 'waived'
        ")->fetchColumn();

        $stats['إجمالي المدفوعات'] = (float) $pdo->query("
            SELECT COALESCE(SUM(amount), 0)
            FROM payments
        ")->fetchColumn();

        $stats['إجمالي المصروفات'] = (float) $pdo->query("
            SELECT COALESCE(SUM(amount), 0)
            FROM expenses
        ")->fetchColumn();

        $stats['صافي المدفوعات بعد المصروفات'] =
            $stats['إجمالي المدفوعات'] - $stats['إجمالي المصروفات'];
    }

    // المعلم
    elseif ($roleId === 4) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM teaching_assignments ta
            INNER JOIN teachers t ON t.id = ta.teacher_id
            WHERE t.user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);

        $stats['عدد التكليفات التدريسية'] = (int) $stmt->fetchColumn();
    }

    // الطالب
    elseif ($roleId === 5) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM students
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $studentId = $stmt->fetchColumn();

        if ($studentId) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM grades
                WHERE student_id = :student_id
            ");
            $stmt->execute(['student_id' => (int) $studentId]);
            $stats['عدد الدرجات المسجلة'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT
                    e.exam_name,
                    e.max_score,
                    g.score,
                    g.graded_at
                FROM grades g
                INNER JOIN exams e ON e.id = g.exam_id
                WHERE g.student_id = :student_id
                ORDER BY g.graded_at DESC
                LIMIT 5
            "); 
            $stmt->execute(['student_id' => (int) $studentId]);
            $recentGrades = $stmt->fetchAll();
        } else {
            $stats['عدد الدرجات المسجلة'] = 0;
        }
    }

    // ولي الأمر: عرض الأبناء المرتبطين بحسابه فقط
    elseif ($roleId === 6) {
        $stmt = $pdo->prepare("
            SELECT
                s.id,
                s.student_number,
                u.full_name AS student_name,
                sg.relationship,
                sg.is_primary
            FROM guardians g
            INNER JOIN student_guardians sg
                ON sg.guardian_id = g.id
            INNER JOIN students s
                ON s.id = sg.student_id
            INNER JOIN users u
                ON u.id = s.user_id
            WHERE g.user_id = :user_id
              AND s.is_active = 1
            ORDER BY sg.is_primary DESC, u.full_name ASC
        ");

        $stmt->execute(['user_id' => $userId]);
        $guardianChildren = $stmt->fetchAll();

        $stats['عدد الأبناء المرتبطين بحسابك'] = count($guardianChildren);
    }

} catch (PDOException $e) {
    error_log('Dashboard error: ' . $e->getMessage());
    $dashboardError = true;
}

require_once __DIR__ . '/includes/header.php';
?>

<?php
$dashboardMenuGroups = [
    'الطلاب وشؤونهم' => [
        ['students.php', '👨‍🎓', 'الطلاب'],
        ['guardians.php', '👨‍👩‍👧', 'أولياء الأمور'],
        ['attendance.php', '📋', 'الحضور والغياب'],
        ['behavior.php', '⭐', 'سلوك الطلاب'],
    ],
    'تنظيم العملية التعليمية' => [
        ['teachers.php', '👨‍🏫', 'المعلمون'],
        ['classrooms.php', '🏫', 'الفصول الدراسية'],
        ['subjects.php', '📚', 'المواد الدراسية'],
        ['academic_years.php', '📅', 'السنوات الدراسية'],
        ['teaching_assignments.php', '🧑‍🏫', 'التكليفات التدريسية'],
        ['timetable.php', '🗓️', 'الجدول الدراسي'],
    ],
    'الاختبارات والواجبات' => [
        ['exams.php', '📝', 'الامتحانات'],
        ['grades.php', '💯', 'الدرجات'],
        ['homework.php', '📖', 'الواجبات'],
        ['homework_submissions.php', '📥', 'تسليم الواجبات'],
        ['reports.php', '📊', 'التقارير والنتائج'],
    ],
    'الحسابات المالية' => [
        ['fees.php', '💰', 'الرسوم الدراسية'],
        ['payments.php', '💳', 'المدفوعات والإيصالات'],
        ['expenses.php', '🧾', 'المصروفات'],
    ],
    'الإدارة والتواصل' => [
        ['users.php', '👥', 'المستخدمون'],
        ['announcements.php', '📢', 'الإعلانات'],
        ['notifications.php', '🔔', 'الإشعارات'],
        ['messages.php', '✉️', 'الرسائل'],
        ['audit_logs.php', '🛡️', 'سجل العمليات'],
        ['settings.php', '⚙️', 'إعدادات المدرسة'],
        ['theme_settings.php', '🎨', 'ألوان التطبيق'],
    ],
    'الحساب الشخصي' => [
        ['change_password.php', '🔑', 'تغيير كلمة المرور'],
    ],
];

$visibleDashboardMenuGroups = [];
foreach ($dashboardMenuGroups as $groupTitle => $items) {
    foreach ($items as $item) {
        if (showMenuLink($item[0])) {
            $visibleDashboardMenuGroups[$groupTitle][] = $item;
        }
    }
}
?>

<style>
    .dashboard-shortcuts {
        margin-bottom: 1.5rem;
    }

    .dashboard-shortcut {
        display: flex;
        align-items: center;
        gap: 14px;
        min-height: 84px;
        padding: 16px;
        color: var(--text);
        text-decoration: none;
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 14px;
        box-shadow: 0 4px 14px rgba(25, 50, 60, 0.045);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .dashboard-shortcut:hover {
        color: var(--primary-dark);
        border-color: rgba(21, 94, 107, 0.35);
        box-shadow: var(--shadow);
        transform: translateY(-3px);
    }

    .dashboard-shortcut-icon {
        display: grid;
        flex: 0 0 46px;
        width: 46px;
        height: 46px;
        place-items: center;
        background: var(--primary-soft);
        border-radius: 12px;
        font-size: 22px;
    }

    .dashboard-shortcut-label {
        font-weight: 700;
    }

    .dashboard-shortcut-arrow {
        margin-right: auto;
        color: var(--muted);
        font-size: 20px;
    }
</style>

<div class="container-fluid" dir="rtl">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h3 mb-2">لوحة التحكم</h1>
            <p class="text-muted mb-0">
                مرحباً، <?= htmlspecialchars(
                    $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'مستخدم',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
                — <?= htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        <a href="logout.php" class="btn btn-outline-danger">
            🚪 تسجيل الخروج
        </a>
    </div>

    <?php if ($dashboardError): ?>

        <div class="alert alert-danger">
            تعذر تحميل بعض بيانات لوحة التحكم. راجع سجل الأخطاء وحاول مرة أخرى.
        </div>

    <?php else: ?>

        <?php if (!empty($stats)): ?>
            <div class="row g-3 mb-4">
                <?php foreach ($stats as $label => $value): ?>
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="text-muted mb-2">
                                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                </div>

                                <div class="h3 mb-0">
                                    <?php if (
                                        is_float($value) ||
                                        str_contains($label, 'الرسوم') ||
                                        str_contains($label, 'المدفوعات') ||
                                        str_contains($label, 'المصروفات') ||
                                        str_contains($label, 'صافي')
                                    ): ?>
                                        <?= number_format((float) $value, 2) ?>
                                    <?php else: ?>
                                        <?= number_format((int) $value) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($visibleDashboardMenuGroups)): ?>
            <section class="dashboard-shortcuts" aria-labelledby="dashboard-shortcuts-title">
                <div class="mb-3">
                    <h2 class="h4 mb-1" id="dashboard-shortcuts-title">الوصول السريع</h2>
                    <p class="text-muted mb-0">اختر القسم الذي تريد الوصول إليه</p>
                </div>

                <?php foreach ($visibleDashboardMenuGroups as $groupTitle => $items): ?>
                    <div class="mb-4">
                        <h3 class="h6 fw-bold mb-3">
                            <?= htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') ?>
                        </h3>
                        <div class="row g-3">
                            <?php foreach ($items as [$page, $icon, $label]): ?>
                                <div class="col-12 col-sm-6 col-xl-4">
                                    <a class="dashboard-shortcut h-100"
                                       href="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>">
                                        <span class="dashboard-shortcut-icon" aria-hidden="true">
                                            <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <span class="dashboard-shortcut-label">
                                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <span class="dashboard-shortcut-arrow" aria-hidden="true">←</span>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ($roleId === 6): ?>

            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h2 class="h5 mb-0">أبنائي الطلاب</h2>
                </div>

                <div class="card-body">
                    <?php if (empty($guardianChildren)): ?>

                        <div class="alert alert-info mb-0">
                            لا يوجد طلاب مرتبطون بحسابك حالياً.
                            يُرجى التواصل مع إدارة المدرسة لربط حسابك بأبنائك.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>م</th>
                                        <th>اسم الطالب</th>
                                        <th>رقم الطالب</th>
                                        <th>صلة القرابة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($guardianChildren as $index => $child): ?>
                                        <tr>
                                            <td><?= $index + 1 ?></td>
                                            <td>
                                                <?= htmlspecialchars(
                                                    $child['student_name'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars(
                                                    $child['student_number'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars(
                                                    $child['relationship'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php endif; ?>
                </div>
            </div>

        <?php endif; ?>

        <?php if ($roleId === 5): ?>

            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h2 class="h5 mb-0">أحدث الدرجات</h2>
                </div>

                <div class="card-body">
                    <?php if (empty($recentGrades)): ?>

                        <p class="text-muted mb-0">
                            لا توجد درجات مسجلة حتى الآن.
                        </p>

                    <?php else: ?>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>الامتحان</th>
                                        <th>الدرجة</th>
                                        <th>الدرجة الكاملة</th>
                                        <th>تاريخ التسجيل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentGrades as $grade): ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars(
                                                    $grade['exam_name'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </td>
                                            <td><?= number_format((float) $grade['score'], 2) ?></td>
                                            <td><?= number_format((float) $grade['max_score'], 2) ?></td>
                                            <td>
                                                <?= htmlspecialchars(
                                                    $grade['graded_at'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php endif; ?>
                </div>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
