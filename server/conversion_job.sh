#!/bin/bash
#cwd
cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"  # cd current directory

if [ $# -lt 5 ]; then
  echo 1>&2 "$0: usage mirax_file tiff_name directory event_name server_url [curl_header] [is_force]"
  exit 2
fi

# parameters
# $1 - file name
# $2 - target tiff name
# $3 - path - absolute directory file path location
# $4 - event name
# $5 - event URL API - record event
# $6 - basic_auth - user:pwd or not set
# $7 - force create - set any true-ish value to force conversion

PREFIX="convert-$1"
SOURCE_FILE="$3/$1"
TARGET_TIFF="$3/$2"

# log formatting does not really work well
#exec > >(trap "" INT TERM; sed "s|^|I:$PREFIX: |")
#exec 2> >(trap "" INT TERM; sed "s|^|E:$PREFIX: |" >&2)

if [ ! -z $6 ]; then
  BASIC="-u $6"
else
  BASIC=""
fi

#first, we run a conversion to a pyramidal tiff

if [ ! -z $7 ] || [ ! -f "$TARGET_TIFF" ]; then
  echo "$PREFIX: tiff..."
  vips tiffsave "$SOURCE_FILE" "$TARGET_TIFF" --tile --pyramid --compression=jpeg --Q=60 --tile-width 512 --tile-height 512 --bigtiff
  RESULT=$?
else
  echo "$PREFIX: vips conversion skipped!"
  RESULT=0
fi

#extract label image, ignore failure
if [ ! -z $7 ] || [ ! -f "$3/label.png" ]; then
  echo "$PREFIX: label..."
  python3 mirax_extract_meta/label_extractor.py "$SOURCE_FILE" "$3/label.png"
else
  echo "$PREFIX: label extraction skipped!"
fi

#curl does not use proxy as the script performs requests within itself (e.g. in a kubernetes cloud)
if [ $RESULT -eq 0 ]; then
  curl --noproxy '*' -s -X POST $BASIC -H "Content-Type: application/json" -d "{\"command\": \"algorithmEvent\", \"fileName\": \"$2\", \"event\": \"$4\", \"payload\": \"success\"}" "$5"
else
  curl --noproxy '*' -s -X POST $BASIC -H "Content-Type: application/json" -d "{\"command\": \"algorithmEvent\", \"fileName\": \"$2\", \"event\": \"$4\", \"payload\": \"error\"}" "$5"
  echo "$PREFIX: Failed: Exit $RESULT"
fi
exit $RESULT
