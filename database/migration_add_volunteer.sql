-- 志愿响应调度迁移脚本
-- 执行此 SQL 来添加志愿需求、服务时段与响应调度所需的表结构

USE `community_board`;

-- 留言表增加发布者标识（用于发起人确认响应）
ALTER TABLE `messages`
    ADD COLUMN `publisher_id` VARCHAR(64) DEFAULT NULL COMMENT '发布者访客标识' AFTER `views`;

-- 志愿需求服务时段表
CREATE TABLE IF NOT EXISTS `volunteer_slots` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL COMMENT '所属求助留言ID',
    `start_at` DATETIME NOT NULL COMMENT '服务开始时间',
    `end_at` DATETIME NOT NULL COMMENT '服务结束时间',
    `quota` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '响应名额',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_message_id` (`message_id`),
    INDEX `idx_start_at` (`start_at`),
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='志愿需求服务时段表';

-- 志愿响应记录表
-- status: pending 待发起人确认(不占名额) / confirmed 已确认(占名额) / waitlist 候补 / rejected 已拒绝 / cancelled 已取消
CREATE TABLE IF NOT EXISTS `volunteer_responses` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slot_id` INT UNSIGNED NOT NULL COMMENT '响应的服务时段ID',
    `message_id` INT UNSIGNED NOT NULL COMMENT '所属求助留言ID(冗余便于冲突校验)',
    `visitor_id` VARCHAR(64) NOT NULL COMMENT '志愿者访客标识',
    `volunteer_name` VARCHAR(50) NOT NULL COMMENT '志愿者称呼',
    `volunteer_phone` VARCHAR(20) DEFAULT NULL COMMENT '志愿者联系电话',
    `status` ENUM('pending','confirmed','waitlist','rejected','cancelled') NOT NULL DEFAULT 'pending' COMMENT '响应状态',
    `client_token` VARCHAR(64) DEFAULT NULL COMMENT '客户端幂等令牌(防网络重试重复占位)',
    `decided_at` DATETIME DEFAULT NULL COMMENT '发起人确认/拒绝时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '响应时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_client_token` (`client_token`),
    INDEX `idx_slot_id` (`slot_id`),
    INDEX `idx_message_visitor` (`message_id`, `visitor_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_slot_status` (`slot_id`, `status`),
    FOREIGN KEY (`slot_id`) REFERENCES `volunteer_slots`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='志愿响应记录表';

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'volunteer_%';
-- DESCRIBE volunteer_slots;
-- DESCRIBE volunteer_responses;
