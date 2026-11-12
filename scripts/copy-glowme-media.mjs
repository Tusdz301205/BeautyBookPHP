import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { parseEnv } from 'node:util';
import { createHash } from 'node:crypto';

const root = path.resolve(import.meta.dirname, '..');
const source = path.resolve(root, '../GlowMe/api');
const env = {};
for (const file of ['.env', '.env.dataset']) if (fs.existsSync(path.join(source, file))) Object.assign(env, parseEnv(fs.readFileSync(path.join(source, file), 'utf8')));
const require = createRequire(path.join(source, 'package.json'));
const { S3Client, GetObjectCommand } = require('@aws-sdk/client-s3');
if (!env.R2_ENDPOINT || !env.R2_ACCESS_KEY_ID || !env.R2_SECRET_ACCESS_KEY || !env.R2_BUCKET_NAME) throw new Error('GlowMe media storage is not configured');
const client = new S3Client({ region: 'auto', endpoint: env.R2_ENDPOINT, credentials: { accessKeyId: env.R2_ACCESS_KEY_ID, secretAccessKey: env.R2_SECRET_ACCESS_KEY }, maxAttempts: 3 });
const prefix = (env.R2_KEY_PREFIX || '').replace(/^\/+|\/+$/g, '');
const snapshot = JSON.parse(fs.readFileSync(path.join(root, 'storage/app/private/glowme-import/source.json'), 'utf8'));
const records = ['media_files', 'chat_attachments'].flatMap(table => snapshot.tables['public.' + table].rows.map(row => ({ ...row, table })));
const folders = { 'image/png': ['images', 'png'], 'image/jpeg': ['images', 'jpg'], 'image/webp': ['images', 'webp'], 'application/pdf': ['pdf', 'pdf'] };
const results = [];
let next = 0;
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const localFiles = new Map();
for (const localRoot of [path.join(source, 'database/media'), path.resolve(source, '../temp/GlowMe-DB-20261103/media')]) {
  if (fs.existsSync(localRoot)) for (const entry of fs.readdirSync(localRoot, { withFileTypes: true })) {
    if (!entry.isFile()) continue;
    const filename = path.join(localRoot, entry.name);
    const bytes = fs.readFileSync(filename);
    localFiles.set(hash(bytes), filename);
  }
}
async function copy(row) {
  if (!/^[a-f0-9-]+\.bin$/i.test(row.storage_key)) throw new Error('Invalid opaque source media key');
  const destination = path.join(root, 'storage/app/private/glowme', row.table === 'media_files' ? 'media' : 'chat', row.storage_key);
  if (fs.existsSync(destination)) {
    const bytes = fs.readFileSync(destination);
    if (bytes.length === row.size_bytes && hash(bytes) === row.sha256) return 'VERIFIED';
  }
  if (localFiles.has(row.sha256)) {
    const bytes = fs.readFileSync(localFiles.get(row.sha256));
    if (bytes.length !== row.size_bytes) throw new Error('Local source size mismatch');
    fs.mkdirSync(path.dirname(destination), { recursive: true });
    fs.writeFileSync(destination, bytes, { mode: 0o600 });
    return 'COPIED_LOCAL';
  }
  const location = folders[row.mime_type];
  if (!location) throw new Error('Unsupported source MIME');
  const keys = [`${location[0]}/${row.storage_key.slice(0, -4)}.${location[1]}`, row.storage_key];
  for (const key of keys) {
    try {
      const response = await client.send(new GetObjectCommand({ Bucket: env.R2_BUCKET_NAME, Key: prefix ? prefix + '/' + key : key, Range: `bytes=0-${row.size_bytes}` }));
      const bytes = Buffer.from(await response.Body.transformToByteArray());
      if (bytes.length !== row.size_bytes || hash(bytes) !== row.sha256) throw new Error('Source byte integrity mismatch');
      fs.mkdirSync(path.dirname(destination), { recursive: true });
      fs.writeFileSync(destination, bytes, { mode: 0o600 });
      return 'COPIED';
    } catch (error) {
      if (error.name === 'NoSuchKey' || error.name === 'NotFound' || error.$metadata?.httpStatusCode === 404) continue;
      throw new Error('Source media read/integrity failure');
    }
  }
  return row.deleted_at ? 'DELETED_AT_SOURCE' : 'MISSING_AT_SOURCE';
}
await Promise.all(Array.from({ length: 6 }, async () => {
  while (next < records.length) {
    const row = records[next++];
    try { results.push({ table: row.table, id: row.id, status: await copy(row) }); }
    catch { results.push({ table: row.table, id: row.id, status: 'READ_FAILED' }); }
  }
}));
const summary = {};
for (const result of results) summary[result.status] = (summary[result.status] || 0) + 1;
fs.writeFileSync(path.join(root, 'storage/app/private/glowme-import/media-report.json'), JSON.stringify({ summary, results }, null, 2));
console.log(JSON.stringify(summary));
if (results.some(row => ['READ_FAILED', 'MISSING_AT_SOURCE'].includes(row.status))) process.exitCode = 1;
