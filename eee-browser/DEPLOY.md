# Deploying eee-browser on Fly.io

## First-time setup

```bash
# Install flyctl if needed
curl -L https://fly.io/install.sh | sh
fly auth login

cd eee-browser

# Create the app (only once)
fly apps create freegle-eee-browser

# Create a persistent volume for the SQLite files (1GB is plenty)
fly volumes create eee_data --region lhr --size 1

# Deploy
fly deploy
```

## Upload the classifications database

After first deploy, copy the classifications SQLite into the volume:

```bash
fly ssh console -C "ls /data"   # should be empty

# From your local machine:
fly sftp shell
put iznik-batch/storage/eee/classifications.sqlite /data/classifications.sqlite
exit
```

Or use `fly sftp get/put` directly:
```bash
fly sftp put iznik-batch/storage/eee/classifications.sqlite /data/classifications.sqlite
```

The labels DB (`eee-labels.db`) is created automatically on first use.

## Subsequent deploys

```bash
fly deploy
```

## View logs

```bash
fly logs
```

## Download labels

```bash
fly sftp get /data/eee-labels.db eee-labels-backup.db
```
