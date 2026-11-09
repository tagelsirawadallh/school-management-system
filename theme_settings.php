<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';

$pageTitle = 'ألوان التطبيق';
$message = '';
$messageType = 'success';
$defaults = [
    'primary' => '#155e6b',
    'accent' => '#c49a5a',
    'background' => '#f3f6f8'
];

if (empty($_SESSION['theme_settings_csrf'])) {
    $_SESSION['theme_settings_csrf'] = bin2hex(random_bytes(32));
}

$colors = $defaults;
$relativeLuminance = static function (string $color): float {
    $channels = [
        hexdec(substr($color, 1, 2)) / 255,
        hexdec(substr($color, 3, 2)) / 255,
        hexdec(substr($color, 5, 2)) / 255
    ];
    $channels = array_map(
        static fn (float $channel): float => $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4,
        $channels
    );

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
};

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_theme_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            color_primary CHAR(7) NOT NULL DEFAULT '#155e6b',
            color_accent CHAR(7) NOT NULL DEFAULT '#c49a5a',
            color_background CHAR(7) NOT NULL DEFAULT '#f3f6f8',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        INSERT IGNORE INTO app_theme_settings
            (id, color_primary, color_accent, color_background)
        VALUES
            (1, '#155e6b', '#c49a5a', '#f3f6f8')
    ");

    $stmt = $pdo->query("
        SELECT color_primary, color_accent, color_background
        FROM app_theme_settings
        WHERE id = 1
        LIMIT 1
    ");
    $savedColors = $stmt->fetch();
    if ($savedColors) {
        $colors = [
            'primary' => $savedColors['color_primary'],
            'accent' => $savedColors['color_accent'],
            'background' => $savedColors['color_background']
        ];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['theme_settings_csrf'], $token)
        ) {
            $message = 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مرة أخرى.';
            $messageType = 'danger';
        } elseif (isset($_POST['reset_theme'])) {
            $colors = $defaults;
        } else {
            $submittedColors = [
                'primary' => $_POST['color_primary'] ?? '',
                'accent' => $_POST['color_accent'] ?? '',
                'background' => $_POST['color_background'] ?? ''
            ];

            foreach ($submittedColors as $color) {
                if (
                    !is_string($color) ||
                    preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1
                ) {
                    $message = 'يرجى اختيار ألوان صحيحة من لوحة الألوان.';
                    $messageType = 'danger';
                    break;
                }
            }

            if (
                $message === '' &&
                (1.05 / ($relativeLuminance($submittedColors['primary']) + 0.05)) < 4.5
            ) {
                $message = 'اختر لوناً أساسياً أغمق ليظل النص الأبيض على الأزرار واضحاً.';
                $messageType = 'danger';
            }

            if (
                $message === '' &&
                (($relativeLuminance($submittedColors['background']) + 0.05)
                    / ($relativeLuminance('#24343b') + 0.05)) < 4.5
            ) {
                $message = 'اختر لون خلفية أفتح لضمان وضوح النصوص.';
                $messageType = 'danger';
            }

            if ($message === '') {
                $colors = array_map('strtolower', $submittedColors);
            }
        }

        if ($messageType === 'success') {
            $update = $pdo->prepare("
                UPDATE app_theme_settings
                SET color_primary = :primary,
                    color_accent = :accent,
                    color_background = :background
                WHERE id = 1
            ");
            $update->execute([
                'primary' => $colors['primary'],
                'accent' => $colors['accent'],
                'background' => $colors['background']
            ]);
            $message = isset($_POST['reset_theme'])
                ? 'تمت استعادة الألوان الافتراضية.'
                : 'تم حفظ ألوان التطبيق بنجاح.';
        }
    }
} catch (PDOException $e) {
    error_log('Theme settings error: ' . $e->getMessage());
    http_response_code(500);
    exit('تعذر تحميل أو حفظ ألوان التطبيق. تحقق من صلاحيات قاعدة البيانات وحاول مرة أخرى.');
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    .theme-settings-page {
        max-width: 1000px;
        margin: 0 auto;
        padding: 18px 0 36px;
    }

    .theme-settings-card {
        padding: clamp(20px, 4vw, 32px);
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 18px;
        box-shadow: var(--shadow);
    }

    .theme-color-field {
        display: flex;
        align-items: center;
        gap: 16px;
        height: 100%;
        padding: 16px;
        background: var(--panel-2);
        border: 1px solid var(--border);
        border-radius: 14px;
    }

    .theme-color-field input[type="color"] {
        flex: 0 0 58px;
        width: 58px;
        height: 52px;
        padding: 4px;
        cursor: pointer;
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 10px;
    }

    .theme-preview {
        overflow: hidden;
        border: 1px solid var(--border);
        border-radius: 14px;
    }

    .theme-preview-header {
        padding: 18px;
        color: #fff;
        background: var(--preview-primary);
        font-weight: 700;
    }

    .theme-preview-body {
        padding: 20px;
        background: var(--preview-background);
    }

    .theme-preview-button {
        display: inline-block;
        margin-top: 12px;
        padding: 9px 16px;
        color: #fff;
        background: var(--preview-primary);
        border-radius: 8px;
        font-weight: 700;
    }

    .theme-preview-accent {
        width: 52px;
        height: 5px;
        margin-top: 12px;
        background: var(--preview-accent);
        border-radius: 5px;
    }
</style>

<div class="container-fluid theme-settings-page" dir="rtl">
    <div class="mb-4">
        <h1 class="h3 fw-bold mb-2">ألوان التطبيق</h1>
        <p class="text-muted mb-0">خصص ألوان النظام لتناسب هوية المدرسة.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>"
             role="alert">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post" action="theme_settings.php" id="theme-settings-form">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['theme_settings_csrf'], ENT_QUOTES, 'UTF-8') ?>">

        <section class="theme-settings-card mb-4">
            <h2 class="h5 fw-bold mb-3">اختيار الألوان</h2>
            <p class="text-muted mb-4">
                تتغير ألوان الأزرار والقائمة والروابط والخلفية في صفحات النظام.
            </p>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="theme-color-field" for="color_primary">
                        <input type="color" name="color_primary" id="color_primary"
                               value="<?= htmlspecialchars($colors['primary'], ENT_QUOTES, 'UTF-8') ?>">
                        <span>
                            <span class="d-block fw-bold">اللون الأساسي</span>
                            <span class="small text-muted">الأزرار والقائمة والروابط</span>
                        </span>
                    </label>
                </div>
                <div class="col-12 col-md-4">
                    <label class="theme-color-field" for="color_accent">
                        <input type="color" name="color_accent" id="color_accent"
                               value="<?= htmlspecialchars($colors['accent'], ENT_QUOTES, 'UTF-8') ?>">
                        <span>
                            <span class="d-block fw-bold">اللون المساعد</span>
                            <span class="small text-muted">لمسات وتفاصيل التصميم</span>
                        </span>
                    </label>
                </div>
                <div class="col-12 col-md-4">
                    <label class="theme-color-field" for="color_background">
                        <input type="color" name="color_background" id="color_background"
                               value="<?= htmlspecialchars($colors['background'], ENT_QUOTES, 'UTF-8') ?>">
                        <span>
                            <span class="d-block fw-bold">لون الخلفية</span>
                            <span class="small text-muted">خلفية صفحات التطبيق</span>
                        </span>
                    </label>
                </div>
            </div>
        </section>

        <section class="theme-settings-card mb-4"
                 id="theme-preview"
                 style="--preview-primary: <?= htmlspecialchars($colors['primary'], ENT_QUOTES, 'UTF-8') ?>; --preview-accent: <?= htmlspecialchars($colors['accent'], ENT_QUOTES, 'UTF-8') ?>; --preview-background: <?= htmlspecialchars($colors['background'], ENT_QUOTES, 'UTF-8') ?>;">
            <h2 class="h5 fw-bold mb-3">معاينة مباشرة</h2>
            <div class="theme-preview">
                <div class="theme-preview-header">اسم المدرسة</div>
                <div class="theme-preview-body">
                    مثال على شكل خلفية الصفحة ومحتواها
                    <div class="theme-preview-accent"></div>
                    <span class="theme-preview-button">زر أساسي</span>
                </div>
            </div>
        </section>

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary px-4">حفظ الألوان</button>
            <button type="submit" name="reset_theme" value="1"
                    class="btn btn-outline-secondary px-4">
                استعادة الألوان الافتراضية
            </button>
        </div>
    </form>
</div>

<script>
    (() => {
        const form = document.getElementById('theme-settings-form');
        const preview = document.getElementById('theme-preview');
        const controls = {
            primary: form.elements.color_primary,
            accent: form.elements.color_accent,
            background: form.elements.color_background
        };

        Object.entries(controls).forEach(([name, control]) => {
            control.addEventListener('input', () => {
                preview.style.setProperty(`--preview-${name}`, control.value);
            });
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
