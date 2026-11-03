
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';

if ((int) $_SESSION['role_id'] !== 1) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'لوحة تحكم مدير النظام';

$totalUsers = (int) $pdo->query(
    "SELECT COUNT(*) FROM users"
)->fetchColumn();

$activeUsers = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE is_active = 1"
)->fetchColumn();

$totalRoles = (int) $pdo->query(
    "SELECT COUNT(*) FROM roles"
)->fetchColumn();

$totalStudents = (int) $pdo->query(
    "SELECT COUNT(*) FROM students"
)->fetchColumn();

$totalTeachers = (int) $pdo->query(
    "SELECT COUNT(*) FROM teachers"
)->fetchColumn();

$totalLogs = (int) $pdo->query(
    "SELECT COUNT(*) FROM audit_logs"
)->fetchColumn();

$recentUsers = $pdo->query("
    SELECT username, full_name, role_id, is_active, created_at
    FROM users
    ORDER BY id DESC
    LIMIT 5
")->fetchAll();

$roleNames = [
    1 => 'مدير النظام',
    2 => 'مدير المدرسة',
    3 => 'المحاسب',
    4 => 'المعلم',
    5 => 'الطالب',
    6 => 'ولي الأمر'
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" dir="rtl">

    <div class="mb-4">
        <h2 class="fw-bold">لوحة تحكم مدير النظام</h2>
        <p class="text-muted">إدارة النظام ومتابعة المستخدمين والعمليات</p>
    </div>

    <div class="row g-3 mb-4">

        <?php
        $cards = [
            ['إجمالي المستخدمين', $totalUsers, 'users.php'],
            ['الحسابات النشطة', $activeUsers, 'users.php'],
            ['الأدوار والصلاحيات', $totalRoles, 'users.php'],
            ['إجمالي الطلاب', $totalStudents, 'students.php'],
            ['إجمالي المعلمين', $totalTeachers, 'teachers.php'],
            ['سجل العمليات', $totalLogs, 'audit_logs.php'],
        ];
        ?>

        <?php foreach ($cards as $card): ?>
            <div class="col-12 col-sm-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-muted mb-2">
                            <?= htmlspecialchars($card[0], ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <h2 class="fw-bold"><?= (int) $card[1] ?></h2>
                        <a href="<?= htmlspecialchars($card[2], ENT_QUOTES, 'UTF-8') ?>"
                           class="text-decoration-none">
                            عرض التفاصيل ←
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white">
            <h5 class="fw-bold mb-0">الوصول السريع</h5>
        </div>

        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="users.php" class="btn btn-outline-primary w-100 py-3">
                        إدارة المستخدمين
                    </a>
                </div>

                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="settings.php" class="btn btn-outline-primary w-100 py-3">
                        إعدادات المدرسة
                    </a>
                </div>

                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="academic_years.php" class="btn btn-outline-primary w-100 py-3">
                        السنوات الدراسية
                    </a>
                </div>

                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="audit_logs.php" class="btn btn-outline-primary w-100 py-3">
                        سجل العمليات
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="fw-bold mb-0">أحدث الحسابات المضافة</h5>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>اسم المستخدم</th>
                            <th>الاسم الكامل</th>
                            <th>نوع الحساب</th>
                            <th>الحالة</th>
                            <th>تاريخ الإنشاء</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recentUsers): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">
                                لا توجد حسابات مسجلة
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentUsers as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($item['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?= htmlspecialchars(
                                        $roleNames[(int) $item['role_id']] ?? 'غير معروف',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>
                                <td>
                                    <?php if ((int) $item['is_active'] === 1): ?>
                                        <span class="badge bg-success">نشط</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">معطل</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string) $item['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <a href="users.php" class="btn btn-primary">عرض جميع المستخدمين</a>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
