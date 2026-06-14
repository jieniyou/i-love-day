<?php
// 新版后台 - 相册图片管理（移动端优先）
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
$partner     = $auth->getPartner();

$albumId = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 情侣双方均可管理相册，按相册 ID 获取
$album = $db->fetch(
    "SELECT * FROM albums WHERE id = :id",
    ['id' => $albumId]
);

if (!$album) {
    header('Location: /albums.php');
    exit;
}

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

// 读取相册权限设置；若无记录或表不存在，则默认允许另一半编辑
$permRow = null;
try {
    $permRow = $db->fetch(
        "SELECT allow_partner_edit FROM album_permissions WHERE album_id = :album_id",
        ['album_id' => $albumId]
    );
} catch (Exception $e) {
    $permRow = null;
}

// 相册权限控制：创建者永远可编辑；另一半仅在允许时可编辑
$isOwner = $currentUser && $album['user_id'] == $currentUser['id'];
$partnerId = $partner['id'] ?? null;
$isPartnerUser = $currentUser && $partnerId && $album['user_id'] == $partnerId;
$allowPartnerEdit = $permRow ? (int) $permRow['allow_partner_edit'] : 1;
$canEditAlbum = $isOwner || ($isPartnerUser && $allowPartnerEdit);

if (!$canEditAlbum) {
    header('Location: /admin/albums.php?error=' . urlencode('你没有权限管理这个相册'));
    exit;
}

$error   = '';
$success = '';

function isAjaxRequest()
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// 重定向后的成功提示
if (isset($_GET['success']) && $_GET['success'] !== '') {
    $success = $_GET['success'];
}

// 更新相册基本信息
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['album_update'])) {
    require_csrf();

    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isEncrypted = isset($_POST['is_encrypted']) ? 1 : 0;
    $keepOriginal = isset($_POST['keep_original_quality']) ? 1 : 0;
    $allowPartnerEditNew = $allowPartnerEdit;
    if ($isOwner) {
        $allowPartnerEditNew = isset($_POST['allow_partner_edit']) ? 1 : 0;
    }

    if ($name === '') {
        $error = '相册名称不能为空';
    } else {
        $updateData = [
            'name'                => $name,
            'description'         => $description !== '' ? $description : null,
            'is_encrypted'        => $isEncrypted,
            'keep_original_quality'=> $keepOriginal,
            'updated_at'          => date('Y-m-d H:i:s'),
        ];

        $db->update('albums', $updateData, 'id = :id', [
            'id' => $albumId,
        ]);

        // 同步更新相册权限（仅创建者可修改，若权限表不存在则静默忽略）
        if ($isOwner) {
            try {
                $db->query("
                    INSERT INTO album_permissions (album_id, allow_partner_edit, updated_at)
                    VALUES (:album_id, :allow_partner_edit, :updated_at)
                    ON DUPLICATE KEY UPDATE
                        allow_partner_edit = VALUES(allow_partner_edit),
                        updated_at = VALUES(updated_at)
                ", [
                    'album_id'          => $albumId,
                    'allow_partner_edit'=> $allowPartnerEditNew,
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]);
            } catch (Exception $e) {
                // 忽略权限表写入失败
            }
        }

        header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode('相册信息已更新'));
        exit;
    }
}

// 删除相册封面
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cover_action']) && $_POST['cover_action'] === 'delete_cover') {
    require_csrf();

    if (!empty($album['cover_image'])) {
        deleteFile($album['cover_image']);
        $db->update('albums', [
            'cover_image' => null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ], 'id = :id', [
            'id' => $albumId,
        ]);

        header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode('封面已删除'));
        exit;
    } else {
        $error = '当前相册没有封面可以删除';
    }
}

// 上传/更新相册封面
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cover_action']) && $_POST['cover_action'] === 'upload_cover' && isset($_FILES['cover_image'])) {
    require_csrf();

    if ($_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $file = [
            'name'     => $_FILES['cover_image']['name'],
            'type'     => $_FILES['cover_image']['type'],
            'tmp_name' => $_FILES['cover_image']['tmp_name'],
            'error'    => $_FILES['cover_image']['error'],
            'size'     => $_FILES['cover_image']['size'],
        ];

        $upload = uploadFile($file, 'albums/' . $albumId);
        if (!empty($upload['success'])) {
            if (!empty($album['cover_image'])) {
                deleteFile($album['cover_image']);
            }

            $db->update('albums', [
                'cover_image' => $upload['path'],
                'updated_at'  => date('Y-m-d H:i:s'),
            ], 'id = :id', [
                'id' => $albumId,
            ]);

            header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode('封面已更新'));
            exit;
        } else {
            $error = '封面上传失败，请检查图片格式和大小';
        }
    } else {
        $error = '请选择要上传的封面图片';
    }
}

// 批量为选中图片/视频设置描述
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'set_description') {
    require_csrf();
    $ids = isset($_POST['delete_images']) ? (array) $_POST['delete_images'] : [];
    $ids = array_map('intval', $ids);
    $bulkDesc = trim($_POST['bulk_description'] ?? '');
    $bulkDesc = $bulkDesc !== '' ? mb_substr($bulkDesc, 0, 255) : null;

    if (!empty($ids)) {
        $updatedImageCount = 0;
        $updatedVideoCount = 0;

        // 先更新图片描述（保持原有行为）
        foreach ($ids as $imageId) {
            if ($imageId <= 0) {
                continue;
            }
            $db->update('album_images', [
                'description' => $bulkDesc,
            ], 'id = :id AND album_id = :album_id', [
                'id'       => $imageId,
                'album_id' => $albumId,
            ]);
            $updatedImageCount++;
        }

        // 可选：若希望勾选同时应用到视频，可改成单独的 video 复选框数组；
        // 目前按你的使用习惯，批量操作主要针对图片，这里不自动修改视频描述。

        if ($updatedImageCount > 0 || $updatedVideoCount > 0) {
            if ($bulkDesc === null) {
                $msg = "已清除 {$updatedImageCount} 张图片的描述";
            } else {
                $msg = "已为 {$updatedImageCount} 张图片设置描述";
            }

            if (isAjaxRequest() || isset($_POST['ajax'])) {
                jsonResponse(['success' => true, 'message' => $msg]);
            } else {
                header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode($msg));
                exit;
            }
        } else {
            $error = '没有检测到需要更新描述的图片';
            if (isAjaxRequest() || isset($_POST['ajax'])) {
                jsonResponse(['success' => false, 'message' => $error]);
            }
        }
    } else {
        $error = '请先选择要批量设置描述的图片';
        if (isAjaxRequest() || isset($_POST['ajax'])) {
            jsonResponse(['success' => false, 'message' => $error]);
        }
    }
}

// 单张保存图片描述
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_single_description'])) {
    require_csrf();
    $imageId = (int) ($_POST['save_single_description'] ?? 0);
    if ($imageId > 0) {
        $descText = '';
        if (isset($_POST['descriptions']) && is_array($_POST['descriptions'])) {
            $descText = (string) ($_POST['descriptions'][$imageId] ?? '');
        }
        $descText = trim($descText);
        $descText = $descText !== '' ? mb_substr($descText, 0, 255) : null;

        $updateData = [
            'description' => $descText,
        ];
        // 同步更新单张图片的跳过压缩标记
        if (isset($_POST['skip_optimize_flags']) && is_array($_POST['skip_optimize_flags'])) {
            $updateData['skip_optimize'] = !empty($_POST['skip_optimize_flags'][$imageId]) ? 1 : 0;
        }

        $db->update('album_images', $updateData, 'id = :id AND album_id = :album_id', [
            'id'       => $imageId,
            'album_id' => $albumId,
        ]);

        $msg = $descText === null ? '已清除该图片的描述' : '已更新该图片的描述';

        if (isAjaxRequest() || isset($_POST['ajax'])) {
            jsonResponse(['success' => true, 'message' => $msg]);
        } else {
            header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode($msg));
            exit;
        }
    } else {
        $error = '无效的图片参数，无法保存描述';
        if (isAjaxRequest() || isset($_POST['ajax'])) {
            jsonResponse(['success' => false, 'message' => $error]);
        }
    }
}

// 批量删除媒体（图片 + 视频）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete_images') {
    require_csrf();
    $imageIds = isset($_POST['delete_images']) ? (array) $_POST['delete_images'] : [];
    $videoIds = isset($_POST['delete_videos']) ? (array) $_POST['delete_videos'] : [];
    $imageIds = array_map('intval', $imageIds);
    $videoIds = array_map('intval', $videoIds);

    if (!empty($imageIds) || !empty($videoIds)) {
        $deletedImages = 0;
        $deletedVideos = 0;

        // 删除选中的图片
        foreach ($imageIds as $imageId) {
            if ($imageId <= 0) {
                continue;
            }
            $image = $db->fetch(
                "SELECT * FROM album_images WHERE id = :id AND album_id = :album_id",
                ['id' => $imageId, 'album_id' => $albumId]
            );
            if ($image) {
                deleteFile($image['image_path']);
                if (!empty($image['thumbnail_path'])) {
                    deleteFile($image['thumbnail_path']);
                }
                $db->delete('album_images', 'id = :id', ['id' => $imageId]);
                // 同步删除上传者映射记录（若表存在）
                try {
                    $db->delete('album_image_uploads', 'image_id = :image_id', ['image_id' => $imageId]);
                } catch (Exception $e) {
                    // 忽略删除失败
                }
                $deletedImages++;
            }
        }

        // 删除选中的视频
        foreach ($videoIds as $videoId) {
            if ($videoId <= 0) {
                continue;
            }
            $video = $db->fetch(
                "SELECT * FROM album_videos WHERE id = :id AND album_id = :album_id",
                ['id' => $videoId, 'album_id' => $albumId]
            );
            if ($video) {
                // 删除当前视频使用的播放文件
                if (!empty($video['video_path'])) {
                    deleteFile($video['video_path']);
                }
                // 若存在原始视频文件路径（批量转码后记录在 original_video_path 中），一并删除
                if (!empty($video['original_video_path'])) {
                    deleteFile($video['original_video_path']);
                }
                if (!empty($video['poster_path'])) {
                    deleteFile($video['poster_path']);
                    $posterDir  = trim(dirname($video['poster_path']), '/');
                    $posterFile = basename($video['poster_path']);
                    if ($posterDir !== '' && $posterFile !== '') {
                        $thumbRelative = $posterDir . '/thumbs/' . $posterFile;
                        deleteFile($thumbRelative);
                    }
                }
                $db->delete('album_videos', 'id = :id', ['id' => $videoId]);
                $deletedVideos++;
            }
        }

        if ($deletedImages > 0 || $deletedVideos > 0) {
            $msgParts = [];
            if ($deletedImages > 0) {
                $msgParts[] = "已批量删除 {$deletedImages} 张图片";
            }
            if ($deletedVideos > 0) {
                $msgParts[] = "已批量删除 {$deletedVideos} 个视频";
            }
            $msg = implode('，', $msgParts);
            header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode($msg));
            exit;
        } else {
            $error = '没有成功删除任何媒体，请重试';
        }
    } else {
        $error = '请先选择要删除的媒体';
    }
}

// 上传图片（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['images'])) {
    require_csrf();

    $imageDescription = trim($_POST['image_description'] ?? '');
    if ($imageDescription !== '') {
        // 截断到 255 个字符，避免超出数据库字段长度
        $imageDescription = mb_substr($imageDescription, 0, 255);
    } else {
        $imageDescription = null;
    }

    // 本次上传是否跳过图片压缩（仅影响当前请求）
    if (!empty($_POST['skip_optimize_this_upload'])) {
        $GLOBALS['YC_SKIP_IMAGE_OPTIMIZE'] = true;
    }

    $uploaded = 0;
    $skipThisUpload = !empty($_POST['skip_optimize_this_upload']);
    $imageOptimizeEnabled = get_setting('image_optimize_enabled', '1');
    $keepOriginalAlbum = !empty($album['keep_original_quality']);

    foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
            $file = [
                'name'     => $_FILES['images']['name'][$key],
                'type'     => $_FILES['images']['type'][$key],
                'tmp_name' => $tmpName,
                'error'    => $_FILES['images']['error'][$key],
                'size'     => $_FILES['images']['size'][$key],
            ];

            $upload = uploadFile($file, 'albums/' . $albumId);
            if (!empty($upload['success'])) {
                // 尝试推断缩略图路径：uploads/albums/{id}/thumbs/{filename}
                $thumbnailPath = null;
                if (!empty($upload['path'])) {
                    $relative = ltrim($upload['path'], '/');
                    $thumbnailRelative = preg_replace('#^(.*/)([^/]+)$#', '$1thumbs/$2', $relative);
                    if ($thumbnailRelative && $thumbnailRelative !== $relative) {
                        $thumbAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . $thumbnailRelative;
                        if (is_file($thumbAbs)) {
                            $thumbnailPath = $thumbnailRelative;
                        }
                    }
                }

                // 根据当前全局/相册/本次上传的开关，估算是否已执行压缩，用于标记 is_optimized
                $markOptimized = (
                    (string)$imageOptimizeEnabled === '1' &&
                    !$skipThisUpload &&
                    !$keepOriginalAlbum
                );

                $imageId = $db->insert('album_images', [
                    'album_id'       => $albumId,
                    'image_path'     => $upload['path'],
                    'thumbnail_path' => $thumbnailPath,
                    'is_optimized'   => $markOptimized ? 1 : 0,
                    'skip_optimize'  => $skipThisUpload ? 1 : 0,
                    'description'    => $imageDescription,
                    'sort_order'     => 0,
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
                $uploaded++;

                // 记录图片上传者（若映射表创建失败，则忽略）
                if (!empty($imageId) && !empty($currentUser['id'])) {
                    try {
                        $db->query("
                            INSERT INTO album_image_uploads (image_id, user_id, created_at)
                            VALUES (:image_id, :user_id, :created_at)
                            ON DUPLICATE KEY UPDATE
                                user_id = VALUES(user_id),
                                created_at = VALUES(created_at)
                        ", [
                            'image_id'  => $imageId,
                            'user_id'   => $currentUser['id'],
                            'created_at'=> date('Y-m-d H:i:s'),
                        ]);
                    } catch (Exception $e) {
                        // 忽略记录失败
                    }
                }
            }
        }
    }

    // 清理本次请求的临时跳过标记（保险起见）
    if (isset($GLOBALS['YC_SKIP_IMAGE_OPTIMIZE'])) {
        unset($GLOBALS['YC_SKIP_IMAGE_OPTIMIZE']);
    }

    if ($uploaded > 0) {
        $msg = "成功上传 {$uploaded} 张图片";

        if (isAjaxRequest() || isset($_POST['ajax'])) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success'  => true,
                'uploaded' => $uploaded,
                'message'  => $msg,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode($msg));
        exit;
    }

    if (isAjaxRequest() || isset($_POST['ajax'])) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success'  => true,
            'uploaded' => 0,
            'message'  => '本次没有新增成功的图片，如已看到图片出现在列表中，可忽略此提示。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $error = '本次没有新增成功的图片，请检查文件后重试';
}

// 上传视频（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['video_upload']) && isset($_FILES['videos'])) {
    require_csrf();

    $videoDescription = trim($_POST['video_description'] ?? '');
    if ($videoDescription !== '') {
        $videoDescription = mb_substr($videoDescription, 0, 255);
    } else {
        $videoDescription = null;
    }

    // 读取“视频上传仅受服务器限制”开关
    $ignoreSiteLimit = (string) get_setting('video_upload_ignore_site_limit', '0') === '1';

    $maxSize = null;
    if ($ignoreSiteLimit) {
        $limits = [];
        foreach (['upload_max_filesize', 'post_max_size'] as $iniKey) {
            $val = ini_get($iniKey);
            if ($val !== false && function_exists('parse_php_size_to_bytes')) {
                $limits[] = parse_php_size_to_bytes($val);
            }
        }
        if (!empty($limits)) {
            $maxSize = min($limits);
        }
    }
    if ($maxSize === null) {
        $maxSize = function_exists('get_max_upload_size_bytes') ? get_max_upload_size_bytes() : (defined('MAX_FILE_SIZE') ? (int) MAX_FILE_SIZE : 15 * 1024 * 1024);
    }

    $uploadedVideos = 0;
    $errorMsg = '';

    // 允许的视频类型与扩展名
    $allowedTypes = ['video/mp4', 'video/webm', 'video/ogg'];
    $allowedExt   = ['mp4', 'webm', 'ogg'];

    foreach ($_FILES['videos']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['videos']['error'][$key] !== UPLOAD_ERR_OK) {
            continue;
        }

        $size = (int) $_FILES['videos']['size'][$key];
        if ($size <= 0) {
            continue;
        }
        if ($size > $maxSize) {
            $maxMb = round($maxSize / 1024 / 1024, 1);
            $errorMsg = $ignoreSiteLimit
                ? '视频大小超出限制，当前服务器允许的单个文件最大约 ' . $maxMb . 'MB，请压缩后重试或联系管理员调整服务器上传限制。'
                : '视频大小超出限制，当前站点的单文件上传大小约为 ' . $maxMb . 'MB。你可以在“系统设置 → 上传与其他 → 单文件最大上传大小（MB）”中调整，或开启“视频上传仅受服务器限制”开关后再尝试上传。';
            continue;
        }

        $name = $_FILES['videos']['name'][$key];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errorMsg = '不支持的视频格式，仅支持 MP4 / WebM / Ogg。';
            continue;
        }

        // 检测 MIME 类型
        $tmpFile = $tmpName;
        if (function_exists('finfo_open')) {
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmpFile);
            finfo_close($finfo);
        } else {
            $mimeType = $_FILES['videos']['type'][$key] ?? '';
        }
        if (!in_array($mimeType, $allowedTypes, true)) {
            $errorMsg = '不支持的视频格式，仅支持 MP4 / WebM / Ogg。';
            continue;
        }

        // 保存到 uploads/albums/{album_id}/videos 目录
        $subDir     = 'albums/' . $albumId . '/videos';
        $uploadPath = rtrim(UPLOAD_DIR, '/\\') . '/' . trim($subDir, '/');
        if (!is_dir($uploadPath)) {
            @mkdir($uploadPath, 0755, true);
        }

        $filename = uniqid('', true) . '.' . $ext;
        $filepath = $uploadPath . '/' . $filename;

        if (!move_uploaded_file($tmpFile, $filepath)) {
            $errorMsg = '保存视频失败，请稍后重试。';
            continue;
        }

        // 原始上传视频的相对路径（不带 /uploads/ 前缀）
        $originalRelative = trim($subDir, '/') . '/' . $filename;
        $originalRelative = ltrim($originalRelative, '/');

        // 默认播放路径为原始文件路径；若后续转码成功，再切换为转码后的路径
        $finalVideoRelative = $originalRelative;
        $isTranscodedFlag   = 0;

        // 尝试使用 ffmpeg 生成一份统一编码的视频文件（H.264 + AAC），用于前台播放
        if (function_exists('shell_exec')) {
            $transcodedSubDir = 'albums/' . $albumId . '/videos/transcoded';
            $transcodedDirAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . trim($transcodedSubDir, '/');
            if (!is_dir($transcodedDirAbs)) {
                @mkdir($transcodedDirAbs, 0755, true);
            }
            if (is_dir($transcodedDirAbs)) {
                $baseName        = pathinfo($filename, PATHINFO_FILENAME);
                $transcodedFile  = $baseName . '_h264.mp4';
                $transcodedAbs   = $transcodedDirAbs . '/' . $transcodedFile;

                $ffmpeg = 'ffmpeg';
                $cmd = $ffmpeg
                    . ' -y'
                    . ' -i ' . escapeshellarg($filepath)
                    . ' -c:v libx264 -preset medium -crf 23'
                    . ' -c:a aac -b:a 128k'
                    . ' -movflags +faststart '
                    . escapeshellarg($transcodedAbs)
                    . ' 2>&1';
                @shell_exec($cmd);

                if (is_file($transcodedAbs)) {
                    $finalVideoRelative = trim($transcodedSubDir, '/') . '/' . $transcodedFile;
                    $isTranscodedFlag   = 1;
                }
            }
        }

        // 尝试使用 ffmpeg 为视频生成一张封面图：uploads/albums/{albumId}/videos/posters/{basename}.jpg
        $posterRelative = null;
        if (function_exists('shell_exec')) {
            $postersDir = rtrim(UPLOAD_DIR, '/\\') . '/albums/' . $albumId . '/videos/posters';
            if (!is_dir($postersDir)) {
                @mkdir($postersDir, 0755, true);
            }
            if (is_dir($postersDir)) {
                $baseName   = pathinfo($filename, PATHINFO_FILENAME);
                $posterFile = $baseName . '.jpg';
                $posterAbs  = $postersDir . '/' . $posterFile;

                $ffmpeg = 'ffmpeg';
                $cmd = $ffmpeg
                    . ' -y'
                    . ' -ss 2'
                    . ' -i ' . escapeshellarg($filepath)
                    . ' -frames:v 1 '
                    . escapeshellarg($posterAbs)
                    . ' 2>&1';
                @shell_exec($cmd);

                if (is_file($posterAbs)) {
                    // 生成成功后，按全局图片优化规则对封面图做一次压缩与缩放
                    if (function_exists('optimize_uploaded_image')) {
                        optimize_uploaded_image($posterAbs);
                    }

                    $posterRelative = 'albums/' . $albumId . '/videos/posters/' . $posterFile;
                    $posterRelative = ltrim($posterRelative, '/');
                }
            }
        }

        $videoId = $db->insert('album_videos', [
            'album_id'            => $albumId,
            'video_path'          => $finalVideoRelative,
            'poster_path'         => $posterRelative,
            'description'         => $videoDescription,
            'uploader_id'         => !empty($currentUser['id']) ? (int)$currentUser['id'] : null,
            'original_video_path' => $isTranscodedFlag ? $originalRelative : null,
            'is_transcoded'       => $isTranscodedFlag ? 1 : 0,
            'sort_order'          => 0,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        // 若转码成功，则可以安全删除原始上传文件以节省磁盘空间（仅保留转码后的视频）
        if ($isTranscodedFlag && !empty($originalRelative)) {
            deleteFile($originalRelative);
        }

        $uploadedVideos++;
    }

    if ($uploadedVideos > 0) {
        $msg = "成功上传 {$uploadedVideos} 个视频";

        if (isAjaxRequest() || isset($_POST['ajax'])) {
            // 查询刚刚插入的视频用于前端热更新（按创建时间倒序取最新若干条）
            try {
                $newVideos = $db->fetchAll(
                    "SELECT v.*, u.nickname AS uploader_nickname, u.username AS uploader_username
                     FROM album_videos v
                     LEFT JOIN users u ON v.uploader_id = u.id
                     WHERE v.album_id = :album_id
                     ORDER BY v.id DESC
                     LIMIT :limit",
                    [
                        'album_id' => $albumId,
                        'limit'    => $uploadedVideos,
                    ]
                );
            } catch (Exception $e) {
                $newVideos = [];
            }

            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success'  => true,
                'uploaded' => $uploadedVideos,
                'message'  => $msg,
                'videos'   => $newVideos,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode($msg));
        exit;
    }

    if (isAjaxRequest() || isset($_POST['ajax'])) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success'  => false,
            'uploaded' => 0,
            'message'  => $errorMsg !== '' ? $errorMsg : '本次没有新增成功的视频，请检查文件后重试',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $error = $errorMsg !== '' ? $errorMsg : '本次没有新增成功的视频，请检查文件后重试';
}

// 为当前相册已有视频补齐封面图（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_video_posters'])) {
    require_csrf();

    $generated = 0;
    $failed    = 0;

    try {
        $existingVideos = $db->fetchAll(
            "SELECT * FROM album_videos WHERE album_id = :album_id",
            ['album_id' => $albumId]
        );

        foreach ($existingVideos as $video) {
            $videoPath   = $video['video_path'] ?? '';
            $posterPath  = $video['poster_path'] ?? '';
            $videoPath   = trim($videoPath);
            $posterPath  = trim($posterPath);

            // 若已存在封面且文件存在，则跳过
            if ($posterPath !== '') {
                $posterAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($posterPath, '/');
                if (is_file($posterAbs)) {
                    continue;
                }
            }

            if ($videoPath === '') {
                $failed++;
                continue;
            }

            $videoAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($videoPath, '/');
            if (!is_file($videoAbs)) {
                $failed++;
                continue;
            }

            if (!function_exists('shell_exec')) {
                $failed++;
                continue;
            }

            $postersDir = rtrim(UPLOAD_DIR, '/\\') . '/albums/' . $albumId . '/videos/posters';
            if (!is_dir($postersDir)) {
                @mkdir($postersDir, 0755, true);
            }
            if (!is_dir($postersDir)) {
                $failed++;
                continue;
            }

            $baseName   = pathinfo($videoAbs, PATHINFO_FILENAME);
            $posterFile = $baseName . '.jpg';
            $posterAbs  = $postersDir . '/' . $posterFile;

            $ffmpeg = 'ffmpeg';
            $cmd = $ffmpeg
                . ' -y'
                . ' -ss 2'
                . ' -i ' . escapeshellarg($videoAbs)
                . ' -frames:v 1 '
                . escapeshellarg($posterAbs)
                . ' 2>&1';
            @shell_exec($cmd);

            if (is_file($posterAbs)) {
                // 为补齐的封面图同样执行一次图片优化与缩略图/WebP 生成
                if (function_exists('optimize_uploaded_image')) {
                    optimize_uploaded_image($posterAbs);
                }

                $posterRelative = 'albums/' . $albumId . '/videos/posters/' . $posterFile;
                $posterRelative = ltrim($posterRelative, '/');

                $db->update('album_videos', [
                    'poster_path' => $posterRelative,
                ], 'id = :id', ['id' => $video['id']]);

                $generated++;
            } else {
                $failed++;
            }
        }

        if ($generated > 0 && $failed === 0) {
            $success = "已为 {$generated} 个视频补齐封面图";
        } elseif ($generated > 0 && $failed > 0) {
            $success = "已为 {$generated} 个视频补齐封面图，另有 {$failed} 个视频生成封面失败";
        } else {
            $error = '没有成功生成任何封面图，请检查服务器是否已安装 ffmpeg 或稍后重试';
        }
    } catch (Exception $e) {
        $error = '生成封面图时发生错误：' . $e->getMessage();
    }
}

// 删除单张图片（POST，带 CSRF）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['single_delete'])) {
    require_csrf();

    $imageId = intval($_POST['single_delete']);
    if ($imageId > 0) {
        $image = $db->fetch(
            "SELECT * FROM album_images WHERE id = :id AND album_id = :album_id",
            ['id' => $imageId, 'album_id' => $albumId]
        );
        if ($image) {
            deleteFile($image['image_path']);
            if (!empty($image['thumbnail_path'])) {
                deleteFile($image['thumbnail_path']);
            }
            $db->delete('album_images', 'id = :id', ['id' => $imageId]);
            // 同步删除上传者映射记录（若表存在）
            try {
                $db->delete('album_image_uploads', 'image_id = :image_id', ['image_id' => $imageId]);
            } catch (Exception $e) {
                // 忽略删除失败
            }

            header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode('图片已删除'));
            exit;
        } else {
            $error = '要删除的图片不存在或已被删除';
        }
    } else {
        $error = '无效的图片参数';
    }
}

// 单个视频保存描述（AJAX）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_single_video_description'])) {
    require_csrf();

    $videoId = intval($_POST['save_single_video_description']);
    if ($videoId > 0) {
        $video = $db->fetch(
            "SELECT * FROM album_videos WHERE id = :id AND album_id = :album_id",
            ['id' => $videoId, 'album_id' => $albumId]
        );
        if ($video) {
            $descKey = 'video_descriptions';
            $allDescs = $_POST[$descKey] ?? [];
            $desc = '';
            if (is_array($allDescs) && array_key_exists($videoId, $allDescs)) {
                $desc = trim((string)$allDescs[$videoId]);
            }
            $desc = $desc !== '' ? mb_substr($desc, 0, 255) : null;

            $db->update('album_videos', [
                'description' => $desc,
            ], 'id = :id AND album_id = :album_id', [
                'id'       => $videoId,
                'album_id' => $albumId,
            ]);

            if (isAjaxRequest() || isset($_POST['ajax'])) {
                jsonResponse(['success' => true, 'message' => '视频描述已保存']);
            }
        } else {
            if (isAjaxRequest() || isset($_POST['ajax'])) {
                jsonResponse(['success' => false, 'message' => '要更新描述的视频不存在或已被删除']);
            }
        }
    } else {
        if (isAjaxRequest() || isset($_POST['ajax'])) {
            jsonResponse(['success' => false, 'message' => '无效的视频参数']);
        }
    }
}

// 单个视频：更新封面图（支持自定义上传或前端截帧上传）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_video_poster'])) {
    require_csrf();

    $videoId = intval($_POST['update_video_poster']);
    if ($videoId <= 0) {
        if (isAjaxRequest() || isset($_POST['ajax'])) {
            jsonResponse(['success' => false, 'message' => '无效的视频参数']);
        } else {
            $error = '无效的视频参数，无法更新封面';
        }
    } else {
        $video = $db->fetch(
            "SELECT * FROM album_videos WHERE id = :id AND album_id = :album_id",
            ['id' => $videoId, 'album_id' => $albumId]
        );

        if (!$video) {
            if (isAjaxRequest() || isset($_POST['ajax'])) {
                jsonResponse(['success' => false, 'message' => '要更新封面的视频不存在或已被删除']);
            } else {
                $error = '要更新封面的视频不存在或已被删除';
            }
        } else {
            if (empty($_FILES['cover_file']) || $_FILES['cover_file']['error'] !== UPLOAD_ERR_OK) {
                $msg = '请先选择要上传的封面图片';
                if (isAjaxRequest() || isset($_POST['ajax'])) {
                    jsonResponse(['success' => false, 'message' => $msg]);
                } else {
                    $error = $msg;
                }
            } else {
                $file = [
                    'name'     => $_FILES['cover_file']['name'],
                    'type'     => $_FILES['cover_file']['type'],
                    'tmp_name' => $_FILES['cover_file']['tmp_name'],
                    'error'    => $_FILES['cover_file']['error'],
                    'size'     => $_FILES['cover_file']['size'],
                ];

                // 将封面统一存放在 albums/{albumId}/videos/posters 目录下
                $upload = uploadFile($file, 'albums/' . $albumId . '/videos/posters');
                if (empty($upload['success'])) {
                    $msg = !empty($upload['message']) ? $upload['message'] : '封面上传失败，请检查图片格式和大小';
                    if (isAjaxRequest() || isset($_POST['ajax'])) {
                        jsonResponse(['success' => false, 'message' => $msg]);
                    } else {
                        $error = $msg;
                    }
                } else {
                    // 删除旧封面文件及其缩略图（无论旧封面来源于自动截帧还是手动上传）
                    $oldPosterPath = trim($video['poster_path'] ?? '');
                    if ($oldPosterPath !== '') {
                        deleteFile($oldPosterPath);
                        $posterDir  = trim(dirname($oldPosterPath), '/');
                        $posterFile = basename($oldPosterPath);
                        if ($posterDir !== '' && $posterFile !== '') {
                            $thumbRelative = $posterDir . '/thumbs/' . $posterFile;
                            deleteFile($thumbRelative);
                        }
                    }

                    $db->update('album_videos', [
                        'poster_path' => $upload['path'],
                    ], 'id = :id AND album_id = :album_id', [
                        'id'       => $videoId,
                        'album_id' => $albumId,
                    ]);

                    $newPosterUrl = upload_url($upload['path']);
                    $msg          = '视频封面已更新';

                    if (isAjaxRequest() || isset($_POST['ajax'])) {
                        jsonResponse([
                            'success'     => true,
                            'message'     => $msg,
                            'poster_path' => $upload['path'],
                            'poster_url'  => $newPosterUrl,
                        ]);
                    } else {
                        header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode($msg));
                        exit;
                    }
                }
            }
        }
    }
}

// 删除单个视频（POST，带 CSRF）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['single_video_delete'])) {
    require_csrf();

    $videoId = intval($_POST['single_video_delete']);
    if ($videoId > 0) {
        $video = $db->fetch(
            "SELECT * FROM album_videos WHERE id = :id AND album_id = :album_id",
            ['id' => $videoId, 'album_id' => $albumId]
        );
        if ($video) {
            // 删除当前视频使用的播放文件
            if (!empty($video['video_path'])) {
                deleteFile($video['video_path']);
            }
            // 若存在原始视频文件路径（批量转码后记录在 original_video_path 中），一并删除
            if (!empty($video['original_video_path'])) {
                deleteFile($video['original_video_path']);
            }
            if (!empty($video['poster_path'])) {
                // 删除封面图本身
                deleteFile($video['poster_path']);

                // 同时清理封面图所在目录下的 thumbs 缩略图（若存在）
                $posterDir  = trim(dirname($video['poster_path']), '/');
                $posterFile = basename($video['poster_path']);
                if ($posterDir !== '' && $posterFile !== '') {
                    $thumbRelative = $posterDir . '/thumbs/' . $posterFile;
                    deleteFile($thumbRelative);
                }
            }
            $db->delete('album_videos', 'id = :id', ['id' => $videoId]);

            header('Location: album_manage.php?id=' . $albumId . '&success=' . urlencode('视频已删除'));
            exit;
        } else {
            $error = '要删除的视频不存在或已被删除';
        }
    } else {
        $error = '无效的视频参数';
    }
}

// 获取当前相册所有图片（附带上传者信息）
try {
    $images = $db->fetchAll(
        "SELECT i.*,
                u.nickname AS uploader_nickname,
                u.username AS uploader_username
         FROM album_images i
         LEFT JOIN album_image_uploads au ON au.image_id = i.id
         LEFT JOIN users u ON au.user_id = u.id
         WHERE i.album_id = :album_id
         ORDER BY i.created_at DESC, i.id DESC",
        ['album_id' => $albumId]
    );
} catch (Exception $e) {
    // 回退到不带上传者信息的查询
    $images = $db->fetchAll(
        "SELECT * FROM album_images WHERE album_id = :album_id ORDER BY created_at DESC, id DESC",
        ['album_id' => $albumId]
    );
}

// 获取当前相册所有视频（用于后台展示与管理）
try {
    $videos = $db->fetchAll(
        "SELECT v.*,
                u.nickname AS uploader_nickname,
                u.username AS uploader_username
         FROM album_videos v
         LEFT JOIN users u ON v.uploader_id = u.id
         WHERE v.album_id = :album_id
         ORDER BY v.sort_order ASC, v.created_at DESC",
        ['album_id' => $albumId]
    );
} catch (Exception $e) {
    $videos = [];
}

// 统一构建媒体列表：图片 + 视频混排，按时间倒序
$mediaItems = [];

foreach ($images as $img) {
    $mediaItems[] = [
        'type'             => 'image',
        'id'               => (int) $img['id'],
        'image_path'       => $img['image_path'],
        'thumbnail_path'   => $img['thumbnail_path'] ?? null,
        'description'      => $img['description'] ?? '',
        'created_at'       => $img['created_at'] ?? null,
        'skip_optimize'    => !empty($img['skip_optimize']) ? 1 : 0,
        'uploader_nickname'=> $img['uploader_nickname'] ?? '',
        'uploader_username'=> $img['uploader_username'] ?? '',
    ];
}

foreach ($videos as $video) {
    $mediaItems[] = [
        'type'        => 'video',
        'id'          => (int) $video['id'],
        'video_path'  => $video['video_path'],
        'poster_path' => $video['poster_path'] ?? null,
        'description' => $video['description'] ?? '',
        'created_at'  => $video['created_at'] ?? null,
        'is_transcoded' => !empty($video['is_transcoded']) ? 1 : 0,
        'uploader_nickname' => $video['uploader_nickname'] ?? '',
        'uploader_username' => $video['uploader_username'] ?? '',
    ];
}

if (!empty($mediaItems)) {
    usort($mediaItems, function ($a, $b) {
        $ta = strtotime($a['created_at'] ?? '') ?: 0;
        $tb = strtotime($b['created_at'] ?? '') ?: 0;
        if ($ta === $tb) {
            return 0;
        }
        return ($ta > $tb) ? -1 : 1;
    });
}

$adminPage = 'albums';

include __DIR__ . '/header.php';
?>

    <section class="admin-page-title">
        <h1>相册：<?php echo e($album['name']); ?></h1>
        <p>上传、管理相册中的照片与视频</p>
    </section>

    <?php
    // 统计当前相册中情侣双方各自上传了多少张照片，用于后台共创提示
    $maleUploads   = 0;
    $femaleUploads = 0;
    $maleId        = null;
    $femaleId      = null;
    $creatorIsMale   = false;
    $creatorIsFemale = false;
    try {
        if (!empty($currentUser) && !empty($partner)) {
            $user1Id = null;
            $user2Id = null;
            if (!empty($currentUser['role']) && !empty($partner['role'])) {
                if ($currentUser['role'] === 'user1') {
                    $user1Id = (int) $currentUser['id'];
                    $user2Id = (int) $partner['id'];
                } else {
                    $user1Id = (int) $partner['id'];
                    $user2Id = (int) $currentUser['id'];
                }
            }

            if ($user1Id && $user2Id) {
                $maleId   = $user1Id;
                $femaleId = $user2Id;

                if (!empty($album['user_id'])) {
                    if ((int) $album['user_id'] === $maleId) {
                        $creatorIsMale = true;
                    } elseif ((int) $album['user_id'] === $femaleId) {
                        $creatorIsFemale = true;
                    }
                }

                $rows = $db->fetchAll(
                    "SELECT au.user_id, COUNT(*) AS cnt
                     FROM album_images ai
                     JOIN album_image_uploads au ON au.image_id = ai.id
                     WHERE ai.album_id = :album_id
                     GROUP BY au.user_id",
                    ['album_id' => $albumId]
                );
                foreach ($rows as $row) {
                    $uid = (int) $row['user_id'];
                    $cnt = (int) $row['cnt'];
                    if ($uid === $user1Id) {
                        $maleUploads = $cnt;
                    } elseif ($uid === $user2Id) {
                        $femaleUploads = $cnt;
                    }
                }

                // 将视频上传数量也计入共创统计
                try {
                    $videoRows = $db->fetchAll(
                        "SELECT uploader_id AS user_id, COUNT(*) AS cnt
                         FROM album_videos
                         WHERE album_id = :album_id AND uploader_id IS NOT NULL
                         GROUP BY uploader_id",
                        ['album_id' => $albumId]
                    );
                    foreach ($videoRows as $row) {
                        $uid = (int) $row['user_id'];
                        $cnt = (int) $row['cnt'];
                        if ($uid === $user1Id) {
                            $maleUploads += $cnt;
                        } elseif ($uid === $user2Id) {
                            $femaleUploads += $cnt;
                        }
                    }
                } catch (Exception $e) {
                    // 视频统计失败时忽略，保持原有图片统计
                }
            }
        }
    } catch (Exception $e) {
        $maleUploads   = 0;
        $femaleUploads = 0;
        $maleId        = null;
        $femaleId      = null;
        $creatorIsMale   = false;
        $creatorIsFemale = false;
    }
    ?>

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

    <?php if ($maleId && $femaleId): ?>
        <?php
        $maleLabelParts = [];
        if ($creatorIsMale) {
            $maleLabelParts[] = '已创建相册';
        }
        if ($maleUploads > 0) {
            $maleLabelParts[] = '上传 ' . $maleUploads . ' 张';
        } elseif (!$creatorIsMale) {
            $maleLabelParts[] = '还未上传照片';
        }
        $maleStatusText = implode(' · ', $maleLabelParts);

        $femaleLabelParts = [];
        if ($creatorIsFemale) {
            $femaleLabelParts[] = '已创建相册';
        }
        if ($femaleUploads > 0) {
            $femaleLabelParts[] = '上传 ' . $femaleUploads . ' 张';
        } elseif (!$creatorIsFemale) {
            $femaleLabelParts[] = '还未上传照片';
        }
        $femaleStatusText = implode(' · ', $femaleLabelParts);

        // 是否已经形成“共创内容”：创建者本身视为参与，其它一方有实际上传即算
        $maleParticipated   = $creatorIsMale || $maleUploads > 0;
        $femaleParticipated = $creatorIsFemale || $femaleUploads > 0;
        $hasCoContent       = ($maleParticipated && $femaleParticipated);
        ?>
        <?php if (!empty($allowPartnerEdit) || $hasCoContent): ?>
            <div class="admin-card" style="margin-bottom:0.75rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;font-size:0.85rem;color:var(--text-light);">
                    <div>
                        <div style="margin-bottom:0.25rem;">当前相册的共创参与情况：</div>
                        <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                            <span class="badge-role badge-male" style="font-size:0.7rem;padding:0.12rem 0.6rem;">
                                男主 <?php echo e($maleStatusText); ?>
                            </span>
                            <span class="badge-role badge-female" style="font-size:0.7rem;padding:0.12rem 0.6rem;">
                                女主 <?php echo e($femaleStatusText); ?>
                            </span>
                        </div>
                    </div>
                    <div style="font-size:0.78rem;color:#9ca3af;text-align:right;">
                        <?php if (!empty($allowPartnerEdit)): ?>
                            创建者从创建相册起即视为已参与，<br>另一半上传任意照片后，就会与创建者一起触发前台的共创效果。
                        <?php elseif ($hasCoContent): ?>
                            当前相册已关闭共创编辑，<br>但男主和女主都曾上传过照片，前台仍会展示为共创内容。
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <section class="admin-grid" style="margin-bottom:0.75rem;">
        <div class="admin-card">
            <div class="admin-card-header">
                <div>
                    <div class="admin-card-title">相册封面</div>
                    <div class="admin-card-subtitle">为这个相册设置一张封面</div>
                </div>
            </div>

            <?php if (!empty($album['cover_image'])): ?>
                <div style="margin-bottom:0.75rem;">
                    <img src="<?php echo e(upload_url($album['cover_image'])); ?>" alt="相册封面"
                         style="width:100%;max-height:220px;object-fit:cover;border-radius:0.9rem;">
                </div>
                <form method="POST" style="display:inline-block;margin-right:0.5rem;" data-confirm="确定要删除当前封面吗？删除后可以重新上传新的封面。">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="cover_action" value="delete_cover">
                    <button type="submit" class="btn btn-secondary" style="background:#fee2e2;color:#b91c1c;border:1px solid rgba(248,113,113,0.6);">
                        <i class="fas fa-trash"></i>
                        <span>删除封面</span>
                    </button>
                </form>
            <?php else: ?>
                <p style="font-size:0.9rem;color:var(--text-light);margin-bottom:0.75rem;">
                    当前相册还没有单独设置封面，将默认使用相册中的第一张照片。
                </p>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" style="margin-top:0.5rem;" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="cover_action" value="upload_cover">
                <div class="form-group" style="margin-bottom:0.5rem;">
                    <input type="file" name="cover_image" accept="image/*" style="font-size:0.85rem;">
                    <?php
                    $maxUploadBytesCover = get_max_upload_size_bytes();
                    $maxUploadMbCover    = round($maxUploadBytesCover / 1024 / 1024, 1);
                    ?>
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        支持 JPG / PNG / GIF / WebP，单文件最大约 <?php echo $maxUploadMbCover; ?>MB。
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload"></i>
                    <span>上传/更新封面</span>
                </button>
            </form>
        </div>

        <div class="admin-card">
            <div class="admin-card-header">
                <div>
                    <div class="admin-card-title">相册信息</div>
                    <div class="admin-card-subtitle">修改相册名称、描述、加密状态与编辑权限</div>
                </div>
            </div>
            <form method="POST" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="album_update" value="1">
                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">相册名称 *</label>
                    <input
                        type="text"
                        name="name"
                        value="<?php echo e($album['name']); ?>"
                        style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
                </div>
                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">相册描述</label>
                    <textarea
                        name="description"
                        rows="3"
                        placeholder="给这个相册写一句小小的说明～"
                        style="width:100%;min-height:80px;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;resize:vertical;"><?php echo e($album['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-group" style="margin-bottom:1rem;">
                    <label class="switch">
                        <input type="checkbox" name="is_encrypted" value="1" <?php echo !empty($album['is_encrypted']) ? 'checked' : ''; ?>>
                        <span class="switch-track">
                            <span class="switch-thumb"></span>
                        </span>
                        <span class="switch-label">加密相册（前台仅登录后可见）</span>
                    </label>
                </div>
                <?php if ($isOwner): ?>
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="switch">
                            <input
                                type="checkbox"
                                name="allow_partner_edit"
                                value="1"
                                <?php echo !empty($allowPartnerEdit) ? 'checked' : ''; ?>>
                            <span class="switch-track">
                                <span class="switch-thumb"></span>
                            </span>
                            <span class="switch-label">允许另一半在后台编辑与上传图片</span>
                        </label>
                        <p style="margin-top:0.25rem;font-size:0.78rem;color:var(--text-light);">
                            关闭后，另一半在后台将看不到“管理图片”和删除按钮，也无法上传或删除这个相册里的图片。
                        </p>
                    </div>
                <?php elseif ($isPartnerUser): ?>
                    <div class="form-group" style="margin-bottom:1rem;">
                        <label class="switch switch-disabled">
                            <input type="checkbox" disabled <?php echo !empty($allowPartnerEdit) ? 'checked' : ''; ?>>
                            <span class="switch-track">
                                <span class="switch-thumb"></span>
                            </span>
                            <span class="switch-label">
                                由创建者控制是否允许另一半在后台编辑与上传图片
                                <?php if (empty($allowPartnerEdit)): ?>
                                    （当前已关闭）
                                <?php endif; ?>
                            </span>
                        </label>
                    </div>
                <?php endif; ?>
                <div class="form-group" style="margin-bottom:1rem;">
                    <label class="switch<?php echo ($imageOptimizeEnabled === '1') ? '' : ' switch-disabled'; ?>">
                        <input
                            type="checkbox"
                            name="keep_original_quality"
                            value="1"
                            <?php echo !empty($album['keep_original_quality']) ? 'checked' : ''; ?>
                            <?php echo ($imageOptimizeEnabled === '1') ? '' : 'disabled'; ?>>
                        <span class="switch-track">
                            <span class="switch-thumb"></span>
                        </span>
                        <span class="switch-label">不对该本相册应用图片压缩</span>
                    </label>
                    <p style="margin-top:0.25rem;font-size:0.78rem;color:var(--text-light);">
                        <?php if ((string)$imageOptimizeEnabled === '1'): ?>
                            关闭时，本相册中的新图片将按照全局“图片压缩与 WebP 优化”规则进行压缩（推荐）。开启后，本相册的新图片会跳过主图压缩，仅生成缩略图与 WebP，更偏向保留原始画质，适合少量精修照片。
                        <?php else: ?>
                            当前已在系统设置中关闭图片压缩，本选项暂不生效。如需单独控制，请先在“系统设置 → 上传与其他”中开启图片压缩。
                        <?php endif; ?>
                    </p>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    <span>保存相册信息</span>
                </button>
            </form>
        </div>
    </section>

    <div class="admin-card" style="margin-bottom:0.75rem;">
        <div class="admin-card-header">
            <div>
                <div class="admin-card-title">上传媒体</div>
                <div class="admin-card-subtitle">在这里上传当前相册的图片和视频</div>
            </div>
        </div>

        <div class="media-upload-tabs" style="display:inline-flex;margin-bottom:0.75rem;border-radius:999px;background:rgba(15,23,42,0.04);padding:0.18rem;">
            <button type="button"
                    class="media-upload-tab active"
                    data-target="image"
                    style="border:none;background:#ffffff;border-radius:999px;padding:0.35rem 0.9rem;font-size:0.85rem;cursor:pointer;box-shadow:0 2px 6px rgba(15,23,42,0.08);">
                <i class="fas fa-image" style="margin-right:0.25rem;"></i>图片上传
            </button>
            <button type="button"
                    class="media-upload-tab"
                    data-target="video"
                    style="border:none;background:transparent;border-radius:999px;padding:0.35rem 0.9rem;font-size:0.85rem;cursor:pointer;color:#6b7280;">
                <i class="fas fa-video" style="margin-right:0.25rem;"></i>视频上传
            </button>
        </div>

        <div class="media-upload-pane media-upload-pane-image" style="display:block;">
            <form method="POST" enctype="multipart/form-data" id="albumUploadForm" novalidate>
                <?php echo csrf_field(); ?>
                <div class="form-group" style="margin-bottom:0.5rem;">
                    <input type="file" name="images[]" multiple accept="image/*" style="font-size:0.85rem;">
                    <?php
                    $maxUploadBytes = get_max_upload_size_bytes();
                    $maxUploadMb    = round($maxUploadBytes / 1024 / 1024, 1);
                    ?>
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        支持多选，格式：JPG / PNG / GIF / WebP，每张最大约 <?php echo $maxUploadMb; ?>MB。
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">本次上传的图片说明（可选）</label>
                    <textarea
                        name="image_description"
                        rows="2"
                        placeholder="这一句描述会应用到本次上传的所有图片，例如：毕业旅行 Day 1～"
                        style="width:100%;min-height:60px;padding:0.5rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;resize:vertical;"></textarea>
                </div>
                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label class="switch">
                        <input
                            type="checkbox"
                            name="skip_optimize_this_upload"
                            value="1">
                        <span class="switch-track">
                            <span class="switch-thumb"></span>
                        </span>
                        <span class="switch-label">本次上传的图片不进行压缩</span>
                    </label>
                    <p style="margin-top:0.25rem;font-size:0.78rem;color:var(--text-light);">
                        仅影响当前这批上传。关闭时，仍按全局与相册的压缩规则处理图片（推荐）；开启后，本次上传的图片会跳过主图压缩，仅生成缩略图与 WebP，更偏向保留原始画质。
                    </p>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload"></i>
                    <span>开始上传图片</span>
                </button>
            </form>

            <div class="upload-progress" id="uploadProgress" style="display:none;margin-top:0.75rem;">
                <div class="upload-progress-bar-wrap">
                    <div class="upload-progress-bar" id="uploadProgressBar"></div>
                </div>
                <div class="upload-progress-text" id="uploadProgressText">准备上传...</div>
            </div>
        </div>

        <div class="media-upload-pane media-upload-pane-video" style="display:none;">
            <form method="POST" enctype="multipart/form-data" style="margin-bottom:0.9rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="video_upload" value="1">
                <div class="form-group" style="margin-bottom:0.5rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">上传视频文件</label>
                    <input type="file" name="videos[]" multiple accept="video/*" style="font-size:0.85rem;">
                    <div style="margin-top:0.2rem;font-size:0.78rem;color:var(--text-light);">
                        支持 MP4 / WebM / Ogg 格式。大小限制与文章视频上传一致，可在“系统设置 → 上传与其他”中调整视频相关限制。
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0.5rem;">
                    <label style="display:block;font-size:0.85rem;margin-bottom:0.25rem;">视频描述（可选，应用于本次所有上传）</label>
                    <input
                        type="text"
                        name="video_description"
                        placeholder="为这些视频写一句小说明～"
                        style="width:100%;padding:0.55rem 0.75rem;border-radius:0.75rem;border:1px solid rgba(148,163,184,0.7);font-size:0.9rem;">
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-video"></i>
                    <span>上传视频</span>
                </button>
            </form>

            <form method="POST" style="margin-bottom:0.75rem;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="generate_video_posters" value="1">
                <button type="submit" class="btn btn-secondary">
                    <i class="fas fa-image"></i>
                    <span>为当前相册视频补齐封面图</span>
                </button>
                <p style="margin-top:0.25rem;font-size:0.78rem;color:var(--text-light);">
                    如发现相册中部分视频仍显示为加载中的占位图，可点击此按钮尝试为这些视频生成静态封面。多次执行是安全的。
                </p>
            </form>
        </div>
    </div>

    <form method="POST" id="imageBulkForm">
        <?php echo csrf_field(); ?>

        <div class="admin-card" style="margin-bottom:0.75rem;">
            <div class="admin-card-header">
                <div>
                    <div class="admin-card-title">相册媒体</div>
                    <div class="admin-card-subtitle">
                        <?php
                        $imageCount = count($images);
                        $videoCount = count($videos);
                        ?>
                        已上传 <?php echo $imageCount; ?> 张图片 / <?php echo $videoCount; ?> 个视频
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:0.25rem;font-size:0.85rem;color:var(--text-light);">
                    <label style="display:flex;align-items:center;gap:0.35rem;">
                        <input type="checkbox" id="selectAllImages">
                        <span>全选图片</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:0.35rem;">
                        <input type="checkbox" id="selectAllVideos">
                        <span>全选视频</span>
                    </label>
                </div>
            </div>
            <div style="margin-top:0.25rem;">
                <button type="submit"
                        name="bulk_action"
                        value="delete_images"
                        class="btn btn-secondary"
                        style="background:#fee2e2;color:#b91c1c;border:1px solid rgba(248,113,113,0.6);">
                    <i class="fas fa-trash"></i>
                    <span>批量删除选中媒体</span>
                </button>
                <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-start;">
                    <textarea
                        name="bulk_description"
                        rows="2"
                        placeholder="为选中的媒体批量设置一条描述（留空则清除描述）"
                        style="flex:1 1 220px;padding:0.35rem 0.5rem;border-radius:0.5rem;border:1px solid rgba(148,163,184,0.7);font-size:0.78rem;resize:vertical;"></textarea>
                    <button type="submit"
                            name="bulk_action"
                            value="set_description"
                            class="btn btn-primary">
                        <i class="fas fa-pen"></i>
                        <span>批量设置描述</span>
                    </button>
                </div>
                <p style="margin-top:0.25rem;font-size:0.78rem;color:var(--text-light);">
                    提示：目前批量操作仅针对图片，视频描述可在下方单独编辑。
                </p>
            </div>
        </div>

        <?php if (!empty($mediaItems)): ?>
            <div class="images-grid">
                <?php foreach ($mediaItems as $media): ?>
                    <?php
                    $isImage = $media['type'] === 'image';
                    $isVideo = $media['type'] === 'video';
                    $uploaderName = '';
                    if ($isImage || $isVideo) {
                        $uploaderName = !empty($media['uploader_nickname'])
                            ? $media['uploader_nickname']
                            : (!empty($media['uploader_username']) ? $media['uploader_username'] : '未知上传者');
                    }
                    ?>
                    <div class="admin-card image-item-card" style="padding:0.5rem;position:relative;">
                        <div style="position:relative;border-radius:0.75rem;overflow:hidden;">
                            <?php if ($isImage): ?>
                                <?php
                                $thumbPath = !empty($media['thumbnail_path']) ? $media['thumbnail_path'] : $media['image_path'];
                                $thumbUrl  = upload_url($thumbPath);
                                ?>
                                <img src="<?php echo e($thumbUrl); ?>" alt=""
                                     style="width:100%;height:150px;object-fit:cover;display:block;">
                            <?php else: ?>
                                <?php
                                $posterPath = $media['poster_path'] ?? '';
                                if ($posterPath) {
                                    $pi = pathinfo($posterPath);
                                    if (!empty($pi['dirname']) && !empty($pi['basename'])) {
                                        $thumbRelative = rtrim($pi['dirname'], '/\\') . '/thumbs/' . $pi['basename'];
                                        $thumbAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . ltrim($thumbRelative, '/\\');
                                        $posterThumbPath = is_file($thumbAbs) ? $thumbRelative : $posterPath;
                                    } else {
                                        $posterThumbPath = $posterPath;
                                    }
                                    $posterUrl = upload_url($posterThumbPath);
                                } else {
                                    $posterUrl = '/assets/images/video-placeholder.svg';
                                }
                                ?>
                                <img src="<?php echo e($posterUrl); ?>" alt=""
                                     style="width:100%;height:150px;object-fit:cover;display:block;">
                                <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
                                    <div style="width:32px;height:32px;border-radius:999px;background:rgba(15,23,42,0.75);display:flex;align-items:center;justify-content:center;color:#f9fafb;">
                                        <i class="fas fa-play"></i>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <label style="position:absolute;top:0.4rem;left:0.4rem;background:rgba(15,23,42,0.65);border-radius:999px;padding:0.2rem 0.55rem;font-size:0.75rem;color:#e5e7eb;display:flex;align-items:center;gap:0.3rem;">
                                <?php if ($isImage): ?>
                                    <input type="checkbox" name="delete_images[]" value="<?php echo $media['id']; ?>" style="margin:0;">
                                    <span>选中</span>
                                <?php else: ?>
                                    <input type="checkbox" name="delete_videos[]" value="<?php echo $media['id']; ?>" style="margin:0;">
                                    <span>选中</span>
                                <?php endif; ?>
                            </label>
                        </div>
                        <div style="margin-top:0.35rem;font-size:0.78rem;color:var(--text-light);display:flex;justify-content:space-between;align-items:center;gap:0.5rem;">
                            <span>
                                类型：
                                <?php if ($isImage): ?>
                                    <i class="fas fa-image" style="margin-right:0.15rem;"></i>图片
                                <?php else: ?>
                                    <i class="fas fa-video" style="margin-right:0.15rem;"></i>视频
                                <?php endif; ?>
                            </span>
                            <span><?php echo !empty($media['created_at']) ? formatDate($media['created_at']) : '未知时间'; ?></span>
                        </div>
                        <?php if ($isImage || $isVideo): ?>
                            <div style="margin-top:0.15rem;font-size:0.78rem;color:var(--text-light);">
                                上传者：<?php echo e($uploaderName); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($isVideo): ?>
                            <div style="margin-top:0.15rem;font-size:0.78rem;color:var(--text-light);">
                                编码状态：
                                <?php if (!empty($media['is_transcoded'])): ?>
                                    <span style="color:#16a34a;">已转码为通用格式（H.264 + AAC）</span>
                                <?php else: ?>
                                    <span style="color:#b45309;">原始视频（未转码，某些设备上可能不兼容）</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div style="margin-top:0.25rem;font-size:0.78rem;color:var(--text-light);">
                            <label style="display:block;margin-bottom:0.15rem;">
                                <?php echo $isImage ? '图片描述（可单独编辑）' : '视频描述（可单独编辑）'; ?>
                            </label>
                            <?php if ($isImage): ?>
                                <textarea
                                    name="descriptions[<?php echo $media['id']; ?>]"
                                    rows="2"
                                    style="width:100%;padding:0.35rem 0.5rem;border-radius:0.5rem;border:1px solid rgba(148,163,184,0.7);font-size:0.78rem;resize:vertical;"><?php echo e($media['description'] ?? ''); ?></textarea>
                            <?php else: ?>
                                <textarea
                                    name="video_descriptions[<?php echo $media['id']; ?>]"
                                    rows="2"
                                    style="width:100%;padding:0.35rem 0.5rem;border-radius:0.5rem;border:1px solid rgba(148,163,184,0.7);font-size:0.78rem;resize:vertical;"><?php echo e($media['description'] ?? ''); ?></textarea>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:0.4rem;display:flex;justify-content:flex-end;gap:0.75rem;">
                            <?php if ($isImage): ?>
                                <label style="font-size:0.78rem;color:var(--text-light);display:inline-flex;align-items:center;gap:0.25rem;margin-right:auto;">
                                    <input
                                        type="checkbox"
                                        name="skip_optimize_flags[<?php echo $media['id']; ?>]"
                                        value="1"
                                        <?php echo !empty($media['skip_optimize']) ? 'checked' : ''; ?>>
                                    <span>本图片不进行压缩</span>
                                </label>
                                <button type="submit"
                                        name="save_single_description"
                                        value="<?php echo $media['id']; ?>"
                                        style="font-size:0.78rem;color:#0f766e;text-decoration:none;background:none;border:none;padding:0;cursor:pointer;">
                                    保存描述
                                </button>
                                <button type="submit"
                                        name="single_delete"
                                        value="<?php echo $media['id']; ?>"
                                        style="font-size:0.78rem;color:#b91c1c;text-decoration:none;background:none;border:none;padding:0;cursor:pointer;">
                                    删除
                                </button>
                            <?php else: ?>
                                <a href="<?php echo e(upload_url($media['video_path'])); ?>"
                                   target="_blank"
                                   style="font-size:0.78rem;color:#0f766e;text-decoration:none;margin-right:auto;">
                                    预览视频
                                </a>
                                <button type="button"
                                        class="video-poster-edit-btn"
                                        data-video-id="<?php echo $media['id']; ?>"
                                        data-video-url="<?php echo e(upload_url($media['video_path'])); ?>"
                                        style="font-size:0.78rem;color:#0369a1;text-decoration:none;background:none;border:none;padding:0;cursor:pointer;">
                                    调整封面
                                </button>
                                <button type="submit"
                                        name="save_single_video_description"
                                        value="<?php echo $media['id']; ?>"
                                        style="font-size:0.78rem;color:#0f766e;text-decoration:none;background:none;border:none;padding:0;cursor:pointer;">
                                    保存描述
                                </button>
                                <button type="submit"
                                        name="single_video_delete"
                                        value="<?php echo $media['id']; ?>"
                                        style="font-size:0.78rem;color:#b91c1c;text-decoration:none;background:none;border:none;padding:0;cursor:pointer;">
                                    删除
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="admin-card" style="margin-bottom:0.75rem;">
                <p style="margin:0;font-size:0.85rem;color:var(--text-light);">
                    当前相册里还没有任何图片或视频。你可以在上方上传图片或视频后，在此统一管理它们的描述与删除操作。
                </p>
            </div>
        <?php endif; ?>
    </form>

    <!-- 视频封面调整弹层：支持从当前帧截取封面或直接上传封面图片 -->
    <div id="videoPosterEditor"
         style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.65);">
        <div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:90%;max-width:720px;background:#ffffff;border-radius:1rem;box-shadow:0 20px 60px rgba(15,23,42,0.5);padding:1rem 1.1rem 1.1rem;max-height:90vh;display:flex;flex-direction:column;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
                <div style="font-size:0.95rem;font-weight:600;color:#111827;">调整视频封面</div>
                <button type="button"
                        data-role="close"
                        style="border:none;background:transparent;font-size:1.1rem;cursor:pointer;color:#6b7280;">
                    &times;
                </button>
            </div>
            <div style="flex:1;min-height:0;display:flex;flex-direction:column;gap:0.5rem;">
                <video id="videoPosterEditorVideo"
                       controls
                       style="width:100%;max-height:320px;border-radius:0.75rem;background:#000000;outline:none;"></video>
                <p style="margin:0;font-size:0.8rem;color:#4b5563;">
                    提示：拖动视频进度到你想要的画面后，点击“使用当前画面作为封面”即可截取该帧作为封面；也可以直接上传一张自定义封面图片。
                </p>
                <div style="margin-top:0.35rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
                    <button type="button"
                            id="videoPosterCaptureBtn"
                            class="btn btn-primary"
                            style="padding:0.35rem 0.8rem;font-size:0.8rem;">
                        <i class="fas fa-camera" style="margin-right:0.25rem;"></i>
                        <span>使用当前画面作为封面</span>
                    </button>
                    <label style="display:inline-flex;align-items:center;gap:0.35rem;font-size:0.8rem;color:#0369a1;cursor:pointer;">
                        <input type="file"
                               id="videoPosterUploadInput"
                               accept="image/*"
                               style="display:none;">
                        <i class="fas fa-image"></i>
                        <span>上传一张封面图片</span>
                    </label>
                </div>
                <div id="videoPosterHint"
                     style="margin-top:0.3rem;font-size:0.78rem;color:#9ca3af;">
                </div>
            </div>
        </div>
        <canvas id="videoPosterEditorCanvas" style="display:none;"></canvas>
    </div>

    <script>
    (function () {
        const form = document.getElementById('albumUploadForm');
        if (!form) return;

        const fileInput = form.querySelector('input[type="file"]');
        const submitBtn = form.querySelector('button[type="submit"]');
        const progressWrap = document.getElementById('uploadProgress');
        const progressBar = document.getElementById('uploadProgressBar');
        const progressText = document.getElementById('uploadProgressText');

        form.addEventListener('submit', function (e) {
            if (!fileInput || !fileInput.files.length) {
                return;
            }

            e.preventDefault();

            const formData = new FormData(form);
            formData.append('ajax', '1');

            progressWrap.style.display = 'block';
            progressBar.style.width = '0%';
            progressText.textContent = '正在准备上传...';
            if (submitBtn) {
                submitBtn.disabled = true;
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action || window.location.href, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                progressBar.style.width = percent + '%';
                if (percent < 100) {
                    progressText.textContent = '正在上传：' + percent + '%';
                } else {
                    progressText.textContent = '上传完成，正在由服务器处理图片...';
                }
            };

            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;

                if (submitBtn) {
                    submitBtn.disabled = false;
                }

                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            progressBar.style.width = '100%';
                            progressText.textContent = resp.message || '上传与处理完成，正在刷新列表...';
                            setTimeout(function () {
                                window.location.reload();
                            }, 600);
                        } else {
                            progressText.textContent = resp.message || '上传失败，请稍后重试';
                        }
                    } catch (err) {
                        console.error('解析上传响应失败', err);
                        progressText.textContent = '上传完成，但解析结果失败，请刷新页面检查。';
                    }
                } else {
                    progressText.textContent = '上传失败，网络或服务器异常（HTTP ' + xhr.status + '）';
                }
            };

            xhr.onerror = function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                progressText.textContent = '上传失败，请检查网络后重试。';
            };

            xhr.send(formData);
        });
    })();

    // 单个视频封面调整：从当前帧截取或上传自定义封面
    (function () {
        var editor = document.getElementById('videoPosterEditor');
        if (!editor) return;

        var videoEl   = document.getElementById('videoPosterEditorVideo');
        var canvasEl  = document.getElementById('videoPosterEditorCanvas');
        var captureBtn= document.getElementById('videoPosterCaptureBtn');
        var uploadInput = document.getElementById('videoPosterUploadInput');
        var hintEl    = document.getElementById('videoPosterHint');
        var closeBtn  = editor.querySelector('[data-role="close"]');
        var currentVideoId = null;

        function showEditor(videoId, videoUrl) {
            if (!videoId || !videoUrl) return;
            currentVideoId = videoId;
            hintEl.textContent = '';
            videoEl.src = videoUrl;
            try {
                videoEl.currentTime = 0;
            } catch (e) {}
            editor.style.display = 'block';
        }

        function hideEditor() {
            editor.style.display = 'none';
            try {
                videoEl.pause();
            } catch (e) {}
            videoEl.removeAttribute('src');
            videoEl.load();
            currentVideoId = null;
            hintEl.textContent = '';
            if (uploadInput) {
                uploadInput.value = '';
            }
        }

        function showToastSafe(msg, type) {
            if (typeof window.showToast === 'function') {
                window.showToast(msg, type || 'info');
                return;
            }
            if (hintEl) {
                hintEl.textContent = msg;
            }
        }

        function sendPosterFile(file) {
            if (!file || !currentVideoId) {
                showToastSafe('没有可上传的封面或视频参数无效', 'error');
                return;
            }
            var formData = new FormData();
            formData.append('update_video_poster', String(currentVideoId));
            formData.append('cover_file', file, file.name || 'poster.jpg');
            formData.append('ajax', '1');

            var csrfInput = document.querySelector('input[name="_token"]');
            if (csrfInput && csrfInput.value) {
                formData.append('_token', csrfInput.value);
            }

            hintEl.textContent = '正在上传并更新封面...';

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            }).then(function (json) {
                if (!json || !json.success) {
                    var msg = (json && json.message) || '封面更新失败，请稍后重试';
                    showToastSafe(msg, 'error');
                    return;
                }
                var msg = json.message || '视频封面已更新';
                showToastSafe(msg, 'success');
                setTimeout(function () {
                    window.location.reload();
                }, 500);
            }).catch(function () {
                showToastSafe('封面更新失败，请检查网络后重试', 'error');
            });
        }

        if (captureBtn && canvasEl && videoEl) {
            captureBtn.addEventListener('click', function () {
                if (!currentVideoId) {
                    showToastSafe('当前没有可更新封面的视频', 'error');
                    return;
                }
                if (!videoEl.videoWidth || !videoEl.videoHeight) {
                    showToastSafe('视频尚未加载完成，请稍后再试或先播放到目标画面', 'error');
                    return;
                }

                var maxWidth = 1280;
                var width = videoEl.videoWidth;
                var height = videoEl.videoHeight;
                if (width > maxWidth) {
                    var scale = maxWidth / width;
                    width = maxWidth;
                    height = Math.round(height * scale);
                }

                canvasEl.width = width;
                canvasEl.height = height;
                var ctx = canvasEl.getContext('2d');
                ctx.drawImage(videoEl, 0, 0, width, height);

                canvasEl.toBlob(function (blob) {
                    if (!blob) {
                        showToastSafe('截取封面失败，请稍后重试', 'error');
                        return;
                    }
                    sendPosterFile(blob);
                }, 'image/jpeg', 0.9);
            });
        }

        if (uploadInput) {
            uploadInput.addEventListener('change', function () {
                var file = uploadInput.files && uploadInput.files[0];
                if (!file) {
                    return;
                }
                sendPosterFile(file);
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                hideEditor();
            });
        }
        editor.addEventListener('click', function (e) {
            if (e.target === editor) {
                hideEditor();
            }
        });

        var editButtons = document.querySelectorAll('.video-poster-edit-btn');
        if (editButtons && editButtons.length) {
            editButtons.forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var videoId = parseInt(this.getAttribute('data-video-id') || '0', 10);
                    var videoUrl = this.getAttribute('data-video-url') || '';
                    if (!videoId || !videoUrl) {
                        showToastSafe('无法打开封面调整工具，视频信息不完整', 'error');
                        return;
                    }
                    showEditor(videoId, videoUrl);
                });
            });
        }
    })();

    (function () {
        const bulkForm = document.getElementById('imageBulkForm');
        if (!bulkForm) return;

        const selectAllImages = document.getElementById('selectAllImages');
        const selectAllVideos = document.getElementById('selectAllVideos');
        const imageCheckboxes = bulkForm.querySelectorAll('input[name="delete_images[]"]');
        const videoCheckboxes = bulkForm.querySelectorAll('input[name="delete_videos[]"]');

        function showToastOrAlert(msg, type) {
            if (typeof window.showToast === 'function') {
                window.showToast(msg, type || 'info');
                return;
            }
            // 简单的后台内联提示，不使用浏览器原生 alert
            let toast = document.getElementById('admin-inline-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'admin-inline-toast';
                toast.style.position = 'fixed';
                toast.style.left = '50%';
                toast.style.top = '1.25rem';
                toast.style.transform = 'translateX(-50%)';
                toast.style.zIndex = '99999';
                toast.style.padding = '0.5rem 0.9rem';
                toast.style.borderRadius = '999px';
                toast.style.fontSize = '0.8rem';
                toast.style.boxShadow = '0 10px 30px rgba(15,23,42,0.18)';
                toast.style.background = 'rgba(15,23,42,0.96)';
                toast.style.color = '#e5e7eb';
                document.body.appendChild(toast);
            }
            toast.textContent = msg || '';
            toast.style.opacity = '1';
            toast.style.display = 'block';
            setTimeout(function () {
                toast.style.opacity = '0';
                setTimeout(function () {
                    toast.style.display = 'none';
                }, 200);
            }, 1800);
        }

        if (selectAllImages) {
            selectAllImages.addEventListener('change', function () {
                const checked = this.checked;
                imageCheckboxes.forEach(function (cb) {
                    cb.checked = checked;
                });
            });
        }

        if (selectAllVideos) {
            selectAllVideos.addEventListener('change', function () {
                const checked = this.checked;
                videoCheckboxes.forEach(function (cb) {
                    cb.checked = checked;
                });
            });
        }

        bulkForm.addEventListener('submit', function (e) {
            const submitter   = e.submitter || document.activeElement;
            if (!submitter) {
                return;
            }

            const name  = submitter.name || '';
            const value = submitter.value || '';
            const isBulkDelete      = name === 'bulk_action' && value === 'delete_images';
            const isBulkDesc        = name === 'bulk_action' && value === 'set_description';
            const isSingleDel       = name === 'single_delete';
            const isSingleVideoDel  = name === 'single_video_delete';
            const isSingleDesc      = name === 'save_single_description';
            const isSingleVideoDesc = name === 'save_single_video_description';

            // 非相关按钮不拦截
            if (!isBulkDelete && !isBulkDesc && !isSingleDel && !isSingleVideoDel && !isSingleDesc && !isSingleVideoDesc) {
                return;
            }

            e.preventDefault();

            const hasImageChecked = Array.from(imageCheckboxes).some(function (cb) { return cb.checked; });
            const hasVideoChecked = Array.from(videoCheckboxes).some(function (cb) { return cb.checked; });
            const hasChecked = hasImageChecked || hasVideoChecked;

            // 批量删除：保持整页刷新
            if (isBulkDelete) {
                if (!hasChecked) {
                    showToastOrAlert('请先勾选要删除的媒体（图片或视频）', 'error');
                    return;
                }

                const message = '确定要删除选中的媒体吗？此操作不可恢复。';
                const doSubmitBulkDelete = function () {
                    let bulkField = bulkForm.querySelector('input[name="bulk_action"]');
                    if (!bulkField) {
                        bulkField = document.createElement('input');
                        bulkField.type = 'hidden';
                        bulkField.name = 'bulk_action';
                        bulkField.value = 'delete_images';
                        bulkForm.appendChild(bulkField);
                    } else {
                        bulkField.value = 'delete_images';
                    }
                    bulkForm.submit();
                };

                if (typeof window.adminConfirm === 'function') {
                    window.adminConfirm(message, doSubmitBulkDelete);
                } else {
                    // 未提供自定义确认弹窗时，直接执行，避免使用浏览器原生 confirm
                    doSubmitBulkDelete();
                }

                return;
            }

            // 批量设置描述：AJAX（图片 + 视频）
            if (isBulkDesc) {
                if (!hasChecked) {
                    showToastOrAlert('请先勾选要设置描述的媒体', 'error');
                    return;
                }

                const bulkDescField = bulkForm.querySelector('textarea[name="bulk_description"]');
                const descText = bulkDescField ? bulkDescField.value.trim() : '';
                const message = descText === ''
                    ? '确定要清除选中媒体的描述吗？'
                    : '确定要为选中媒体批量设置这条描述吗？';

                const doSubmitBulkDesc = function () {
                    const formData = new FormData(bulkForm);
                    formData.set('bulk_action', 'set_description');
                    formData.append('ajax', '1');

                    fetch(bulkForm.action || window.location.href, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }).then(function (res) {
                        return res.json().catch(function () {
                            return { success: false, message: '批量更新描述成功，正在刷新页面检查结果…' };
                        });
                    }).then(function (data) {
                        if (data && data.success) {
                            if (bulkDescField) {
                                bulkDescField.value = '';
                            }
                            showToastOrAlert(data.message || '批量描述已更新', 'success');
                        } else {
                            showToastOrAlert((data && data.message) || '批量设置描述失败，请稍后重试', 'error');
                        }
                    }).catch(function () {
                        showToastOrAlert('网络异常，批量设置描述失败，请稍后重试', 'error');
                    });
                };

                if (typeof window.adminConfirm === 'function') {
                    window.adminConfirm(message, doSubmitBulkDesc);
                } else {
                    doSubmitBulkDesc();
                }

                return;
            }

            // 单张删除：保持整页刷新
            if (isSingleDel) {
                const imageId = submitter && submitter.value ? submitter.value : '';
                if (!imageId) {
                    return;
                }

                const message = '确定要删除这张图片吗？';
                const doSubmitSingle = function () {
                    Array.from(bulkForm.querySelectorAll('input[name="single_delete"]')).forEach(function (el) {
                        el.parentNode.removeChild(el);
                    });

                    const singleField = document.createElement('input');
                    singleField.type = 'hidden';
                    singleField.name = 'single_delete';
                    singleField.value = imageId;
                    bulkForm.appendChild(singleField);

                    bulkForm.submit();
                };

                if (typeof window.adminConfirm === 'function') {
                    window.adminConfirm(message, doSubmitSingle);
                } else {
                    doSubmitSingle();
                }

                return;
            }

            // 单个视频删除：保持整页刷新
            if (isSingleVideoDel) {
                const videoId = submitter && submitter.value ? submitter.value : '';
                if (!videoId) {
                    return;
                }

                const message = '确定要删除这个视频吗？';
                const doSubmitSingleVideo = function () {
                    Array.from(bulkForm.querySelectorAll('input[name=\"single_video_delete\"]')).forEach(function (el) {
                        el.parentNode.removeChild(el);
                    });

                    const singleField = document.createElement('input');
                    singleField.type = 'hidden';
                    singleField.name = 'single_video_delete';
                    singleField.value = videoId;
                    bulkForm.appendChild(singleField);

                    bulkForm.submit();
                };

                if (typeof window.adminConfirm === 'function') {
                    window.adminConfirm(message, doSubmitSingleVideo);
                } else {
                    doSubmitSingleVideo();
                }

                return;
            }

            // 单张保存图片描述：AJAX
            if (isSingleDesc) {
                const imageId = submitter && submitter.value ? submitter.value : '';
                if (!imageId) {
                    return;
                }

                const formData = new FormData(bulkForm);
                formData.append('save_single_description', imageId);
                formData.append('ajax', '1');

                fetch(bulkForm.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function (res) {
                    return res.json().catch(function () {
                        return { success: false, message: '描述已保存，正在刷新页面检查结果…' };
                    });
                }).then(function (data) {
                    if (data && data.success) {
                        showToastOrAlert(data.message || '描述已保存', 'success');
                    } else {
                        showToastOrAlert((data && data.message) || '保存描述失败，请稍后重试', 'error');
                    }
                }).catch(function () {
                    showToastOrAlert('网络异常，保存描述失败，请稍后重试', 'error');
                });
                return;
            }

            // 单个视频保存描述：AJAX
            if (isSingleVideoDesc) {
                const videoId = submitter && submitter.value ? submitter.value : '';
                if (!videoId) {
                    return;
                }

                const formData = new FormData(bulkForm);
                formData.append('save_single_video_description', videoId);
                formData.append('ajax', '1');

                fetch(bulkForm.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function (res) {
                    return res.json().catch(function () {
                        return { success: false, message: '描述已保存，正在刷新页面检查结果…' };
                    });
                }).then(function (data) {
                    if (data && data.success) {
                        showToastOrAlert(data.message || '描述已保存', 'success');
                    } else {
                        showToastOrAlert((data && data.message) || '保存描述失败，请稍后重试', 'error');
                    }
                }).catch(function () {
                    showToastOrAlert('网络异常，保存描述失败，请稍后重试', 'error');
                });
            }
        });
    })();

    // 视频上传：带进度条的 AJAX 上传（上传完成后自动刷新列表）
    (function () {
        const form = document.querySelector('.media-upload-pane-video form[method="POST"][enctype]');
        if (!form) return;

        const fileInput = form.querySelector('input[type="file"][name="videos[]"]');
        const submitBtn = form.querySelector('button[type="submit"]');

        // 复用图片上传的进度条区域，但文案独立
        let progressWrap = document.getElementById('videoUploadProgress');
        let progressBar = document.getElementById('videoUploadProgressBar');
        let progressText = document.getElementById('videoUploadProgressText');

        if (!progressWrap) {
            progressWrap = document.createElement('div');
            progressWrap.id = 'videoUploadProgress';
            progressWrap.className = 'upload-progress';
            progressWrap.style.display = 'none';
            progressWrap.style.marginTop = '0.75rem';
            progressWrap.innerHTML = '' +
                '<div class="upload-progress-bar-wrap">' +
                    '<div class="upload-progress-bar" id="videoUploadProgressBar"></div>' +
                '</div>' +
                '<div class="upload-progress-text" id="videoUploadProgressText">准备上传视频...</div>';
            form.parentNode.insertBefore(progressWrap, form.nextSibling);
            progressBar = document.getElementById('videoUploadProgressBar');
            progressText = document.getElementById('videoUploadProgressText');
        }

        form.addEventListener('submit', function (e) {
            if (!fileInput || !fileInput.files.length) {
                return;
            }

            e.preventDefault();

            const formData = new FormData(form);
            formData.append('ajax', '1');

            progressWrap.style.display = 'block';
            progressBar.style.width = '0%';
            progressText.textContent = '正在准备上传视频...';
            if (submitBtn) {
                submitBtn.disabled = true;
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action || window.location.href, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                progressBar.style.width = percent + '%';
                if (percent < 100) {
                    progressText.textContent = '正在上传视频：' + percent + '%';
                } else {
                    progressText.textContent = '上传完成，正在由服务器处理视频...';
                }
            };

            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;

                if (submitBtn) {
                    submitBtn.disabled = false;
                }

                if (xhr.status >= 200 && xhr.status < 300) {
                    let resp = null;
                    try {
                        resp = JSON.parse(xhr.responseText);
                    } catch (err) {
                        console.error('解析视频上传响应失败', err);
                    }
                    if (resp && resp.success) {
                        progressBar.style.width = '100%';
                        progressText.textContent = resp.message || '视频上传与处理完成，正在刷新列表...';
                        if (typeof window.showToast === 'function') {
                            window.showToast(resp.message || '视频上传成功', 'success');
                        }
                        setTimeout(function () {
                            window.location.reload();
                        }, 600);
                    } else {
                        const msg = (resp && resp.message) || '视频上传失败，请稍后重试';
                        progressText.textContent = msg;
                        if (typeof window.showToast === 'function') {
                            window.showToast(msg, 'error');
                        }
                    }
                } else {
                    const msg = '视频上传失败，网络或服务器异常（HTTP ' + xhr.status + '）';
                    progressText.textContent = msg;
                    if (typeof window.showToast === 'function') {
                        window.showToast(msg, 'error');
                    }
                }
            };

            xhr.onerror = function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
                const msg = '视频上传失败，请检查网络后重试。';
                progressText.textContent = msg;
                if (typeof window.showToast === 'function') {
                    window.showToast(msg, 'error');
                }
            };

            xhr.send(formData);
        });
    })();

    // 媒体上传 Tab 切换
    (function () {
        const tabs = document.querySelectorAll('.media-upload-tab');
        const panes = document.querySelectorAll('.media-upload-pane');
        if (!tabs.length || !panes.length) return;

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                const target = tab.getAttribute('data-target');
                if (!target) return;

                tabs.forEach(function (t) {
                    t.classList.remove('active');
                    t.style.background = 'transparent';
                    t.style.color = '#6b7280';
                    t.style.boxShadow = 'none';
                });
                tab.classList.add('active');
                tab.style.background = '#ffffff';
                tab.style.color = '#111827';
                tab.style.boxShadow = '0 2px 6px rgba(15,23,42,0.08)';

                panes.forEach(function (pane) {
                    if (pane.classList.contains('media-upload-pane-' + target)) {
                        pane.style.display = 'block';
                    } else {
                        pane.style.display = 'none';
                    }
                });
            });
        });
    })();
    </script>

<?php include __DIR__ . '/footer.php'; ?>
