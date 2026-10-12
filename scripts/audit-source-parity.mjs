import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const sourceRoot = path.resolve(process.argv[2] || '../beauty-booking-api-main/beauty-booking-web-main/beauty-booking-web-main/src');
const portRoot = path.resolve('resources/beautybook/src');
const output = path.resolve(process.argv[3] || 'tmp/beautybook-audit/source-files.json');
function files(root) {
  return fs.readdirSync(root, { withFileTypes: true }).flatMap(entry => {
    const target = path.join(root, entry.name);
    return entry.isDirectory() ? files(target) : [target];
  });
}
const relative = (root, file) => path.relative(root, file).replaceAll('\\', '/');
const sourceFiles = new Map(files(sourceRoot).map(file => [relative(sourceRoot, file), file]));
const portFiles = new Map(files(portRoot).map(file => [relative(portRoot, file), file]));
const hash = buffer => crypto.createHash('sha256').update(buffer).digest('hex');
const comparisons = [...new Set([...sourceFiles.keys(), ...portFiles.keys()])].sort().map(file => {
  const source = sourceFiles.has(file) ? fs.readFileSync(sourceFiles.get(file)) : null;
  const port = portFiles.has(file) ? fs.readFileSync(portFiles.get(file)) : null;
  const status = !source ? 'port-only' : !port ? 'source-only' : source.equals(port) ? 'identical' : source.toString().replaceAll('\r\n', '\n') === port.toString().replaceAll('\r\n', '\n') ? 'line-endings-only' : 'changed';
  return { file, status, sourceSha256: source && hash(source), portSha256: port && hash(port) };
});
const phpFiles = ['app', 'bootstrap', 'config', 'database', 'routes', 'resources/views', 'tests', 'public'].flatMap(root => files(root))
  .filter(file => file.endsWith('.php'))
  .map(file => ({ file: relative(process.cwd(), path.resolve(file)), sha256: hash(fs.readFileSync(file)) }));
const summary = { sourceFiles: sourceFiles.size, portFiles: portFiles.size, phpFiles: phpFiles.length };
for (const status of ['identical', 'line-endings-only', 'changed', 'port-only', 'source-only']) summary[status] = comparisons.filter(row => row.status === status).length;
fs.mkdirSync(path.dirname(output), { recursive: true });
fs.writeFileSync(output, JSON.stringify({ createdAt: new Date().toISOString(), sourceRoot, portRoot, summary, comparisons, phpFiles }, null, 2));
console.log(JSON.stringify({ summary, differences: comparisons.filter(row => !['identical', 'line-endings-only'].includes(row.status)).map(({file,status}) => ({file,status})) }, null, 2));
