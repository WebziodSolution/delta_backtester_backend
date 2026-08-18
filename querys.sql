CREATE TABLE `strategys` (`id` INT NOT NULL AUTO_INCREMENT , `name` VARCHAR(255) NOT NULL , `description` VARCHAR(255) NULL , `asset` VARCHAR(100) NULL , `margin_allocation` INT NULL , `leverage` INT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;

ALTER TABLE `strategys` ADD `lot_size` INT NULL AFTER `leverage`;

CREATE TABLE `subscribe_strategys` (`id` INT NOT NULL AUTO_INCREMENT , `user_id` INT NOT NULL , `strategy_id` INT NOT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;

ALTER TABLE `subscribe_strategys` ADD FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT; ALTER TABLE `subscribe_strategys` ADD FOREIGN KEY (`strategy_id`) REFERENCES `strategys`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;


ALTER TABLE `orders_info` ADD `strategy_id` INT NULL AFTER `user_id`;

ALTER TABLE `orders_info` ADD FOREIGN KEY (`strategy_id`) REFERENCES `strategys`(`id`) ON DELETE CASCADE ON UPDATE RESTRICT;

ALTER TABLE `account_info` ADD `current_margin` INT NULL AFTER `api_secret`;

ALTER TABLE `subscribe_strategys` ADD `asset` VARCHAR(100) NOT NULL AFTER `strategy_id`, ADD `margin_allocation` INT NULL AFTER `asset`, ADD `leverage` INT NULL AFTER `margin_allocation`, ADD `lot_size` INT NULL AFTER `leverage`;

ALTER TABLE `strategys`
  DROP `asset`,
  DROP `margin_allocation`,
  DROP `leverage`,
  DROP `lot_size`;

-- OPTION SCALPING STRATEGY SCHEMA UPDATES
ALTER TABLE `orders_info` 
  ADD COLUMN `tp_price` DOUBLE NULL AFTER `broker_fees`,
  ADD COLUMN `sl_price` DOUBLE NULL AFTER `tp_price`,
  ADD COLUMN `trade_action` VARCHAR(20) NULL AFTER `sl_price`;

ALTER TABLE `subscribe_strategys` 
  ADD COLUMN `peak_balance` DOUBLE NULL AFTER `lot_size`;


ALTER TABLE `subscribe_strategys` 
ADD COLUMN `allocated_balance` DOUBLE NULL AFTER `peak_balance`,
ADD COLUMN `current_balance` DOUBLE NULL AFTER `allocated_balance`;
