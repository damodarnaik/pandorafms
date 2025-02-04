START TRANSACTION;

ALTER TABLE `trecon_task` ADD COLUMN `snmp_skip_non_enabled_ifs` TINYINT UNSIGNED DEFAULT 1;

ALTER TABLE `tlayout` ADD COLUMN `maintenance_mode` TEXT;

ALTER TABLE `tusuario` ADD COLUMN `auth_token_secret` VARCHAR(45) DEFAULT NULL;

ALTER TABLE `tmodule_inventory` ADD COLUMN `script_mode` INT NOT NULL DEFAULT 2;
ALTER TABLE `tmodule_inventory` ADD COLUMN `script_path` VARCHAR(1000) DEFAULT '';

ALTER TABLE `tevent_filter` ADD COLUMN `search_recursive_groups` INT NOT NULL DEFAULT 0;

ALTER TABLE `tcredential_store` MODIFY COLUMN `product` ENUM('CUSTOM', 'AWS', 'AZURE', 'GOOGLE', 'SAP', 'WMI', 'SNMP') DEFAULT 'CUSTOM';

COMMIT;
