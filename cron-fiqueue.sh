#!/bin/bash
dir=`dirname "$0"`
php "$dir/cron-fiqueue1.php" &
sleep 2
php "$dir/cron-fiqueue2.php" &
