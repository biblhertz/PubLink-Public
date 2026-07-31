#! /bin/bash
set -m
exec -a worker /usr/local/bin/php /var/www/job_queue/worker.php &
