#!/usr/bin/env node
/**
 * Yesterday Backup Management API (Node.js)
 *
 * Provides endpoints for:
 * - Listing available backups from GCS
 * - Starting backup restoration with progress tracking
 * - Monitoring restoration progress
 */

const express = require('express');
const { exec, spawn } = require('child_process');
const fs = require('fs');
const fsPromises = require('fs').promises;
const path = require('path');
const { promisify } = require('util');

const execAsync = promisify(exec);

const app = express();
const PORT = process.env.PORT || 8082;

// Global state for tracking restoration progress
const restorationJobs = {};
/*
Structure:
{
  "20251031": {
    status: "downloading|extracting|preparing|importing|completed|failed",
    progress: 0-100,
    message: "Current status message",
    started: timestamp,
    completed: timestamp or null,
    error: error message or null
  }
}
*/

app.use(express.json());

// Helper: Parse backup date from filename
function parseBackupDate(filename) {
  const match = filename.match(/iznik-(\d{4})-(\d{2})-(\d{2})/);
  if (match) {
    return `${match[1]}${match[2]}${match[3]}`;
  }
  return null;
}

// Helper: Format bytes to human readable
function formatSize(bytes) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let size = bytes;
  let unitIndex = 0;

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex++;
  }

  return `${size.toFixed(1)} ${units[unitIndex]}`;
}

// Helper: Check if backup is currently loaded
async function isBackupLoaded(backupDate) {
  try {
    const { stdout } = await execAsync(`docker ps --filter "name=freegle-db" --format "{{.Names}}"`);
    // For single-db model, we check the current-backup.json file instead
    const stateFile = '/var/www/FreegleDocker/yesterday/data/current-backup.json';
    try {
      const data = await fsPromises.readFile(stateFile, 'utf8');
      const state = JSON.parse(data);
      return state.date === backupDate;
    } catch {
      return false;
    }
  } catch {
    return false;
  }
}

// GET /api/backups - List all available backups
app.get('/api/backups', async (req, res) => {
  try {
    const bucket = process.env.BACKUP_BUCKET || 'gs://freegle_backup_uk';

    // Get list of backups with size and date
    const { stdout } = await execAsync(`gsutil ls -l "${bucket}/iznik-*.xbstream"`);

    // Dates that have an LVM thin snapshot are instantly switchable (~1 min)
    // rather than a full restore (~1-2h). The host writes this manifest after
    // each prime/refresh; absent file => no LVM, everything is a full restore.
    let instantDates = [];
    try {
      instantDates = (JSON.parse(require('fs').readFileSync('/data/lvm-snapshots.json', 'utf8')).dates) || [];
    } catch (e) { /* no manifest yet */ }

    const backups = [];
    const lines = stdout.trim().split('\n');

    for (const line of lines) {
      if (line.includes('TOTAL:') || !line.trim()) continue;

      const parts = line.trim().split(/\s+/);
      if (parts.length >= 3) {
        const size = parseInt(parts[0]);
        const dateStr = parts[1];
        const url = parts[2];
        const filename = path.basename(url);

        const backupDate = parseBackupDate(filename);
        if (backupDate) {
          backups.push({
            date: backupDate,
            filename: filename,
            url: url,
            size: size,
            size_human: formatSize(size),
            timestamp: dateStr,
            loaded: await isBackupLoaded(backupDate),
            instant: instantDates.includes(backupDate)
          });
        }
      }
    }

    // Sort by date descending (most recent first)
    backups.sort((a, b) => b.date.localeCompare(a.date));

    res.json({
      backups: backups,
      total: backups.length
    });

  } catch (error) {
    console.error('Error listing backups:', error);
    res.status(500).json({ error: `Failed to list backups: ${error.message}` });
  }
});

// Helper: Sync in-memory jobs with status file (clean up completed jobs)
async function syncJobsWithStatusFile() {
  const statusFile = '/data/restore-status.json';

  try {
    const statusData = await fsPromises.readFile(statusFile, 'utf8');
    const status = JSON.parse(statusData);

    // If status file shows completed or failed, update in-memory job
    if (status.backupDate && restorationJobs[status.backupDate]) {
      if (status.status === 'completed' || status.status === 'failed') {
        restorationJobs[status.backupDate].status = status.status;
        restorationJobs[status.backupDate].completed = status.timestamp;
        console.log(`Synced job ${status.backupDate} to status: ${status.status}`);
      }
    }
  } catch (e) {
    // No status file or error reading it - nothing to sync
  }
}

// Helper: Check if any restore is currently in progress
async function isRestoreInProgress() {
  const statusFile = '/data/restore-status.json';
  const triggerFile = '/data/restore-trigger';

  // First, sync in-memory jobs with status file
  await syncJobsWithStatusFile();

  // Check if trigger file exists (restore queued)
  try {
    const queuedBackupDate = (await fsPromises.readFile(triggerFile, 'utf8')).trim();
    return { inProgress: true, backupDate: queuedBackupDate, status: 'queued' };
  } catch (e) {
    // No trigger file
  }

  // Check status file for active restore
  try {
    const statusData = await fsPromises.readFile(statusFile, 'utf8');
    const status = JSON.parse(statusData);

    // Check if status is recent (within last 10 minutes) and still running
    const statusAge = Date.now() - new Date(status.timestamp).getTime();
    const isRecent = statusAge < 10 * 60 * 1000;

    if (isRecent && status.status !== 'completed' && status.status !== 'failed') {
      return { inProgress: true, backupDate: status.backupDate, status: status.status };
    }
  } catch (e) {
    // No status file or error reading it
  }

  return { inProgress: false };
}

// POST /api/backups/:backupDate/load - Start loading a backup
app.post('/api/backups/:backupDate/load', async (req, res) => {
  const { backupDate } = req.params;

  // Check if any restore is already in progress
  const restoreStatus = await isRestoreInProgress();
  if (restoreStatus.inProgress) {
    return res.status(409).json({
      error: `Backup ${restoreStatus.backupDate} is already being loaded (status: ${restoreStatus.status})`,
      currentBackup: restoreStatus.backupDate,
      status: restoreStatus.status
    });
  }

  // Also check in-memory jobs (in case status file hasn't been written yet)
  for (const [jobDate, job] of Object.entries(restorationJobs)) {
    if (job.status !== 'completed' && job.status !== 'failed') {
      return res.status(409).json({
        error: `Backup ${jobDate} is already being loaded (status: ${job.status})`,
        currentBackup: jobDate,
        status: job.status,
        progress: job.progress
      });
    }
  }

  // Check if already loaded
  if (await isBackupLoaded(backupDate)) {
    return res.status(409).json({
      error: 'Backup is already loaded',
      status: 'running'
    });
  }

  // Initialize job tracking
  restorationJobs[backupDate] = {
    status: 'starting',
    progress: 0,
    message: 'Initializing restoration...',
    started: new Date().toISOString(),
    completed: null,
    error: null
  };

  // Start restoration in background
  runRestoration(backupDate);

  res.json({
    message: `Started loading backup ${backupDate}`,
    status: 'starting'
  });
});

// Trigger restoration via host-side monitor (writes trigger file)
function runRestoration(backupDate) {
  const triggerFile = '/data/restore-trigger';

  // Write backup date to trigger file
  // The host-side systemd service monitors this file and runs the restore
  require('fs').writeFileSync(triggerFile, backupDate);

  console.log(`Restore trigger written for backup ${backupDate}`);
  console.log('Note: This container will shut down during the restore process');
  console.log('The host-side restore-monitor service will handle the actual restoration');
}

// GET /api/backups/:backupDate/progress - Get restoration progress
app.get('/api/backups/:backupDate/progress', (req, res) => {
  const { backupDate } = req.params;

  if (!restorationJobs[backupDate]) {
    return res.status(404).json({
      error: 'No restoration job found for this backup'
    });
  }

  res.json(restorationJobs[backupDate]);
});

// GET /api/current-backup - Get currently loaded backup info
app.get('/api/current-backup', async (req, res) => {
  const stateFile = '/data/current-backup.json';

  try {
    const data = await fsPromises.readFile(stateFile, 'utf8');
    const state = JSON.parse(data);
    res.json(state);
  } catch (error) {
    if (error.code === 'ENOENT') {
      res.json({
        date: null,
        message: 'No backup currently loaded'
      });
    } else {
      res.status(500).json({ error: error.message });
    }
  }
});

// GET /api/system-status - Get Docker container status
app.get('/api/system-status', async (req, res) => {
  try {
    const { stdout } = await execAsync('docker ps -a --format "{{.Names}}|{{.State}}|{{.Status}}" --filter "label=com.docker.compose.project=freegledocker"');

    const containers = stdout.trim().split('\n')
      .filter(line => line)
      .map(line => {
        const [name, state, status] = line.split('|');
        let health = 'unknown';
        if (status.includes('healthy')) health = 'healthy';
        else if (status.includes('unhealthy')) health = 'unhealthy';
        else if (status.includes('starting')) health = 'starting';

        return { name, state, health, status };
      });

    res.json({ containers });
  } catch (err) {
    console.error('Error getting container status:', err);
    res.status(500).json({ error: 'Failed to get container status', containers: [] });
  }
});

// GET /api/whitelisted-ips - Get list of whitelisted IPs
app.get('/api/whitelisted-ips', async (req, res) => {
  const whitelistFile = '/data/2fa/ip-whitelist.json';

  try {
    const data = await fsPromises.readFile(whitelistFile, 'utf8');
    const whitelist = JSON.parse(data);
    const now = Date.now();

    // Filter out expired entries and format for display
    const activeIps = Object.entries(whitelist)
      .filter(([ip, data]) => data.expires > now)
      .map(([ip, data]) => ({
        ip,
        expires: new Date(data.expires).toISOString(),
        expiresIn: Math.floor((data.expires - now) / 1000 / 60 / 60) + ' hours',
        username: data.username || 'unknown'
      }));

    res.json({ ips: activeIps, total: activeIps.length });
  } catch (error) {
    if (error.code === 'ENOENT') {
      res.json({ ips: [], total: 0 });
    } else {
      res.status(500).json({ error: error.message, ips: [], total: 0 });
    }
  }
});

// Typical phase durations in minutes (based on ~42GB backup on n2-standard-4)
const PHASE_DURATIONS = {
  starting: 2,
  stopping: 1,
  extracting: 20,
  decompressing: 90,
  preparing: 5,
  restoring_loki: 35,
  building: 10,
  starting_infra: 5,
  starting_apps: 3
};
const PHASE_ORDER = Object.keys(PHASE_DURATIONS);

function estimateETA(status) {
  const phaseIndex = PHASE_ORDER.indexOf(status.status);
  if (phaseIndex === -1) return null;

  // Time spent in current phase
  const phaseStart = new Date(status.timestamp).getTime();
  const elapsed = (Date.now() - phaseStart) / 60000; // minutes

  // Remaining time = rest of current phase + all subsequent phases
  const currentPhaseDuration = PHASE_DURATIONS[status.status] || 5;
  let remaining = Math.max(0, currentPhaseDuration - elapsed);

  for (let i = phaseIndex + 1; i < PHASE_ORDER.length; i++) {
    remaining += PHASE_DURATIONS[PHASE_ORDER[i]];
  }

  return {
    estimatedMinutesRemaining: Math.ceil(remaining),
    estimatedCompletion: new Date(Date.now() + remaining * 60000).toISOString(),
    currentPhase: status.status,
    phaseIndex: phaseIndex + 1,
    totalPhases: PHASE_ORDER.length
  };
}

// GET /api/restore-status - Check if a restore is currently running
app.get('/api/restore-status', async (req, res) => {
  try {
    const statusFile = '/data/restore-status.json';
    const triggerFile = '/data/restore-trigger';

    // Check if trigger file exists (restore queued but not yet started)
    let triggerExists = false;
    let queuedBackupDate = null;
    try {
      queuedBackupDate = (await fsPromises.readFile(triggerFile, 'utf8')).trim();
      triggerExists = true;
    } catch (e) {
      // File doesn't exist - no queued restore
    }

    // Try to read the status file
    try {
      const statusData = await fsPromises.readFile(statusFile, 'utf8');
      const status = JSON.parse(statusData);

      // Check if status is recent — some phases (decompression, Loki restore) take 90+ minutes
      // without a status update, so use a generous window
      const statusAge = Date.now() - new Date(status.timestamp).getTime();
      const isRecent = statusAge < 120 * 60 * 1000;

      if (isRecent && (status.status !== 'completed' && status.status !== 'failed')) {
        const eta = estimateETA(status);
        return res.json({
          inProgress: true,
          status: status.status,
          message: status.message,
          backupDate: status.backupDate,
          filesRemaining: status.filesRemaining || 0,
          queued: triggerExists,
          eta
        });
      }

      // Status file exists but restore is completed or old
      if (status.status === 'completed') {
        return res.json({
          inProgress: false,
          status: 'completed',
          message: 'Last restore completed successfully',
          backupDate: status.backupDate,
          completedAt: status.timestamp
        });
      }

      if (status.status === 'failed') {
        return res.json({
          inProgress: false,
          status: 'failed',
          message: 'Last restore failed - check logs',
          backupDate: status.backupDate,
          completedAt: status.timestamp
        });
      }

      // Status is old, check if we have a queued restore
      if (triggerExists) {
        return res.json({
          inProgress: false,
          status: 'queued',
          message: 'Restore queued, waiting to start',
          backupDate: queuedBackupDate,
          queued: true
        });
      }

      return res.json({
        inProgress: false,
        status: 'idle',
        message: 'No active restore'
      });

    } catch (error) {
      // Status file doesn't exist
      if (error.code === 'ENOENT') {
        // Check if we have a queued restore
        if (triggerExists) {
          return res.json({
            inProgress: false,
            status: 'queued',
            message: 'Restore queued, waiting to start',
            backupDate: queuedBackupDate,
            queued: true
          });
        }

        return res.json({
          inProgress: false,
          status: 'idle',
          message: 'No restore in progress'
        });
      }
      throw error;
    }
  } catch (error) {
    console.error('Error checking restore status:', error);
    res.status(500).json({ error: error.message });
  }
});

// GET /health - Health check
app.get('/health', (req, res) => {
  res.json({ status: 'healthy' });
});

// Start server
app.listen(PORT, '0.0.0.0', () => {
  console.log(`Yesterday Backup Management API running on port ${PORT}`);
  console.log(`Endpoints:`);
  console.log(`  GET  /api/backups - List available backups`);
  console.log(`  POST /api/backups/:date/load - Load a backup`);
  console.log(`  GET  /api/backups/:date/progress - Check progress`);
  console.log(`  GET  /api/current-backup - Get current backup info`);
  console.log(`  GET  /api/system-status - Get Docker container status`);
  console.log(`  GET  /health - Health check`);
});
