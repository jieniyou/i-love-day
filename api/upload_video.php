<?php
// wangEditor 视频上传接口（后台使用）
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/helpers.php';

$auth = new Auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['errno' => 1, 'message' => 'Invalid request'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF 校验：后台编辑器也必须携带 token
if (!csrf_verify($_POST['_token'] ?? null)) {
    echo json_encode(['errno' => 1, 'message' => '表单已过期，请刷新页面后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 仅登录用户可上传
if (!$auth->isLoggedIn()) {
    echo json_encode(['errno' => 1, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 兼容不同字段名：取第一个文件
if (empty($_FILES)) {
    // 可能是未选择文件，也可能是上传内容超过服务器限制导致 $_FILES 为空
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > 0 && function_exists('parse_php_size_to_bytes')) {
        $limits = [];
        foreach (['upload_max_filesize', 'post_max_size'] as $iniKey) {
            $val = ini_get($iniKey);
            if ($val !== false) {
                $limits[] = parse_php_size_to_bytes($val);
            }
        }
        if (!empty($limits)) {
            $serverMax = min($limits);
            if ($serverMax > 0 && $contentLength > $serverMax) {
                $maxMb = round($serverMax / 1024 / 1024, 1);
                echo json_encode([
                    'errno'   => 1,
                    'message' => '上传内容超过服务器限制，当前服务器允许的单个文件最大约 ' . $maxMb . 'MB，请压缩后重试或联系管理员调整服务器上传限制。',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    echo json_encode(['errno' => 1, 'message' => '没有选择文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = reset($_FILES);

// 文章 ID（可选）：若提供则按 uploads/articles/{article_id}/videos 分目录存储
$articleId = isset($_POST['article_id']) ? (int) $_POST['article_id'] : 0;
if ($articleId < 0) {
    $articleId = 0;
}

// 基本大小限制
// - 默认沿用全局上传限制（站点配置 + 服务器 php.ini）
// - 若开启“视频忽略站点大小限制”开关，则仅按服务器 php.ini 限制
$maxSize = null;

// 读取视频大小限制开关：video_upload_ignore_site_limit（1=仅受服务器限制）
$ignoreSiteLimit = false;
try {
    if (class_exists('Database')) {
        $db = Database::getInstance();
        $row = $db->fetch("SELECT value FROM settings WHERE `key` = 'video_upload_ignore_site_limit' LIMIT 1");
        if ($row && isset($row['value']) && (string)$row['value'] === '1') {
            $ignoreSiteLimit = true;
        }
    }
} catch (Throwable $e) {
    $ignoreSiteLimit = false;
}

if ($ignoreSiteLimit) {
    // 仅按服务器 php.ini 限制
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
    // 默认沿用全局上传限制
    $maxSize = function_exists('get_max_upload_size_bytes') ? get_max_upload_size_bytes() : MAX_FILE_SIZE;
}

if ($file['size'] > $maxSize) {
    $maxMb = round($maxSize / 1024 / 1024, 1);

    if ($ignoreSiteLimit) {
        // 已开启“仅受服务器限制”：提示服务器硬上限
        $msg = '视频大小超出限制，当前服务器允许的单个文件最大约 ' . $maxMb . 'MB，请压缩后重试或联系管理员调整服务器上传限制。';
    } else {
        // 使用站点限制：提示可在后台调整站点限制或开启视频专用开关
        $msg = '视频大小超出限制，当前站点的单文件上传大小约为 ' . $maxMb . 'MB。'
             . '你可以在后台“系统设置 → 上传与其他 → 单文件最大上传大小（MB）”中调整，'
             . '或开启“视频上传仅受服务器限制”开关后再尝试上传。';
    }

    echo json_encode(['errno' => 1, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// 允许的视频类型
$allowedTypes = ['video/mp4', 'video/webm', 'video/ogg'];

if (function_exists('finfo_open')) {
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
} else {
    $mimeType = $file['type'] ?? '';
}

if (!in_array($mimeType, $allowedTypes, true)) {
    echo json_encode(['errno' => 1, 'message' => '不支持的视频格式'], JSON_UNESCAPED_UNICODE);
    exit;
}

$extension  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExt = ['mp4', 'webm', 'ogg'];
if (!in_array($extension, $allowedExt, true)) {
    echo json_encode(['errno' => 1, 'message' => '视频扩展名不被允许'], JSON_UNESCAPED_UNICODE);
    exit;
}

$filename = uniqid('', true) . '.' . $extension;

// 文章已存在时，按 ID 分目录：uploads/articles/{articleId}/videos
// 否则回退到 uploads/articles/videos（兼容旧数据与新建文章阶段）
if ($articleId > 0) {
    $subDirBase = 'articles/' . $articleId . '/videos';
} else {
    $subDirBase = 'articles/videos';
}

$uploadPath = rtrim(UPLOAD_DIR . '/' . trim($subDirBase, '/'), '/');

if (!is_dir($uploadPath)) {
    @mkdir($uploadPath, 0755, true);
}

if (!is_dir($uploadPath)) {
    echo json_encode(['errno' => 1, 'message' => '保存视频失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$filepath = $uploadPath . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['errno' => 1, 'message' => '保存视频失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 原始上传视频的相对路径（不带 /uploads/ 前缀）
$originalRelative = trim($subDirBase, '/') . '/' . $filename;
$originalRelative = ltrim($originalRelative, '/');

// 默认播放路径为原始文件路径；若后续转码成功，再切换为转码后的路径
$finalVideoRelative = $originalRelative;
$isTranscodedFlag   = 0;
$posterRelative     = null;

// 使用 ffmpeg 生成统一编码的视频文件（H.264 + AAC），尽量提升浏览器兼容性
if (function_exists('shell_exec')) {
    // 转码目标目录：uploads/articles/{articleId}/videos/transcoded 或 uploads/articles/videos/transcoded
    if ($articleId > 0) {
        $transcodedSubDir = 'articles/' . $articleId . '/videos/transcoded';
    } else {
        $transcodedSubDir = 'articles/videos/transcoded';
    }

    $transcodedDirAbs = rtrim(UPLOAD_DIR, '/\\') . '/' . trim($transcodedSubDir, '/');
    if (!is_dir($transcodedDirAbs)) {
        @mkdir($transcodedDirAbs, 0755, true);
    }

    if (is_dir($transcodedDirAbs)) {
        $baseName       = pathinfo($filename, PATHINFO_FILENAME);
        $transcodedFile = $baseName . '_h264.mp4';
        $transcodedAbs  = $transcodedDirAbs . '/' . $transcodedFile;

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

// 文章当前前台并未使用独立封面图，为简化逻辑与磁盘占用，这里不再为文章视频生成单独封面文件。
// $posterRelative 保持为 null 即可。

// 尝试将文章视频记录到 article_videos 表（仅在已有 article_id 时）
if ($articleId > 0 && class_exists('Database')) {
    try {
        $db          = Database::getInstance();
        $currentUser = $auth->getCurrentUser();
        $uploaderId  = !empty($currentUser['id']) ? (int) $currentUser['id'] : null;

        $db->insert('article_videos', [
            'article_id'          => $articleId,
            'video_path'          => $finalVideoRelative,
            'original_video_path' => $isTranscodedFlag ? $originalRelative : null,
            'poster_path'         => $posterRelative,
            'description'         => null,
            'uploader_id'         => $uploaderId,
            'is_transcoded'       => $isTranscodedFlag ? 1 : 0,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // 入库失败不影响上传主流程
    }
}

// 若转码成功，则可以安全删除原始上传文件，仅保留统一编码后的播放文件
if ($isTranscodedFlag && $originalRelative !== '') {
    try {
        deleteFile($originalRelative);
    } catch (Throwable $e) {
        // 删除失败不影响主流程
    }
}

$finalRelative = ltrim($finalVideoRelative, '/');
$url           = upload_url($finalRelative);

if ($url === '') {
    echo json_encode(['errno' => 1, 'message' => '生成视频地址失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'errno' => 0,
    'data'  => [$url],
], JSON_UNESCAPED_UNICODE);
exit;
