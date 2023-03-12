#!/bin/bash
#cwd
cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"  # cd current directory

SOURCE_FILE=$1
./update_event.php "${SOURCE_FILE}" $2 "processing"
echo "$2:$3 START PROCESS"

#parameters
# $1 - absolute file path
# $2 - request id - session number in which the job runs, unique each run (the same for all sessions within given request)
# $3 - session id - session number in which the job runs, unique each run (the same for each script run in a session)

c_w_d=${PWD}
cd /mnt/data/visualization-public-demo/histopat
snakemake target_vis --config slide_fp="${SOURCE_FILE}"
RESULT=$?
cd ${c_w_d}

if [ $RESULT -eq 0 ]
then
  # finally, log this job as finished, php runs update on database logs
  ./update_event.php "${SOURCE_FILE}" $2 "finished"
  echo "$2:$3 DONE"
  exit 0
else
  ./update_event.php "${SOURCE_FILE}" $2 "processing-failed"
  echo "$2:$3 FAILED"
  exit 42
fi
