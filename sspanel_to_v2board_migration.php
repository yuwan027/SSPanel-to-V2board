<?php

// ==================== 配置区域 ====================
// 请根据实际情况修改以下配置

// 源数据库配置 (SSpanel数据库)
$sourceConfig = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => '',  // SSpanel数据库
    'username' => '',
    'password' => ''
];

// 目标数据库配置 (V2board项目数据库)
$targetConfig = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => '',  // 当前项目数据库
    'username' => '',
    'password' => ''
];

// ==================== 迁移类 ====================

/**
 * SSpanel到V2board数据库迁移脚本
 * 
 * 迁移规则：
 * - a数据库(SSpanel) -> b数据库(V2board)
 * - 从第二行开始插入数据（v2_user内已有一行数据）
 * - 字段映射和转换规则
 */
class SSpanelToV2boardMigration
{
    private $sourceDb;      // 源数据库连接
    private $targetDb;      // 目标数据库连接
    private $sourceConfig;
    private $targetConfig;
    
    public function __construct($sourceConfig, $targetConfig)
    {
        $this->sourceConfig = $sourceConfig;
        $this->targetConfig = $targetConfig;
        $this->connectDatabases();
    }
    
    /**
     * 连接数据库
     */
    private function connectDatabases()
    {
        try {
            // 连接源数据库
            $this->sourceDb = new PDO(
                "mysql:host={$this->sourceConfig['host']};port={$this->sourceConfig['port']};dbname={$this->sourceConfig['database']};charset=utf8mb4",
                $this->sourceConfig['username'],
                $this->sourceConfig['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
            
            // 连接目标数据库
            $this->targetDb = new PDO(
                "mysql:host={$this->targetConfig['host']};port={$this->targetConfig['port']};dbname={$this->targetConfig['database']};charset=utf8mb4",
                $this->targetConfig['username'],
                $this->targetConfig['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
            
            echo "数据库连接成功！\n";
            
        } catch (PDOException $e) {
            die("数据库连接失败: " . $e->getMessage() . "\n");
        }
    }
    
    /**
     * 开始迁移
     */
    public function migrate()
    {
        echo "开始数据迁移...\n";
        
        try {
            // 开始事务
            $this->targetDb->beginTransaction();
            
            // 执行具体的迁移逻辑
            $this->executeMigration();
            
            // 提交事务
            $this->targetDb->commit();
            echo "数据迁移完成！\n";
            
            // 迁移完成后重置ID
            $this->resetUserIds();
            
        } catch (Exception $e) {
            // 回滚事务
            $this->targetDb->rollBack();
            echo "迁移失败: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    /**
     * 执行迁移逻辑
     */
    private function executeMigration()
    {
        $this->log("开始SSpanel到V2board数据迁移");
        
        // 1. 迁移用户数据
        $this->migrateUsers();
        
        $this->log("数据迁移完成");
    }
    
    /**
     * 迁移用户数据
     */
    private function migrateUsers()
    {
        $this->log("开始迁移用户数据...");
        
        // 获取源数据库用户数据
        $stmt = $this->sourceDb->query("SELECT * FROM user ORDER BY id");
        $users = $stmt->fetchAll();
        
        $this->log("找到 " . count($users) . " 个用户需要迁移");
        
        // 获取目标数据库当前最大ID
        $stmt = $this->targetDb->query("SELECT MAX(id) as max_id FROM v2_user");
        $result = $stmt->fetch();
        $nextId = ($result['max_id'] ?? 0) + 1;
        
        $this->log("目标数据库当前最大ID: " . ($result['max_id'] ?? 0) . ", 下一个ID: " . $nextId);
        
        foreach ($users as $user) {
            try {
                // 从link表获取token字段，使用userid字段匹配
                $linkStmt = $this->sourceDb->prepare("SELECT token FROM link WHERE userid = ?");
                $linkStmt->execute([$user['id']]);
                $linkData = $linkStmt->fetch();
                
                // 处理class字段映射到group_id
                $groupId = $this->processClassToGroupId($user['class']);
                
                // 处理plan_id逻辑
                $planId = $this->processPlanId($user['class'], $user['class_expire'], $user['auto_reset_bandwidth']);
                
                // 处理带宽和流量计算
                $bandwidthData = $this->processBandwidthAndTraffic($user);
                
                // 准备插入数据
                $insertData = [
                    'id' => $nextId,
                    'invite_user_id' => $user['ref_by'] ? ($user['ref_by'] + 1) : null,
                    'telegram_id' => $user['telegram_id'],
                    'email' => $user['email'],
                    'password' => $user['pass'],
                    'password_algo' => null,
                    'password_salt' => null,
                    'balance' => $user['money'],
                    't' => $user['t'],
                    'u' => $bandwidthData['u'],
                    'd' => $bandwidthData['d'],
                    'transfer_enable' => $bandwidthData['transfer_enable'],
                    'device_limit' => null,
                    'banned' => 0,
                    'is_admin' => 0,
                    'last_login_at' => null,
                    'is_staff' => 0,
                    'last_login_ip' => null,
                    'uuid' => $user['passwd'],  // 使用user表的passwd字段作为uuid
                    'group_id' => $groupId,
                    'plan_id' => $planId,
                    'speed_limit' => null,
                    'auto_renewal' => 0,
                    'remind_expire' => 1,
                    'remind_traffic' => 1,
                    'token' => $linkData['token'] ?? $this->generateToken(),
                    'expired_at' => $this->dateToTimestamp($user['class_expire'] ?? null),
                    'remarks' => null,
                    'created_at' => $this->dateToTimestamp($user['reg_date']),
                    'updated_at' => time()
                ];
                
                // 插入数据
                $sql = "INSERT INTO v2_user (
                    id, invite_user_id, telegram_id, email, password, password_algo, password_salt,
                    balance, t, u, d, transfer_enable, device_limit, banned, is_admin,
                    last_login_at, is_staff, last_login_ip, uuid, group_id, plan_id,
                    speed_limit, auto_renewal, remind_expire, remind_traffic, token,
                    expired_at, remarks, created_at, updated_at
                ) VALUES (
                    :id, :invite_user_id, :telegram_id, :email, :password, :password_algo, :password_salt,
                    :balance, :t, :u, :d, :transfer_enable, :device_limit, :banned, :is_admin,
                    :last_login_at, :is_staff, :last_login_ip, :uuid, :group_id, :plan_id,
                    :speed_limit, :auto_renewal, :remind_expire, :remind_traffic, :token,
                    :expired_at, :remarks, :created_at, :updated_at
                )";
                
                $stmt = $this->targetDb->prepare($sql);
                $stmt->execute($insertData);
                
                $this->log("迁移用户 ID: {$user['id']} -> {$nextId}, Email: {$user['email']}, Class: {$user['class']}, GroupId: {$groupId}, PlanId: {$planId}");
                $this->log("  带宽: auto_reset_bandwidth={$user['auto_reset_bandwidth']}GB, transfer_enable={$bandwidthData['transfer_enable']} bytes");
                $this->log("  流量: u={$bandwidthData['u']}, d={$bandwidthData['d']}, t={$user['t']}");
                $nextId++;
                
            } catch (Exception $e) {
                $this->log("迁移用户 ID: {$user['id']} 失败: " . $e->getMessage());
                // 继续处理下一个用户
            }
        }
        
        $this->log("用户数据迁移完成");
    }
    
    /**
     * 处理带宽和流量计算
     */
    private function processBandwidthAndTraffic($user)
    {
        $autoResetBandwidth = floatval($user['auto_reset_bandwidth'] ?? 0);
        $sspTransferEnable = intval($user['transfer_enable'] ?? 0);
        $sspU = intval($user['u'] ?? 0);
        $sspD = intval($user['d'] ?? 0);
        
        // 1. 确定transfer_enable
        if ($autoResetBandwidth > 0) {
            // auto_reset_bandwidth不为0 -> transfer_enable(十进制GB转二进制字节)
            $transferEnable = $this->gbToBytes($autoResetBandwidth);
        } else {
            // auto_reset_bandwidth为0 -> transfer_enable(ssp) -> transfer_enable(v2_user)
            $transferEnable = $sspTransferEnable;
        }
        
        // 2. 计算已使用流量: transfer_enable(ssp) - u(ssp) - d(ssp)
        $usedTraffic = max(0, $sspTransferEnable - $sspU - $sspD);
        
        // 3. 计算剩余可用流量: transfer_enable(v2_user) - 已使用流量
        $remainingTraffic = max(0, $transferEnable - $usedTraffic);
        
        // 4. 按比例分配u和d
        $totalUsed = $sspU + $sspD;
        if ($totalUsed > 0) {
            // 按比例计算
            $uRatio = $sspU / $totalUsed;
            $dRatio = $sspD / $totalUsed;
            
            $u = intval($remainingTraffic * $uRatio);
            $d = intval($remainingTraffic * $dRatio);
        } else {
            // 如果没有使用流量，都设为0
            $u = 0;
            $d = 0;
        }
        
        return [
            'transfer_enable' => $transferEnable,
            'u' => $u,
            'd' => $d
        ];
    }
    
    /**
     * 将GB转换为字节
     * 使用二进制转换: 1 GiB = 1024^3 bytes
     */
    private function gbToBytes($gb)
    {
        if ($gb <= 0) {
            return 0;
        }
        
        // 使用二进制转换 (1 GiB = 1024^3 bytes)
        return intval($gb * 1024 * 1024 * 1024);
    }
    
    /**
     * 重置v2_user表ID
     */
    private function resetUserIds()
    {
        $this->log("开始重置v2_user表ID...");
        
        try {
            // 开始事务
            $this->targetDb->beginTransaction();
            
            // 获取所有用户数据，按ID排序
            $stmt = $this->targetDb->query("SELECT * FROM v2_user ORDER BY id ASC");
            $users = $stmt->fetchAll();
            
            if (empty($users)) {
                $this->log("v2_user表没有数据");
                return;
            }
            
            $this->log("找到 " . count($users) . " 个用户需要重置ID");
            
            // 创建ID映射表
            $idMapping = [];
            $newId = 1;
            
            foreach ($users as $user) {
                $oldId = $user['id'];
                $idMapping[$oldId] = $newId;
                $newId++;
            }
            
            // 创建临时表
            $tempTable = 'v2_user_temp_' . time();
            $this->targetDb->exec("CREATE TABLE {$tempTable} LIKE v2_user");
            
            // 将数据按新ID插入临时表
            foreach ($users as $user) {
                $oldId = $user['id'];
                $newId = $idMapping[$oldId];
                
                // 更新ID
                $user['id'] = $newId;
                
                // 更新invite_user_id（如果有的话）
                if ($user['invite_user_id'] && isset($idMapping[$user['invite_user_id']])) {
                    $user['invite_user_id'] = $idMapping[$user['invite_user_id']];
                } else {
                    $user['invite_user_id'] = null;
                }
                
                // 插入到临时表
                $this->insertUserToTempTable($tempTable, $user);
                
                $this->log("ID映射: {$oldId} -> {$newId}, invite_user_id: " . ($user['invite_user_id'] ?? 'NULL'));
            }
            
            // 删除原表数据
            $this->targetDb->exec("DELETE FROM v2_user");
            
            // 重置自增ID
            $this->targetDb->exec("ALTER TABLE v2_user AUTO_INCREMENT = 1");
            
            // 从临时表复制数据回原表，按ID排序插入
            $this->targetDb->exec("INSERT INTO v2_user SELECT * FROM {$tempTable} ORDER BY id ASC");
            
            // 删除临时表
            $this->targetDb->exec("DROP TABLE {$tempTable}");
            
            // 优化表，确保物理存储顺序
            $this->targetDb->exec("OPTIMIZE TABLE v2_user");
            
            // 提交事务
            $this->targetDb->commit();
            $this->log("v2_user表ID重置完成！");
            
        } catch (Exception $e) {
            // 回滚事务
            $this->targetDb->rollBack();
            $this->log("ID重置失败: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 插入用户到临时表
     */
    private function insertUserToTempTable($tempTable, $user)
    {
        // 确保所有字段都有值，避免NULL值问题
        $columns = [];
        $values = [];
        $placeholders = [];
        
        foreach ($user as $column => $value) {
            $columns[] = $column;
            $placeholders[] = ':' . $column;
            $values[':' . $column] = $value;
        }
        
        $columnList = implode(', ', $columns);
        $placeholderList = implode(', ', $placeholders);
        
        $sql = "INSERT INTO {$tempTable} ({$columnList}) VALUES ({$placeholderList})";
        $stmt = $this->targetDb->prepare($sql);
        $stmt->execute($values);
    }
    
    /**
     * 处理class字段映射到group_id
     * group_id(v2_user)应用class(ssp)的值，若class=0则设置NULL
     */
    private function processClassToGroupId($class)
    {
        if ($class == 0 || $class === null || $class === '') {
            return null;
        }
        return (int)$class;
    }
    
    /**
     * 处理plan_id逻辑
     * - class=0: plan_id=NULL
     * - class≠0且class_expire>当前: 
     *   - auto_reset_bandwidth=0: plan_id=2
     *   - auto_reset_bandwidth≠0: plan_id=1
     * - class≠0且class_expire≤当前: plan_id=NULL
     */
    private function processPlanId($class, $classExpire, $autoResetBandwidth)
    {
        // class为0，直接返回NULL
        if ($class == 0 || $class === null || $class === '') {
            return null;
        }
        
        // class不为0，检查class_expire是否超过现在时间
        if ($classExpire) {
            $expireTimestamp = $this->dateToTimestamp($classExpire);
            $currentTimestamp = time();
            
            // 如果过期时间超过现在时间
            if ($expireTimestamp && $expireTimestamp > $currentTimestamp) {
                // 判断auto_reset_bandwidth
                if (floatval($autoResetBandwidth) == 0) {
                    return 2;  // auto_reset_bandwidth=0 -> plan_id=2
                } else {
                    return 1;  // auto_reset_bandwidth≠0 -> plan_id=1
                }
            }
        }
        
        // class_expire未超过现在时间或为空，返回NULL
        return null;
    }
    
    /**
     * 将日期时间字符串转换为时间戳
     */
    private function dateToTimestamp($dateString)
    {
        if (empty($dateString)) {
            return null;
        }
        return strtotime($dateString);
    }
    
    /**
     * 将字母转换为数字 (A=1, B=2, ..., Z=26)
     */
    private function letterToNumber($letter)
    {
        if (empty($letter)) {
            return null;
        }
        return ord(strtoupper($letter)) - ord('A') + 1;
    }
    
    /**
     * 生成UUID
     */
    private function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * 生成随机token
     */
    private function generateToken()
    {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * 记录迁移日志
     */
    private function log($message)
    {
        echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
    }
    
    /**
     * 关闭数据库连接
     */
    public function __destruct()
    {
        $this->sourceDb = null;
        $this->targetDb = null;
    }
}

// ==================== 运行脚本 ====================

if (php_sapi_name() === 'cli') {
    echo "=== SSpanel到V2board数据库迁移工具 ===\n\n";
    
    try {
        $migration = new SSpanelToV2boardMigration($sourceConfig, $targetConfig);
        echo "1. 数据库连接检查: ✓\n";
        
        echo "\n准备开始迁移...\n";
        echo "注意: 此操作将向v2_user表插入数据并重置ID，请确保已备份数据库！\n";
        echo "按 Enter 继续，或 Ctrl+C 取消...\n";
        fgets(STDIN);
        
        $migration->migrate();
        echo "\n=== 迁移完成 ===\n";
        
    } catch (Exception $e) {
        echo "\n=== 迁移失败 ===\n";
        echo "错误: " . $e->getMessage() . "\n";
        exit(1);
    }
}
