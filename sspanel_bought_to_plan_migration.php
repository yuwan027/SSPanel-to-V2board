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
 * SSpanel bought表到V2board plan_id迁移脚本
 * 
 * 迁移规则：
 * - 从SSpanel的bought表按id从小到大排序
 * - 对每个userid去重，保留id最大的shopid
 * - 通过userid在user表找到email
 * - 通过email在v2_user表更新plan_id
 */
class SSpanelBoughtToPlanMigration
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
        echo "开始bought表到plan_id迁移...\n";
        
        try {
            // 开始事务
            $this->targetDb->beginTransaction();
            
            // 执行具体的迁移逻辑
            $this->executeMigration();
            
            // 提交事务
            $this->targetDb->commit();
            echo "bought表到plan_id迁移完成！\n";
            
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
        $this->log("开始SSpanel bought表到V2board plan_id迁移");
        
        // 1. 获取每个用户最后一次购买的shopid（按bought.id排序，去重保留id最大的）
        $userLastBought = $this->getUserLastBought();
        
        // 2. 更新v2_user表的plan_id
        $this->updateUserPlanId($userLastBought);
        
        $this->log("bought表到plan_id迁移完成");
    }
    
    /**
     * 获取每个用户最后一次购买的shopid
     * 按bought.id从小到大排序，对userid去重保留id最大的记录
     */
    private function getUserLastBought()
    {
        $this->log("开始获取用户最后一次购买记录...");
        
        // 查询所有bought记录，按id排序
        $sql = "
            SELECT 
                b.id,
                b.userid,
                b.shopid,
                b.datetime,
                u.email
            FROM bought b
            INNER JOIN user u ON b.userid = u.id
            ORDER BY b.id ASC
        ";
        
        $stmt = $this->sourceDb->query($sql);
        $allBought = $stmt->fetchAll();
        
        $this->log("找到 " . count($allBought) . " 条购买记录");
        
        // 对每个userid去重，保留id最大的记录
        $userLastBought = [];
        foreach ($allBought as $bought) {
            $userId = $bought['userid'];
            
            // 如果这个userid还没有记录，或者当前记录的id更大，则更新
            if (!isset($userLastBought[$userId]) || $bought['id'] > $userLastBought[$userId]['id']) {
                $userLastBought[$userId] = [
                    'id' => $bought['id'],
                    'shopid' => $bought['shopid'],
                    'datetime' => $bought['datetime'],
                    'email' => $bought['email']
                ];
            }
        }
        
        $this->log("去重后找到 " . count($userLastBought) . " 个用户的最后购买记录");
        
        return $userLastBought;
    }
    
    /**
     * 更新v2_user表的plan_id
     */
    private function updateUserPlanId($userLastBought)
    {
        $this->log("开始更新v2_user表的plan_id...");
        
        // 获取v2_user表中的所有用户
        $stmt = $this->targetDb->query("SELECT id, email, plan_id FROM v2_user ORDER BY id");
        $v2Users = $stmt->fetchAll();
        
        $this->log("找到 " . count($v2Users) . " 个V2board用户");
        
        $updatedCount = 0;
        $skippedCount = 0;
        
        foreach ($v2Users as $v2User) {
            try {
                // 通过email匹配找到对应的SSpanel用户
                $sspUserId = $this->findSSpanelUserIdByEmail($v2User['email']);
                
                if (!$sspUserId) {
                    $this->log("跳过用户 ID: {$v2User['id']}, Email: {$v2User['email']} - 在SSpanel中未找到");
                    $skippedCount++;
                    continue;
                }
                
                // 检查是否有购买记录
                if (!isset($userLastBought[$sspUserId])) {
                    $this->log("跳过用户 ID: {$v2User['id']}, Email: {$v2User['email']} - 没有购买记录");
                    $skippedCount++;
                    continue;
                }
                
                $boughtData = $userLastBought[$sspUserId];
                $shopId = $boughtData['shopid'];
                
                // 将shopid映射到plan_id
                $planId = $this->mapShopIdToPlanId($shopId);
                
                // 更新plan_id
                $updateSql = "UPDATE v2_user SET plan_id = :plan_id WHERE id = :id";
                $updateStmt = $this->targetDb->prepare($updateSql);
                $updateStmt->execute([
                    'plan_id' => $planId,
                    'id' => $v2User['id']
                ]);
                
                $this->log("更新用户 ID: {$v2User['id']}, Email: {$v2User['email']}, ShopId: {$shopId} -> PlanId: {$planId}, 购买时间: {$boughtData['datetime']}, BoughtId: {$boughtData['id']}");
                $updatedCount++;
                
            } catch (Exception $e) {
                $this->log("更新用户 ID: {$v2User['id']} 失败: " . $e->getMessage());
                $skippedCount++;
            }
        }
        
        $this->log("更新完成 - 成功更新: {$updatedCount} 个用户, 跳过: {$skippedCount} 个用户");
    }
    
    /**
     * 通过email在SSpanel用户表中查找用户ID
     */
    private function findSSpanelUserIdByEmail($email)
    {
        $stmt = $this->sourceDb->prepare("SELECT id FROM user WHERE email = ?");
        $stmt->execute([$email]);
        $result = $stmt->fetch();
        
        return $result ? $result['id'] : null;
    }
    
    /**
     * 将shopid映射到plan_id
     * 这里需要根据实际的shop和plan对应关系来调整
     */
    private function mapShopIdToPlanId($shopId)
    {
        // 根据实际shop和plan对应关系调整的映射规则
        $shopToPlanMapping = [
            1 => 1,   // shopid 1 -> plan_id 1
            2 => 1,   // shopid 2 -> plan_id 1
            3 => 3,   // shopid 3 -> plan_id 3
            4 => 3,   // shopid 4 -> plan_id 3
            5 => 4,   // shopid 5 -> plan_id 4
            6 => 4,   // shopid 6 -> plan_id 4
            7 => 5,   // shopid 7 -> plan_id 5
            12 => 2,  // shopid 12 -> plan_id 2
            13 => 2,  // shopid 13 -> plan_id 2
        ];
        
        // 如果找到映射关系，返回对应的plan_id
        if (isset($shopToPlanMapping[$shopId])) {
            return $shopToPlanMapping[$shopId];
        }
        
        // 如果没有找到映射关系，返回shopid本身（假设shopid和plan_id相同）
        return $shopId;
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
    echo "=== SSpanel bought表到V2board plan_id迁移工具 ===\n\n";
    
    try {
        $migration = new SSpanelBoughtToPlanMigration($sourceConfig, $targetConfig);
        echo "1. 数据库连接检查: ✓\n";
        
        echo "\n准备开始迁移...\n";
        echo "注意: 此操作将更新v2_user表的plan_id字段，请确保已备份数据库！\n";
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
