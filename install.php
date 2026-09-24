<?php
/**
 * 数据库初始化脚本 - 运行一次后删除
 */
$host = 'localhost';
$user = 'root';
$pass = '123456';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `community_board` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `community_board`");

    // 留言表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `messages` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `visitor_id` VARCHAR(64) DEFAULT NULL COMMENT '发起人访客标识',
        `nickname` VARCHAR(50) NOT NULL COMMENT '昵称',
        `phone` VARCHAR(20) DEFAULT NULL COMMENT '联系电话',
        `type` ENUM('help','suggest','lost') NOT NULL DEFAULT 'help' COMMENT '类型: help求助, suggest建议, lost失物招领',
        `title` VARCHAR(100) NOT NULL COMMENT '标题',
        `content` TEXT NOT NULL COMMENT '内容',
        `image` VARCHAR(255) DEFAULT NULL COMMENT '图片路径',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待审核, 1已通过, 2已拒绝',
        `views` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_type` (`type`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言表'");

    // 管理员表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员表'");

    // 收藏表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `favorites` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '访客唯一标识',
        `message_id` INT UNSIGNED NOT NULL COMMENT '留言ID',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '收藏时间',
        UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_message_id` (`message_id`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏表'");

    // 举报表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `reports` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `message_id` INT UNSIGNED NOT NULL COMMENT '被举报的留言ID',
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '举报人访客标识',
        `report_type` VARCHAR(50) NOT NULL COMMENT '举报类型: spam垃圾信息, abuse辱骂攻击, illegal违法违规, porn色情低俗, other其他',
        `description` TEXT COMMENT '补充说明',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待处理, 1已处理-已删除, 2已处理-已忽略, 3已驳回',
        `processed_by` INT UNSIGNED DEFAULT NULL COMMENT '处理人管理员ID',
        `processed_at` DATETIME DEFAULT NULL COMMENT '处理时间',
        `process_note` VARCHAR(500) DEFAULT NULL COMMENT '处理备注',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '举报时间',
        UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
        INDEX `idx_message_id` (`message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`processed_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报表'");

    // 志愿服务时段表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `volunteer_slots` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `message_id` INT UNSIGNED NOT NULL COMMENT '所属求助留言ID',
        `slot_date` DATE NOT NULL COMMENT '服务日期',
        `start_time` TIME NOT NULL COMMENT '服务开始时间',
        `end_time` TIME NOT NULL COMMENT '服务结束时间',
        `quota` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '名额数量',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        INDEX `idx_message_id` (`message_id`),
        INDEX `idx_slot_time` (`slot_date`, `start_time`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='志愿服务时段表'");

    // 志愿响应记录表（active_key 唯一索引保证同一志愿者对同一时段只有一条活跃响应，网络重试不产生重复占位）
    $pdo->exec("CREATE TABLE IF NOT EXISTS `volunteer_responses` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `slot_id` INT UNSIGNED NOT NULL COMMENT '响应的时段ID',
        `message_id` INT UNSIGNED NOT NULL COMMENT '所属求助留言ID(冗余便于校验)',
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '志愿者访客标识',
        `nickname` VARCHAR(50) NOT NULL COMMENT '志愿者昵称',
        `phone` VARCHAR(20) DEFAULT NULL COMMENT '志愿者联系电话',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待确认, 1已确认, 2候补中, 3未采纳, 4已取消',
        `decided_at` DATETIME DEFAULT NULL COMMENT '发起人确认/拒绝时间',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '响应时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        `active_key` VARCHAR(90) GENERATED ALWAYS AS
            (IF(`status` IN (0,1,2), CONCAT(LPAD(`slot_id`, 10, '0'), ':', `visitor_id`), NULL)) STORED,
        UNIQUE KEY `uk_active_slot_visitor` (`active_key`),
        INDEX `idx_slot_id` (`slot_id`),
        INDEX `idx_message_id` (`message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`),
        FOREIGN KEY (`slot_id`) REFERENCES `volunteer_slots`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='志愿响应记录表'");

    // 插入默认管理员 admin/admin123
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT IGNORE INTO `admins` (`username`, `password`) VALUES ('admin', ?)");
    $stmt->execute([$hash]);

    // 插入测试数据
    $testData = [
        ['张大爷', '13800001111', 'help', '楼道灯坏了', '3号楼2单元楼道灯已经坏了一周，晚上出行很不方便，希望能尽快维修。', null, 1],
        ['李阿姨', '13800002222', 'suggest', '建议增加健身器材', '小区广场上没有健身器材，建议物业能增加一些简单的健身设施，方便居民锻炼。', null, 1],
        ['王先生', '13800003333', 'lost', '捡到一只白色小猫', '昨天在小区门口捡到一只白色小猫，有项圈，应该是附近居民养的。联系电话联系我。', null, 1],
        ['赵女士', '13800004444', 'help', '下水道堵塞', '1号楼1单元下水道堵塞严重，污水都漫出来了，影响整栋楼居民生活，急需处理！', null, 1],
        ['孙师傅', '13800005555', 'suggest', '停车位规划建议', '小区停车位紧张，建议物业重新规划停车区域，利用闲置空地增加停车位。', null, 1],
        ['周同学', '13800006666', 'lost', '丢失蓝色书包', '今天下午在小区花园丢失一个蓝色书包，里面有课本和文具，如有拾到请联系我，万分感谢！', null, 1],
    ];

    $stmt = $pdo->prepare("INSERT INTO `messages` (`visitor_id`, `nickname`, `phone`, `type`, `title`, `content`, `image`, `status`, `views`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($testData as $i => $d) {
        // 带志愿时段的演示求助指定固定发起人标识（可用该 cookie/会话体验发起人确认）
        $visitorId = $d[3] === '楼道灯坏了' ? 'demo_owner_zhang' : null;
        $stmt->execute([$visitorId, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], rand(10, 200)]);
    }

    // 志愿调度演示数据：给第一条求助（楼道灯坏了）发布两个服务时段
    $demoMsgId = (int)$pdo->query("SELECT id FROM messages WHERE title = '楼道灯坏了' LIMIT 1")->fetchColumn();
    if ($demoMsgId > 0) {
        $slotStmt = $pdo->prepare("INSERT INTO `volunteer_slots` (`message_id`, `slot_date`, `start_time`, `end_time`, `quota`) VALUES (?, ?, ?, ?, ?)");
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $dayAfter = date('Y-m-d', strtotime('+2 days'));
        $slotStmt->execute([$demoMsgId, $tomorrow, '09:00:00', '11:00:00', 2]);
        $slot1Id = (int)$pdo->lastInsertId();
        $slotStmt->execute([$demoMsgId, $dayAfter, '14:00:00', '16:00:00', 1]);
        $slot2Id = (int)$pdo->lastInsertId();

        // 时段1：名额2 -> 1已确认 + 1待确认（满员）；再加 1 条候补
        $respStmt = $pdo->prepare("INSERT INTO `volunteer_responses` (`slot_id`, `message_id`, `visitor_id`, `nickname`, `phone`, `status`, `decided_at`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $respStmt->execute([$slot1Id, $demoMsgId, 'demo_volunteer_1', '志愿者小陈', '13900001111', 1, date('Y-m-d H:i:s')]);
        $respStmt->execute([$slot1Id, $demoMsgId, 'demo_volunteer_2', '志愿者老刘', '13900002222', 0, null]);
        $respStmt->execute([$slot1Id, $demoMsgId, 'demo_volunteer_3', '志愿者阿梅', null, 2, null]);
        // 时段2：名额1 -> 1已确认（满员）+ 1 候补
        $respStmt->execute([$slot2Id, $demoMsgId, 'demo_volunteer_4', '志愿者大伟', '13900003333', 1, date('Y-m-d H:i:s')]);
        $respStmt->execute([$slot2Id, $demoMsgId, 'demo_volunteer_5', '志愿者小芳', null, 2, null]);
    }

    // 创建上传目录
    if (!is_dir(__DIR__ . '/uploads')) {
        mkdir(__DIR__ . '/uploads', 0755, true);
    }

    echo "<h2>安装成功！</h2>";
    echo "<p>数据库和表已创建完成，测试数据已插入。</p>";
    echo "<p>后台管理账号：<strong>admin</strong> / <strong>admin123</strong></p>";
    echo "<p><a href='index.php'>访问首页</a> | <a href='admin/login.php'>进入后台</a></p>";
    echo "<p style='color:red;'>请删除此安装文件 (install.php) 以确保安全！</p>";

} catch (PDOException $e) {
    die("安装失败: " . $e->getMessage());
}
