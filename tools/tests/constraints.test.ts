// Behaviour of the PostgreSQL constraints that protect QR tokens and event state.
// Each test runs in a transaction that is rolled back.
import { after, before, beforeEach, afterEach, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import type pg from 'pg';
import { createPgScratch, dropPg, pgClient, skipPg } from './helpers.ts';

const PG = 'holcim_migtest_constraints';

describe('PostgreSQL constraints', { skip: skipPg }, () => {
  let p: pg.Client;
  before(async () => {
    await createPgScratch(PG);
    p = await pgClient(PG);
  });
  after(async () => {
    await p?.end();
    await dropPg(PG);
  });
  beforeEach(async () => {
    await p.query('BEGIN');
    await p.query(`INSERT INTO events (id, name, event_date, status) VALUES (1, 'E1', '2026-10-10', 'active'), (2, 'E2', '2026-10-11', 'draft');
      INSERT INTO event_days (id, event_id, day_number, event_date, status) VALUES (1, 1, 1, '2026-10-10', 'active'), (2, 2, 1, '2026-10-11', 'upcoming');
      INSERT INTO attendees (id, event_id, attendee_code, full_name) VALUES (1, 1, 'HC-1', 'A'), (2, 1, 'HC-2', 'B'), (3, 2, 'HC-1', 'C');`);
  });
  afterEach(async () => {
    await p.query('ROLLBACK');
  });

  /** Runs sql inside a savepoint; returns the SQLSTATE on failure, or 'ok'. */
  const attempt = async (sql: string, params: unknown[] = []): Promise<string> => {
    await p.query('SAVEPOINT s');
    try {
      await p.query(sql, params);
      await p.query('RELEASE SAVEPOINT s');
      return 'ok';
    } catch (e) {
      await p.query('ROLLBACK TO SAVEPOINT s');
      return String((e as { code?: string }).code);
    }
  };
  const qr = (attendee: number, token: string): Promise<string> => attempt('INSERT INTO attendee_qr_codes (attendee_id, token) VALUES ($1, $2)', [attendee, token]);

  test('token is unique and CASE-SENSITIVE ("A" and "a" are different tokens)', async () => {
    assert.equal(await qr(1, 'AbCdEfGhIjKlMnOpQrStUvWx-_012345'), 'ok');
    assert.equal(await qr(2, 'aBcDeFgHiJkLmNoPqRsTuVwX-_012345'), 'ok');
    assert.equal(await qr(3, 'AbCdEfGhIjKlMnOpQrStUvWx-_012345'), '23505');
  });

  test('"-" and "_" are significant', async () => {
    assert.equal(await qr(1, 'abcdefghijklmnopqrstuvwxyz-01234'), 'ok');
    assert.equal(await qr(2, 'abcdefghijklmnopqrstuvwxyz_01234'), 'ok');
  });

  test('one QR per attendee', async () => {
    assert.equal(await qr(1, 'abcdefghijklmnopqrstuvwxyz-01234'), 'ok');
    assert.equal(await qr(1, 'Zbcdefghijklmnopqrstuvwxyz-01234'), '23505');
  });

  test('token CHECK refuses unscannable values and never alters stored ones', async () => {
    for (const bad of ['', ' abcdefghijklmnopqrstuvwxyz-0123', 'abcdefghijklmnopqrstuvwxyz-0123 ', 'abcdefghijklmnopqrstuvwxyz+01234', 'abcdefghijklmnopqrstuvwxyz/01234',
      'abcdefghijklmnopqrstuvwxyz=0123', 'short', 'x'.repeat(65), 'abcdefghijklmnop\nqrstuvwxyz']) {
      assert.equal(await qr(1, bad), '23514', JSON.stringify(bad));
    }
    assert.equal(await attempt('INSERT INTO attendee_qr_codes (attendee_id, token) VALUES (1, NULL)'), '23502');
    const legacy = 'A'.repeat(20) + '-_' + 'z'.repeat(21); // 43 chars (old format) is still accepted
    assert.equal(await qr(1, legacy), 'ok');
    const r = await p.query('SELECT token, length(token) AS n, token = $1 AS same FROM attendee_qr_codes WHERE attendee_id = 1', [legacy]);
    assert.deepEqual(r.rows[0], { token: legacy, n: 43, same: true });
  });

  test('orphan QR codes are impossible; deleting an attendee deletes its QR (as in MySQL)', async () => {
    assert.equal(await qr(99, 'abcdefghijklmnopqrstuvwxyz-01234'), '23503');
    assert.equal(await qr(2, 'abcdefghijklmnopqrstuvwxyz-01234'), 'ok');
    await p.query('DELETE FROM attendees WHERE id = 2');
    assert.equal((await p.query('SELECT count(*)::int AS n FROM attendee_qr_codes')).rows[0].n, 0);
  });

  test('renumbering an attendee id cascades to its QR (ON UPDATE CASCADE)', async () => {
    await qr(2, 'abcdefghijklmnopqrstuvwxyz-01234');
    await p.query('UPDATE attendees SET id = 500 WHERE id = 2');
    assert.equal((await p.query('SELECT attendee_id FROM attendee_qr_codes')).rows[0].attendee_id, 500);
  });

  test('only one active event and one active day per event', async () => {
    assert.equal(await attempt("UPDATE events SET status = 'active' WHERE id = 2"), '23505');
    assert.equal(await attempt("INSERT INTO event_days (id, event_id, day_number, event_date, status) VALUES (3, 1, 2, '2026-10-11', 'active')"), '23505');
    assert.equal(await attempt("INSERT INTO event_days (id, event_id, day_number, event_date, status) VALUES (3, 1, 2, '2026-10-11', 'upcoming')"), 'ok');
    assert.equal(await attempt("UPDATE event_days SET status = 'active' WHERE id = 2"), 'ok');
  });

  test('attendee code unique per event, case-insensitive like MySQL; free across events', async () => {
    assert.equal(await attempt("INSERT INTO attendees (id, event_id, attendee_code, full_name) VALUES (10, 1, 'hc-1', 'X')"), '23505');
    assert.equal(await attempt("INSERT INTO attendees (id, event_id, attendee_code, full_name) VALUES (11, 2, 'HC-2', 'X')"), 'ok');
  });

  test('user email / username unique case-insensitively', async () => {
    assert.equal(await attempt("INSERT INTO users (name, email, username, password_hash) VALUES ('a', 'A@x.com', 'Admin', 'h')"), 'ok');
    assert.equal(await attempt("INSERT INTO users (name, email, password_hash) VALUES ('b', 'a@X.com', 'h')"), '23505');
    assert.equal(await attempt("INSERT INTO users (name, username, password_hash) VALUES ('c', 'admin', 'h')"), '23505');
    assert.equal(await attempt("INSERT INTO users (name, password_hash, role) VALUES ('d', 'h', 'superuser')"), '23514');
  });

  test('composite FKs: a scan must use a day and attendee of the SAME event; one scan per day', async () => {
    assert.equal(await attempt('INSERT INTO registration_scans (event_id, event_day_id, attendee_id) VALUES (1, 2, 1)'), '23503');
    assert.equal(await attempt('INSERT INTO registration_scans (event_id, event_day_id, attendee_id) VALUES (1, 1, 3)'), '23503');
    assert.equal(await attempt('INSERT INTO registration_scans (event_id, event_day_id, attendee_id) VALUES (1, 1, 1)'), 'ok');
    assert.equal(await attempt('INSERT INTO registration_scans (event_id, event_day_id, attendee_id) VALUES (1, 1, 1)'), '23505');
  });

  test('history is protected: an event with attendees / a drawn attendee cannot be deleted', async () => {
    assert.equal(await attempt('DELETE FROM events WHERE id = 1'), '23503');
    await p.query("INSERT INTO randomizer_draws (event_id, event_day_id, attendee_id, randomizer_type) VALUES (1, 1, 1, 'minor')");
    assert.equal(await attempt('DELETE FROM attendees WHERE id = 1'), '23503');
  });

  test('updated_at: refreshed on change, kept when set explicitly or nothing changed', async () => {
    await p.query("UPDATE attendees SET updated_at = '2026-01-01 00:00:00+08' WHERE id = 1");
    const t0 = (await p.query('SELECT updated_at FROM attendees WHERE id = 1')).rows[0].updated_at as Date;
    assert.equal(t0.toISOString(), '2025-12-31T16:00:00.000Z', 'explicit value kept');
    await p.query('UPDATE attendees SET full_name = full_name WHERE id = 1');
    assert.equal((await p.query('SELECT updated_at FROM attendees WHERE id = 1')).rows[0].updated_at.toISOString(), t0.toISOString(), 'no-op update keeps it');
    await p.query("UPDATE attendees SET company = 'New' WHERE id = 1");
    assert.ok((await p.query('SELECT updated_at FROM attendees WHERE id = 1')).rows[0].updated_at > t0, 'real change refreshes it');
  });

  test('Manila wall time maps to the right instant (UTC+8, no DST)', async () => {
    const r = await p.query("SELECT ('2026-10-10 00:30:00'::timestamp AT TIME ZONE 'Asia/Manila') AS a, ('2026-06-01 12:00:00'::timestamp AT TIME ZONE 'Asia/Manila') AS b");
    assert.equal(r.rows[0].a.toISOString(), '2026-10-09T16:30:00.000Z');
    assert.equal(r.rows[0].b.toISOString(), '2026-06-01T04:00:00.000Z');
  });
});
