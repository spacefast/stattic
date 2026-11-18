ALTER TABLE `{prefix}tp_items` ADD INDEX `expiresOn_queried` (`expiresOn`);
--> statement-breakpoint
ALTER TABLE `{prefix}tp_items` ADD INDEX `quantity_queried` (`quantity`);
