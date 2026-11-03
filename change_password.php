
<?php


require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}



if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!is_string($currentPassword) ||
            !is_string($newPassword) ||
            !is_string($confirmPassword)) {
            $error = 'البيانات المدخلة غير صحيحة.';
        } elseif (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            $error = 'يرجى ملء جميع الحقول.';
        } elseif (strlen($newPassword) < 12) {
            $error = 'كلمة المرور الجديدة يجب أن تكون 12 حرفًا على الأقل.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'تأكيد كلمة المرور غير مطابق.';
        } elseif ($currentPassword === $newPassword) {
            $error = 'اختر كلمة مرور مختلفة عن الحالية.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "SELECT password FROM users WHERE id = ? LIMIT 1"
                );
                $stmt->execute([(int) $_SESSION['user_id']]);
                $account = $stmt->fetch();

                if (
                    !$account ||
                    !password_verify($currentPassword, $account['password'])
                ) {
                    $error = 'كلمة المرور الحالية غير صحيحة.';
                } else {
                    $newHash = password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                    $update = $pdo->prepare(
                        "UPDATE users SET password = ? WHERE id = ?"
                    );
                    $update->execute([
                        $newHash,
                        (int) $_SESSION['user_id']
                    ]);

                    session_regenerate_id(true);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $success = 'تم تغيير كلمة المرور بنجاح.';
                }
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = 'تعذر تغيير كلمة المرور. حاول مرة أخرى.';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <h3 class="mb-3">تغيير كلمة المرور</h3>
    <p class="text-muted">استخدم كلمة مرور قوية وفريدة لحماية حسابك.</p>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" autocomplete="off">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION['csrf_token'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <div class="mb-3">
                    <label class="form-label">كلمة المرور الحالية</label>
                    <input
                        type="password"
                        name="current_password"
                        class="form-control"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label">كلمة المرور الجديدة</label>
                    <input
                        type="password"
                        name="new_password"
                        class="form-control"
                        minlength="12"
                        autocomplete="new-password"
                        required
                    >
                    <small class="text-muted">
                        استخدم 12 حرفًا على الأقل، مع حروف وأرقام ورموز.
                    </small>
                </div>

                <div class="mb-3">
                    <label class="form-label">تأكيد كلمة المرور الجديدة</label>
                    <input
                        type="password"
                        name="confirm_password"
                        class="form-control"
                        minlength="12"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary">
                    حفظ كلمة المرور
                </button>
                <a href="index.php" class="btn btn-secondary">
                    رجوع للرئيسية
                </a>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
