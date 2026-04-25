#!/bin/bash

/bin/rm -f "/var/atscript/logs/"*"-$(/bin/date '+%Y-%m' -d '-6 month').txt" 2>/dev/null
/bin/rm -f "/var/atscript/logs/log-linkresolv-$(/bin/date '+%Y-%m' -d '-3 month').txt" 2>/dev/null
/bin/rm -f "/var/atscript/logs/info-curl-$(/bin/date '+%Y-%m' -d '-3 month').txt" 2>/dev/null

# remove old dump files
/usr/bin/find /var/atscript/logs/ -maxdepth 1 -type f -mtime +365 -name '*_dump_*.html' -print0 | /usr/bin/xargs --no-run-if-empty -0 /bin/rm -f

# remove stuff we expect to see more frequently
/usr/bin/find /var/atscript/logs/ -maxdepth 1 -type f -mtime +180 \( \
  -name 'aria2_magnet_dump_*.html' \
  -or -name 'feedparser_dump_*.html' \
  -or -name 'releasesrc_torrent_dump_*.html' \
  -or -name 'scrape_dump_*.html' \
  -or -name 'torrent-data_dump_*.html' \
  -or -name 'uploader_invhttpresp_dump_*.html' \
  \) -print0 | /usr/bin/xargs --no-run-if-empty -0 /bin/rm -f
