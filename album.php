<?php
// 相册详情页（UTF-8）
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/helpers.php';

// 默认标题，后面用相册名覆盖
$pageTitle = '相册详情';

$auth = new Auth();
$db   = Database::getInstance();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 获取相册信息
$album = $db->fetch(
    "SELECT a.*, u.nickname, u.avatar 
     FROM albums a 
     LEFT JOIN users u ON a.user_id = u.id 
     WHERE a.id = :id",
    ['id' => $id]
);

if (!$album) {
    header('HTTP/1.0 404 Not Found');
    die('相册不存在');
}

// 确保图片上传者映射表存在（用于记录每张图片是谁上传的）
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS `album_image_uploads` (
            `image_id` int(11) NOT NULL COMMENT '图片ID',
            `user_id` int(11) NOT NULL COMMENT '上传用户ID',
            `created_at` datetime NOT NULL COMMENT '记录创建时间',
            PRIMARY KEY (`image_id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册图片上传者映射';
    ");
} catch (Exception $e) {
    // 表创建失败时，仅影响上传者信息展示，不影响主流程
}

// 标题使用相册名称
$pageTitle = $album['name'];

// 获取相册图片（后上传的在前），并尽量携带上传者信息
try {
    $images = $db->fetchAll(
        "SELECT ai.*,
                au.user_id AS uploader_id,
                u.nickname AS uploader_nickname,
                u.avatar   AS uploader_avatar
         FROM album_images ai
         LEFT JOIN album_image_uploads au ON au.image_id = ai.id
         LEFT JOIN users u ON au.user_id = u.id
         WHERE ai.album_id = :album_id
         ORDER BY ai.created_at DESC, ai.id DESC",
        ['album_id' => $id]
    );
} catch (Exception $e) {
    // 回退到不带上传者信息的查询
    $images = $db->fetchAll(
        "SELECT * FROM album_images WHERE album_id = :album_id ORDER BY created_at DESC, id DESC",
        ['album_id' => $id]
    );
}

// 获取相册视频（后上传的在前，尽量携带上传者信息）
try {
    $videos = $db->fetchAll(
        "SELECT v.*,
                u.nickname AS uploader_nickname,
                u.username AS uploader_username,
                u.avatar   AS uploader_avatar
         FROM album_videos v
         LEFT JOIN users u ON v.uploader_id = u.id
         WHERE v.album_id = :album_id
         ORDER BY v.created_at DESC, v.id DESC",
        ['album_id' => $id]
    );
} catch (Exception $e) {
    $videos = [];
}

$currentUser = $auth->getCurrentUser();
$partner     = $currentUser ? $auth->getPartner() : null;

// 传一些信息给 header，用于顶部展示
$albumImageCount   = count($images);
$albumVideoCount   = count($videos);
$albumHeaderTitle  = (!empty($album['is_encrypted']) && empty($currentUser))
    ? '该相册已被加密，请登录后查看'
    : $album['name'];
$albumHeaderDate   = $album['created_at'];

// 默认作者标签为相册创建者昵称
$albumHeaderAuthor = $album['nickname'];
// 额外的一句氛围文案，例如“由 A 和 B 共同记录这本相册”
$albumHeaderMood   = '';

// 如果相册中已有媒体，则根据"上传者 + 创建者"共同参与情况显示标签（图片 + 视频）
if (($albumImageCount + $albumVideoCount) > 0) {
    // 获取情侣双方信息（不依赖登录状态）
    $couple = get_couple_users();
    $user1 = $couple['user1'];
    $user2 = $couple['user2'];
    
    if ($user1 && $user2) {
        $creatorId = isset($album['user_id']) ? (int) $album['user_id'] : 0;
        $user1Id   = (int) $user1['id'];
        $user2Id   = (int) $user2['id'];

        $user1Contributed = false;
        $user2Contributed = false;

        // 统计上传参与情况（图片）
        foreach ($images as $img) {
            if (empty($img['uploader_id'])) {
                continue;
            }
            $uploaderId = (int) $img['uploader_id'];
            if ($uploaderId === $user1Id) {
                $user1Contributed = true;
            }
            if ($uploaderId === $user2Id) {
                $user2Contributed = true;
            }
            if ($user1Contributed && $user2Contributed) {
                break;
            }
        }

        // 统计上传参与情况（视频）
        foreach ($videos as $vid) {
            if (empty($vid['uploader_id'])) {
                continue;
            }
            $uploaderId = (int) $vid['uploader_id'];
            if ($uploaderId === $user1Id) {
                $user1Contributed = true;
            }
            if ($uploaderId === $user2Id) {
                $user2Contributed = true;
            }
            if ($user1Contributed && $user2Contributed) {
                break;
            }
        }

        // 创建者本身即视为"参与"，即使 TA 没有上传任何图片
        if ($creatorId === $user1Id) {
            $user1Contributed = true;
        }
        if ($creatorId === $user2Id) {
            $user2Contributed = true;
        }

        if ($user1Contributed && $user2Contributed) {
            $albumHeaderAuthor = $user1['nickname'] . ' & ' . $user2['nickname'];
            $albumHeaderMood   = '共创';
        } elseif ($user1Contributed) {
            $albumHeaderAuthor = $user1['nickname'];
        } elseif ($user2Contributed) {
            $albumHeaderAuthor = $user2['nickname'];
        }
    }
}

$albumHeaderCount  = $albumImageCount;
$albumHeaderImageCount = $albumImageCount;
$albumHeaderVideoCount = $albumVideoCount;
$isAlbumDetail     = true;
// 标记当前是否为“加密相册且未登录”（用于顶部标签展示锁图标）
$albumIsEncryptedForGuest = !empty($album['is_encrypted']) && empty($currentUser);

// 相册作者信息（用于默认显示；具体每张图片优先使用实际上传者）
$albumAuthorName   = !empty($album['nickname']) ? $album['nickname'] : '匿名用户';
$albumAuthorAvatar = !empty($album['avatar']) ? $album['avatar'] : '/assets/images/default-avatar.svg';

// 未加密或已登录的相册才覆盖头图；未登录且加密的相册保持默认头图，由 header.php 从设置中读取
if (empty($album['is_encrypted']) || !empty($currentUser)) {
    $homeBannerImage = '';
    if (!empty($album['cover_image'])) {
        $homeBannerImage = upload_url($album['cover_image']);
    } elseif (!empty($images)) {
        $homeBannerImage = upload_url($images[0]['image_path']);
    }
}

include __DIR__ . '/views/header.php';
?>

<section class="album-page-section">
    <div class="album-page-main">
        <?php if (!empty($album['is_encrypted']) && empty($currentUser)): ?>
            <div class="encrypted-content glass-card">
                <i class="fas fa-lock"></i>
                <p>当前相册已加密，请登录后双方可见</p>
            </div>
        <?php else: ?>
            <?php if (empty($images) && empty($videos)): ?>
                <div class="empty-state glass-card">
                    <i class="fas fa-image"></i>
                    <p>相册里还没有任何图片哦～</p>
                </div>
            <?php else: ?>
                <?php
                // 将图片与视频合并为单一媒体流，用于前端瀑布流展示
                $mediaItems = [];
                foreach ($images as $imgIndex => $image) {
                    $imagePath = $image['image_path'];
                    $ext       = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
                    $uploaderName = !empty($image['uploader_nickname']) ? $image['uploader_nickname'] : $albumAuthorName;
                    $uploaderAvatar = !empty($image['uploader_avatar']) ? $image['uploader_avatar'] : $albumAuthorAvatar;

                    // 兼容历史数据：如果 image_path 实际是视频扩展名，则视为视频媒体
                    if (in_array($ext, ['mp4','webm','ogg'], true)) {
                        $mediaItems[] = [
                            'type'           => 'video',
                            'video_path'     => $imagePath,
                            'poster_path'    => null,
                            'description'    => $image['description'] ?? '',
                            'created_at'     => $image['created_at'] ?? $album['created_at'],
                            'uploader_name'  => $uploaderName,
                            'uploader_avatar'=> $uploaderAvatar,
                        ];
                    } else {
                        $mediaItems[] = [
                            'type'          => 'image',
                            'image_path'    => $imagePath,
                            'thumbnail_path'=> $image['thumbnail_path'] ?? null,
                            'description'   => $image['description'] ?? '',
                            'created_at'    => $image['created_at'] ?? $album['created_at'],
                            'uploader_name' => $uploaderName,
                            'uploader_avatar'=> $uploaderAvatar,
                        ];
                    }
                }
                foreach ($videos as $video) {
                    $videoUploaderName = !empty($video['uploader_nickname'])
                        ? $video['uploader_nickname']
                        : (!empty($video['uploader_username']) ? $video['uploader_username'] : $albumAuthorName);
                    $videoUploaderAvatar = !empty($video['uploader_avatar'])
                        ? $video['uploader_avatar']
                        : $albumAuthorAvatar;
                    $mediaItems[] = [
                        'type'           => 'video',
                        'video_path'     => $video['video_path'],
                        'poster_path'    => $video['poster_path'] ?? null,
                        'description'    => $video['description'] ?? '',
                        'created_at'     => $video['created_at'] ?? $album['created_at'],
                        'uploader_name'  => $videoUploaderName,
                        'uploader_avatar'=> $videoUploaderAvatar,
                    ];
                }
                usort($mediaItems, function ($a, $b) {
                    $ta = strtotime($a['created_at']);
                    $tb = strtotime($b['created_at']);
                    if ($ta === $tb) {
                        return 0;
                    }
                    // 后上传的在前：按时间倒序
                    return ($ta > $tb) ? -1 : 1;
                });
                ?>
                <div class="album-photo-wall">
                    <?php foreach ($mediaItems as $index => $item): ?>
                        <?php
                        $isVideo       = ($item['type'] === 'video');
                        $uploaderName  = $item['uploader_name'];
                        $uploaderAvatar= $item['uploader_avatar'];
                        if ($isVideo) {
                            $previewPath = $item['poster_path'] ?: null;
                            $videoUrl    = upload_url($item['video_path']);
                            // 暂未生成独立封面时，统一使用本地静态图作为视频封面占位
                            $previewUrl  = $previewPath ? upload_url($previewPath) : '/assets/images/Coverloaderror.jpg';
                        } else {
                            // 相册详情瀑布流使用被压缩后的正式图，以保证清晰度
                            $previewPath = $item['image_path'];
                            $previewUrl  = upload_url($previewPath);
                        }
                        // 为支持 WebP 的浏览器准备 WebP 备用地址（若存在）
                        $previewWebpUrl = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $previewUrl);
                        $createdAt = $item['created_at'];
                        ?>
                        <div
                            class="album-photo-item <?php echo $isVideo ? 'album-photo-item-video' : ''; ?>"
                            data-index="<?php echo (int) $index; ?>"
                            <?php if ($isVideo): ?>
                                data-video-url="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                data-media-type="video"
                                onclick="openMediaLightbox(<?php echo (int) $index; ?>)"
                            <?php else: ?>
                                data-media-type="image"
                                onclick="openMediaLightbox(<?php echo (int) $index; ?>)"
                            <?php endif; ?>
                        >
                            <div class="album-photo-media">
                                <img src="/assets/images/image-placeholder.svg"
                                     data-src="<?php echo htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                     <?php if (preg_match('/\.(jpg|jpeg|png)$/i', $previewUrl)): ?>
                                         data-src-webp="<?php echo htmlspecialchars($previewWebpUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                     <?php endif; ?>
                                     alt="">
                                <?php if (!$isVideo): ?>
                                    <div class="photo-loader">
                                        <div class="reverse-spinner"></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($isVideo): ?>
                                    <div class="album-video-play-icon">
                                        <div class="album-video-play-circle">
                                            <i class="fas fa-play"></i>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="album-photo-meta">
                                <div class="album-photo-meta-left">
                                    <div class="album-photo-avatar-wrap">
                                        <img class="album-photo-avatar" src="<?php echo e($uploaderAvatar); ?>" alt="<?php echo e($uploaderName); ?>">
                                    </div>
                                    <div class="album-photo-author-info">
                                        <div class="album-photo-by">
                                            By：<?php echo e($uploaderName); ?>
                                        </div>
                                        <?php if (!empty($item['description'])): ?>
                                            <div class="album-photo-desc">
                                                <?php echo e($item['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="album-photo-meta-right">
                                    <i class="far fa-clock"></i>
                                    <span><?php echo isset($createdAt) ? date('Y-m-d', strtotime($createdAt)) : date('Y-m-d', strtotime($album['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- 媒体预览灯箱（支持图片与视频，单张预览） -->
<div id="lightbox" class="lightbox" onclick="closeLightbox(event, false)">
    <span class="lightbox-close" onclick="event.stopPropagation(); closeLightbox(null, true);">&times;</span>
    <div class="lightbox-inner">
        <div class="lightbox-card" id="lightbox-media-wrapper">
            <img id="lightbox-img" src="" alt="" style="display:none;">
            <video id="lightbox-video" class="plyr-video" controls preload="metadata" style="display:none;max-width:100%;height:auto;"></video>
        </div>
    </div>
</div>

<link rel="stylesheet" href="/assets/vendor/plyr/plyr.css">
<style>
.album-page-section {
    padding-top: 0;
}

.album-page-main {
    max-width: 1320px;
    margin: 0 auto 3rem;
}

/* 相册详情瀑布流容器：交给 JS Masonry 计算位置，CSS 只负责基础宽度和间距 */
.album-photo-wall {
    position: relative;
    width: 100%;
}

.album-photo-item {
    position: relative;
    padding: 0;
    overflow: hidden;
    cursor: pointer;
    border-radius: 20px;
    margin-bottom: 24px;
    break-inside: avoid;
    background: #ffffff;
    border: 1px solid rgba(15, 23, 42, 0.04);
    box-shadow: var(--shadow-md);
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), 
                box-shadow 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}

/* 图片区域 */
.album-photo-media {
    position: relative;
    overflow: hidden;
    border-radius: 20px;
}

.album-photo-media::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 90px;
    background: linear-gradient(to top, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0));
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    z-index: 2;
}

/* 视频卡片上的播放图标覆盖层 */
.album-photo-item-video .album-video-play-icon {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 3;
    pointer-events: none;
}

.album-video-play-circle {
    width: 50px;
    height: 50px;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.85);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #f9fafb;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.6);
}

.album-video-play-circle i {
    margin-left: 2px;
}

/* 图片加载占位块：静态深色背景，避免破图图标 */
.album-photo-item::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: #f3f4f6;
    z-index: 0;
}

.album-photo-item.loaded::before {
    opacity: 0;
    pointer-events: none;
}

.album-photo-item img {
    width: 100%;
    height: auto;
    display: block;
    position: relative;
    z-index: 1;
    transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.album-photo-item:hover {
    transform: translate3d(0, -4px, 0) scale3d(1.03, 1.03, 1);
    box-shadow: 0 16px 48px rgba(15, 23, 42, 0.25);
}

.album-photo-item:hover img {
    transform: scale(1.05);
}

/* 相册详情页图片加载完成时，平滑隐藏 loader */
.album-photo-media img.image-loaded + .photo-loader {
    opacity: 0;
    transform: scale(0.9);
    transition: opacity 0.25s ease-out, transform 0.25s ease-out;
}

/* 底部信息条：头像 + By + 日期 */
.album-photo-meta {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 0.6rem 0.9rem 0.7rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #f9fafb;
    font-size: 0.78rem;
    z-index: 3;
    background: linear-gradient(to top, rgba(15, 23, 42, 0.92), rgba(15, 23, 42, 0.0));
    transform: translateY(100%);
    opacity: 0;
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1),
                opacity 0.2s ease-out;
    pointer-events: none;
}

.album-photo-meta-left {
    display: flex;
    align-items: center;
    gap: 0.45rem;
}

.album-photo-avatar-wrap {
    width: 30px;
    height: 30px;
    border-radius: 999px;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.7);
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.6);
    flex-shrink: 0;
}

.album-photo-avatar {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.album-photo-author-info {
    display: flex;
    flex-direction: column;
}

.album-photo-by {
    font-weight: 600;
    letter-spacing: 0.02em;
}

.album-photo-meta-right {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.75rem;
    opacity: 0.9;
}

.album-photo-meta-right i {
    font-size: 0.8rem;
}

.album-photo-item:hover .album-photo-media::after {
    opacity: 1;
}

.album-photo-desc {
    margin-top: 0.15rem;
    font-size: 0.75rem;
    line-height: 1.4;
    opacity: 0.9;
    max-height: 2.8em;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    text-overflow: ellipsis;
}

/* 悬停时，信息条从底部滑入显示 */
.album-photo-item:hover .album-photo-meta {
    transform: translateY(0);
    opacity: 1;
    pointer-events: auto;
}

/* 相册详情隐藏头部右侧按钮，保留主视觉和导航 */
.main-nav .nav-buttons {
    display: none;
}
</style>

<script>
// 相册详情页：仅处理占位符与错误回退，布局交给 CSS Grid + JS 完成
(function () {
    // 相册详情页已接入全局懒加载与模糊过渡，这里不再做额外占位处理
    // （保留自执行函数以避免删除后可能的合并冲突）
})();
</script>


<script>
// 灯箱浏览当前相册所有媒体（图片 + 视频）
var albumMedia = <?php echo json_encode($mediaItems ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || [];
var currentMediaIndex = 0;
var lightboxPlayer = null;

function openMediaLightbox(index) {
    if (!albumMedia.length) return;
    if (typeof index === 'number' && index >= 0 && index < albumMedia.length) {
        currentMediaIndex = index;
    }
    updateMediaLightbox();
    document.getElementById('lightbox').classList.add('active');
}

function updateMediaLightbox() {
    if (!albumMedia.length) return;
    var item = albumMedia[currentMediaIndex];
    var imgEl = document.getElementById('lightbox-img');
    var videoEl = document.getElementById('lightbox-video');
    var currentEl = document.getElementById('lightbox-current');
    var totalEl = document.getElementById('lightbox-total');

    if (item.type === 'video') {
        imgEl.style.display = 'none';
        videoEl.style.display = 'block';

        // 每次切换视频前销毁旧的 Plyr 实例，避免状态残留影响进度条
        if (lightboxPlayer && typeof lightboxPlayer.destroy === 'function') {
            try { lightboxPlayer.destroy(); } catch (e) {}
            lightboxPlayer = null;
        }

        var videoUrl = item.video_path ? "<?php echo rtrim(UPLOAD_URL, '/'); ?>/" + item.video_path : '';
        videoEl.src = videoUrl;
        // 重新加载以触发 metadata 事件，确保获取到时长后再初始化 Plyr
        try {
            videoEl.load();
        } catch (e) {}

        var initPlyr = function () {
            if (typeof Plyr !== 'undefined') {
                try {
                    lightboxPlayer = new Plyr(videoEl, {
                        controls: [
                            'play-large',
                            'play',
                            'progress',
                            'current-time',
                            'duration',
                            'mute',
                            'volume',
                            'fullscreen'
                        ],
                        autoplay: true
                    });
                    return;
                } catch (e) {
                    // 回退到原生播放控件
                }
            }
            // 未加载 Plyr 或初始化失败时，使用浏览器原生控件播放
            try {
                videoEl.play().catch(function () {});
            } catch (e) {}
        };

        if (videoEl.readyState >= 1) {
            // 已经拿到 metadata，直接初始化
            initPlyr();
        } else {
            // 等待 metadata 就绪后再初始化，保证进度条与时长正常
            var onLoadedMetadata = function () {
                videoEl.removeEventListener('loadedmetadata', onLoadedMetadata);
                initPlyr();
            };
            videoEl.addEventListener('loadedmetadata', onLoadedMetadata);
        }
    } else {
        var imagePath = item.image_path;
        var src = "<?php echo rtrim(UPLOAD_URL, '/'); ?>/" + imagePath;

        imgEl.style.display = 'block';
        videoEl.style.display = 'none';

        imgEl.onerror = null;
        if (window.__supportsWebP) {
            var webp = src.replace(/\.(jpg|jpeg|png)$/i, '.webp');
            imgEl.onerror = function () {
                imgEl.onerror = null;
                imgEl.src = src;
            };
            imgEl.src = webp;
        } else {
            imgEl.src = src;
        }
    }

    if (currentEl) currentEl.textContent = currentMediaIndex + 1;
    if (totalEl) totalEl.textContent = albumMedia.length;
}

function closeLightbox(event, force) {
    if (!force && event && event.target !== event.currentTarget) {
        return;
    }
    document.getElementById('lightbox').classList.remove('active');
    if (lightboxPlayer && typeof lightboxPlayer.destroy === 'function') {
        try { lightboxPlayer.destroy(); } catch (e) {}
        lightboxPlayer = null;
    }
}

document.addEventListener('keydown', function (e) {
    var lightbox = document.getElementById('lightbox');
    if (!lightbox || !lightbox.classList.contains('active')) {
        return;
    }
    if (e.key === 'Escape') {
        closeLightbox(null, true);
    }
});

// 移动端灯箱尺寸兜底优化：避免在部分手机上媒体区域过小
document.addEventListener('DOMContentLoaded', function () {
    if (window.innerWidth > 768) {
        return; // 仅在移动端生效，不影响 PC
    }
    var wrapper = document.getElementById('lightbox-media-wrapper');
    var imgEl   = document.getElementById('lightbox-img');
    var videoEl = document.getElementById('lightbox-video');

    if (wrapper) {
        wrapper.style.maxWidth  = '100vw';
        wrapper.style.maxHeight = '100vh';
    }

    [imgEl, videoEl].forEach(function (el) {
        if (!el) return;
        el.style.maxWidth  = '100vw';
        el.style.maxHeight = '90vh';
        el.style.width     = '100vw';
        el.style.height    = 'auto';
        el.style.objectFit = 'contain';
    });
});
</script>

<script src="/assets/vendor/plyr/plyr.polyfilled.min.js"></script>

<?php include __DIR__ . '/views/footer.php'; ?>
