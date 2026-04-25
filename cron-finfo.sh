#!/bin/bash
dir=`dirname "$0"`
sleep 5  # allow cron-complete to process
php "$dir/cron-finfo1.php" &
sleep 2
php "$dir/cron-finfo2.php" &
