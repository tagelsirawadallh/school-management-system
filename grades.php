
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
    $examId = filter_input(INPUT_POST, 'exam_id', FILTER_VALIDATE_INT);
    $score = $_POST['score'] ?? '';

    if (
        !$studentId ||
        !$examId ||
        !is_numeric($score) ||
        (float)$score < 0
    ) {
        $error = 'يرجى إدخال بيانات صحيحة.';
    } else {
        try {
            $check = $pdo->prepare(
                "SELECT e.max_score
                 FROM exams e
                 WHERE e.id = ?"
            );
            $check->execute([$examId]);
            $exam = $check->fetch();

            $studentCheck = $pdo->prepare(
                "SELECT id FROM students WHERE id = ?"
            );
            $studentCheck->execute([$studentId]);

            if (!$exam || !$studentCheck->fetch()) {
                $error = 'الطالب أو الامتحان غير موجود.';
            } elseif ((float)$score > (float)$exam['max_score']) {
                $error = 'الدرجة لا يمكن أن تتجاوز الدرجة الكاملة للامتحان.';
            } else {
                $checkExisting = $pdo->prepare(
                    "SELECT id FROM grades
                     WHERE student_id = ? AND exam_id = ?"
                );
                $checkExisting->execute([$studentId, $examId]);

                if ($checkExisting->fetch()) {
                    $error = 'تم تسجيل درجة لهذا الطالب في هذا الامتحان مسبقاً.';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO grades (student_id, exam_id, score)
                         VALUES (?, ?, ?)"
                    );
                    $stmt->execute([$studentId, $examId, $score]);
                    $success = 'تم تسجيل الدرجة بنجاح.';
                }
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'حدث خطأ أثناء حفظ الدرجة. قد تختلف أعمدة جدول الدرجات عن الكود.';
        }
    }
}

$students = $pdo->query(
    "SELECT s.id, s.student_number, u.full_name
     FROM students s
     INNER JOIN users u ON u.id = s.user_id
     ORDER BY u.full_name"
)->fetchAll();

$exams = $pdo->query(
    "SELECT id, exam_name, max_score FROM exams ORDER BY exam_date DESC"
)->fetchAll();

$grades = $pdo->query(
    "SELECT g.id, g.student_id, g.exam_id, g.score,
            s.student_number, u.full_name, e.exam_name, e.max_score
     FROM grades g
     INNER JOIN students s ON s.id = g.student_id
     INNER JOIN users u ON u.id = s.user_id
     INNER JOIN exams e ON e.id = g.exam_id
     ORDER BY g.id DESC"
)->fetchAll();

$pageTitle = 'إدارة الدرجات';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">إدارة درجات الطلاب</h1>

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
    <div class="card-header bg-white">
        <h2 class="h5 mb-0">تسجيل درجة جديدة</h2>
    </div>

    <div class="card-body">
        <?php if (!$students || !$exams): ?>
            <div class="alert alert-warning mb-0">
                يجب تسجيل طالب وامتحان واحد على الأقل أولاً.
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">الطالب</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">اختر الطالب</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int)$student['id'] ?>">
                                    <?= htmlspecialchars(
                                        $student['full_name'] . ' — ' .
                                        ($student['student_number'] ?? ('رقم ' . $student['id'])),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">الامتحان</label>
                        <select name="exam_id" class="form-select" required>
                            <option value="">اختر الامتحان</option>
                            <?php foreach ($exams as $exam): ?>
                                <option value="<?= (int)$exam['id'] ?>">
                                    <?= htmlspecialchars($exam['exam_name'], ENT_QUOTES, 'UTF-8') ?>
                                    — الدرجة الكاملة:
                                    <?= htmlspecialchars((string)$exam['max_score'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">درجة الطالب</label>
                        <input type="number" name="score"
                               class="form-control" min="0"
                               step="0.01" required>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            حفظ الدرجة
                        </button>
                    </div>

                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h5 mb-0">
            الدرجات المسجلة (<?= count($grades) ?>)
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>الطالب</th>
                    <th>الامتحان</th>
                    <th>الدرجة</th>
                    <th>الدرجة الكاملة</th>
                    <th>النسبة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$grades): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">
                            لا توجد درجات مسجلة حتى الآن.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($grades as $grade): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(
                                    $grade['full_name'] . ' — ' .
                                    ($grade['student_number'] ?? ''),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($grade['exam_name'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td><?= htmlspecialchars((string)$grade['score'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$grade['max_score'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php
                                $percentage = (float)$grade['max_score'] > 0
                                    ? ((float)$grade['score'] / (float)$grade['max_score']) * 100
                                    : 0;
                                echo number_format($percentage, 2) . '%';
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
