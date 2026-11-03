
<?php
require_once __DIR__ . '/config/database.php';

$username = 'sysadmin';
$fullName = 'مدير النظام';
$password = 'ChangeMe_2026!';

try {
    $check = $pdo->prepare(
        "SELECT id FROM users WHERE username = ? LIMIT 1"
    );
    $check->execute([$username]);

    if ($check->fetch()) {
        exit('حساب sysadmin موجود بالفعل. لم يتم إجراء أي تغيير.');
    }

    $checkRole = $pdo->prepare(
        "SELECT id FROM roles WHERE id = 1 LIMIT 1"
    );
    $checkRole->execute();

    if (!$checkRole->fetch()) {
        exit('رتبة مدير النظام غير موجودة.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users
            (username, password, role_id, full_name, is_active)
        VALUES
            (?, ?, 1, ?, 1)
    ");

    $stmt->execute([$username, $hash, $fullName]);

    echo 'تم إنشاء حساب مدير النظام بنجاح.<br>';
    echo 'اسم المستخدم: sysadmin<br>';
    echo 'كلمة المرور: ChangeMe_2026!<br>';
    echo 'احذف ملف create_admin.php بعد نجاح العملية، ثم غيّر كلمة المرور إلى كلمة قوية وفريدة.';
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('تعذر إنشاء الحساب. راجع سجل الأخطاء.');
}
