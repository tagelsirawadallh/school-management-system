
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/config/database.php';

$errors = [];
$results = [];
$summary = [
    'students' => 0,
    'exams' => 0,
    'average' => 0,
    'pass_rate' => 0
];

$examId = filter_input(INPUT_GET, 'exam_id', FILTER_VALIDATE_INT) ?: 0;
$search = trim($_GET['search'] ?? '');
$academicYearId = filter_input(INPUT_GET, 'academic_year_id', FILTER_VALIDATE_INT) ?: 0;

$examTypes = [
    'monthly' => 'شهري',
    'midterm' => 'منتصف الفصل',
    'final' => 'نهائي',
    'practical' => 'عملي',
    'other' => 'أخرى'
];

try {
    // قائمة الاختبارات
    $exams = $pdo->query("
        SELECT id, exam_name, max_score, exam_date, academic_year_id
        FROM exams
        ORDER BY exam_date DESC, id DESC
    ")->fetchAll();

    // قائمة الأعوام الدراسية
    $academicYears = $pdo->query("
        SELECT id, year_name
        FROM academic_years
        ORDER BY start_date DESC
    ")->fetchAll();

    // إحصائيات عامة
    $summary['students'] = (int) $pdo->query("
        SELECT COUNT(*) FROM students WHERE is_active = 1
    ")->fetchColumn();

    $summary['exams'] = (int) $pdo->query("
        SELECT COUNT(*) FROM exams
    ")->fetchColumn();

    // شروط البحث
    $where = [];
    $params = [];

    if ($examId > 0) {
        $where[] = 'e.id = :exam_id';
        $params['exam_id'] = $examId;
    }

    if ($academicYearId > 0) {
        $where[] = 'e.academic_year_id = :year_id';
        $params['year_id'] = $academicYearId;
    }

    if ($search !== '') {
        $where[] = '(u.full_name LIKE :search_name OR s.student_number LIKE :search_number OR e.exam_name LIKE :search_exam)';
        $keyword = '%' . $search . '%';
        $params['search_name'] = $keyword;
        $params['search_number'] = $keyword;
        $params['search_exam'] = $keyword;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // نتائج الطلاب
    $sql = "
        SELECT
            g.id AS grade_id,
            u.full_name,
            s.student_number,
            e.exam_name,
            e.exam_type,
            e.exam_date,
            e.max_score,
            g.score,
            g.remarks,
            CASE
                WHEN e.max_score > 0 THEN ROUND((g.score / e.max_score) * 100, 2)
                ELSE 0
            END AS percentage
        FROM grades g
        INNER JOIN students s ON s.id = g.student_id
        INNER JOIN users u ON u.id = s.user_id
        INNER JOIN exams e ON e.id = g.exam_id
        $whereSql
        ORDER BY e.exam_date DESC, u.full_name ASC
        LIMIT 1000
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // متوسط الدرجات ونسبة النجاح
    if ($results) {
        $totalPercentage = 0;
        $passed = 0;

        foreach ($results as $result) {
            $percentage = (float) $result['percentage'];
            $totalPercentage += $percentage;

            if ($percentage >= 50) {
                $passed++;
            }
        }

        $summary['average'] = round($totalPercentage / count($results), 2);
        $summary['pass_rate'] = round(($passed / count($results)) * 100, 2);
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $errors[] = 'حصل خطأ أثناء تحميل التقارير. راجع سجل الأخطاء إذا استمرت المشكلة.';
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.report-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 3px 12px rgba(0, 0, 0, .06);
}
.report-number {
    font-size: 1.8rem;
    font-weight: 700;
}
.table-responsive {
    border-radius: 10px;
}
@media print {
    .no-print {
        display: none !important;
    }
    body * {
        visibility: hidden !important;
    }
    .results-print-area,
    .results-print-area * {
        visibility: visible !important;
    }
    .results-print-area {
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        box-shadow: none !important;
    }
    .results-print-area .table-responsive {
        overflow: visible !important;
    }
}
</style>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h2 class="fw-bold mb-1">التقارير والنتائج</h2>
            <p class="text-secondary mb-0">متابعة أداء الطلاب ونتائج الاختبارات</p>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endforeach; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="card report-card h-100">
                <div class="card-body">
                    <div class="text-secondary">الطلاب النشطون</div>
                    <div class="report-number text-primary">
                        <?= number_format($summary['students']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card report-card h-100">
                <div class="card-body">
                    <div class="text-secondary">إجمالي الاختبارات</div>
                    <div class="report-number text-success">
                        <?= number_format($summary['exams']) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card report-card h-100">
                <div class="card-body">
                    <div class="text-secondary">متوسط النسب المعروضة</div>
                    <div class="report-number text-info">
                        <?= number_format($summary['average'], 2) ?>%
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card report-card h-100">
                <div class="card-body">
                    <div class="text-secondary">نسبة النجاح التقديرية</div>
                    <div class="report-number text-warning">
                        <?= number_format($summary['pass_rate'], 2) ?>%
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card report-card mb-4 no-print">
        <div class="card-body">
            <h5 class="fw-bold mb-3">البحث وتصفية النتائج</h5>

            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">اسم الطالب أو رقمه أو الاختبار</label>
                    <input type="text" name="search" class="form-control"
                           value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="اكتب كلمة البحث">
                </div>

                <div class="col-md-4">
                    <label class="form-label">الاختبار</label>
                    <select name="exam_id" class="form-select">
                        <option value="0">كل الاختبارات</option>
                        <?php foreach ($exams as $exam): ?>
                            <option value="<?= (int) $exam['id'] ?>"
                                <?= $examId === (int) $exam['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($exam['exam_name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">العام الدراسي</label>
                    <select name="academic_year_id" class="form-select">
                        <option value="0">كل الأعوام</option>
                        <?php foreach ($academicYears as $year): ?>
                            <option value="<?= (int) $year['id'] ?>"
                                <?= $academicYearId === (int) $year['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($year['year_name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">عرض النتائج</button>
                    <a href="reports.php" class="btn btn-outline-secondary">إعادة ضبط</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card report-card results-print-area">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">تفاصيل درجات الطلاب</h5>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary">
                        <?= number_format(count($results)) ?> نتيجة
                    </span>
                    <button type="button" class="btn btn-outline-primary btn-sm no-print"
                            onclick="window.print()">
                        🖨️ طباعة النتائج
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>اسم الطالب</th>
                            <th>رقم الطالب</th>
                            <th>الاختبار</th>
                            <th>نوع الاختبار</th>
                            <th>التاريخ</th>
                            <th>الدرجة</th>
                            <th>النسبة</th>
                            <th>الحالة</th>
                            <th>الملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$results): ?>
                            <tr>
                                <td colspan="10" class="text-center text-secondary py-4">
                                    لا توجد نتائج مطابقة للبحث.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($results as $index => $row): ?>
                                <?php
                                    $percentage = (float) $row['percentage'];
                                    $passed = $percentage >= 50;
                                ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($row['student_number'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($row['exam_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?= htmlspecialchars($examTypes[$row['exam_type']] ?? 'أخرى', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['exam_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?= number_format((float) $row['score'], 2) ?>
                                        /
                                        <?= number_format((float) $row['max_score'], 2) ?>
                                    </td>
                                    <td><?= number_format($percentage, 2) ?>%</td>
                                    <td>
                                        <span class="badge <?= $passed ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $passed ? 'ناجح' : 'راسب' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="text-secondary small mt-2 mb-0">
                ملاحظة: تعرض الشاشة أول 1000 نتيجة كحد أقصى، ويُحسب النجاح هنا عند الحصول على 50% فأكثر.
                عدّل حد النجاح حسب لائحة المدرسة إذا كانت مختلفة.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
