<?php
$migration_name = 'Add table for facts collected from target servers';

// Several processes (web requests, cron jobs, sync children) may find this migration pending at the same time
// after an update; the table must not make the slower ones fail.

$this->database->query("
CREATE TABLE IF NOT EXISTS `server_host_facts` (
  `server_id` int(10) unsigned NOT NULL PRIMARY KEY,
  `collected` datetime NOT NULL,
  `facts` mediumtext NOT NULL,
  FOREIGN KEY (`server_id`) REFERENCES `server` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
