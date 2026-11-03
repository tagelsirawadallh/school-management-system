
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
    $academicYearId = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
    $behaviorType = $_POST['behavior_type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $behaviorDate = $_POST['behavior_date'] ?? '';
    $actionTaken = trim($_POST['action_taken'] ?? '');

    $allowedTypes = ['positive', 'negative'];
    $date = DateTime::createFromFormat('!Y-m-d', $behaviorDate);

    if (
        !$studentId ||
        !$academicYearId ||
        !in_array($behaviorType, $allowedTypes, true) ||
        $title === '' ||
        strlen($title) > 150 ||
        $description === '' ||
        !$date ||
        $date->format('Y-m-d') !== $behaviorDate
    ) {
        $error = 'يرجى تعبئة البيانات المطلوبة بصورة صحيحة.';
    } else {
        try {
            $check = $pdo->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM students WHERE id = ?) AS student_exists,
                    (SELECT COUNT(*) FROM academic_years WHERE id = ?) AS year_exists"
            );
            $check->execute([$studentId, $academicYearId]);
            $validRecords = $check->fetch();

            if (
                !$validRecords ||
                (int)$validRecords['student_exists'] === 0 ||
                (int)$validRecords['year_exists'] === 0
            ) {
                $error = 'الطالب أو السنة الدراسية المحددة غير موجودة.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO student_behavior
                    (student_id, academic_year_id, recorded_by, behavior_type,
                     title, description, behavior_date, action_taken)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $studentId,
                    $academicYearId,
                    (int)$_SESSION['user_id'],
                    $behaviorType,
                    $title,
                    $description,
                    $behaviorDate,
                    $actionTaken !== '' ? $actionTaken : null
                ]);

                $success = 'تم تسجيل الملاحظة السلوكية بنجاح.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'تعذر حفظ الملاحظة في قاعدة البيانات. راجع سجل الأخطاء أو تحقق من وجود السنة الدراسية وعنوان الملاحظة.';
        }
    }
}

$students = $pdo->query(
    "SELECT s.id, s.student_number, u.full_name
     FROM students s
     INNER JOIN users u ON u.id = s.user_id
     ORDER BY u.full_name"
)->fetchAll();

$years = $pdo->query(
    "SELECT id, year_name, is_current
     FROM academic_years
     ORDER BY is_current DESC, id DESC"
)->fetchAll();

$records = $pdo->query(
    "SELECT b.*, s.student_number, u.full_name, ay.year_name
     FROM student_behavior b
     INNER JOIN students s ON s.id = b.student_id
     INNER JOIN users u ON u.id = s.user_id
     INNER JOIN academic_years ay ON ay.id = b.academic_year_id
     ORDER BY b.behavior_date DESC, b.id DESC"
)->fetchAll();

$pageTitle = 'سلوك الطلاب';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">إدارة سلوك الطلاب</h1>

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
        <h2 class="h5 mb-0">تسجيل ملاحظة سلوكية</h2>
    </div>

    <div class="card-body">
        <?php if (!$students || !$years): ?>
            <div class="alert alert-warning mb-0">
                يجب تسجيل طالب وسنة دراسية أولاً قبل إضافة ملاحظة سلوكية.
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">الطالب *</label>
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
                        <label class="form-label">نوع السلوك *</label>
                        <select name="behavior_type" class="form-select" required>
                            <option value="positive">إيجابي</option>
                            <option value="negative">سلبي</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">السنة الدراسية *</label>
                        <select name="academic_year_id" class="form-select" required>
                            <option value="">اختر السنة الدراسية</option>
                            <?php foreach ($years as $year): ?>
                                <option value="<?= (int)$year['id'] ?>"
                                    <?= !empty($year['is_current']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($year['year_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">تاريخ الملاحظة *</label>
                        <input type="date" name="behavior_date"
                               class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">عنوان الملاحظة *</label>
                        <input type="text" name="title" class="form-control"
                               maxlength="150" required
                               placeholder="مثلاً: الالتزام بالنظام">
                    </div>

                    <div class="col-12">
                        <label class="form-label">تفاصيل الملاحظة *</label>
                        <textarea name="description" class="form-control"
                                  rows="3" maxlength="1000"
                                  placeholder="اكتب تفاصيل السلوك..." required></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">الإجراء المتخذ</label>
                        <textarea name="action_taken" class="form-control"
                                  rows="2" maxlength="1000"
                                  placeholder="مثلاً: إشادة، تنبيه، أو تواصل مع ولي الأمر"></textarea>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            حفظ الملاحظة
                        </button>
                    </div>

                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">سجل السلوك (<?= count($records) ?>)</h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>الطالب</th>
                    <th>السنة الدراسية</th>
                    <th>النوع</th>
                    <th>العنوان</th>
                    <th>التفاصيل</th>
                    <th>التاريخ</th>
                    <th>الإجراء المتخذ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$records): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            لا توجد ملاحظات سلوكية مسجلة.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(
                                    $record['full_name'] . ' — ' .
                                    ($record['student_number'] ?? ''),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>
                            <td><?= htmlspecialchars($record['year_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($record['behavior_type'] === 'positive'): ?>
                                    <span class="badge bg-success">إيجابي</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">سلبي</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($record['title'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?= nl2br(htmlspecialchars(
                                    $record['description'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                )) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($record['behavior_date'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <?= htmlspecialchars(
                                    $record['action_taken'] ?? '—',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
