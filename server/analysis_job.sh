#!/bin/bash
#cwd
cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"  # cd current directory

#parameters
# $1 - file name
# $2 - absolute file path
# $3 - biopsy
# $4 - algorithm name
# $5 - session id

./update_event.php "$1" "processing" "$4"
SOURCE_FILE=$2

c_w_d=${PWD}
#cd /mnt/data/visualization-public-demo/histopat
#snakemake target_vis --config slide_fp="${SOURCE_FILE}"
#RESULT=$?
sleep 20
RESULT=0
cd ${c_w_d}

if [ $RESULT -eq 0 ]
then
  # finally, log this job as finished, php runs update on database logs
  ./update_event.php "$1" "processing-finished" "$4"
  echo "DONE"
  exit 0
else
  ./update_event.php "$1" "failed" "$4"
  echo "FAILED"
  exit $RESULT
fi
