
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = 'سجل العمليات والتدقيق';

$error = '';
$logs = [];

try {
    $stmt = $pdo->query(
        "SELECT a.*, u.full_name, u.username
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.user_id
         ORDER BY a.id DESC
         LIMIT 300"
    );

    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'تعذر تحميل سجل العمليات. تحقق من أعمدة جدول audit_logs.';
}

require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">سجل العمليات والتدقيق</h1>

<div class="alert alert-info">
    تعرض هذه الشاشة آخر 300 عملية مسجلة في قاعدة البيانات.
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php else: ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0">
                سجل العمليات (<?= count($logs) ?>)
            </h2>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>رقم العملية</th>
                        <th>المستخدم</th>
                        <th>نوع العملية</th>
                        <th>الجدول أو العنصر</th>
                        <th>التفاصيل</th>
                        <th>التاريخ</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$logs): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                لا توجد عمليات مسجلة حتى الآن.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= (int)$log['id'] ?></td>

                                <td>
                                    <?= htmlspecialchars(
                                        $log['full_name'] ?? $log['username'] ?? 'مستخدم غير معروف',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $log['action'] ?? $log['action_type'] ?? '—',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $log['table_name'] ?? $log['entity'] ?? '—',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $log['details'] ?? $log['description'] ?? '—',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        $log['created_at'] ?? '—',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
