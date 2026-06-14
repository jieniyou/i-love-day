<?php
/**
 * 认证与权限相关功能
 */

class Auth
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->startSession();
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * 用户登录（带简单防爆破）
     */
    public function login(string $username, string $password): bool
    {
        $username = trim($username);
        $password = (string) $password;

        if ($username === '' || $password === '') {
            return false;
        }

        // 统一使用配置中的防爆破参数（config/config.php 已定义）
        $ip  = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $now = time();

        // 确保存在登录尝试记录表（幂等创建）
        try {
            $this->db->query("
                CREATE TABLE IF NOT EXISTS `login_attempts` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `username` varchar(50) DEFAULT NULL,
                    `ip` varchar(45) DEFAULT NULL,
                    `success` tinyint(1) NOT NULL DEFAULT 0,
                    `created_at` datetime NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_username_ip_time` (`username`,`ip`,`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录尝试记录';
            ");
        } catch (Exception $e) {
            // 表创建失败时不影响主流程，只回退到无持久化记录
        }

        // 基于账号 + IP 的防爆破：统计最近窗口期内的失败次数
        try {
            $windowStart = date('Y-m-d H:i:s', $now - LOGIN_ATTEMPT_WINDOW);
            $row = $this->db->fetch(
                "SELECT COUNT(*) AS c, MAX(created_at) AS last_fail
                 FROM login_attempts
                 WHERE username = :u AND ip = :ip AND success = 0 AND created_at >= :start",
                [
                    'u'     => strtolower($username),
                    'ip'    => $ip,
                    'start' => $windowStart,
                ]
            );

            $failCount = $row ? (int) ($row['c'] ?? 0) : 0;
            $lastFail  = $row && !empty($row['last_fail']) ? strtotime($row['last_fail']) : 0;

            if ($failCount >= LOGIN_MAX_ATTEMPTS && $lastFail && ($now - $lastFail) < LOGIN_LOCKOUT_SECONDS) {
                // 仍在封禁期内，直接拒绝登录
                return false;
            }
        } catch (Exception $e) {
            // 查询失败时忽略，继续正常登录流程
        }

        // 基于 IP 的全局防爆破：限制同一 IP 在窗口期内的总失败次数
        try {
            $ipRow = $this->db->fetch(
                "SELECT COUNT(*) AS c, MAX(created_at) AS last_fail
                 FROM login_attempts
                 WHERE ip = :ip AND success = 0 AND created_at >= :start",
                [
                    'ip'    => $ip,
                    'start' => $windowStart,
                ]
            );

            $ipFailCount = $ipRow ? (int) ($ipRow['c'] ?? 0) : 0;
            $ipLastFail  = $ipRow && !empty($ipRow['last_fail']) ? strtotime($ipRow['last_fail']) : 0;

            // 同一 IP 在窗口期内失败次数超过 20 次则临时封禁（公网环境更严格）
            if ($ipFailCount >= 20 && $ipLastFail && ($now - $ipLastFail) < LOGIN_LOCKOUT_SECONDS) {
                return false;
            }
        } catch (Exception $e) {
            // 查询失败时忽略
        }

        // 继续登录校验：仅允许情侣角色（user1 / user2）登录
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE username = ? AND status = 'active' AND role IN ('user1','user2')",
            [$username]
        );

        $loginSuccess = $user && password_verify($password, $user['password']);

        // 记录登录尝试结果（最佳努力，不影响主流程）
        try {
            $this->db->insert('login_attempts', [
                'username'   => strtolower($username),
                'ip'         => $ip,
                'success'    => $loginSuccess ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s', $now),
            ]);
        } catch (Exception $e) {
            // 记录失败忽略
        }

        if ($loginSuccess) {
            // 登录成功：刷新 session id 防止会话固定
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['nickname']  = $user['nickname'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['avatar']    = $user['avatar'];
            return true;
        }
        // 登录失败时增加微小延迟，进一步降低暴力破解效率
        usleep(200000); // 约 200ms
        return false;
    }

    /**
     * 用户注册
     */
    public function register(string $username, string $password, string $nickname, string $role = 'user1'): array
    {
        // 基础输入校验：长度与字符集限制
        $username = trim($username);
        $nickname = trim($nickname);
        $role     = trim($role);

        if ($username === '' || $password === '' || $nickname === '') {
            return ['success' => false, 'message' => '请填写所有必填项'];
        }

        // 用户名：限制为 3~32 位的字母、数字、下划线
        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
            return ['success' => false, 'message' => '用户名格式不合法（仅允许字母、数字和下划线，长度 3~32 位）'];
        }

        // 昵称：控制在 1~32 个字符以内（UTF-8 长度）
        if (mb_strlen($nickname, 'UTF-8') > 32) {
            return ['success' => false, 'message' => '昵称长度不能超过 32 个字符'];
        }

        // 密码：至少 8 位
        if (strlen($password) < 8) {
            return ['success' => false, 'message' => '密码长度不能少于 8 位'];
        }

        // 角色仅允许 user1 / user2
        if (!in_array($role, ['user1', 'user2'], true)) {
            return ['success' => false, 'message' => '角色不合法'];
        }

        // 限制最多只能有两位活跃用户（情侣两人）
        $countRow = $this->db->fetch(
            "SELECT COUNT(*) AS c FROM users WHERE status = 'active'"
        );
        $activeCount = $countRow ? (int) $countRow['c'] : 0;
        if ($activeCount >= 2) {
            return ['success' => false, 'message' => '当前已注册满两位用户，已关闭注册'];
        }

        // 检查用户名是否已存在
        $existing = $this->db->fetch(
            "SELECT id FROM users WHERE username = ?",
            [$username]
        );

        if ($existing) {
            return ['success' => false, 'message' => '用户名已存在'];
        }

        // 检查角色是否已被使用（user1 / user2 只能各有一人）
        if ($role === 'user1' || $role === 'user2') {
            $existingRole = $this->db->fetch(
                "SELECT id FROM users WHERE role = ?",
                [$role]
            );

            if ($existingRole) {
                return ['success' => false, 'message' => '该角色已被使用'];
            }
        }

        $data = [
            'username'   => $username,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'nickname'   => $nickname,
            'role'       => $role,
            'avatar'     => '/assets/images/default-avatar.svg',
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $userId = $this->db->insert('users', $data);

        return ['success' => true, 'user_id' => $userId];
    }

    /**
     * 用户退出登录
     */
    public function logout(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        return true;
    }

    /**
     * 是否已登录
     */
    public function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    /**
     * 获取当前登录用户信息
     */
    public function getCurrentUser(): ?array
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return [
            'id'       => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'nickname' => $_SESSION['nickname'],
            'role'     => $_SESSION['role'],
            'avatar'   => $_SESSION['avatar']
        ];
    }

    /**
     * 获取另一半（情侣另一方）的用户信息
     */
    public function getPartner(): ?array
    {
        $currentUser = $this->getCurrentUser();
        if (!$currentUser) {
            return null;
        }

        $partnerRole = $currentUser['role'] === 'user1' ? 'user2' : 'user1';

        return $this->db->fetch(
            "SELECT * FROM users WHERE role = ? AND status = 'active'",
            [$partnerRole]
        );
    }

    /**
     * 需要登录后才能访问的页面调用
     */
    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }

    /**
     * 要求当前登录用户具有指定角色之一
     * 用于后台等敏感区域的访问控制
     *
     * @param string[] $roles
     */
    public function requireRole(array $roles): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
            header('Location: /login.php');
            exit;
        }

        $currentRole = (string) $_SESSION['role'];
        if (!in_array($currentRole, $roles, true)) {
            // 非法访问后台或受限区域时，统一跳转到首页
            header('Location: /');
            exit;
        }
    }

    /**
     * 更新当前用户资料
     */
    public function updateProfile(array $data): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }

        // 确保用户资料相关扩展字段在 users 表中存在（兼容老版本数据库结构）
        $this->ensureUserProfileColumns();

        $userId = $_SESSION['user_id'];

        // 密码单独处理
        if (isset($data['new_password']) && $data['new_password'] !== '') {
            $data['password'] = password_hash($data['new_password'], PASSWORD_DEFAULT);
        }
        unset($data['new_password']);

        if (isset($data['password']) && $data['password'] === '') {
            unset($data['password']);
        }

        $this->db->update('users', $data, 'id = :id', ['id' => $userId]);

        // 同步更新 session
        if (isset($data['nickname'])) {
            $_SESSION['nickname'] = $data['nickname'];
        }
        if (isset($data['avatar'])) {
            $_SESSION['avatar'] = $data['avatar'];
        }
    }

    /**
     * 兼容性处理：确保 users 表中存在新版本资料字段（例如 qq、avatar_source）
     * 仅在需要更新资料时检查一次，避免手工执行 SQL
     */
    private function ensureUserProfileColumns(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            // 检查并自动创建 qq 字段
            $row = $this->db->fetch("SHOW COLUMNS FROM `users` LIKE 'qq'");
            if (!$row) {
                $this->db->query("ALTER TABLE `users` ADD COLUMN `qq` varchar(32) DEFAULT NULL COMMENT 'QQ 号'");
            }

            // 检查并自动创建 avatar_source 字段（记录头像来源：上传/QQ）
            $row = $this->db->fetch("SHOW COLUMNS FROM `users` LIKE 'avatar_source'");
            if (!$row) {
                $this->db->query("ALTER TABLE `users` ADD COLUMN `avatar_source` varchar(20) DEFAULT 'upload' COMMENT '头像来源'");
            }
        } catch (Exception $e) {
            // 兼容环境中无权限 ALTER TABLE 时，不中断主流程，仅放弃自动修复
        }

        $checked = true;
    }
}
