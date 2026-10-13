// Phase 2 security tests: Supabase Auth profiles, roles, RLS and role-checked RPCs.
//
// Part 1 (SQL) runs every query exactly as PostgREST does: SET ROLE anon|authenticated
// plus the verified JWT payload in request.jwt.claims. Part 2 (REST) starts a real
// PostgREST in front of the same database (TEST_POSTGREST_BIN) and attacks it with
// HTTP requests and forged / expired tokens. The frontend is NOT the security boundary.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, type ChildProcess } from 'node:child_process';
import { createHmac, randomUUID } from 'node:crypto';
import { createServer } from 'node:net';
import type pg from 'pg';
import { createPgScratch, dropPg, pgClient, pgUrl, skipPg, token } from './helpers.ts';

const DB = 'holcim_migtest_auth_rls';
const POSTGREST = process.env.TEST_POSTGREST_BIN ?? '';
const JWT_SECRET = 'test-only-jwt-secret-' + 'x'.repeat(32);

type Persona = 'admin' | 'admin2' | 'staff' | 'scanner' | 'operator' | 'inactiveAdmin' | 'selfSignup' | 'unknown';
const PERSONAS: Persona[] = ['admin', 'admin2', 'staff', 'scanner', 'operator', 'inactiveAdmin', 'selfSignup', 'unknown'];
const ROLE_OF: Partial<Record<Persona, string>> = {
  admin: 'admin', admin2: 'admin', staff: 'registration_staff', scanner: 'scanner_operator', operator: 'event_operator',
};

// Which roles may SELECT which table (mirrors the PHP route groups).
const STAFF = ['admin', 'registration_staff', 'event_operator'];
const SCANNERS = ['admin', 'registration_staff', 'scanner_operator'];
const RANDOMIZER = ['admin', 'event_operator'];
const ADMIN = ['admin'];
const SELECT_MATRIX: Record<string, string[]> = {
  events: STAFF, event_days: ADMIN, registration_sessions: ADMIN, attendees: STAFF,
  session_registrations: STAFF, session_registration_changes: STAFF,
  registration_scans: SCANNERS, scan_logs: SCANNERS,
  minor_manual_entries: RANDOMIZER, major_eligibility: RANDOMIZER, randomizer_draws: RANDOMIZER, predrawn_winners: RANDOMIZER, randomizer_pool_sessions: RANDOMIZER,
  major_entries: ADMIN, import_batches: ADMIN, audit_logs: ADMIN, migration_import_runs: ADMIN,
};
const NO_CLIENT_ACCESS = ['users', 'login_attempts', 'attendee_qr_codes'];

describe('Supabase auth, roles and RLS (SQL as PostgREST runs it)', { skip: skipPg }, () => {
  let p: pg.Client;
  const ids = {} as Record<Persona, string>;
  const seeded: Record<string, number> = {};
  const qrTokens: string[] = [];

  before(async () => {
    await createPgScratch(DB);
    p = await pgClient(DB);
    await p.query(`
      INSERT INTO users (id, name, email, username, password_hash, role, status) VALUES
        (1, 'Legacy Admin', 'admin@example.com', NULL, '$2y$12$abcdefghijklmnopqrstuuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0', 'admin', 'active'),
        (2, 'Gate 1', NULL, 'gate-1', '$2y$12$abcdefghijklmnopqrstuuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1', 'scanner_operator', 'active');
      INSERT INTO events (id, name, event_date, status) VALUES (1, 'Holcim 2026', '2026-10-14', 'active');
      INSERT INTO event_days (id, event_id, day_number, event_date, status) VALUES (1, 1, 1, '2026-10-14', 'active');
      INSERT INTO registration_sessions (id, event_id, event_day_id, label, session_date, start_time, is_current, counts_for_major)
        VALUES (1, 1, 1, 'Oct 14 - 5:00 PM', '2026-10-14', '17:00', true, true);
      INSERT INTO import_batches (id, event_id, import_type, original_filename, status) VALUES (1, 1, 'attendees', 'a.xlsx', 'completed');
      INSERT INTO attendees (id, event_id, attendee_code, full_name, email) VALUES
        (1, 1, 'ATT-0001', 'Juan Dela Cruz', 'juan@example.com'), (2, 1, 'ATT-0002', 'Maria Santos', NULL), (3, 1, 'ATT-0003', 'Jose Reyes', NULL);
      INSERT INTO registration_scans (id, event_id, event_day_id, attendee_id, scanner_user_id) VALUES (1, 1, 1, 1, 2);
      INSERT INTO scan_logs (id, event_id, event_day_id, registration_session_id, user_id, attendee_id, result) VALUES (1, 1, 1, 1, 2, 1, 'registered');
      INSERT INTO session_registrations (id, event_id, event_day_id, registration_session_id, attendee_id, source, registered_by) VALUES (1, 1, 1, 1, 1, 'scan', 2);
      INSERT INTO session_registration_changes (id, session_registration_id, event_id, action, status_after) VALUES (1, 1, 1, 'scanned', 'active');
      INSERT INTO minor_manual_entries (id, event_id, event_day_id, attendee_id) VALUES (1, 1, 1, 2);
      INSERT INTO major_eligibility (id, event_id, event_day_id, attendee_id, source) VALUES (1, 1, 1, 3, 'manual');
      INSERT INTO major_entries (id, event_id, full_name) VALUES (1, 1, 'Legacy');
      INSERT INTO randomizer_draws (id, event_id, event_day_id, attendee_id, randomizer_type) VALUES (1, 1, 1, 1, 'minor');
      INSERT INTO predrawn_winners (id, event_id, event_day_id, letter, attendee_id, prize_label) VALUES (1, 1, 1, 'A', 2, 'Prize');
      INSERT INTO randomizer_pool_sessions (id, event_id, event_day_id, registration_session_id) VALUES (1, 1, 1, 1);
      INSERT INTO audit_logs (action) VALUES ('seed');
      INSERT INTO login_attempts (id, email_hash, ip_address) VALUES (1, repeat('a', 64), '10.0.0.1');
      INSERT INTO migration_import_runs (source_database, source_migrations, table_counts, table_fingerprints, qr_fingerprint, qr_identity_fingerprint, started_at)
        VALUES ('x', '[]', '{}', '{}', 'f', 'i', now());`);
    for (let i = 1; i <= 3; i++) {
      const t = token();
      qrTokens.push(t);
      await p.query('INSERT INTO attendee_qr_codes (attendee_id, token) VALUES ($1, $2)', [i, t]);
    }
    // Auth users as the Supabase Admin API (service role) would create them.
    const create = async (persona: Persona, email: string, app: object, userMeta: object = {}): Promise<void> => {
      const r = await p.query('INSERT INTO auth.users (email, raw_app_meta_data, raw_user_meta_data) VALUES ($1, $2, $3) RETURNING id',
        [email, JSON.stringify(app), JSON.stringify(userMeta)]);
      ids[persona] = r.rows[0].id;
    };
    await create('admin', 'admin@example.com', { role: 'admin', display_name: 'Admin', legacy_user_id: 1 });
    await create('admin2', 'admin2@example.com', { role: 'admin', display_name: 'Second Admin' });
    await create('staff', 'staff@example.com', { role: 'registration_staff', display_name: 'Desk Staff' });
    await create('scanner', 'gate-1@scanner.holcim-event.invalid', { role: 'scanner_operator', display_name: 'Gate 1', username: 'gate-1', legacy_user_id: 2 });
    await create('operator', 'operator@example.com', { role: 'event_operator', display_name: 'Operator' });
    await create('inactiveAdmin', 'old-admin@example.com', { role: 'admin', display_name: 'Old Admin', active: false });
    // Self sign-up that tries to grant itself admin through user-editable metadata.
    await create('selfSignup', 'attacker@example.com', { provider: 'email', providers: ['email'] }, { role: 'admin', active: true });
    ids.unknown = randomUUID(); // a well-formed JWT subject with no auth user / profile
    for (const t of Object.keys(SELECT_MATRIX)) seeded[t] = Number((await p.query(`SELECT count(*) AS n FROM public.${t}`)).rows[0].n);
  });
  after(async () => {
    await p?.end();
    await dropPg(DB);
  });

  /** Runs SQL as PostgREST would for this persona (null = anonymous), always rolled back. */
  const as = async (who: Persona | null, sql: string, params: unknown[] = []): Promise<{ rows: Record<string, unknown>[]; code?: string }> => {
    await p.query('BEGIN');
    try {
      await p.query(`SET LOCAL ROLE ${who === null ? 'anon' : 'authenticated'}`);
      const claims = who === null ? { role: 'anon' } : { sub: ids[who], role: 'authenticated', aud: 'authenticated' };
      await p.query("SELECT set_config('request.jwt.claims', $1, true)", [JSON.stringify(claims)]);
      const r = await p.query(sql, params);
      return { rows: r.rows };
    } catch (e) {
      return { rows: [], code: (e as { code?: string }).code };
    } finally {
      await p.query('ROLLBACK');
    }
  };
  /** Same, but COMMITs (for admin RPCs whose effect is checked afterwards). */
  const asCommit = async (who: Persona, sql: string, params: unknown[] = []): Promise<{ rows: Record<string, unknown>[]; code?: string; message?: string }> => {
    await p.query('BEGIN');
    try {
      await p.query('SET LOCAL ROLE authenticated');
      await p.query("SELECT set_config('request.jwt.claims', $1, true)", [JSON.stringify({ sub: ids[who], role: 'authenticated' })]);
      const r = await p.query(sql, params);
      await p.query('COMMIT');
      return { rows: r.rows };
    } catch (e) {
      await p.query('ROLLBACK');
      return { rows: [], code: (e as { code?: string }).code, message: (e as Error).message };
    }
  };

  test('profiles are created only from app_metadata; user-editable metadata cannot grant a role', async () => {
    const rows = (await p.query('SELECT id, role, is_active, username, legacy_user_id FROM profiles')).rows;
    const by = (who: Persona) => rows.find((r) => r.id === ids[who]);
    assert.deepEqual([by('admin')?.role, by('admin')?.is_active, by('admin')?.legacy_user_id], ['admin', true, 1]);
    assert.deepEqual([by('scanner')?.role, by('scanner')?.username, by('scanner')?.legacy_user_id], ['scanner_operator', 'gate-1', 2]);
    assert.deepEqual([by('inactiveAdmin')?.role, by('inactiveAdmin')?.is_active], ['admin', false]);
    assert.deepEqual([by('selfSignup')?.role, by('selfSignup')?.is_active], ['scanner_operator', false], 'self sign-up: no access');
    const cols = (await p.query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'profiles'")).rows.map((r) => r.column_name);
    assert.ok(!cols.some((c: string) => /pass|hash|email/.test(c)), 'profiles hold no password, hash or email');
  });

  test('anonymous: no table, no RPC, no QR token', async () => {
    for (const t of [...Object.keys(SELECT_MATRIX), ...NO_CLIENT_ACCESS, 'profiles']) {
      assert.equal((await as(null, `SELECT * FROM public.${t} LIMIT 1`)).code, '42501', `anon SELECT ${t}`);
    }
    for (const fn of ['current_user_context()', 'current_event_context()', 'record_sign_in()', 'admin_list_users()',
      `admin_set_user_role('${randomUUID()}', 'admin')`, `admin_set_user_active('${randomUUID()}', true)`]) {
      assert.equal((await as(null, `SELECT public.${fn}`)).code, '42501', `anon ${fn}`);
    }
    assert.equal((await as(null, "UPDATE public.attendees SET full_name = 'x'")).code, '42501');
    assert.equal((await as(null, "INSERT INTO public.session_registrations (event_id, event_day_id, registration_session_id, attendee_id, source) VALUES (1,1,1,2,'manual')")).code, '42501');
    assert.equal((await as(null, "INSERT INTO public.randomizer_draws (event_id, event_day_id, attendee_id, randomizer_type) VALUES (1,1,2,'major')")).code, '42501');
    assert.equal((await as(null, 'SELECT private.is_admin()')).code, '42501', 'anon cannot use the private schema');
  });

  test('SELECT matrix: each role sees exactly the tables its PHP routes allow', async () => {
    for (const who of PERSONAS) {
      const role = ROLE_OF[who];
      for (const [t, allowed] of Object.entries(SELECT_MATRIX)) {
        const r = await as(who, `SELECT count(*)::int AS n FROM public.${t}`);
        const expected = role !== undefined && allowed.includes(role) ? seeded[t] : 0;
        assert.equal(r.code, undefined, `${who} ${t}`);
        assert.equal(r.rows[0]?.n, expected, `${who} on ${t}: ${expected === 0 ? 'no rows' : 'all rows'}`);
      }
    }
  });

  test('QR tokens and legacy credentials cannot be read by ANY client role (admin included)', async () => {
    for (const who of PERSONAS) {
      for (const t of NO_CLIENT_ACCESS) {
        assert.equal((await as(who, `SELECT * FROM public.${t} LIMIT 1`)).code, '42501', `${who} ${t}`);
      }
      assert.equal((await as(who, 'SELECT a.full_name, (SELECT q.token FROM public.attendee_qr_codes q WHERE q.attendee_id = a.id) FROM public.attendees a')).code, '42501');
      assert.equal((await as(who, 'SELECT count(*) FROM public.attendee_qr_codes WHERE token = $1', [qrTokens[0]])).code, '42501', `${who} token probe`);
    }
    for (const fn of ['public.generate_qr_token()', 'public.set_updated_at()']) {
      const r = await p.query("SELECT has_function_privilege('authenticated', $1, 'execute') AS x", [fn]);
      assert.equal(r.rows[0].x, false, fn);
    }
  });

  test('no client writes at all: role/active payloads, attendee/event ids, registrations, draws, fake audit', async () => {
    const writes = [
      `UPDATE public.profiles SET role = 'admin'`,
      `UPDATE public.profiles SET is_active = true`,
      `INSERT INTO public.profiles (id, display_name, role, is_active) VALUES ('${randomUUID()}', 'x', 'admin', true)`,
      `UPDATE public.attendees SET event_id = 2 WHERE id = 1`,
      `UPDATE public.attendees SET id = 99 WHERE id = 1`,
      `UPDATE public.events SET status = 'archived'`,
      `INSERT INTO public.events (name, event_date) VALUES ('x', '2026-01-01')`,
      `DELETE FROM public.attendees`,
      `INSERT INTO public.session_registrations (event_id, event_day_id, registration_session_id, attendee_id, source) VALUES (1,1,1,2,'manual')`,
      `UPDATE public.session_registrations SET status = 'removed'`,
      `UPDATE public.registration_sessions SET counts_for_major = true`,
      `INSERT INTO public.major_eligibility (event_id, event_day_id, attendee_id, source) VALUES (1,1,2,'manual')`,
      `INSERT INTO public.randomizer_draws (event_id, event_day_id, attendee_id, randomizer_type) VALUES (1,1,2,'major')`,
      `UPDATE public.randomizer_draws SET voided_at = now()`,
      `INSERT INTO public.predrawn_winners (event_id, event_day_id, letter, attendee_id) VALUES (1,1,'B',3)`,
      `UPDATE public.predrawn_winners SET revealed_at = now()`,
      `DELETE FROM public.predrawn_winners`,
      `INSERT INTO public.randomizer_pool_sessions (event_id, event_day_id, registration_session_id) VALUES (1,1,1)`,
      `DELETE FROM public.randomizer_pool_sessions`,
      `INSERT INTO public.audit_logs (action, description) VALUES ('user.role_changed', 'fake')`,
      `DELETE FROM public.audit_logs`,
      `UPDATE public.attendee_qr_codes SET token = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'`,
    ];
    for (const who of PERSONAS) {
      for (const sql of writes) assert.equal((await as(who, sql)).code, '42501', `${who}: ${sql}`);
    }
  });

  test('profiles: own row only (inactive too); admins see everyone', async () => {
    for (const who of ['staff', 'scanner', 'operator', 'inactiveAdmin', 'selfSignup'] as Persona[]) {
      const r = await as(who, 'SELECT id FROM public.profiles');
      assert.deepEqual(r.rows.map((x) => x.id), [ids[who]], `${who} sees only itself`);
      assert.equal((await as(who, 'SELECT id FROM public.profiles WHERE id = $1', [ids.admin])).rows.length, 0, `${who} cannot read the admin profile`);
    }
    assert.equal((await as('admin', 'SELECT id FROM public.profiles')).rows.length, 7);
    assert.equal((await as('unknown', 'SELECT id FROM public.profiles')).rows.length, 0);
  });

  test('current_user_context / current_event_context', async () => {
    for (const who of PERSONAS) {
      const r = await as(who, 'SELECT public.current_user_context() AS c');
      const c = r.rows[0]?.c as { role: string | null; active: boolean };
      assert.equal(c.active, ROLE_OF[who] !== undefined, `${who} active`);
      if (ROLE_OF[who]) assert.equal(c.role, ROLE_OF[who]);
    }
    const ctx = (await as('scanner', 'SELECT public.current_event_context() AS c')).rows[0]?.c as Record<string, Record<string, unknown>>;
    assert.equal(ctx.event?.name, 'Holcim 2026');
    assert.equal(ctx.registrationSession?.label, 'Oct 14 - 5:00 PM');
    assert.equal(ctx.registrationSession?.startTime, '17:00');
    assert.ok(!JSON.stringify(ctx).includes(qrTokens[0] as string));
    for (const who of ['inactiveAdmin', 'selfSignup', 'unknown'] as Persona[]) {
      assert.equal((await as(who, 'SELECT public.current_event_context()')).code, '42501', who);
    }
  });

  test('admin-only RPCs are refused for every other role and for inactive admins', async () => {
    for (const who of ['staff', 'scanner', 'operator', 'inactiveAdmin', 'selfSignup', 'unknown'] as Persona[]) {
      assert.equal((await as(who, 'SELECT * FROM public.admin_list_users()')).code, '42501', `${who} list`);
      assert.equal((await as(who, 'SELECT public.admin_set_user_role($1, $2)', [ids[who], 'admin'])).code, '42501', `${who} self-escalation`);
      assert.equal((await as(who, 'SELECT public.admin_set_user_active($1, true)', [ids.inactiveAdmin])).code, '42501', `${who} reactivate`);
      assert.equal((await as(who, 'SELECT public.admin_set_user_active($1, false)', [ids.admin])).code, '42501', `${who} deactivate admin`);
    }
    const roles = (await p.query('SELECT role, is_active FROM profiles WHERE id = $1', [ids.inactiveAdmin])).rows[0];
    assert.deepEqual(roles, { role: 'admin', is_active: false }, 'nothing changed');
    const list = await as('admin', 'SELECT * FROM public.admin_list_users()');
    assert.equal(list.rows.length, 7);
    assert.ok(list.rows.some((r) => r.email === 'staff@example.com'));
    assert.ok(!JSON.stringify(list.rows).match(/\$2y\$|password/i));
  });

  test('admin role / active changes are audited and take effect on the next request', async () => {
    assert.equal((await as('operator', 'SELECT count(*)::int AS n FROM public.attendees')).rows[0]?.n, 3);
    let r = await asCommit('admin', 'SELECT public.admin_set_user_active($1, false) AS x', [ids.operator]);
    assert.equal((r.rows[0]?.x as { changed: boolean } | undefined)?.changed, true, `${r.code} ${r.message}`);
    assert.equal((await as('operator', 'SELECT count(*)::int AS n FROM public.attendees')).rows[0]?.n, 0, 'same token, now inactive: no rows');
    assert.equal((await as('operator', 'SELECT public.current_event_context()')).code, '42501');
    r = await asCommit('admin', 'SELECT public.admin_set_user_active($1, true) AS x', [ids.operator]);
    assert.equal((await as('operator', 'SELECT count(*)::int AS n FROM public.attendees')).rows[0]?.n, 3, 'reactivated');

    r = await asCommit('admin', 'SELECT public.admin_set_user_role($1, $2) AS x', [ids.operator, 'registration_staff']);
    assert.equal((r.rows[0]?.x as { changed: boolean }).changed, true, r.message);
    assert.equal((await as('operator', 'SELECT count(*)::int AS n FROM public.randomizer_draws')).rows[0]?.n, 0, 'no longer a randomizer operator');
    assert.equal((await as('operator', 'SELECT count(*)::int AS n FROM public.scan_logs')).rows[0]?.n, 1, 'now a registration-desk role');
    await asCommit('admin', 'SELECT public.admin_set_user_role($1, $2)', [ids.operator, 'event_operator']);
    assert.equal((await asCommit('admin', 'SELECT public.admin_set_user_role($1, $2)', [ids.operator, 'superuser'])).code, '22023', 'unknown role');

    const audit = (await p.query("SELECT action, actor_id, user_id, metadata FROM audit_logs WHERE action LIKE 'user.%' ORDER BY id")).rows;
    assert.deepEqual(audit.map((a) => a.action), ['user.deactivated', 'user.reactivated', 'user.role_changed', 'user.role_changed']);
    assert.ok(audit.every((a) => a.actor_id === ids.admin && a.user_id === 1), 'actor = the Supabase admin (legacy id 1 kept)');
    assert.equal((await as('admin', "SELECT count(*)::int AS n FROM public.audit_logs WHERE action = 'user.role_changed'")).rows[0]?.n, 2, 'admin can read the audit log');
  });

  test('last active admin is protected; admins cannot deactivate themselves', async () => {
    assert.equal((await asCommit('admin', 'SELECT public.admin_set_user_active($1, false)', [ids.admin])).code, '42501', 'self-deactivation');
    assert.equal((await asCommit('admin', 'SELECT public.admin_set_user_active($1, false)', [ids.admin2])).code, undefined);
    assert.equal((await asCommit('admin', 'SELECT public.admin_set_user_role($1, $2)', [ids.admin, 'registration_staff'])).code, '42501', 'last admin demotion');
    assert.equal((await asCommit('admin2', 'SELECT public.admin_set_user_active($1, true)', [ids.admin2])).code, '42501', 'deactivated admin cannot reactivate itself');
    await asCommit('admin', 'SELECT public.admin_set_user_active($1, true)', [ids.admin2]);
    const admins = (await p.query("SELECT count(*)::int AS n FROM profiles WHERE role = 'admin' AND is_active")).rows[0].n;
    assert.equal(admins, 2);
  });

  test('record_sign_in: active -> last_login_at + auth.login; inactive -> refused (audited), still no access', async () => {
    const r = await asCommit('scanner', 'SELECT public.record_sign_in() AS c');
    assert.equal((r.rows[0]?.c as { active: boolean }).active, true, r.message);
    const prof = (await p.query('SELECT last_login_at FROM profiles WHERE id = $1', [ids.scanner])).rows[0];
    assert.ok(prof.last_login_at instanceof Date);
    const bad = await asCommit('inactiveAdmin', 'SELECT public.record_sign_in() AS c');
    assert.equal((bad.rows[0]?.c as { active: boolean }).active, false);
    const audit = (await p.query("SELECT action, actor_id FROM audit_logs WHERE action LIKE 'auth.%' ORDER BY id")).rows;
    assert.deepEqual(audit, [{ action: 'auth.login', actor_id: ids.scanner }, { action: 'auth.login_refused', actor_id: ids.inactiveAdmin }]);
    assert.equal((await as('inactiveAdmin', 'SELECT count(*)::int AS n FROM public.audit_logs')).rows[0]?.n, 0);
    await asCommit('staff', 'SELECT public.record_sign_out()');
    assert.equal((await p.query("SELECT count(*)::int AS n FROM audit_logs WHERE action = 'auth.logout' AND actor_id = $1", [ids.staff])).rows[0].n, 1);
  });

  test('helper functions are not callable directly by clients where they could leak or write', async () => {
    for (const fn of ['private.write_audit(text,text,integer,jsonb)', 'private.require_role(text[])', 'private.handle_new_auth_user()']) {
      const r = await p.query("SELECT has_function_privilege('authenticated', $1, 'execute') AS x", [fn]);
      assert.equal(r.rows[0].x, false, fn);
    }
    assert.equal((await as('admin', "SELECT private.write_audit('fake', 'fake')")).code, '42501');
  });
});

// ---------------------------------------------------------------- real PostgREST
function sign(payload: Record<string, unknown>, secret = JWT_SECRET): string {
  const enc = (o: object) => Buffer.from(JSON.stringify(o)).toString('base64url');
  const body = `${enc({ alg: 'HS256', typ: 'JWT' })}.${enc(payload)}`;
  return `${body}.${createHmac('sha256', secret).update(body).digest('base64url')}`;
}
async function freePort(): Promise<number> {
  return new Promise((resolve) => {
    const s = createServer();
    s.listen(0, () => {
      const port = (s.address() as { port: number }).port;
      s.close(() => resolve(port));
    });
  });
}

describe('Direct REST (PostgREST) access cannot bypass roles', { skip: skipPg || (POSTGREST ? false : 'TEST_POSTGREST_BIN not set') }, () => {
  const REST_DB = 'holcim_migtest_auth_rest';
  let proc: ChildProcess;
  let base = '';
  const ids: Record<string, string> = {};
  let qrToken = '';

  before(async () => {
    await createPgScratch(REST_DB);
    const c = await pgClient(REST_DB);
    await c.query(`INSERT INTO events (id, name, event_date, status) VALUES (1, 'E', '2026-10-14', 'active');
      INSERT INTO event_days (id, event_id, day_number, event_date, status) VALUES (1, 1, 1, '2026-10-14', 'active');
      INSERT INTO attendees (id, event_id, attendee_code, full_name) VALUES (1, 1, 'ATT-0001', 'Juan'), (2, 1, 'ATT-0002', 'Maria');`);
    qrToken = token();
    await c.query('INSERT INTO attendee_qr_codes (attendee_id, token) VALUES (1, $1)', [qrToken]);
    for (const [k, role] of [['admin', 'admin'], ['staff', 'registration_staff'], ['scanner', 'scanner_operator'], ['operator', 'event_operator']]) {
      ids[k as string] = (await c.query('INSERT INTO auth.users (email, raw_app_meta_data) VALUES ($1, $2) RETURNING id',
        [`${k}@example.com`, JSON.stringify({ role, display_name: k })])).rows[0].id;
    }
    await c.end();
    const port = await freePort();
    base = `http://127.0.0.1:${port}`;
    const u = new URL(pgUrl(REST_DB));
    u.username = 'authenticator';
    u.password = 'authenticator-test-only';
    proc = spawn(POSTGREST, [], {
      env: {
        ...process.env, PGRST_DB_URI: u.toString(), PGRST_DB_SCHEMAS: 'public', PGRST_DB_ANON_ROLE: 'anon',
        PGRST_JWT_SECRET: JWT_SECRET, PGRST_SERVER_PORT: String(port), PGRST_SERVER_HOST: '127.0.0.1', PGRST_DB_POOL: '3',
      },
      stdio: 'ignore',
    });
    for (let i = 0; i < 100; i++) {
      try {
        if ((await fetch(`${base}/`)).status < 500) return;
      } catch { /* starting */ }
      await new Promise((r) => setTimeout(r, 100));
    }
    throw new Error('PostgREST did not start');
  });
  after(async () => {
    proc?.kill();
    await dropPg(REST_DB);
  });

  const jwt = (who: string, extra: Record<string, unknown> = {}) =>
    sign({ sub: ids[who], role: 'authenticated', aud: 'authenticated', exp: Math.floor(Date.now() / 1000) + 600, ...extra });
  const call = async (path: string, init: { method?: string; token?: string; body?: unknown } = {}) => {
    const res = await fetch(`${base}${path}`, {
      method: init.method ?? 'GET',
      headers: { 'Content-Type': 'application/json', Prefer: 'return=representation', ...(init.token ? { Authorization: `Bearer ${init.token}` } : {}) },
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
    });
    const text = await res.text();
    return { status: res.status, text, json: text ? (JSON.parse(text) as unknown) : null };
  };

  test('anonymous REST calls are refused (tables, QR, RPCs)', async () => {
    for (const path of ['/attendees', '/attendee_qr_codes', '/profiles', '/audit_logs', '/users', '/session_registrations', '/randomizer_draws']) {
      const r = await call(path);
      assert.ok([401, 403].includes(r.status), `${path} -> ${r.status} ${r.text}`);
      assert.ok(!r.text.includes('Juan') && !r.text.includes(qrToken));
    }
    assert.ok([401, 403].includes((await call('/rpc/current_event_context', { method: 'POST', body: {} })).status));
    assert.ok([401, 403].includes((await call('/attendees', { method: 'POST', body: { event_id: 1, attendee_code: 'X', full_name: 'X' } })).status));
  });

  test('role-scoped reads through REST', async () => {
    const staff = await call('/attendees?select=attendee_code', { token: jwt('staff') });
    assert.equal(staff.status, 200);
    assert.equal((staff.json as unknown[]).length, 2);
    const scanner = await call('/attendees', { token: jwt('scanner') });
    assert.equal(scanner.status, 200);
    assert.deepEqual(scanner.json, [], 'scanner operators do not list attendees');
    const ctx = await call('/rpc/current_event_context', { method: 'POST', token: jwt('scanner'), body: {} });
    assert.equal(ctx.status, 200, ctx.text);
  });

  test('QR tokens are not reachable through REST, not even by embedding', async () => {
    for (const who of ['admin', 'staff', 'scanner', 'operator']) {
      for (const path of ['/attendee_qr_codes', `/attendee_qr_codes?token=eq.${qrToken}`, '/attendees?select=attendee_code,attendee_qr_codes(token)']) {
        const r = await call(path, { token: jwt(who) });
        assert.notEqual(r.status, 200, `${who} ${path}`);
        assert.ok(!r.text.includes(qrToken), `${who} ${path} leaked a token`);
      }
    }
  });

  test('role escalation / payload tampering through REST is refused', async () => {
    let r = await call(`/profiles?id=eq.${ids.staff}`, { method: 'PATCH', token: jwt('staff'), body: { role: 'admin' } });
    assert.ok([401, 403].includes(r.status), `PATCH own role -> ${r.status}`);
    r = await call(`/profiles?id=eq.${ids.staff}`, { method: 'PATCH', token: jwt('staff'), body: { is_active: true } });
    assert.ok([401, 403].includes(r.status));
    r = await call('/rpc/admin_set_user_role', { method: 'POST', token: jwt('staff'), body: { p_user_id: ids.staff, p_role: 'admin' } });
    assert.equal(r.status, 403, r.text);
    r = await call('/audit_logs', { method: 'POST', token: jwt('admin'), body: { action: 'user.created', description: 'fake' } });
    assert.ok([401, 403].includes(r.status), 'even an admin cannot insert audit rows directly');
    r = await call('/attendees?id=eq.1', { method: 'PATCH', token: jwt('admin'), body: { event_id: 2 } });
    assert.ok([401, 403].includes(r.status), 'no direct attendee writes (Phase 4 RPCs)');
    const role = await call('/rpc/current_user_context', { method: 'POST', token: jwt('staff'), body: {} });
    assert.equal((role.json as { role: string }).role, 'registration_staff', 'role unchanged');
  });

  test('forged, expired, other-secret and unknown-subject tokens', async () => {
    const forged = sign({ sub: ids.admin, role: 'authenticated', exp: Math.floor(Date.now() / 1000) + 600 }, 'not-the-real-secret-' + 'y'.repeat(32));
    assert.equal((await call('/attendees', { token: forged })).status, 401, 'wrong signature');
    const expired = jwt('admin', { exp: Math.floor(Date.now() / 1000) - 60 });
    assert.equal((await call('/attendees', { token: expired })).status, 401, 'expired');
    const unknown = sign({ sub: randomUUID(), role: 'authenticated', exp: Math.floor(Date.now() / 1000) + 600 });
    const r = await call('/attendees', { token: unknown });
    assert.deepEqual([r.status, r.json], [200, []], 'a valid token without an active profile sees nothing');
    const badRole = jwt('staff', { role: 'postgres' });
    assert.notEqual((await call('/attendees', { token: badRole })).status, 200, 'cannot switch to a privileged database role');
  });

  test('admin RPCs work for an admin; private helpers and legacy credentials are not exposed', async () => {
    const list = await call('/rpc/admin_list_users', { method: 'POST', token: jwt('admin'), body: {} });
    assert.equal(list.status, 200, list.text);
    assert.equal((list.json as unknown[]).length, 4);
    assert.ok([404, 400].includes((await call('/rpc/is_admin', { method: 'POST', token: jwt('admin'), body: {} })).status), 'private schema not exposed');
    assert.ok([401, 403].includes((await call('/users', { token: jwt('admin') })).status), 'legacy users (password hashes) not readable');
  });
});
