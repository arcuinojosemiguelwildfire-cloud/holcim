// End-to-end Phase 1 migration test on THROWAWAY databases:
//   seed MySQL (264 attendees + QR + rows in every table) -> save QR manifest from MySQL
//   (TS tool, and PHP tool when available) -> import dry run -> import -> verify PostgreSQL
//   -> mutation scenarios that must FAIL (and harmless ones that must PASS).
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type pg from 'pg';
import { connect } from '../lib/db.ts';
import { TABLES, mysqlColumns, postgresColumns, tableFingerprint } from '../lib/tables.ts';
import { snapshot } from '../lib/qr-verifier.ts';
import { REPO, createMysqlScratch, createPgScratch, dropMysql, dropPg, field, mysqlAdmin, mysqlChecksums, mysqlUrl, pgClient, pgUrl, runTool, seedMysql, skipBoth } from './helpers.ts';

const MY = 'holcim_migtest_e2e_my';
const PG = 'holcim_migtest_e2e_pg';
const PG2 = 'holcim_migtest_e2e_pg_dry';

describe('MySQL -> PostgreSQL import and QR verification', { skip: skipBoth }, () => {
  let dir: string;
  let manifest: string;
  let tokens: string[];
  let checksumBefore: string;
  let p: pg.Client;

  before(async () => {
    dir = mkdtempSync(join(tmpdir(), 'holcim-migtest-'));
    await createMysqlScratch(MY);
    ({ tokens } = await seedMysql(MY));
    await createPgScratch(PG);
    await createPgScratch(PG2);
    checksumBefore = await mysqlChecksums(MY);
  });
  after(async () => {
    await p?.end();
    rmSync(dir, { recursive: true, force: true });
    await dropPg(PG);
    await dropPg(PG2);
    await dropMysql(MY);
  });

  test('source has the expected Phase 1 counts: 264 attendees, 264 QR, no missing / orphan / empty', () => {
    const r = runTool('verify-qr-migration.ts', [`--db=${mysqlUrl(MY)}`, '--save', `--out=${dir}`]);
    assert.equal(r.code, 0, r.out);
    assert.equal(field(r.out, 'Attendees:'), '264');
    assert.equal(field(r.out, 'QR records:'), '264');
    assert.equal(field(r.out, 'Missing QR (active attendees):'), '0');
    assert.equal(field(r.out, 'Orphan QR records:'), '0');
    assert.equal(field(r.out, 'Empty tokens:'), '0');
    assert.equal(field(r.out, 'Duplicate tokens:'), '0');
    assert.match(r.out, /Note - tokens not 32 chars:\s+2/);
    manifest = join(dir, readdirSync(dir).find((f) => f.endsWith('.json')) as string);
  });

  test('PHP and TypeScript verifiers compute the same fingerprints (cross-tool parity)', (t) => {
    const php = spawnSync('php', [join(REPO, 'backend/cli/verify-qr-migration.php')], { encoding: 'utf8', env: { ...process.env, DB_DATABASE: MY } });
    if (php.error || /Could not connect|Access denied|SQLSTATE/.test(`${php.stdout}${php.stderr}`)) {
      t.skip('php CLI / app DB user cannot read the scratch database');
      return;
    }
    const m = JSON.parse(readFileSync(manifest, 'utf8'));
    assert.ok(php.stdout.includes(m.fingerprint), `PHP dataset SHA-256 differs:\n${php.stdout}`);
    assert.ok(php.stdout.includes(m.identity_fingerprint), 'PHP identity SHA-256 differs');
    // ...and the PHP tool accepts the manifest written by the TS tool.
    const cmp = spawnSync('php', [join(REPO, 'backend/cli/verify-qr-migration.php'), `--compare=${manifest}`], { encoding: 'utf8', env: { ...process.env, DB_DATABASE: MY } });
    assert.equal(cmp.status, 0, cmp.stdout);
  });

  test('import refuses an unexpected source count', () => {
    const r = runTool('import-mysql-to-postgres.ts', [`--mysql=${mysqlUrl(MY)}`, `--pg=${pgUrl(PG)}`, '--expect-attendees=263']);
    assert.equal(r.code, 1, r.out);
    assert.match(r.out, /REFUSED: Expected 263 attendees/);
  });

  test('dry run verifies everything and leaves the target empty', async () => {
    const r = runTool('import-mysql-to-postgres.ts', [`--mysql=${mysqlUrl(MY)}`, `--pg=${pgUrl(PG2)}`, '--dry-run']);
    assert.equal(r.code, 0, r.out);
    assert.match(r.out, /RESULT: PASS - dry run/);
    const c = await pgClient(PG2);
    for (const t of [...TABLES, 'migration_import_runs']) assert.equal((await c.query(`SELECT count(*)::int AS n FROM ${t}`)).rows[0].n, 0, t);
    await c.end();
  });

  test('import copies every table exactly (counts + per-table SHA-256) and records the run', async () => {
    const r = runTool('import-mysql-to-postgres.ts', [`--mysql=${mysqlUrl(MY)}`, `--pg=${pgUrl(PG)}`, '--expect-attendees=264', '--expect-qr=264']);
    assert.equal(r.code, 0, r.out);
    assert.match(r.out, /QR tokens identical:\s+YES/);
    assert.doesNotMatch(r.out, /MISMATCH/);
    for (const tk of tokens) assert.ok(!r.out.includes(tk), 'import output must not print tokens');
    p = await pgClient(PG);
    const run = (await p.query('SELECT * FROM migration_import_runs')).rows;
    assert.equal(run.length, 1);
    assert.equal(run[0].table_counts.attendees, 264);
    assert.equal(run[0].table_counts.attendee_qr_codes, 264);
    assert.equal(run[0].source_migrations.length, 32);
    // Independent re-check of the per-table fingerprints from fresh connections.
    const my = await connect(mysqlUrl(MY));
    const pgc = await connect(pgUrl(PG));
    for (const t of TABLES) {
      const a = await tableFingerprint(my, t, await mysqlColumns(my, t));
      const b = await tableFingerprint(pgc, t, await postgresColumns(pgc, t));
      assert.equal(b.count, a.count, `${t} count`);
      assert.equal(b.sha256, a.sha256, `${t} content`);
      assert.equal(run[0].table_fingerprints[t], a.sha256, `${t} recorded fingerprint`);
    }
    await my.close();
    await pgc.close();
  });

  test('QR tokens are byte-identical, attached to the same attendee ids and codes', async () => {
    const m = await mysqlAdmin(MY);
    const [rows] = await m.query('SELECT q.id, q.attendee_id, a.attendee_code, a.event_id, q.token, HEX(q.token) AS hx FROM attendee_qr_codes q JOIN attendees a ON a.id = q.attendee_id ORDER BY q.id');
    await m.end();
    const pgRows = (await p.query("SELECT q.id, q.attendee_id, a.attendee_code, a.event_id, q.token, upper(encode(convert_to(q.token, 'UTF8'), 'hex')) AS hx FROM attendee_qr_codes q JOIN attendees a ON a.id = q.attendee_id ORDER BY q.id")).rows;
    const src = rows as Record<string, unknown>[];
    assert.equal(pgRows.length, 264);
    assert.equal(src.length, 264);
    src.forEach((s, i) => {
      const d = pgRows[i];
      assert.deepEqual([d.id, d.attendee_id, d.attendee_code, d.event_id, d.token, d.hx], [Number(s.id), Number(s.attendee_id), s.attendee_code, Number(s.event_id), s.token, s.hx]);
    });
    assert.deepEqual(pgRows.map((r) => r.token).sort(), [...tokens].sort());
    const mixed = pgRows.filter((r) => /^[-_]/.test(r.token)).length;
    assert.ok(mixed >= 2, 'tokens starting with "-" / "_" survived');
  });

  test('verifier on PostgreSQL: integrity PASS and manifest compare PASS with matching fingerprint', () => {
    const integrity = runTool('verify-qr-migration.ts', [`--db=${pgUrl(PG)}`]);
    assert.equal(integrity.code, 0, integrity.out);
    for (const [label, want] of [['Attendees:', '264'], ['QR records:', '264'], ['Missing QR (active attendees):', '0'], ['Orphan QR records:', '0'], ['Empty tokens:', '0'], ['Invalid token format:', '0']]) {
      assert.equal(field(integrity.out, label as string), want, label);
    }
    const m = JSON.parse(readFileSync(manifest, 'utf8'));
    assert.ok(integrity.out.includes(m.fingerprint), 'PostgreSQL dataset fingerprint equals the MySQL manifest');
    const cmp = runTool('verify-qr-migration.ts', [`--db=${pgUrl(PG)}`, `--compare=${manifest}`]);
    assert.equal(cmp.code, 0, cmp.out);
    assert.equal(field(cmp.out, 'QR identity mappings preserved:'), '264/264');
    assert.equal(field(cmp.out, 'Manifest file checksum (.sha256):'), 'OK');
    assert.equal(field(cmp.out, 'Attendee IDs changed:'), '0 (reported only; not a failure)');
    assert.match(cmp.out, /RESULT: PASS/);
  });

  test('identity sequences continue after the imported ids (and after MySQL AUTO_INCREMENT)', async () => {
    const m = await mysqlAdmin(MY);
    const [ai] = await m.query("SELECT table_name AS t, auto_increment AS ai FROM information_schema.tables WHERE table_schema = DATABASE()");
    await m.end();
    const autoInc = new Map((ai as Record<string, unknown>[]).map((x) => [String(x.t), Number(x.ai)]));
    await p.query('BEGIN');
    for (const t of TABLES) {
      const max = Number((await p.query(`SELECT coalesce(max(id), 0) AS m FROM ${t}`)).rows[0].m);
      const seq = (await p.query(`SELECT pg_get_serial_sequence('public.${t}', 'id') AS s`)).rows[0].s;
      const next = Number((await p.query(`SELECT nextval('${seq}') AS n`)).rows[0].n);
      assert.equal(next, Math.max(max + 1, autoInc.get(t) ?? 1), `next id of ${t}`);
    }
    await p.query('ROLLBACK'); // nextval is not transactional, but nothing else uses this scratch DB
    assert.equal(autoInc.get('attendees'), 277, 'seed left AUTO_INCREMENT above max(id) on purpose');
    // A new row gets a fresh id instead of colliding with an imported one.
    await p.query('BEGIN');
    const ev = (await p.query("INSERT INTO events (name, event_date) VALUES ('new', '2026-12-01') RETURNING id")).rows[0].id;
    assert.ok(ev > 3);
    await p.query('ROLLBACK');
  });

  test('DATETIME values became the right instants (Asia/Manila wall time) and JSON is preserved', async () => {
    const r = (await p.query("SELECT created_at, updated_at, to_char(updated_at AT TIME ZONE 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') AS wall FROM users WHERE id = 1")).rows[0];
    assert.equal(r.created_at.toISOString(), '2025-12-31T16:00:00.000Z'); // 2026-01-01 00:00:00 Manila
    assert.equal(r.wall, '2026-01-02 00:30:00');
    const ev = (await p.query("SELECT to_char(event_date, 'YYYY-MM-DD') AS d, description FROM events WHERE id = 1")).rows[0];
    assert.deepEqual(ev, { d: '2026-10-10', description: 'Main | event\nline two' });
    const b = (await p.query('SELECT column_mapping, error_summary FROM import_batches ORDER BY id')).rows;
    assert.deepEqual(b[0].column_mapping, { name: 'Full Name', code: 'Employee No.', zeta: 1, alpha: [1, 2, { b: 'x', a: 'y' }] });
    assert.deepEqual(b[1].error_summary, [{ row: 2, error: 'Ünknown "name"' }]);
    const names = (await p.query("SELECT full_name FROM attendees WHERE full_name LIKE '  Spaces  %' OR full_name LIKE '%🎉%' OR full_name LIKE E'Line\\nBreak%'")).rows;
    assert.ok(names.length >= 3, 'whitespace, emoji and newlines kept as-is');
    const draws = (await p.query('SELECT id, voided_at IS NOT NULL AS v, reset_at IS NOT NULL AS r, reset_by FROM randomizer_draws ORDER BY id')).rows;
    assert.deepEqual(draws, [{ id: 1, v: false, r: false, reset_by: null }, { id: 2, v: true, r: false, reset_by: null }, { id: 3, v: false, r: true, reset_by: 1 }]);
  });

  test('a second import into the now non-empty target is refused', () => {
    const r = runTool('import-mysql-to-postgres.ts', [`--mysql=${mysqlUrl(MY)}`, `--pg=${pgUrl(PG)}`]);
    assert.equal(r.code, 1, r.out);
    assert.match(r.out, /REFUSED: Target table users is not empty/);
  });

  test('the MySQL source was not modified (CHECKSUM TABLE + AUTO_INCREMENT unchanged)', async () => {
    assert.equal(await mysqlChecksums(MY), checksumBefore);
  });

  // ---- Mutation scenarios: each change is applied, verified, then undone.
  const scenario = async (sql: string, undo: string): Promise<{ code: number; out: string }> => {
    await p.query(sql);
    try {
      return runTool('verify-qr-migration.ts', [`--db=${pgUrl(PG)}`, `--compare=${manifest}`]);
    } finally {
      await p.query(undo);
    }
  };
  const firstToken = async (code: string): Promise<string> =>
    (await p.query('SELECT q.token FROM attendee_qr_codes q JOIN attendees a ON a.id = q.attendee_id WHERE a.attendee_code = $1 AND a.event_id = 1', [code])).rows[0].token;

  test('FAIL when one token changes case', async () => {
    const t = await firstToken('HC-0010');
    const flipped = [...t].map((ch) => (ch === ch.toUpperCase() ? ch.toLowerCase() : ch.toUpperCase())).join('');
    const r = await scenario(`UPDATE attendee_qr_codes SET token = '${flipped}' WHERE token = '${t}'`, `UPDATE attendee_qr_codes SET token = '${t}' WHERE token = '${flipped}'`);
    assert.equal(r.code, 1);
    assert.equal(field(r.out, 'Changed QR tokens:'), '1');
  });

  test('FAIL when "-" becomes "_"', async () => {
    const t = await firstToken('HC-0001'); // starts with "-"
    const changed = `_${t.slice(1)}`;
    const r = await scenario(`UPDATE attendee_qr_codes SET token = '${changed}' WHERE token = '${t}'`, `UPDATE attendee_qr_codes SET token = '${t}' WHERE token = '${changed}'`);
    assert.equal(r.code, 1);
  });

  test('FAIL when two attendees swap tokens', async () => {
    const a = await firstToken('HC-0020');
    const b = await firstToken('HC-0021');
    // tokens are unique (not deferrable): swap through a temporary value
    const swap = (x: string, y: string): string => `UPDATE attendee_qr_codes SET token = 'tmp-swap-token-0000000000000000' WHERE token = '${x}';
      UPDATE attendee_qr_codes SET token = '${x}' WHERE token = '${y}';
      UPDATE attendee_qr_codes SET token = '${y}' WHERE token = 'tmp-swap-token-0000000000000000'`;
    const sql = swap(a, b);
    const r = await scenario(sql, sql);
    assert.equal(r.code, 1);
    assert.equal(field(r.out, 'Changed QR tokens:'), '2');
  });

  test('FAIL when a QR record is missing', async () => {
    const row = (await p.query("SELECT q.* FROM attendee_qr_codes q JOIN attendees a ON a.id = q.attendee_id WHERE a.attendee_code = 'HC-0030' AND a.event_id = 1")).rows[0];
    const r = await scenario(`DELETE FROM attendee_qr_codes WHERE id = ${row.id}`,
      `INSERT INTO attendee_qr_codes (id, attendee_id, token, created_at, updated_at) VALUES (${row.id}, ${row.attendee_id}, '${row.token}', '${row.created_at.toISOString()}', '${row.updated_at.toISOString()}')`);
    assert.equal(r.code, 1);
    assert.equal(field(r.out, 'Missing QR (active attendees):'), '1');
  });

  test('FAIL when an attendee code changes', async () => {
    const r = await scenario("UPDATE attendees SET attendee_code = 'HC-X040' WHERE attendee_code = 'HC-0040' AND event_id = 1",
      "UPDATE attendees SET attendee_code = 'HC-0040' WHERE attendee_code = 'HC-X040' AND event_id = 1");
    assert.equal(r.code, 1);
    assert.equal(field(r.out, 'Changed attendee codes:'), '1');
  });

  test('FAIL when an attendee moves to another event', async () => {
    const r = await scenario("UPDATE attendees SET event_id = 3 WHERE attendee_code = 'HC-0200' AND event_id = 1",
      "UPDATE attendees SET event_id = 1 WHERE attendee_code = 'HC-0200' AND event_id = 3");
    assert.equal(r.code, 1);
    assert.equal(field(r.out, 'Event mismatches:'), '1');
  });

  test('PASS (reported) when ids are renumbered or company changes - the printed QR still works', async () => {
    const before = (await p.query("SELECT company FROM attendees WHERE attendee_code = 'HC-0250'")).rows[0].company as string | null;
    await p.query("UPDATE attendees SET company = 'Renamed Co' WHERE attendee_code = 'HC-0250'");
    const r = await scenario('UPDATE attendees SET id = id + 5000 WHERE event_id = 3', 'UPDATE attendees SET id = id - 5000 WHERE event_id = 3 AND id > 5000');
    await p.query('UPDATE attendees SET company = $1 WHERE attendee_code = $2', [before, 'HC-0250']);
    assert.equal(r.code, 0, r.out);
    assert.equal(field(r.out, 'Attendee IDs changed:'), '24 (reported only; not a failure)');
    assert.equal(field(r.out, 'Company changed:'), '1 (reported only; does not affect the QR)');
  });

  test('after every scenario was undone the data matches the manifest again', () => {
    const r = runTool('verify-qr-migration.ts', [`--db=${pgUrl(PG)}`, `--compare=${manifest}`]);
    assert.equal(r.code, 0, r.out);
  });

  test('orphan, empty and whitespace-padded tokens cannot even be stored', async () => {
    for (const sql of ["INSERT INTO attendee_qr_codes (attendee_id, token) VALUES (999999, 'abcdefghijklmnopqrstuvwxyz-01234')",
      "UPDATE attendee_qr_codes SET token = '' WHERE id = 1001", "UPDATE attendee_qr_codes SET token = token || ' ' WHERE id = 1001"]) {
      await assert.rejects(p.query(sql));
    }
  });

  test('read-only: the verifier refuses to run statements that write (READ ONLY transaction)', async () => {
    const db = await connect(pgUrl(PG));
    await db.beginReadOnly();
    await assert.rejects(db.query("UPDATE events SET name = name WHERE id = 1"), /read-only transaction/);
    await db.rollback();
    const snap = await snapshot(db);
    assert.equal(snap.qr_count, 264);
    await db.close();
  });
});
