
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';
$currentUserId = (int)$_SESSION['user_id'];
$selectedReceiverId = 0;

if (empty($_SESSION['messages_csrf'])) {
    $_SESSION['messages_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['messages_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $receiverId = filter_input(INPUT_POST, 'receiver_id', FILTER_VALIDATE_INT);
    $selectedReceiverId = is_int($receiverId) ? $receiverId : 0;
    $subjectInput = $_POST['subject'] ?? '';
    $messageInput = $_POST['message_body'] ?? '';
    $subject = is_string($subjectInput) ? trim($subjectInput) : '';
    $messageBody = is_string($messageInput) ? trim($messageInput) : '';
    $subjectLength = preg_match_all('/./us', $subject);
    $messageLength = preg_match_all('/./us', $messageBody);

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        $error = 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.';
    } elseif (
        !$receiverId ||
        $receiverId === $currentUserId ||
        $subject === '' ||
        $subjectLength === false ||
        $subjectLength > 200 ||
        $messageBody === '' ||
        $messageLength === false ||
        $messageLength > 10000
    ) {
        $error = 'أدخل عنواناً لا يتجاوز 200 حرف ومحتوى لا يتجاوز 10000 حرف، واختر مستلماً آخر.';
    } else {
        try {
            $check = $pdo->prepare(
                "SELECT id FROM users
                 WHERE id = ? AND is_active = 1
                 LIMIT 1"
            );
            $check->execute([$receiverId]);

            if (!$check->fetch()) {
                $error = 'المستلم غير موجود أو غير نشط.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO messages
                    (sender_id, receiver_id, subject, message_body)
                    VALUES (?, ?, ?, ?)"
                );
                $stmt->execute([
                    $currentUserId,
                    $receiverId,
                    $subject,
                    $messageBody
                ]);

                $success = 'تم إرسال الرسالة بنجاح.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'تعذر إرسال الرسالة بسبب خطأ في قاعدة البيانات. حاول مرة أخرى، وإذا استمرت المشكلة راجع سجل الأخطاء.';
        }
    }
}

$userStmt = $pdo->prepare(
    "SELECT id, full_name, username
     FROM users
     WHERE is_active = 1
       AND id <> :current_user_id
     ORDER BY full_name"
);
$userStmt->execute(['current_user_id' => $currentUserId]);
$users = $userStmt->fetchAll();

$markReadStmt = $pdo->prepare(
    "UPDATE messages
     SET status = 'read', read_at = CURRENT_TIMESTAMP
     WHERE receiver_id = :receiver_id
       AND status = 'sent'"
);
$markReadStmt->execute(['receiver_id' => $currentUserId]);

$messageStmt = $pdo->prepare(
    "SELECT m.*,
            sender.full_name AS sender_name,
            receiver.full_name AS receiver_name
     FROM messages m
     INNER JOIN users sender ON sender.id = m.sender_id
     INNER JOIN users receiver ON receiver.id = m.receiver_id
     WHERE m.sender_id = :sender_id
        OR m.receiver_id = :receiver_id
     ORDER BY m.id DESC"
);
$messageStmt->execute([
    'sender_id' => $currentUserId,
    'receiver_id' => $currentUserId
]);
$messages = $messageStmt->fetchAll();

$messageStatusLabels = [
    'sent' => 'مرسلة - بانتظار القراءة',
    'read' => 'تمت القراءة',
    'archived' => 'مؤرشفة'
];

$pageTitle = 'الرسائل';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">الرسائل بين المستخدمين</h1>

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

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">إرسال رسالة جديدة</h2>
    </div>

    <div class="card-body">
        <?php if (!$users): ?>
            <div class="alert alert-warning mb-0">
                لا يوجد مستخدم آخر نشط لإرسال رسالة إليه.
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">المستلم *</label>
                        <select name="receiver_id" class="form-select" required>
                            <option value="">اختر المستلم</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= (int)$user['id'] ?>"
                                    <?= $selectedReceiverId === (int)$user['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(
                                        $user['full_name'] . ' (' . $user['username'] . ')',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">عنوان الرسالة *</label>
                        <input type="text" name="subject" class="form-control"
                               maxlength="200" required
                               value="<?= htmlspecialchars(
                                   is_string($_POST['subject'] ?? null) ? $_POST['subject'] : '',
                                   ENT_QUOTES,
                                   'UTF-8'
                               ) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">محتوى الرسالة *</label>
                        <textarea name="message_body" class="form-control"
                                  rows="4" maxlength="10000" required><?= htmlspecialchars(
                                      is_string($_POST['message_body'] ?? null) ? $_POST['message_body'] : '',
                                      ENT_QUOTES,
                                      'UTF-8'
                                  ) ?></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            إرسال الرسالة
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">سجل الرسائل (<?= count($messages) ?>)</h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>المرسل</th>
                    <th>المستلم</th>
                    <th>العنوان</th>
                    <th>المحتوى</th>
                    <th>التاريخ</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$messages): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4">
                            لا توجد رسائل مسجلة.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($messages as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['sender_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['receiver_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['subject'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= nl2br(htmlspecialchars($item['message_body'], ENT_QUOTES, 'UTF-8')) ?></td>
                            <td><?= htmlspecialchars($item['sent_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(
                                $messageStatusLabels[$item['status']] ?? 'غير معروفة',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
