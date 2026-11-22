-- typedpress-check {"check":"rename","name":"activity.message","table":"{prefix}tp_activity","column":"message","to":"summary","definition":"`summary` LONGTEXT NOT NULL","adopted":false}
ALTER TABLE `{prefix}tp_activity` RENAME COLUMN `message` TO `summary`;
