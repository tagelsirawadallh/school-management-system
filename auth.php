
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// التأكد من وجود جلسة مستخدم مسجّل الدخول
if (
    empty($_SESSION['user_id']) ||
    !isset($_SESSION['role_id'])
) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

// التأكد من أن الحساب ما زال مفعّلاً
try {
    $stmt = $pdo->prepare("
        SELECT id, username, full_name, role_id, is_active
        FROM users
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => (int)$_SESSION['user_id']
    ]);

    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httponly']
            );
        }

        session_destroy();

        header('Location: login.php');
        exit;
    }

    // تحديث بيانات الجلسة من قاعدة البيانات
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role_id'] = (int)$user['role_id'];

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(503);
    exit('تعذر التحقق من الحساب. حاول مرة أخرى لاحقاً.');
}
