
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';
$csrfToken = $_SESSION['csrf_token'] ?? '';

if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
    $academicYearId = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
    $attendanceDate = $_POST['attendance_date'] ?? '';
    $status = $_POST['status'] ?? '';
    $arrivalTime = $_POST['arrival_time'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    $allowedStatuses = ['present', 'absent', 'late', 'excused'];

    $validDate = DateTime::createFromFormat('!Y-m-d', $attendanceDate);

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        $error = 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.';
    } elseif (
        !$studentId || !$academicYearId ||
        !$validDate || $validDate->format('Y-m-d') !== $attendanceDate ||
        !in_array($status, $allowedStatuses, true)
    ) {
        $error = 'يرجى التأكد من صحة بيانات الحضور المطلوبة.';
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
                $error = 'الطالب أو السنة الأكاديمية المحددة غير موجودة.';
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id
                     FROM student_attendance
                     WHERE student_id = ?
                       AND attendance_date = ?
                     LIMIT 1"
                );
                $stmt->execute([
                    $studentId,
                    $attendanceDate
                ]);

                if ($stmt->fetch()) {
                    $error = 'تم تسجيل حضور هذا الطالب في هذا التاريخ مسبقاً، ولا يسمح النظام بأكثر من سجل واحد للطالب في اليوم.';
                } else {
                    $timeValue = null;

                    if ($arrivalTime !== '') {
                        $time = DateTime::createFromFormat('!H:i', $arrivalTime);

                        if (!$time || $time->format('H:i') !== $arrivalTime) {
                            throw new InvalidArgumentException('وقت الوصول غير صحيح.');
                        }

                        $timeValue = $arrivalTime . ':00';
                    }

                    $insert = $pdo->prepare(
                        "INSERT INTO student_attendance
                        (student_id, academic_year_id, attendance_date,
                         status, arrival_time, notes, recorded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );

                    $insert->execute([
                        $studentId,
                        $academicYearId,
                        $attendanceDate,
                        $status,
                        $timeValue,
                        $notes !== '' ? $notes : null,
                        (int)$_SESSION['user_id']
                    ]);

                    $success = 'تم تسجيل الحضور بنجاح.';
                }
            }
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'تعذر تسجيل الحضور. تحقق من البيانات وحاول مرة أخرى.';
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
    "SELECT id, year_name
     FROM academic_years
     ORDER BY id DESC"
)->fetchAll();

$records = $pdo->query(
    "SELECT a.attendance_date, a.status, a.arrival_time, a.notes,
            s.student_number, u.full_name, y.year_name
     FROM student_attendance a
     INNER JOIN students s ON s.id = a.student_id
     INNER JOIN users u ON u.id = s.user_id
     INNER JOIN academic_years y ON y.id = a.academic_year_id
     ORDER BY a.attendance_date DESC, u.full_name
     LIMIT 100"
)->fetchAll();

$statusLabels = [
    'present' => 'حاضر',
    'absent' => 'غائب',
    'late' => 'متأخر',
    'excused' => 'بعذر'
];

$pageTitle = 'الحضور والغياب';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">الحضور والغياب</h1>

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
        <h2 class="h5 mb-0">تسجيل حضور طالب</h2>
    </div>

    <div class="card-body">
        <?php if (!$students || !$years): ?>
            <div class="alert alert-warning mb-0">
                يجب إضافة طالب وسنة أكاديمية قبل تسجيل الحضور.
            </div>
        <?php else: ?>
            <form method="POST" action="attendance.php">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">الطالب *</label>
                        <select name="student_id" class="form-select" required>
                            <option value="">اختر الطالب</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int)$student['id'] ?>">
                                    <?= htmlspecialchars(
                                        $student['full_name'] . ' — ' .
                                        $student['student_number'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">السنة الأكاديمية *</label>
                        <select name="academic_year_id"
                                class="form-select" required>
                            <option value="">اختر السنة</option>
                            <?php foreach ($years as $year): ?>
                                <option value="<?= (int)$year['id'] ?>">
                                    <?= htmlspecialchars(
                                        $year['year_name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">تاريخ الحضور *</label>
                        <input type="date" name="attendance_date"
                               class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">الحالة *</label>
                        <select name="status" class="form-select" required>
                            <option value="present">حاضر</option>
                            <option value="absent">غائب</option>
                            <option value="late">متأخر</option>
                            <option value="excused">غائب بعذر</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">وقت الوصول</label>
                        <input type="time" name="arrival_time"
                               class="form-control">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">ملاحظات</label>
                        <input type="text" name="notes"
                               class="form-control" maxlength="255"
                               placeholder="ملاحظات اختيارية">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            تسجيل الحضور
                        </button>
                    </div>

                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">سجل الحضور والغياب</h2>
        <small class="text-secondary">آخر 100 سجل</small>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>التاريخ</th>
                    <th>رقم الطالب</th>
                    <th>اسم الطالب</th>
                    <th>السنة الأكاديمية</th>
                    <th>الحالة</th>
                    <th>وقت الوصول</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$records): ?>
                    <tr>
                        <td colspan="7"
                            class="text-center py-4 text-secondary">
                            لا توجد سجلات حضور حتى الآن.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?= htmlspecialchars($record['attendance_date'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($record['student_number'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($record['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($record['year_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $statusLabels[$record['status']] ?? 'غير معروف' ?></td>
                            <td><?= htmlspecialchars($record['arrival_time'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($record['notes'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
