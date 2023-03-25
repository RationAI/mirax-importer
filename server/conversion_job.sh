#!/bin/bash
#cwd
cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"  # cd current directory

SOURCE_TIFF="$1.tiff"

SOURCE_FILE="$2/$1"
TARGET_TIFF="$2/$SOURCE_TIFF"

#parameters
# $1 - file name
# $2 - file path - absolute directory file path location
# $3 - biopsy
# $5 - year
# $4 - session id - session number in which the job runs, unique each run (the same for each script run in a session)

#first, we run a conversion to a pyramidal tiff
echo "$3:$5 converting tiff..."

vips tiffsave "$SOURCE_FILE" "$TARGET_TIFF" --tile --pyramid --compression=jpeg --Q=60 --tile-width 512 --tile-height 512 --bigtiff
RESULT=$?

#extract label image, ignore failure
python3 mirax_extract_meta/label_extractor.py "$SOURCE_FILE" "$2/m_label.png"

# then, get ready for analysis

if [ $RESULT -eq 0 ]
then
  ./update_event.php "$SOURCE_TIFF" "tiff-generated" "mirax-importer"
  echo "$3:$5 DONE"
else
  ./update_event.php "$SOURCE_TIFF" "tiff-failed" "mirax-importer"
  echo "$3:$5 FAILED - CODE $RESULT"
fi
exit $RESULT
