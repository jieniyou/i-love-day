<?php
/**
 * 数据库连接类
 */

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $config = require ROOT_PATH . '/config/database.php';

        // 兼容没有 charset / options 的旧配置
        $host    = $config['host']    ?? 'localhost';
        $port    = $config['port']    ?? 3306;
        $dbname  = $config['dbname']  ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';
        $options = $config['options'] ?? [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $dbname,
            $charset
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $options
            );
        } catch (PDOException $e) {
            // 某些较老 MySQL 不支持 utf8mb4，自动回退到 utf8
            if (strpos($e->getMessage(), 'Unknown character set') !== false && $charset === 'utf8mb4') {
                $charset = 'utf8';
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $host,
                    $port,
                    $dbname,
                    $charset
                );

                $this->pdo = new PDO(
                    $dsn,
                    $config['username'],
                    $config['password'],
                    $options
                );
            } else {
                // 上线环境不直接把数据库错误信息暴露给前台，避免泄露主机/库名等敏感信息
                $message = '数据库连接失败';
                // 调试模式下仍然输出详细错误，便于排查
                if (defined('DEBUG_MODE') && DEBUG_MODE) {
                    $message .= ': ' . $e->getMessage();
                }
                // 无论是否调试模式，都记录详细错误到日志
                error_log('[Database] Connection failed: ' . $e->getMessage());
                die($message);
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getPDO() {
        return $this->pdo;
    }
    
    public function query($sql, $params = [], $retry = false) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // 不再在数据库层自动触发安装脚本，安全起见交由上层显式处理
            throw $e;
        }
    }
    
    public function fetch($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = array_map(function($field) {
            return ':' . $field;
        }, $fields);
        
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $fields),
            implode(', ', $placeholders)
        );
        
        $this->query($sql, $data);
        return $this->pdo->lastInsertId();
    }
    
    public function update($table, $data, $where, $whereParams = []) {
        $setParts = [];
        foreach (array_keys($data) as $field) {
            $setParts[] = "`{$field}` = :{$field}";
        }
        
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $setParts),
            $where
        );
        
        $params = array_merge($data, $whereParams);
        $this->query($sql, $params);
        return true;
    }
    
    public function delete($table, $where, $params = []) {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);
        $this->query($sql, $params);
        return true;
    }
    
    public function count($table, $where = '1=1', $params = []) {
        $sql = sprintf('SELECT COUNT(*) AS count FROM `%s` WHERE %s', $table, $where);
        $result = $this->fetch($sql, $params);
        return $result ? (int)$result['count'] : 0;
    }
}
