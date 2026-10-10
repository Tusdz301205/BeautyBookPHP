import fs from 'node:fs/promises';
import path from 'node:path';
import { createRequire } from 'node:module';
import { createHash } from 'node:crypto';

// Read constants only. This script never runs the original seed, Prisma, or a source DB.
const root = path.resolve(process.cwd());
const sourceRoot = path.resolve(process.argv[2] || path.join(root, '..', 'beauty-booking-api-main'));
const apiRoot = path.join(sourceRoot, 'beauty-booking-api-main');
const require = createRequire(path.join(apiRoot, 'package.json'));
const ts = require('typescript');
const seedPath = path.join(apiRoot, 'prisma', 'seed.ts');
const planPath = path.join(sourceRoot, 'docs', 'generated-images', 'beautybook', 'entity-plan.json');
const seed = await fs.readFile(seedPath, 'utf8');
const planText = await fs.readFile(planPath, 'utf8');
const sourceFile = ts.createSourceFile(seedPath, seed, ts.ScriptTarget.Latest, true);

function literal(node) {
  if (ts.isAsExpression(node) || ts.isParenthesizedExpression(node)) return literal(node.expression);
  if (ts.isStringLiteral(node) || ts.isNumericLiteral(node)) return ts.isNumericLiteral(node) ? Number(node.text) : node.text;
  if (node.kind === ts.SyntaxKind.NullKeyword) return null;
  if (node.kind === ts.SyntaxKind.TrueKeyword) return true;
  if (node.kind === ts.SyntaxKind.FalseKeyword) return false;
  if (ts.isArrayLiteralExpression(node)) return node.elements.map(literal);
  if (ts.isObjectLiteralExpression(node)) return Object.fromEntries(node.properties.map(property => {
    if (!ts.isPropertyAssignment(property)) throw new Error('Unsupported source constant property');
    return [property.name.text, literal(property.initializer)];
  }));
  // Keep all source templates instead of evaluating seedConfig or any application code.
  if (ts.isCallExpression(node) && ts.isPropertyAccessExpression(node.expression) && node.expression.name.text === 'slice') return literal(node.expression.expression);
  throw new Error(`Unsupported source constant: ${node.getText(sourceFile).slice(0, 80)}`);
}

const names = new Set(['ownerDefs', 'businessDefs', 'svcTemplates', 'catData', 'canonicalDefinitions', 'provinceDefs']);
const constants = {};
function visit(node) {
  if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) && names.has(node.name.text)) constants[node.name.text] = literal(node.initializer);
  ts.forEachChild(node, visit);
}
visit(sourceFile);
for (const name of names) if (!constants[name]) throw new Error(`Source constant missing: ${name}`);
const plan = JSON.parse(planText);
const templateByName = new Map(constants.svcTemplates.map(item => [item.name, item]));
const definitionByName = new Map(constants.businessDefs.map((item, index) => [item.name, { ...item, owner: constants.ownerDefs[index] }]));
const provinceByDistrict = new Map(constants.provinceDefs.flatMap(province => province.districts.map(district => [district, { name: district, provinceName: province.name, provinceCode: province.code }])));
function media(entry) {
  const outputs = [...entry.outputs].sort((a, b) => a.width - b.width);
  const largest = outputs.at(-1);
  return { src: largest.path, srcSet: outputs.map(item => `${item.path} ${item.width}w`).join(', '), sizes: entry.entityType === 'service' ? '(max-width: 720px) 92vw, (max-width: 1200px) 32vw, 420px' : '(max-width: 720px) 92vw, (max-width: 1200px) 48vw, 560px', width: largest.width, height: largest.height, alt: entry.alt, illustration: true };
}
const output = {
  schemaVersion: 1,
  provenance: { source: 'Original source constants and generated entity media plan; no database export', seedSha256: createHash('sha256').update(seed).digest('hex'), entityPlanSha256: createHash('sha256').update(planText).digest('hex'), planGeneratedAt: plan.generatedAt, priceBasis: 'TEMPLATE_BASE_PRICE', limitation: 'The media plan does not contain live branch price jitter, ratings, bookings or randomly generated staff identities. These are not fabricated as an original DB snapshot.' },
  categories: constants.catData,
  provinces: constants.provinceDefs,
  canonicals: constants.canonicalDefinitions.map(([code, slug, name, synonyms]) => ({ code, slug, name, synonyms })),
  businesses: plan.businesses.map(entry => {
    const definition = definitionByName.get(entry.name);
    if (!definition) throw new Error(`Unmatched business ${entry.name}`);
    return { sourceUuid: entry.entityId, name: entry.name, slug: entry.slug, description: definition.desc, owner: definition.owner, media: media(entry) };
  }),
  branches: plan.branches.map(entry => {
    const districtName = entry.name.slice(entry.businessName.length + 3);
    const district = provinceByDistrict.get(districtName);
    if (!district) throw new Error(`Unmatched district ${districtName}`);
    return { sourceUuid: entry.entityId, businessUuid: entry.businessId, name: entry.name, addressLine: entry.address, district, workingHours: { openTime: '08:00', closeTime: '23:00', allDaysOpen: true }, media: media(entry) };
  }),
  offerings: plan.services.map(entry => {
    const template = templateByName.get(entry.name);
    if (!template) throw new Error(`Unmatched service ${entry.name}`);
    return { sourceUuid: entry.entityId, businessUuid: entry.businessId, branchUuid: entry.branchId, name: entry.name, description: `${entry.name} với sản phẩm cao cấp, nhân viên chuyên nghiệp.`, categorySlug: template.cat, categoryName: entry.categoryName, canonicalCode: template.canonical, price: template.price, durationMinutes: template.dur, priceBasis: 'TEMPLATE_BASE_PRICE', media: media(entry) };
  }),
};
const outputPath = path.join(root, 'database', 'data', 'beautybook-source-reference.json');
await fs.mkdir(path.dirname(outputPath), { recursive: true });
await fs.writeFile(outputPath, JSON.stringify(output, null, 2) + '\n', 'utf8');
process.stdout.write(JSON.stringify({ output: outputPath, businesses: output.businesses.length, branches: output.branches.length, offerings: output.offerings.length, sourceReadOnly: true, priceBasis: output.provenance.priceBasis }) + '\n');
