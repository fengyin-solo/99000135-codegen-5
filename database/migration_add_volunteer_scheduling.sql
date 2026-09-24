-- 志愿响应调度迁移脚本
-- 执行此 SQL 来添加志愿响应调度功能所需的表结构
-- 功能：居民求助可按服务时段发布志愿名额，志愿者响应后由发起人确认，满员自动候补，
--       取消立即释放名额并自动递补。

USE `community_board`;

-- 留言表增加访客标识，用于标识求助发起人（发起人确认/拒绝志愿者）
ALTER TABLE `messages`
    ADD COLUMN `visitor_id` VARCHAR(64) DEFAULT NULL COMMENT '发起人访客标识' AFTER `id`,
    ADD INDEX `idx_visitor_id` (`visitor_id`);

-- 志愿服务时段表（一条求助可发布多个服务时段，每个时段独立名额）
CREATE TABLE IF NOT EXISTS `volunteer_slots` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='志愿服务时段表';

-- 志愿响应记录表
-- status: 0待确认(占响应不占名额) 1已确认(占用名额) 2候补中 3发起人未采纳 4志愿者已取消
-- active_key 为生成列：仅 待确认/已确认/候补 三种活跃状态生成唯一键，
-- 同一志愿者对同一时段只能有一条活跃记录（取消/未采纳后可重新响应），
-- 同时保证网络中断重试不会产生重复占位。NULL 值不参与唯一约束。
CREATE TABLE IF NOT EXISTS `volunteer_responses` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='志愿响应记录表';

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'volunteer_%';
-- DESCRIBE volunteer_slots;
-- DESCRIBE volunteer_responses;
