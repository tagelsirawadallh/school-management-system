```php
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

function homeworkEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function homeworkValidDate(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $assignmentId = filter_input(INPUT_POST, 'teaching_assignment_id', FILTER_VALIDATE_INT);
    $assignedDate = $_POST['assigned_date'] ?? '';
    $dueDate = $_POST['due_date'] ?? '';
    $maxScoreInput = $_POST['max_score'] ?? '10';
    $maxScore = filter_var($maxScoreInput, FILTER_VALIDATE_FLOAT);
    $status = $_POST['status'] ?? 'published';

    if (
        $title === '' ||
        mb_strlen($title) > 200 ||
        !$assignmentId ||
        !homeworkValidDate($assignedDate) ||
        !homeworkValidDate($dueDate) ||
        $dueDate < $assignedDate ||
        $maxScore === false ||
        $maxScore <= 0 ||
        $maxScore > 99999.99 ||
        !in_array($status, ['draft', 'published', 'closed'], true)
    ) {
        $error = 'يرجى التأكد من صحة جميع البيانات المطلوبة.';
    } else {
        try {
            $check = $pdo->prepare("
                SELECT
                    ta.id,
                    ta.academic_year_id
                FROM teaching_assignments ta
                INNER JOIN subjects s ON s.id = ta.subject_id
                INNER JOIN classrooms c ON c.id = ta.classroom_id
                INNER JOIN academic_years ay ON ay.id = ta.academic_year_id
                WHERE ta.id = ?
                  AND s.is_active = 1
                  AND c.is_active = 1
                LIMIT 1
            ");
            $check->execute([$assignmentId]);
            $assignment = $check->fetch();

            if (!$assignment) {
                $error = 'التكليف التدريسي المحدد غير موجود أو غير نشط.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO homework (
                        title,
                        description,
                        teaching_assignment_id,
                        academic_year_id,
                        assigned_by,
                        assigned_date,
                        due_date,
                        max_score,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $title,
                    $description !== '' ? $description : null,
                    (int)$assignment['id'],
                    (int)$assignment['academic_year_id'],
                    (int)$_SESSION['user_id'],
                    $assignedDate,
                    $dueDate,
                    $maxScore,
                    $status
                ]);

                $success = 'تمت إضافة الواجب بنجاح.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'تعذرت إضافة الواجب. راجع سجل الأخطاء إذا استمرت المشكلة.';
        }
    }
}

try {
    $assignments = $pdo->query("
        SELECT
            ta.id,
            s.subject_name,
            c.classroom_name,
            u.full_name AS teacher_name,
            ay.year_name
        FROM teaching_assignments ta
        INNER JOIN subjects s ON s.id = ta.subject_id
        INNER JOIN classrooms c ON c.id = ta.classroom_id
        INNER JOIN teachers t ON t.id = ta.teacher_id
        INNER JOIN users u ON u.id = t.user_id
        INNER JOIN academic_years ay ON ay.id = ta.academic_year_id
        WHERE s.is_active = 1
          AND c.is_active = 1
        ORDER BY ay.year_name DESC, c.classroom_name, s.subject_name
    ")->fetchAll();

    $homeworks = $pdo->query("
        SELECT
            h.id,
            h.title,
            h.description,
            h.assigned_date,
            h.due_date,
            h.max_score,
            h.status,
            s.subject_name,
            c.classroom_name,
            u.full_name AS teacher_name,
            ay.year_name
        FROM homework h
        INNER JOIN teaching_assignments ta
            ON ta.id = h.teaching_assignment_id
        INNER JOIN subjects s ON s.id = ta.subject_id
        INNER JOIN classrooms c ON c.id = ta.classroom_id
        INNER JOIN teachers t ON t.id = ta.teacher_id
        INNER JOIN users u ON u.id = t.user_id
        INNER JOIN academic_years ay ON ay.id = h.academic_year_id
        ORDER BY h.created_at DESC, h.id DESC
    ")->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    exit('تعذر تحميل بيانات الواجبات. تحقق من بنية الجداول المرتبطة.');
}

$pageTitle = 'الواجبات المدرسية';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">إدارة الواجبات المدرسية</h1>

<?php if ($success): ?>
    <div class="alert alert-success">
        <?= homeworkEscape($success) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <?= homeworkEscape($error) ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">إضافة واجب جديد</h2>
    </div>

    <div class="card-body">
        <?php if (!$assignments): ?>
            <div class="alert alert-warning mb-0">
                لا توجد تكليفات تدريسية متاحة. أضف تكليفاً يربط المعلم بالمادة والفصل والسنة الدراسية أولاً.
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">عنوان الواجب *</label>
                        <input
                            type="text"
                            name="title"
                            class="form-control"
                            maxlength="200"
                            required
                            placeholder="مثلاً: حل تمارين الفصل الأول"
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">التكليف التدريسي *</label>
                        <select
                            name="teaching_assignment_id"
                            class="form-select"
                            required
                        >
                            <option value="">اختر المادة والفصل والمعلم</option>

                            <?php foreach ($assignments as $assignment): ?>
                                <option value="<?= (int)$assignment['id'] ?>">
                                    <?= homeworkEscape($assignment['subject_name']) ?>
                                    — <?= homeworkEscape($assignment['classroom_name']) ?>
                                    — <?= homeworkEscape($assignment['teacher_name']) ?>
                                    — <?= homeworkEscape($assignment['year_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">تاريخ التكليف *</label>
                        <input
                            type="date"
                            name="assigned_date"
                            class="form-control"
                            value="<?= date('Y-m-d') ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">تاريخ التسليم *</label>
                        <input
                            type="date"
                            name="due_date"
                            class="form-control"
                            min="<?= date('Y-m-d') ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">الدرجة القصوى *</label>
                        <input
                            type="number"
                            name="max_score"
                            class="form-control"
                            min="0.01"
                            max="99999.99"
                            step="0.01"
                            value="10"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">حالة الواجب *</label>
                        <select name="status" class="form-select" required>
                            <option value="published">منشور</option>
                            <option value="draft">مسودة</option>
                            <option value="closed">مغلق</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">تفاصيل الواجب</label>
                        <textarea
                            name="description"
                            class="form-control"
                            rows="3"
                            maxlength="5000"
                            placeholder="اكتب المطلوب من الطلاب..."
                        ></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            حفظ الواجب
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
            الواجبات المسجلة (<?= count($homeworks) ?>)
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>عنوان الواجب</th>
                    <th>المادة</th>
                    <th>الفصل</th>
                    <th>المعلم</th>
                    <th>السنة الدراسية</th>
                    <th>تاريخ التكليف</th>
                    <th>تاريخ التسليم</th>
                    <th>الدرجة</th>
                    <th>الحالة</th>
                    <th>التفاصيل</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$homeworks): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4">
                            لا توجد واجبات مسجلة حتى الآن.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($homeworks as $homework): ?>
                        <tr>
                            <td><?= homeworkEscape($homework['title']) ?></td>
                            <td><?= homeworkEscape($homework['subject_name']) ?></td>
                            <td><?= homeworkEscape($homework['classroom_name']) ?></td>
                            <td><?= homeworkEscape($homework['teacher_name']) ?></td>
                            <td><?= homeworkEscape($homework['year_name']) ?></td>
                            <td><?= homeworkEscape($homework['assigned_date']) ?></td>
                            <td><?= homeworkEscape($homework['due_date']) ?></td>
                            <td><?= homeworkEscape($homework['max_score']) ?></td>
                            <td>
                                <?php
                                $statusLabels = [
                                    'draft' => 'مسودة',
                                    'published' => 'منشور',
                                    'closed' => 'مغلق'
                                ];
                                ?>
                                <?= homeworkEscape($statusLabels[$homework['status']] ?? $homework['status']) ?>
                            </td>
                            <td>
                                <?= nl2br(homeworkEscape($homework['description'] ?? '—')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
