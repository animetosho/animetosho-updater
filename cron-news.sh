#!/bin/bash
dir=`dirname "$0"`
sleep 10  #  prioritise some ulqueue getting the semaphores
php "$dir/cron-news1.php" &
sleep 11
php "$dir/cron-news2.php" &
