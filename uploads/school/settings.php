
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['settings_csrf'])) {
    $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
}

$pageTitle = 'إعدادات المدرسة';
$success = '';
$error = '';

$settings = [
    'id' => null,
    'school_name' => '',
    'school_name_en' => '',
    'logo_path' => '',
    'address' => '',
    'phone' => '',
    'email' => '',
    'website' => '',
    'currency' => 'SDG',
    'academic_year_id' => '',
    'principal_name' => ''
];

try {
    $stmt = $pdo->query("
        SELECT *
        FROM school_settings
        ORDER BY id ASC
        LIMIT 1
    ");

    $savedSettings = $stmt->fetch();

    if ($savedSettings) {
        $settings = array_merge($settings, $savedSettings);
    }

    $yearsStmt = $pdo->query("
        SELECT id, year_name
        FROM academic_years
        ORDER BY id DESC
    ");

    $academicYears = $yearsStmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $academicYears = [];
    $error = 'تعذر تحميل بيانات الإعدادات. تحقق من جداول قاعدة البيانات.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['settings_csrf'], $token)
    ) {
        $error = 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مرة أخرى.';
    } else {
        $settings['school_name'] = trim($_POST['school_name'] ?? '');
        $settings['school_name_en'] = trim($_POST['school_name_en'] ?? '');
        $settings['address'] = trim($_POST['address'] ?? '');
        $settings['phone'] = trim($_POST['phone'] ?? '');
        $settings['email'] = trim($_POST['email'] ?? '');
        $settings['website'] = trim($_POST['website'] ?? '');
        $settings['currency'] = trim($_POST['currency'] ?? 'SDG');
        $settings['principal_name'] = trim($_POST['principal_name'] ?? '');

        $yearInput = $_POST['academic_year_id'] ?? '';
        $settings['academic_year_id'] = $yearInput;

        if ($settings['school_name'] === '') {
            $error = 'يرجى إدخال اسم المدرسة.';
        } elseif (
            $settings['email'] !== '' &&
            !filter_var($settings['email'], FILTER_VALIDATE_EMAIL)
        ) {
            $error = 'البريد الإلكتروني غير صحيح.';
        } elseif (
            !in_array($settings['currency'], ['SDG', 'SAR', 'EGP', 'USD'], true)
        ) {
            $error = 'العملة المحددة غير صحيحة.';
        } else {
            $logoPath = $settings['logo_path'];

            if (
                isset($_FILES['school_logo']) &&
                $_FILES['school_logo']['error'] !== UPLOAD_ERR_NO_FILE
            ) {
                $file = $_FILES['school_logo'];

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $error = 'حدث خطأ أثناء رفع الشعار.';
                } elseif ($file['size'] > 2 * 1024 * 1024) {
                    $error = 'حجم الشعار يجب ألا يتجاوز 2 ميجابايت.';
                } else {
                    $imageInfo = @getimagesize($file['tmp_name']);
                    $allowedTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp'
                    ];

                    $mime = $imageInfo['mime'] ?? '';

                    if (!$imageInfo || !isset($allowedTypes[$mime])) {
                        $error = 'الشعار يجب أن يكون صورة JPG أو PNG أو WEBP.';
                    } else {
                        $uploadDir = __DIR__ . '/uploads/school/';

                        if (
                            !is_dir($uploadDir) &&
                            !mkdir($uploadDir, 0755, true) &&
                            !is_dir($uploadDir)
                        ) {
                            $error = 'تعذر إنشاء مجلد حفظ الشعار.';
                        } else {
                            $newFileName = bin2hex(random_bytes(16))
                                . '.' . $allowedTypes[$mime];

                            if (
                                move_uploaded_file(
                                    $file['tmp_name'],
                                    $uploadDir . $newFileName
                                )
                            ) {
                                $logoPath = 'uploads/school/' . $newFileName;
                            } else {
                                $error = 'تعذر حفظ الشعار.';
                            }
                        }
                    }
                }
            }

            if ($error === '') {
                try {
                    $yearId = null;

                    if ($yearInput !== '') {
                        $yearId = filter_var(
                            $yearInput,
                            FILTER_VALIDATE_INT,
                            ['options' => ['min_range' => 1]]
                        );

                        if ($yearId === false) {
                            throw new RuntimeException('السنة الدراسية غير صحيحة.');
                        }

                        $checkYear = $pdo->prepare(
                            'SELECT id FROM academic_years WHERE id = :id'
                        );
                        $checkYear->execute(['id' => $yearId]);

                        if (!$checkYear->fetch()) {
                            throw new RuntimeException('السنة الدراسية المحددة غير موجودة.');
                        }
                    }

                    $data = [
                        'school_name' => $settings['school_name'],
                        'school_name_en' => $settings['school_name_en'] ?: null,
                        'logo_path' => $logoPath ?: null,
                        'address' => $settings['address'] ?: null,
                        'phone' => $settings['phone'] ?: null,
                        'email' => $settings['email'] ?: null,
                        'website' => $settings['website'] ?: null,
                        'currency' => $settings['currency'],
                        'academic_year_id' => $yearId,
                        'principal_name' => $settings['principal_name'] ?: null
                    ];

                    $pdo->beginTransaction();

                    $check = $pdo->query(
                        'SELECT id FROM school_settings ORDER BY id ASC LIMIT 1'
                    );
                    $existing = $check->fetch();

                    if ($existing) {
                        $data['id'] = $existing['id'];

                        $sql = "
                            UPDATE school_settings SET
                                school_name = :school_name,
                                school_name_en = :school_name_en,
                                logo_path = :logo_path,
                                address = :address,
                                phone = :phone,
                                email = :email,
                                website = :website,
                                currency = :currency,
                                academic_year_id = :academic_year_id,
                                principal_name = :principal_name,
                                updated_at = NOW()
                            WHERE id = :id
                        ";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($data);
                    } else {
                        $sql = "
                            INSERT INTO school_settings (
                                school_name, school_name_en, logo_path,
                                address, phone, email, website, currency,
                                academic_year_id, principal_name,
                                created_at, updated_at
                            ) VALUES (
                                :school_name, :school_name_en, :logo_path,
                                :address, :phone, :email, :website, :currency,
                                :academic_year_id, :principal_name,
                                NOW(), NOW()
                            )
                        ";

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($data);
                    }

                    $pdo->commit();

                    $settings['logo_path'] = $logoPath;
                    $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
                    $success = 'تم حفظ إعدادات المدرسة بنجاح.';

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log($e->getMessage());
                    $error = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : 'تعذر حفظ الإعدادات. تحقق من قاعدة البيانات وحاول مرة أخرى.';
                }
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';

function settingEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<style>
.settings-page {
    max-width: 1050px;
    margin: 0 auto;
    padding: 20px 0 40px;
}
.settings-heading {
    margin-bottom: 24px;
}
.settings-heading h2 {
    color: #08405F;
    font-weight: 700;
}
.settings-card {
    background: #fff;
    border: 1px solid #e1e8ef;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 22px;
    box-shadow: 0 4px 16px rgba(20, 50, 70, .04);
}
.settings-card h5 {
    color: #0D5C8C;
    font-weight: 700;
    margin-bottom: 22px;
}
.settings-page .form-label {
    font-weight: 600;
    margin-bottom: 8px;
}
.settings-page .form-control,
.settings-page .form-select {
    min-height: 44px;
    border-radius: 8px;
}
.settings-page .form-control:focus,
.settings-page .form-select:focus {
    border-color: #0D5C8C;
    box-shadow: 0 0 0 .2rem rgba(13, 92, 140, .12);
}
.logo-preview {
    width: 105px;
    height: 105px;
    object-fit: contain;
    border: 1px solid #e1e8ef;
    border-radius: 12px;
    padding: 8px;
    background: #f8fafc;
}
.settings-save {
    background: #0D5C8C;
    border: none;
    border-radius: 8px;
    padding: 12px 28px;
    color: white;
    font-weight: 600;
}
.settings-save:hover {
    background: #08405F;
    color: white;
}
</style>

<div class="settings-page">

    <div class="settings-heading">
        <h2>إعدادات المدرسة</h2>
        <p class="text-secondary mb-0">
            إدارة بيانات المدرسة الأساسية والشعار والسنة الدراسية.
        </p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success" role="alert">
            <?= settingEscape($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= settingEscape($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="settings.php" enctype="multipart/form-data">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= settingEscape($_SESSION['settings_csrf']) ?>"
        >

        <div class="settings-card">
            <h5>البيانات الأساسية</h5>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="school_name">اسم المدرسة بالعربية *</label>
                    <input
                        class="form-control"
                        type="text"
                        id="school_name"
                        name="school_name"
                        maxlength="200"
                        required
                        value="<?= settingEscape($settings['school_name']) ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="school_name_en">اسم المدرسة بالإنجليزية</label>
                    <input
                        class="form-control"
                        type="text"
                        id="school_name_en"
                        name="school_name_en"
                        maxlength="200"
                        value="<?= settingEscape($settings['school_name_en']) ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="principal_name">اسم مدير المدرسة</label>
                    <input
                        class="form-control"
                        type="text"
                        id="principal_name"
                        name="principal_name"
                        maxlength="150"
                        value="<?= settingEscape($settings['principal_name']) ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="academic_year_id">السنة الدراسية</label>
                    <select class="form-select" id="academic_year_id" name="academic_year_id">
                        <option value="">اختر السنة الدراسية</option>
                        <?php foreach ($academicYears as $year): ?>
                            <option
                                value="<?= (int)$year['id'] ?>"
                                <?= (string)$settings['academic_year_id'] === (string)$year['id'] ? 'selected' : '' ?>
                            >
                                <?= settingEscape($year['year_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="settings-card">
            <h5>معلومات التواصل</h5>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="phone">رقم الهاتف</label>
                    <input
                        class="form-control"
                        type="text"
                        id="phone"
                        name="phone"
                        maxlength="30"
                        value="<?= settingEscape($settings['phone']) ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="email">البريد الإلكتروني</label>
                    <input
                        class="form-control"
                        type="email"
                        id="email"
                        name="email"
                        maxlength="150"
                        value="<?= settingEscape($settings['email']) ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="website">الموقع الإلكتروني</label>
                    <input
                        class="form-control"
                        type="url"
                        id="website"
                        name="website"
                        maxlength="255"
                        placeholder="https://example.com"
                        value="<?= settingEscape($settings['website']) ?>"
                    >
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="address">عنوان المدرسة</label>
                    <textarea
                        class="form-control"
                        id="address"
                        name="address"
                        rows="2"
                        maxlength="1000"
                    ><?= settingEscape($settings['address']) ?></textarea>
                </div>
            </div>
        </div>

        <div class="settings-card">
            <h5>الشعار والعملة</h5>

            <div class="row g-3 align-items-start">
                <div class="col-md-6">
                    <label class="form-label" for="school_logo">شعار المدرسة</label>
                    <input
                        class="form-control"
                        type="file"
                        id="school_logo"
                        name="school_logo"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    >
                    <small class="text-secondary">
                        الصيغ المقبولة JPG وPNG وWEBP، بحد أقصى 2 ميجابايت.
                    </small>

                    <?php if (!empty($settings['logo_path'])): ?>
                        <div class="mt-3">
                            <img
                                class="logo-preview"
                                src="<?= settingEscape($settings['logo_path']) ?>"
                                alt="شعار المدرسة الحالي"
                            >
                            <p class="text-secondary small mt-2 mb-0">
                                الشعار الحالي
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="currency">العملة</label>
                    <select class="form-select" id="currency" name="currency">
                        <option value="SDG" <?= $settings['currency'] === 'SDG' ? 'selected' : '' ?>>
                            الجنيه السوداني (SDG)
                        </option>
                        <option value="SAR" <?= $settings['currency'] === 'SAR' ? 'selected' : '' ?>>
                            الريال السعودي (SAR)
                        </option>
                        <option value="EGP" <?= $settings['currency'] === 'EGP' ? 'selected' : '' ?>>
                            الجنيه المصري (EGP)
                        </option>
                        <option value="USD" <?= $settings['currency'] === 'USD' ? 'selected' : '' ?>>
                            الدولار الأمريكي (USD)
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-start gap-2">
            <button type="submit" class="btn settings-save">
                حفظ الإعدادات
            </button>

            <a href="index.php" class="btn btn-outline-secondary px-4">
                رجوع للرئيسية
            </a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
