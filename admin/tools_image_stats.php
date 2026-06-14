<?php
// 后台小工具：简单统计图片体积与相册带宽预估
// 根据不同操作返回 HTML 或 JSON
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

// 状态提示
$error   = '';
$success = '';

// 确保最新表结构（包含 is_optimized / skip_optimize）
if (function_exists('migrate_schema_if_needed')) {
    migrate_schema_if_needed();
}

$adminPage = 'tools_stats';

// 每次批处理的最大条数，避免超时（用于相册缩略图补齐相关操作）
$batchLimit = 100;

// AJAX: 统计压缩占比
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'stats') {
    header('Content-Type: application/json; charset=UTF-8');

    $excludeAlbums  = !empty($_POST['exclude_albums']) ? 1 : 0;
    $excludeImages  = !empty($_POST['exclude_images']) ? 1 : 0;

    try {
        // 可压缩图片：排除不压缩相册 / 图片
        $where = [];
        $params = [];

        if ($excludeAlbums) {
            $where[] = '(a.keep_original_quality = 0 OR a.keep_original_quality IS NULL)';
        }
        if ($excludeImages) {
            $where[] = 'ai.skip_optimize = 0';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $totalRow = $db->fetch("
            SELECT COUNT(*) AS c
            FROM album_images ai
            LEFT JOIN albums a ON ai.album_id = a.id
            $whereSql
        ", $params);
        $total = (int) ($totalRow['c'] ?? 0);

        $optimized = 0;
        if ($total > 0) {
            $whereOpt = $where;
            $whereOpt[] = 'ai.is_optimized = 1';
            $whereOptSql = 'WHERE ' . implode(' AND ', $whereOpt);

            $optRow = $db->fetch("
                SELECT COUNT(*) AS c
                FROM album_images ai
                LEFT JOIN albums a ON ai.album_id = a.id
                $whereOptSql
            ", $params);
            $optimized = (int) ($optRow['c'] ?? 0);
        }

        $notOptimized = max(0, $total - $optimized);

        echo json_encode([
            'success'       => true,
            'total'         => $total,
            'optimized'     => $optimized,
            'not_optimized' => $notOptimized,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => '统计失败：' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// AJAX: 统计相册视频转码情况
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'video_stats') {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $totalRow = $db->fetch("SELECT COUNT(*) AS c FROM album_videos");
        $total = (int) ($totalRow['c'] ?? 0);

        $transcoded = 0;
        if ($total > 0) {
            $doneRow = $db->fetch("SELECT COUNT(*) AS c FROM album_videos WHERE is_transcoded = 1");
            $transcoded = (int) ($doneRow['c'] ?? 0);
        }

        $pending = max(0, $total - $transcoded);

        echo json_encode([
            'success'    => true,
            'total'      => $total,
            'transcoded' => $transcoded,
            'pending'    => $pending,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        echo json_encode([
            'success'    => true,
            'total'      => 0,
            'transcoded' => 0,
            'pending'    => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// AJAX: 统计文章视频转码情况
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'article_video_stats') {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $totalRow = $db->fetch("SELECT COUNT(*) AS c FROM article_videos");
        $total = (int) ($totalRow['c'] ?? 0);

        $transcoded = 0;
        if ($total > 0) {
            $doneRow = $db->fetch("SELECT COUNT(*) AS c FROM article_videos WHERE is_transcoded = 1");
            $transcoded = (int) ($doneRow['c'] ?? 0);
        }

        $pending = max(0, $total - $transcoded);

        echo json_encode([
            'success'    => true,
            'total'      => $total,
            'transcoded' => $transcoded,
            'pending'    => $pending,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        echo json_encode([
            'success'    => true,
            'total'      => 0,
            'transcoded' => 0,
            'pending'    => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// AJAX: 相册视频一键转码（分批）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'video_transcode_batch') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!function_exists('shell_exec')) {
        echo json_encode([
            'success'   => true,
            'processed' => 0,
            'pending'   => 0,
            'message'   => '当前 PHP 环境禁用了 shell_exec，已跳过视频转码。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $limit = isset($_POST['limit']) ? max(1, (int) $_POST['limit']) : $batchLimit;

    try {
        $rows = $db->fetchAll(
            "SELECT id, album_id, video_path, original_video_path, is_transcoded
             FROM album_videos
             WHERE is_transcoded = 0 OR is_transcoded IS NULL
             ORDER BY id ASC
             LIMIT :limit",
            ['limit' => $limit]
        );
    } catch (Throwable $e) {
        echo json_encode([
            'success'   => true,
            'processed' => 0,
            'pending'   => 0,
            'message'   => '读取相册视频失败：' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $processed = 0;
    if ($rows) {
        foreach ($rows as $row) {
            $videoId   = (int) ($row['id'] ?? 0);
            $albumId   = (int) ($row['album_id'] ?? 0);
            if ($videoId <= 0 || $albumId <= 0) {
                continue;
            }

            $originalPath = $row['original_video_path'] ?: $row['video_path'];
            $originalPath = trim((string) $originalPath);
            if ($originalPath === '') {
                continue;
            }

            $videoAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($originalPath, '/');
            if (!is_file($videoAbs)) {
                continue;
            }

            $transcodedSubDir = 'albums/' . $albumId . '/videos/transcoded';
            $transcodedDirAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . trim($transcodedSubDir, '/');
            if (!is_dir($transcodedDirAbs)) {
                @mkdir($transcodedDirAbs, 0755, true);
            }
            if (!is_dir($transcodedDirAbs)) {
                continue;
            }

            $transcodedFile = 'video_' . $videoId . '_h264.mp4';
            $transcodedAbs  = $transcodedDirAbs . '/' . $transcodedFile;

            $ffmpeg = 'ffmpeg';
            $cmd = $ffmpeg
                . ' -y'
                . ' -i ' . escapeshellarg($videoAbs)
                . ' -c:v libx264 -preset medium -crf 23'
                . ' -c:a aac -b:a 128k'
                . ' -movflags +faststart '
                . escapeshellarg($transcodedAbs)
                . ' 2>&1';
            @shell_exec($cmd);

            if (!is_file($transcodedAbs)) {
                continue;
            }

            $newRelative = trim($transcodedSubDir, '/') . '/' . $transcodedFile;

            $updateData = [
                'video_path'    => $newRelative,
                'is_transcoded' => 1,
            ];
            if (empty($row['original_video_path'])) {
                $updateData['original_video_path'] = $originalPath;
            }

            try {
                $db->update('album_videos', $updateData, 'id = :id', ['id' => $videoId]);
                $processed++;
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    // 计算剩余未转码数量
    try {
        $pendingRow = $db->fetch(
            "SELECT COUNT(*) AS c
             FROM album_videos
             WHERE is_transcoded = 0 OR is_transcoded IS NULL"
        );
        $pending = (int) ($pendingRow['c'] ?? 0);
    } catch (Throwable $e) {
        $pending = 0;
    }

    echo json_encode([
        'success'   => true,
        'processed' => $processed,
        'pending'   => $pending,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX: 文章视频一键转码（分批）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'article_video_transcode_batch') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!function_exists('shell_exec')) {
        echo json_encode([
            'success'   => true,
            'processed' => 0,
            'pending'   => 0,
            'message'   => '当前 PHP 环境禁用了 shell_exec，已跳过文章视频转码。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $limit = isset($_POST['limit']) ? max(1, (int) $_POST['limit']) : $batchLimit;

    try {
        $rows = $db->fetchAll(
            "SELECT id, article_id, video_path, original_video_path, is_transcoded
             FROM article_videos
             WHERE is_transcoded = 0 OR is_transcoded IS NULL
             ORDER BY id ASC
             LIMIT :limit",
            ['limit' => $limit]
        );
    } catch (Throwable $e) {
        echo json_encode([
            'success'   => true,
            'processed' => 0,
            'pending'   => 0,
            'message'   => '读取文章视频失败：' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $processed = 0;
    if ($rows) {
        foreach ($rows as $row) {
            $videoId   = (int) ($row['id'] ?? 0);
            $articleId = (int) ($row['article_id'] ?? 0);
            if ($videoId <= 0) {
                continue;
            }

            $originalPath = $row['original_video_path'] ?: $row['video_path'];
            $originalPath = trim((string) $originalPath);
            if ($originalPath === '') {
                continue;
            }

            $videoAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($originalPath, '/');
            if (!is_file($videoAbs)) {
                continue;
            }

            // 优先根据 article_id 分目录；若为 0，则回退到 uploads/articles/videos/transcoded
            if ($articleId > 0) {
                $transcodedSubDir = 'articles/' . $articleId . '/videos/transcoded';
            } else {
                $transcodedSubDir = 'articles/videos/transcoded';
            }
            $transcodedDirAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . trim($transcodedSubDir, '/');
            if (!is_dir($transcodedDirAbs)) {
                @mkdir($transcodedDirAbs, 0755, true);
            }
            if (!is_dir($transcodedDirAbs)) {
                continue;
            }

            // 以视频 ID 生成固定文件名，避免重复转码造成路径不一致
            $transcodedFile = 'article_video_' . $videoId . '_h264.mp4';
            $transcodedAbs  = $transcodedDirAbs . '/' . $transcodedFile;

            $ffmpeg = 'ffmpeg';
            $cmd = $ffmpeg
                . ' -y'
                . ' -i ' . escapeshellarg($videoAbs)
                . ' -c:v libx264 -preset medium -crf 23'
                . ' -c:a aac -b:a 128k'
                . ' -movflags +faststart '
                . escapeshellarg($transcodedAbs)
                . ' 2>&1';
            @shell_exec($cmd);

            if (!is_file($transcodedAbs)) {
                continue;
            }

            $newRelative = trim($transcodedSubDir, '/') . '/' . $transcodedFile;

            $updateData = [
                'video_path'    => $newRelative,
                'is_transcoded' => 1,
            ];
            if (empty($row['original_video_path'])) {
                $updateData['original_video_path'] = $originalPath;
            }

            try {
                $db->update('article_videos', $updateData, 'id = :id', ['id' => $videoId]);
                $processed++;
            } catch (Throwable $e) {
                // 单条更新失败忽略
                continue;
            }
        }
    }

    // 计算剩余未转码数量
    try {
        $pendingRow = $db->fetch(
            "SELECT COUNT(*) AS c
             FROM article_videos
             WHERE is_transcoded = 0 OR is_transcoded IS NULL"
        );
        $pending = (int) ($pendingRow['c'] ?? 0);
    } catch (Throwable $e) {
        $pending = 0;
    }

    echo json_encode([
        'success'   => true,
        'processed' => $processed,
        'pending'   => $pending,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX: 一键压缩未压缩图片（分批）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'optimize_batch') {
    header('Content-Type: application/json; charset=UTF-8');

    $excludeAlbums  = !empty($_POST['exclude_albums']) ? 1 : 0;
    $excludeImages  = !empty($_POST['exclude_images']) ? 1 : 0;
    $limit          = isset($_POST['limit']) ? max(1, (int) $_POST['limit']) : 50;

    try {
        $where = ['ai.is_optimized = 0'];
        $params = [];
        if ($excludeAlbums) {
            $where[] = '(a.keep_original_quality = 0 OR a.keep_original_quality IS NULL)';
        }
        if ($excludeImages) {
            $where[] = 'ai.skip_optimize = 0';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $db->fetchAll("
            SELECT ai.id, ai.image_path
            FROM album_images ai
            LEFT JOIN albums a ON ai.album_id = a.id
            $whereSql
            ORDER BY ai.id ASC
            LIMIT :limit
        ", ['limit' => $limit]);

        $processed = 0;
        foreach ($rows as $row) {
            $path = $row['image_path'] ?? '';
            if (!$path) {
                continue;
            }
            $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($path, '/');
            if (!is_file($abs)) {
                continue;
            }
            try {
                optimize_uploaded_image($abs);
                $db->update('album_images', [
                    'is_optimized' => 1,
                ], 'id = :id', ['id' => $row['id']]);
                $processed++;
            } catch (Throwable $e) {
                // 单张失败忽略
                continue;
            }
        }

        // 计算剩余未压缩数量
        $remainingRow = $db->fetch("
            SELECT COUNT(*) AS c
            FROM album_images ai
            LEFT JOIN albums a ON ai.album_id = a.id
            $whereSql
        ");
        $remaining = (int) ($remainingRow['c'] ?? 0);

        echo json_encode([
            'success'   => true,
            'processed' => $processed,
            'remaining' => $remaining,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => '压缩失败：' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// POST：相册缩略图补齐 / 仅补表 / 文章图片 WebP 补齐（复用原 tools_image_optimize.php 逻辑）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode'])) {
    require_csrf();
    $mode = $_POST['mode'] ?? '';

    if ($mode === 'album_thumbs') {
        // 为缺少缩略图的相册图片补齐缩略图（及其 WebP）
        try {
            $rows = $db->fetchAll(
                "SELECT ai.id, ai.album_id, ai.image_path
                 FROM album_images ai
                 LEFT JOIN albums a ON ai.album_id = a.id
                 WHERE (ai.thumbnail_path IS NULL OR ai.thumbnail_path = '')
                   AND (a.keep_original_quality = 0 OR a.keep_original_quality IS NULL)
                   AND ai.skip_optimize = 0
                 ORDER BY ai.id ASC
                 LIMIT :limit",
                ['limit' => $batchLimit]
            );
        } catch (Throwable $e) {
            $rows = [];
            $error = '读取相册图片失败：' . $e->getMessage();
        }

        $processed = 0;
        if (!$error && $rows) {
            foreach ($rows as $row) {
                $imagePath = $row['image_path'] ?? '';
                if ($imagePath === '') {
                    continue;
                }
                $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($imagePath, '/');
                if (!is_file($abs)) {
                    continue;
                }

                try {
                    // 直接调用统一优化函数，让其负责生成缩略图与 WebP
                    optimize_uploaded_image($abs);

                    // 推断缩略图路径
                    $relative      = ltrim($imagePath, '/');
                    $thumbRelative = preg_replace('#^(.*/)([^/]+)$#', '$1thumbs/$2', $relative);
                    $thumbAbs      = rtrim(UPLOAD_DIR, '/\\') . '/' . $thumbRelative;
                    if (is_file($thumbAbs)) {
                        $db->update('album_images', [
                            'thumbnail_path' => $thumbRelative,
                        ], 'id = :id', ['id' => $row['id']]);
                        $processed++;
                    }
                } catch (Throwable $e) {
                    // 单条失败忽略，继续处理下一条
                    continue;
                }
            }
        }

        if (!$error) {
            $success = "本次为 {$processed} 张相册图片补齐了缩略图（及 WebP）。如仍有剩余，可再次点击执行。";
        }
    } elseif ($mode === 'album_thumbs_relink') {
        // 仅补表：不重新生成缩略图，只根据已有 thumbs 目录为老数据回填 thumbnail_path
        try {
            $rows = $db->fetchAll(
                "SELECT id, image_path
                 FROM album_images
                 WHERE (thumbnail_path IS NULL OR thumbnail_path = '')
                 ORDER BY id ASC
                 LIMIT :limit",
                ['limit' => $batchLimit]
            );
        } catch (Throwable $e) {
            $rows = [];
            $error = '读取相册图片失败：' . $e->getMessage();
        }

        $processed = 0;
        if (!$error && $rows) {
            foreach ($rows as $row) {
                $imagePath = $row['image_path'] ?? '';
                if ($imagePath === '') {
                    continue;
                }

                $relative      = ltrim($imagePath, '/');
                $thumbRelative = preg_replace('#^(.*/)([^/]+)$#', '$1thumbs/$2', $relative);
                if (!$thumbRelative || $thumbRelative === $relative) {
                    continue;
                }

                $thumbAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . $thumbRelative;
                if (!is_file($thumbAbs)) {
                    continue;
                }

                try {
                    $db->update('album_images', [
                        'thumbnail_path' => $thumbRelative,
                    ], 'id = :id', ['id' => $row['id']]);
                    $processed++;
                } catch (Throwable $e) {
                    continue;
                }
            }
        }

        if (!$error) {
            $success = "本次为 {$processed} 张相册图片补齐了 thumbnail_path 字段（仅根据已有 thumbs 目录回填，无需重新生成缩略图）。";
        }
    } elseif ($mode === 'article_images') {
        // 文章正文图片：这里只负责触发文件级 WebP 补全（不改文章 HTML，因为访问时已经有懒加载 + WebP 逻辑）
        try {
            $rows = $db->fetchAll(
                "SELECT id, content
                 FROM articles
                 WHERE status = 'published'
                 ORDER BY id ASC
                 LIMIT :limit",
                ['limit' => $batchLimit]
            );
        } catch (Throwable $e) {
            $rows = [];
            $error = '读取文章失败：' . $e->getMessage();
        }

        $processed = 0;
        if (!$error && $rows) {
            foreach ($rows as $row) {
                $html = (string)($row['content'] ?? '');
                if ($html === '') {
                    continue;
                }
                // 粗略匹配正文中的 uploads 图片
                if (!preg_match_all('#src=("|\')([^"\']*uploads/[^"\']+\.(?:jpg|jpeg|png))\1#i', $html, $m)) {
                    continue;
                }
                $paths = array_unique($m[2]);
                foreach ($paths as $url) {
                    // 去掉域名，仅保留 /uploads/... 或 uploads/...
                    $p = preg_replace('#^https?://[^/]+/#i', '/', $url);
                    $p = ltrim($p, '/');
                    if (strpos($p, 'uploads/') !== 0) {
                        continue;
                    }
                    $relative = substr($p, strlen('uploads/'));
                    $abs      = rtrim(UPLOAD_DIR, '/\\') . '/' . $relative;
                    if (!is_file($abs)) {
                        continue;
                    }
                    try {
                        optimize_uploaded_image($abs);
                        $processed++;
                    } catch (Throwable $e) {
                        continue;
                    }
                }
            }
        }

        if (!$error) {
            $success = "本次扫描了最多 {$batchLimit} 篇文章，为其中引用的 {$processed} 张图片触发了 WebP/缩放补齐。";
        }
    } elseif ($mode === 'video_transcode_batch') {
        // 为尚未按统一规则转码的视频生成 H.264 + AAC 版本（仅处理 is_transcoded = 0 的记录）
        if (!function_exists('shell_exec')) {
            $error = '当前 PHP 环境禁用了 shell_exec，无法执行视频转码。';
        } else {
            try {
                $rows = $db->fetchAll(
                    "SELECT id, album_id, video_path, original_video_path, is_transcoded
                     FROM album_videos
                     WHERE is_transcoded = 0 OR is_transcoded IS NULL
                     ORDER BY id ASC
                     LIMIT :limit",
                    ['limit' => $batchLimit]
                );
            } catch (Throwable $e) {
                $rows  = [];
                $error = '读取相册视频失败：' . $e->getMessage();
            }

            $processed = 0;
            if (!$error && $rows) {
                foreach ($rows as $row) {
                    $videoId   = (int) ($row['id'] ?? 0);
                    $albumId   = (int) ($row['album_id'] ?? 0);
                    if ($videoId <= 0 || $albumId <= 0) {
                        continue;
                    }

                    $originalPath = $row['original_video_path'] ?: $row['video_path'];
                    $originalPath = trim((string) $originalPath);
                    if ($originalPath === '') {
                        continue;
                    }

                    $videoAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($originalPath, '/');
                    if (!is_file($videoAbs)) {
                        continue;
                    }

                    $transcodedSubDir = 'albums/' . $albumId . '/videos/transcoded';
                    $transcodedDirAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . trim($transcodedSubDir, '/');
                    if (!is_dir($transcodedDirAbs)) {
                        @mkdir($transcodedDirAbs, 0755, true);
                    }
                    if (!is_dir($transcodedDirAbs)) {
                        continue;
                    }

                    // 以视频 ID 生成固定文件名，避免重复转码造成路径不一致
                    $transcodedFile = 'video_' . $videoId . '_h264.mp4';
                    $transcodedAbs  = $transcodedDirAbs . '/' . $transcodedFile;

                    $ffmpeg = 'ffmpeg';
                    $cmd = $ffmpeg
                        . ' -y'
                        . ' -i ' . escapeshellarg($videoAbs)
                        . ' -c:v libx264 -preset medium -crf 23'
                        . ' -c:a aac -b:a 128k'
                        . ' -movflags +faststart '
                        . escapeshellarg($transcodedAbs)
                        . ' 2>&1';
                    @shell_exec($cmd);

                    if (!is_file($transcodedAbs)) {
                        continue;
                    }

                    $newRelative = trim($transcodedSubDir, '/') . '/' . $transcodedFile;

                    $updateData = [
                        'video_path'    => $newRelative,
                        'is_transcoded' => 1,
                    ];
                    if (empty($row['original_video_path'])) {
                        $updateData['original_video_path'] = $originalPath;
                    }

                    try {
                        $db->update('album_videos', $updateData, 'id = :id', ['id' => $videoId]);
                        $processed++;
                    } catch (Throwable $e) {
                        // 单条更新失败忽略
                        continue;
                    }
                }
            }

            if (!$error) {
                if ($processed > 0) {
                    $success = "本次为 {$processed} 个视频生成了转码版本（H.264 + AAC）。如仍有剩余，可再次点击执行。";
                } else {
                    $success = "未检测到需要转码的视频（或本次批次中转码均失败）。";
                }
            }
        }
    } elseif ($mode === 'video_cleanup_originals') {
        // 清理已转码视频的原始文件，仅在 is_transcoded = 1 且 original_video_path 不为空时执行
        try {
            $rows = $db->fetchAll(
                "SELECT id, original_video_path
                 FROM album_videos
                 WHERE is_transcoded = 1
                   AND original_video_path IS NOT NULL
                   AND original_video_path <> ''
                 ORDER BY id ASC
                 LIMIT :limit",
                ['limit' => $batchLimit]
            );
        } catch (Throwable $e) {
            $rows  = [];
            $error = '读取相册视频失败：' . $e->getMessage();
        }

        $processed = 0;
        if (!$error && $rows) {
            foreach ($rows as $row) {
                $videoId = (int) ($row['id'] ?? 0);
                $origRel = trim((string) ($row['original_video_path'] ?? ''));
                if ($videoId <= 0 || $origRel === '') {
                    continue;
                }

                $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($origRel, '/');
                if (is_file($abs)) {
                    // 删除原始视频文件本身（不影响转码后的播放文件）
                    deleteFile($origRel);
                }

                try {
                    // 无论文件是否实际存在，统一清空 original_video_path，避免重复清理
                    $db->update('album_videos', [
                        'original_video_path' => null,
                    ], 'id = :id', ['id' => $videoId]);
                    $processed++;
                } catch (Throwable $e) {
                    continue;
                }
            }
        }

        if (!$error) {
            if ($processed > 0) {
                $success = "本次为 {$processed} 个已转码视频清理了原始上传文件。";
            } else {
                $success = "未检测到需要清理原始文件的视频（可能已清理完毕或尚未转码）。";
            }
        }
    } elseif ($mode === 'article_video_cleanup_originals') {
        // 清理文章已转码视频的原始文件，仅在 is_transcoded = 1 且 original_video_path 不为空时执行
        try {
            $rows = $db->fetchAll(
                "SELECT id, original_video_path
                 FROM article_videos
                 WHERE is_transcoded = 1
                   AND original_video_path IS NOT NULL
                   AND original_video_path <> ''
                 ORDER BY id ASC
                 LIMIT :limit",
                ['limit' => $batchLimit]
            );
        } catch (Throwable $e) {
            $rows  = [];
            $error = '读取文章视频失败：' . $e->getMessage();
        }

        $processed = 0;
        if (!$error && $rows) {
            foreach ($rows as $row) {
                $videoId = (int) ($row['id'] ?? 0);
                $origRel = trim((string) ($row['original_video_path'] ?? ''));
                if ($videoId <= 0 || $origRel === '') {
                    continue;
                }

                $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($origRel, '/');
                if (is_file($abs)) {
                    // 删除原始视频文件本身（不影响转码后的播放文件）
                    deleteFile($origRel);
                }

                try {
                    // 无论文件是否实际存在，统一清空 original_video_path，避免重复清理
                    $db->update('article_videos', [
                        'original_video_path' => null,
                    ], 'id = :id', ['id' => $videoId]);
                    $processed++;
                } catch (Throwable $e) {
                    continue;
                }
            }
        }

        if (!$error) {
            if ($processed > 0) {
                $success = "本次为 {$processed} 个文章视频清理了原始上传文件。";
            } else {
                $success = "未检测到需要清理原始文件的文章视频（可能已清理完毕或尚未转码）。";
            }
        }
    } else {
        $error = '无效的操作类型';
    }
}

// 简单读取相册图片基础数据（用于体积估算）
$albumImageStats = [
    'count'       => 0,
    'total_bytes' => 0,
    'avg_bytes'   => 0,
];

// 采样最近部分相册图片用于体积估算（逻辑与仪表盘保持一致）
try {
    $rows = $db->fetchAll("
        SELECT image_path
        FROM album_images
        ORDER BY id DESC
        LIMIT 500
    ");
    $totalBytes = 0;
    $count      = 0;
    foreach ($rows as $row) {
        $path = $row['image_path'] ?? '';
        if (!$path) continue;
        $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($path, '/');
        if (!is_file($abs)) continue;
        $sz = filesize($abs);
        if ($sz === false) continue;
        $totalBytes += $sz;
        $count++;
    }
    if ($count > 0) {
        $albumImageStats['count']       = $count;
        $albumImageStats['total_bytes'] = $totalBytes;
        $albumImageStats['avg_bytes']   = (int) floor($totalBytes / $count);
    }
} catch (Throwable $e) {
    // 统计失败时保持默认值，页面仍可访问
}

include __DIR__ . '/header.php';
?>

<section class="admin-page-title">
    <h1>图片体积、压缩与补齐工具</h1>
    <p>查看图片体积、压缩占比，并按需一键压缩或为旧数据补齐缩略图 / WebP / 视频转码。</p>
    <p style="margin-top:0.25rem;font-size:0.85rem;color:var(--text-light);">
        推荐使用顺序：<strong>① 相册图片：补齐缩略图 / WebP 或仅补表</strong>（先让相册卡片和瀑布流都有专属缩略图） →
        <strong>② 压缩状态与一键压缩</strong>（统一压缩主图体积并生成 WebP） →
        <strong>③ 文章图片：触发 WebP 补齐</strong>（可选，对正文图片做相同处理） →
        <strong>④ 相册视频：统一视频转码</strong>（可选，为未转码视频生成浏览器更友好的版本）。
    </p>
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

<section class="admin-grid">
    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">相册图片概览（最近 500 张）</div>
                <div class="admin-card-subtitle">基于最近上传的图片样本进行估算。</div>
            </div>
        </div>
        <?php if ($albumImageStats['count'] > 0): ?>
            <?php
            $count      = $albumImageStats['count'];
            $totalBytes = $albumImageStats['total_bytes'];
            $avgBytes   = $albumImageStats['avg_bytes'];

            $avgKb  = round($avgBytes / 1024, 1);
            $avgMb  = round($avgBytes / 1024 / 1024, 2);
            $totalMb = round($totalBytes / 1024 / 1024, 1);

            // 简单预估：一次加载 30 张图（首页/相册瀑布流）
            $sceneCount = 30;
            $sceneBytes = $avgBytes * $sceneCount;
            $sceneMb    = round($sceneBytes / 1024 / 1024, 2);
            ?>
            <div style="font-size:0.9rem;color:var(--text-normal);">
                <p>采样图片数量：<strong><?php echo $count; ?></strong> 张</p>
                <p>总体积约：<strong><?php echo $totalMb; ?> MB</strong></p>
                <p>平均单张大小：约 <strong><?php echo $avgKb; ?> KB</strong>（约 <?php echo $avgMb; ?> MB）</p>
                <hr style="border:none;border-top:1px dashed rgba(148,163,184,0.6);margin:0.75rem 0;">
                <p>场景估算：</p>
                <ul style="margin:0.25rem 0 0.5rem 1.1rem;font-size:0.88rem;color:var(--text-light);">
                    <li>一次加载 <strong><?php echo $sceneCount; ?></strong> 张图（例如单个相册页或首页多行瀑布流），约 <strong><?php echo $sceneMb; ?> MB</strong></li>
                    <li>若同时有 WebP 与缩略图启用，实际流量通常会低于上述估算值。</li>
                </ul>
            </div>
        <?php else: ?>
            <p style="font-size:0.9rem;color:var(--text-light);">
                暂无相册图片或无法读取文件体积，稍后再试试吧～
            </p>
        <?php endif; ?>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">压缩状态与一键压缩</div>
                <div class="admin-card-subtitle">
                    统计已压缩占比，按需批量压缩未压缩图片（只针对主图体积与 WebP，不修改 thumbnail_path）。
                </div>
            </div>
        </div>

        <div style="font-size:0.88rem;color:var(--text-normal);margin-bottom:0.75rem;">
            <label style="display:inline-flex;align-items:center;margin-right:1rem;font-size:0.85rem;">
                <input type="checkbox" id="excludeAlbums" checked style="margin-right:0.35rem;">
                <span>排除已设置为“不压缩相册”的图片</span>
            </label>
            <label style="display:inline-flex;align-items:center;font-size:0.85rem;">
                <input type="checkbox" id="excludeImages" checked style="margin-right:0.35rem;">
                <span>排除被单独标记为“不压缩”的图片</span>
            </label>
        </div>

        <div id="optStats" style="font-size:0.9rem;color:var(--text-normal);margin-bottom:0.75rem;">
            <p>可压缩图片总数：<strong id="optTotal">-</strong> 张</p>
            <p>已压缩：<strong id="optDone">-</strong> 张，未压缩：<strong id="optPending">-</strong> 张</p>
            <p>已压缩占比：<strong id="optPercent">-</strong></p>
        </div>

        <div id="optProgressWrap" style="display:none;margin-bottom:0.75rem;">
            <div class="upload-progress-bar-wrap">
                <div class="upload-progress-bar" id="optProgressBar"></div>
            </div>
            <div class="upload-progress-text" id="optProgressText">准备压缩...</div>
        </div>

        <button type="button" class="btn btn-primary" id="btnOptimize">
            <i class="fas fa-magic"></i>
            <span>一键压缩未压缩的图片</span>
        </button>
        <p style="margin-top:0.45rem;font-size:0.78rem;color:var(--text-light);">
            为避免超时，系统会分批压缩，每次处理少量图片。你可以在压缩过程中继续浏览其他页面。
        </p>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">相册图片：补齐缩略图 / WebP</div>
                <div class="admin-card-subtitle">
                    为旧相册中尚未生成缩略图的图片补齐 thumbs 与 WebP，并在生成成功后为其写入 thumbnail_path（不会处理已标记为“不压缩”的相册和图片）。
                </div>
            </div>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="mode" value="album_thumbs">
            <p style="font-size:0.85rem;color:var(--text-light);margin-bottom:0.75rem;">
                每次最多处理 <?php echo (int)$batchLimit; ?> 张图片。建议在非高峰时间多次执行，直到没有明显变化为止。
            </p>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-magic"></i>
                <span>执行一次相册图片补齐</span>
            </button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">相册图片：仅补表（不重新生成缩略图）</div>
                <div class="admin-card-subtitle">当磁盘上已经存在 thumbs 缩略图文件，但 thumbnail_path 尚未写入表时，可使用此工具回填字段。</div>
            </div>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="mode" value="album_thumbs_relink">
            <p style="font-size:0.85rem;color:var(--text-light);margin-bottom:0.75rem;">
                每次最多处理 <?php echo (int)$batchLimit; ?> 张图片。仅在检测到 <code>thumbs/</code> 目录下已存在对应缩略图文件时，才会为该图片回填 <code>thumbnail_path</code> 字段，不会重新生成缩略图文件。
            </p>
            <button type="submit" class="btn btn-secondary">
                <i class="fas fa-link"></i>
                <span>执行一次相册 thumbnail_path 补表</span>
            </button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">文章图片：触发 WebP 补齐</div>
                <div class="admin-card-subtitle">扫描文章正文中引用的 /uploads 图片，按需生成 WebP / 压缩版本。</div>
            </div>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="mode" value="article_images">
            <p style="font-size:0.85rem;color:var(--text-light);margin-bottom:0.75rem;">
                每次最多扫描 <?php echo (int)$batchLimit; ?> 篇文章。由于正文图片引用可能重复，请按需多次执行。
            </p>
            <button type="submit" class="btn btn-secondary">
                <i class="fas fa-sync-alt"></i>
                <span>执行一次文章图片扫描</span>
            </button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">相册视频：一键转码为通用格式</div>
                <div class="admin-card-subtitle">
                    为尚未按统一规则转码的相册视频生成 H.264 + AAC 版本，提升浏览器兼容性，减少黑屏但有声音的情况。
                </div>
            </div>
        </div>
        <form method="POST" onsubmit="return false;">
            <?php echo csrf_field(); ?>
            <p style="font-size:0.85rem;color:var(--text-light);margin-bottom:0.75rem;">
                每次最多处理 <?php echo (int)$batchLimit; ?> 个视频，仅针对 <code>album_videos</code> 中尚未标记为
                <code>is_transcoded = 1</code> 的记录。转码成功后会更新视频播放路径，并在 <code>original_video_path</code> 中保留原始上传路径。
            </p>
            <p style="font-size:0.8rem;color:var(--text-light);margin-bottom:0.5rem;">
                当前统计：共 <span id="videoTransTotal">-</span> 个视频，
                已转码 <span id="videoTransDone">-</span> 个，
                剩余 <span id="videoTransPending">-</span> 个待转码。
            </p>
            <div class="upload-progress" id="videoTransProgressWrap" style="display:none;margin-top:0.5rem;">
                <div class="upload-progress-bar-wrap">
                    <div class="upload-progress-bar" id="videoTransProgressBar"></div>
                </div>
                <div class="upload-progress-text" id="videoTransProgressText">准备开始视频转码...</div>
            </div>
            <button type="button" id="btnVideoTranscode" class="btn btn-secondary" style="margin-top:0.5rem;">
                <i class="fas fa-film"></i>
                <span>执行一次未转码视频批量转码</span>
            </button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">相册视频：清理已转码视频的原始文件</div>
                <div class="admin-card-subtitle">
                    为已经完成转码（<code>is_transcoded = 1</code>）的视频清理原始上传文件，仅保留统一编码后的播放文件，以释放磁盘空间。
                </div>
            </div>
        </div>
        <form method="POST" data-confirm="该操作将删除已转码视频的原始上传文件，仅保留转码版本，确认继续吗？">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="mode" value="video_cleanup_originals">
            <p style="font-size:0.85rem;color:var(--text-light);margin-bottom:0.75rem;">
                每次最多处理 <?php echo (int)$batchLimit; ?> 个视频，仅针对 <code>is_transcoded = 1</code> 且
                <code>original_video_path</code> 仍有记录的条目。执行后将清空 <code>original_video_path</code> 字段，避免重复清理。
                请在确认所有设备播放正常、且不再需要原始文件时再使用本工具。
            </p>
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-trash-alt"></i>
                <span>执行一次原始视频清理</span>
            </button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">文章视频：一键转码为通用格式</div>
                <div class="admin-card-subtitle">
                    为尚未按统一规则转码的文章视频生成 H.264 + AAC 版本，提升浏览器兼容性，减少黑屏但有声音的情况。
                </div>
            </div>
        </div>
        <form method="POST" onsubmit="return false;">
            <?php echo csrf_field(); ?>
            <p style="font-size:0.85rem;color:var(--text-light);margin-bottom:0.75rem;">
                每次最多处理 <?php echo (int)$batchLimit; ?> 个视频，仅针对 <code>article_videos</code> 中尚未标记为
                <code>is_transcoded = 1</code> 的记录。转码成功后会更新视频播放路径，并在 <code>original_video_path</code> 中保留原始上传路径。
            </p>
            <p style="font-size:0.8rem;color:var(--text-light);margin-bottom:0.5rem;">
                当前统计：共 <span id="articleVideoTransTotal">-</span> 个视频，
                已转码 <span id="articleVideoTransDone">-</span> 个，
                剩余 <span id="articleVideoTransPending">-</span> 个待转码。
            </p>
            <div class="upload-progress" id="articleVideoTransProgressWrap" style="display:none;margin-top:0.5rem;">
                <div class="upload-progress-bar-wrap">
                    <div class="upload-progress-bar" id="articleVideoTransProgressBar"></div>
                </div>
                <div class="upload-progress-text" id="articleVideoTransProgressText">准备开始文章视频转码...</div>
            </div>
            <button type="button" id="btnArticleVideoTranscode" class="btn btn-secondary" style="margin-top:0.5rem;">
                <i class="fas fa-film"></i>
                <span>执行一次文章未转码视频批量转码</span>
            </button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">文章视频：清理已转码视频的原始文件</div>
                <div class="admin-card-subtitle">
                    为已经完成转码（<code>is_transcoded = 1</code>）的文章视频清理原始上传文件，仅保留统一编码后的播放文件，以释放磁盘空间。
                </div>
            </div>
        </div>
        <form method="POST" data-confirm="该操作将删除文章已转码视频的原始上传文件，仅保留转码版本，确认继续吗？">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="mode" value="article_video_cleanup_originals">
            <p style="font-size:0.85rem;color:var(--text-light);margin-bottom:0.75rem;">
                每次最多处理 <?php echo (int)$batchLimit; ?> 个视频，仅针对 <code>is_transcoded = 1</code> 且
                <code>original_video_path</code> 仍有记录的条目。执行后将清空 <code>original_video_path</code> 字段，避免重复清理。
                请在确认所有设备播放正常、且不再需要原始文件时再使用本工具。
            </p>
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-trash-alt"></i>
                <span>执行一次文章原始视频清理</span>
            </button>
        </form>
    </div>
</section>

<script>
(function() {
    function fetchStats() {
        var excludeAlbums = document.getElementById('excludeAlbums').checked ? 1 : 0;
        var excludeImages = document.getElementById('excludeImages').checked ? 1 : 0;

        var formData = new FormData();
        formData.append('action', 'stats');
        formData.append('exclude_albums', excludeAlbums);
        formData.append('exclude_images', excludeImages);

        fetch('tools_image_stats.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (!json || !json.success) return;
                var total = json.total || 0;
                var done = json.optimized || 0;
                var pending = json.not_optimized || 0;
                var percent = total > 0 ? ((done / total) * 100).toFixed(1) + '%' : '0%';

                document.getElementById('optTotal').textContent = total;
                document.getElementById('optDone').textContent = done;
                document.getElementById('optPending').textContent = pending;
                document.getElementById('optPercent').textContent = percent;
            })
            .catch(function() {});
    }

    function fetchVideoStats() {
        var fd = new FormData();
        fd.append('action', 'video_stats');

        fetch('tools_image_stats.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (!json || !json.success) return;
                var total   = json.total || 0;
                var done    = json.transcoded || 0;
                var pending = json.pending || 0;

                var elTotal   = document.getElementById('videoTransTotal');
                var elDone    = document.getElementById('videoTransDone');
                var elPending = document.getElementById('videoTransPending');
                if (elTotal)   elTotal.textContent   = total;
                if (elDone)    elDone.textContent    = done;
                if (elPending) elPending.textContent = pending;
            })
            .catch(function() {});
    }

    function fetchArticleVideoStats() {
        var fd = new FormData();
        fd.append('action', 'article_video_stats');

        fetch('tools_image_stats.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (!json || !json.success) return;
                var total   = json.total || 0;
                var done    = json.transcoded || 0;
                var pending = json.pending || 0;

                var elTotal   = document.getElementById('articleVideoTransTotal');
                var elDone    = document.getElementById('articleVideoTransDone');
                var elPending = document.getElementById('articleVideoTransPending');
                if (elTotal)   elTotal.textContent   = total;
                if (elDone)    elDone.textContent    = done;
                if (elPending) elPending.textContent = pending;
            })
            .catch(function() {});
    }

    function startOptimize() {
        var excludeAlbums = document.getElementById('excludeAlbums').checked ? 1 : 0;
        var excludeImages = document.getElementById('excludeImages').checked ? 1 : 0;
        var btn = document.getElementById('btnOptimize');
        var barWrap = document.getElementById('optProgressWrap');
        var bar = document.getElementById('optProgressBar');
        var text = document.getElementById('optProgressText');

        btn.disabled = true;
        barWrap.style.display = 'block';
        bar.style.width = '0%';
        text.textContent = '准备压缩...';

        // 先获取总数，便于计算百分比
        var total = 0;
        (function initTotal() {
            var fd = new FormData();
            fd.append('action', 'stats');
            fd.append('exclude_albums', excludeAlbums);
            fd.append('exclude_images', excludeImages);
            fetch('tools_image_stats.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
                .then(function(res) { return res.json(); })
                .then(function(json) {
                    if (!json || !json.success) {
                        btn.disabled = false;
                        return;
                    }
                    total = json.not_optimized || 0;
                    if (!total) {
                        bar.style.width = '100%';
                        text.textContent = '当前已无未压缩图片';
                        btn.disabled = false;
                        fetchStats();
                        return;
                    }
                    // 真正开始分批压缩
                    batchOptimize(total, 0);
                })
                .catch(function() {
                    btn.disabled = false;
                });
        })();

        function batchOptimize(totalCount, doneSoFar) {
            var fd = new FormData();
            fd.append('action', 'optimize_batch');
            fd.append('exclude_albums', excludeAlbums);
            fd.append('exclude_images', excludeImages);
            fd.append('limit', 50);

            fetch('tools_image_stats.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
                .then(function(res) { return res.json(); })
                .then(function(json) {
                    if (!json || !json.success) {
                        btn.disabled = false;
                        return;
                    }
                    var processed = json.processed || 0;
                    var remaining = json.remaining || 0;
                    var done = doneSoFar + processed;
                    var totalSafe = totalCount || (done + remaining);
                    var percent = totalSafe ? Math.min(100, (done / totalSafe) * 100) : 100;

                    bar.style.width = percent.toFixed(1) + '%';
                    text.textContent = '已压缩 ' + done + ' 张，剩余约 ' + remaining + ' 张...';

                    if (remaining > 0 && processed > 0) {
                        setTimeout(function() {
                            batchOptimize(totalSafe, done);
                        }, 300);
                    } else {
                        text.textContent = '压缩完成，共处理 ' + done + ' 张图片';
                        btn.disabled = false;
                        fetchStats();
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var excludeAlbums = document.getElementById('excludeAlbums');
        var excludeImages = document.getElementById('excludeImages');
        var btnOptimize   = document.getElementById('btnOptimize');

        if (excludeAlbums && excludeImages) {
            excludeAlbums.addEventListener('change', fetchStats);
            excludeImages.addEventListener('change', fetchStats);
        }
        if (btnOptimize) {
            btnOptimize.addEventListener('click', startOptimize);
        }

        // 初始化图片压缩与视频转码统计
        fetchStats();
        fetchVideoStats();
        fetchArticleVideoStats();
    });
    })();

    // 视频一键转码：单次批处理 + 进度展示（可多次点击）
    (function () {
        var btn    = document.getElementById('btnVideoTranscode');
        var wrap   = document.getElementById('videoTransProgressWrap');
        var bar    = document.getElementById('videoTransProgressBar');
        var textEl = document.getElementById('videoTransProgressText');
        if (!btn || !wrap || !bar || !textEl) return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            wrap.style.display = 'block';
            bar.style.width = '0%';
            textEl.textContent = '正在执行本次视频转码批处理...';

            var fd = new FormData();
            fd.append('action', 'video_transcode_batch');
            fd.append('limit', <?php echo (int)$batchLimit; ?>);

            fetch('tools_image_stats.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
                .then(function (res) {
                    return res.json().catch(function () {
                        // 解析失败视为本次未处理任何视频
                        return { processed: 0, pending: 0 };
                    });
                })
                .then(function (json) {
                    var processed = (json && typeof json.processed === 'number') ? json.processed : 0;
                    var remaining = (json && typeof json.pending === 'number') ? json.pending : 0;

                    // 简单进度：本次处理占 100%，剩余数量直接展示给用户
                    bar.style.width = processed > 0 ? '100%' : '0%';

                    if (processed > 0) {
                        textEl.textContent = '本次已转码 ' + processed + ' 个视频，剩余约 ' + remaining + ' 个（可再次点击继续处理）';
                    } else if (remaining > 0) {
                        textEl.textContent = '本次未处理任何视频，请稍后重试或检查服务器配置';
                    } else {
                        textEl.textContent = '当前已无待转码视频';
                    }

                    btn.disabled = false;
                    fetchVideoStats();
                })
                .catch(function () {
                    // 网络或解析异常时，不覆盖前面的提示，只恢复按钮，避免给出误导性的“失败”提示
                    btn.disabled = false;
                });
        });
    })();

    // 文章视频一键转码：单次批处理 + 进度展示（可多次点击）
    (function () {
        var btn    = document.getElementById('btnArticleVideoTranscode');
        var wrap   = document.getElementById('articleVideoTransProgressWrap');
        var bar    = document.getElementById('articleVideoTransProgressBar');
        var textEl = document.getElementById('articleVideoTransProgressText');
        if (!btn || !wrap || !bar || !textEl) return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            wrap.style.display = 'block';
            bar.style.width = '0%';
            textEl.textContent = '正在执行本次文章视频转码批处理...';

            var fd = new FormData();
            fd.append('action', 'article_video_transcode_batch');
            fd.append('limit', <?php echo (int)$batchLimit; ?>);

            fetch('tools_image_stats.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            })
                .then(function (res) {
                    return res.json().catch(function () {
                        return { processed: 0, pending: 0 };
                    });
                })
                .then(function (json) {
                    var processed = (json && typeof json.processed === 'number') ? json.processed : 0;
                    var remaining = (json && typeof json.pending === 'number') ? json.pending : 0;

                    bar.style.width = processed > 0 ? '100%' : '0%';

                    if (processed > 0) {
                        textEl.textContent = '本次已为 ' + processed + ' 个文章视频完成转码，剩余约 ' + remaining + ' 个（可再次点击继续处理）';
                    } else if (remaining > 0) {
                        textEl.textContent = '本次未处理任何文章视频，请稍后重试或检查服务器配置';
                    } else {
                        textEl.textContent = '当前已无待转码的文章视频';
                    }

                    btn.disabled = false;
                    fetchArticleVideoStats();
                })
                .catch(function () {
                    btn.disabled = false;
                });
        });
    })();
</script>

<?php include __DIR__ . '/footer.php'; ?>
