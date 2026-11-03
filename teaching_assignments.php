<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

if (!in_array($roleName, ['system_admin', 'school_admin'], true)) {
    http_response_code(403);
    exit('عذراً، ليست لديك صلاحية لإدارة التكليفات التدريسية.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        $message = 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مرة أخرى.';
        $messageType = 'danger';
    } else {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $classroomId = (int)($_POST['classroom_id'] ?? 0);

        try {
            if ($teacherId <= 0 || $subjectId <= 0 || $classroomId <= 0) {
                throw new RuntimeException('يرجى اختيار المعلم والمادة والفصل.');
            }

            $stmt = $pdo->prepare("
                SELECT c.academic_year_id
                FROM classrooms c
                INNER JOIN academic_years ay ON ay.id = c.academic_year_id
                WHERE c.id = :classroom_id
                  AND c.is_active = 1
                  AND ay.status = 'active'
                  AND EXISTS (
                      SELECT 1
                      FROM teachers t
                      INNER JOIN users u ON u.id = t.user_id
                      WHERE t.id = :teacher_id
                        AND t.is_active = 1
                        AND u.is_active = 1
                  )
                  AND EXISTS (
                      SELECT 1
                      FROM subjects s
                      WHERE s.id = :subject_id
                        AND s.is_active = 1
                  )
            ");
            $stmt->execute([
                'classroom_id' => $classroomId,
                'teacher_id' => $teacherId,
                'subject_id' => $subjectId
            ]);
            $academicYearId = $stmt->fetchColumn();

            if (!$academicYearId) {
                throw new RuntimeException(
                    'المعلم أو المادة أو الفصل غير موجود أو غير نشط، أو أن السنة الدراسية غير فعالة.'
                );
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM teaching_assignments
                WHERE teacher_id = :teacher_id
                  AND subject_id = :subject_id
                  AND classroom_id = :classroom_id
                  AND academic_year_id = :academic_year_id
                LIMIT 1
            ");
            $stmt->execute([
                'teacher_id' => $teacherId,
                'subject_id' => $subjectId,
                'classroom_id' => $classroomId,
                'academic_year_id' => $academicYearId
            ]);

            if ($stmt->fetch()) {
                throw new RuntimeException('هذا التكليف مسجل مسبقاً.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO teaching_assignments (
                    teacher_id,
                    subject_id,
                    classroom_id,
                    academic_year_id
                ) VALUES (
                    :teacher_id,
                    :subject_id,
                    :classroom_id,
                    :academic_year_id
                )
            ");
            $stmt->execute([
                'teacher_id' => $teacherId,
                'subject_id' => $subjectId,
                'classroom_id' => $classroomId,
                'academic_year_id' => $academicYearId
            ]);

            $message = 'تمت إضافة التكليف التدريسي. يمكنك الآن إنشاء حصصه.';
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

$teachers = $pdo->query("
    SELECT t.id, u.full_name
    FROM teachers t
    INNER JOIN users u ON u.id = t.user_id
    WHERE t.is_active = 1 AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

$subjects = $pdo->query("
    SELECT id, subject_name
    FROM subjects
    WHERE is_active = 1
    ORDER BY subject_name
")->fetchAll();

$classrooms = $pdo->query("
    SELECT c.id, c.classroom_name, ay.year_name
    FROM classrooms c
    INNER JOIN academic_years ay ON ay.id = c.academic_year_id
    WHERE c.is_active = 1 AND ay.status = 'active'
    ORDER BY ay.id DESC, c.classroom_name
")->fetchAll();

$assignments = $pdo->query("
    SELECT
        ta.id,
        u.full_name AS teacher_name,
        s.subject_name,
        c.classroom_name,
        ay.year_name
    FROM teaching_assignments ta
    INNER JOIN teachers t ON t.id = ta.teacher_id
    INNER JOIN users u ON u.id = t.user_id
    INNER JOIN subjects s ON s.id = ta.subject_id
    INNER JOIN classrooms c ON c.id = ta.classroom_id
    INNER JOIN academic_years ay ON ay.id = ta.academic_year_id
    ORDER BY ay.id DESC, c.classroom_name, s.subject_name
")->fetchAll();

$pageTitle = 'التكليفات التدريسية';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" dir="rtl">
    <div class="mb-4">
        <h1 class="h3">التكليفات التدريسية</h1>
        <p class="text-muted mb-0">
            اربط المعلم بالمادة والفصل قبل إضافة الحصص إلى الجدول.
        </p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$teachers || !$subjects || !$classrooms): ?>
        <div class="alert alert-warning">
            لإضافة تكليف، تأكد من تسجيل معلم نشط ومادة نشطة وفصل نشط مرتبط بسنة دراسية فعالة.
        </div>
    <?php else: ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">إضافة تكليف تدريسي</div>
            <div class="card-body">
                <form method="POST" action="teaching_assignments.php">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">المعلم *</label>
                            <select name="teacher_id" class="form-select" required>
                                <option value="">اختر المعلم</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?= (int)$teacher['id'] ?>">
                                        <?= htmlspecialchars($teacher['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">المادة *</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">اختر المادة</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= (int)$subject['id'] ?>">
                                        <?= htmlspecialchars($subject['subject_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">الفصل والسنة الدراسية *</label>
                            <select name="classroom_id" class="form-select" required>
                                <option value="">اختر الفصل</option>
                                <?php foreach ($classrooms as $classroom): ?>
                                    <option value="<?= (int)$classroom['id'] ?>">
                                        <?= htmlspecialchars(
                                            $classroom['classroom_name'] . ' - ' .
                                            $classroom['year_name']
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                حفظ التكليف
                            </button>
                            <a href="timetable.php" class="btn btn-outline-secondary">
                                الانتقال إلى جدول الحصص
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header">التكليفات المسجلة (<?= count($assignments) ?>)</div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>المعلم</th>
                        <th>المادة</th>
                        <th>الفصل</th>
                        <th>السنة الدراسية</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$assignments): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                لا توجد تكليفات تدريسية مسجلة حتى الآن.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td><?= htmlspecialchars($assignment['teacher_name']) ?></td>
                                <td><?= htmlspecialchars($assignment['subject_name']) ?></td>
                                <td><?= htmlspecialchars($assignment['classroom_name']) ?></td>
                                <td><?= htmlspecialchars($assignment['year_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
