
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $classroomName = trim($_POST['classroom_name'] ?? '');
    $gradeLevelId = filter_input(INPUT_POST, 'grade_level_id', FILTER_VALIDATE_INT);
    $academicYearId = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
    $capacity = filter_input(INPUT_POST, 'capacity', FILTER_VALIDATE_INT);
    $roomNumber = trim($_POST['room_number'] ?? '');

    if (
        $classroomName === '' ||
        !$gradeLevelId || !$academicYearId ||
        !$capacity || $capacity < 1
    ) {
        $error = 'يرجى ملء الحقول المطلوبة والتأكد من السعة.';
    } else {
        try {
            $check = $pdo->prepare(
                "SELECT
                    (SELECT COUNT(*) FROM grade_levels
                     WHERE id = ? AND is_active = 1) AS grade_exists,
                    (SELECT COUNT(*) FROM academic_years
                     WHERE id = ?) AS year_exists"
            );
            $check->execute([$gradeLevelId, $academicYearId]);
            $valid = $check->fetch();

            if (!$valid['grade_exists'] || !$valid['year_exists']) {
                $error = 'الصف الدراسي أو السنة الأكاديمية غير صحيحة.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO classrooms
                    (classroom_name, grade_level_id, academic_year_id,
                     capacity, room_number)
                    VALUES (?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $classroomName,
                    $gradeLevelId,
                    $academicYearId,
                    $capacity,
                    $roomNumber !== '' ? $roomNumber : null
                ]);

                $success = 'تمت إضافة الفصل الدراسي بنجاح.';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'تعذرت إضافة الفصل. تحقق من البيانات وحاول مرة أخرى.';
        }
    }
}

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

$classrooms = $pdo->query(
    "SELECT c.id, c.classroom_name, c.capacity, c.room_number,
            c.is_active, g.grade_name, a.year_name
     FROM classrooms c
     INNER JOIN grade_levels g ON g.id = c.grade_level_id
     INNER JOIN academic_years a ON a.id = c.academic_year_id
     ORDER BY c.id DESC"
)->fetchAll();

$pageTitle = 'إدارة الفصول الدراسية';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">إدارة الفصول الدراسية</h1>

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
        <h2 class="h5 mb-0">إضافة فصل جديد</h2>
    </div>

    <div class="card-body">
        <?php if (!$grades || !$years): ?>
            <div class="alert alert-warning mb-0">
                يجب التأكد من وجود صف دراسي نشط وسنة أكاديمية قبل إضافة فصل.
            </div>
        <?php else: ?>
            <form method="POST" action="classrooms.php">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">اسم الفصل *</label>
                        <input type="text" name="classroom_name"
                               class="form-control" maxlength="100"
                               placeholder="مثلاً: الفصل أ" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">الصف الدراسي *</label>
                        <select name="grade_level_id"
                                class="form-select" required>
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
                        <select name="academic_year_id"
                                class="form-select" required>
                            <option value="">اختر السنة الأكاديمية</option>
                            <?php foreach ($years as $year): ?>
                                <option value="<?= (int)$year['id'] ?>">
                                    <?= htmlspecialchars($year['year_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">سعة الفصل *</label>
                        <input type="number" name="capacity"
                               class="form-control" min="1"
                               max="1000" value="30" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">رقم الغرفة</label>
                        <input type="text" name="room_number"
                               class="form-control" maxlength="30">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            حفظ الفصل
                        </button>
                    </div>

                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 mb-0">الفصول المسجلة</h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>اسم الفصل</th>
                    <th>الصف الدراسي</th>
                    <th>السنة الأكاديمية</th>
                    <th>رقم الغرفة</th>
                    <th>السعة</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$classrooms): ?>
                    <tr>
                        <td colspan="6"
                            class="text-center py-4 text-secondary">
                            لا توجد فصول مسجلة حتى الآن.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($classrooms as $classroom): ?>
                        <tr>
                            <td><?= htmlspecialchars($classroom['classroom_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($classroom['grade_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($classroom['year_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($classroom['room_number'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)$classroom['capacity'] ?></td>
                            <td>
                                <?php if ((int)$classroom['is_active'] === 1): ?>
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
