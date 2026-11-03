
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

$methods = [
    'cash'          => 'نقداً',
    'bank_transfer' => 'تحويل بنكي',
    'card'          => 'بطاقة',
    'mobile_money'  => 'دفع إلكتروني',
    'other'         => 'أخرى',
];

function paymentEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function paymentRedirect(): void
{
    header('Location: payments.php');
    exit;
}

$error = '';
$message = $_SESSION['payments_message'] ?? '';
unset($_SESSION['payments_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(403);
        exit('انتهت صلاحية الطلب. أعد تحميل الصفحة وحاول مرة أخرى.');
    }

    try {
        $feeId = filter_var(
            $_POST['student_fee_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $amountInput = trim($_POST['amount'] ?? '');
        $paymentDate = $_POST['payment_date'] ?? '';
        $method = $_POST['payment_method'] ?? '';
        $notes = trim($_POST['notes'] ?? '');

        if (!$feeId || $feeId < 1) {
            throw new RuntimeException('يرجى اختيار الرسوم المطلوب سدادها.');
        }

        if (
            $amountInput === '' ||
            !is_numeric($amountInput) ||
            !is_finite((float)$amountInput) ||
            (float)$amountInput <= 0 ||
            (float)$amountInput > 9999999999.99
        ) {
            throw new RuntimeException('أدخل مبلغاً صحيحاً أكبر من صفر.');
        }

        $dateObject = DateTime::createFromFormat('!Y-m-d', $paymentDate);

        if (!$dateObject || $dateObject->format('Y-m-d') !== $paymentDate) {
            throw new RuntimeException('يرجى اختيار تاريخ صحيح للدفعة.');
        }

        if (!isset($methods[$method])) {
            throw new RuntimeException('طريقة الدفع المحددة غير صحيحة.');
        }

        if (strlen($notes) > 60000) {
            throw new RuntimeException('الملاحظات طويلة جداً.');
        }

        $amount = round((float)$amountInput, 2);
        $pdo->beginTransaction();

        // قفل سجل الرسوم أثناء تنفيذ الدفعة لمنع تجاوز المستحق.
        $stmt = $pdo->prepare("
            SELECT id, total_amount, paid_amount, status
            FROM student_fees
            WHERE id = :id
            FOR UPDATE
        ");
        $stmt->execute(['id' => $feeId]);
        $fee = $stmt->fetch();

        if (!$fee) {
            throw new RuntimeException('الرسوم المحددة غير موجودة.');
        }

        if ($fee['status'] === 'waived') {
            throw new RuntimeException('لا يمكن تسجيل دفعة لرسوم معفاة.');
        }

        $total = round((float)$fee['total_amount'], 2);
        $paid = round((float)$fee['paid_amount'], 2);
        $remaining = round($total - $paid, 2);

        if ($remaining <= 0) {
            throw new RuntimeException('هذه الرسوم مسددة بالكامل بالفعل.');
        }

        if ($amount > $remaining + 0.001) {
            throw new RuntimeException(
                'المبلغ أكبر من المتبقي. المبلغ المستحق هو: ' .
                number_format($remaining, 2)
            );
        }

        $newPaid = round($paid + $amount, 2);
        $newStatus = $newPaid >= $total ? 'paid' : 'partial';

        // إنشاء رقم إيصال فريد.
        $receiptNumber = 'PAY-' . date('Ymd') . '-' .
            strtoupper(bin2hex(random_bytes(5)));

        $stmt = $pdo->prepare("
            INSERT INTO payments (
                student_fee_id,
                amount,
                payment_date,
                payment_method,
                receipt_number,
                received_by,
                notes
            ) VALUES (
                :fee_id,
                :amount,
                :payment_date,
                :method,
                :receipt,
                :received_by,
                :notes
            )
        ");

        $stmt->execute([
            'fee_id' => $feeId,
            'amount' => number_format($amount, 2, '.', ''),
            'payment_date' => $paymentDate,
            'method' => $method,
            'receipt' => $receiptNumber,
            'received_by' => (int)$_SESSION['user_id'],
            'notes' => $notes === '' ? null : $notes,
        ]);

        // تحديث ملخص الرسوم بعد نجاح تسجيل الدفعة.
        $stmt = $pdo->prepare("
            UPDATE student_fees
            SET paid_amount = :paid,
                status = :status
            WHERE id = :id
        ");

        $stmt->execute([
            'paid' => number_format($newPaid, 2, '.', ''),
            'status' => $newStatus,
            'id' => $feeId,
        ]);

        $pdo->commit();

        $_SESSION['payments_message'] =
            'تم تسجيل الدفعة بنجاح. رقم الإيصال: ' . $receiptNumber;

        paymentRedirect();

    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $ex->getMessage();

    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log($ex->getMessage());
        $error = 'تعذر تسجيل الدفعة. تحقق من البيانات وحاول مرة أخرى.';
    }
}

// الرسوم التي ما زال عليها مبلغ مستحق.
$stmt = $pdo->query("
    SELECT
        sf.id,
        sf.fee_name,
        sf.total_amount,
        sf.paid_amount,
        sf.due_date,
        sf.status,
        s.student_number,
        u.full_name AS student_name
    FROM student_fees sf
    INNER JOIN students s ON s.id = sf.student_id
    INNER JOIN users u ON u.id = s.user_id
    WHERE sf.status IN ('unpaid', 'partial')
      AND sf.paid_amount < sf.total_amount
    ORDER BY u.full_name ASC, sf.due_date ASC
");

$unpaidFees = $stmt->fetchAll();

// البحث في سجل المدفوعات.
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        p.id,
        p.amount,
        p.payment_date,
        p.payment_method,
        p.receipt_number,
        p.notes,
        sf.fee_name,
        s.student_number,
        student_user.full_name AS student_name,
        receiver.full_name AS receiver_name
    FROM payments p
    INNER JOIN student_fees sf ON sf.id = p.student_fee_id
    INNER JOIN students s ON s.id = sf.student_id
    INNER JOIN users student_user ON student_user.id = s.user_id
    LEFT JOIN users receiver ON receiver.id = p.received_by
    WHERE (
        student_user.full_name LIKE :name_search
        OR s.student_number LIKE :number_search
        OR p.receipt_number LIKE :receipt_search
    )
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 300
";

$stmt = $pdo->prepare($sql);
$searchTerm = '%' . $search . '%';
$stmt->execute([
    'name_search' => $searchTerm,
    'number_search' => $searchTerm,
    'receipt_search' => $searchTerm,
]);

$payments = $stmt->fetchAll();

$totalCollected = 0;
foreach ($payments as $payment) {
    $totalCollected += (float)$payment['amount'];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" dir="rtl">

    <div class="mb-4">
        <h2 class="fw-bold">المدفوعات والتحصيل</h2>
        <p class="text-secondary mb-0">
            تسجيل دفعات الطلاب ومتابعة إيصالات السداد
        </p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success">
            <?= paymentEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= paymentEscape($error) ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-secondary">إجمالي الدفعات المعروضة</div>
                    <div class="fs-3 fw-bold text-success">
                        <?= number_format($totalCollected, 2) ?>
                    </div>
                    <small class="text-secondary">
                        آخر 300 دفعة كحد أقصى
                    </small>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-secondary">رسوم عليها مبالغ مستحقة</div>
                    <div class="fs-3 fw-bold text-primary">
                        <?= count($unpaidFees) ?>
                    </div>
                    <small class="text-secondary">
                        عدد سجلات الرسوم غير المسددة بالكامل
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">تسجيل دفعة جديدة</h5>
        </div>

        <div class="card-body">
            <?php if (!$unpaidFees): ?>
                <div class="alert alert-info mb-0">
                    لا توجد رسوم مستحقة حالياً. أضف الرسوم للطلاب أولاً من شاشة الرسوم الدراسية.
                </div>
            <?php else: ?>
                <form method="post" action="payments.php">
                    <input type="hidden" name="csrf_token"
                           value="<?= paymentEscape($csrfToken) ?>">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">الطالب والرسوم المستحقة *</label>
                            <select name="student_fee_id" class="form-select" required>
                                <option value="">اختر الطالب والرسوم</option>

                                <?php foreach ($unpaidFees as $fee): ?>
                                    <?php
                                    $remaining = round(
                                        (float)$fee['total_amount'] -
                                        (float)$fee['paid_amount'],
                                        2
                                    );
                                    ?>
                                    <option value="<?= (int)$fee['id'] ?>"
                                        <?= (string)($_POST['student_fee_id'] ?? '') === (string)$fee['id'] ? 'selected' : '' ?>>
                                        <?= paymentEscape($fee['student_name']) ?>
                                        — <?= paymentEscape($fee['student_number']) ?>
                                        — <?= paymentEscape($fee['fee_name']) ?>
                                        — المتبقي: <?= number_format($remaining, 2) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">مبلغ الدفعة *</label>
                            <input
                                type="number"
                                name="amount"
                                class="form-control"
                                min="0.01"
                                max="9999999999.99"
                                step="0.01"
                                required
                                value="<?= paymentEscape($_POST['amount'] ?? '') ?>"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">تاريخ الدفع *</label>
                            <input
                                type="date"
                                name="payment_date"
                                class="form-control"
                                required
                                value="<?= paymentEscape($_POST['payment_date'] ?? date('Y-m-d')) ?>"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">طريقة الدفع *</label>
                            <select name="payment_method" class="form-select" required>
                                <?php foreach ($methods as $value => $label): ?>
                                    <option value="<?= paymentEscape($value) ?>"
                                        <?= ($_POST['payment_method'] ?? 'cash') === $value ? 'selected' : '' ?>>
                                        <?= paymentEscape($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">ملاحظات</label>
                            <textarea
                                name="notes"
                                class="form-control"
                                rows="2"
                            ><?= paymentEscape($_POST['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                تسجيل الدفعة وإصدار رقم الإيصال
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">سجل المدفوعات</h5>
        </div>

        <div class="card-body">
            <form method="get" action="payments.php" class="row g-2 mb-4">
                <div class="col-md-9">
                    <input
                        type="search"
                        name="search"
                        class="form-control"
                        placeholder="ابحث باسم الطالب أو رقمه أو رقم الإيصال"
                        value="<?= paymentEscape($search) ?>"
                    >
                </div>
                <div class="col-md-3 d-grid">
                    <button type="submit" class="btn btn-outline-primary">
                        بحث
                    </button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>رقم الإيصال</th>
                            <th>الطالب</th>
                            <th>الرسوم</th>
                            <th>المبلغ</th>
                            <th>تاريخ الدفع</th>
                            <th>طريقة الدفع</th>
                            <th>استلمها</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$payments): ?>
                        <tr>
                            <td colspan="8" class="text-center text-secondary py-4">
                                لا توجد دفعات مسجلة حتى الآن.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= (int)$payment['id'] ?></td>
                                <td>
                                    <span class="fw-semibold">
                                        <?= paymentEscape($payment['receipt_number']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= paymentEscape($payment['student_name']) ?>
                                    <br>
                                    <small class="text-secondary">
                                        <?= paymentEscape($payment['student_number']) ?>
                                    </small>
                                </td>
                                <td><?= paymentEscape($payment['fee_name']) ?></td>
                                <td class="fw-bold text-success">
                                    <?= number_format((float)$payment['amount'], 2) ?>
                                </td>
                                <td><?= paymentEscape($payment['payment_date']) ?></td>
                                <td>
                                    <?= paymentEscape($methods[$payment['payment_method']] ?? 'أخرى') ?>
                                </td>
                                <td>
                                    <?= paymentEscape($payment['receiver_name'] ?? 'غير محدد') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>

                    <tfoot class="table-light">
                        <tr>
                            <th colspan="4">إجمالي النتائج المعروضة</th>
                            <th><?= number_format($totalCollected, 2) ?></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
