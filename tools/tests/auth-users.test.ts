// Username -> internal Auth email mapping, and the read-only user-migration planner.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { DEFAULT_USERNAME_EMAIL_DOMAIN, isInternalUsernameEmail, loginIdentifierToEmail, usernameToAuthEmail } from '../../frontend/src/utils/authIdentifier.ts';
import { createMysqlScratch, dropMysql, field, mysqlAdmin, mysqlChecksums, mysqlUrl, runTool, skipMysql } from './helpers.ts';

test('login identifier: emails pass through, usernames map to the internal domain', () => {
  assert.equal(loginIdentifierToEmail(' Admin@Example.com '), 'admin@example.com');
  assert.equal(loginIdentifierToEmail('Gate-1'), `gate-1@${DEFAULT_USERNAME_EMAIL_DOMAIN}`);
  assert.equal(loginIdentifierToEmail('ab'), null, 'too short');
  assert.equal(loginIdentifierToEmail('bad name'), null);
  assert.equal(loginIdentifierToEmail('x@y'), null);
  assert.equal(usernameToAuthEmail('scan.2', 'example.org'), 'scan.2@example.org');
  assert.throws(() => usernameToAuthEmail('no!'));
  assert.ok(isInternalUsernameEmail(`gate-1@${DEFAULT_USERNAME_EMAIL_DOMAIN}`));
  assert.ok(!isInternalUsernameEmail('gate-1@example.com'));
});

const MY = 'holcim_migtest_auth_users';

describe('plan-auth-users.ts (read-only planner)', { skip: skipMysql }, () => {
  let dir: string;
  let before1: string;
  before(async () => {
    dir = mkdtempSync(join(tmpdir(), 'holcim-auth-plan-'));
    await createMysqlScratch(MY);
    const c = await mysqlAdmin(MY);
    const h = (n: number) => `$2y$12$${'A'.repeat(52)}${n}`;
    await c.query('INSERT INTO users (id, name, email, username, password_hash, role, status) VALUES ?', [[
      [1, 'Admin One', 'Admin@Example.com', null, h(1), 'admin', 'active'],
      [2, 'Desk', 'desk@example.com', null, h(2), 'registration_staff', 'active'],
      [3, 'Operator', 'op@example.com', null, h(3), 'event_operator', 'inactive'],
      [4, 'Gate 1', null, 'gate-1', h(4), 'scanner_operator', 'active'],
      [5, 'Gate 2', null, 'gate-2', '$2y$10$' + 'B'.repeat(53), 'scanner_operator', 'active'],
    ]]);
    await c.end();
    before1 = await mysqlChecksums(MY);
  });
  after(async () => {
    rmSync(dir, { recursive: true, force: true });
    await dropMysql(MY);
  });

  test('summary prints counts only - no emails, usernames or hashes', () => {
    const r = runTool('plan-auth-users.ts', [`--mysql=${mysqlUrl(MY)}`]);
    assert.equal(r.code, 0, r.out);
    assert.equal(field(r.out, 'Users:'), '5');
    assert.match(r.out, /"bcrypt \$2y\$ cost 12":4/);
    assert.match(r.out, /"bcrypt \$2y\$ cost 10":1/);
    assert.match(r.out, /RESULT: READY/);
    for (const secret of ['admin@example.com', 'desk@example.com', 'gate-1', '$2y$12$AAAA']) assert.ok(!r.out.toLowerCase().includes(secret.toLowerCase()), secret);
  });

  test('manifest without hashes by default; with --include-password-hashes it is 0600', () => {
    const out = join(dir, 'plan.json');
    assert.equal(runTool('plan-auth-users.ts', [`--mysql=${mysqlUrl(MY)}`, `--out=${out}`]).code, 0);
    const text = readFileSync(out, 'utf8');
    assert.ok(!text.includes('$2y$'), 'no hashes');
    const m = JSON.parse(text) as { users: { legacy_user_id: number; auth_email: string; app_metadata: { role: string; active: boolean; username: string | null } }[] };
    const gate = m.users.find((u) => u.legacy_user_id === 4);
    assert.equal(gate?.auth_email, `gate-1@${DEFAULT_USERNAME_EMAIL_DOMAIN}`);
    assert.deepEqual([gate?.app_metadata.role, gate?.app_metadata.username], ['scanner_operator', 'gate-1']);
    assert.equal(m.users.find((u) => u.legacy_user_id === 1)?.auth_email, 'admin@example.com');
    assert.equal(m.users.find((u) => u.legacy_user_id === 3)?.app_metadata.active, false, 'inactive stays inactive');
    const withHashes = join(dir, 'plan-hashes.json');
    assert.equal(runTool('plan-auth-users.ts', [`--mysql=${mysqlUrl(MY)}`, `--out=${withHashes}`, '--include-password-hashes']).code, 0);
    assert.ok(readFileSync(withHashes, 'utf8').includes('$2y$12$'));
    assert.equal(statSync(withHashes).mode & 0o777, 0o600);
  });

  test('flags accounts that need attention and never modifies MySQL', async () => {
    const c = await mysqlAdmin(MY);
    await c.query("INSERT INTO users (id, name, email, username, password_hash, role, status) VALUES (6, 'No Login', NULL, NULL, 'x', 'registration_staff', 'active')");
    await c.end();
    const snapshot = await mysqlChecksums(MY);
    const r = runTool('plan-auth-users.ts', [`--mysql=${mysqlUrl(MY)}`]);
    assert.equal(await mysqlChecksums(MY), snapshot, 'the planner changed nothing (checksums + AUTO_INCREMENT)');
    assert.equal(r.code, 1);
    assert.equal(field(r.out, 'Issue - no_login_identifier:'), '1');
    assert.equal(field(r.out, 'Issue - password_hash_not_bcrypt:'), '1');
    const c2 = await mysqlAdmin(MY);
    await c2.query('DELETE FROM users WHERE id = 6');
    await c2.end();
    assert.ok(before1.length > 0);
  });
});
