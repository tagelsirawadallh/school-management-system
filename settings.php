
<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once 'config/database.php';

$pageTitle = 'إعدادات المدرسة';
$message = '';
$messageType = 'success';

function settingValue($settings, $key) {
    return htmlspecialchars(
        (string)($settings[$key] ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$stmt = $pdo->query(
    "SELECT * FROM school_settings ORDER BY id ASC LIMIT 1"
);
$settings = $stmt->fetch();

$years = $pdo->query(
    "SELECT id, year_name FROM academic_years ORDER BY id DESC"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schoolName = trim($_POST['school_name'] ?? '');

    if ($schoolName === '') {
        $message = 'يرجى إدخال اسم المدرسة بالعربية.';
        $messageType = 'danger';
    } else {
        $data = [
            'school_name' => $schoolName,
            'school_name_en' => trim($_POST['school_name_en'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'website' => trim($_POST['website'] ?? ''),
            'currency' => $_POST['currency'] ?? 'SDG',
            'academic_year_id' =>
                ($_POST['academic_year_id'] ?? '') !== ''
                    ? (int)$_POST['academic_year_id']
                    : null,
            'principal_name' => trim($_POST['principal_name'] ?? '')
        ];

        $logoPath = $settings['logo_path'] ?? null;
        $newLogoPath = null;
        $error = '';

        // التحقق من البريد الإلكتروني
        if (
            $data['email'] !== '' &&
            !filter_var($data['email'], FILTER_VALIDATE_EMAIL)
        ) {
            $error = 'صيغة البريد الإلكتروني غير صحيحة.';
        }

        // التحقق من الموقع الإلكتروني
        if (
            $error === '' &&
            $data['website'] !== '' &&
            !filter_var($data['website'], FILTER_VALIDATE_URL)
        ) {
            $error = 'رابط الموقع الإلكتروني غير صحيح.';
        }

        // معالجة رفع الشعار
        if (
            $error === '' &&
            isset($_FILES['logo']) &&
            $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE
        ) {
            $file = $_FILES['logo'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'حدث خطأ أثناء رفع الشعار.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error = 'حجم الشعار يجب ألا يتجاوز 2 ميجابايت.';
            } else {
                $imageInfo = @getimagesize($file['tmp_name']);
                $mime = $imageInfo['mime'] ?? '';

                $allowedTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (
                    $imageInfo === false ||
                    !isset($allowedTypes[$mime])
                ) {
                    $error = 'يسمح فقط بصور JPG وPNG وWEBP.';
                } else {
                    $uploadDir = __DIR__ . '/uploads/school/';

                    if (
                        !is_dir($uploadDir) &&
                        !mkdir($uploadDir, 0755, true)
                    ) {
                        $error = 'تعذر إنشاء مجلد الشعار.';
                    } else {
                        $fileName = bin2hex(random_bytes(16))
                            . '.' . $allowedTypes[$mime];

                        $destination = $uploadDir . $fileName;

                        if (
                            move_uploaded_file(
                                $file['tmp_name'],
                                $destination
                            )
                        ) {
                            $newLogoPath = 'uploads/school/' . $fileName;
                            $logoPath = $newLogoPath;
                        } else {
                            $error = 'تعذر حفظ الشعار في المجلد.';
                        }
                    }
                }
            }
        }

        if ($error !== '') {
            $message = $error;
            $messageType = 'danger';
        } else {
            $data['logo_path'] = $logoPath;

            try {
                if ($settings) {
                    $data['id'] = $settings['id'];

                    $stmt = $pdo->prepare("
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
                            principal_name = :principal_name
                        WHERE id = :id
                    ");
                    $stmt->execute($data);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO school_settings (
                            school_name, school_name_en, logo_path,
                            address, phone, email, website, currency,
                            academic_year_id, principal_name
                        ) VALUES (
                            :school_name, :school_name_en, :logo_path,
                            :address, :phone, :email, :website, :currency,
                            :academic_year_id, :principal_name
                        )
                    ");
                    $stmt->execute($data);
                }

                // حذف الشعار القديم بعد نجاح حفظ الشعار الجديد
                if ($newLogoPath && !empty($settings['logo_path'])) {
                    $oldPath = __DIR__ . '/' . $settings['logo_path'];

                    if (
                        is_file($oldPath) &&
                        realpath(dirname($oldPath)) ===
                        realpath(__DIR__ . '/uploads/school')
                    ) {
                        @unlink($oldPath);
                    }
                }

                $message = 'تم حفظ إعدادات المدرسة بنجاح.';
                $messageType = 'success';

                $stmt = $pdo->query(
                    "SELECT * FROM school_settings ORDER BY id ASC LIMIT 1"
                );
                $settings = $stmt->fetch();

            } catch (PDOException $e) {
                error_log($e->getMessage());

                if ($newLogoPath) {
                    $newFile = __DIR__ . '/' . $newLogoPath;
                    if (is_file($newFile)) {
                        @unlink($newFile);
                    }
                }

                $message = 'حدث خطأ أثناء حفظ الإعدادات.';
                $messageType = 'danger';
            }
        }
    }
}

require_once 'includes/header.php';
?>

<div class="container-fluid">
    <h3 class="fw-bold mb-2">إعدادات المدرسة</h3>
    <p class="text-secondary mb-4">
        إدارة بيانات المدرسة وشعارها
    </p>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= $messageType ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="settings.php"
          enctype="multipart/form-data">

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold py-3">
                البيانات الأساسية
            </div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">
                            اسم المدرسة بالعربية *
                        </label>
                        <input type="text" name="school_name"
                               class="form-control" required maxlength="200"
                               value="<?= settingValue($settings, 'school_name') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            اسم المدرسة بالإنجليزية
                        </label>
                        <input type="text" name="school_name_en"
                               class="form-control" maxlength="200"
                               value="<?= settingValue($settings, 'school_name_en') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">اسم المدير</label>
                        <input type="text" name="principal_name"
                               class="form-control" maxlength="150"
                               value="<?= settingValue($settings, 'principal_name') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" name="phone"
                               class="form-control" maxlength="30"
                               value="<?= settingValue($settings, 'phone') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">البريد الإلكتروني</label>
                        <input type="email" name="email"
                               class="form-control" maxlength="150"
                               value="<?= settingValue($settings, 'email') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">الموقع الإلكتروني</label>
                        <input type="url" name="website"
                               class="form-control" maxlength="255"
                               placeholder="https://example.com"
                               value="<?= settingValue($settings, 'website') ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">عنوان المدرسة</label>
                        <textarea name="address" class="form-control"
                                  rows="3"><?= settingValue($settings, 'address') ?></textarea>
                    </div>

                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold py-3">
                شعار المدرسة
            </div>

            <div class="card-body">
                <?php if (!empty($settings['logo_path'])): ?>
                    <div class="mb-3">
                        <p class="text-secondary">الشعار الحالي:</p>
                        <img
                            src="<?= settingValue($settings, 'logo_path') ?>"
                            alt="شعار المدرسة"
                            style="max-width:140px;max-height:140px;object-fit:contain;"
                            class="border rounded p-2"
                        >
                    </div>
                <?php endif; ?>

                <label class="form-label">اختر صورة الشعار</label>
                <input type="file" name="logo" class="form-control"
                       accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">

                <small class="text-secondary">
                    الصيغ المسموحة: JPG وPNG وWEBP، بحد أقصى 2 ميجابايت.
                    اترك الحقل فارغاً للاحتفاظ بالشعار الحالي.
                </small>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold py-3">
                الإعدادات الأكاديمية والمالية
            </div>

            <div class="card-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">العملة</label>
                        <select name="currency" class="form-select" required>
                            <?php
                            $currencies = [
                                'SDG' => 'الجنيه السوداني (SDG)',
                                'SAR' => 'الريال السعودي (SAR)',
                                'USD' => 'الدولار الأمريكي (USD)',
                                'EGP' => 'الجنيه المصري (EGP)'
                            ];

                            $selectedCurrency = $settings['currency'] ?? 'SDG';

                            foreach ($currencies as $code => $label):
                            ?>
                                <option value="<?= $code ?>"
                                    <?= $selectedCurrency === $code ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">السنة الدراسية الحالية</label>
                        <select name="academic_year_id" class="form-select">
                            <option value="">-- اختر السنة الدراسية --</option>

                            <?php foreach ($years as $year): ?>
                                <option value="<?= (int)$year['id'] ?>"
                                    <?= (string)($settings['academic_year_id'] ?? '') ===
                                        (string)$year['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($year['year_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary px-4">
            حفظ الإعدادات
        </button>

        <a href="index.php" class="btn btn-outline-secondary">
            رجوع للرئيسية
        </a>
    </form>
</div>

<?php require_once 'includes/footer.php'; ?>
