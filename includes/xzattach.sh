#!/bin/bash

# 1: input, 2: output, 3: xz settings

dir=`dirname "$0"`
OPENSSL=openssl
if [ -f "$dir/../3rdparty/openssl" ]; then
	OPENSSL="$dir/../3rdparty/openssl"
fi

cat "$1" | tee >(xz --compress --keep --format xz --check=crc32 --threads 1 $3 --stdout - >"$2") | "$OPENSSL" sha1
