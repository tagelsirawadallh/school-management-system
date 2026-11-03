
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// حذف بيانات الجلسة
$_SESSION = [];

// حذف كوكي الجلسة من المتصفح
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

// إنهاء الجلسة
session_destroy();

// الرجوع إلى صفحة تسجيل الدخول
header('Location: login.php');
exit;
