#!/usr/bin/python3
from kubernetes import config, client
import yaml
import sys
import re

# get current namespace
namespace = open("/var/run/secrets/kubernetes.io/serviceaccount/namespace").read()

if len(sys.argv) < 5:
   print("usage: importer_job.py run|status identifier command logfile")
   exit(1)

command=sys.argv[1]
service_id=sys.argv[2]
to_execute=sys.argv[3]
logfile=sys.argv[4]

pvc = 'pvc-xopat-demo'

# init config
config.load_incluster_config()

def create_job(name, command, logfile):
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
      - name: importer-job
        image: cerit.io/xopat/mirax-importer:php8.1
        imagePullPolicy: Always
        env:
        - name: XO_DB_DRIVER
          value: pgsql
        - name: XO_DB_HOST
          value: xopat-postgresdb-rw
        - name: XO_DB_PORT
          value: '5432'
        - name: XO_DB_NAME
          value: postgresdb
        - name: XO_DB_USER
          value: browser
        - name: XO_DB_PASS
          valueFrom:
            secretKeyRef:
              key: password
              name: xopat-postgresdb-app
        - name: XO_MIRAX_SERVER_URL
          value: https://mirax-xopat.dyn.cloud.e-infra.cz/importer/server
        command: ['bash']
        args:
        - -c
        - /var/www/html/importer/server/kubernetes/fetch_git_mirax_importer.sh >> "{logfile}" 2>&1 \
        - && {command} >> "{logfile}" 2>&1
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
           cpu: 1
           memory: '4Gi'
        volumeMounts:
          - mountPath: /var/www/data
            name: data
      volumes:
      - name: data
        persistentVolumeClaim:
          claimName: {pvc}
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

name = re.sub("(\/)|(_)|(.mrxs)|(.tiff?)", "", service_id.lower())

if command == 'run':
   create_job(name, to_execute, logfile)
   exit(0)

if command == 'status':
   status_job(name)
   exit(0)

print("Unknown command: "+command)
exit(1)
