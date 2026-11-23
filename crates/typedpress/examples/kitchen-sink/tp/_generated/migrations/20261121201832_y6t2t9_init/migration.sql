CREATE TABLE `{prefix}tp_registrations` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`eventId` BIGINT UNSIGNED NOT NULL,
	`memberId` BIGINT UNSIGNED NOT NULL,
	`seats` BIGINT DEFAULT 1 NOT NULL,
	`code` VARCHAR(36) NOT NULL,
	`preferences` LONGTEXT,
	`bookedOn` CHAR(10) DEFAULT '1970-01-01T00:00:00.000Z' NOT NULL,
	`updatedAt` CHAR(24) DEFAULT '1970-01-01T00:00:00.000Z' NOT NULL,
	`author` BIGINT,
	`createdAt` CHAR(24) DEFAULT '1970-01-01T00:00:00.000Z' NOT NULL,
	`deletedAt` CHAR(24),
	`deletedBy` BIGINT,
	`deletedGroup` VARCHAR(36),
	`_live` TINYINT(1) DEFAULT 1,
	PRIMARY KEY (`id`),
	UNIQUE KEY `code_unique` (`code`, `_live`),
	KEY `deletedAt_idx` (`deletedAt`),
	UNIQUE KEY `idx_eventId_memberId` (`eventId`, `memberId`, `_live`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--> statement-breakpoint
CREATE TABLE `{prefix}tp_activity` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`eventId` BIGINT UNSIGNED,
	`message` LONGTEXT NOT NULL,
	`createdAt` CHAR(24) DEFAULT '1970-01-01T00:00:00.000Z' NOT NULL,
	`author` BIGINT,
	`updatedAt` CHAR(24) DEFAULT '1970-01-01T00:00:00.000Z' NOT NULL,
	`deletedAt` CHAR(24),
	`deletedBy` BIGINT,
	`deletedGroup` VARCHAR(36),
	`_live` TINYINT(1) DEFAULT 1,
	PRIMARY KEY (`id`),
	KEY `deletedAt_idx` (`deletedAt`),
	KEY `createdAt_queried` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--> statement-breakpoint
CREATE TABLE `{prefix}tp__revisions` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`collection` VARCHAR(64) NOT NULL,
	`pk` VARCHAR(191) NOT NULL,
	`version` BIGINT DEFAULT 1 NOT NULL,
	`op` VARCHAR(16) DEFAULT 'update' NOT NULL,
	`at` CHAR(24) NOT NULL,
	`userId` BIGINT,
	`schemaMigration` VARCHAR(191),
	`row` LONGTEXT NOT NULL,
	`changed` LONGTEXT,
	PRIMARY KEY (`id`),
	KEY `row` (`collection`, `pk`),
	UNIQUE KEY `row_version` (`collection`, `pk`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
