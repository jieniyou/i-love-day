<?php
// 新版后台 - 系统设置（移动端优先）
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

$auth = new Auth();
$auth->requireLogin();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();

$error   = '';
$success = '';

// 读取当前设置
$settings     = $db->fetchAll("SELECT `key`, `value` FROM settings");
$settingsData = [];
foreach ($settings as $setting) {
    $settingsData[$setting['key']] = $setting['value'];
}

// PRG 成功提示
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $success = '设置保存成功';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // 如果已有首页大图并使用旧目录 banners，则删除旧文件并清空设置，让用户重新上传
    if (!empty($settingsData['home_banner_image']) && strpos($settingsData['home_banner_image'], '/banners/') !== false) {
        if (strpos($settingsData['home_banner_image'], UPLOAD_URL) === 0) {
            $oldPath = str_replace(UPLOAD_URL, '', $settingsData['home_banner_image']);
            deleteFile($oldPath);
        }
        $_POST['settings']['home_banner_image'] = '';
        $settingsData['home_banner_image'] = '';
    }

    // 处理首页大图上传（新目录 hero_covers，避免命中广告拦截规则）
    if (isset($_FILES['home_banner_image']) && $_FILES['home_banner_image']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadFile($_FILES['home_banner_image'], 'hero_covers');
        if (!empty($upload['success'])) {
            // 删除旧的大图文件（无论是 URL 还是相对路径）
            if (!empty($settingsData['home_banner_image'])) {
                $oldPath = $settingsData['home_banner_image'];
                if (strpos($oldPath, UPLOAD_URL) === 0) {
                    $oldPath = str_replace(UPLOAD_URL, '', $oldPath);
                }
                deleteFile($oldPath);
            }
            // 只保存相对路径，便于站点迁移
            $_POST['settings']['home_banner_image'] = $upload['path'];
        } else {
            $error = $upload['message'] ?? '首页大图上传失败';
        }
    }

    if (!$error && !empty($_POST['settings']) && is_array($_POST['settings'])) {
        // 规范化布尔开关：未勾选时明确写入 '0'
        $booleanKeys = [
            'image_optimize_enabled',
            'video_upload_ignore_site_limit',
            'turnstile_enabled',
        ];
        foreach ($booleanKeys as $boolKey) {
            if (!isset($_POST['settings'][$boolKey])) {
                $_POST['settings'][$boolKey] = '0';
            }
        }

        // 上传大小设置单独校验（单位：MB，范围 1~50）
        if (isset($_POST['settings']['max_upload_size_mb'])) {
            $maxUploadMb = (int) $_POST['settings']['max_upload_size_mb'];
            if ($maxUploadMb < 1 || $maxUploadMb > 50) {
                $error = '单文件上传大小必须在 1MB 到 50MB 之间';
            } else {
                $_POST['settings']['max_upload_size_mb'] = (string) $maxUploadMb;
            }
        }

        // Turnstile：启用时必须同时填写 Site Key 与 Secret Key
        if (!$error && isset($_POST['settings']['turnstile_enabled']) && $_POST['settings']['turnstile_enabled'] === '1') {
            $tsSiteKey   = trim((string)($_POST['settings']['turnstile_site_key'] ?? ''));
            $tsSecretKey = trim((string)($_POST['settings']['turnstile_secret_key'] ?? ''));
            if ($tsSiteKey === '' || $tsSecretKey === '') {
                $error = '启用 Turnstile 前，请先填写完整的 Site Key 和 Secret Key。';
            } else {
                $_POST['settings']['turnstile_site_key']   = $tsSiteKey;
                $_POST['settings']['turnstile_secret_key'] = $tsSecretKey;
            }
        }
    }

    if (!$error && !empty($_POST['settings']) && is_array($_POST['settings'])) {
        // 恋爱开始时间：支持精确到秒（datetime-local），统一转换为 "Y-m-d H:i:s" 存入数据库
        if (array_key_exists('love_date', $_POST['settings'])) {
            $loveDateInput = trim((string) $_POST['settings']['love_date']);
            if ($loveDateInput === '') {
                $_POST['settings']['love_date'] = '';
            } else {
                // 浏览器 datetime-local 通常为 "Y-m-dTH:i" 或 "Y-m-dTH:i:s"
                // 同时兼容旧格式 "Y-m-d" / "Y-m-d H:i:s"
                $normalized = str_replace(' ', 'T', $loveDateInput);
                $dt = date_create($normalized);
                if ($dt instanceof DateTime) {
                    $_POST['settings']['love_date'] = $dt->format('Y-m-d H:i:s');
                } else {
                    $error = '恋爱开始时间格式不正确，请重新选择。';
                }
            }
        }
    }

    if (!$error && !empty($_POST['settings']) && is_array($_POST['settings'])) {
        foreach ($_POST['settings'] as $key => $value) {
            $existing = $db->fetch("SELECT id FROM settings WHERE `key` = :key", ['key' => $key]);

            if ($existing) {
                $db->update('settings', [
                    'value'      => $value,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], '`key` = :key', ['key' => $key]);
            } else {
                $db->insert('settings', [
                    'key'        => $key,
                    'value'      => $value,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    if (!$error) {
        header('Location: settings.php?success=1');
        exit;
    }

    // 有错误则重新加载最新设置用于展示
    $settings     = $db->fetchAll("SELECT `key`, `value` FROM settings");
    $settingsData = [];
    foreach ($settings as $setting) {
        $settingsData[$setting['key']] = $setting['value'];
    }
}

$adminPage = 'settings';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>系统设置</h1>
        <p>管理站点基础信息、首页展示和备案信息</p>
    </section>

    <?php if ($error): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(248,113,113,0.05);border:1px solid rgba(248,113,113,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#b91c1c;font-size:0.9rem;">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo e($error); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(34,197,94,0.05);border:1px solid rgba(34,197,94,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#15803d;font-size:0.9rem;">
                <i class="fas fa-check-circle"></i>
                <span><?php echo e($success); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" novalidate>
        <?php echo csrf_field(); ?>

        <section class="admin-grid" style="margin-bottom:0.75rem;">
            <div class="admin-card">
                <div class="admin-card-header">
                    <div>
                        <div class="admin-card-title">基础信息</div>
                        <div class="admin-card-subtitle">站点标题与描述、登录安全</div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">网站标题</label>
                    <input
                        type="text"
                        name="settings[site_title]"
                        value="<?php echo e($settingsData['site_title'] ?? SITE_NAME); ?>"
                        style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.6);font-size:0.9rem;">
                </div>

                <div class="form-group">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">网站描述</label>
                    <textarea
                        name="settings[site_description]"
                        style="width:100%;min-height:80px;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.6);font-size:0.9rem;resize:vertical;"><?php echo e($settingsData['site_description'] ?? ''); ?></textarea>
                </div>

                <hr style="border:none;border-top:1px dashed rgba(148,163,184,0.5);margin:0.75rem 0;">

                <div class="form-group" style="margin-bottom:0.5rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Cloudflare Turnstile 登录验证</label>
                    <?php
                    $turnstileEnabled   = $settingsData['turnstile_enabled'] ?? '0';
                    $turnstileSiteKey   = $settingsData['turnstile_site_key'] ?? '';
                    $turnstileSecretKey = $settingsData['turnstile_secret_key'] ?? '';
                    ?>
                    <label class="switch">
                        <input
                            type="checkbox"
                            name="settings[turnstile_enabled]"
                            value="1"
                            <?php echo $turnstileEnabled === '1' ? 'checked' : ''; ?>>
                        <span class="switch-track">
                            <span class="switch-thumb"></span>
                        </span>
                        <span class="switch-label">启用 Cloudflare Turnstile 登录验证</span>
                    </label>
                    <p style="margin:0.25rem 0 0;font-size:0.78rem;color:var(--text-light);">
                        启用后，登录 / 注册时需要通过 Turnstile 验证，建议配合 Cloudflare 保护站点安全。
                    </p>
                    <p style="margin:0.25rem 0 0;font-size:0.78rem;color:var(--text-light);">
                        启用 Cloudflare Turnstile 后，可以在很大程度上提升登录安全性，有效防止脚本暴力尝试登录。但由于 Cloudflare 在中国大陆的访问速度和稳定性不友好，请在确认当前网络环境能够正常访问 Cloudflare 后再开启此功能，否则可能会导致登录页验证码无法加载，从而无法正常登录后台。
                    </p>
                </div>

                <div class="form-group" style="margin-bottom:0.5rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Turnstile Site Key</label>
                    <input
                        type="text"
                        name="settings[turnstile_site_key]"
                        value="<?php echo e($turnstileSiteKey); ?>"
                        placeholder="在 Cloudflare Turnstile 控制台中获取"
                        style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.6);font-size:0.9rem;">
                </div>

                <div class="form-group">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">Turnstile Secret Key</label>
                    <input
                        type="text"
                        name="settings[turnstile_secret_key]"
                        value="<?php echo e($turnstileSecretKey); ?>"
                        placeholder="在 Cloudflare Turnstile 控制台中获取（请妥善保管）"
                        style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.6);font-size:0.9rem;">
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        Secret Key 仅用于服务器端验证，切勿公开。若不启用 Turnstile，可留空。
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div>
                        <div class="admin-card-title">恋爱与首页</div>
                        <div class="admin-card-subtitle">恋爱开始日期与首页大图</div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">恋爱开始时间</label>
                    <?php
                    $loveDateRaw = $settingsData['love_date'] ?? '';
                    $loveDateValue = '';
                    if ($loveDateRaw !== '') {
                        // 兼容旧数据：仅日期 或 带时间的 "Y-m-d H:i:s"
                        $normalized = str_replace(' ', 'T', $loveDateRaw);
                        $dt = date_create($normalized);
                        if ($dt instanceof DateTime) {
                            $loveDateValue = $dt->format('Y-m-d\TH:i:s');
                        }
                    }
                    ?>
                    <input
                        type="datetime-local"
                        step="1"
                        name="settings[love_date]"
                        value="<?php echo e($loveDateValue); ?>"
                        style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.6);font-size:0.9rem;">
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        用于计算“在一起多少天”，支持精确到秒。留空时按当前日期开始计算。
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">首页大图地址</label>
                    <?php
                    $hasHomeBannerSetting = array_key_exists('home_banner_image', $settingsData);
                    // 若尚未保存过该设置，则展示静态默认图路径，避免依赖 uploads 目录
                    $homeBannerSetting = $hasHomeBannerSetting ? $settingsData['home_banner_image'] : '/assets/images/default_hero.jpg';
                    ?>
                    <input
                        type="text"
                        name="settings[home_banner_image]"
                        value="<?php echo e($homeBannerSetting); ?>"
                        placeholder="图片 URL / 外链图片地址"
                        style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.6);font-size:0.9rem;">
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        如果同时上传了图片，将优先使用上传的新图片。
                    </div>
                </div>

                <div class="form-group">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">首页大图上传</label>
                    <input type="file" name="home_banner_image" accept="image/*" style="font-size:0.85rem;">
                    <?php
                    $bannerMaxBytes = get_max_upload_size_bytes();
                    $bannerMaxMb    = round($bannerMaxBytes / 1024 / 1024, 1);
                    ?>
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        建议使用横向大图，宽度不小于 1200 像素，单文件最大约 <?php echo $bannerMaxMb; ?>MB。
                    </div>
                </div>
            </div>
        </section>

        <section class="admin-grid">
            <div class="admin-card">
                <div class="admin-card-header">
                    <div>
                        <div class="admin-card-title">上传与其他</div>
                        <div class="admin-card-subtitle">上传限制、图片压缩、备案号等信息</div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">图片压缩与 WebP 优化</label>
                    <?php
                    // 默认开启图片压缩与 WebP 优化
                    $imageOptimizeEnabled = $settingsData['image_optimize_enabled'] ?? '1';
                    ?>
                    <label class="switch">
                        <input
                            type="checkbox"
                            name="settings[image_optimize_enabled]"
                            value="1"
                            <?php echo $imageOptimizeEnabled === '1' ? 'checked' : ''; ?>>
                        <span class="switch-track">
                            <span class="switch-thumb"></span>
                        </span>
                        <span class="switch-label">启用图片压缩与 WebP 优化（推荐）</span>
                    </label>
                    <p style="margin:0.25rem 0 0;font-size:0.78rem;color:var(--text-light);">
                        开启后，新上传的图片会自动按比例缩小（长边约 2560 像素）并进行适度压缩，同时为 JPEG/PNG 生成一份 WebP 副本，用于前台优先加载以减轻带宽压力。
                        仅对之后上传的图片生效，已有图片不受影响。
                    </p>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">单文件最大上传大小（MB）</label>
                    <?php
                    $maxUploadSizeMb = $settingsData['max_upload_size_mb'] ?? '';
                    if ($maxUploadSizeMb === '' || !is_numeric($maxUploadSizeMb)) {
                        $maxUploadSizeMb = 15;
                    }

                    // 计算服务器层面的硬上限（来自 php.ini）
                    $serverUploadLimitMb = null;
                    $serverLimits = [];
                    foreach (['upload_max_filesize', 'post_max_size'] as $iniKey) {
                        $val = ini_get($iniKey);
                        if ($val !== false && function_exists('parse_php_size_to_bytes')) {
                            $serverLimits[] = parse_php_size_to_bytes($val);
                        }
                    }
                    if (!empty($serverLimits)) {
                        $serverUploadLimitMb = round(min($serverLimits) / 1024 / 1024, 1);
                    }
                    ?>
                    <input
                        type="number"
                        name="settings[max_upload_size_mb]"
                        min="1"
                        max="50"
                        value="<?php echo e($maxUploadSizeMb); ?>"
                        style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.6);font-size:0.9rem;">
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        默认 15MB，取值范围 1～50MB。实际生效值不能超过服务器的 <code>upload_max_filesize</code> 和 <code>post_max_size</code>。
                        <?php if ($serverUploadLimitMb !== null): ?>
                            当前服务器单个上传文件硬上限约 <?php echo $serverUploadLimitMb; ?>MB。
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">视频上传大小限制</label>
                    <?php
                    $videoIgnoreLimit = $settingsData['video_upload_ignore_site_limit'] ?? '0';
                    ?>
                    <label class="switch">
                        <input
                            type="checkbox"
                            name="settings[video_upload_ignore_site_limit]"
                            value="1"
                            <?php echo $videoIgnoreLimit === '1' ? 'checked' : ''; ?>>
                        <span class="switch-track">
                            <span class="switch-thumb"></span>
                        </span>
                        <span class="switch-label">视频上传仅受服务器限制（忽略上面的站点单文件大小限制）</span>
                    </label>
                    <p style="margin:0.25rem 0 0;font-size:0.78rem;color:var(--text-light);">
                        开启后，视频上传不再受“单文件最大上传大小（MB）”限制，仅受服务器 <code>upload_max_filesize</code> 与 <code>post_max_size</code> 控制。图片等其它上传仍按上面的站点限制执行。
                    </p>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">网站底部版权信息</label>
                    <input
                        type="text"
                        name="settings[site_footer_copyright]"
                        value="<?php echo e($settingsData['site_footer_copyright'] ?? ''); ?>"
                        placeholder="例如：Copyright © <?php echo date('Y'); ?> 某某情侣 All Rights Reserved."
                        style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.6);font-size:0.9rem;">
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        留空时，将使用默认的版权信息（站点名称 + 年份）。可以填写纯文字内容。
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">网站备案号</label>
                    <input
                        type="text"
                        name="settings[icp_beian]"
                        value="<?php echo e($settingsData['icp_beian'] ?? ''); ?>"
                        placeholder="例如：粤ICP备2025079898号-1"
                        style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.6);font-size:0.9rem;">
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        填写后会显示在网站底部，可点击跳转备案查询页面。留空则不显示。
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">统计代码（可选）</label>
                    <textarea
                        name="settings[site_analytics_code]"
                        placeholder="在这里粘贴统计平台提供的代码，例如 Google Analytics、百度统计等"
                        style="width:100%;min-height:120px;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.6);font-size:0.86rem;resize:vertical;"><?php echo e($settingsData['site_analytics_code'] ?? ''); ?></textarea>
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        支持完整的 &lt;script&gt;、&lt;noscript&gt; 等代码片段，保存后会自动插入到网站所有页面底部（&lt;/body&gt; 之前）。
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div>
                        <div class="admin-card-title">保存设置</div>
                        <div class="admin-card-subtitle">确认无误后保存</div>
                    </div>
                </div>

                <p style="font-size:0.85rem;color:var(--text-light);margin-bottom:0.75rem;">
                    保存后，新设置会立即生效。涉及首页大图等资源的修改，可能需要刷新前台页面才能看到最新效果。
                </p>

                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
                    <i class="fas fa-save"></i>
                    <span>保存设置</span>
                </button>

                <div style="margin-top:0.5rem;font-size:0.78rem;color:var(--text-light);text-align:center;">
                    如果保存设置后出现异常，可以使用底部“旧版后台”入口回到旧设置页面排查。
                </div>
            </div>
        </section>
    </form>

<?php include __DIR__ . '/footer.php'; ?>
