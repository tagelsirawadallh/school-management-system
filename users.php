
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$currentUserId = (int) $_SESSION['user_id'];
$currentRoleId = (int) $_SESSION['role_id'];

if (!in_array($currentRoleId, [1, 2], true)) {
    http_response_code(403);
    exit('ليس لديك صلاحية الوصول إلى إدارة المستخدمين.');
}

$isSystemAdmin = $currentRoleId === 1;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function userEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function userRedirect(): void
{
    header('Location: users.php');
    exit;
}

$error = '';
$message = $_SESSION['users_message'] ?? '';
unset($_SESSION['users_message']);

$allowedRoles = $isSystemAdmin
    ? [1, 2, 3, 4, 5, 6]
    : [3, 4, 5, 6];

$roleStmt = $pdo->query(
    'SELECT id, role_name, role_name_ar FROM roles ORDER BY id'
);
$allRoles = $roleStmt->fetchAll();

$roleNames = [];
foreach ($allRoles as $role) {
    $roleNames[(int) $role['id']] =
        $role['role_name_ar'] ?: $role['role_name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(403);
        exit('انتهت صلاحية الطلب. أعد تحميل الصفحة وحاول مرة أخرى.');
    }

    $action = $_POST['action'] ?? '';

    try {
        // إنشاء الحساب والملف المرتبط به
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';
            $roleId = filter_var(
                $_POST['role_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                $username === '' ||
                strlen($username) > 100 ||
                !preg_match('/^[A-Za-z0-9_.-]+$/', $username)
            ) {
                throw new RuntimeException(
                    'اسم المستخدم مطلوب، ويُسمح بالحروف الإنجليزية والأرقام والنقطة والشرطة.'
                );
            }

            if (
                $fullName === '' ||
                (function_exists('mb_strlen')
                    ? mb_strlen($fullName, 'UTF-8')
                    : strlen($fullName)) > 150
            ) {
                throw new RuntimeException('يرجى إدخال الاسم الكامل.');
            }

            if (
                $email !== '' &&
                (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
                    strlen($email) > 150)
            ) {
                throw new RuntimeException('البريد الإلكتروني غير صحيح.');
            }

            if (strlen($phone) > 30) {
                throw new RuntimeException('رقم الهاتف طويل جداً.');
            }

            if (!is_string($password) || strlen($password) < 8) {
                throw new RuntimeException(
                    'كلمة المرور يجب ألا تقل عن 8 أحرف.'
                );
            }

            if (strlen($password) > 4096) {
                throw new RuntimeException('كلمة المرور طويلة جداً.');
            }

            if (!$roleId || !in_array($roleId, $allowedRoles, true)) {
                throw new RuntimeException(
                    'ليس لديك صلاحية إنشاء الحساب بهذا الدور.'
                );
            }

            $email = $email === '' ? null : $email;
            $phone = $phone === '' ? null : $phone;

            $stmt = $pdo->prepare(
                'SELECT id FROM roles WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $roleId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException('الدور المحدد غير موجود.');
            }

            // بيانات الملف المرتبط
            $studentNumber = trim($_POST['student_number'] ?? '');
            $admissionDate = trim($_POST['admission_date'] ?? '');
            $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
            $gender = $_POST['gender'] ?? '';

            $employeeNumber = trim($_POST['employee_number'] ?? '');
            $specialization = trim($_POST['specialization'] ?? '');
            $qualification = trim($_POST['qualification'] ?? '');
            $hireDate = trim($_POST['hire_date'] ?? '');
            $salaryInput = trim($_POST['salary'] ?? '0');
            $address = trim($_POST['profile_address'] ?? '');

            $nationalId = trim($_POST['national_id'] ?? '');
            $occupation = trim($_POST['occupation'] ?? '');
            $workplace = trim($_POST['workplace'] ?? '');

            // التحقق من الحقول المطلوبة حسب نوع الحساب
            if ($roleId === 5) {
                if (
                    $studentNumber === '' ||
                    strlen($studentNumber) > 50
                ) {
                    throw new RuntimeException(
                        'أدخل رقم الطالب، بحد أقصى 50 حرفاً.'
                    );
                }

                if (
                    $admissionDate === '' ||
                    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $admissionDate) ||
                    !checkdate(
                        (int) substr($admissionDate, 5, 2),
                        (int) substr($admissionDate, 8, 2),
                        (int) substr($admissionDate, 0, 4)
                    )
                ) {
                    throw new RuntimeException(
                        'أدخل تاريخ قبول صحيحاً للطالب.'
                    );
                }

                if (
                    $dateOfBirth !== '' &&
                    (
                        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth) ||
                        !checkdate(
                            (int) substr($dateOfBirth, 5, 2),
                            (int) substr($dateOfBirth, 8, 2),
                            (int) substr($dateOfBirth, 0, 4)
                        )
                    )
                ) {
                    throw new RuntimeException('تاريخ ميلاد الطالب غير صحيح.');
                }

                if (!in_array($gender, ['', 'male', 'female'], true)) {
                    throw new RuntimeException('نوع الجنس غير صحيح.');
                }
            }

            if ($roleId === 4) {
                if (
                    $employeeNumber === '' ||
                    strlen($employeeNumber) > 50
                ) {
                    throw new RuntimeException(
                        'أدخل الرقم الوظيفي للمعلم، بحد أقصى 50 حرفاً.'
                    );
                }

                if (
                    !is_numeric($salaryInput) ||
                    (float) $salaryInput < 0 ||
                    (float) $salaryInput > 9999999999.99
                ) {
                    throw new RuntimeException('الراتب غير صحيح.');
                }

                if (
                    strlen($specialization) > 100 ||
                    strlen($qualification) > 150
                ) {
                    throw new RuntimeException(
                        'التخصص أو المؤهل تجاوز الطول المسموح.'
                    );
                }

                if (
                    $hireDate !== '' &&
                    (
                        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireDate) ||
                        !checkdate(
                            (int) substr($hireDate, 5, 2),
                            (int) substr($hireDate, 8, 2),
                            (int) substr($hireDate, 0, 4)
                        )
                    )
                ) {
                    throw new RuntimeException('تاريخ التعيين غير صحيح.');
                }
            }

            if ($roleId === 6) {
                if (strlen($nationalId) > 50) {
                    throw new RuntimeException(
                        'رقم الهوية طويل جداً.'
                    );
                }

                if (
                    strlen($occupation) > 100 ||
                    strlen($workplace) > 150
                ) {
                    throw new RuntimeException(
                        'المهنة أو جهة العمل تجاوزت الطول المسموح.'
                    );
                }
            }

            if (strlen($address) > 255) {
                throw new RuntimeException('العنوان طويل جداً.');
            }

            // تنفيذ إنشاء الحساب والملف في معاملة واحدة
            $pdo->beginTransaction();

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO users
                    (username, email, password, role_id, full_name, phone, is_active)
                 VALUES
                    (:username, :email, :password, :role_id, :full_name, :phone, 1)'
            );

            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'password' => $passwordHash,
                'role_id' => $roleId,
                'full_name' => $fullName,
                'phone' => $phone,
            ]);

            $newUserId = (int) $pdo->lastInsertId();

            // ملف الطالب
            if ($roleId === 5) {
                $stmt = $pdo->prepare(
                    'INSERT INTO students
                        (user_id, student_number, date_of_birth, gender,
                         admission_date, address, is_active)
                     VALUES
                        (:user_id, :student_number, :date_of_birth, :gender,
                         :admission_date, :address, 1)'
                );

                $stmt->execute([
                    'user_id' => $newUserId,
                    'student_number' => $studentNumber,
                    'date_of_birth' => $dateOfBirth === '' ? null : $dateOfBirth,
                    'gender' => $gender === '' ? null : $gender,
                    'admission_date' => $admissionDate,
                    'address' => $address === '' ? null : $address,
                ]);
            }

            // ملف المعلم
            if ($roleId === 4) {
                $stmt = $pdo->prepare(
                    'INSERT INTO teachers
                        (user_id, employee_number, specialization,
                         qualification, hire_date, salary, address, is_active)
                     VALUES
                        (:user_id, :employee_number, :specialization,
                         :qualification, :hire_date, :salary, :address, 1)'
                );

                $stmt->execute([
                    'user_id' => $newUserId,
                    'employee_number' => $employeeNumber,
                    'specialization' => $specialization === '' ? null : $specialization,
                    'qualification' => $qualification === '' ? null : $qualification,
                    'hire_date' => $hireDate === '' ? null : $hireDate,
                    'salary' => $salaryInput,
                    'address' => $address === '' ? null : $address,
                ]);
            }

            // ملف ولي الأمر
            if ($roleId === 6) {
                $stmt = $pdo->prepare(
                    'INSERT INTO guardians
                        (user_id, national_id, occupation, workplace, address)
                     VALUES
                        (:user_id, :national_id, :occupation, :workplace, :address)'
                );

                $stmt->execute([
                    'user_id' => $newUserId,
                    'national_id' => $nationalId === '' ? null : $nationalId,
                    'occupation' => $occupation === '' ? null : $occupation,
                    'workplace' => $workplace === '' ? null : $workplace,
                    'address' => $address === '' ? null : $address,
                ]);
            }

            $pdo->commit();

            $_SESSION['users_message'] =
                'تم إنشاء حساب ' .
                ($roleNames[$roleId] ?? 'المستخدم') .
                ' وربطه بملفه بنجاح.';

            userRedirect();
        }

        // تفعيل أو تعطيل الحساب
        if ($action === 'toggle') {
            $targetId = filter_var(
                $_POST['user_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$targetId || $targetId < 1) {
                throw new RuntimeException('رقم الحساب غير صحيح.');
            }

            if ($targetId === $currentUserId) {
                throw new RuntimeException(
                    'لا يمكنك تعطيل حسابك الحالي من هذه الشاشة.'
                );
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT id, role_id, is_active
                 FROM users WHERE id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $targetId]);
            $target = $stmt->fetch();

            if (!$target) {
                throw new RuntimeException('الحساب غير موجود.');
            }

            $targetRoleId = (int) $target['role_id'];

            if (
                !$isSystemAdmin &&
                !in_array($targetRoleId, [3, 4, 5, 6], true)
            ) {
                throw new RuntimeException(
                    'لا يمكنك تغيير حالة هذا الحساب.'
                );
            }

            $newStatus = (int) $target['is_active'] === 1 ? 0 : 1;

            $stmt = $pdo->prepare(
                'UPDATE users SET is_active = :status WHERE id = :id'
            );
            $stmt->execute([
                'status' => $newStatus,
                'id' => $targetId,
            ]);

            $pdo->commit();

            $_SESSION['users_message'] = $newStatus
                ? 'تم تفعيل الحساب.'
                : 'تم تعطيل الحساب.';

            userRedirect();
        }

        throw new RuntimeException('العملية المطلوبة غير صحيحة.');

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

        if ($ex->getCode() === '23000') {
            $error =
                'اسم المستخدم أو البريد أو رقم الطالب أو الرقم الوظيفي أو الهوية مستخدم بالفعل.';
        } else {
            $error = 'تعذر تنفيذ العملية. راجع سجل الأخطاء وحاول مرة أخرى.';
        }
    }
}

// جلب الحسابات المسجلة
$search = trim($_GET['search'] ?? '');

$sql = '
    SELECT u.id, u.username, u.email, u.full_name, u.phone,
           u.role_id, u.is_active, u.last_login, u.created_at,
           r.role_name_ar, r.role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE 1 = 1
';

$params = [];

if ($search !== '') {
    $sql .= ' AND (
        u.username LIKE :username
        OR u.full_name LIKE :full_name
        OR u.email LIKE :email
    )';

    $term = '%' . $search . '%';
    $params = [
        'username' => $term,
        'full_name' => $term,
        'email' => $term,
    ];
}

$sql .= ' ORDER BY u.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" dir="rtl">

    <div class="mb-4">
        <h2 class="fw-bold">إدارة المستخدمين</h2>
        <p class="text-secondary mb-0">
            إنشاء الحسابات وربطها بملفات الطلاب والمعلمين وأولياء الأمور
        </p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success">
            <?= userEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= userEscape($error) ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">إنشاء حساب جديد</h5>
        </div>

        <div class="card-body">
            <form method="post" action="users.php">
                <input type="hidden" name="csrf_token"
                       value="<?= userEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="create">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">الاسم الكامل *</label>
                        <input type="text" name="full_name"
                               class="form-control" maxlength="150"
                               required value="<?= userEscape($_POST['full_name'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">اسم المستخدم *</label>
                        <input type="text" name="username"
                               class="form-control" maxlength="100"
                               pattern="[A-Za-z0-9_.-]+"
                               autocomplete="username" required
                               value="<?= userEscape($_POST['username'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">البريد الإلكتروني</label>
                        <input type="email" name="email"
                               class="form-control" maxlength="150"
                               value="<?= userEscape($_POST['email'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone"
                               class="form-control" maxlength="30"
                               value="<?= userEscape($_POST['phone'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">كلمة المرور *</label>
                        <input type="password" name="password"
                               class="form-control" minlength="8"
                               maxlength="4096"
                               autocomplete="new-password" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">نوع الحساب *</label>
                        <select name="role_id" id="role_id"
                                class="form-select" required>
                            <option value="">اختر نوع الحساب</option>
                            <?php foreach ($allRoles as $role): ?>
                                <?php
                                $rid = (int) $role['id'];
                                if (!in_array($rid, $allowedRoles, true)) {
                                    continue;
                                }
                                ?>
                                <option value="<?= $rid ?>"
                                    <?= (string)($_POST['role_id'] ?? '') === (string)$rid ? 'selected' : '' ?>>
                                    <?= userEscape($role['role_name_ar'] ?: $role['role_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="studentFields" class="row g-3 mt-1 d-none">
                        <div class="col-12">
                            <h6>بيانات الطالب</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">رقم الطالب *</label>
                            <input name="student_number" class="form-control"
                                   maxlength="50"
                                   value="<?= userEscape($_POST['student_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">تاريخ القبول *</label>
                            <input type="date" name="admission_date"
                                   class="form-control"
                                   value="<?= userEscape($_POST['admission_date'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">تاريخ الميلاد</label>
                            <input type="date" name="date_of_birth"
                                   class="form-control"
                                   value="<?= userEscape($_POST['date_of_birth'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الجنس</label>
                            <select name="gender" class="form-select">
                                <option value="">غير محدد</option>
                                <option value="male" <?= ($_POST['gender'] ?? '') === 'male' ? 'selected' : '' ?>>ذكر</option>
                                <option value="female" <?= ($_POST['gender'] ?? '') === 'female' ? 'selected' : '' ?>>أنثى</option>
                            </select>
                        </div>
                    </div>

                    <div id="teacherFields" class="row g-3 mt-1 d-none">
                        <div class="col-12">
                            <h6>بيانات المعلم</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الرقم الوظيفي *</label>
                            <input name="employee_number" class="form-control"
                                   maxlength="50"
                                   value="<?= userEscape($_POST['employee_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">التخصص</label>
                            <input name="specialization" class="form-control"
                                   maxlength="100"
                                   value="<?= userEscape($_POST['specialization'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">المؤهل</label>
                            <input name="qualification" class="form-control"
                                   maxlength="150"
                                   value="<?= userEscape($_POST['qualification'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">تاريخ التعيين</label>
                            <input type="date" name="hire_date" class="form-control"
                                   value="<?= userEscape($_POST['hire_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الراتب</label>
                            <input type="number" name="salary" class="form-control"
                                   min="0" step="0.01"
                                   value="<?= userEscape($_POST['salary'] ?? '0') ?>">
                        </div>
                    </div>

                    <div id="guardianFields" class="row g-3 mt-1 d-none">
                        <div class="col-12">
                            <h6>بيانات ولي الأمر</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">رقم الهوية</label>
                            <input name="national_id" class="form-control"
                                   maxlength="50"
                                   value="<?= userEscape($_POST['national_id'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">المهنة</label>
                            <input name="occupation" class="form-control"
                                   maxlength="100"
                                   value="<?= userEscape($_POST['occupation'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">جهة العمل</label>
                            <input name="workplace" class="form-control"
                                   maxlength="150"
                                   value="<?= userEscape($_POST['workplace'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">العنوان</label>
                        <input name="profile_address" class="form-control"
                               maxlength="255"
                               value="<?= userEscape($_POST['profile_address'] ?? '') ?>">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            إنشاء الحساب وربط الملف
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">الحسابات المسجلة</h5>
        </div>

        <div class="card-body">
            <form method="get" action="users.php" class="row g-2 mb-4">
                <div class="col-md-9">
                    <input type="search" name="search" class="form-control"
                           placeholder="ابحث بالاسم أو اسم المستخدم أو البريد"
                           value="<?= userEscape($search) ?>">
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
                            <th>الاسم</th>
                            <th>اسم المستخدم</th>
                            <th>الدور</th>
                            <th>الحالة</th>
                            <th>آخر دخول</th>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr>
                            <td colspan="7" class="text-center text-secondary py-4">
                                لا توجد حسابات مطابقة.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $account): ?>
                            <?php $accountRoleId = (int)$account['role_id']; ?>
                            <tr>
                                <td><?= (int)$account['id'] ?></td>
                                <td>
                                    <div class="fw-semibold">
                                        <?= userEscape($account['full_name']) ?>
                                    </div>
                                    <small class="text-secondary">
                                        <?= userEscape($account['email'] ?? '') ?>
                                    </small>
                                </td>
                                <td><?= userEscape($account['username']) ?></td>
                                <td>
                                    <?= userEscape($account['role_name_ar'] ?: $account['role_name']) ?>
                                </td>
                                <td>
                                    <?php if ((int)$account['is_active'] === 1): ?>
                                        <span class="badge bg-success">نشط</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">معطّل</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= userEscape($account['last_login'] ?? 'لم يسجل الدخول') ?></td>
                                <td>
                                    <?php
                                    $canToggle = (int)$account['id'] !== $currentUserId
                                        && (
                                            $isSystemAdmin ||
                                            in_array($accountRoleId, [3, 4, 5, 6], true)
                                        );
                                    ?>
                                    <?php if ($canToggle): ?>
                                        <form method="post" action="users.php"
                                              onsubmit="return confirm('هل تريد تغيير حالة هذا الحساب؟');">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= userEscape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="user_id"
                                                   value="<?= (int)$account['id'] ?>">
                                            <button type="submit"
                                                class="btn btn-sm <?= (int)$account['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                                <?= (int)$account['is_active'] === 1 ? 'تعطيل' : 'تفعيل' ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif; ?>
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

<script>
(function () {
    const roleSelect = document.getElementById('role_id');
    const studentFields = document.getElementById('studentFields');
    const teacherFields = document.getElementById('teacherFields');
    const guardianFields = document.getElementById('guardianFields');

    function updateProfileFields() {
        const role = roleSelect.value;

        studentFields.classList.toggle('d-none', role !== '5');
        teacherFields.classList.toggle('d-none', role !== '4');
        guardianFields.classList.toggle('d-none', role !== '6');
    }

    roleSelect.addEventListener('change', updateProfileFields);
    updateProfileFields();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
