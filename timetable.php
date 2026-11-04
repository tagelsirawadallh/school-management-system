
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$message = '';
$messageType = 'success';
$canManageTimetable = in_array($roleName, ['system_admin', 'school_admin'], true);

$days = [
    'sunday' => 'الأحد',
    'monday' => 'الاثنين',
    'tuesday' => 'الثلاثاء',
    'wednesday' => 'الأربعاء',
    'thursday' => 'الخميس',
    'friday' => 'الجمعة',
    'saturday' => 'السبت'
];

$editLesson = null;

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageTimetable) {
        http_response_code(403);
        exit('عذراً، ليست لديك صلاحية لإدارة جدول الحصص.');
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        $message = 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $assignmentId = (int)($_POST['teaching_assignment_id'] ?? 0);
                $day = $_POST['day_of_week'] ?? '';
                $start = $_POST['start_time'] ?? '';
                $end = $_POST['end_time'] ?? '';
                $room = trim($_POST['room_number'] ?? '');

                if (!array_key_exists($day, $days)) {
                    throw new RuntimeException('يرجى اختيار يوم صحيح.');
                }

                if (
                    !preg_match('/^\d{2}:\d{2}$/', $start) ||
                    !preg_match('/^\d{2}:\d{2}$/', $end) ||
                    $start >= $end ||
                    $start > '23:59' ||
                    $end > '23:59'
                ) {
                    throw new RuntimeException(
                        'وقت البداية والنهاية غير صحيح، ويجب أن تكون النهاية بعد البداية.'
                    );
                }

                if ($assignmentId <= 0) {
                    throw new RuntimeException('يرجى اختيار التكليف التدريسي.');
                }

                // التأكد من وجود التكليف
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM teaching_assignments
                    WHERE id = :id
                ");
                $stmt->execute(['id' => $assignmentId]);

                if (!$stmt->fetch()) {
                    throw new RuntimeException('التكليف التدريسي المحدد غير موجود.');
                }

                // منع تعارض وقت المعلم أو الفصل في اليوم نفسه
                $sql = "
                    SELECT t.id
                    FROM timetable t
                    INNER JOIN teaching_assignments current_assignment
                        ON current_assignment.id = t.teaching_assignment_id
                    INNER JOIN teaching_assignments new_assignment
                        ON new_assignment.id = :new_assignment_id
                    WHERE t.day_of_week = :day
                      AND t.start_time < :new_end
                      AND t.end_time > :new_start
                      AND (
                          current_assignment.teacher_id = new_assignment.teacher_id
                          OR current_assignment.classroom_id = new_assignment.classroom_id
                      )
                ";

                $params = [
                    'new_assignment_id' => $assignmentId,
                    'day' => $day,
                    'new_end' => $end,
                    'new_start' => $start
                ];

                if ($id > 0) {
                    $sql .= " AND t.id <> :id";
                    $params['id'] = $id;
                }

                $sql .= " LIMIT 1";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if ($stmt->fetch()) {
                    throw new RuntimeException(
                        'يوجد تعارض في وقت المعلم أو الفصل مع حصة أخرى في اليوم نفسه.'
                    );
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE timetable
                        SET teaching_assignment_id = :assignment_id,
                            day_of_week = :day,
                            start_time = :start_time,
                            end_time = :end_time,
                            room_number = :room
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        'assignment_id' => $assignmentId,
                        'day' => $day,
                        'start_time' => $start,
                        'end_time' => $end,
                        'room' => $room !== '' ? $room : null,
                        'id' => $id
                    ]);

                    $message = 'تم تعديل الحصة بنجاح.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO timetable (
                            teaching_assignment_id,
                            day_of_week,
                            start_time,
                            end_time,
                            room_number
                        ) VALUES (
                            :assignment_id,
                            :day,
                            :start_time,
                            :end_time,
                            :room
                        )
                    ");

                    $stmt->execute([
                        'assignment_id' => $assignmentId,
                        'day' => $day,
                        'start_time' => $start,
                        'end_time' => $end,
                        'room' => $room !== '' ? $room : null
                    ]);

                    $message = 'تمت إضافة الحصة بنجاح.';
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);

                if ($id <= 0) {
                    throw new RuntimeException('رقم الحصة غير صحيح.');
                }

                $stmt = $pdo->prepare("
                    DELETE FROM timetable WHERE id = :id
                ");
                $stmt->execute(['id' => $id]);

                $message = $stmt->rowCount()
                    ? 'تم حذف الحصة بنجاح.'
                    : 'الحصة المطلوبة غير موجودة.';
            }
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $message = 'حدث خطأ في قاعدة البيانات. راجع سجل الأخطاء.';
            $messageType = 'danger';
        }
    }
}

// تحميل بيانات الحصة للتعديل
$editId = (int)($_GET['edit'] ?? 0);

if ($canManageTimetable && $editId > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM timetable
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $editId]);
    $editLesson = $stmt->fetch() ?: null;
}

// جلب التكليفات مع أسماء المعلمين والمواد والفصول والسنة
$assignments = [];
if ($canManageTimetable) {
    $stmt = $pdo->query("
    SELECT
        ta.id,
        u.full_name AS teacher_name,
        s.subject_name,
        c.classroom_name,
        ay.year_name
    FROM teaching_assignments ta
    INNER JOIN teachers te ON te.id = ta.teacher_id
    INNER JOIN users u ON u.id = te.user_id
    INNER JOIN subjects s ON s.id = ta.subject_id
    INNER JOIN classrooms c ON c.id = ta.classroom_id
    INNER JOIN academic_years ay ON ay.id = ta.academic_year_id
    ORDER BY ay.id DESC, c.classroom_name, s.subject_name
    ");
    $assignments = $stmt->fetchAll();
}

$studentEnrollment = null;
if ($roleName === 'student') {
    $stmt = $pdo->prepare("
        SELECT e.classroom_id, e.academic_year_id, c.classroom_name
        FROM students s
        INNER JOIN student_enrollments e ON e.student_id = s.id
        INNER JOIN academic_years ay ON ay.id = e.academic_year_id
        INNER JOIN classrooms c ON c.id = e.classroom_id
        WHERE s.user_id = :user_id
          AND e.status = 'enrolled'
          AND ay.is_current = 1
          AND ay.status = 'active'
          AND c.is_active = 1
        ORDER BY e.id DESC
        LIMIT 1
    ");
    $stmt->execute(['user_id' => (int) $_SESSION['user_id']]);
    $studentEnrollment = $stmt->fetch() ?: null;
}

// جلب جدول الحصص
$timetableSql = "
    SELECT
        t.*,
        u.full_name AS teacher_name,
        s.subject_name,
        c.classroom_name,
        ay.year_name
    FROM timetable t
    INNER JOIN teaching_assignments ta
        ON ta.id = t.teaching_assignment_id
    INNER JOIN teachers te ON te.id = ta.teacher_id
    INNER JOIN users u ON u.id = te.user_id
    INNER JOIN subjects s ON s.id = ta.subject_id
    INNER JOIN classrooms c ON c.id = ta.classroom_id
    INNER JOIN academic_years ay ON ay.id = ta.academic_year_id
";

if ($studentEnrollment) {
    $timetableSql .= "
    WHERE ta.classroom_id = :classroom_id
      AND ta.academic_year_id = :academic_year_id
    ";
}

$timetableSql .= "
    ORDER BY FIELD(
        t.day_of_week,
        'sunday', 'monday', 'tuesday', 'wednesday',
        'thursday', 'friday', 'saturday'
    ), t.start_time
";

$lessons = [];
if ($roleName !== 'student' || $studentEnrollment) {
    $stmt = $pdo->prepare($timetableSql);
    if ($studentEnrollment) {
        $stmt->execute([
            'classroom_id' => (int) $studentEnrollment['classroom_id'],
            'academic_year_id' => (int) $studentEnrollment['academic_year_id']
        ]);
    } else {
        $stmt->execute();
    }
    $lessons = $stmt->fetchAll();
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" dir="rtl">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>جدول الحصص الدراسية</h2>
            <p class="text-muted mb-0">
                <?php if ($roleName === 'student' && $studentEnrollment): ?>
                    جدول فصل <?= htmlspecialchars($studentEnrollment['classroom_name'], ENT_QUOTES, 'UTF-8') ?>
                <?php elseif ($roleName === 'student'): ?>
                    جدول الفصل الدراسي الحالي
                <?php else: ?>
                    تنظيم مواعيد المعلمين والمواد والفصول
                <?php endif; ?>
            </p>
        </div>
        <span class="badge bg-primary fs-6">
            عدد الحصص: <?= count($lessons) ?>
        </span>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$assignments && $canManageTimetable): ?>
        <div class="alert alert-warning">
            لا توجد تكليفات تدريسية، لذلك لن تتمكن من إضافة حصة بعد.
            <a href="teaching_assignments.php" class="alert-link">
                أضف تكليفاً تدريسياً أولاً
            </a>
            يربط المعلم بالمادة والفصل والسنة الدراسية.
        </div>
    <?php endif; ?>

    <?php if ($canManageTimetable): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <?= $editLesson ? 'تعديل حصة' : 'إضافة حصة جديدة' ?>
        </div>

        <div class="card-body">
            <form method="POST" action="timetable.php">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id"
                       value="<?= (int)($editLesson['id'] ?? 0) ?>">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">التكليف التدريسي *</label>
                        <select name="teaching_assignment_id"
                                class="form-select" required>
                            <option value="">اختر المعلم والمادة والفصل</option>
                            <?php foreach ($assignments as $assignment): ?>
                                <option
                                    value="<?= (int)$assignment['id'] ?>"
                                    <?= (int)($editLesson['teaching_assignment_id'] ?? 0) === (int)$assignment['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(
                                        $assignment['teacher_name'] . ' - ' .
                                        $assignment['subject_name'] . ' - ' .
                                        $assignment['classroom_name'] . ' - ' .
                                        $assignment['year_name']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">اليوم *</label>
                        <select name="day_of_week" class="form-select" required>
                            <option value="">اختر اليوم</option>
                            <?php foreach ($days as $key => $label): ?>
                                <option
                                    value="<?= htmlspecialchars($key) ?>"
                                    <?= ($editLesson['day_of_week'] ?? '') === $key ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">وقت البداية *</label>
                        <input type="time" name="start_time"
                               class="form-control" required
                               value="<?= htmlspecialchars(
                                   isset($editLesson['start_time'])
                                       ? substr($editLesson['start_time'], 0, 5)
                                       : ''
                               ) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">وقت النهاية *</label>
                        <input type="time" name="end_time"
                               class="form-control" required
                               value="<?= htmlspecialchars(
                                   isset($editLesson['end_time'])
                                       ? substr($editLesson['end_time'], 0, 5)
                                       : ''
                               ) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">رقم القاعة</label>
                        <input type="text" name="room_number"
                               class="form-control" maxlength="30"
                               value="<?= htmlspecialchars($editLesson['room_number'] ?? '') ?>">
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"
                            <?= !$assignments ? 'disabled' : '' ?>>
                            <?= $editLesson ? 'حفظ التعديلات' : 'إضافة الحصة' ?>
                        </button>

                        <?php if ($editLesson): ?>
                            <a href="timetable.php" class="btn btn-secondary">
                                إلغاء
                            </a>
                        <?php endif; ?>
                    </div>

                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header">الحصص المسجلة</div>
        <div class="card-body">
            <?php if ($roleName === 'student' && !$studentEnrollment): ?>
                <div class="alert alert-warning">
                    لم يتم تسجيلك في فصل للسنة الدراسية الحالية. يُرجى التواصل مع إدارة المدرسة.
                </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>اليوم</th>
                            <th>المعلم</th>
                            <th>المادة</th>
                            <th>الفصل</th>
                            <th>السنة الدراسية</th>
                            <th>البداية</th>
                            <th>النهاية</th>
                            <th>القاعة</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$lessons): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted">
                                    <?= $roleName === 'student' && $studentEnrollment
                                        ? 'لا توجد حصص مسجلة لفصلك حتى الآن.'
                                        : 'لا توجد حصص مسجلة حتى الآن.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lessons as $index => $lesson): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($days[$lesson['day_of_week']] ?? $lesson['day_of_week']) ?></td>
                                    <td><?= htmlspecialchars($lesson['teacher_name']) ?></td>
                                    <td><?= htmlspecialchars($lesson['subject_name']) ?></td>
                                    <td><?= htmlspecialchars($lesson['classroom_name']) ?></td>
                                    <td><?= htmlspecialchars($lesson['year_name']) ?></td>
                                    <td><?= htmlspecialchars(substr($lesson['start_time'], 0, 5)) ?></td>
                                    <td><?= htmlspecialchars(substr($lesson['end_time'], 0, 5)) ?></td>
                                    <td><?= htmlspecialchars($lesson['room_number'] ?? '') ?></td>
                                    <td>
                                        <?php if ($canManageTimetable): ?>
                                        <a href="timetable.php?edit=<?= (int)$lesson['id'] ?>"
                                           class="btn btn-sm btn-warning mb-1">
                                            تعديل
                                        </a>

                                        <form method="POST" action="timetable.php"
                                              class="d-inline"
                                              onsubmit="return confirm('هل تريد حذف هذه الحصة؟');">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id"
                                                   value="<?= (int)$lesson['id'] ?>">
                                            <button type="submit"
                                                    class="btn btn-sm btn-danger">
                                                حذف
                                            </button>
                                        </form>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
