CREATE TABLE `{prefix}tp_locations` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`name` LONGTEXT NOT NULL,
	`kind` VARCHAR(191) DEFAULT 'pantry' NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--> statement-breakpoint
CREATE TABLE `{prefix}tp_items` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`name` LONGTEXT NOT NULL,
	`slug` VARCHAR(191) NOT NULL,
	`quantity` BIGINT DEFAULT 1 NOT NULL,
	`unit` VARCHAR(191) DEFAULT 'pcs' NOT NULL,
	`lowStockAt` BIGINT DEFAULT 1 NOT NULL,
	`expiresOn` CHAR(10),
	`opened` TINYINT(1) DEFAULT 0 NOT NULL,
	`locationId` BIGINT UNSIGNED,
	`tags` LONGTEXT NOT NULL,
	`createdAt` CHAR(24) NOT NULL,
	`updatedAt` CHAR(24) NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `slug_unique` (`slug`),
	CONSTRAINT `tp_items_locationId_fk` FOREIGN KEY (`locationId`) REFERENCES `{prefix}tp_locations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
--> statement-breakpoint
CREATE TABLE `{prefix}tp_shopping_list` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`itemId` BIGINT UNSIGNED,
	`name` LONGTEXT NOT NULL,
	`done` TINYINT(1) DEFAULT 0 NOT NULL,
	`createdAt` CHAR(24) NOT NULL,
	`updatedAt` CHAR(24) NOT NULL,
	PRIMARY KEY (`id`),
	UNIQUE KEY `itemId_unique` (`itemId`),
	CONSTRAINT `tp_shopping_list_itemId_fk` FOREIGN KEY (`itemId`) REFERENCES `{prefix}tp_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
