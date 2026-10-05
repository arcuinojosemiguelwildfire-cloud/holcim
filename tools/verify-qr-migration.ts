// QR migration verification (READ-ONLY) - replacement for backend/cli/verify-qr-migration.php.
//
//   node verify-qr-migration.ts --db=URL                    integrity check + SHA-256 of the QR dataset
//   node verify-qr-migration.ts --db=URL --save [--out=DIR] ...and save the manifest (+ .sha256)
//   node verify-qr-migration.ts --db=URL --compare=FILE     compare a database with a saved manifest
//   node verify-qr-migration.ts --db=URL --event=ID         one event only (default: all events)
//
// --db accepts mysql://... (the current system) or postgresql://... (Supabase);
// default: $SUPABASE_DB_URL, then $DATABASE_URL. Manifests written by the PHP tool
// and by this tool are interchangeable (same canonical format and fingerprints).
//
// Never writes to the database: everything is read in a READ ONLY transaction that is
// rolled back. Use a read-only role where possible (docs/SUPABASE_MIGRATION.md).
// A printed QR contains only attendee_qr_codes.token; it keeps working after a move
// ONLY if the same token stays attached to the same attendee (event_id + attendee_code).
// Manifests contain names and QR tokens: keep them private. There is no web endpoint.
//
// Exit code: 0 = PASS, 1 = FAIL, 2 = usage / read error.
import { chmodSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { basename, dirname, join, resolve } from 'node:path';
import { parseArgs } from 'node:util';
import { connect, describeUrl } from './lib/db.ts';
import { buildManifest, compare, issueCount, listed, manifestIsIntact, snapshot, type Manifest } from './lib/qr-verifier.ts';

const { values: opt } = parseArgs({
  options: {
    db: { type: 'string' }, save: { type: 'boolean' }, out: { type: 'string' }, compare: { type: 'string' },
    event: { type: 'string' }, ca: { type: 'string' }, help: { type: 'boolean' },
  },
});
if (opt.help) {
  console.log('Usage: node verify-qr-migration.ts --db=URL [--save [--out=DIR]] [--compare=FILE] [--event=ID] [--ca=CERT]');
  process.exit(0);
}
const url = opt.db ?? process.env.SUPABASE_DB_URL ?? process.env.DATABASE_URL;
if (!url) {
  console.error('No database: pass --db=URL or set SUPABASE_DB_URL.');
  process.exit(2);
}

let eventId: number | null = opt.event && /^\d+$/.test(opt.event) ? Number(opt.event) : null;
let manifest: Partial<Manifest> | null = null;
if (opt.compare) {
  try {
    manifest = JSON.parse(readFileSync(opt.compare, 'utf8')) as Partial<Manifest>;
  } catch {
    console.error(`Cannot read manifest: ${opt.compare}`);
    process.exit(2);
  }
  eventId = manifest.event_scope ?? null; // compare the same scope that was saved
}

let db;
let snap;
try {
  db = await connect(url, { ca: opt.ca });
  snap = await snapshot(db, eventId);
} catch (e) {
  console.error(`Cannot read the database (${(e as Error).name}): ${(e as Error).message}`);
  process.exit(2);
} finally {
  await db?.close();
}
const issues = snap.issues;
const w = (label: string, value: unknown): void => console.log(`${label.padEnd(34)} ${value}`);

console.log('QR Migration Verification\n-------------------------');
w('Database:', `${describeUrl(url)}${eventId !== null ? ` (event ${eventId} only)` : ' (all events)'}`);
console.log('');

if (manifest === null) {
  w('Attendees:', snap.attendee_count);
  w('Attendees with QR:', snap.attendees_with_qr);
  w('QR records:', snap.qr_count);
  w('Missing QR (active attendees):', issues.missing_qr.length);
  w('Duplicate tokens:', issues.duplicate_tokens.length);
  w('Attendees with more than one QR:', issues.duplicate_attendee_qr.length);
  w('Orphan QR records:', issues.orphan_qr.length);
  w('Empty tokens:', issues.empty_token.length);
  w('Invalid token format:', issues.invalid_token_format.length);
  w('Missing attendee codes:', issues.missing_attendee_code.length);
  w('Attendee event missing:', issues.event_missing.length);
  w('Scans linked to another QR:', issues.scan_qr_mismatch);
  if (snap.warnings.non_standard_token_length.length) w('Note - tokens not 32 chars:', `${snap.warnings.non_standard_token_length.length} (still scannable)`);
  if (snap.warnings.archived_without_qr > 0) w('Note - archived without QR:', `${snap.warnings.archived_without_qr} (not an error)`);
  console.log(`\nQR Dataset SHA-256:\n${snap.fingerprint}\nQR Identity SHA-256 (event_id|attendee_code|token):\n${snap.identity_fingerprint}`);
  const named: Array<[keyof typeof issues, string]> = [['missing_qr', 'Missing QR'], ['duplicate_tokens', 'Duplicate tokens'],
    ['duplicate_attendee_qr', 'More than one QR'], ['empty_token', 'Empty token'], ['invalid_token_format', 'Invalid token format'], ['event_missing', 'Event missing']];
  for (const [k, label] of named) {
    const v = issues[k];
    if (Array.isArray(v) && v.length) console.log(`  ${label}: ${listed(v)}`);
  }
  if (issues.orphan_qr.length) console.log(`  Orphan QR record ids: ${listed(issues.orphan_qr)}`);

  if (opt.save) {
    const dir = resolve(opt.out ?? join(dirname(new URL(import.meta.url).pathname), '..', 'database', 'qr-migration'));
    mkdirSync(dir, { recursive: true, mode: 0o700 });
    if (!existsSync(join(dir, '.htaccess'))) writeFileSync(join(dir, '.htaccess'), 'Require all denied\n');
    const stamp = new Date(Date.now() + 8 * 3600_000).toISOString().replace(/[-:]/g, '').replace('T', '-').slice(0, 15);
    const name = `qr-manifest-${stamp}`;
    const json = JSON.stringify(buildManifest(snap, db?.label ?? '?'), null, 4) + '\n';
    writeFileSync(join(dir, `${name}.json`), json, { mode: 0o600 });
    writeFileSync(join(dir, `${name}.sha256`), `${createHash('sha256').update(json).digest('hex')}  ${name}.json\n`, { mode: 0o600 });
    chmodSync(join(dir, `${name}.json`), 0o600);
    console.log(`\nSaved manifest (contains names and QR tokens - keep private):\n  ${join(dir, `${name}.json`)}\n  ${join(dir, `${name}.sha256`)}`);
  }
  const ok = issueCount(issues) === 0;
  console.log(`\nRESULT: ${ok ? 'PASS' : 'FAIL'}`);
  process.exit(ok ? 0 : 1);
}

// ---- Compare mode
const path = opt.compare as string;
let fileOk: boolean | null = null;
const sidecar = path.replace(/\.json$/, '') + '.sha256';
if (existsSync(sidecar)) {
  const expected = readFileSync(sidecar, 'utf8').split(/\s+/)[0] ?? '';
  fileOk = expected === createHash('sha256').update(readFileSync(path)).digest('hex');
}
const intact = manifestIsIntact(manifest);
const c = compare(manifest as Manifest, snap);
const pass = c.pass && intact && fileOk !== false;

w('Manifest:', `${basename(path)} (from ${manifest.database ?? '?'}, ${manifest.generated_at ?? '?'})`);
w('Manifest file checksum (.sha256):', fileOk === null ? 'not found (skipped)' : fileOk ? 'OK' : 'MISMATCH - file was changed');
w('Manifest content fingerprint:', intact ? 'OK' : 'MISMATCH - records were changed');
console.log('');
w('Expected records:', c.expected_count);
w('Actual records:', c.actual_count);
console.log('');
w('Matching QR tokens:', c.matching.length);
w('Changed QR tokens:', c.changed_tokens.length);
w('Missing attendees:', c.missing.length);
w('Unexpected attendees:', c.unexpected.length);
w('Changed attendee codes:', c.changed_codes.length);
w('Changed attendee/token relationships:', c.relationship_changes.length);
w('Event mismatches:', c.event_mismatch.length);
w('Duplicate tokens:', issues.duplicate_tokens.length);
w('Attendees with more than one QR:', issues.duplicate_attendee_qr.length);
w('Orphan QR records:', issues.orphan_qr.length);
w('Missing QR (active attendees):', issues.missing_qr.length);
w('Empty / invalid tokens:', issues.empty_token.length + issues.invalid_token_format.length);
console.log('');
w('QR identity mappings preserved:', `${c.matching.length}/${c.expected_count}`);
w('Attendee IDs changed:', `${c.id_changes.length} (reported only; not a failure)`);
for (const [field, label] of [['full_name', 'Name'], ['company', 'Company'], ['department', 'Cluster']] as const) {
  w(`${label} changed:`, `${c.detail_changes[field]?.length ?? 0} (reported only; does not affect the QR)`);
}
w('Expected identity SHA-256:', manifest.identity_fingerprint ?? '?');
w('Actual identity SHA-256:', snap.identity_fingerprint);

let printed = false;
const show = (label: string, items: unknown[] | undefined): void => {
  if (!items || items.length === 0) return;
  if (!printed) console.log('\nAffected attendee codes:');
  console.log(`  ${label}: ${listed(items)}`);
  printed = true;
};
show('Changed QR tokens', c.changed_tokens);
show('Missing attendees', c.missing);
show('Unexpected attendees', c.unexpected);
show('Changed attendee codes', c.changed_codes);
show('Token moved to another attendee', c.relationship_changes);
show('Event mismatch', c.event_mismatch);
show('Attendee ID changed', c.id_changes);
show('Name changed', c.detail_changes.full_name);
show('Company changed', c.detail_changes.company);
show('Cluster changed', c.detail_changes.department);
show('Duplicate tokens', issues.duplicate_tokens);
show('More than one QR', issues.duplicate_attendee_qr);
show('Missing QR', issues.missing_qr);
show('Empty token', issues.empty_token);
show('Invalid token format', issues.invalid_token_format);
show('Event missing', issues.event_missing);

console.log(`\nRESULT: ${pass ? 'PASS' : 'FAIL'}`);
if (pass) console.log('Every printed QR token resolves to the same attendee in this database.');
process.exit(pass ? 0 : 1);
