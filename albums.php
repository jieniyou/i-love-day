<?php
// 设置 UTF-8 编码
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/helpers.php';

// 确保数据库结构为最新（包含相册视频表等）
if (function_exists('migrate_schema_if_needed')) {
    migrate_schema_if_needed();
}

// 设置页面标题
$pageTitle = '爱情相册';

$auth        = new Auth();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$partner     = $currentUser ? $auth->getPartner() : null;

// 每页相册数量（首屏 + 后续分页保持一致）
$albumsPerPage = 3;

// 首屏仅查询第一页相册，详细预览图在模板中按需查询
$albums = $db->fetchAll(
    "SELECT a.*, u.nickname, u.avatar,
     (
        (SELECT COUNT(*) FROM album_images WHERE album_id = a.id) +
        (SELECT COUNT(*) FROM album_videos  WHERE album_id = a.id)
     ) AS image_count
     FROM albums a 
     LEFT JOIN users u ON a.user_id = u.id 
     ORDER BY a.created_at DESC
     LIMIT " . (int) $albumsPerPage
);

// 共创标记：判断当前情侣双方是否都参与了相册（创建或上传）
$albumCoCreated = [];
if (!empty($albums)) {
    // 获取情侣双方信息（不依赖登录状态）
    $couple = get_couple_users();
    $user1 = $couple['user1'];
    $user2 = $couple['user2'];
    
    if ($user1 && $user2) {
        // 确保图片上传者映射表存在
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
            // 创建失败时，不影响后续展示，只是不显示共创标签
        }

        try {
            $albumIds = array_column($albums, 'id');
            $albumIds = array_map('intval', $albumIds);

            if ($albumIds) {
                $placeholders = implode(',', array_fill(0, count($albumIds), '?'));

                // 图片上传者列表（通过 album_image_uploads 映射）
                $rows = $db->fetchAll(
                    "SELECT ai.album_id, au.user_id
                     FROM album_images ai
                     JOIN album_image_uploads au ON au.image_id = ai.id
                     WHERE ai.album_id IN ($placeholders)",
                    $albumIds
                );

                $contributors = [];
                foreach ($rows as $row) {
                    $aid = (int) $row['album_id'];
                    $uid = (int) $row['user_id'];
                    if (!isset($contributors[$aid])) {
                        $contributors[$aid] = [];
                    }
                    if (!in_array($uid, $contributors[$aid], true)) {
                        $contributors[$aid][] = $uid;
                    }
                }

                // 视频上传者列表：直接使用 album_videos.uploader_id
                try {
                    $rowsVideo = $db->fetchAll(
                        "SELECT album_id, uploader_id AS user_id
                         FROM album_videos
                         WHERE album_id IN ($placeholders) AND uploader_id IS NOT NULL",
                        $albumIds
                    );
                    foreach ($rowsVideo as $row) {
                        $aid = (int) $row['album_id'];
                        $uid = (int) $row['user_id'];
                        if (!isset($contributors[$aid])) {
                            $contributors[$aid] = [];
                        }
                        if ($uid > 0 && !in_array($uid, $contributors[$aid], true)) {
                            $contributors[$aid][] = $uid;
                        }
                    }
                } catch (Exception $e) {
                    // 忽略视频上传者统计失败，保持已有图片逻辑
                }

                $user1Id = (int) $user1['id'];
                $user2Id = (int) $user2['id'];

                foreach ($albums as $album) {
                    $aid       = (int) $album['id'];
                    $creatorId = isset($album['user_id']) ? (int) $album['user_id'] : 0;
                    $list      = $contributors[$aid] ?? [];

                    $user1Contributed = in_array($user1Id, $list, true);
                    $user2Contributed = in_array($user2Id, $list, true);

                    // 创建者本身也视为"参与"
                    if ($creatorId === $user1Id) {
                        $user1Contributed = true;
                    }
                    if ($creatorId === $user2Id) {
                        $user2Contributed = true;
                    }

                    $albumCoCreated[$aid] = $user1Contributed && $user2Contributed;
                }
            }
        } catch (Exception $e) {
            // 读取失败时，忽略共创标记
            $albumCoCreated = [];
        }
    }
}

include __DIR__ . '/views/header.php';
?>

<section class="content-section">
    <div class="section-header card-header-row">
        <h2><i class="fas fa-images"></i> 爱情相册</h2>
    </div>
    
    <?php if (!empty($albums)): ?>
    <div class="albums-masonry">
        <?php foreach ($albums as $album): ?>
        <?php
            $aid = (int) $album['id'];
            $isCoCreated = !empty($albumCoCreated[$aid]);

            // 计算双头像：主头像为相册创建者，次头像为另一半（若存在）
            $creatorAvatar   = !empty($album['avatar']) ? $album['avatar'] : '/assets/images/default-avatar.svg';
            $creatorNickname = !empty($album['nickname']) ? $album['nickname'] : '匿名用户';
            $secondAvatar    = '';
            $secondNickname  = '';

            // 仅当双方对相册都有实际参与（与"共创"标签同一判断）时才显示第二个头像
            if ($isCoCreated) {
                $couple = get_couple_users();
                $user1 = $couple['user1'];
                $user2 = $couple['user2'];
                
                if ($user1 && $user2) {
                    $creatorId = isset($album['user_id']) ? (int) $album['user_id'] : 0;
                    $user1Id = (int) $user1['id'];
                    $user2Id = (int) $user2['id'];

                    if ($creatorId === $user1Id) {
                        $secondAvatar   = !empty($user2['avatar']) ? $user2['avatar'] : '/assets/images/default-avatar.svg';
                        $secondNickname = !empty($user2['nickname']) ? $user2['nickname'] : '';
                    } elseif ($creatorId === $user2Id) {
                        $secondAvatar   = !empty($user1['avatar']) ? $user1['avatar'] : '/assets/images/default-avatar.svg';
                        $secondNickname = !empty($user1['nickname']) ? $user1['nickname'] : '';
                    }
                }
            }
        ?>
        <div class="album-card glass-card album-card-grid<?php echo $isCoCreated ? ' album-card-co' : ''; ?>">
            <div class="album-header">
                <div class="album-avatar-stack<?php echo ($isCoCreated && $secondAvatar) ? ' album-avatar-stack-co' : ''; ?>">
                    <img src="<?php echo e($creatorAvatar); ?>" alt="<?php echo e($creatorNickname); ?>" class="album-avatar album-avatar-main">
                    <?php if ($secondAvatar): ?>
                        <img src="<?php echo e($secondAvatar); ?>" alt="<?php echo e($secondNickname ?: $creatorNickname); ?>" class="album-avatar-secondary">
                    <?php endif; ?>
                </div>
                <div class="album-user-info">
                    <span class="album-nickname">
                        <?php echo (!empty($album['is_encrypted']) && empty($currentUser)) ? '加密相册' : e($album['name']); ?>
                    </span>
                    <div class="album-user-meta">
                        <span class="album-meta-time"><?php echo formatDate($album['created_at'], 'Y-m-d'); ?></span>
                        <?php if ($isCoCreated): ?>
                            <span class="album-meta-co">
                                <i class="fas fa-heart"></i>
                                共创
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="album-grid-preview">
                <?php if (!empty($album['is_encrypted']) && empty($currentUser)): ?>
                    <div class="encrypted-content">
                        <i class="fas fa-lock"></i>
                        <p>当前相册已被加密，请登录后查看</p>
                    </div>
                <?php else: ?>
                    <?php
                    // 相册卡片预览：同时纳入图片缩略图与视频封面缩略图，统一当作图片网格展示
                    $previewItems = [];
                    try {
                        $rowsImg = $db->fetchAll(
                            "SELECT COALESCE(thumbnail_path, image_path) AS path, created_at 
                             FROM album_images 
                             WHERE album_id = :id",
                            ['id' => $album['id']]
                        );
                    } catch (Exception $e) {
                        $rowsImg = [];
                    }

                    foreach ($rowsImg as $row) {
                        if (empty($row['path'])) {
                            continue;
                        }
                        $previewItems[] = [
                            'type'       => 'image',
                            'path'       => $row['path'],
                            'created_at' => $row['created_at'] ?? $album['created_at'],
                        ];
                    }

                    // 视频封面：优先使用封面图的 thumbs 缩略图，其次回退到封面主图
                    try {
                        $rowsVid = $db->fetchAll(
                            "SELECT poster_path, created_at 
                             FROM album_videos 
                             WHERE album_id = :id",
                            ['id' => $album['id']]
                        );
                    } catch (Exception $e) {
                        $rowsVid = [];
                    }

                    foreach ($rowsVid as $row) {
                        $posterPath = trim($row['poster_path'] ?? '');
                        // 无独立封面图时，使用统一默认封面图占位，避免视频在相册卡片中“消失”
                        if ($posterPath === '') {
                            $finalPath = '/assets/images/Coverloaderror.jpg';
                        } else {
                            $finalPath = $posterPath;
                            $pi = pathinfo($posterPath);
                            if (!empty($pi['dirname']) && !empty($pi['basename'])) {
                                $thumbRelative = rtrim($pi['dirname'], '/\\') . '/thumbs/' . $pi['basename'];
                                $thumbAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($thumbRelative, '/\\');
                                if (is_file($thumbAbs)) {
                                    $finalPath = $thumbRelative;
                                }
                            }
                        }

                        $previewItems[] = [
                            'type'       => 'video',
                            'path'       => $finalPath,
                            'created_at' => $row['created_at'] ?? $album['created_at'],
                        ];
                    }

                    if (!empty($previewItems)) {
                        usort($previewItems, function ($a, $b) {
                            $ta = strtotime($a['created_at'] ?? '') ?: 0;
                            $tb = strtotime($b['created_at'] ?? '') ?: 0;
                            if ($ta === $tb) {
                                return 0;
                            }
                            // 后上传的在前
                            return ($ta > $tb) ? -1 : 1;
                        });
                        $previewItems = array_slice($previewItems, 0, 9);
                    }
                    ?>
                    <?php if (!empty($previewItems)): ?>
                        <?php foreach ($previewItems as $itemPreview): ?>
                        <?php
                            $previewPath = $itemPreview['path'];
                            $isVideo     = isset($itemPreview['type']) && $itemPreview['type'] === 'video';
                            // /assets/ 开头的为站点静态资源，直接使用相对站点根路径；
                            // 其余仍视为上传文件路径，交给 upload_url 处理。
                            if (strpos($previewPath, '/assets/') === 0) {
                                $dataSrc = $previewPath;
                            } else {
                                $dataSrc = upload_url($previewPath);
                            }
                            $dataSrcWebp = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $dataSrc);
                            ?>
                        <div class="album-grid-item<?php echo $isVideo ? ' album-grid-item-video' : ''; ?>">
                            <img src="/assets/images/image-placeholder.svg"
                                 data-src="<?php echo $dataSrc; ?>"
                                 data-src-webp="<?php echo $dataSrcWebp; ?>"
                                 alt="<?php echo e($album['name']); ?>">
                            <div class="photo-loader">
                                <div class="reverse-spinner"></div>
                            </div>
                            <?php if ($isVideo): ?>
                                <div class="album-grid-video-play">
                                    <div class="album-grid-video-play-circle">
                                        <i class="fas fa-play"></i>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="album-grid-empty">
                            <i class="fas fa-images"></i>
                            <span>还没有照片</span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($album['description']) && empty($album['is_encrypted'])): ?>
            <div class="album-description" title="<?php echo e($album['description']); ?>">
                <?php echo e($album['description']); ?>
            </div>
            <?php endif; ?>
            <div class="album-card-footer-row">
                <div class="album-card-footer-left">
                    <div class="album-photo-count">
                        <?php if (!empty($album['is_encrypted']) && empty($currentUser)): ?>
                            <i class="fas fa-lock"></i>
                        <?php else: ?>
                            <i class="fas fa-image"></i>
                            <span><?php echo $album['image_count']; ?> 张照片</span>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="/album.php?id=<?php echo $album['id']; ?>" class="btn-view">
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div id="albums-load-more-sentinel" class="albums-load-more-sentinel"></div>
    <?php else: ?>
    <div class="empty-state glass-card">
        <i class="fas fa-images"></i>
        <p>还没有任何相册，去创建第一本属于你们的相册吧～</p>
    </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/views/footer.php'; ?>

<script>
// 相册列表：滚动到下方时按需加载更多相册卡片
(function () {
    var masonryContainer = document.querySelector('.albums-masonry');
    var sentinel = document.getElementById('albums-load-more-sentinel');
    if (!masonryContainer || !sentinel) return;

    var currentPage = 1;
    var perPage = <?php echo (int) $albumsPerPage; ?>;
    var isLoading = false;
    var hasMore = true;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function buildAlbumCard(item) {
        var card = document.createElement('div');
        card.className = 'album-card glass-card album-card-grid';

        var isEncrypted = parseInt(item.is_encrypted, 10) === 1;
        var isCoCreated = parseInt(item.is_co_created || 0, 10) === 1;
        var userId = parseInt(item.user_id || 0, 10);
        var albumName = item.name || '';
        var createdAt = item.created_at || '';
        var imageCount = item.image_count || 0;
        var nickname = item.nickname || '';
        var avatar = item.avatar || '';
        var description = item.description || '';

        // 计算双头像（与 PHP 渲染逻辑保持一致）
        var currentUserId = <?php echo $currentUser ? (int) $currentUser['id'] : 0; ?>;
        var partnerUserId = <?php echo $partner ? (int) $partner['id'] : 0; ?>;
        var currentUserAvatar = <?php echo $currentUser ? "'" . addslashes($currentUser['avatar']) . "'" : "''"; ?>;
        var currentUserNickname = <?php echo $currentUser ? "'" . addslashes($currentUser['nickname']) . "'" : "''"; ?>;
        var partnerAvatar = <?php echo $partner ? "'" . addslashes($partner['avatar']) . "'" : "''"; ?>;
        var partnerNickname = <?php echo $partner ? "'" . addslashes($partner['nickname']) . "'" : "''"; ?>;
        var creatorAvatar = avatar || '/assets/images/default-avatar.svg';
        var creatorNickname = nickname || '匿名用户';
        var secondAvatar = '';
        var secondNickname = '';

        // 仅当双方对相册都有实际参与（与“共创”标签同一判断）时才显示第二个头像
        if (isCoCreated && userId && currentUserId && partnerUserId) {
            if (userId === currentUserId) {
                secondAvatar = partnerAvatar || '';
                secondNickname = partnerNickname || '';
            } else if (userId === partnerUserId) {
                secondAvatar = currentUserAvatar || '';
                secondNickname = currentUserNickname || '';
            }
        }

        var stackClass = 'album-avatar-stack' + (isCoCreated && secondAvatar ? ' album-avatar-stack-co' : '');

        var avatarHtml =
            '<div class="' + stackClass + '">' +
                '<img src="' + escapeHtml(creatorAvatar) + '" alt="' + escapeHtml(creatorNickname) + '" class="album-avatar album-avatar-main">' +
                (secondAvatar
                    ? '<img src="' + escapeHtml(secondAvatar) + '" alt="' + escapeHtml(secondNickname || creatorNickname) + '" class="album-avatar-secondary">'
                    : '') +
            '</div>';

        var headerHtml =
            '<div class="album-header">' +
                avatarHtml +
                '<div class="album-user-info">' +
                    '<span class="album-nickname">' +
                        (isEncrypted && !<?php echo $currentUser ? 'true' : 'false'; ?> ? '加密相册' : escapeHtml(albumName)) +
                    '</span>' +
                    '<div class="album-user-meta">' +
                        '<span class="album-meta-time">' + escapeHtml(createdAt.substring(0, 10)) + '</span>' +
                        (isCoCreated
                            ? '<span class="album-meta-co"><i class="fas fa-heart"></i> 共创</span>'
                            : '') +
                    '</div>' +
                '</div>' +
            '</div>';

        var gridHtml = '';
        if (isEncrypted && !<?php echo $currentUser ? 'true' : 'false'; ?>) {
            gridHtml =
                '<div class="album-grid-preview">' +
                    '<div class="encrypted-content">' +
                        '<i class="fas fa-lock"></i>' +
                        '<p>当前相册已被加密，请登录后查看</p>' +
                    '</div>' +
                '</div>';
        } else {
            var images = item.images || [];
            if (images.length) {
                var cells = images.map(function (entry) {
                    var src;
                    var type = 'image';
                    if (typeof entry === 'string') {
                        src = entry;
                    } else if (entry && typeof entry === 'object') {
                        src = entry.src || '';
                        if (entry.type) {
                            type = entry.type;
                        }
                    } else {
                        src = '';
                    }

                    if (!src) {
                        return '';
                    }

                    var isVideo = type === 'video';
                    var webpSrc = src.replace(/\.(jpg|jpeg|png)$/i, '.webp');
                    return (
                        '<div class="album-grid-item' + (isVideo ? ' album-grid-item-video' : '') + '">' +
                            '<img src="/assets/images/image-placeholder.svg" data-src="' + escapeHtml(src) + '" data-src-webp="' + escapeHtml(webpSrc) + '" alt="' + escapeHtml(albumName) + '">' +
                            '<div class="photo-loader">' +
                                '<div class="reverse-spinner"></div>' +
                            '</div>' +
                            (isVideo
                                ? '<div class="album-grid-video-play">' +
                                      '<div class="album-grid-video-play-circle">' +
                                          '<i class="fas fa-play"></i>' +
                                      '</div>' +
                                  '</div>'
                                : '') +
                        '</div>'
                    );
                }).join('');
                gridHtml =
                    '<div class="album-grid-preview">' +
                        cells +
                    '</div>';
            } else {
                gridHtml =
                    '<div class="album-grid-preview">' +
                        '<div class="album-grid-empty">' +
                            '<i class="fas fa-images"></i>' +
                            '<span>还没有照片</span>' +
                        '</div>' +
                    '</div>';
            }
        }

        var descHtml = '';
        if (description && !isEncrypted) {
            descHtml =
                '<div class="album-description" title="' + escapeHtml(description) + '">' +
                    escapeHtml(description) +
                '</div>';
        }

        var footerHtml;
        if (isEncrypted && !<?php echo $currentUser ? 'true' : 'false'; ?>) {
            footerHtml =
                '<div class="album-card-footer-row">' +
                    '<div class="album-card-footer-left">' +
                        '<div class="album-photo-count">' +
                            '<i class="fas fa-lock"></i>' +
                        '</div>' +
                    '</div>' +
                    '<a href="/album.php?id=' + item.id + '" class="btn-view">' +
                        '<i class="fas fa-arrow-right"></i>' +
                    '</a>' +
                '</div>';
        } else {
            footerHtml =
                '<div class="album-card-footer-row">' +
                    '<div class="album-card-footer-left">' +
                        '<div class="album-photo-count">' +
                            '<i class="fas fa-image"></i>' +
                            '<span>' + imageCount + ' 张照片</span>' +
                        '</div>' +
                    '</div>' +
                    '<a href="/album.php?id=' + item.id + '" class="btn-view">' +
                        '<i class="fas fa-arrow-right"></i>' +
                    '</a>' +
                '</div>';
        }

        card.innerHTML = headerHtml + gridHtml + descHtml + footerHtml;
        return card;
    }

    function appendAlbums(items) {
        if (!items || !items.length) return;
        var frag = document.createDocumentFragment();
        items.forEach(function (item) {
            frag.appendChild(buildAlbumCard(item));
        });
        masonryContainer.appendChild(frag);

        // 新增相册卡片需要重新初始化瀑布流布局和动画
        if (typeof initMasonryForContainer === 'function') {
            initMasonryForContainer('.albums-masonry', '.album-card');
        }
        // 为新加入的预览图片注册懒加载
        if (typeof initLazyLoading === 'function') {
            initLazyLoading();
        }
    }

    function loadMore() {
        if (isLoading || !hasMore) return;
        isLoading = true;
        var nextPage = currentPage + 1;

        fetch('/api/albums.php?page=' + nextPage + '&per_page=' + perPage, {
            credentials: 'same-origin'
        })
            .then(function (res) {
                if (!res.ok) return null;
                return res.json();
            })
            .then(function (json) {
                if (!json || !json.success) return;
                appendAlbums(json.items || []);
                currentPage = json.page || nextPage;
                hasMore = !!json.has_more;
                if (!hasMore && observer) {
                    observer.unobserve(sentinel);
                }
            })
            .catch(function () {
                // 加载失败时保持现状，下次滚动可再次尝试
            })
            .finally(function () {
                isLoading = false;
            });
    }

    var observer = null;
    if ('IntersectionObserver' in window) {
        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    loadMore();
                }
            });
        }, {
            root: null,
            rootMargin: '0px 0px 200px 0px',
            threshold: 0.01
        });
        observer.observe(sentinel);
    }
})();
</script>
