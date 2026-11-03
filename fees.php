
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$message = '';
$messageType = 'success';
$editFee = null;

function feeEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// معالجة الإضافة والتعديل والحذف
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
                $studentId = (int)($_POST['student_id'] ?? 0);
                $yearId = (int)($_POST['academic_year_id'] ?? 0);
                $feeName = trim($_POST['fee_name'] ?? '');
                $totalInput = trim($_POST['total_amount'] ?? '');
                $paidInput = trim($_POST['paid_amount'] ?? '');
                $dueDate = trim($_POST['due_date'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                $waived = isset($_POST['waived']);

                if ($studentId <= 0 || $yearId <= 0) {
                    throw new RuntimeException(
                        'يرجى اختيار الطالب والسنة الدراسية.'
                    );
                }

                if ($feeName === '' || mb_strlen($feeName) > 150) {
                    throw new RuntimeException(
                        'يرجى إدخال اسم صحيح للرسوم، بحد أقصى 150 حرفاً.'
                    );
                }

                if (
                    !is_numeric($totalInput) ||
                    !is_numeric($paidInput) ||
                    (float)$totalInput <= 0 ||
                    (float)$paidInput < 0
                ) {
                    throw new RuntimeException(
                        'تحقق من إجمالي الرسوم والمبلغ المدفوع.'
                    );
                }

                $total = round((float)$totalInput, 2);
                $paid = round((float)$paidInput, 2);

                if ($paid > $total) {
                    throw new RuntimeException(
                        'المبلغ المدفوع لا يمكن أن يتجاوز إجمالي الرسوم.'
                    );
                }

                if ($dueDate !== '') {
                    $date = DateTime::createFromFormat('!Y-m-d', $dueDate);

                    if (!$date || $date->format('Y-m-d') !== $dueDate) {
                        throw new RuntimeException('تاريخ الاستحقاق غير صحيح.');
                    }
                } else {
                    $dueDate = null;
                }

                // التأكد من وجود الطالب
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM students
                    WHERE id = :id AND is_active = 1
                    LIMIT 1
                ");
                $stmt->execute(['id' => $studentId]);

                if (!$stmt->fetch()) {
                    throw new RuntimeException(
                        'الطالب غير موجود أو غير نشط.'
                    );
                }

                // التأكد من وجود السنة الدراسية
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM academic_years
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute(['id' => $yearId]);

                if (!$stmt->fetch()) {
                    throw new RuntimeException(
                        'السنة الدراسية المحددة غير موجودة.'
                    );
                }

                // تحديد حالة السداد تلقائياً
                if ($waived) {
                    $status = 'waived';
                } elseif ($paid == 0) {
                    $status = 'unpaid';
                } elseif ($paid >= $total) {
                    $status = 'paid';
                } else {
                    $status = 'partial';
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE student_fees
                        SET student_id = :student_id,
                            academic_year_id = :year_id,
                            fee_name = :fee_name,
                            total_amount = :total,
                            paid_amount = :paid,
                            due_date = :due_date,
                            status = :status,
                            notes = :notes
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        'student_id' => $studentId,
                        'year_id' => $yearId,
                        'fee_name' => $feeName,
                        'total' => $total,
                        'paid' => $paid,
                        'due_date' => $dueDate,
                        'status' => $status,
                        'notes' => $notes !== '' ? $notes : null,
                        'id' => $id
                    ]);

                    $message = 'تم تحديث الرسوم الدراسية بنجاح.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO student_fees (
                            student_id,
                            academic_year_id,
                            fee_name,
                            total_amount,
                            paid_amount,
                            due_date,
                            status,
                            notes
                        ) VALUES (
                            :student_id,
                            :year_id,
                            :fee_name,
                            :total,
                            :paid,
                            :due_date,
                            :status,
                            :notes
                        )
                    ");

                    $stmt->execute([
                        'student_id' => $studentId,
                        'year_id' => $yearId,
                        'fee_name' => $feeName,
                        'total' => $total,
                        'paid' => $paid,
                        'due_date' => $dueDate,
                        'status' => $status,
                        'notes' => $notes !== '' ? $notes : null
                    ]);

                    $message = 'تمت إضافة الرسوم الدراسية بنجاح.';
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);

                if ($id <= 0) {
                    throw new RuntimeException('رقم سجل الرسوم غير صحيح.');
                }

                $stmt = $pdo->prepare("
                    DELETE FROM student_fees
                    WHERE id = :id
                ");
                $stmt->execute(['id' => $id]);

                $message = $stmt->rowCount()
                    ? 'تم حذف سجل الرسوم.'
                    : 'سجل الرسوم المطلوب غير موجود.';
            }
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        } catch (PDOException $e) {
            error_log($e->getMessage());

            if ($e->getCode() === '23000') {
                $message = 'تعذر تنفيذ العملية بسبب ارتباط السجل ببيانات أخرى.';
            } else {
                $message = 'حدث خطأ في قاعدة البيانات. راجع سجل الأخطاء.';
            }

            $messageType = 'danger';
        }
    }
}

// جلب سجل الرسوم عند التعديل
$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM student_fees
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $editId]);
    $editFee = $stmt->fetch() ?: null;

    if (!$editFee && $message === '') {
        $message = 'سجل الرسوم المطلوب غير موجود.';
        $messageType = 'danger';
    }
}

// جلب الطلاب النشطين
$stmt = $pdo->query("
    SELECT
        s.id,
        s.student_number,
        u.full_name
    FROM students s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.is_active = 1
    ORDER BY u.full_name
");
$students = $stmt->fetchAll();

// جلب السنوات الدراسية
$stmt = $pdo->query("
    SELECT id, year_name
    FROM academic_years
    ORDER BY is_current DESC, start_date DESC
");
$years = $stmt->fetchAll();

// جلب الرسوم المسجلة
$stmt = $pdo->query("
    SELECT
        f.*,
        u.full_name AS student_name,
        s.student_number,
        ay.year_name
    FROM student_fees f
    INNER JOIN students s ON s.id = f.student_id
    INNER JOIN users u ON u.id = s.user_id
    INNER JOIN academic_years ay ON ay.id = f.academic_year_id
    ORDER BY f.id DESC
");
$fees = $stmt->fetchAll();

// إجمالي الرسوم والمتبقي، مع احتساب الإعفاء
$totalFees = 0;
$totalPaid = 0;
$totalRemaining = 0;

foreach ($fees as $fee) {
    $totalFees += (float)$fee['total_amount'];
    $totalPaid += (float)$fee['paid_amount'];

    if ($fee['status'] !== 'waived') {
        $totalRemaining += max(
            0,
            (float)$fee['total_amount'] - (float)$fee['paid_amount']
        );
    }
}

$statusLabels = [
    'unpaid' => 'غير مدفوعة',
    'partial' => 'مدفوعة جزئياً',
    'paid' => 'مدفوعة بالكامل',
    'waived' => 'معفاة'
];

$statusColors = [
    'unpaid' => 'danger',
    'partial' => 'warning',
    'paid' => 'success',
    'waived' => 'secondary'
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" dir="rtl">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>إدارة الرسوم الدراسية</h2>
            <p class="text-muted mb-0">
                تسجيل الرسوم ومتابعة السداد والمبالغ المتبقية
            </p>
        </div>
        <span class="badge bg-primary fs-6">
            عدد السجلات: <?= count($fees) ?>
        </span>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= feeEscape($messageType) ?> alert-dismissible fade show">
            <?= feeEscape($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted">إجمالي الرسوم المسجلة</div>
                    <h4 class="mt-2">
                        <?= number_format($totalFees, 2) ?>
                    </h4>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted">إجمالي المبالغ المدفوعة</div>
                    <h4 class="text-success mt-2">
                        <?= number_format($totalPaid, 2) ?>
                    </h4>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted">المبالغ المتبقية للتحصيل</div>
                    <h4 class="text-danger mt-2">
                        <?= number_format($totalRemaining, 2) ?>
                    </h4>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$students || !$years): ?>
        <div class="alert alert-warning">
            <?php if (!$students): ?>
                لا توجد بيانات طلاب نشطين. أضف طالباً أولاً.
            <?php endif; ?>

            <?php if (!$years): ?>
                لا توجد سنوات دراسية. أضف سنة دراسية أولاً.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <?= $editFee ? 'تعديل الرسوم' : 'إضافة رسوم دراسية' ?>
        </div>

        <div class="card-body">
            <form method="POST" action="fees.php">
                <input type="hidden" name="csrf_token"
                       value="<?= feeEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id"
                       value="<?= (int)($editFee['id'] ?? 0) ?>">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">الطالب *</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">اختر الطالب</option>
                            <?php foreach ($students as $student): ?>
                                <option
                                    value="<?= (int)$student['id'] ?>"
                                    <?= (int)($editFee['student_id'] ?? 0) === (int)$student['id'] ? 'selected' : '' ?>>
                                    <?= feeEscape($student['full_name']) ?>
                                    — <?= feeEscape($student['student_number']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">السنة الدراسية *</label>
                        <select name="academic_year_id" class="form-select" required>
                            <option value="">اختر السنة الدراسية</option>
                            <?php foreach ($years as $year): ?>
                                <option
                                    value="<?= (int)$year['id'] ?>"
                                    <?= (int)($editFee['academic_year_id'] ?? 0) === (int)$year['id'] ? 'selected' : '' ?>>
                                    <?= feeEscape($year['year_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">اسم الرسوم *</label>
                        <input type="text" name="fee_name" class="form-control"
                               maxlength="150" required
                               placeholder="مثال: الرسوم الدراسية السنوية"
                               value="<?= feeEscape($editFee['fee_name'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">تاريخ الاستحقاق</label>
                        <input type="date" name="due_date" class="form-control"
                               value="<?= feeEscape($editFee['due_date'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">إجمالي الرسوم *</label>
                        <input type="number" name="total_amount"
                               class="form-control" min="0.01" step="0.01"
                               required
                               value="<?= feeEscape($editFee['total_amount'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">المبلغ المدفوع *</label>
                        <input type="number" name="paid_amount"
                               class="form-control" min="0" step="0.01"
                               required
                               value="<?= feeEscape($editFee['paid_amount'] ?? '0.00') ?>">
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" name="waived" id="waived"
                                   class="form-check-input" value="1"
                                   <?= ($editFee['status'] ?? '') === 'waived' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="waived">
                                إعفاء الطالب من هذه الرسوم
                            </label>
                        </div>
                        <small class="text-muted">
                            عند اختيار الإعفاء لن تُحسب هذه الرسوم ضمن المبالغ المتبقية للتحصيل.
                        </small>
                    </div>

                    <div class="col-12">
                        <label class="form-label">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="3"><?= feeEscape($editFee['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"
                            <?= (!$students || !$years) ? 'disabled' : '' ?>>
                            <?= $editFee ? 'حفظ التعديلات' : 'إضافة الرسوم' ?>
                        </button>

                        <?php if ($editFee): ?>
                            <a href="fees.php" class="btn btn-secondary">إلغاء</a>
                        <?php endif; ?>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">سجلات الرسوم الدراسية</div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>الطالب</th>
                            <th>رقم الطالب</th>
                            <th>السنة الدراسية</th>
                            <th>نوع الرسوم</th>
                            <th>الإجمالي</th>
                            <th>المدفوع</th>
                            <th>المتبقي</th>
                            <th>الاستحقاق</th>
                            <th>الحالة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$fees): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted">
                                لا توجد رسوم مسجلة حتى الآن.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($fees as $index => $fee): ?>
                            <?php
                            $remaining = $fee['status'] === 'waived'
                                ? 0
                                : max(
                                    0,
                                    (float)$fee['total_amount'] -
                                    (float)$fee['paid_amount']
                                );
                            ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= feeEscape($fee['student_name']) ?></td>
                                <td><?= feeEscape($fee['student_number']) ?></td>
                                <td><?= feeEscape($fee['year_name']) ?></td>
                                <td><?= feeEscape($fee['fee_name']) ?></td>
                                <td><?= number_format((float)$fee['total_amount'], 2) ?></td>
                                <td><?= number_format((float)$fee['paid_amount'], 2) ?></td>
                                <td><?= number_format($remaining, 2) ?></td>
                                <td><?= feeEscape($fee['due_date'] ?: '—') ?></td>
                                <td>
                                    <span class="badge bg-<?= feeEscape(
                                        $statusColors[$fee['status']] ?? 'secondary'
                                    ) ?>">
                                        <?= feeEscape(
                                            $statusLabels[$fee['status']] ?? $fee['status']
                                        ) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="fees.php?edit=<?= (int)$fee['id'] ?>"
                                       class="btn btn-sm btn-warning mb-1">
                                        تعديل
                                    </a>

                                    <form method="POST" action="fees.php"
                                          class="d-inline"
                                          onsubmit="return confirm('هل تريد حذف سجل الرسوم هذا؟');">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= feeEscape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id"
                                               value="<?= (int)$fee['id'] ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-danger">
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
