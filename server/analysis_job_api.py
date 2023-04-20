#!/usr/bin/python3
from kubernetes import config, client
import os
import yaml
import sys
import re

# get current namespace
namespace = open("/var/run/secrets/kubernetes.io/serviceaccount/namespace").read()

if len(sys.argv) < 5:
   print("usage: run.py run|status slide algorithm serviceAPI")
   exit(1)

command=sys.argv[1]
slide=sys.argv[2]
algorithm=sys.argv[3]
service=sys.argv[4]

# data mount point in the job container, irrelevant of what importer uses
mntpath='/mnt/data'

pvc = 'pvc-xopat-demo'

# init config
config.load_incluster_config()

def create_job(name, slide, algorithm, service):
    job_template = """\
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
        image: cerit.io/xopat/histopat:v0.1
        imagePullPolicy: Always
        command: ['snakemake', 'target_vis', '--cores', '4', '--config', 'slide_fp={slide}', '--config', 'algorithm={algorithm}', '--config', 'endpoint={service}']
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
           nvidia.com/gpu: 1
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
    job = job_template.format(name=name, pvc=pvc, slide=slide)
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

name = slide
name = re.sub("(\/)|(_)|(.mrxs)", "", name)

if command == 'run':
   create_job(name, f"{mntpath}/{slide}", algorithm, service)
   exit(0)

if command == 'status':
   status_job(name)
   exit(0)

print("Unknown command: "+command)
exit(1)