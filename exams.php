
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $examName = trim($_POST['exam_name'] ?? '');
    $subjectId = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
    $gradeLevelId = filter_input(INPUT_POST, 'grade_level_id', FILTER_VALIDATE_INT);
    $academicYearId = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
    $examType = $_POST['exam_type'] ?? 'other';
    $examDate = $_POST['exam_date'] ?? '';
    $maxScore = $_POST['max_score'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    $allowedTypes = ['monthly', 'midterm', 'final', 'practical', 'other'];
    $validDate = DateTime::createFromFormat('!Y-m-d', $examDate);

    if (
        $examName === '' ||
        !$subjectId || !$gradeLevelId || !$academicYearId ||
        !$validDate || $validDate->format('Y-m-d') !== $examDate ||
        !in_array($examType, $allowedTypes, true) ||
        !is_numeric($maxScore) || (float)$maxScore <= 0
    ) {
        $error = 'يرجى التأكد من إدخال جميع البيانات بصورة صحيحة.';
    } else {
        try {
            $check = $pdo->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM subjects
                     WHERE id = ? AND is_active = 1) AS subject_exists,
                    (SELECT COUNT(*) FROM grade_levels
                     WHERE id = ? AND is_active = 1) AS grade_exists,
                    (SELECT COUNT(*) FROM academic_years
                     WHERE id = ?) AS year_exists"
            );

            $check->execute([
                $subjectId,
                $gradeLevelId,
                $academicYearId
            ]);

            $valid = $check->fetch();

            if (
                !$valid['subject_exists'] ||
                !$valid['grade_exists'] ||
                !$valid['year_exists']
            ) {
                $error = 'المادة أو الصف أو السنة الأكاديمية غير صحيحة.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO exams
                    (exam_name, subject_id, grade_level_id,
                     academic_year_id, exam_type, exam_date,
                     max_score, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $examName,
                    $subjectId,
                    $gradeLevelId,
                    $academicYearId,
                    $examType,
                    $examDate,
                    $maxScore,
                    $notes !== '' ? $notes : null
                ]);

                $success = 'تمت إضافة الامتحان بنجاح.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'تعذرت إضافة الامتحان. تحقق من بنية الجدول والبيانات.';
        }
    }
}

$subjects = $pdo->query(
    "SELECT id, subject_name
     FROM subjects
     WHERE is_active = 1
     ORDER BY subject_name"
)->fetchAll();

$grades = $pdo->query(
    "SELECT id, grade_name
     FROM grade_levels
     WHERE is_active = 1
     ORDER BY grade_number"
)->fetchAll();

$years = $pdo->query(
    "SELECT id, year_name
     FROM academic_years
     ORDER BY id DESC"
)->fetchAll();

$exams = $pdo->query(
    "SELECT e.id, e.exam_name, e.exam_type, e.exam_date,
            e.max_score, s.subject_name, g.grade_name, a.year_name
     FROM exams e
     INNER JOIN subjects s ON s.id = e.subject_id
     INNER JOIN grade_levels g ON g.id = e.grade_level_id
     INNER JOIN academic_years a ON a.id = e.academic_year_id
     ORDER BY e.exam_date DESC, e.id DESC"
)->fetchAll();

$typeLabels = [
    'monthly' => 'شهري',
    'midterm' => 'منتصف الفصل',
    'final' => 'نهائي',
    'practical' => 'عملي',
    'other' => 'أخرى'
];

$pageTitle = 'إدارة الامتحانات';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">إدارة الامتحانات</h1>

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
        <h2 class="h5 mb-0">إضافة امتحان جديد</h2>
    </div>

    <div class="card-body">
        <?php if (!$subjects || !$grades || !$years): ?>
            <div class="alert alert-warning mb-0">
                يجب وجود مادة دراسية وصف دراسي نشط وسنة أكاديمية أولاً.
            </div>
        <?php else: ?>
            <form method="POST" action="exams.php">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">اسم الامتحان *</label>
                        <input type="text" name="exam_name"
                               class="form-control" maxlength="150"
                               placeholder="مثلاً: امتحان الرياضيات الأول"
                               required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">المادة الدراسية *</label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">اختر المادة</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= (int)$subject['id'] ?>">
                                    <?= htmlspecialchars($subject['subject_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">الصف الدراسي *</label>
                        <select name="grade_level_id" class="form-select" required>
                            <option value="">اختر الصف</option>
                            <?php foreach ($grades as $grade): ?>
                                <option value="<?= (int)$grade['id'] ?>">
                                    <?= htmlspecialchars($grade['grade_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">السنة الأكاديمية *</label>
                        <select name="academic_year_id" class="form-select" required>
                            <option value="">اختر السنة</option>
                            <?php foreach ($years as $year): ?>
                                <option value="<?= (int)$year['id'] ?>">
                                    <?= htmlspecialchars($year['year_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">نوع الامتحان *</label>
                        <select name="exam_type" class="form-select" required>
                            <option value="monthly">شهري</option>
                            <option value="midterm">منتصف الفصل</option>
                            <option value="final">نهائي</option>
                            <option value="practical">عملي</option>
                            <option value="other">أخرى</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">تاريخ الامتحان *</label>
                        <input type="date" name="exam_date"
                               class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">الدرجة الكاملة *</label>
                        <input type="number" name="max_score"
                               class="form-control" min="0.01"
                               max="999999.99" step="0.01"
                               value="100" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">ملاحظات</label>
                        <textarea name="notes" class="form-control"
                                  rows="2" placeholder="ملاحظات اختيارية"></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            حفظ الامتحان
                        </button>
                    </div>

                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">
            الامتحانات المسجلة (<?= count($exams) ?>)
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>اسم الامتحان</th>
                    <th>المادة</th>
                    <th>الصف</th>
                    <th>السنة الأكاديمية</th>
                    <th>النوع</th>
                    <th>التاريخ</th>
                    <th>الدرجة الكاملة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$exams): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-secondary">
                            لا توجد امتحانات مسجلة حتى الآن.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($exams as $exam): ?>
                        <tr>
                            <td><?= htmlspecialchars($exam['exam_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($exam['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($exam['grade_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($exam['year_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $typeLabels[$exam['exam_type']] ?? 'أخرى' ?></td>
                            <td><?= htmlspecialchars($exam['exam_date'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$exam['max_score'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
