<?php
/**
 * 安装脚本
 */

// 设置 UTF-8 编码
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// 先判断是否已安装：已安装时禁止访问安装页面，直接跳转到首页
if (file_exists(__DIR__ . '/.installed')) {
    header('Location: /');
    exit;
}

// 再检查安装解锁文件：没有 enable_install.lock 时禁止访问安装脚本
$installLockFile = __DIR__ . '/enable_install.lock';
if (!file_exists($installLockFile)) {
    http_response_code(403);
    echo '安装未解锁。请在网站根目录创建 enable_install.lock 文件后再访问本页面。';
    exit;
}

// 确保安装时存在全局配置文件（某些环境可能未上传 config/config.php）
$configPath = __DIR__ . '/config/config.php';
if (!file_exists($configPath)) {
    if (!is_dir(__DIR__ . '/config')) {
        mkdir(__DIR__ . '/config', 0755, true);
    }
    $defaultConfig = <<<'PHP'
<?php
/**
 * 应用全局配置文件（前台和后台共用）
 */

// 调试模式：上线后建议为 false
define('DEBUG_MODE', false);

// 错误报告：开发环境显示错误，上线后仅记录到日志
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 应用根目录
define('ROOT_PATH', dirname(__DIR__));

// 应用 URL：根据当前请求自动生成（包含协议）
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL', $scheme . '://' . $host);

// Session 设置
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
if ($scheme === 'https') {
    ini_set('session.cookie_secure', '1');
}
ini_set('session.cookie_samesite', 'Lax');

// 安全相关 HTTP 响应头（仅在非 CLI 环境下设置）
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($scheme === 'https') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// 文件上传设置
define('UPLOAD_DIR', ROOT_PATH . '/uploads/');
define('UPLOAD_URL', BASE_URL . '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// 安全密钥（请在生产环境中改为随机字符串）
define('SECRET_KEY', 'your-secret-key-change-this-in-production');

// 登录防爆破配置
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_ATTEMPT_WINDOW', 900);
define('LOGIN_LOCKOUT_SECONDS', 900);

// 站点名称
define('SITE_NAME', '我们的小情侣网站');
PHP;

    file_put_contents($configPath, $defaultConfig);
}

require_once $configPath;
require_once __DIR__ . '/core/helpers.php';

$error = '';
$success = '';
// 优先从 POST 中读取步骤（因为表单是 POST 提交），再回退到 GET，最后默认为 1
$step = isset($_POST['step'])
    ? intval($_POST['step'])
    : (isset($_GET['step']) ? intval($_GET['step']) : 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    // 第一步：数据库配置
    $dbConfig = [
        'host' => $_POST['host'] ?? 'localhost',
        'port' => intval($_POST['port'] ?? 3306),
        'dbname' => $_POST['dbname'] ?? 'couple_website',
        'username' => $_POST['username'] ?? 'root',
        'password' => $_POST['password'] ?? '',
    ];

    // 为后续运行统一补充 charset 和 PDO 选项，避免安装后配置不完整
    if (!isset($dbConfig['charset'])) {
        // 默认优先使用 utf8mb4，后续在 Database 类中会自动回退到 utf8（兼容老 MySQL）
        $dbConfig['charset'] = 'utf8mb4';
    }
    if (!isset($dbConfig['options'])) {
        $dbConfig['options'] = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
    }
    
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $dbConfig['host'],
            $dbConfig['port']
        );
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
        
        // 创建数据库
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbConfig['dbname']}`");
        
        // 保存配置（安装完成后，系统将直接使用该配置）
        $configContent = "<?php\nreturn " . var_export($dbConfig, true) . ";\n";
        file_put_contents(__DIR__ . '/config/database.php', $configContent);
        
        // 导入数据库结构
        $sql = file_get_contents(__DIR__ . '/database/schema.sql');
        $statements = explode(';', $sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }
        
        $step = 2;
    } catch (PDOException $e) {
        $error = '数据库连接失败：' . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    // 第二步：创建管理员账号
    require_once __DIR__ . '/core/Database.php';
    require_once __DIR__ . '/core/Auth.php';

    // 安装时也允许选择角色（男/女），内部仍使用 user1/user2
    $role = $_POST['role'] ?? 'user1';
    if (!in_array($role, ['user1', 'user2'], true)) {
        $role = 'user1';
    }

    $auth = new Auth();
    $result = $auth->register(
        $_POST['username'] ?? '',
        $_POST['password'] ?? '',
        $_POST['nickname'] ?? '',
        $role
    );

    if ($result['success']) {
        // 管理员创建成功，进入第三步：站点基础信息设置
        $success = '管理员账号创建成功，请继续配置网站基础信息。';
        $step    = 3;
    } else {
        $error = $result['message'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    // 第三步：设置网站基础信息
    require_once __DIR__ . '/core/Database.php';
    $db = Database::getInstance();

    $siteTitle       = trim($_POST['site_title'] ?? '');
    $siteDescription = trim($_POST['site_description'] ?? '');
    $loveDate        = trim($_POST['love_date'] ?? '');

    if ($siteTitle === '') {
        $siteTitle = SITE_NAME;
    }

    try {
        // 兼容安装向导中的恋爱开始时间：允许使用日期或精确到秒
        if ($loveDate !== '') {
            $normalizedLove = str_replace(' ', 'T', $loveDate);
            $dtLove = date_create($normalizedLove);
            if ($dtLove instanceof DateTime) {
                $loveDate = $dtLove->format('Y-m-d H:i:s');
            }
        }

        $settingsToSave = [
            'site_title'       => $siteTitle,
            'site_description' => $siteDescription,
            'love_date'        => $loveDate,
        ];

        foreach ($settingsToSave as $key => $value) {
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

        // 标记安装完成
        file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));
        // 安装完成后，删除安装解锁文件，避免长期遗留提升误操作风险
        if (file_exists($installLockFile)) {
            @unlink($installLockFile);
        }
        $success = '安装完成！';
        $step    = 4;
    } catch (Exception $e) {
        $error = '保存网站信息失败：' . $e->getMessage();
        $step  = 3;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装 - <?php echo e(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css"
          onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
</head>
<body class="auth-page install-page">
    <?php
    // 安装步骤总数（不含“完成”页）
    $totalSteps   = 3;
    $displayStep  = $step > $totalSteps ? $totalSteps : $step;
    ?>
    <div class="auth-container">
        <div class="auth-box glass-card">
            <div class="auth-header">
                <h1><i class="fas fa-heart"></i> 安装向导</h1>
                <p>步骤 <?php echo $displayStep; ?> / <?php echo $totalSteps; ?></p>
            </div>
            
            <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo e($success); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($step === 1): ?>
            <form method="POST" novalidate>
                <input type="hidden" name="step" value="1">
                <h3>数据库配置</h3>
                <div class="form-group">
                    <label>数据库主机</label>
                    <input type="text" name="host" value="localhost">
                </div>
                <div class="form-group">
                    <label>端口</label>
                    <input type="number" name="port" value="3306">
                </div>
                <div class="form-group">
                    <label>数据库名称</label>
                    <input type="text" name="dbname" value="couple_website">
                </div>
                <div class="form-group">
                    <label>数据库用户名</label>
                    <input type="text" name="username" value="root">
                </div>
                <div class="form-group">
                    <label>数据库密码</label>
                    <input type="password" name="password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-arrow-right"></i> 下一步
                </button>
            </form>
            <?php elseif ($step === 2): ?>
            <form method="POST" novalidate>
                <input type="hidden" name="step" value="2">
                <h3>创建第一个账号</h3>
                <div class="form-group">
                    <label><i class="fas fa-user"></i> 用户名</label>
                    <input type="text" name="username">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> 昵称</label>
                    <input type="text" name="nickname">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> 密码</label>
                    <input type="password" name="password">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user-friends"></i> 角色（选择当前用户在网站中的身份）</label>
                    <div class="role-toggle">
                        <label class="role-option">
                            <input type="radio" name="role" value="user1" checked>
                            <span class="role-option-inner">
                                <i class="fas fa-mars role-male-icon"></i>
                                <span class="role-text-main">男生</span>
                                <span class="role-text-sub">（角色 1）</span>
                            </span>
                        </label>
                        <label class="role-option">
                            <input type="radio" name="role" value="user2">
                            <span class="role-option-inner">
                                <i class="fas fa-venus role-female-icon"></i>
                                <span class="role-text-main">女生</span>
                                <span class="role-text-sub">（角色 2）</span>
                            </span>
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-check"></i> 完成安装
                </button>
            </form>
            <?php elseif ($step === 3): ?>
            <form method="POST">
                <input type="hidden" name="step" value="3">
                <h3>网站基础信息</h3>
                <div class="form-group">
                    <label><i class="fas fa-heading"></i> 网站标题</label>
                    <input type="text" name="site_title"
                           value="<?php echo isset($_POST['site_title']) ? e($_POST['site_title']) : e(SITE_NAME); ?>"
                           placeholder="例如：我们的小情侣网站">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-align-left"></i> 网站描述</label>
                    <textarea name="site_description"
                              placeholder="简单介绍一下你们的故事～"><?php echo isset($_POST['site_description']) ? e($_POST['site_description']) : ''; ?></textarea>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-days"></i> 恋爱开始时间</label>
                    <input type="datetime-local" name="love_date"
                           value="<?php echo isset($_POST['love_date']) ? e($_POST['love_date']) : ''; ?>"
                           placeholder="未设置" step="1">
                    <small>用于计算在一起的天数，支持精确到秒（可留空，未设置时默认按当前日期开始计算）。</small>
                </div>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-check-circle"></i> 保存并完成安装
                </button>
            </form>
            <?php elseif ($step === 4): ?>
            <div style="text-align: center; padding: 2rem 0;">
                <i class="fas fa-check-circle" style="font-size: 4rem; color: #4ecdc4; margin-bottom: 1rem;"></i>
                <h2>安装完成！</h2>
                <p style="margin: 1.5rem 0;">系统已成功安装，现在可以开始使用了！</p>
                <p style="margin-bottom: 2rem; font-size: 0.9rem; color: #6b7280;">
                    出于安全考虑，建议现在删除站点根目录下的 <code>enable_install.lock</code> 文件
                    （如无特殊需要，也可以备份后删除 <code>install.php</code>）。
                </p>
                <a href="/login.php" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> 前往登录
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="/assets/js/main.js"></script>
</body>
</html>
