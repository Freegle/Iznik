#!/bin/bash
# Prevent concurrent runs
exec 9>/tmp/fsm-cron.lock
flock -n 9 || exit 0

cd /home/edward/FreegleDockerWSL/monitor-fsm
npm run run-once >> /tmp/fsm-cron.log 2>&1
