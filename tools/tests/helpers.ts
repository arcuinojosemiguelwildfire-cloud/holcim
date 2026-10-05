// Shared helpers for the Phase 1 migration tests. They use THROWAWAY databases only:
//   TEST_MYSQL_ADMIN_URL  mysql://user:pass@127.0.0.1:3306        (may CREATE/DROP holcim_migtest_* databases)
//   TEST_PG_ADMIN_URL     postgresql://postgres@localhost:5432/postgres (may CREATE/DROP holcim_migtest_* databases)
// Every scratch name must start with "holcim_migtest_"; nothing else is ever created or dropped.
// Without these variables the database tests are skipped (pure unit tests still run).
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import mysql from 'mysql2/promise';
import pg from 'pg';

export const TOOLS = join(dirname(fileURLToPath(import.meta.url)), '..');
export const REPO = join(TOOLS, '..');
export const MYSQL_ADMIN = process.env.TEST_MYSQL_ADMIN_URL ?? '';
export const PG_ADMIN = process.env.TEST_PG_ADMIN_URL ?? '';
export const skipMysql = MYSQL_ADMIN ? false : 'TEST_MYSQL_ADMIN_URL not set';
export const skipPg = PG_ADMIN ? false : 'TEST_PG_ADMIN_URL not set';
export const skipBoth = skipMysql || skipPg;

function guard(name: string): void {
  if (!/^holcim_migtest_[a-z0-9_]+$/.test(name)) throw new Error(`Refusing to touch non-scratch database ${name}`);
}

export function mysqlUrl(db: string): string {
  const u = new URL(MYSQL_ADMIN);
  u.pathname = `/${db}`;
  return u.toString();
}
export function pgUrl(db: string): string {
  const u = new URL(PG_ADMIN);
  u.pathname = `/${db}`;
  return u.toString();
}

export async function mysqlAdmin(db?: string): Promise<mysql.Connection> {
  return mysql.createConnection({ uri: db ? mysqlUrl(db) : MYSQL_ADMIN, multipleStatements: true, dateStrings: true, charset: 'utf8mb4_unicode_ci' });
}

/** Fresh MySQL database migrated with database/migrations/*.sql, exactly like backend/cli/migrate.php. */
export async function createMysqlScratch(name: string): Promise<void> {
  guard(name);
  const admin = await mysqlAdmin();
  await admin.query(`DROP DATABASE IF EXISTS \`${name}\``);
  await admin.query(`CREATE DATABASE \`${name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
  await admin.end();
  const c = await mysqlAdmin(name);
  await c.query(`CREATE TABLE schema_migrations (id INT UNSIGNED NOT NULL AUTO_INCREMENT, migration VARCHAR(255) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uq_schema_migrations_migration (migration))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
  const dir = join(REPO, 'database', 'migrations');
  for (const f of readdirSync(dir).filter((x) => x.endsWith('.sql')).sort()) {
    await c.query(readFileSync(join(dir, f), 'utf8'));
    await c.query('INSERT INTO schema_migrations (migration) VALUES (?)', [f]);
  }
  await c.end();
}

export async function dropMysql(name: string): Promise<void> {
  guard(name);
  const admin = await mysqlAdmin();
  await admin.query(`DROP DATABASE IF EXISTS \`${name}\``);
  await admin.end();
}

export async function pgClient(db: string): Promise<pg.Client> {
  const c = new pg.Client({ connectionString: pgUrl(db) });
  await c.connect();
  return c;
}

/** Fresh PostgreSQL database with supabase/migrations/*.sql applied in order. */
export async function createPgScratch(name: string, applyMigrations = true): Promise<void> {
  guard(name);
  const admin = new pg.Client({ connectionString: PG_ADMIN });
  await admin.connect();
  await admin.query(`DROP DATABASE IF EXISTS "${name}" WITH (FORCE)`);
  await admin.query(`CREATE DATABASE "${name}"`);
  for (const role of ['anon', 'authenticated', 'service_role']) {
    // Supabase has these roles; create them locally so the grants are testable.
    await admin.query(`DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${role}') THEN CREATE ROLE ${role} NOLOGIN; END IF; END $$`);
  }
  await admin.end();
  if (!applyMigrations) return;
  const c = await pgClient(name);
  // Supabase grants the API roles access to public by default; mimic that so the
  // security baseline migration is what removes it.
  await c.query('GRANT USAGE ON SCHEMA public TO anon, authenticated; ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO anon, authenticated; ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO anon, authenticated; ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO anon, authenticated');
  const dir = join(REPO, 'supabase', 'migrations');
  for (const f of readdirSync(dir).filter((x) => x.endsWith('.sql')).sort()) await c.query(readFileSync(join(dir, f), 'utf8'));
  await c.end();
}

export async function dropPg(name: string): Promise<void> {
  guard(name);
  const admin = new pg.Client({ connectionString: PG_ADMIN });
  await admin.connect();
  await admin.query(`DROP DATABASE IF EXISTS "${name}" WITH (FORCE)`);
  await admin.end();
}

export interface ToolRun { code: number; out: string }
export function runTool(script: string, args: string[], env: Record<string, string> = {}): ToolRun {
  const r = spawnSync(process.execPath, [join(TOOLS, script), ...args], { encoding: 'utf8', env: { ...process.env, ...env } });
  return { code: r.status ?? -1, out: `${r.stdout}${r.stderr}` };
}

/** Value printed on a "Label:   value" line of the tools' output. */
export function field(out: string, label: string): string | undefined {
  const line = out.split('\n').find((l) => l.startsWith(label));
  return line?.slice(label.length).trim();
}

export const token = (): string => randomBytes(24).toString('base64url');

/**
 * Seeds a migrated MySQL scratch database with realistic synthetic data in EVERY table:
 * 264 active attendees each with one QR (two events; tokens include "-", "_" and mixed
 * case; two legacy 43-char tokens), Unicode / pipes / backslashes / newlines in text,
 * JSON columns, scans, logs, draws (voided and reset), audit and login history.
 * AUTO_INCREMENT of attendees and QR is left above max(id) (deleted rows) on purpose.
 */
export async function seedMysql(name: string): Promise<{ tokens: string[] }> {
  guard(name);
  const c = await mysqlAdmin(name);
  const hash = '$2y$10$abcdefghijklmnopqrstuuJ0yWl4wP2xHqCjv5Cq8o8E3cN0m1y1G';
  await c.query('INSERT INTO users (id, name, email, username, password_hash, session_version, role, status, last_login_at, created_at, updated_at) VALUES ?', [[
    [1, 'Admin Ñandú', 'Admin@Example.com', 'admin', hash, 3, 'admin', 'active', '2026-09-30 23:59:59', '2026-01-01 00:00:00', '2026-01-02 00:30:00'],
    [2, 'Desk "One"', 'desk1@example.com', 'desk1', hash, 0, 'registration_staff', 'active', null, '2026-01-01 08:00:00', '2026-01-01 08:00:00'],
    [3, 'Scanner\\Two', null, 'scan2', hash, 1, 'scanner_operator', 'inactive', null, '2026-01-01 08:00:00', '2026-01-01 08:00:00'],
    [5, 'Operator', 'op@example.com', null, hash, 0, 'event_operator', 'active', null, '2026-01-01 08:00:00', '2026-01-01 08:00:00'],
  ]]);
  await c.query('INSERT INTO events (id, name, description, event_date, status, created_at, updated_at) VALUES ?', [[
    [1, 'Holcim Annual Gathering 2026', 'Main | event\nline two', '2026-10-10', 'active', '2026-09-01 09:00:00', '2026-09-01 09:00:00'],
    [3, 'Archived rehearsal', null, '2026-08-01', 'archived', '2026-07-01 00:00:00', '2026-08-02 00:00:00'],
  ]]);
  await c.query('INSERT INTO event_days (id, event_id, day_number, event_date, label, status, created_at, updated_at) VALUES ?', [[
    [1, 1, 1, '2026-10-10', 'Day 1', 'completed', '2026-09-01 09:00:00', '2026-10-10 18:00:00'],
    [2, 1, 2, '2026-10-11', 'Day 2', 'active', '2026-09-01 09:00:00', '2026-10-11 07:00:00'],
    [4, 3, 1, '2026-08-01', null, 'completed', '2026-07-01 00:00:00', '2026-08-01 00:00:00'],
  ]]);
  await c.query('INSERT INTO import_batches (id, event_id, event_day_id, import_type, original_filename, status, total_rows, successful_rows, failed_rows, column_mapping, error_summary, imported_by, created_at, completed_at) VALUES ?', [[
    [1, 1, null, 'attendees', 'Attendees – final (v2).xlsx', 'completed', 250, 250, 0, JSON.stringify({ name: 'Full Name', code: 'Employee No.', zeta: 1, alpha: [1, 2, { b: 'x', a: 'y' }] }), null, 1, '2026-09-02 10:00:00', '2026-09-02 10:00:05'],
    [2, 1, 2, 'major_entries', 'major.csv', 'failed', 3, 1, 2, '{"b": 2, "a": 1}', '[{"row": 2, "error": "Ünknown \\"name\\""}]', 5, '2026-09-03 10:00:00', null],
  ]]);

  const tokens: string[] = [];
  const attendees: unknown[][] = [];
  const qrs: unknown[][] = [];
  const specials = ['José Rizal Ñ', 'O\'Brien | Ops', 'Back\\slash', 'Line\nBreak', '李小龍', 'Émoji 🎉', '  Spaces  ', 'Tab\tName'];
  for (let i = 1; i <= 266; i++) {
    const id = i <= 200 ? i : i + 10; // gap in ids
    const event = i <= 240 ? 1 : 3;
    let t = token();
    if (i === 1) t = `-${t.slice(1)}`;
    if (i === 2) t = `_${t.slice(1)}`;
    if (i === 3) t = 'AbCdEfGhIjKlMnOpQrStUvWx-_0123456';
    if (i === 3) t = t.slice(0, 32);
    if (i === 4) t = 'aBcDeFgHiJkLmNoPqRsTuVwX-_012345'; // same letters, other case than #3
    if (i === 5 || i === 6) t = randomBytes(32).toString('base64url'); // legacy 43-char
    tokens.push(t);
    attendees.push([id, event, `HC-${String(i).padStart(4, '0')}`, specials[i % specials.length] + ` ${i}`, i % 7 === 0 ? null : `Company ${i % 13}`,
      i % 5 === 0 ? null : `Cluster ${i % 4}`, i % 3 === 0 ? `p${i}@example.com` : null, i % 2 === 0 ? `EMP-${i}` : null, 'active', null,
      i % 9 === 0 ? JSON.stringify({ shirt: 'L', notes: `n${i}`, nested: { z: 1, a: [true, null, 1.5] } }) : null, event === 1 ? 1 : null,
      '2026-09-02 10:00:01', i % 11 === 0 ? '2026-09-20 16:45:30' : '2026-09-02 10:00:01']);
    qrs.push([id + 1000, id, t, '2026-09-02 10:00:02', '2026-09-02 10:00:02']);
  }
  await c.query('INSERT INTO attendees (id, event_id, attendee_code, full_name, company, department, email, external_identifier, status, archived_at, extra_data, import_batch_id, created_at, updated_at) VALUES ?', [attendees]);
  await c.query('INSERT INTO attendee_qr_codes (id, attendee_id, token, created_at, updated_at) VALUES ?', [qrs]);
  // Two deleted rows leave AUTO_INCREMENT above max(id): the import must not reuse those ids.
  await c.query('DELETE FROM attendees WHERE id IN (275, 276)'); // cascades their QR codes
  tokens.splice(264, 2);

  const scans: unknown[][] = [];
  const logs: unknown[][] = [];
  for (let i = 1; i <= 120; i++) {
    const day = i <= 70 ? 1 : 2;
    scans.push([i, 1, day, i, i + 1000, i % 2 ? 2 : 3, `2026-10-1${day === 1 ? 0 : 1} 0${i % 9}:${String(i % 60).padStart(2, '0')}:00`, '2026-10-10 00:00:00']);
    logs.push([i, 1, day, i % 2 ? 2 : null, i, i % 10 === 0 ? 'already_registered' : 'registered', '2026-10-10 00:15:00']);
  }
  logs.push([121, 1, 2, 2, null, 'invalid_qr', '2026-10-11 23:59:59']);
  await c.query('INSERT INTO registration_scans (id, event_id, event_day_id, attendee_id, qr_code_id, scanner_user_id, scanned_at, created_at) VALUES ?', [scans]);
  await c.query('INSERT INTO scan_logs (id, event_id, event_day_id, user_id, attendee_id, result, scanned_at) VALUES ?', [logs]);
  await c.query('INSERT INTO minor_manual_entries (id, event_id, event_day_id, attendee_id, added_by, reason, created_at) VALUES ?', [[
    [1, 1, 1, 150, 1, 'Late arrival – manual', '2026-10-10 12:00:00'], [2, 1, 2, 151, null, null, '2026-10-11 12:00:00']]]);
  await c.query('INSERT INTO major_eligibility (id, event_id, event_day_id, attendee_id, import_batch_id, source, added_by, reason, imported_at) VALUES ?', [[
    [1, 1, 2, 10, 2, 'import', 5, null, '2026-09-03 10:00:00'], [2, 1, 2, 11, null, 'manual', 1, 'VIP', '2026-10-11 09:00:00']]]);
  await c.query('INSERT INTO major_entries (id, event_id, attendee_id, full_name, department, email, external_identifier, response_data, submitted_at, import_batch_id, created_at, updated_at) VALUES ?', [[
    [1, 1, 10, 'Entry One', 'Cluster 1', null, 'EMP-10', '{"q1": "yes", "q0": ["a", "b"]}', '2026-09-03 08:00:00', 2, '2026-09-03 10:00:00', '2026-09-03 10:00:00']]]);
  await c.query('INSERT INTO randomizer_draws (id, event_id, event_day_id, attendee_id, randomizer_type, drawn_by, selected_at, voided_at, voided_by, void_reason, reset_at, reset_by) VALUES ?', [[
    [1, 1, 1, 20, 'minor', 1, '2026-10-10 15:00:00', null, null, null, null, null],
    [2, 1, 1, 21, 'minor', 1, '2026-10-10 15:01:00', '2026-10-10 15:02:00', 1, 'Not present', null, null],
    [3, 1, 2, 10, 'major', 5, '2026-10-11 20:00:00', null, null, null, '2026-10-11 21:00:00', 1],
  ]]);
  const audit: unknown[][] = [];
  for (let i = 1; i <= 40; i++) {
    audit.push([i, i % 3 ? 1 : null, i % 4 ? 1 : null, `action.${i % 5}`, i % 2 ? `Description ${i} | ü` : null,
      i % 2 ? JSON.stringify({ i, list: [3, 2, 1], deep: { y: 'ÿ', x: null } }) : null, i % 2 ? '192.168.1.10' : '::1', 'Mozilla/5.0 (Test)', `2026-09-${String(10 + (i % 18)).padStart(2, '0')} 0${i % 10}:00:00`]);
  }
  await c.query('INSERT INTO audit_logs (id, user_id, event_id, action, description, metadata, ip_address, user_agent, created_at) VALUES ?', [audit]);
  await c.query('INSERT INTO login_attempts (id, email_hash, ip_address, attempted_at) VALUES ?', [[
    [7, 'a'.repeat(64), '10.0.0.1', '2026-10-01 00:00:00'], [8, 'b'.repeat(64), '10.0.0.2', '2026-10-01 00:00:01']]]);
  await c.end();
  return { tokens };
}

/** MySQL CHECKSUM TABLE of every table: proves the source was not modified. */
export async function mysqlChecksums(name: string): Promise<string> {
  const c = await mysqlAdmin(name);
  const [rows] = await c.query('CHECKSUM TABLE users, events, event_days, import_batches, attendees, attendee_qr_codes, registration_scans, scan_logs, minor_manual_entries, major_eligibility, major_entries, randomizer_draws, audit_logs, login_attempts, schema_migrations');
  const [ai] = await c.query('SELECT table_name, auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name');
  await c.end();
  return JSON.stringify([rows, ai]);
}
