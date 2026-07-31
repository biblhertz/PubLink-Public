-- Adds the removeManifest.php handler to intranet_scripts.
--
-- This row was added to docker/mysql/bibliotheca.sql in commit 36162de
-- (2026-05-29), 16 days after xmlToManifest.php and publishManifest.php
-- were added in 7c7ec45 (2026-05-13). bibliotheca.sql is only applied by
-- MySQL on first container initialization (docker-entrypoint-initdb.d), so
-- any install that was already running before 2026-05-29 will not have
-- picked up this row automatically and must have it applied manually here.
--
-- Without this row, Bibliotheca_Intranet_Page defaults removeManifest.php
-- to user_group_id 21 (very restrictive) instead of 1, which blocks normal
-- users and causes the handler to return an HTML error page instead of
-- JSON when called via fetch() from article.html's manifest tab.

INSERT IGNORE INTO `intranet_scripts` (`id`, `name`, `user_group_id`)
VALUES (64, 'removeManifest.php', 1);
