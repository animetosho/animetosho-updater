#!/bin/bash

verbose=""
force=""
ids=()
for arg in "$@"; do
    if [[ "$arg" == "-v" ]]; then
        verbose="-v"
    elif [[ "$arg" == "-f" ]]; then
        force="-f"
    else
        ids+=("$arg")
    fi
done

php admin-readd-resolv.php $force ${ids[@]}
for i in "${ids[@]}"; do
	su -c "php cron-adb.php -l $verbose" atscript
done
