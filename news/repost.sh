#!/bin/bash
dir=`dirname "$0"`
basedir="$dir/.."
dumpdir="/atdata/nntpdump"

lockfile=/var/tmp/news-repost.lock
if ( set -o noclobber; echo "$$" > "$lockfile") 2> /dev/null; then
trap '/usr/bin/unlink "$lockfile"; exit 1' INT TERM EXIT



# repost
DATE=`date +%Y-%m`
/usr/bin/find "$dumpdir/" -type f -mtime +1 -print0 | /usr/bin/xargs --no-run-if-empty -0 /usr/bin/nodejs "$basedir/3rdparty/nyuu/bin/nyuu" -C "$dir/nyuu-repost.json" 2>>"$basedir/logs/log-news-repost-$DATE.txt"

# move out those that just fail after 5 tries
/usr/bin/find "$dumpdir/" -type f -mtime +5 -print0 | /usr/bin/xargs --no-run-if-empty -0 mv -t "$dumpdir-fail/"



unlink "$lockfile"
trap - INT TERM EXIT

else
  exit 1
fi
