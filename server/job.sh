#!/bin/bash

#parameters
# $1 - file name
# $2 - file path - absolute directory file path location
# $3 - request id - session number in which the job runs, unique each run (the same for all sessions within given request)
# $4 - session id - session number in which the job runs, unique each run (the same for each script run in a session)
# $5 - server URL

SOURCE_FILE="$2/$1"
TARGET_TIFF="${SOURCE_FILE}.tiff"

echo $1
echo $2
echo $3
echo $4
echo $5
echo "----"
echo $SOURCE_FILE
echo $TARGET_TIFF

sleep 60


#first, we run a conversion to a pyramidal tiff
#vips tiffsave SOURCE_FILE TRAGET_TIFF --tile --pyramid --compression=jpeg --Q=80 --tile-width 512 --tile-height 512 --bigtiff

# then, run a neural network job
#todo

# finally, log this job as finished
#wget --post-data "requestId=$3&sessionId=$4&status=success" $5
echo "DONE"
