
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = 'إدارة السنوات الدراسية';

$success = '';
$error = '';

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['academic_years_csrf'])) {
    $_SESSION['academic_years_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['academic_years_csrf'], $token)
    ) {
        $error = 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مرة أخرى.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'add') {
                $yearName = trim($_POST['year_name'] ?? '');
                $startDate = $_POST['start_date'] ?? '';
                $endDate = $_POST['end_date'] ?? '';
                $status = $_POST['status'] ?? 'planned';

                if ($yearName === '' || strlen($yearName) > 20) {
                    throw new RuntimeException('أدخل اسم السنة الدراسية بحد أقصى 20 حرفاً.');
                }

                $validDate = static function ($date): bool {
                    if (!is_string($date)) {
                        return false;
                    }

                    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

                    return $parsed !== false
                        && $parsed->format('Y-m-d') === $date;
                };

                if (!$validDate($startDate) || !$validDate($endDate)) {
                    throw new RuntimeException('يرجى إدخال تاريخي بداية ونهاية صحيحين.');
                }

                if ($endDate <= $startDate) {
                    throw new RuntimeException('تاريخ نهاية السنة يجب أن يكون بعد تاريخ البداية.');
                }

                if (!in_array($status, ['planned', 'active', 'completed'], true)) {
                    throw new RuntimeException('حالة السنة الدراسية غير صحيحة.');
                }

                $pdo->beginTransaction();

                if ($status === 'active') {
                    $pdo->exec("
                        UPDATE academic_years
                        SET is_current = 0
                        WHERE is_current = 1
                    ");
                }

                $stmt = $pdo->prepare("
                    INSERT INTO academic_years
                        (year_name, start_date, end_date, is_current, status)
                    VALUES
                        (:year_name, :start_date, :end_date, :is_current, :status)
                ");

                $stmt->execute([
                    'year_name' => $yearName,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_current' => $status === 'active' ? 1 : 0,
                    'status' => $status
                ]);

                $pdo->commit();
                $success = 'تمت إضافة السنة الدراسية بنجاح.';

            } elseif ($action === 'set_current') {
                $yearId = filter_var(
                    $_POST['year_id'] ?? '',
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if ($yearId === false) {
                    throw new RuntimeException('معرّف السنة الدراسية غير صحيح.');
                }

                $pdo->beginTransaction();

                $check = $pdo->prepare("
                    SELECT id
                    FROM academic_years
                    WHERE id = :id
                    FOR UPDATE
                ");
                $check->execute(['id' => $yearId]);

                if (!$check->fetch()) {
                    throw new RuntimeException('السنة الدراسية غير موجودة.');
                }

                $pdo->exec("
                    UPDATE academic_years
                    SET is_current = 0
                    WHERE is_current = 1
                ");

                $update = $pdo->prepare("
                    UPDATE academic_years
                    SET is_current = 1, status = 'active'
                    WHERE id = :id
                ");
                $update->execute(['id' => $yearId]);

                $pdo->commit();
                $success = 'تم تحديد السنة الحالية بنجاح.';

            } elseif ($action === 'change_status') {
                $yearId = filter_var(
                    $_POST['year_id'] ?? '',
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                $status = $_POST['status'] ?? '';

                if ($yearId === false) {
                    throw new RuntimeException('معرّف السنة الدراسية غير صحيح.');
                }

                if (!in_array($status, ['planned', 'active', 'completed'], true)) {
                    throw new RuntimeException('الحالة المحددة غير صحيحة.');
                }

                $pdo->beginTransaction();

                if ($status === 'active') {
                    $pdo->exec("
                        UPDATE academic_years
                        SET is_current = 0
                        WHERE is_current = 1
                    ");
                }

                $stmt = $pdo->prepare("
                    UPDATE academic_years
                    SET status = :status,
                        is_current = :is_current
                    WHERE id = :id
                ");

                $stmt->execute([
                    'status' => $status,
                    'is_current' => $status === 'active' ? 1 : 0,
                    'id' => $yearId
                ]);

                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare(
                        'SELECT id FROM academic_years WHERE id = :id'
                    );
                    $check->execute(['id' => $yearId]);

                    if (!$check->fetch()) {
                        throw new RuntimeException('السنة الدراسية غير موجودة.');
                    }
                }

                $pdo->commit();
                $success = 'تم تحديث حالة السنة الدراسية.';

            } else {
                throw new RuntimeException('العملية المطلوبة غير معروفة.');
            }

            $_SESSION['academic_years_csrf'] = bin2hex(random_bytes(32));

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($ex instanceof PDOException && $ex->getCode() === '23000') {
                $error = 'اسم السنة الدراسية موجود مسبقاً.';
            } elseif ($ex instanceof RuntimeException) {
                $error = $ex->getMessage();
            } else {
                error_log($ex->getMessage());
                $error = 'حدث خطأ أثناء تنفيذ العملية. تحقق من السجل وحاول مرة أخرى.';
            }
        }
    }
}

$stmt = $pdo->query("
    SELECT id, year_name, start_date, end_date, is_current, status, created_at
    FROM academic_years
    ORDER BY start_date DESC, id DESC
");

$years = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<style>
.academic-page {
    max-width: 1100px;
    margin: 0 auto;
    padding: 20px 0 40px;
}
.academic-card {
    background: #fff;
    border: 1px solid #e1e8ef;
    border-radius: 14px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 4px 16px rgba(20, 50, 70, .04);
}
.academic-page h2,
.academic-page h5 {
    color: #0D5C8C;
    font-weight: 700;
}
.academic-page .form-control,
.academic-page .form-select {
    min-height: 42px;
    border-radius: 8px;
}
.academic-page .form-label {
    font-weight: 600;
}
.academic-page .table th {
    background: #E8F1F8;
    color: #08405F;
    white-space: nowrap;
}
.academic-page .table td {
    vertical-align: middle;
}
.current-year {
    background: #e6f5ec;
    color: #17663a;
}
.status-form {
    min-width: 145px;
}
</style>

<div class="academic-page">

    <div class="mb-4">
        <h2>إدارة السنوات الدراسية</h2>
        <p class="text-secondary">
            إضافة السنوات الدراسية وتحديد السنة الحالية ومتابعة حالتها.
        </p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="academic-card">
        <h5 class="mb-3">إضافة سنة دراسية جديدة</h5>

        <form method="POST" action="academic_years.php">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['academic_years_csrf']) ?>">
            <input type="hidden" name="action" value="add">

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="year_name">اسم السنة الدراسية</label>
                    <input class="form-control" type="text" id="year_name"
                           name="year_name" maxlength="20"
                           placeholder="2026-2027" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="start_date">تاريخ البداية</label>
                    <input class="form-control" type="date" id="start_date"
                           name="start_date" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="end_date">تاريخ النهاية</label>
                    <input class="form-control" type="date" id="end_date"
                           name="end_date" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="status">الحالة</label>
                    <select class="form-select" id="status" name="status">
                        <option value="planned">مخططة</option>
                        <option value="active">نشطة</option>
                        <option value="completed">مكتملة</option>
                    </select>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary px-4">
                        إضافة السنة الدراسية
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="academic-card">
        <h5 class="mb-3">السنوات الدراسية المسجلة</h5>

        <?php if (!$years): ?>
            <div class="alert alert-info mb-0">
                لا توجد سنوات دراسية مسجلة حتى الآن. أضف السنة الأولى من النموذج أعلاه.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>السنة الدراسية</th>
                            <th>البداية</th>
                            <th>النهاية</th>
                            <th>الحالة</th>
                            <th>السنة الحالية</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($years as $index => $year): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= e($year['year_name']) ?></td>
                            <td><?= e($year['start_date']) ?></td>
                            <td><?= e($year['end_date']) ?></td>
                            <td>
                                <?php
                                $statusLabels = [
                                    'planned' => 'مخططة',
                                    'active' => 'نشطة',
                                    'completed' => 'مكتملة'
                                ];
                                ?>
                                <?= e($statusLabels[$year['status']] ?? $year['status']) ?>
                            </td>
                            <td>
                                <?php if ((int)$year['is_current'] === 1): ?>
                                    <span class="badge current-year">السنة الحالية</span>
                                <?php else: ?>
                                    <span class="text-secondary">لا</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if ((int)$year['is_current'] !== 1): ?>
                                        <form method="POST" action="academic_years.php">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($_SESSION['academic_years_csrf']) ?>">
                                            <input type="hidden" name="action" value="set_current">
                                            <input type="hidden" name="year_id"
                                                   value="<?= (int)$year['id'] ?>">
                                            <button class="btn btn-sm btn-outline-primary"
                                                    type="submit">
                                                جعلها الحالية
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" action="academic_years.php"
                                          class="status-form d-flex gap-1">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['academic_years_csrf']) ?>">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="year_id"
                                               value="<?= (int)$year['id'] ?>">

                                        <select class="form-select form-select-sm"
                                                name="status" aria-label="تغيير الحالة">
                                            <option value="planned"
                                                <?= $year['status'] === 'planned' ? 'selected' : '' ?>>
                                                مخططة
                                            </option>
                                            <option value="active"
                                                <?= $year['status'] === 'active' ? 'selected' : '' ?>>
                                                نشطة
                                            </option>
                                            <option value="completed"
                                                <?= $year['status'] === 'completed' ? 'selected' : '' ?>>
                                                مكتملة
                                            </option>
                                        </select>

                                        <button class="btn btn-sm btn-outline-secondary"
                                                type="submit">
                                            حفظ
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
