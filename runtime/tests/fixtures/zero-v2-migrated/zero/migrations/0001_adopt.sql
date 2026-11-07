-- zero-adopt {"table":"sf_spc_zero_v2_migrated_taskitems_58c2072c6d","indexes":[{"name":"PRIMARY","unique":true,"columns":["id"]}],"foreignKeys":[],"integerColumns":[{"name":"id","unsigned":true}]}
SELECT `id`, `createdAt`, `updatedAt`, `title`, `done` FROM `sf_spc_zero_v2_migrated_taskitems_58c2072c6d` LIMIT 0;
--> statement-breakpoint
CREATE TABLE `{prefix}zero__revisions` (
	`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	`collection` VARCHAR(64) NOT NULL,
	`pk` VARCHAR(64) NOT NULL,
	`at` CHAR(24) NOT NULL,
	`userId` BIGINT,
	`row` LONGTEXT NOT NULL,
	PRIMARY KEY (`id`),
	KEY `row` (`collection`, `pk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
