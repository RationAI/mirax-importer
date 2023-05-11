#!/usr/bin/python3
from kubernetes import config, client
from pathlib import Path
from datetime import datetime
import os
import yaml
import sys
import re

# get current namespace
namespace = open("/var/run/secrets/kubernetes.io/serviceaccount/namespace").read()

if len(sys.argv) < 5:
   print("usage: analysis_job_api.py run|status slide algorithm serviceAPIs [headers]")
   exit(1)

command=sys.argv[1]
slide=sys.argv[2]
algorithm=sys.argv[3]
service=sys.argv[4]
headers=sys.argv[5] if len(sys.argv) > 4 else '{}'

# data mount point in the job container, irrelevant of what importer uses
mntpath='/mnt/data'

slide_path=Path(slide).parent
log_name=datetime.now().strftime("%Y_%m_%d")
log_path=f"{mntpath}/{slide_path}/logs"
log_file=f"{log_path}/{log_name}.log"


pvc = os.environ.get('XO_KUBE_PVC', 'error-XO_KUBE_PVC-not-set')

# init config
config.load_incluster_config()

def create_job(name, slide, algorithm, service, headers, log_path, log_file):
    job = f"""\
apiVersion: batch/v1
kind: Job
metadata:
  name: {name}
spec:
  backoffLimit: 0
  ttlSecondsAfterFinished: 300
  template:
    spec:
      restartPolicy: Never
      securityContext:
        runAsNonRoot: true
        seccompProfile:
          type: RuntimeDefault
      containers:
      - name: snakemake-job
        image: cerit.io/xopat/histopat:v1.1
        imagePullPolicy: Always
        env:
        - name: HTTPS_PROXY
          value: "{os.environ.get('HTTPS_PROXY', 'http://proxy.ics.muni.cz:3128')}"
        command: ["bash"]
        args: ["-c", "mkdir -p {log_path} && snakemake -F target_vis --cores 4 --config slide_fp='{slide}' algorithm='{algorithm}' endpoint='{service}' headers='{headers}' >> '{log_file}' 2>&1"]
        securityContext:
          runAsUser: 33
          runAsGroup: 33
          allowPrivilegeEscalation: false
          capabilities:
            drop:
              - ALL
          privileged: false
        resources:
          limits:
           cpu: 4
           memory: '128Gi'
        volumeMounts:
        - mountPath: /mnt/data
          name: data
        - mountPath: /dev/shm
          name: dshm
      volumes:
      - name: data
        persistentVolumeClaim:
          claimName: {pvc}
      - name: dshm
        emptyDir:
          medium: Memory
          sizeLimit: 32Gi
"""
    batch_api = client.BatchV1Api()
    batch_api.create_namespaced_job(namespace, body=yaml.safe_load(job))

def status_job(name):
   batch_api = client.BatchV1Api()
   try:
     status = batch_api.read_namespaced_job_status(name, namespace)
     print(status.status)
   except:
     print("Job not found")
     pass

name = re.sub("(\/)|(_)|(.mrxs)|(.tiff?)", "", slide.lower())

if command == 'run':
   create_job(name, f"{mntpath}/{slide}", algorithm.replace('"', '\\"'), service, headers.replace('"', '\\"'), log_path, log_file)
   exit(0)

if command == 'status':
   status_job(name)
   exit(0)

print("Unknown command: "+command)
exit(1)
