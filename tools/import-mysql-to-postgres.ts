// One-time copy of the Holcim MySQL database into the Supabase PostgreSQL schema
// (supabase/migrations). Phase 1 of the Supabase migration.
//
//   node import-mysql-to-postgres.ts --mysql=URL --pg=URL [--dry-run]
//        [--expect-attendees=264] [--expect-qr=264] [--ca=CERT]
//
//   --mysql  source, default $MYSQL_URL          (mysql://user:pass@127.0.0.1:3306/holcim_event)
//   --pg     target, default $SUPABASE_DB_URL / $DATABASE_URL (Supabase session pooler / direct URL)
//   --dry-run  do everything, verify, then ROLL BACK (the target stays empty)
//   --expect-attendees / --expect-qr  refuse to import unless the source has exactly these counts
//
// Safety:
//   * MySQL is only READ, inside one READ ONLY consistent-snapshot transaction (never written).
//   * The target must be EMPTY (all application tables and migration_import_runs); a second
//     run is refused instead of merging or overwriting.
//   * Everything is written in ONE PostgreSQL transaction. Before COMMIT the tool re-reads
//     the target and requires: identical row counts and identical canonical SHA-256 per
//     table, and identical QR dataset / identity fingerprints. Any difference -> ROLLBACK.
//   * Ids are copied as they are; identity sequences are then moved past them
//     (next id = max(MySQL AUTO_INCREMENT, max(id) + 1)).
//   * QR tokens are copied byte for byte (no trim, case change, regeneration or hashing).
//   * DATETIME values are Asia/Manila wall-clock time (the PHP app's time zone): each is
//     stored as the instant  'YYYY-MM-DD HH:MM:SS'::timestamp AT TIME ZONE 'Asia/Manila'.
// Never prints passwords, tokens or attendee data; only counts and hashes.
//
// Exit code: 0 = imported (or dry run passed), 1 = refused / verification failed (nothing kept), 2 = error.
import { parseArgs } from 'node:util';
import { connect, describeUrl, type Db, type Row } from './lib/db.ts';
import { issueCount, snapshot, type Snapshot } from './lib/qr-verifier.ts';
import { TABLES, TIME_ZONE, canonicalJson, explainDifference, mysqlColumns, postgresColumns, tableFingerprint, type Column, type TableFingerprint } from './lib/tables.ts';

const { values: opt } = parseArgs({
  options: {
    mysql: { type: 'string' }, pg: { type: 'string' }, 'dry-run': { type: 'boolean' },
    'expect-attendees': { type: 'string' }, 'expect-qr': { type: 'string' }, ca: { type: 'string' },
    'batch-size': { type: 'string' }, help: { type: 'boolean' },
  },
});
if (opt.help) {
  console.log('Usage: node import-mysql-to-postgres.ts --mysql=URL --pg=URL [--dry-run] [--expect-attendees=N] [--expect-qr=N] [--ca=CERT]');
  process.exit(0);
}
const mysqlUrl = opt.mysql ?? process.env.MYSQL_URL;
const pgUrl = opt.pg ?? process.env.SUPABASE_DB_URL ?? process.env.DATABASE_URL;
if (!mysqlUrl || !/^mysql:/i.test(mysqlUrl) || !pgUrl || !/^postgres(ql)?:/i.test(pgUrl)) {
  console.error('Need --mysql=mysql://... (or MYSQL_URL) and --pg=postgresql://... (or SUPABASE_DB_URL).');
  process.exit(2);
}
const dryRun = opt['dry-run'] === true;
const batchSize = Math.max(1, Math.min(1000, Number(opt['batch-size'] ?? 500)));
const w = (label: string, value: unknown): void => console.log(`${label.padEnd(34)} ${value}`);

class Refused extends Error {}

const startedAt = new Date();
let src: Db | undefined;
let dst: Db | undefined;
let pgOpen = false;
let exitCode = 0;
try {
  src = await connect(mysqlUrl);
  dst = await connect(pgUrl, { ca: opt.ca });
  console.log(`MySQL -> PostgreSQL import${dryRun ? ' (DRY RUN - will roll back)' : ''}\n--------------------------`);
  w('Source (read only):', describeUrl(mysqlUrl));
  w('Target:', describeUrl(pgUrl));
  console.log('');

  // ---- 1. Source: one consistent read-only snapshot for everything below.
  await src.beginReadOnly();
  const srcColumns = new Map<string, Column[]>();
  const srcFingerprints: Record<string, TableFingerprint> = {};
  const autoIncrement: Record<string, number> = {};
  for (const t of TABLES) {
    const cols = await mysqlColumns(src, t);
    if (cols.length === 0) throw new Refused(`Source table ${t} not found - is this the Holcim database?`);
    srcColumns.set(t, cols);
  }
  const migrations = (await src.query('SELECT migration FROM schema_migrations ORDER BY migration')).map((r) => String(r.migration));
  for (const r of await src.query('SELECT table_name AS t, auto_increment AS ai FROM information_schema.tables WHERE table_schema = DATABASE()')) {
    autoIncrement[String(r.t)] = Number(r.ai ?? 0);
  }
  const srcQr: Snapshot = await snapshot(src, null, { inTransaction: true });
  for (const t of TABLES) srcFingerprints[t] = await tableFingerprint(src, t, srcColumns.get(t) as Column[], true);

  w('Source migrations applied:', `${migrations.length} (latest ${migrations.at(-1) ?? '-'})`);
  w('Source attendees / QR records:', `${srcQr.attendee_count} / ${srcQr.qr_count}`);
  w('Source QR integrity issues:', issueCount(srcQr.issues));
  const expectA = opt['expect-attendees'];
  const expectQ = opt['expect-qr'];
  if (expectA !== undefined && Number(expectA) !== srcQr.attendee_count) throw new Refused(`Expected ${expectA} attendees in the source, found ${srcQr.attendee_count}.`);
  if (expectQ !== undefined && Number(expectQ) !== srcQr.qr_count) throw new Refused(`Expected ${expectQ} QR records in the source, found ${srcQr.qr_count}.`);
  if (!migrations.some((m) => m.startsWith('032'))) throw new Refused('Source database is missing migration 032 (randomizer reset); run php database/migrate.php first.');

  // ---- 2. Target: one transaction; schema must match and every table must be empty.
  await dst.query('BEGIN ISOLATION LEVEL SERIALIZABLE');
  pgOpen = true;
  await dst.query("SET LOCAL lock_timeout = '10s'");
  await dst.query(`SET LOCAL TIME ZONE '${TIME_ZONE}'`);
  const allTargets = [...TABLES, 'migration_import_runs'];
  const existing = new Set((await dst.query(
    "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = 'public'")).map((r) => String(r.t)));
  const missing = allTargets.filter((t) => !existing.has(t));
  if (missing.length) throw new Refused(`Target schema is missing tables (${missing.join(', ')}). Apply supabase/migrations first.`);
  await dst.query(`LOCK TABLE ${allTargets.map((t) => `public."${t}"`).join(', ')} IN EXCLUSIVE MODE`);
  for (const t of allTargets) {
    const [row] = await dst.query(`SELECT count(*)::int AS n FROM public."${t}"`);
    if (Number(row?.n) !== 0) throw new Refused(`Target table ${t} is not empty (${row?.n} rows). The import only runs into an empty schema.`);
  }
  const dstColumns = new Map<string, Column[]>();
  for (const t of TABLES) {
    const s = srcColumns.get(t) as Column[];
    const d = await postgresColumns(dst, t);
    const sNames = s.map((c) => c.name).join(',');
    const dNames = d.map((c) => c.name).join(',');
    if (sNames !== dNames) throw new Refused(`Column mismatch in ${t}: MySQL [${sNames}] vs PostgreSQL [${dNames}].`);
    for (const c of s) {
      const dk = d.find((x) => x.name === c.name)?.kind;
      if (dk !== c.kind) throw new Refused(`Type mismatch in ${t}.${c.name}: MySQL ${c.kind} vs PostgreSQL ${dk}.`);
    }
    dstColumns.set(t, d);
  }

  // ---- 3. Copy table by table, ids included.
  for (const t of TABLES) {
    const cols = srcColumns.get(t) as Column[];
    const rows = await src.query(`SELECT ${cols.map((c) => '`' + c.name + '`').join(', ')} FROM \`${t}\` ORDER BY id`);
    for (let i = 0; i < rows.length; i += batchSize) {
      const chunk = rows.slice(i, i + batchSize);
      const params: unknown[] = [];
      const tuples = chunk.map((row) => `(${cols.map((c) => placeholder(c, convert(t, c, row), params)).join(', ')})`);
      await dst.query(`INSERT INTO public."${t}" (${cols.map((c) => `"${c.name}"`).join(', ')}) VALUES ${tuples.join(', ')}`, params);
    }
    // Next id = max(MySQL AUTO_INCREMENT, max(id) + 1).
    const [m] = await dst.query(`SELECT coalesce(max(id), 0)::bigint AS m FROM public."${t}"`);
    const last = Math.max(Number(m?.m ?? 0), (autoIncrement[t] ?? 1) - 1);
    await dst.query(`SELECT setval(pg_get_serial_sequence('public."${t}"', 'id'), $1, $2)`, [Math.max(last, 1), last >= 1]);
  }

  // ---- 4. Verify inside the same transaction, before anything is committed.
  let failures = 0;
  const dstFingerprints: Record<string, TableFingerprint> = {};
  console.log('\nTable                    Source  Target  Content SHA-256');
  for (const t of TABLES) {
    const d = await tableFingerprint(dst, t, dstColumns.get(t) as Column[], true);
    dstFingerprints[t] = d;
    const s = srcFingerprints[t] as TableFingerprint;
    const ok = s.count === d.count && s.sha256 === d.sha256;
    if (!ok) failures++;
    console.log(`${t.padEnd(24)} ${String(s.count).padStart(6)}  ${String(d.count).padStart(6)}  ${ok ? 'match ' + d.sha256.slice(0, 16) : 'MISMATCH'}`);
    if (!ok) for (const line of explainDifference(s, d, dstColumns.get(t) as Column[])) console.log(`    ${line}`);
  }
  const dstQr = await snapshot(dst, null, { inTransaction: true });
  const qrOk = dstQr.fingerprint === srcQr.fingerprint && dstQr.identity_fingerprint === srcQr.identity_fingerprint
    && dstQr.qr_count === srcQr.qr_count && dstQr.attendee_count === srcQr.attendee_count;
  if (!qrOk) failures++;
  for (const t of TABLES) {
    const next = await nextIdentity(dst, t);
    const want = Math.max((dstFingerprints[t]?.max_id ?? 0) + 1, autoIncrement[t] ?? 1);
    if (next < want) { failures++; console.log(`Identity for ${t} would reuse ids (next ${next} < ${want})`); }
  }
  console.log('');
  w('QR dataset SHA-256 (source):', srcQr.fingerprint);
  w('QR dataset SHA-256 (target):', dstQr.fingerprint);
  w('QR identity SHA-256 (source):', srcQr.identity_fingerprint);
  w('QR identity SHA-256 (target):', dstQr.identity_fingerprint);
  w('QR tokens identical:', qrOk ? 'YES' : 'NO');

  if (failures > 0) {
    await dst.query('ROLLBACK');
    pgOpen = false;
    console.log(`\nRESULT: FAIL (${failures} difference(s)) - rolled back, nothing was written.`);
    exitCode = 1;
  } else {
    await dst.query(
      `INSERT INTO public.migration_import_runs (source_database, source_migrations, table_counts, table_fingerprints,
         qr_fingerprint, qr_identity_fingerprint, started_at) VALUES ($1, $2::jsonb, $3::jsonb, $4::jsonb, $5, $6, $7)`,
      [src.label, JSON.stringify(migrations), JSON.stringify(Object.fromEntries(TABLES.map((t) => [t, dstFingerprints[t]?.count]))),
        JSON.stringify(Object.fromEntries(TABLES.map((t) => [t, dstFingerprints[t]?.sha256]))), dstQr.fingerprint, dstQr.identity_fingerprint, startedAt.toISOString()]);
    await dst.query(dryRun ? 'ROLLBACK' : 'COMMIT');
    pgOpen = false;
    console.log(`\nRESULT: PASS - ${dryRun ? 'dry run verified and rolled back (target unchanged)' : 'imported and committed'}.`);
    if (!dryRun) console.log('Next: node verify-qr-migration.ts --db=<target> --compare=<manifest saved from MySQL before the import>');
  }
} catch (e) {
  if (pgOpen) await dst?.query('ROLLBACK').catch(() => undefined);
  if (e instanceof Refused) {
    console.error(`\nREFUSED: ${e.message}\nNothing was written.`);
    exitCode = 1;
  } else {
    console.error(`\nERROR (${(e as Error).name}): ${(e as Error).message}\nRolled back; nothing was written.`);
    exitCode = 2;
  }
} finally {
  if (src) { await src.rollback().catch(() => undefined); await src.close().catch(() => undefined); }
  await dst?.close().catch(() => undefined);
}
process.exit(exitCode);

// ---------------------------------------------------------------- helpers
/** The id the identity column will hand out next (read without consuming it). */
async function nextIdentity(db: Db, t: string): Promise<number> {
  const [s] = await db.query(`SELECT pg_get_serial_sequence('public."${t}"', 'id') AS seq`);
  const [r] = await db.query(`SELECT last_value::bigint AS v, is_called FROM ${String(s?.seq)}`);
  return Number(r?.v) + (r?.is_called ? 1 : 0);
}

/** Validates/normalises one MySQL value for PostgreSQL WITHOUT changing its content. */
function convert(table: string, c: Column, row: Row): unknown {
  const v = row[c.name];
  if (v === null || v === undefined) return null;
  const where = `${table}.${c.name} (id ${String(row.id)})`;
  switch (c.kind) {
    case 'datetime':
    case 'date': {
      const s = String(v);
      if (!/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2}(\.\d{1,6})?)?$/.test(s) || s.startsWith('0000')) throw new Refused(`Invalid date value in ${where}.`);
      return s;
    }
    case 'json': {
      if (typeof v !== 'string') return canonicalJson(v);
      try { JSON.parse(v); } catch { throw new Refused(`Invalid JSON in ${where}; fix it in MySQL before importing.`); }
      if (v.includes('\\u0000')) throw new Refused(`JSON with a NUL character in ${where} cannot be stored as jsonb.`);
      return v;
    }
    case 'int':
      return String(v);
    default: {
      const s = Buffer.isBuffer(v) ? v.toString('utf8') : String(v);
      if (s.includes('\u0000')) throw new Refused(`Text with a NUL character in ${where} cannot be stored in PostgreSQL.`);
      return s; // exact: no trimming / case change (QR tokens included)
    }
  }
}

function placeholder(c: Column, value: unknown, params: unknown[]): string {
  params.push(value);
  const p = `$${params.length}`;
  switch (c.kind) {
    case 'datetime': return `(${p}::timestamp AT TIME ZONE '${TIME_ZONE}')`;
    case 'date': return `${p}::date`;
    case 'json': return `${p}::jsonb`;
    default: return p;
  }
}
