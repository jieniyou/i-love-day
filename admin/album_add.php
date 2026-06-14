<?php
// 新版后台 - 新建相册（移动端优先）
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

// 确保数据库结构为最新（包含 keep_original_quality 等字段）
if (function_exists('migrate_schema_if_needed')) {
    migrate_schema_if_needed();
}

// 读取全局图片压缩开关，用于控制相册级别压缩开关是否可用
$imageOptimizeEnabled = get_setting('image_optimize_enabled', '1');

$auth = new Auth();
$auth->requireLogin();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();
// 确保相册权限表存在（用于控制另一半是否可编辑）
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS `album_permissions` (
            `album_id` int(11) NOT NULL COMMENT '相册ID',
            `allow_partner_edit` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许另一半编辑与上传',
            `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
            PRIMARY KEY (`album_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册权限表';
    ");
} catch (Exception $e) {
    // 表创建失败不影响其它逻辑，后续仅在表存在时写入权限
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isEncrypted = isset($_POST['is_encrypted']) ? 1 : 0;
    // 相册级图片压缩开关：勾选“本相册不应用压缩”时，保持原始画质（跳过主图压缩）
    $keepOriginal = isset($_POST['keep_original_quality']) ? 1 : 0;
    $allowPartnerEdit = isset($_POST['allow_partner_edit']) ? 1 : 0;

    if ($name === '') {
        $error = '请输入相册名称';
    } else {
        $coverImage = null;
        if (isset($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['cover'], 'albums');
            if (!empty($upload['success'])) {
                $coverImage = $upload['path'];
            } else {
                $error = $upload['message'] ?? '封面上传失败';
            }
        }

        if (!$error) {
            $data = [
                'user_id'     => $currentUser['id'],
                'name'        => $name,
                'description' => $description,
                'cover_image' => $coverImage,
                'keep_original_quality' => $keepOriginal,
                'is_encrypted'=> $isEncrypted,
                'created_at'  => date('Y-m-d H:i:s'),
            ];

            $albumId = $db->insert('albums', $data);

            if ($albumId) {
                // 写入/更新相册权限（若权限表创建失败，此处忽略错误）
                try {
                    $db->query("
                        INSERT INTO album_permissions (album_id, allow_partner_edit, updated_at)
                        VALUES (:album_id, :allow_partner_edit, :updated_at)
                        ON DUPLICATE KEY UPDATE
                            allow_partner_edit = VALUES(allow_partner_edit),
                            updated_at = VALUES(updated_at)
                    ", [
                        'album_id'          => $albumId,
                        'allow_partner_edit'=> $allowPartnerEdit,
                        'updated_at'        => date('Y-m-d H:i:s'),
                    ]);
                } catch (Exception $e) {
                    // 忽略权限表写入失败，保持创建相册主流程可用
                }
                header('Location: /admin/albums.php?success=相册创建成功');
                exit;
            } else {
                $error = '创建失败，请重试';
            }
        }
    }
}

$adminPage = 'albums';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>新建相册</h1>
        <p>为一组特别的照片创建一个家</p>
    </section>

    <?php if ($error): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(248,113,113,0.05);border:1px solid rgba(248,113,113,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#b91c1c;font-size:0.9rem;">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo e($error); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="admin-card" novalidate>
        <?php echo csrf_field(); ?>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">相册名称 *</label>
            <input
                type="text"
                name="name"
                value="<?php echo e($_POST['name'] ?? ''); ?>"
                style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">描述</label>
            <textarea
                name="description"
                style="width:100%;min-height:80px;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;resize:vertical;"><?php echo e($_POST['description'] ?? ''); ?></textarea>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">封面图片</label>
            <input type="file" name="cover" accept="image/*" style="font-size:0.85rem;">
            <?php
            $maxUploadBytesCover = get_max_upload_size_bytes();
            $maxUploadMbCover    = round($maxUploadBytesCover / 1024 / 1024, 1);
            ?>
            <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                支持 JPG / PNG / GIF / WebP，建议横向图片，单文件最大约 <?php echo $maxUploadMbCover; ?>MB。
            </div>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label class="switch">
                <input
                    type="checkbox"
                    name="allow_partner_edit"
                    value="1"
                    <?php echo ($_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['allow_partner_edit'])) ? 'checked' : ''; ?>>
                <span class="switch-track">
                    <span class="switch-thumb"></span>
                </span>
                <span class="switch-label">允许另一半编辑与上传（默认开启）</span>
            </label>
        </div>

        <div class="form-group" style="margin-bottom:0.75rem;">
            <label class="switch<?php echo ($imageOptimizeEnabled === '1') ? '' : ' switch-disabled'; ?>">
                <input
                    type="checkbox"
                    name="keep_original_quality"
                    value="1"
                    <?php echo isset($_POST['keep_original_quality']) ? 'checked' : ''; ?>
                    <?php echo ($imageOptimizeEnabled === '1') ? '' : 'disabled'; ?>>
                <span class="switch-track">
                    <span class="switch-thumb"></span>
                </span>
                <span class="switch-label">不对该本相册应用图片压缩</span>
            </label>
            <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                <?php if ((string)$imageOptimizeEnabled === '1'): ?>
                    关闭时，本相册中的新图片将按照全局“图片压缩与 WebP 优化”规则进行压缩（推荐）。开启后，本相册的新图片会跳过主图压缩，仅生成缩略图与 WebP，更偏向保留原始画质，适合少量精修照片。
                <?php else: ?>
                    当前已在系统设置中关闭图片压缩，本选项暂不生效。如需单独控制，请先在“系统设置 → 上传与其他”中开启图片压缩。
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group" style="margin-bottom:1rem;">
            <label class="switch">
                <input type="checkbox" name="is_encrypted" value="1" <?php echo isset($_POST['is_encrypted']) ? 'checked' : ''; ?>>
                <span class="switch-track">
                    <span class="switch-thumb"></span>
                </span>
                <span class="switch-label">加密相册（仅双方可见）</span>
            </label>
        </div>

        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i>
                <span>创建相册</span>
            </button>
            <a href="/admin/albums.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i>
                <span>返回列表</span>
            </a>
        </div>
    </form>

<?php include __DIR__ . '/footer.php'; ?>
