
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';
$currentUserId = (int)$_SESSION['user_id'];
$canManageNotifications = in_array($roleName, ['system_admin', 'school_admin'], true);

if (empty($_SESSION['notifications_csrf'])) {
    $_SESSION['notifications_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['notifications_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageNotifications) {
        http_response_code(403);
        exit('عذراً، ليست لديك صلاحية لإرسال الإشعارات.');
    }

    $token = $_POST['csrf_token'] ?? '';
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $titleInput = $_POST['title'] ?? '';
    $messageInput = $_POST['message'] ?? '';
    $title = is_string($titleInput) ? trim($titleInput) : '';
    $message = is_string($messageInput) ? trim($messageInput) : '';

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        $error = 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.';
    } elseif (!$userId || $title === '' || $message === '' || strlen($title) > 200) {
        $error = 'يرجى تعبئة جميع الحقول المطلوبة.';
    } else {
        try {
            $check = $pdo->prepare(
                "SELECT id FROM users WHERE id = ? AND is_active = 1"
            );
            $check->execute([$userId]);

            if (!$check->fetch()) {
                $error = 'المستخدم غير موجود أو غير نشط.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO notifications
                    (user_id, title, message)
                    VALUES (?, ?, ?)"
                );

                $stmt->execute([$userId, $title, $message]);
                $success = 'تم تسجيل الإشعار بنجاح.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'تعذر حفظ الإشعار في قاعدة البيانات. حاول مرة أخرى، وإذا استمرت المشكلة راجع سجل الأخطاء.';
        }
    }
}

if ($canManageNotifications) {
    $users = $pdo->query(
        "SELECT id, full_name, username
         FROM users
         WHERE is_active = 1
         ORDER BY full_name"
    )->fetchAll();

    $notifications = $pdo->query(
        "SELECT n.*, u.full_name, u.username
         FROM notifications n
         INNER JOIN users u ON u.id = n.user_id
         ORDER BY n.id DESC"
    )->fetchAll();
} else {
    $users = [];
    $notificationStmt = $pdo->prepare(
        "SELECT n.*, u.full_name, u.username
         FROM notifications n
         INNER JOIN users u ON u.id = n.user_id
         WHERE n.user_id = :user_id
         ORDER BY n.id DESC"
    );
    $notificationStmt->execute(['user_id' => $currentUserId]);
    $notifications = $notificationStmt->fetchAll();
}

$pageTitle = 'إدارة الإشعارات';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">إدارة الإشعارات</h1>

<?php if ($success): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($canManageNotifications): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">إنشاء إشعار</h2>
    </div>

    <div class="card-body">
        <?php if (!$users): ?>
            <div class="alert alert-warning mb-0">
                لا يوجد مستخدمون نشطون لإرسال الإشعارات إليهم.
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">المستخدم المستهدف *</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">اختر المستخدم</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= (int)$user['id'] ?>">
                                    <?= htmlspecialchars(
                                        $user['full_name'] . ' (' . $user['username'] . ')',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">عنوان الإشعار *</label>
                        <input type="text" name="title" class="form-control"
                               maxlength="200" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">نص الإشعار *</label>
                        <textarea name="message" class="form-control"
                                  rows="4" maxlength="5000" required></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            حفظ الإشعار
                        </button>
                    </div>

                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">
            سجل الإشعارات (<?= count($notifications) ?>)
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>المستخدم</th>
                    <th>العنوان</th>
                    <th>الإشعار</th>
                    <th>التاريخ</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$notifications): ?>
                    <tr>
                        <td colspan="4" class="text-center py-4">
                            لا توجد إشعارات مسجلة.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($notifications as $notification): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(
                                    $notification['full_name'] ?? $notification['username'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <?= nl2br(htmlspecialchars(
                                    $notification['message'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                )) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars(
                                    $notification['created_at'] ?? '—',
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
