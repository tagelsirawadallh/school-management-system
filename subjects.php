
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subjectName = trim($_POST['subject_name'] ?? '');
    $subjectCode = trim($_POST['subject_code'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($subjectName === '' || $subjectCode === '') {
        $error = 'اسم المادة ورمز المادة مطلوبان.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO subjects
                (subject_name, subject_code, description)
                VALUES (?, ?, ?)"
            );

            $stmt->execute([
                $subjectName,
                $subjectCode,
                $description !== '' ? $description : null
            ]);

            $success = 'تمت إضافة المادة الدراسية بنجاح.';
        } catch (PDOException $e) {
            error_log($e->getMessage());

            if ($e->getCode() === '23000') {
                $error = 'رمز المادة مستخدم مسبقاً. اختر رمزاً آخر.';
            } else {
                $error = 'حدث خطأ أثناء حفظ المادة. حاول مرة أخرى.';
            }
        }
    }
}

$stmt = $pdo->query(
    "SELECT id, subject_name, subject_code, description, is_active
     FROM subjects
     ORDER BY subject_name ASC"
);

$subjects = $stmt->fetchAll();

$pageTitle = 'إدارة المواد الدراسية';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">إدارة المواد الدراسية</h1>

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
        <h2 class="h5 mb-0">إضافة مادة جديدة</h2>
    </div>

    <div class="card-body">
        <form method="POST" action="subjects.php">
            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label">اسم المادة *</label>
                    <input
                        type="text"
                        name="subject_name"
                        class="form-control"
                        maxlength="100"
                        placeholder="مثلاً: الرياضيات"
                        required
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label">رمز المادة *</label>
                    <input
                        type="text"
                        name="subject_code"
                        class="form-control"
                        maxlength="30"
                        placeholder="مثلاً: MATH101"
                        required
                    >
                </div>

                <div class="col-12">
                    <label class="form-label">وصف المادة</label>
                    <textarea
                        name="description"
                        class="form-control"
                        maxlength="255"
                        rows="3"
                        placeholder="اكتب وصفاً مختصراً للمادة"
                    ></textarea>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        حفظ المادة
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">
            المواد المسجلة (<?= count($subjects) ?>)
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>رمز المادة</th>
                    <th>اسم المادة</th>
                    <th>الوصف</th>
                    <th>الحالة</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$subjects): ?>
                    <tr>
                        <td colspan="4"
                            class="text-center py-4 text-secondary">
                            لا توجد مواد مسجلة حتى الآن.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subjects as $subject): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(
                                    $subject['subject_code'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $subject['subject_name'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $subject['description'] ?? '-',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?php if ((int)$subject['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">
                                        نشطة
                                    </span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">
                                        غير نشطة
                                    </span>
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
