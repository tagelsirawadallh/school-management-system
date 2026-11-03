
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

$categories = [
    'salaries'    => 'الرواتب',
    'rent'        => 'الإيجار',
    'utilities'   => 'الكهرباء والمياه',
    'supplies'    => 'المستلزمات',
    'maintenance' => 'الصيانة',
    'transport'   => 'المواصلات',
    'other'       => 'أخرى',
];

$paymentMethods = [
    'cash'          => 'نقداً',
    'bank_transfer' => 'تحويل بنكي',
    'card'          => 'بطاقة',
    'mobile_money'  => 'دفع إلكتروني',
    'other'         => 'أخرى',
];

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectExpenses(): void
{
    header('Location: expenses.php');
    exit;
}

$error = '';
$message = '';
$editingExpense = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        exit('انتهت صلاحية الطلب. أعد تحميل الصفحة وحاول مرة أخرى.');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'delete') {
            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$id || $id < 1) {
                throw new RuntimeException('رقم المصروف غير صحيح.');
            }

            $stmt = $pdo->prepare(
                'DELETE FROM expenses WHERE id = :id'
            );
            $stmt->execute(['id' => $id]);

            $_SESSION['expenses_message'] =
                $stmt->rowCount() > 0
                ? 'تم حذف المصروف بنجاح.'
                : 'المصروف غير موجود.';

            redirectExpenses();
        }

        if ($action === 'save') {
            $id = filter_var(
                $_POST['id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $name = trim($_POST['expense_name'] ?? '');
            $category = $_POST['category'] ?? '';
            $amountInput = trim($_POST['amount'] ?? '');
            $date = $_POST['expense_date'] ?? '';
            $method = $_POST['payment_method'] ?? '';
            $reference = trim($_POST['reference_number'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($name === '' || strlen($name) > 450) {
                throw new RuntimeException(
                    'يرجى إدخال اسم المصروف، بحد أقصى 150 حرفاً.'
                );
            }

            if (!isset($categories[$category])) {
                throw new RuntimeException(
                    'يرجى اختيار تصنيف صحيح للمصروف.'
                );
            }

            if (
                $amountInput === '' ||
                !is_numeric($amountInput) ||
                !is_finite((float)$amountInput) ||
                (float)$amountInput <= 0 ||
                (float)$amountInput > 9999999999.99
            ) {
                throw new RuntimeException(
                    'أدخل مبلغاً صحيحاً أكبر من صفر.'
                );
            }

            $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
                throw new RuntimeException(
                    'يرجى إدخال تاريخ صحيح.'
                );
            }

            if (!isset($paymentMethods[$method])) {
                throw new RuntimeException(
                    'يرجى اختيار طريقة دفع صحيحة.'
                );
            }

            if (strlen($reference) > 300) {
                throw new RuntimeException(
                    'الرقم المرجعي يجب ألا يتجاوز 100 حرف.'
                );
            }

            if (strlen($description) > 60000) {
                throw new RuntimeException(
                    'الوصف طويل جداً.'
                );
            }

            $amount = number_format((float)$amountInput, 2, '.', '');
            $reference = $reference === '' ? null : $reference;
            $description = $description === '' ? null : $description;
            $userId = (int)$_SESSION['user_id'];

            if ($id) {
                if ($id < 1) {
                    throw new RuntimeException(
                        'رقم المصروف غير صحيح.'
                    );
                }

                $stmt = $pdo->prepare(
                    'UPDATE expenses
                     SET expense_name = :name,
                         category = :category,
                         amount = :amount,
                         expense_date = :expense_date,
                         payment_method = :method,
                         reference_number = :reference,
                         description = :description
                     WHERE id = :id'
                );

                $stmt->execute([
                    'name' => $name,
                    'category' => $category,
                    'amount' => $amount,
                    'expense_date' => $date,
                    'method' => $method,
                    'reference' => $reference,
                    'description' => $description,
                    'id' => $id,
                ]);

                $_SESSION['expenses_message'] =
                    'تم تحديث بيانات المصروف بنجاح.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO expenses (
                        expense_name,
                        category,
                        amount,
                        expense_date,
                        payment_method,
                        reference_number,
                        description,
                        recorded_by
                    ) VALUES (
                        :name,
                        :category,
                        :amount,
                        :expense_date,
                        :method,
                        :reference,
                        :description,
                        :recorded_by
                    )'
                );

                $stmt->execute([
                    'name' => $name,
                    'category' => $category,
                    'amount' => $amount,
                    'expense_date' => $date,
                    'method' => $method,
                    'reference' => $reference,
                    'description' => $description,
                    'recorded_by' => $userId,
                ]);

                $_SESSION['expenses_message'] =
                    'تم تسجيل المصروف بنجاح.';
            }

            redirectExpenses();
        }

        throw new RuntimeException('العملية المطلوبة غير صحيحة.');

    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $error = 'تعذر تنفيذ العملية. تحقق من البيانات وحاول مرة أخرى.';
    }
}

$message = $_SESSION['expenses_message'] ?? '';
unset($_SESSION['expenses_message']);

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);

if ($editId && $editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editingExpense = $stmt->fetch();

    if (!$editingExpense) {
        $error = 'المصروف المطلوب غير موجود.';
    }
}

$form = $editingExpense ?: [
    'id' => '',
    'expense_name' => '',
    'category' => 'other',
    'amount' => '',
    'expense_date' => date('Y-m-d'),
    'payment_method' => 'cash',
    'reference_number' => '',
    'description' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save') {
    $form = array_merge($form, [
        'id' => $_POST['id'] ?? '',
        'expense_name' => $_POST['expense_name'] ?? '',
        'category' => $_POST['category'] ?? 'other',
        'amount' => $_POST['amount'] ?? '',
        'expense_date' => $_POST['expense_date'] ?? date('Y-m-d'),
        'payment_method' => $_POST['payment_method'] ?? 'cash',
        'reference_number' => $_POST['reference_number'] ?? '',
        'description' => $_POST['description'] ?? '',
    ]);
}

$search = trim($_GET['search'] ?? '');
$selectedCategory = $_GET['category'] ?? '';

$sql = "
    SELECT e.*, u.full_name AS recorder_name
    FROM expenses e
    LEFT JOIN users u ON u.id = e.recorded_by
    WHERE 1 = 1
";
$params = [];

if ($search !== '') {
    $sql .= ' AND (e.expense_name LIKE :search
               OR e.reference_number LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if (isset($categories[$selectedCategory])) {
    $sql .= ' AND e.category = :category';
    $params['category'] = $selectedCategory;
}

$sql .= ' ORDER BY e.expense_date DESC, e.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$totalExpenses = 0;
foreach ($expenses as $expense) {
    $totalExpenses += (float)$expense['amount'];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" dir="rtl">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h2 class="fw-bold mb-1">المصروفات المدرسية</h2>
            <p class="text-secondary mb-0">إدارة وتسجيل مصروفات المدرسة</p>
        </div>
        <a href="expenses.php" class="btn btn-outline-secondary">
            تحديث الصفحة
        </a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="text-secondary">إجمالي المصروفات المعروضة</div>
            <div class="fs-3 fw-bold text-primary">
                <?= number_format($totalExpenses, 2) ?>
            </div>
            <small class="text-secondary">
                العملة حسب إعدادات المدرسة
            </small>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">
                <?= $editingExpense ? 'تعديل مصروف' : 'إضافة مصروف جديد' ?>
            </h5>
        </div>

        <div class="card-body">
            <form method="post" action="expenses.php<?= $editingExpense ? '?edit=' . (int)$editingExpense['id'] : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= e($form['id']) ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">اسم المصروف *</label>
                        <input
                            type="text"
                            name="expense_name"
                            class="form-control"
                            maxlength="150"
                            required
                            value="<?= e($form['expense_name']) ?>"
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">التصنيف *</label>
                        <select name="category" class="form-select" required>
                            <?php foreach ($categories as $value => $label): ?>
                                <option value="<?= e($value) ?>"
                                    <?= $form['category'] === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">المبلغ *</label>
                        <input
                            type="number"
                            name="amount"
                            class="form-control"
                            min="0.01"
                            max="9999999999.99"
                            step="0.01"
                            required
                            value="<?= e($form['amount']) ?>"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">تاريخ المصروف *</label>
                        <input
                            type="date"
                            name="expense_date"
                            class="form-control"
                            required
                            value="<?= e($form['expense_date']) ?>"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">طريقة الدفع *</label>
                        <select name="payment_method" class="form-select" required>
                            <?php foreach ($paymentMethods as $value => $label): ?>
                                <option value="<?= e($value) ?>"
                                    <?= $form['payment_method'] === $value ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">الرقم المرجعي</label>
                        <input
                            type="text"
                            name="reference_number"
                            class="form-control"
                            maxlength="100"
                            value="<?= e($form['reference_number']) ?>"
                        >
                    </div>

                    <div class="col-12">
                        <label class="form-label">الوصف أو الملاحظات</label>
                        <textarea
                            name="description"
                            class="form-control"
                            rows="3"
                        ><?= e($form['description']) ?></textarea>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <?= $editingExpense ? 'حفظ التعديلات' : 'تسجيل المصروف' ?>
                        </button>

                        <?php if ($editingExpense): ?>
                            <a href="expenses.php" class="btn btn-secondary">
                                إلغاء التعديل
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">سجل المصروفات</h5>
        </div>

        <div class="card-body">
            <form method="get" class="row g-2 mb-4">
                <div class="col-md-6">
                    <input
                        type="search"
                        name="search"
                        class="form-control"
                        placeholder="ابحث باسم المصروف أو الرقم المرجعي"
                        value="<?= e($search) ?>"
                    >
                </div>

                <div class="col-md-4">
                    <select name="category" class="form-select">
                        <option value="">كل التصنيفات</option>
                        <?php foreach ($categories as $value => $label): ?>
                            <option value="<?= e($value) ?>"
                                <?= $selectedCategory === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 d-grid">
                    <button class="btn btn-outline-primary" type="submit">
                        بحث
                    </button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>اسم المصروف</th>
                            <th>التصنيف</th>
                            <th>المبلغ</th>
                            <th>التاريخ</th>
                            <th>طريقة الدفع</th>
                            <th>سجله</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$expenses): ?>
                        <tr>
                            <td colspan="8" class="text-center text-secondary py-4">
                                لا توجد مصروفات مسجلة حتى الآن.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?= (int)$expense['id'] ?></td>
                                <td>
                                    <div class="fw-semibold">
                                        <?= e($expense['expense_name']) ?>
                                    </div>
                                    <?php if ($expense['description']): ?>
                                        <small class="text-secondary">
                                            <?= e($expense['description']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($categories[$expense['category']] ?? 'أخرى') ?></td>
                                <td class="fw-bold">
                                    <?= number_format((float)$expense['amount'], 2) ?>
                                </td>
                                <td><?= e($expense['expense_date']) ?></td>
                                <td><?= e($paymentMethods[$expense['payment_method']] ?? 'أخرى') ?></td>
                                <td><?= e($expense['recorder_name'] ?? 'غير محدد') ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a
                                            href="expenses.php?edit=<?= (int)$expense['id'] ?>"
                                            class="btn btn-sm btn-outline-primary"
                                        >
                                            تعديل
                                        </a>

                                        <form
                                            method="post"
                                            action="expenses.php"
                                            onsubmit="return confirm('هل أنت متأكد من حذف هذا المصروف؟');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$expense['id'] ?>">

                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                حذف
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>

                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3">الإجمالي</th>
                            <th><?= number_format($totalExpenses, 2) ?></th>
                            <th colspan="4"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
