
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';
$canPublishAnnouncements = in_array($roleName, ['system_admin', 'school_admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canPublishAnnouncements) {
        http_response_code(403);
        exit('عذراً، ليست لديك صلاحية لنشر الإعلانات.');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['announcements_csrf'])) {
        $_SESSION['announcements_csrf'] = bin2hex(random_bytes(32));
    }

    $token = $_POST['csrf_token'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $targetRole = $_POST['target_role'] ?? 'all';

    $allowedRoles = ['all', 'student', 'teacher', 'guardian'];

    if (!is_string($token) || !hash_equals($_SESSION['announcements_csrf'], $token)) {
        $error = 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.';
    } elseif (
        $title === '' ||
        strlen($title) > 200 ||
        $content === '' ||
        !in_array($targetRole, $allowedRoles, true)
    ) {
        $error = 'يرجى إدخال عنوان الإعلان ومحتواه بصورة صحيحة.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO announcements
                (title, content, target_role, published_by, status)
                VALUES (?, ?, ?, ?, 'published')"
            );

            $stmt->execute([
                $title,
                $content,
                $targetRole,
                (int)$_SESSION['user_id']
            ]);

            $success = 'تم نشر الإعلان بنجاح.';
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'تعذر نشر الإعلان. راجع سجل الأخطاء وتأكد من صحة البيانات.';
        }
    }
}

$announcementSql = "
    SELECT * FROM announcements
    WHERE status = 'published'
      AND publish_date <= CURRENT_TIMESTAMP
      AND (expiry_date IS NULL OR expiry_date >= CURRENT_TIMESTAMP)
";

if (!$canPublishAnnouncements) {
    $announcementSql .= " AND (target_role = 'all' OR target_role = :target_role)";
}

$announcementSql .= " ORDER BY publish_date DESC, id DESC";
$announcementStmt = $pdo->prepare($announcementSql);

if ($canPublishAnnouncements) {
    $announcementStmt->execute();
} else {
    $announcementStmt->execute(['target_role' => $roleName]);
}

$announcements = $announcementStmt->fetchAll();

$audienceLabels = [
    'all' => 'الجميع',
    'student' => 'الطلاب',
    'teacher' => 'المعلمون',
    'guardian' => 'أولياء الأمور'
];

if ($canPublishAnnouncements) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['announcements_csrf'])) {
        $_SESSION['announcements_csrf'] = bin2hex(random_bytes(32));
    }

    $csrfToken = $_SESSION['announcements_csrf'];
}

$pageTitle = 'الإعلانات المدرسية';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">الإعلانات المدرسية</h1>

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

<?php if ($canPublishAnnouncements): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">نشر إعلان جديد</h2>
    </div>

    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="row g-3">

                <div class="col-md-8">
                    <label class="form-label">عنوان الإعلان *</label>
                    <input type="text" name="title" class="form-control"
                           maxlength="200" required
                           placeholder="مثلاً: موعد بداية الامتحانات">
                </div>

                <div class="col-md-4">
                    <label class="form-label">الفئة المستهدفة *</label>
                    <select name="target_role" class="form-select" required>
                        <option value="all">الجميع</option>
                        <option value="student">الطلاب</option>
                        <option value="teacher">المعلمون</option>
                        <option value="guardian">أولياء الأمور</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">محتوى الإعلان *</label>
                    <textarea name="content" class="form-control"
                              rows="5" maxlength="10000" required
                              placeholder="اكتب تفاصيل الإعلان هنا..."></textarea>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        نشر الإعلان
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<h2 class="h5 mb-3">الإعلانات المنشورة</h2>

<?php if (!$announcements): ?>
    <div class="alert alert-light border">
        لا توجد إعلانات حتى الآن.
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($announcements as $announcement): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                            <h3 class="h5 mb-0">
                                <?= htmlspecialchars($announcement['title'], ENT_QUOTES, 'UTF-8') ?>
                            </h3>

                            <span class="badge bg-primary">
                                <?= htmlspecialchars(
                                    $audienceLabels[$announcement['target_role']] ?? 'الجميع',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                        </div>

                        <p class="mb-2">
                            <?= nl2br(htmlspecialchars(
                                $announcement['content'],
                                ENT_QUOTES,
                                'UTF-8'
                            )) ?>
                        </p>

                        <small class="text-secondary">
                            <?php
                            if (!empty($announcement['created_at'])) {
                                echo htmlspecialchars(
                                    $announcement['created_at'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            }
                            ?>
                        </small>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
