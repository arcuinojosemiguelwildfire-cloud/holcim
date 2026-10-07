// Migration 033 (MySQL) and its PostgreSQL twin on EXISTING data: every event day
// gets one default session that counts for Major, every existing check-in becomes a
// registration in it (so eligibility is exactly what it was), and no attendee,
// QR token, check-in or draw changes.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import type mysql from 'mysql2/promise';
import { applyMysqlMigration, applyPgMigration, createMysqlScratch, createPgScratch, dropMysql, dropPg, mysqlAdmin, pgClient, skipMysql, skipPg, token } from './helpers.ts';

const MY = 'holcim_migtest_sessions_my';
const PG = 'holcim_migtest_sessions_pg';
const SESSIONS_MIGRATION = '20261007090000';

const UNCHANGED = 'attendees, attendee_qr_codes, registration_scans, randomizer_draws, events, minor_manual_entries, major_eligibility';

describe('MySQL migration 033 backfill', { skip: skipMysql }, () => {
  let c: mysql.Connection;
  let checksums: string;
  before(async () => {
    await createMysqlScratch(MY, '032');
    c = await mysqlAdmin(MY);
    await c.query(`INSERT INTO users (id, name, email, password_hash, role) VALUES (1, 'Admin', 'a@example.com', 'x', 'admin');
      INSERT INTO events (id, name, event_date, status) VALUES (1, 'E', '2026-10-14', 'active'), (2, 'Old', '2025-01-01', 'archived');
      INSERT INTO event_days (id, event_id, day_number, event_date, status) VALUES (1, 1, 1, '2026-10-14', 'completed'), (2, 1, 2, '2026-10-15', 'active'), (3, 2, 1, '2025-01-01', 'completed');`);
    for (let i = 1; i <= 6; i++) {
      await c.query('INSERT INTO attendees (id, event_id, attendee_code, full_name) VALUES (?, ?, ?, ?)', [i, i <= 5 ? 1 : 2, `ATT-${i}`, `P${i}`]);
      await c.query('INSERT INTO attendee_qr_codes (attendee_id, token) VALUES (?, ?)', [i, token()]);
    }
    await c.query(`INSERT INTO registration_scans (event_id, event_day_id, attendee_id, qr_code_id, scanner_user_id, scanned_at) VALUES
      (1, 1, 1, 1, 1, '2026-10-14 17:05:00'), (1, 1, 2, 2, NULL, '2026-10-14 17:06:00'), (1, 2, 1, 1, 1, '2026-10-15 08:01:00'), (2, 3, 6, 6, 1, '2025-01-01 09:00:00')`);
    await c.query("INSERT INTO randomizer_draws (event_id, event_day_id, attendee_id, randomizer_type, drawn_by) VALUES (1, 1, 2, 'major', 1)");
    checksums = JSON.stringify((await c.query(`CHECKSUM TABLE ${UNCHANGED}`))[0]);
    await applyMysqlMigration(MY, '033');
  });
  after(async () => {
    await c?.end();
    await dropMysql(MY);
  });

  test('each existing day gets ONE default session: current, enabled, counts for Major, no start time', async () => {
    const [rows] = await c.query('SELECT event_day_id, label, start_time, is_current, is_enabled, counts_for_major, session_date FROM registration_sessions ORDER BY event_day_id');
    assert.deepEqual(rows, [
      { event_day_id: 1, label: 'Registration', start_time: null, is_current: 1, is_enabled: 1, counts_for_major: 1, session_date: '2026-10-14' },
      { event_day_id: 2, label: 'Registration', start_time: null, is_current: 1, is_enabled: 1, counts_for_major: 1, session_date: '2026-10-15' },
      { event_day_id: 3, label: 'Registration', start_time: null, is_current: 1, is_enabled: 1, counts_for_major: 1, session_date: '2025-01-01' },
    ]);
  });

  test('every existing check-in becomes an active scan registration with the same time and scanner', async () => {
    const [rows] = await c.query(`SELECT r.attendee_id, r.event_day_id, sr.registered_at = r.scanned_at AS same_time,
        sr.registered_by <=> r.scanner_user_id AS same_user, sr.status, sr.source
      FROM registration_scans r JOIN session_registrations sr ON sr.attendee_id = r.attendee_id AND sr.event_day_id = r.event_day_id ORDER BY r.id`);
    assert.equal((rows as unknown[]).length, 4);
    for (const row of rows as Record<string, unknown>[]) {
      assert.deepEqual([row.same_time, row.same_user, row.status, row.source], [1, 1, 'active', 'scan']);
    }
    const [[count]] = (await c.query('SELECT COUNT(*) AS n FROM session_registrations')) as unknown as [[{ n: number }]];
    assert.equal(Number(count.n), 4);
  });

  test('attendees, QR tokens, check-ins, draws and manual entries are unchanged', async () => {
    assert.equal(JSON.stringify((await c.query(`CHECKSUM TABLE ${UNCHANGED}`))[0]), checksums);
  });
});

describe('PostgreSQL registration-sessions migration backfill', { skip: skipPg }, () => {
  before(async () => {
    await createPgScratch(PG, true, SESSIONS_MIGRATION);
    const p = await pgClient(PG);
    await p.query(`INSERT INTO users (id, name, password_hash) VALUES (1, 'Admin', 'x');
      INSERT INTO events (id, name, event_date, status) VALUES (1, 'E', '2026-10-14', 'active');
      INSERT INTO event_days (id, event_id, day_number, event_date, status) VALUES (1, 1, 1, '2026-10-14', 'completed'), (2, 1, 2, '2026-10-15', 'active');
      INSERT INTO attendees (id, event_id, attendee_code, full_name) VALUES (1, 1, 'ATT-1', 'P1'), (2, 1, 'ATT-2', 'P2');
      INSERT INTO attendee_qr_codes (id, attendee_id, token) VALUES (1, 1, '${token()}'), (2, 2, '${token()}');
      INSERT INTO registration_scans (event_id, event_day_id, attendee_id, qr_code_id, scanner_user_id, scanned_at)
        VALUES (1, 1, 1, 1, 1, '2026-10-14 17:05:00+08'), (1, 2, 2, 2, NULL, '2026-10-15 08:00:00+08');`);
    await p.end();
    await applyPgMigration(PG, SESSIONS_MIGRATION);
  });
  after(async () => {
    await dropPg(PG);
  });

  test('default sessions + registrations backfilled exactly like MySQL 033', async () => {
    const p = await pgClient(PG);
    const s = (await p.query('SELECT event_day_id, label, start_time, is_current, counts_for_major FROM registration_sessions ORDER BY event_day_id')).rows;
    assert.deepEqual(s, [
      { event_day_id: 1, label: 'Registration', start_time: null, is_current: true, counts_for_major: true },
      { event_day_id: 2, label: 'Registration', start_time: null, is_current: true, counts_for_major: true },
    ]);
    const r = (await p.query(`SELECT sr.attendee_id, sr.registered_at = rs.scanned_at AS same_time, sr.source, sr.status
      FROM session_registrations sr JOIN registration_scans rs ON rs.attendee_id = sr.attendee_id AND rs.event_day_id = sr.event_day_id ORDER BY sr.attendee_id`)).rows;
    assert.deepEqual(r, [{ attendee_id: 1, same_time: true, source: 'scan', status: 'active' }, { attendee_id: 2, same_time: true, source: 'scan', status: 'active' }]);
    const rls = (await p.query("SELECT bool_and(relrowsecurity) AS ok FROM pg_class WHERE relname IN ('registration_sessions', 'session_registrations', 'session_registration_changes')")).rows[0];
    assert.equal(rls.ok, true);
    await p.end();
  });
});
