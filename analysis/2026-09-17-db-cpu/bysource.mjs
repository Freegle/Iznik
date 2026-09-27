// Mean concurrent mysqld threads on a node, split by which host issued the query,
// bucketed by hour. Streams - the raw TSVs run to hundreds of MB.
import fs from 'fs';
import readline from 'readline';

const file = process.argv[2];
const hours = new Map();   // HH -> {polls:Set, src:Map}

const rl = readline.createInterface({ input: fs.createReadStream(file), crlfDelay: Infinity });
for await (const line of rl) {
  const c = line.split('\t');
  if (c.length < 9) continue;
  const ts = +c[0]; if (!ts) continue;
  const h = new Date(ts * 1000).toISOString().slice(11, 13);
  let b = hours.get(h);
  if (!b) { b = { polls: new Set(), src: new Map() }; hours.set(h, b); }
  b.polls.add(c[0]);
  const info = c.slice(8).join('\t');
  if (info.includes('information_schema.processlist')) continue;  // the sampler itself
  if (!info) continue;
  const host = c[3];
  let s;
  if (/^docker-internal/.test(host)) s = 'batch (FD host)';
  else if (/^db\d-internal/.test(host)) s = 'apiv2 ' + host.slice(0, 3);
  else if (/^localhost/.test(host)) s = 'local';
  else s = host.split(':')[0] || '?';
  b.src.set(s, (b.src.get(s) || 0) + 1);
}

const names = [...new Set([...hours.values()].flatMap(b => [...b.src.keys()]))].sort();
console.log('mean concurrent threads, by hour (UTC)\n');
console.log(['hour', 'polls', ...names.map(n => n.padStart(16))].join('  '));
for (const [h, b] of [...hours].sort()) {
  const P = b.polls.size;
  console.log([
    h + ':00',
    String(P).padStart(5),
    ...names.map(n => ((b.src.get(n) || 0) / P).toFixed(2).padStart(16)),
  ].join('  '));
}
