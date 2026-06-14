<?php
// 相册分页加载接口
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

// 确保数据库结构为最新（包含相册视频表等）
if (function_exists('migrate_schema_if_needed')) {
    migrate_schema_if_needed();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Invalid request'], 405);
}

$auth        = new Auth();
$db          = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$partner     = $currentUser ? $auth->getPartner() : null;

$page    = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 3;

if ($perPage <= 0) {
    $perPage = 3;
} elseif ($perPage > 30) {
    $perPage = 30;
}

$offset    = ($page - 1) * $perPage;
$limitPlus = $perPage + 1;

try {
    $sql = "
        SELECT a.*, u.nickname, u.avatar,
               (
                   (SELECT COUNT(*) FROM album_images WHERE album_id = a.id) +
                   (SELECT COUNT(*) FROM album_videos  WHERE album_id = a.id)
               ) AS image_count
        FROM albums a
        LEFT JOIN users u ON a.user_id = u.id
        ORDER BY a.created_at DESC
        LIMIT {$limitPlus} OFFSET {$offset}
    ";
    $albums = $db->fetchAll($sql);

    $albumIds = array_column($albums, 'id');
    $covers = [];
    $albumCoCreated = [];
    if ($albumIds) {
        // 为当前这一页的所有相册一次性查封面图（每个相册最多 9 张用于预览）
        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));

        // 媒体预览集合：同时纳入图片缩略图和视频封面缩略图
        $mediaMap = [];
        foreach ($albumIds as $aIdRaw) {
            $mediaMap[(int)$aIdRaw] = [];
        }

        // 图片预览：COALESCE(thumbnail_path, image_path)
        $rows = $db->fetchAll(
            "SELECT album_id, image_path, thumbnail_path, created_at
             FROM album_images 
             WHERE album_id IN ($placeholders) 
             ORDER BY album_id ASC, created_at DESC, id DESC",
            $albumIds
        );
        foreach ($rows as $row) {
            $aid = (int) $row['album_id'];
            $path = $row['thumbnail_path'] ?: $row['image_path'];
            if (!$path) {
                continue;
            }
            $mediaMap[$aid][] = [
                'type'       => 'image',
                'path'       => $path,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        // 视频封面预览：优先使用封面图的 thumbs 缩略图，其次回退到封面主图
        try {
            $rowsVideo = $db->fetchAll(
                "SELECT album_id, poster_path, created_at
                 FROM album_videos
                 WHERE album_id IN ($placeholders)
                 ORDER BY album_id ASC, created_at DESC, id DESC",
                $albumIds
            );
        } catch (Exception $e) {
            $rowsVideo = [];
        }

        foreach ($rowsVideo as $row) {
            $aid = (int) $row['album_id'];
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

            $mediaMap[$aid][] = [
                'type'       => 'video',
                'path'       => $finalPath,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        // 每个相册最多 9 张预览图：按创建时间倒序混排图片与视频封面
        foreach ($mediaMap as $aid => $items) {
            if (empty($items)) {
                continue;
            }
            usort($items, function ($a, $b) {
                $ta = strtotime($a['created_at'] ?? '') ?: 0;
                $tb = strtotime($b['created_at'] ?? '') ?: 0;
                if ($ta === $tb) {
                    return 0;
                }
                return ($ta > $tb) ? -1 : 1;
            });
            $items = array_slice($items, 0, 9);
            foreach ($items as $it) {
                $path = $it['path'];
                $type = $it['type'] ?? 'image';

                // /assets/ 开头的为站点静态资源，直接返回相对站点根路径；
                // 其余仍视为上传文件路径，交给 upload_url 处理。
                if (strpos($path, '/assets/') === 0) {
                    $url = $path;
                } else {
                    $url = upload_url($path);
                }

                $covers[$aid][] = [
                    'src'  => $url,
                    'type' => $type,
                ];
            }
        }

        // 共创标记：仅在已登录且存在另一半时计算（沿用原有逻辑）
        if (!empty($albums)) {
            // 获取情侣双方信息（不依赖登录状态）
            $couple = get_couple_users();
            $user1 = $couple['user1'];
            $user2 = $couple['user2'];
            
            if ($user1 && $user2) {
                try {
                    // 确保图片上传者映射表存在
                    $db->query("
                        CREATE TABLE IF NOT EXISTS `album_image_uploads` (
                            `image_id` int(11) NOT NULL COMMENT '图片ID',
                            `user_id` int(11) NOT NULL COMMENT '上传用户ID',
                            `created_at` datetime NOT NULL COMMENT '记录创建时间',
                            PRIMARY KEY (`image_id`),
                            KEY `user_id` (`user_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册图片上传者映射';
                    ");

                    $placeholders2 = implode(',', array_fill(0, count($albumIds), '?'));
                    $rows2 = $db->fetchAll(
                        "SELECT ai.album_id, au.user_id
                         FROM album_images ai
                         JOIN album_image_uploads au ON au.image_id = ai.id
                         WHERE ai.album_id IN ($placeholders2)",
                        $albumIds
                    );

                    $contributors = [];
                    foreach ($rows2 as $row) {
                        $aid2 = (int) $row['album_id'];
                        $uid = (int) $row['user_id'];
                        if (!isset($contributors[$aid2])) {
                            $contributors[$aid2] = [];
                        }
                        if (!in_array($uid, $contributors[$aid2], true)) {
                            $contributors[$aid2][] = $uid;
                        }
                    }

                    // 将视频上传者也纳入共创统计：使用 album_videos.uploader_id
                    try {
                        $videoRows = $db->fetchAll(
                            "SELECT album_id, uploader_id AS user_id
                             FROM album_videos
                             WHERE album_id IN ($placeholders2) AND uploader_id IS NOT NULL",
                            $albumIds
                        );
                        foreach ($videoRows as $row) {
                            $aid2 = (int) $row['album_id'];
                            $uid  = (int) $row['user_id'];
                            if (!isset($contributors[$aid2])) {
                                $contributors[$aid2] = [];
                            }
                            if ($uid > 0 && !in_array($uid, $contributors[$aid2], true)) {
                                $contributors[$aid2][] = $uid;
                            }
                        }
                    } catch (Exception $e) {
                        // 视频上传者统计失败时忽略，保持已有图片逻辑
                    }

                    $user1Id = (int) $user1['id'];
                    $user2Id = (int) $user2['id'];

                    foreach ($albums as $album) {
                        $aid2       = (int) $album['id'];
                        $creatorId = isset($album['user_id']) ? (int) $album['user_id'] : 0;
                        $list      = $contributors[$aid2] ?? [];

                        $user1Contributed = in_array($user1Id, $list, true);
                        $user2Contributed = in_array($user2Id, $list, true);

                        // 创建者本身也视为"参与"
                        if ($creatorId === $user1Id) {
                            $user1Contributed = true;
                        }
                        if ($creatorId === $user2Id) {
                            $user2Contributed = true;
                        }

                        $albumCoCreated[$aid2] = $user1Contributed && $user2Contributed;
                    }
                } catch (Exception $e) {
                    $albumCoCreated = [];
                }
            }
        }
    }
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => '加载相册失败',
    ], 500);
}

$hasMore = false;
if (count($albums) > $perPage) {
    $hasMore = true;
    $albums  = array_slice($albums, 0, $perPage);
}

$items = [];
foreach ($albums as $album) {
    $aid          = (int) $album['id'];
    $isEncrypted  = (int) ($album['is_encrypted'] ?? 0) === 1;
    $imageCount   = (int) ($album['image_count'] ?? 0);
    $previewImages = array_values($covers[$aid] ?? []);

    // 未登录用户访问加密相册时，不返回真实预览图片 URL，只保留计数等元信息
    if ($isEncrypted && !$currentUser) {
        $previewImages = [];
    }

    $items[] = [
        'id'           => $aid,
        'user_id'      => (int) ($album['user_id'] ?? 0),
        'name'         => (string) ($album['name'] ?? ''),
        'is_encrypted' => $isEncrypted ? 1 : 0,
        'created_at'   => (string) ($album['created_at'] ?? date('Y-m-d H:i:s')),
        'description'  => (string) ($album['description'] ?? ''),
        'image_count'  => $imageCount,
        'nickname'     => (string) ($album['nickname'] ?? ''),
        'avatar'       => (string) ($album['avatar'] ?? ''),
        'is_co_created'=> !empty($albumCoCreated[$aid]) ? 1 : 0,
        // 封面预览使用的图片数组（最多 9 张）
        'images'       => $previewImages,
    ];
}

jsonResponse([
    'success'  => true,
    'page'     => $page,
    'per_page' => $perPage,
    'has_more' => $hasMore,
    'items'    => $items,
]);
