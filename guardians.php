
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$message = '';
$messageType = 'success';
$editGuardian = null;

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        $message = 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $userId = (int)($_POST['user_id'] ?? 0);
                $nationalId = trim($_POST['national_id'] ?? '');
                $occupation = trim($_POST['occupation'] ?? '');
                $workplace = trim($_POST['workplace'] ?? '');
                $address = trim($_POST['address'] ?? '');

                if ($userId <= 0) {
                    throw new RuntimeException('يرجى اختيار حساب ولي الأمر.');
                }

                $stmt = $pdo->prepare("
                    SELECT u.id
                    FROM users u
                    INNER JOIN roles r ON r.id = u.role_id
                    WHERE u.id = ?
                      AND r.role_name = 'guardian'
                      AND u.is_active = 1
                    LIMIT 1
                ");
                $stmt->execute([$userId]);

                if (!$stmt->fetch()) {
                    throw new RuntimeException(
                        'الحساب المختار غير موجود أو ليس حساب ولي أمر نشطاً.'
                    );
                }

                $nationalId = $nationalId === '' ? null : $nationalId;
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM guardians
                    WHERE user_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
                $existingGuardianId = (int)($stmt->fetchColumn() ?: 0);

                if ($id > 0) {
                    if ($existingGuardianId > 0 && $existingGuardianId !== $id) {
                        throw new RuntimeException(
                            'حساب ولي الأمر مرتبط بملف آخر بالفعل.'
                        );
                    }

                    $stmt = $pdo->prepare("
                        UPDATE guardians
                        SET user_id = ?, national_id = ?, occupation = ?,
                            workplace = ?, address = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $userId,
                        $nationalId,
                        $occupation ?: null,
                        $workplace ?: null,
                        $address ?: null,
                        $id
                    ]);

                    $message = 'تم تحديث بيانات ولي الأمر بنجاح.';
                } elseif ($existingGuardianId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE guardians
                        SET national_id = ?, occupation = ?, workplace = ?,
                            address = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $nationalId,
                        $occupation ?: null,
                        $workplace ?: null,
                        $address ?: null,
                        $existingGuardianId
                    ]);

                    $message = 'حساب ولي الأمر لديه ملف مسجل مسبقاً؛ تم تحديث بياناته.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO guardians
                            (user_id, national_id, occupation, workplace, address)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $userId,
                        $nationalId,
                        $occupation ?: null,
                        $workplace ?: null,
                        $address ?: null
                    ]);

                    $message = 'تمت إضافة ولي الأمر بنجاح.';
                }
            } elseif ($action === 'link_student') {
                $guardianId = (int)($_POST['guardian_id'] ?? 0);
                $studentId = (int)($_POST['student_id'] ?? 0);
                $relationship = trim($_POST['relationship'] ?? '');
                $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
                $receivesNotifications =
                    isset($_POST['receives_notifications']) ? 1 : 0;

                $allowedRelationships = [
                    'أب', 'أم', 'أخ', 'أخت', 'جد', 'جدة',
                    'عم', 'عمة', 'خال', 'خالة', 'وصي', 'أخرى'
                ];

                if ($guardianId <= 0 || $studentId <= 0) {
                    throw new RuntimeException('يرجى اختيار ولي الأمر والطالب.');
                }

                if (!in_array($relationship, $allowedRelationships, true)) {
                    throw new RuntimeException('صلة القرابة المختارة غير صحيحة.');
                }

                $stmt = $pdo->prepare("SELECT id FROM guardians WHERE id = ?");
                $stmt->execute([$guardianId]);

                if (!$stmt->fetch()) {
                    throw new RuntimeException('ولي الأمر غير موجود.');
                }

                $stmt = $pdo->prepare("
                    SELECT s.id
                    FROM students s
                    INNER JOIN users u ON u.id = s.user_id
                    WHERE s.id = ? AND s.is_active = 1 AND u.is_active = 1
                    LIMIT 1
                ");
                $stmt->execute([$studentId]);

                if (!$stmt->fetch()) {
                    throw new RuntimeException('الطالب غير موجود أو غير نشط.');
                }

                $stmt = $pdo->prepare("
                    SELECT id FROM student_guardians
                    WHERE student_id = ? AND guardian_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$studentId, $guardianId]);

                if ($stmt->fetch()) {
                    throw new RuntimeException(
                        'هذا الطالب مرتبط بولي الأمر بالفعل.'
                    );
                }

                $pdo->beginTransaction();

                if ($isPrimary) {
                    $stmt = $pdo->prepare("
                        UPDATE student_guardians
                        SET is_primary = 0
                        WHERE student_id = ?
                    ");
                    $stmt->execute([$studentId]);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO student_guardians
                        (student_id, guardian_id, relationship,
                         is_primary, receives_notifications)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $studentId,
                    $guardianId,
                    $relationship,
                    $isPrimary,
                    $receivesNotifications
                ]);

                $pdo->commit();
                $message = 'تم ربط الطالب بولي الأمر بنجاح.';
            } elseif ($action === 'unlink_student') {
                $linkId = (int)($_POST['link_id'] ?? 0);

                if ($linkId <= 0) {
                    throw new RuntimeException('رقم الربط غير صحيح.');
                }

                $stmt = $pdo->prepare("
                    DELETE FROM student_guardians WHERE id = ?
                ");
                $stmt->execute([$linkId]);

                $message = $stmt->rowCount()
                    ? 'تم إلغاء الربط بنجاح، ولم يتم حذف بيانات الطالب.'
                    : 'الربط المطلوب غير موجود.';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);

                if ($id <= 0) {
                    throw new RuntimeException('رقم ولي الأمر غير صحيح.');
                }

                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM student_guardians
                    WHERE guardian_id = ?
                ");
                $stmt->execute([$id]);

                if ((int)$stmt->fetchColumn() > 0) {
                    throw new RuntimeException(
                        'لا يمكن حذف ولي الأمر قبل إلغاء ارتباطه بالطلاب.'
                    );
                }

                $stmt = $pdo->prepare("DELETE FROM guardians WHERE id = ?");
                $stmt->execute([$id]);

                $message = $stmt->rowCount()
                    ? 'تم حذف سجل ولي الأمر.'
                    : 'السجل المطلوب غير موجود.';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Guardians error: ' . $e->getMessage());

            $message = $e->getCode() === '23000'
                ? 'البيانات مكررة أو مرتبطة بسجل آخر.'
                : 'حدث خطأ في قاعدة البيانات.';
            $messageType = 'danger';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    $stmt = $pdo->prepare("
        SELECT g.*, u.full_name, u.username
        FROM guardians g
        INNER JOIN users u ON u.id = g.user_id
        WHERE g.id = ?
        LIMIT 1
    ");
    $stmt->execute([$editId]);
    $editGuardian = $stmt->fetch() ?: null;
}

$stmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.username, g.id AS guardian_profile_id
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    LEFT JOIN guardians g ON g.user_id = u.id
    WHERE r.role_name = 'guardian'
      AND (u.is_active = 1 OR g.id = ?)
    ORDER BY u.full_name
");
$stmt->execute([$editGuardian ? (int)$editGuardian['id'] : 0]);
$availableUsers = $stmt->fetchAll();

$guardians = $pdo->query("
    SELECT g.*, u.full_name, u.username, u.phone, u.email
    FROM guardians g
    INNER JOIN users u ON u.id = g.user_id
    ORDER BY g.id DESC
")->fetchAll();

$students = $pdo->query("
    SELECT s.id, s.student_number, u.full_name
    FROM students s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.is_active = 1 AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

$links = $pdo->query("
    SELECT sg.id AS link_id, sg.student_id, sg.guardian_id,
           sg.relationship, sg.is_primary, sg.receives_notifications,
           su.full_name AS student_name, s.student_number,
           gu.full_name AS guardian_name
    FROM student_guardians sg
    INNER JOIN students s ON s.id = sg.student_id
    INNER JOIN users su ON su.id = s.user_id
    INNER JOIN guardians g ON g.id = sg.guardian_id
    INNER JOIN users gu ON gu.id = g.user_id
    ORDER BY gu.full_name, sg.is_primary DESC, su.full_name
")->fetchAll();

$pageTitle = 'إدارة أولياء الأمور';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>إدارة أولياء الأمور</h2>
            <p class="text-muted mb-0">
                إدارة بيانات أولياء الأمور وربطهم بالطلاب
            </p>
        </div>
        <span class="badge bg-primary fs-6">
            العدد: <?= count($guardians) ?>
        </span>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= h($messageType) ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <?= $editGuardian ? 'تعديل بيانات ولي الأمر' : 'إضافة ولي أمر' ?>
        </div>
        <div class="card-body">
            <form method="POST" action="guardians.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editGuardian['id'] ?? 0) ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">حساب ولي الأمر *</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">اختر الحساب</option>
                            <?php foreach ($availableUsers as $account): ?>
                                <option value="<?= (int)$account['id'] ?>"
                                    <?= (int)($editGuardian['user_id'] ?? 0) === (int)$account['id'] ? 'selected' : '' ?>>
                                    <?= h($account['full_name']) ?> (<?= h($account['username']) ?>)<?= !empty($account['guardian_profile_id']) ? ' — ملفه مسجل' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$availableUsers): ?>
                            <small class="form-text text-muted">
                                لا توجد حسابات أولياء أمور. أنشئ حساباً بدور «ولي أمر» من
                                <a href="users.php">إدارة المستخدمين</a> أولاً.
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الرقم الوطني</label>
                        <input name="national_id" class="form-control" maxlength="50"
                               value="<?= h($editGuardian['national_id'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">المهنة</label>
                        <input name="occupation" class="form-control" maxlength="100"
                               value="<?= h($editGuardian['occupation'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">مكان العمل</label>
                        <input name="workplace" class="form-control" maxlength="150"
                               value="<?= h($editGuardian['workplace'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">العنوان</label>
                        <textarea name="address" class="form-control" maxlength="255" rows="2"><?= h($editGuardian['address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">
                            <?= $editGuardian ? 'حفظ التعديلات' : 'إضافة ولي الأمر' ?>
                        </button>
                        <?php if ($editGuardian): ?>
                            <a href="guardians.php" class="btn btn-secondary">إلغاء</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white">
            ربط طالب بولي أمر
        </div>
        <div class="card-body">
            <form method="POST" action="guardians.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="link_student">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">ولي الأمر *</label>
                        <select name="guardian_id" class="form-select" required>
                            <option value="">اختر ولي الأمر</option>
                            <?php foreach ($guardians as $g): ?>
                                <option value="<?= (int)$g['id'] ?>">
                                    <?= h($g['full_name']) ?> (<?= h($g['username']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">الطالب *</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">اختر الطالب</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?= (int)$s['id'] ?>">
                                    <?= h($s['full_name']) ?> — <?= h($s['student_number']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">صلة القرابة *</label>
                        <select name="relationship" class="form-select" required>
                            <option value="">اختر صلة القرابة</option>
                            <?php foreach (['أب','أم','أخ','أخت','جد','جدة','عم','عمة','خال','خالة','وصي','أخرى'] as $rel): ?>
                                <option value="<?= h($rel) ?>"><?= h($rel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input type="checkbox" class="form-check-input"
                                   name="is_primary" id="is_primary">
                            <label for="is_primary" class="form-check-label">
                                ولي الأمر الأساسي
                            </label>
                        </div>
                    </div>

                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input type="checkbox" class="form-check-input"
                                   name="receives_notifications"
                                   id="receives_notifications" checked>
                            <label for="receives_notifications" class="form-check-label">
                                يستقبل الإشعارات
                            </label>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-success">
                            ربط الطالب بولي الأمر
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header">روابط أولياء الأمور بالطلاب</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ولي الأمر</th>
                            <th>الطالب</th>
                            <th>رقم الطالب</th>
                            <th>صلة القرابة</th>
                            <th>أساسي</th>
                            <th>الإشعارات</th>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$links): ?>
                        <tr><td colspan="7" class="text-center text-muted">
                            لا توجد روابط مسجلة حالياً.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($links as $link): ?>
                            <tr>
                                <td><?= h($link['guardian_name']) ?></td>
                                <td><?= h($link['student_name']) ?></td>
                                <td><?= h($link['student_number']) ?></td>
                                <td><?= h($link['relationship']) ?></td>
                                <td><?= (int)$link['is_primary'] === 1 ? 'نعم' : 'لا' ?></td>
                                <td><?= (int)$link['receives_notifications'] === 1 ? 'نعم' : 'لا' ?></td>
                                <td>
                                    <form method="POST" action="guardians.php"
                                          onsubmit="return confirm('هل تريد إلغاء هذا الربط؟');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="unlink_student">
                                        <input type="hidden" name="link_id" value="<?= (int)$link['link_id'] ?>">
                                        <button class="btn btn-sm btn-danger" type="submit">
                                            إلغاء الربط
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">قائمة أولياء الأمور</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th><th>الاسم</th><th>اسم المستخدم</th>
                            <th>الهاتف</th><th>الرقم الوطني</th><th>المهنة</th>
                            <th>مكان العمل</th><th>العنوان</th><th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$guardians): ?>
                        <tr><td colspan="9" class="text-center text-muted">
                            لا توجد بيانات مسجلة حالياً.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($guardians as $index => $g): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= h($g['full_name']) ?></td>
                                <td><?= h($g['username']) ?></td>
                                <td><?= h($g['phone']) ?></td>
                                <td><?= h($g['national_id']) ?></td>
                                <td><?= h($g['occupation']) ?></td>
                                <td><?= h($g['workplace']) ?></td>
                                <td><?= h($g['address']) ?></td>
                                <td>
                                    <a href="guardians.php?edit=<?= (int)$g['id'] ?>"
                                       class="btn btn-sm btn-warning mb-1">تعديل</a>
                                    <form method="POST" action="guardians.php"
                                          class="d-inline"
                                          onsubmit="return confirm('هل تريد حذف سجل ولي الأمر؟');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                                        <button class="btn btn-sm btn-danger" type="submit">
                                            حذف
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
