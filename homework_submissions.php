
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $homeworkId = filter_input(INPUT_POST, 'homework_id', FILTER_VALIDATE_INT);
    $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
    $submissionStatus = $_POST['submission_status'] ?? '';
    $scoreInput = trim($_POST['score'] ?? '');
    $feedback = trim($_POST['feedback'] ?? '');

    $allowedStatuses = ['submitted', 'late', 'missing'];

    if (
        !$homeworkId ||
        !$studentId ||
        !in_array($submissionStatus, $allowedStatuses, true) ||
        ($scoreInput !== '' && (!is_numeric($scoreInput) || (float)$scoreInput < 0))
    ) {
        $error = 'يرجى إدخال بيانات صحيحة.';
    } else {
        try {
            $check = $pdo->prepare(
                "SELECT id FROM homework WHERE id = ?"
            );
            $check->execute([$homeworkId]);
            $homeworkExists = $check->fetch();

            $check = $pdo->prepare(
                "SELECT id FROM students WHERE id = ?"
            );
            $check->execute([$studentId]);
            $studentExists = $check->fetch();

            if (!$homeworkExists || !$studentExists) {
                $error = 'الطالب أو الواجب غير موجود.';
            } else {
                $check = $pdo->prepare(
                    "SELECT id FROM homework_submissions
                     WHERE homework_id = ? AND student_id = ?"
                );
                $check->execute([$homeworkId, $studentId]);

                if ($check->fetch()) {
                    $error = 'توجد متابعة مسجلة لهذا الطالب والواجب مسبقاً.';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO homework_submissions
                        (homework_id, student_id, submission_status, score, feedback)
                        VALUES (?, ?, ?, ?, ?)"
                    );

                    $stmt->execute([
                        $homeworkId,
                        $studentId,
                        $submissionStatus,
                        $scoreInput !== '' ? $scoreInput : null,
                        $feedback !== '' ? $feedback : null
                    ]);

                    $success = 'تم تسجيل متابعة الواجب بنجاح.';
                }
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'تعذر الحفظ. قد تختلف أعمدة جدول تسليم الواجبات عن الكود.';
        }
    }
}

$homeworks = $pdo->query(
    "SELECT id, title FROM homework ORDER BY id DESC"
)->fetchAll();

$students = $pdo->query(
    "SELECT id, student_number FROM students ORDER BY id DESC"
)->fetchAll();

$submissions = $pdo->query(
    "SELECT hs.*, h.title, s.student_number
     FROM homework_submissions hs
     INNER JOIN homework h ON h.id = hs.homework_id
     INNER JOIN students s ON s.id = hs.student_id
     ORDER BY hs.id DESC"
)->fetchAll();

$statusLabels = [
    'submitted' => 'تم التسليم',
    'late' => 'تسليم متأخر',
    'missing' => 'لم يُسلّم'
];

$pageTitle = 'متابعة تسليم الواجبات';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">متابعة تسليم الواجبات</h1>

<?php if ($success): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">تسجيل حالة الواجب</h2>
    </div>

    <div class="card-body">
        <?php if (!$homeworks || !$students): ?>
            <div class="alert alert-warning mb-0">
                يجب تسجيل واجب وطالب أولاً.
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">الواجب *</label>
                        <select name="homework_id" class="form-select" required>
                            <option value="">اختر الواجب</option>
                            <?php foreach ($homeworks as $homework): ?>
                                <option value="<?= (int)$homework['id'] ?>">
                                    <?= htmlspecialchars($homework['title'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">الطالب *</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">اختر الطالب</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int)$student['id'] ?>">
                                    <?= htmlspecialchars(
                                        $student['student_number'] ?? ('طالب رقم ' . $student['id']),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">حالة التسليم *</label>
                        <select name="submission_status" class="form-select" required>
                            <option value="submitted">تم التسليم</option>
                            <option value="late">تسليم متأخر</option>
                            <option value="missing">لم يُسلّم</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">الدرجة (اختياري)</label>
                        <input type="number" name="score" class="form-control"
                               min="0" step="0.01">
                    </div>

                    <div class="col-12">
                        <label class="form-label">ملاحظات المعلم</label>
                        <textarea name="feedback" class="form-control"
                                  rows="3" maxlength="2000"></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            حفظ المتابعة
                        </button>
                    </div>

                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">سجل التسليمات (<?= count($submissions) ?>)</h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>الطالب</th>
                    <th>الواجب</th>
                    <th>الحالة</th>
                    <th>الدرجة</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$submissions): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">
                            لا توجد سجلات تسليم حتى الآن.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $item): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(
                                    $item['student_number'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <?= htmlspecialchars(
                                    $statusLabels[$item['submission_status']] ?? $item['submission_status'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars((string)($item['score'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($item['feedback'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
