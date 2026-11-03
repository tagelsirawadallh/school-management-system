
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

$error = '';
$username = '';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'يرجى إدخال اسم المستخدم وكلمة المرور.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, username, full_name, password, role_id, is_active
                FROM users
                WHERE username = :username OR email = :email
                LIMIT 1
            ");

            $stmt->execute([
                'username' => $username,
                'email' => $username
            ]);

            $user = $stmt->fetch();

            if (
                !$user ||
                !password_verify($password, $user['password']) ||
                (int) $user['is_active'] !== 1
            ) {
                $error = 'اسم المستخدم أو كلمة المرور غير صحيحة، أو الحساب معطل.';
            } else {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role_id'] = (int) $user['role_id'];

                $update = $pdo->prepare("
                    UPDATE users
                    SET last_login = NOW()
                    WHERE id = ?
                ");
                $update->execute([(int) $user['id']]);

                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'حدث خطأ أثناء تسجيل الدخول. حاول مرة أخرى.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول - نظام إدارة المدرسة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css"
          rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef3f8;
            font-family: Tahoma, Arial, sans-serif;
        }
        .login-card {
            width: 100%;
            max-width: 430px;
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .08);
        }
        .login-title {
            color: #0d5c8c;
        }
    </style>
</head>
<body>

<div class="container px-3">
    <div class="card login-card mx-auto">
        <div class="card-body p-4 p-md-5">

            <div class="text-center mb-4">
                <h2 class="login-title fw-bold">نظام إدارة المدرسة</h2>
                <p class="text-muted">سجّل الدخول للمتابعة</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="mb-3">
                    <label for="username" class="form-label">
                        اسم المستخدم أو البريد الإلكتروني
                    </label>
                    <input
                        type="text"
                        name="username"
                        id="username"
                        class="form-control"
                        value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">
                        كلمة المرور
                    </label>
                    <input
                        type="password"
                        name="password"
                        id="password"
                        class="form-control"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">
                    تسجيل الدخول
                </button>
            </form>

        </div>
    </div>
</div>

</body>
</html>
