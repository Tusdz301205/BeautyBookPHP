import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';
import { parseEnv } from 'node:util';

const root = path.resolve(import.meta.dirname, '..');
const source = path.resolve(process.argv[2] || path.join(root, '../GlowMe/api'));
const sourceEnv = {};
for (const file of ['.env', '.env.dataset']) {
  const filename = path.join(source, file);
  if (fs.existsSync(filename)) Object.assign(sourceEnv, parseEnv(fs.readFileSync(filename, 'utf8')));
}
const require = createRequire(path.join(source, 'package.json'));
const { Client, types } = require('pg');
for (const oid of [1082, 1114, 1184, 1700, 20]) types.setTypeParser(oid, value => value);
const connectionString = sourceEnv.DATABASE_URL;
if (!connectionString) throw new Error('GlowMe DATABASE_URL is not configured');
const connection = new URL(connectionString);
if (process.argv.includes('--connection-info')) {
  console.log(JSON.stringify({ host: connection.hostname, port: connection.port || '5432', database: connection.pathname.slice(1) }));
  process.exit(0);
}
if (!['localhost', '127.0.0.1', '::1'].includes(connection.hostname)) throw new Error('Expected local GlowMe source');
const client = new Client({ connectionString });
const output = path.join(root, 'storage/app/private/glowme-import');
fs.mkdirSync(output, { recursive: true });
const quote = value => '"' + value.replaceAll('"', '""') + '"';
try {
  await client.connect();
  await client.query('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
  const tables = (await client.query("select schemaname, tablename from pg_tables where schemaname not in ('pg_catalog','information_schema') order by 1,2")).rows;
  const snapshot = { version: 1, exportedAt: new Date().toISOString(), database: connection.pathname.slice(1), tables: {} };
  for (const table of tables) {
    const key = `${table.schemaname}.${table.tablename}`;
    const columns = (await client.query('select column_name,data_type,udt_name,is_nullable,column_default from information_schema.columns where table_schema=$1 and table_name=$2 order by ordinal_position', [table.schemaname, table.tablename])).rows;
    const rows = (await client.query(`SELECT * FROM ${quote(table.schemaname)}.${quote(table.tablename)}`)).rows;
    // Raw JSON text preserves PostgreSQL numeric values beyond JavaScript precision as well.
    const rawRows = (await client.query(`SELECT row_to_json(t)::text AS row FROM ${quote(table.schemaname)}.${quote(table.tablename)} t`)).rows.map(row => row.row);
    snapshot.tables[key] = { columns, rows, rawRows };
  }
  await client.query('COMMIT');
  const content = JSON.stringify(snapshot);
  fs.writeFileSync(path.join(output, 'source.json'), content, { mode: 0o600 });
  const report = { exportedAt: snapshot.exportedAt, database: snapshot.database, tables: Object.fromEntries(Object.entries(snapshot.tables).map(([key, table]) => [key, table.rows.length])), sha256: createHash('sha256').update(content).digest('hex') };
  fs.writeFileSync(path.join(output, 'manifest.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify({ database: report.database, tables: tables.length, rows: Object.values(report.tables).reduce((sum, n) => sum + n, 0), sha256: report.sha256 }));
} catch (error) {
  console.error('GlowMe read-only export failed (' + (error.code || error.constructor.name) + '); no credentials printed');
  process.exitCode = 1;
} finally { await client.end(); }
