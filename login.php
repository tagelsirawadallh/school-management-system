
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

$loginTheme = [
    'primary' => '#155e6b',
    'accent' => '#c49a5a',
    'background' => '#f3f6f8'
];

try {
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
            foreach ($loginTheme as $key => $default) {
                $savedColor = $savedTheme['color_' . $key] ?? null;
                if (
                    is_string($savedColor) &&
                    preg_match('/^#[0-9a-fA-F]{6}$/', $savedColor) === 1
                ) {
                    $loginTheme[$key] = strtolower($savedColor);
                }
            }
        }
    }
} catch (PDOException $e) {
    error_log('Login theme colors could not be loaded: ' . $e->getMessage());
}

$loginPrimaryDark = sprintf(
    '#%02x%02x%02x',
    (int) round(hexdec(substr($loginTheme['primary'], 1, 2)) * 0.8),
    (int) round(hexdec(substr($loginTheme['primary'], 3, 2)) * 0.8),
    (int) round(hexdec(substr($loginTheme['primary'], 5, 2)) * 0.8)
);
$loginPrimaryRgb = implode(', ', [
    hexdec(substr($loginTheme['primary'], 1, 2)),
    hexdec(substr($loginTheme['primary'], 3, 2)),
    hexdec(substr($loginTheme['primary'], 5, 2))
]);
$loginDarkRgb = implode(', ', [
    hexdec(substr($loginPrimaryDark, 1, 2)),
    hexdec(substr($loginPrimaryDark, 3, 2)),
    hexdec(substr($loginPrimaryDark, 5, 2))
]);
$loginAccentRgb = implode(', ', [
    hexdec(substr($loginTheme['accent'], 1, 2)),
    hexdec(substr($loginTheme['accent'], 3, 2)),
    hexdec(substr($loginTheme['accent'], 5, 2))
]);

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
        :root {
            --primary: <?= htmlspecialchars($loginTheme['primary'], ENT_QUOTES, 'UTF-8') ?>;
            --primary-dark: <?= htmlspecialchars($loginPrimaryDark, ENT_QUOTES, 'UTF-8') ?>;
            --primary-rgb: <?= $loginPrimaryRgb ?>;
            --primary-dark-rgb: <?= $loginDarkRgb ?>;
            --login-accent: <?= htmlspecialchars($loginTheme['accent'], ENT_QUOTES, 'UTF-8') ?>;
            --login-accent-rgb: <?= $loginAccentRgb ?>;
            --login-background: <?= htmlspecialchars($loginTheme['background'], ENT_QUOTES, 'UTF-8') ?>;
            --bs-primary: var(--primary);
            --bs-primary-rgb: var(--primary-rgb);
            --bs-link-color: var(--primary);
            --bs-link-color-rgb: var(--primary-rgb);
            --bs-link-hover-color: var(--primary-dark);
            --bs-link-hover-color-rgb: var(--primary-dark-rgb);
        }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(ellipse at 10% 10%, rgba(var(--login-accent-rgb), .12), transparent 35%),
                var(--login-background);
            font-family: Tahoma, Arial, sans-serif;
            color: #24343b;
        }
        .login-card {
            width: 100%;
            max-width: 430px;
            border: 0;
            border-top: 4px solid var(--login-accent);
            border-radius: 20px;
            box-shadow: 0 18px 48px rgba(25, 50, 60, .12);
        }
        .login-title {
            color: var(--primary-dark);
        }
        .login-card .form-control {
            min-height: 46px;
            border-color: #dce5e8;
            border-radius: 10px;
        }
        .login-card .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(var(--primary-rgb), .14);
        }
        .login-card .btn-primary {
            --bs-btn-bg: var(--primary);
            --bs-btn-border-color: var(--primary);
            --bs-btn-hover-bg: var(--primary-dark);
            --bs-btn-hover-border-color: var(--primary-dark);
            --bs-btn-active-bg: var(--primary-dark);
            --bs-btn-active-border-color: var(--primary-dark);
            border-radius: 10px;
            font-weight: 700;
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
