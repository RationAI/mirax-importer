#!/bin/bash
#cwd
cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"  # cd current directory
source /var/www/.bashrc

#parameters
# $1 - file name
# $2 - absolute mirax file path
# $3 - biopsy
# $4 - algorithm name
# $5 - algorithm data - serialized JSON
# $6 - session id

./update_event.php "$1" "processing" "$4"

micromamba activate snakemake
snakemake target_vis --config slide_fp="$2" algorithm="$5"
RESULT=$?
micromamba deactivate

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
