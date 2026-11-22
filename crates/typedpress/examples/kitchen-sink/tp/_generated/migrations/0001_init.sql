CREATE TABLE `{prefix}tp_registrations` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`eventId` BIGINT UNSIGNED NOT NULL,
	`memberId` BIGINT UNSIGNED NOT NULL,
	`seats` BIGINT DEFAULT 1 NOT NULL,
	`code` VARCHAR(36) NOT NULL,
	`preferences` LONGTEXT,
	`bookedOn` CHAR(10) NOT NULL,
	`updatedAt` CHAR(24) NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `code_unique` (`code`),
	UNIQUE KEY `idx_eventId_memberId` (`eventId`, `memberId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--> statement-breakpoint
CREATE TABLE `{prefix}tp_activity` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`eventId` BIGINT UNSIGNED,
	`message` LONGTEXT NOT NULL,
	`createdAt` CHAR(24) NOT NULL,
	PRIMARY KEY (`id`),
	KEY `createdAt_queried` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--> statement-breakpoint
CREATE TABLE `{prefix}tp__revisions` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`collection` VARCHAR(64) NOT NULL,
	`pk` VARCHAR(64) NOT NULL,
	`at` CHAR(24) NOT NULL,
	`userId` BIGINT,
	`row` LONGTEXT NOT NULL,
	PRIMARY KEY (`id`),
	KEY `row` (`collection`, `pk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
