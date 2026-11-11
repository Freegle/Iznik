// PAF extractor sidecar. Downloads a Royal Mail address data release from AFD over SFTP,
// unlocks and extracts it (extract.sh, under Wine), and works out which lines changed
// since the last release that was applied. The batch container drives it over HTTP and
// does the database work; this side owns the files, so batch needs no Wine and no
// shared volume.
//
//   GET  /health
//   GET  /latest                  the release at the top of AFD's site: { size, published }
//   GET  /jobs/current            state of the current job, or { phase: 'idle' }
//   POST /jobs                    { id, key, edition? } start a job
//   GET  /jobs/:id/delta          the changed lines, once phase is 'ready'
//   POST /jobs/:id/commit         body: lines the batch side could not apply, kept for
//                                 next time. Makes this release the new baseline.
//
// Files live under DATA_DIR (a volume): baseline.csv is the last applied release, sorted;
// pending.csv holds lines that could not be applied last time; work/ is per job.

import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'
import { spawn } from 'node:child_process'
import readline from 'node:readline'
import { pipeline } from 'node:stream/promises'

const PORT = parseInt(process.env.PORT || '8080', 10)
const DATA_DIR = process.env.PAF_DATA_DIR || '/paf-data'
const HOST = process.env.PAF_SFTP_HOST || 'pafdownload.afd.co.uk'
const USER = process.env.PAF_SFTP_USER || ''
const PASSWORD = process.env.PAF_SFTP_PASSWORD || ''
const SCRIPT_DIR = path.dirname(new URL(import.meta.url).pathname)

const WORK = path.join(DATA_DIR, 'work')
const BASELINE = path.join(DATA_DIR, 'baseline.csv')
const PENDING = path.join(DATA_DIR, 'pending.csv')
const STATE_FILE = path.join(DATA_DIR, 'state.json')

const ACTIVE = ['downloading', 'extracting', 'comparing']

let state = { phase: 'idle' }

function setState(patch) {
  state = { ...state, ...patch, updatedAt: new Date().toISOString() }
  fs.writeFileSync(STATE_FILE, JSON.stringify(state))
}

function loadState() {
  try {
    state = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'))
  } catch {
    state = { phase: 'idle' }
  }

  // A job that was running when the container stopped cannot be resumed: the child
  // processes are gone. A finished one ('ready') can still be collected.
  if (ACTIVE.includes(state.phase)) {
    setState({ phase: 'failed', error: 'The extractor restarted part way through' })
  }
}

// Runs a command, resolving with its stdout. Rejects with stderr on a non-zero exit.
function run(cmd, args, opts = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(cmd, args, opts)
    let out = ''
    let err = ''
    child.stdout?.on('data', (d) => (out += d))
    child.stderr?.on('data', (d) => (err += d))
    child.on('error', reject)
    child.on('close', (code) =>
      code === 0
        ? resolve(out)
        : reject(new Error(`${cmd} exited ${code}: ${err.trim().slice(0, 500)}`))
    )
  })
}

function countLines(file) {
  return new Promise((resolve, reject) => {
    let n = 0
    fs.createReadStream(file)
      .on('data', (buf) => {
        for (let i = 0; i < buf.length; i++) if (buf[i] === 10) n++
      })
      .on('end', () => resolve(n))
      .on('error', reject)
  })
}

// The current release is at the top level; earlier ones are under Archive/YyyMmm.
function remoteDir(edition) {
  return edition ? `/Archive/${edition}/` : '/'
}

function curlArgs(netrc) {
  // Host key checking uses /root/.ssh/known_hosts, baked into the image. The credentials
  // go through a netrc file so they do not appear in the process list.
  return ['-sS', '--fail', '--netrc-file', netrc]
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

// The SetupRM.exe line of an SFTP directory listing, which curl gives in `ls -l` form:
// "-rw-r--r-- 1 owner group 440401920 Oct  3 09:12 SetupRM.exe". A date within the last six
// months has a time instead of a year. Returns { size, published: 'YYYY-MM-DD' } or null.
export function parseRelease(listing, now = new Date()) {
  const line = listing.split('\n').find((l) => / SetupRM\.exe\s*$/.test(l))
  if (!line) return null
  const f = line.trim().split(/\s+/)
  const size = parseInt(f[4], 10)
  const month = MONTHS.indexOf(f[5])
  const day = parseInt(f[6], 10)
  if (month < 0 || !day) return { size, published: null }
  let year = /^\d{4}$/.test(f[7]) ? parseInt(f[7], 10) : now.getUTCFullYear()
  // A time, not a year, and a date later than today: it was last year.
  if (!/^\d{4}$/.test(f[7]) && Date.UTC(year, month, day) > now.getTime() + 86400000) year--
  const published = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`
  return { size, published }
}

// What is at the top of AFD's site now. Its own netrc, outside WORK, which a job clears.
async function latest() {
  const netrc = path.join(DATA_DIR, '.netrc-latest')
  fs.writeFileSync(netrc, `machine ${HOST} login ${USER} password ${PASSWORD}\n`, { mode: 0o600 })
  try {
    const release = parseRelease(await run('curl', [...curlArgs(netrc), '--max-time', '60', `sftp://${HOST}/`]))
    if (!release) throw new Error(`No SetupRM.exe at the top of ${HOST}`)
    return release
  } finally {
    fs.rmSync(netrc, { force: true })
  }
}

async function download(job, netrc) {
  const dir = remoteDir(job.edition)
  const listing = await run('curl', [...curlArgs(netrc), `sftp://${HOST}${dir}`])
  const line = listing.split('\n').find((l) => / SetupRM\.exe\s*$/.test(l))
  if (!line) {
    throw new Error(`No SetupRM.exe in ${dir} on ${HOST}`)
  }
  const total = parseInt(line.trim().split(/\s+/)[4], 10)
  const exe = path.join(WORK, 'SetupRM.exe')

  const timer = setInterval(() => {
    let got = 0
    try {
      got = fs.statSync(exe).size
    } catch {}
    setState({
      progress: total ? Math.min(99, Math.floor((got * 100) / total)) : 0,
      detail: `Downloaded ${Math.round(got / 1e6)} of ${Math.round(total / 1e6)} MB`,
    })
  }, 2000)

  try {
    await run('curl', [
      ...curlArgs(netrc),
      '--max-time',
      '7200',
      '-o',
      exe,
      `sftp://${HOST}${dir}SetupRM.exe`,
    ])
  } finally {
    clearInterval(timer)
  }

  return exe
}

function extract(exe, key) {
  return new Promise((resolve, reject) => {
    const out = path.join(WORK, 'out')
    const child = spawn('bash', [path.join(SCRIPT_DIR, 'extract.sh'), exe, out, key])
    let csv = null
    let error = null

    readline.createInterface({ input: child.stdout }).on('line', (line) => {
      const [event, ...rest] = line.split(' ')
      const arg = rest.join(' ')
      if (event === 'STATE') {
        setState({ detail: `Extractor: ${arg}` })
      } else if (event === 'PROGRESS') {
        setState({
          progress: Math.min(99, Math.floor(parseFloat(arg) * 100)),
          detail: 'Extracting CSV PAF',
        })
      } else if (event === 'DONE') {
        csv = arg
      } else if (event === 'ERROR') {
        const [code, ...text] = rest
        error = code === 'invalid_key' ? 'The key was not accepted for this release. Check it matches the edition.' : text.join(' ')
      }
    })
    child.stderr.on('data', () => {})
    child.on('close', () => (csv ? resolve(csv) : reject(new Error(error || 'Extraction failed'))))
  })
}

async function compare(csv) {
  const sorted = path.join(WORK, 'new.sorted.csv')
  const delta = path.join(WORK, 'delta.csv')
  const tmp = path.join(WORK, 'tmp')
  fs.mkdirSync(tmp, { recursive: true })
  const env = { ...process.env, LC_ALL: 'C' }

  setState({ progress: 0, detail: 'Sorting the new release' })
  await run('sort', ['-S', '1G', '-T', tmp, '-o', sorted, csv], { env })

  // The raw files are no longer needed and are large.
  fs.rmSync(path.join(WORK, 'out'), { recursive: true, force: true })
  fs.rmSync(path.join(WORK, 'SetupRM.exe'), { force: true })

  const totalLines = await countLines(sorted)

  if (fs.existsSync(BASELINE)) {
    setState({ progress: 50, detail: 'Comparing with the last release applied' })
    // Lines only in the new release: addresses that are new or have changed.
    const changed = path.join(WORK, 'changed.csv')
    const fd = fs.openSync(changed, 'w')
    await run('comm', ['-13', BASELINE, sorted], { env, stdio: ['ignore', fd, 'pipe'] })
    fs.closeSync(fd)

    const inputs = [changed]
    if (fs.existsSync(PENDING)) inputs.push(PENDING)
    await run('sort', ['-u', '-S', '1G', '-T', tmp, '-o', delta, ...inputs], { env })
    fs.rmSync(changed, { force: true })
  } else {
    // Nothing to compare with: every line is applied. A link, not a copy: it is 3 GB.
    fs.linkSync(sorted, delta)
  }

  return { totalLines, deltaLines: await countLines(delta) }
}

async function runJob(job) {
  fs.rmSync(WORK, { recursive: true, force: true })
  fs.mkdirSync(WORK, { recursive: true })
  const netrc = path.join(WORK, '.netrc')
  fs.writeFileSync(netrc, `machine ${HOST} login ${USER} password ${PASSWORD}\n`, { mode: 0o600 })

  try {
    setState({ phase: 'downloading', progress: 0, detail: 'Connecting to AFD' })
    const exe = await download(job, netrc)
    fs.rmSync(netrc, { force: true })

    setState({ phase: 'extracting', progress: 0, detail: 'Starting the extractor' })
    const csv = await extract(exe, job.key)

    setState({ phase: 'comparing', progress: 0 })
    const counts = await compare(csv)

    setState({
      phase: 'ready',
      progress: 100,
      detail: `${counts.deltaLines} of ${counts.totalLines} lines are new or changed`,
      ...counts,
    })
  } catch (e) {
    fs.rmSync(netrc, { force: true })
    setState({ phase: 'failed', error: e.message })
  }
}

// The request body is the lines batch could not apply. It is written straight to disk:
// if locations lacks many postcodes it is too big to hold as a string.
async function commit(req) {
  const incoming = path.join(WORK, 'pending.incoming')
  await pipeline(req, fs.createWriteStream(incoming))
  fs.renameSync(incoming, PENDING)
  fs.renameSync(path.join(WORK, 'new.sorted.csv'), BASELINE)
  fs.rmSync(WORK, { recursive: true, force: true })
  setState({ phase: 'committed', progress: 100, detail: 'Applied' })
}

function json(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json' })
  res.end(JSON.stringify(body))
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = []
    req.on('data', (c) => chunks.push(c))
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')))
    req.on('error', reject)
  })
}

// Exposed without the key.
function publicState() {
  const { key, ...rest } = state
  return rest
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x')
  const parts = url.pathname.split('/').filter(Boolean)

  try {
    if (req.method === 'GET' && url.pathname === '/health') {
      return json(res, 200, { ok: true })
    }

    if (req.method === 'GET' && url.pathname === '/latest') {
      if (!USER || !PASSWORD) {
        return json(res, 500, { error: 'PAF_SFTP_USER and PAF_SFTP_PASSWORD are not set' })
      }
      return json(res, 200, await latest())
    }

    if (req.method === 'GET' && url.pathname === '/jobs/current') {
      return json(res, 200, publicState())
    }

    if (req.method === 'POST' && url.pathname === '/jobs') {
      if (ACTIVE.includes(state.phase)) {
        return json(res, 409, { error: `Job ${state.id} is still ${state.phase}` })
      }
      const body = JSON.parse((await readBody(req)) || '{}')
      const id = parseInt(body.id, 10)
      const key = String(body.key || '').toUpperCase().replace(/[^A-Z0-9]/g, '')
      const edition = body.edition ? String(body.edition) : null
      if (!id || key.length < 10 || (edition && !/^Y\d\dM\d\d$/.test(edition))) {
        return json(res, 400, { error: 'Need id, key and an edition like Y26M08 (or none)' })
      }
      if (!USER || !PASSWORD) {
        return json(res, 500, { error: 'PAF_SFTP_USER and PAF_SFTP_PASSWORD are not set' })
      }

      state = { id, edition, startedAt: new Date().toISOString() }
      setState({ phase: 'downloading', progress: 0, detail: 'Queued' })
      runJob({ id, key, edition })
      return json(res, 202, publicState())
    }

    if (parts[0] === 'jobs' && parts[1] && String(state.id) !== parts[1]) {
      return json(res, 404, { error: `No job ${parts[1]}` })
    }

    if (req.method === 'GET' && parts[0] === 'jobs' && parts[2] === 'delta') {
      if (state.phase !== 'ready') {
        return json(res, 409, { error: `Job is ${state.phase}, not ready` })
      }
      const file = path.join(WORK, 'delta.csv')
      res.writeHead(200, {
        'Content-Type': 'text/csv',
        'Content-Length': fs.statSync(file).size,
      })
      return fs.createReadStream(file).pipe(res)
    }

    if (req.method === 'POST' && parts[0] === 'jobs' && parts[2] === 'commit') {
      if (state.phase !== 'ready') {
        return json(res, 409, { error: `Job is ${state.phase}, not ready` })
      }
      await commit(req)
      return json(res, 200, publicState())
    }

    return json(res, 404, { error: 'Not found' })
  } catch (e) {
    return json(res, 500, { error: e.message })
  }
})

if (process.argv[1] === new URL(import.meta.url).pathname) {
  fs.mkdirSync(DATA_DIR, { recursive: true })
  loadState()
  server.listen(PORT, () => console.log(`paf-extractor listening on ${PORT}`))
}
