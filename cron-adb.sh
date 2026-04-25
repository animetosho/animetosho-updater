#!/bin/bash
dir=`dirname "$0"`
sleep 5  # allow cron-complete to process
php "$dir/cron-adb.php"
#sleep 20
#php "$dir/cron-adb.php"
