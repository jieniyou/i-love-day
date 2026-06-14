<?php
// 新版后台 - 相册列表（移动端优先）
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

$auth = new Auth();
$auth->requireLogin();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$partner     = $auth->getPartner();

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
    // 表创建失败时，后续保持默认“允许另一半编辑”行为
}

// 删除相册：通过 POST + CSRF 处理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    require_csrf();
    $id          = intval($_POST['delete_id']);
    $deleteFiles = isset($_POST['delete_files']);

    if ($id > 0) {
        // 权限校验：创建者永远可删除；另一半仅在允许编辑时可删除
        $albumRow = null;
        try {
            $albumRow = $db->fetch(
                "SELECT a.*, COALESCE(ap.allow_partner_edit, 1) AS allow_partner_edit
                 FROM albums a
                 LEFT JOIN album_permissions ap ON ap.album_id = a.id
                 WHERE a.id = :id",
                ['id' => $id]
            );
        } catch (Exception $e) {
            // 如果权限表不存在或查询失败，则退回只查 albums 表
            $albumRow = $db->fetch(
                "SELECT a.* FROM albums a WHERE a.id = :id",
                ['id' => $id]
            );
        }

        if (!$albumRow) {
            header('Location: albums.php?error=' . urlencode('相册不存在或已被删除'));
            exit;
        }

        $isOwner = $albumRow['user_id'] == $currentUser['id'];
        $partnerId = $partner['id'] ?? null;
        $isPartnerUser = $partnerId && $albumRow['user_id'] == $partnerId;
        // 若数据库中尚未添加 allow_partner_edit 字段，则默认为允许另一半编辑
        $allowPartnerEdit = isset($albumRow['allow_partner_edit']) ? (int) $albumRow['allow_partner_edit'] : 1;
        $canEditAlbum = $isOwner || ($isPartnerUser && $allowPartnerEdit);

        if (!$canEditAlbum) {
            header('Location: albums.php?error=' . urlencode('你没有权限删除这个相册'));
            exit;
        }

        if ($deleteFiles) {
            // 删除当前相册下所有图片及其衍生文件（缩略图 / WebP 等）
            try {
                $images = $db->fetchAll(
                    "SELECT id, image_path, thumbnail_path FROM album_images WHERE album_id = :album_id",
                    ['album_id' => $id]
                );
            } catch (Exception $e) {
                $images = [];
            }

            if (!empty($images)) {
                foreach ($images as $image) {
                    if (!empty($image['image_path'])) {
                        // 删除主图及同名 WebP
                        deleteFile($image['image_path']);
                    }
                    if (!empty($image['thumbnail_path'])) {
                        // 删除缩略图及同名 WebP
                        deleteFile($image['thumbnail_path']);
                    }

                    // 同步删除上传者映射记录（若表存在）
                    if (!empty($image['id'])) {
                        try {
                            $db->delete('album_image_uploads', 'image_id = :image_id', ['image_id' => $image['id']]);
                        } catch (Exception $e) {
                            // 忽略映射表删除失败
                        }
                    }
                }
            }

            // 删除当前相册下所有视频及其封面 / 缩略图
            try {
                $videos = $db->fetchAll(
                    "SELECT video_path, poster_path FROM album_videos WHERE album_id = :album_id",
                    ['album_id' => $id]
                );
            } catch (Exception $e) {
                $videos = [];
            }

            if (!empty($videos)) {
                foreach ($videos as $video) {
                    if (!empty($video['video_path'])) {
                        deleteFile($video['video_path']);
                    }
                    if (!empty($video['poster_path'])) {
                        // 删除封面图本身及同名 WebP
                        deleteFile($video['poster_path']);

                        // 同时清理封面图所在目录下的 thumbs 缩略图（若存在）
                        $posterDir  = trim(dirname($video['poster_path']), '/');
                        $posterFile = basename($video['poster_path']);
                        if ($posterDir !== '' && $posterFile !== '') {
                            $thumbRelative = $posterDir . '/thumbs/' . $posterFile;
                            deleteFile($thumbRelative);
                        }
                    }
                }
            }

            // 删除相册封面及其缩略图（若有）
            if (!empty($albumRow['cover_image'])) {
                deleteFile($albumRow['cover_image']);

                $coverDir  = trim(dirname($albumRow['cover_image']), '/');
                $coverFile = basename($albumRow['cover_image']);
                if ($coverDir !== '' && $coverFile !== '') {
                    $coverThumbRelative = $coverDir . '/thumbs/' . $coverFile;
                    deleteFile($coverThumbRelative);
                }
            }

            // 兜底清理：删除 uploads/albums/{id} 目录下残留的所有文件和空文件夹
            $albumUploadDir = rtrim(UPLOAD_DIR, '/\\') . '/albums/' . (int) $id;
            if (is_dir($albumUploadDir)) {
                $items = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($albumUploadDir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($items as $item) {
                    /** @var SplFileInfo $item */
                    if ($item->isDir()) {
                        @rmdir($item->getPathname());
                    } else {
                        @unlink($item->getPathname());
                    }
                }
                @rmdir($albumUploadDir);
            }
        }

        $db->delete('album_images', 'album_id = :album_id', ['album_id' => $id]);
        // 同步清理相册视频记录（文件已在上面删除）
        try {
            $db->delete('album_videos', 'album_id = :album_id', ['album_id' => $id]);
        } catch (Exception $e) {
            // 忽略相册视频表删除失败
        }
        $db->delete('albums', 'id = :id', ['id' => $id]);
        // 同步删除相册权限记录（若表存在）
        try {
            $db->delete('album_permissions', 'album_id = :album_id', ['album_id' => $id]);
        } catch (Exception $e) {
            // 忽略删除权限记录失败
        }

        header('Location: albums.php?success=' . urlencode('删除成功'));
        exit;
    }
}

// 获取相册列表
try {
    $albums = $db->fetchAll(
        "SELECT a.*,
                u.nickname AS creator_nickname,
                u.username AS creator_username,
                COALESCE(ap.allow_partner_edit, 1) AS allow_partner_edit,
                (SELECT COUNT(*) FROM album_images WHERE album_id = a.id) AS image_count
         FROM albums a
         LEFT JOIN users u ON a.user_id = u.id
         LEFT JOIN album_permissions ap ON ap.album_id = a.id
         ORDER BY a.created_at DESC"
    );
} catch (Exception $e) {
    // 若权限表不存在或查询失败，退回不带权限信息的相册列表（仍尽量附带创建者信息）
    $albums = $db->fetchAll(
        "SELECT a.*,
                u.nickname AS creator_nickname,
                u.username AS creator_username,
                (SELECT COUNT(*) FROM album_images WHERE album_id = a.id) AS image_count
         FROM albums a
         LEFT JOIN users u ON a.user_id = u.id
         ORDER BY a.created_at DESC"
    );
}

$adminPage = 'albums';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>相册管理</h1>
        <p>管理你们的照片和回忆</p>
    </section>

    <?php if (isset($_GET['error'])): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(248,113,113,0.05);border:1px solid rgba(248,113,113,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#b91c1c;font-size:0.9rem;">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo e($_GET['error']); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="admin-card" style="margin-bottom:0.75rem;background:rgba(34,197,94,0.05);border:1px solid rgba(34,197,94,0.35);">
            <div style="display:flex;align-items:center;gap:0.5rem;color:#15803d;font-size:0.9rem;">
                <i class="fas fa-check-circle"></i>
                <span><?php echo e($_GET['success']); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <div class="admin-card" style="margin-bottom:0.75rem;">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">相册概览</div>
                <div class="admin-card-subtitle">共 <?php echo count($albums); ?> 个相册</div>
            </div>
            <a href="/admin/album_add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                <span>新建相册</span>
            </a>
        </div>
    </div>

    <?php if (empty($albums)): ?>
        <div class="admin-card">
            <p style="font-size:0.9rem;color:var(--text-light);">
                还没有相册，点击右上角“新建相册”创建第一个相册吧～
            </p>
        </div>
    <?php else: ?>
        <section class="admin-grid">
            <?php foreach ($albums as $album): ?>
                <?php
                $isOwner = $album['user_id'] == $currentUser['id'];
                $partnerId = $partner['id'] ?? null;
                $isPartnerUser = $partnerId && $album['user_id'] == $partnerId;
                $allowPartnerEdit = isset($album['allow_partner_edit']) ? (int) $album['allow_partner_edit'] : 1;
                $canEditAlbum = $isOwner || ($isPartnerUser && $allowPartnerEdit);
                $creatorName = !empty($album['creator_nickname'])
                    ? $album['creator_nickname']
                    : (!empty($album['creator_username']) ? $album['creator_username'] : '未知用户');
                ?>
                <article class="admin-card">
                    <div style="display:flex;gap:0.75rem;">
                        <div style="width:90px;height:90px;border-radius:0.9rem;overflow:hidden;flex-shrink:0;background:#f3f4f6;display:flex;align-items:center;justify-content:center;">
                            <?php if (!empty($album['cover_image'])): ?>
                                <img src="<?php echo e(upload_url($album['cover_image'])); ?>" alt="<?php echo e($album['name']); ?>" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <i class="fas fa-image" style="font-size:1.8rem;color:#cbd5f5;"></i>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;">
                                <div>
                                    <div class="admin-card-title" style="max-width:12rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?php echo e($album['name']); ?>
                                    </div>
                                    <div class="admin-card-subtitle">
                                        <?php echo formatDate($album['created_at']); ?> ·
                                        <?php echo (int) $album['image_count']; ?> 张图片 ·
                                        创建者：<?php echo e($creatorName); ?>
                                    </div>
                                </div>
                                <span class="badge <?php echo !empty($album['is_encrypted']) ? 'badge-warning' : 'badge-success'; ?>">
                                    <?php if (!empty($album['is_encrypted'])): ?>
                                        <i class="fas fa-lock" style="margin-right:0.2rem;"></i> 加密
                                    <?php else: ?>
                                        公开
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if (!empty($album['description'])): ?>
                                <div style="margin-top:0.4rem;font-size:0.8rem;color:var(--text-light);max-height:3.2em;overflow:hidden;">
                                    <?php echo nl2br(e($album['description'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top:0.75rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
                        <a href="/album.php?id=<?php echo $album['id']; ?>" target="_blank" class="btn btn-secondary">
                            <i class="fas fa-eye"></i>
                            <span>前台查看</span>
                        </a>
                        <?php if ($canEditAlbum): ?>
                            <a href="/admin/album_manage.php?id=<?php echo $album['id']; ?>" class="btn btn-outline">
                                <i class="fas fa-images"></i>
                                <span>管理图片</span>
                            </a>
                            <form method="POST" data-confirm="确定要删除这个相册吗？可以选择是否同时删除相册内所有图片和封面。">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_id" value="<?php echo $album['id']; ?>">
                                <label style="display:inline-flex;align-items:center;font-size:0.78rem;margin-right:0.25rem;color:var(--text-light);">
                                    <input type="checkbox" name="delete_files" value="1" checked style="margin-right:0.25rem;">
                                    <span>同时删除图片与封面</span>
                                </label>
                                <button type="submit" class="btn btn-secondary" style="background:#fee2e2;color:#b91c1c;border:1px solid rgba(248,113,113,0.6);">
                                    <i class="fas fa-trash"></i>
                                    <span>删除</span>
                                </button>
                            </form>
                        <?php elseif ($isPartnerUser && !$canEditAlbum): ?>
                            <button type="button" class="btn btn-outline" disabled style="opacity:0.6;cursor:not-allowed;">
                                <i class="fas fa-lock"></i>
                                <span>对方已关闭共创</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
