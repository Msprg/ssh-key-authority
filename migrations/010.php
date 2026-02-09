<?php
$migration_name = 'Add per-server history username environment sync settings';

$this->database->query("
ALTER TABLE `server`
ADD `history_username_env_mode` enum('inherit', 'enabled', 'disabled') NOT NULL DEFAULT 'inherit',
ADD `history_username_env_format` varchar(255) NULL;
");
