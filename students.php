
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

$classrooms = $pdo->query("
    SELECT c.id, c.classroom_name, g.grade_name, ay.year_name,
           c.grade_level_id, c.academic_year_id
    FROM classrooms c
    INNER JOIN grade_levels g ON g.id = c.grade_level_id
    INNER JOIN academic_years ay ON ay.id = c.academic_year_id
    WHERE c.is_active = 1
      AND g.is_active = 1
      AND ay.is_current = 1
      AND ay.status = 'active'
    ORDER BY g.grade_number, c.classroom_name
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $studentNumber = trim($_POST['student_number'] ?? '');
    $classroomId = filter_input(INPUT_POST, 'classroom_id', FILTER_VALIDATE_INT);
    $gender = $_POST['gender'] ?? '';
    $dateOfBirth = $_POST['date_of_birth'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $admissionDate = $_POST['admission_date'] ?? date('Y-m-d');

    if (
        $fullName === '' || $username === '' ||
        $password === '' || $studentNumber === '' ||
        !$classroomId ||
        !in_array($gender, ['male', 'female'], true)
    ) {
        $error = 'يرجى ملء الحقول المطلوبة بصورة صحيحة.';
    } elseif (strlen($password) < 8) {
        $error = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'البريد الإلكتروني غير صحيح.';
    } else {
        try {
            $pdo->beginTransaction();

            $classroomStmt = $pdo->prepare("
                SELECT c.grade_level_id, c.academic_year_id
                FROM classrooms c
                INNER JOIN grade_levels g ON g.id = c.grade_level_id
                INNER JOIN academic_years ay ON ay.id = c.academic_year_id
                WHERE c.id = :classroom_id
                  AND c.is_active = 1
                  AND g.is_active = 1
                  AND ay.is_current = 1
                  AND ay.status = 'active'
                LIMIT 1
            ");
            $classroomStmt->execute(['classroom_id' => $classroomId]);
            $classroom = $classroomStmt->fetch();

            if (!$classroom) {
                throw new RuntimeException(
                    'الفصل المحدد غير متاح في السنة الدراسية الحالية.'
                );
            }

            $roleStmt = $pdo->prepare(
                "SELECT id FROM roles WHERE role_name = 'student' LIMIT 1"
            );
            $roleStmt->execute();
            $roleId = $roleStmt->fetchColumn();

            if (!$roleId) {
                throw new RuntimeException('دور الطالب غير موجود في جدول roles.');
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

            $userId = (int) $pdo->lastInsertId();

            $studentStmt = $pdo->prepare(
                "INSERT INTO students
                (user_id, student_number, date_of_birth, gender,
                 admission_date, address)
                VALUES (?, ?, ?, ?, ?, ?)"
            );

            $studentStmt->execute([
                $userId,
                $studentNumber,
                $dateOfBirth !== '' ? $dateOfBirth : null,
                $gender,
                $admissionDate,
                $address !== '' ? $address : null
            ]);

            $studentId = (int) $pdo->lastInsertId();

            $enrollmentStmt = $pdo->prepare("
                INSERT INTO student_enrollments (
                    student_id,
                    academic_year_id,
                    grade_level_id,
                    classroom_id,
                    enrollment_date,
                    status
                ) VALUES (?, ?, ?, ?, ?, 'enrolled')
            ");
            $enrollmentStmt->execute([
                $studentId,
                (int) $classroom['academic_year_id'],
                (int) $classroom['grade_level_id'],
                (int) $classroomId,
                $admissionDate
            ]);

            $pdo->commit();
            $success = 'تمت إضافة الطالب بنجاح.';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof PDOException && $e->getCode() === '23000') {
                $error = 'اسم المستخدم أو رقم الطالب أو البريد الإلكتروني مستخدم مسبقاً.';
            } else {
                error_log($e->getMessage());
                $error = 'تعذرت إضافة الطالب. تحقق من الحقول وإعدادات قاعدة البيانات.';
            }
        }
    }
}

$students = $pdo->query(
    "SELECT s.id, s.student_number, s.gender, s.admission_date,
            u.full_name, u.username, u.phone,
            (
                SELECT c.classroom_name
                FROM student_enrollments se
                INNER JOIN academic_years ay
                    ON ay.id = se.academic_year_id
                INNER JOIN classrooms c
                    ON c.id = se.classroom_id
                WHERE se.student_id = s.id
                  AND se.status = 'enrolled'
                  AND ay.is_current = 1
                  AND ay.status = 'active'
                ORDER BY se.id DESC
                LIMIT 1
            ) AS classroom_name
     FROM students s
     INNER JOIN users u ON u.id = s.user_id
     ORDER BY s.id DESC"
)->fetchAll();

$pageTitle = 'إدارة الطلاب';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">إدارة الطلاب</h1>

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
        <h2 class="h5 mb-0">إضافة طالب جديد</h2>
    </div>

    <div class="card-body">
        <?php if (!$classrooms): ?>
            <div class="alert alert-warning">
                لا توجد فصول نشطة للسنة الدراسية الحالية. أضف فصلاً وفعّل السنة الدراسية الحالية أولاً.
            </div>
        <?php endif; ?>
        <form method="POST" action="students.php">
            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label">اسم الطالب الكامل *</label>
                    <input type="text" name="full_name" class="form-control"
                           maxlength="150" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">رقم الطالب *</label>
                    <input type="text" name="student_number" class="form-control"
                           maxlength="50" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">اسم المستخدم *</label>
                    <input type="text" name="username" class="form-control"
                           maxlength="100" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">كلمة المرور *</label>
                    <input type="password" name="password" class="form-control"
                           minlength="8" autocomplete="new-password" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">البريد الإلكتروني</label>
                    <input type="email" name="email" class="form-control"
                           maxlength="150">
                </div>

                <div class="col-md-6">
                    <label class="form-label">رقم الهاتف</label>
                    <input type="text" name="phone" class="form-control"
                           maxlength="30">
                </div>

                <div class="col-md-6">
                    <label class="form-label">الفصل الدراسي *</label>
                    <select name="classroom_id" class="form-select" required
                            <?= !$classrooms ? 'disabled' : '' ?>>
                        <option value="">اختر الفصل</option>
                        <?php foreach ($classrooms as $classroom): ?>
                            <option value="<?= (int) $classroom['id'] ?>"
                                <?= (int) ($_POST['classroom_id'] ?? 0) === (int) $classroom['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(
                                    $classroom['grade_name'] . ' - ' .
                                    $classroom['classroom_name'] . ' - ' .
                                    $classroom['year_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">الجنس *</label>
                    <select name="gender" class="form-select" required>
                        <option value="">اختر الجنس</option>
                        <option value="male">ذكر</option>
                        <option value="female">أنثى</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">تاريخ الميلاد</label>
                    <input type="date" name="date_of_birth" class="form-control">
                </div>

                <div class="col-md-4">
                    <label class="form-label">تاريخ القبول *</label>
                    <input type="date" name="admission_date"
                           class="form-control"
                           value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="col-12">
                    <label class="form-label">العنوان</label>
                    <textarea name="address" class="form-control"
                              rows="2"></textarea>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary"
                            <?= !$classrooms ? 'disabled' : '' ?>>
                        حفظ الطالب
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">الطلاب المسجلون</h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>رقم الطالب</th>
                    <th>اسم الطالب</th>
                    <th>اسم المستخدم</th>
                    <th>الجنس</th>
                    <th>الهاتف</th>
                    <th>الفصل</th>
                    <th>تاريخ القبول</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$students): ?>
                <tr>
                    <td colspan="7" class="text-center py-4 text-secondary">
                        لا يوجد طلاب مسجلون حتى الآن.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><?= htmlspecialchars($student['student_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($student['username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $student['gender'] === 'male' ? 'ذكر' : 'أنثى' ?></td>
                        <td><?= htmlspecialchars($student['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($student['classroom_name'] ?? 'غير مسجل', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($student['admission_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
