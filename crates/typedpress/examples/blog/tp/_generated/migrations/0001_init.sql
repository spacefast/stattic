CREATE TABLE `{prefix}tp_comments` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`postId` BIGINT UNSIGNED NOT NULL,
	`name` VARCHAR(60) NOT NULL,
	`body` LONGTEXT NOT NULL,
	`approved` TINYINT(1) DEFAULT 0 NOT NULL,
	`createdAt` CHAR(24) NOT NULL,
	PRIMARY KEY (`id`)
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
