#!/bin/bash

# $1 -input, $2 -output, $3 -thumbdims (0 for none), $4 -thumbnail

dir=`dirname "$0"`
pdir="$dir/../3rdparty"
to="timeout -sINT"

# minify source image
ext=`echo ${1##*.} | tr '[A-Z]' '[a-z]'`
test "$ext" = "jpeg" && ext="jpg"
test "$ext" = "jpe" && ext="jpg"
test "$ext" = "tiff" && ext="tif"
if [ "$ext" = "png" ]; then
	cp "$1" "$2"
	$to -k120 90 "advpng" -z -q -2 "$2"
elif [ "$ext" = "bmp" ]; then
	if [ -x "$pdir/pngout" ]; then
		$to -k210 180 "$pdir/pngout" -q "$1" "$2"
	else
		$to -k120 90 ffmpeg -nostdin -loglevel error -v 0 -i "$1" -vcodec png -f image2 "$2" 2>/dev/null
		$to -k150 120  "advpng" -z -q -2 "$2"
	fi
elif [ "$ext" = "jpg" ]; then
	$to -k120 90 "$pdir/jpegtran" "$1" >"$2" 2>/dev/null
else
	cp "$1" "$2"
fi

# generate thumbnail
if [ "$3" != "0" ]; then
	$to -k150 120 ffmpeg -nostdin -loglevel error -v 0 -i "$1" -vcodec bmp -f image2 -s "$3" - 2>/dev/null | $to -k150 120 "$pdir/cjpeg" -dc-scan-opt 2 -quality 75 >"$4"
	chmod 0666 "$4"
fi

if [ ! -s "$2" ]; then
	unlink "$2" 2>/dev/null
	mv "$1" "$2"
	chmod 0666 "$2"
else
	unlink "$1" 2>/dev/null # shouldn't fail as the screen dump script makes it writable
	chmod 0666 "$2"
fi
