#!/bin/bash
dir=`dirname "$0"`
sleep 5  # allow cron-complete to process
php "$dir/cron-ulqueue1.php" &
sleep 2
php "$dir/cron-ulqueue2.php" &
sleep 2
php "$dir/cron-ulqueue3.php" &
sleep 2
php "$dir/cron-ulqueue4.php" &
sleep 2
php "$dir/cron-ulqueue5.php" &
sleep 2
php "$dir/cron-ulqueue6.php" &
#sleep 2
#php "$dir/cron-ulqueue7.php" &
#sleep 2
#php "$dir/cron-ulqueue8.php" &
#sleep 2
#php "$dir/cron-ulqueue9.php" &
