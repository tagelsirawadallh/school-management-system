
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $employeeNumber = trim($_POST['employee_number'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $hireDate = $_POST['hire_date'] ?? '';
    $salaryInput = trim($_POST['salary'] ?? '0');
    $address = trim($_POST['address'] ?? '');

    if (
        $fullName === '' || $username === '' ||
        $password === '' || $employeeNumber === ''
    ) {
        $error = 'يرجى ملء جميع الحقول المطلوبة.';
    } elseif (strlen($password) < 8) {
        $error = 'كلمة المرور يجب ألا تقل عن 8 أحرف.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'البريد الإلكتروني غير صحيح.';
    } elseif (!is_numeric($salaryInput) || (float)$salaryInput < 0) {
        $error = 'الراتب يجب أن يكون رقماً موجباً أو صفراً.';
    } else {
        try {
            $pdo->beginTransaction();

            $roleStmt = $pdo->prepare(
                "SELECT id FROM roles WHERE role_name = 'teacher' LIMIT 1"
            );
            $roleStmt->execute();
            $roleId = $roleStmt->fetchColumn();

            if (!$roleId) {
                throw new RuntimeException('دور المعلم غير موجود في جدول roles.');
            }

            $userStmt = $pdo->prepare(
                "INSERT INTO users
                (username, email, password, role_id, full_name, phone)
                VALUES (?, ?, ?, ?, ?, ?)"
            );

            $userStmt->execute([
                $username,
                $email !== '' ? $email : null,
                password_hash($password, PASSWORD_DEFAULT),
                $roleId,
                $fullName,
                $phone !== '' ? $phone : null
            ]);

            $userId = (int)$pdo->lastInsertId();

            $teacherStmt = $pdo->prepare(
                "INSERT INTO teachers
                (user_id, employee_number, specialization, qualification,
                 hire_date, salary, address)
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $teacherStmt->execute([
                $userId,
                $employeeNumber,
                $specialization !== '' ? $specialization : null,
                $qualification !== '' ? $qualification : null,
                $hireDate !== '' ? $hireDate : null,
                (float)$salaryInput,
                $address !== '' ? $address : null
            ]);

            $pdo->commit();
            $success = 'تمت إضافة المعلم بنجاح.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof PDOException && $e->getCode() === '23000') {
                $error = 'اسم المستخدم أو البريد الإلكتروني أو رقم الموظف مستخدم مسبقاً.';
            } else {
                error_log($e->getMessage());
                $error = 'تعذرت إضافة المعلم. تحقق من البيانات وإعدادات قاعدة البيانات.';
            }
        }
    }
}

$stmt = $pdo->query(
    "SELECT t.id, t.employee_number, t.specialization,
            t.qualification, t.hire_date, t.salary, t.is_active,
            u.full_name, u.username, u.phone
     FROM teachers t
     INNER JOIN users u ON u.id = t.user_id
     ORDER BY t.id DESC"
);
$teachers = $stmt->fetchAll();

$pageTitle = 'إدارة المعلمين';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">إدارة المعلمين</h1>

<?php if ($success !== ''): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">إضافة معلم جديد</h2>
    </div>

    <div class="card-body">
        <form method="POST" action="teachers.php">
            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label">اسم المعلم الكامل *</label>
                    <input type="text" name="full_name"
                           class="form-control" maxlength="150" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">رقم الموظف *</label>
                    <input type="text" name="employee_number"
                           class="form-control" maxlength="50" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">اسم المستخدم *</label>
                    <input type="text" name="username"
                           class="form-control" maxlength="100" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">كلمة المرور *</label>
                    <input type="password" name="password"
                           class="form-control" minlength="8"
                           autocomplete="new-password" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email"
                           class="form-control" maxlength="150">
                </div>

                <div class="col-md-6">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" name="phone"
                           class="form-control" maxlength="30">
                </div>

                <div class="col-md-6">
                    <label class="form-label">التخصص</label>
                    <input type="text" name="specialization"
                           class="form-control" maxlength="100"
                           placeholder="مثلاً: الرياضيات">
                </div>

                <div class="col-md-6">
                    <label class="form-label">المؤهل العلمي</label>
                    <input type="text" name="qualification"
                           class="form-control" maxlength="150"
                           placeholder="مثلاً: بكالوريوس">
                </div>

                <div class="col-md-6">
                    <label class="form-label">تاريخ التعيين</label>
                    <input type="date" name="hire_date"
                           class="form-control">
                </div>

                <div class="col-md-6">
                    <label class="form-label">الراتب الشهري</label>
                    <input type="number" name="salary"
                           class="form-control" min="0" step="0.01"
                           value="0">
                    <small class="text-secondary">أدخل الراتب بالعملة المستخدمة في المدرسة.</small>
                </div>

                <div class="col-12">
                    <label class="form-label">العنوان</label>
                    <textarea name="address" class="form-control"
                              rows="2" maxlength="255"></textarea>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <span>حفظ المعلم</span>
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">قائمة المعلمين</h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>رقم الموظف</th>
                    <th>اسم المعلم</th>
                    <th>التخصص</th>
                    <th>المؤهل</th>
                    <th>الهاتف</th>
                    <th>الراتب</th>
                    <th>الحالة</th>
                </tr>
            </thead>

            <tbody>
            <?php if (!$teachers): ?>
                <tr>
                    <td colspan="7" class="text-center py-4 text-secondary">
                        لا يوجد معلمون مسجلون حتى الآن.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($teachers as $teacher): ?>
                    <tr>
                        <td><?= htmlspecialchars($teacher['employee_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($teacher['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($teacher['specialization'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($teacher['qualification'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($teacher['phone'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format((float)$teacher['salary'], 2) ?></td>
                        <td>
                            <?php if ((int)$teacher['is_active'] === 1): ?>
                                <span class="badge text-bg-success">نشط</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">غير نشط</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
