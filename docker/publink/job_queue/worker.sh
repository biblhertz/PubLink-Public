#! /bin/bash
set -m
#/usr/local/bin/php /var/www/job_queue/worker.php &
exec -a job_queue /usr/local/bin/php /var/www/job_queue/worker.php &