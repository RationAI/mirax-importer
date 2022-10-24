#!/bin/bash

SOURCE_FILE="$2/$1"
TARGET_TIFF="${SOURCE_FILE}.tiff"

#parameters
# $1 - file name
# $2 - file path - absolute directory file path location
# $3 - request id - session number in which the job runs, unique each run (the same for all sessions within given request)
# $4 - session id - session number in which the job runs, unique each run (the same for each script run in a session)
# $5 - server URL

echo "$3:$4 converting tiff..."

#first, we run a conversion to a pyramidal tiff
vips tiffsave SOURCE_FILE TARGET_TIFF --tile --pyramid --compression=jpeg --Q=80 --tile-width 512 --tile-height 512 --bigtiff

echo "$3:$4 running inference..."

# then, run a neural network job
#todo
sleep 60

# finally, log this job as finished
#wget --post-data "requestId=$3&sessionId=$4&status=success" $5
echo "$3:$4 DONE"
