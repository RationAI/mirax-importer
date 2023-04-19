#!/bin/bash
#cwd
cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"  # cd current directory

SOURCE_FILE="$3/$1"
TARGET_TIFF="$3/$2"

#parameters
# $1 - file name
# $2 - target tiff name
# $3 - file path - absolute directory file path location
# $4 - biopsy
# $5 - session id - session number in which the job runs, unique each run (the same for each script run in a session)
# $6 - year
# $7 - force create - set any true-ish value to force conversion

#first, we run a conversion to a pyramidal tiff

if [ ! -z $7 ] || [ ! -f "$TARGET_TIFF" ]; then
  echo "$4:$6 converting tiff..."
  vips tiffsave "$SOURCE_FILE" "$TARGET_TIFF" --tile --pyramid --compression=jpeg --Q=60 --tile-width 512 --tile-height 512 --bigtiff
  RESULT=$?
else
  echo "$4:$6 conversion skipped!"
  RESULT=0
fi

#extract label image, ignore failure
if [ ! -z $7 ] || [ ! -f "$3/m_label.png" ]; then
  echo "$4:$6 getting label..."
  python3 mirax_extract_meta/label_extractor.py "$SOURCE_FILE" "$3/m_label.png"
else
  echo "$4:$6 label extraction skipped!"
fi

if [ $RESULT -eq 0 ]; then
  ./update_event.php "$2" "tiff-generated" "mirax-importer"
  echo "$4:$6 DONE"
else
  ./update_event.php "$2" "tiff-failed" "mirax-importer"
  echo "$4:$6 FAILED - ERR $RESULT"
fi
exit $RESULT
