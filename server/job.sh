#!/bin/bash
#cwd
cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"  # cd current directory

SOURCE_FILE="$2/$1"
TARGET_TIFF="${SOURCE_FILE}.tiff"

#parameters
# $1 - file name
# $2 - file path - absolute directory file path location
# $3 - request id - session number in which the job runs, unique each run (the same for all sessions within given request)
# $4 - session id - session number in which the job runs, unique each run (the same for each script run in a session)

#first, we run a conversion to a pyramidal tiff
./jobStatus.php $SOURCE_FILE $3 "converting"
echo "$3:$4 converting tiff..."

vips tiffsave $SOURCE_FILE $TARGET_TIFF --tile --pyramid --compression=jpeg --Q=80 --tile-width 512 --tile-height 512 --bigtiff
RESULT=$?

# then, get ready for analysis
./jobStatus.php $SOURCE_FILE $3 "ready"

if [ $RESULT -eq 0 ]
then
  echo "$3:$4 DONE"
  exit 0
else
  echo "$3:$4 FAILED"
  exit 42
fi
