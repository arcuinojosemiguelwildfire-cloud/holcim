// Plans the move of the legacy PHP/MySQL staff accounts to Supabase Auth (Phase 2).
// READ-ONLY: reads `users` inside a READ ONLY transaction; never writes anywhere except
// the optional manifest file. Prints counts only - never emails, usernames, passwords
// or password hashes.
//
//   node plan-auth-users.ts --mysql=URL                        summary (counts + issues)
//   node plan-auth-users.ts --mysql=URL --out=FILE.json        + manifest for the user-import
//                                                              Edge Function (no hashes)
//   ... --out=FILE.json --include-password-hashes              + bcrypt hashes (0600 file;
//                                                              delete after the import)
//   --username-email-domain=scanner.holcim-event.invalid       domain for username logins
//
// The manifest is the input of the server-side import (Supabase Admin API
// auth.admin.createUser with email_confirm: true, app_metadata {role, display_name,
// username, legacy_user_id, active} and, if included, password_hash). That call needs
// the service-role key, so it runs ONLY in a Supabase Edge Function / trusted server,
// never in the browser. See docs/SUPABASE_AUTH.md.
import { chmodSync, writeFileSync } from 'node:fs';
import { parseArgs } from 'node:util';
import { connect, describeUrl } from './lib/db.ts';
import { DEFAULT_USERNAME_EMAIL_DOMAIN, usernameToAuthEmail } from '../frontend/src/utils/authIdentifier.ts';

const { values: opt } = parseArgs({
  options: {
    mysql: { type: 'string' }, out: { type: 'string' }, 'include-password-hashes': { type: 'boolean' },
    'username-email-domain': { type: 'string' }, help: { type: 'boolean' },
  },
});
if (opt.help) {
  console.log('Usage: node plan-auth-users.ts --mysql=URL [--out=FILE.json [--include-password-hashes]] [--username-email-domain=DOMAIN]');
  process.exit(0);
}
const url = opt.mysql ?? process.env.MYSQL_URL;
if (!url || !/^mysql:/i.test(url)) {
  console.error('Need --mysql=mysql://... (or MYSQL_URL).');
  process.exit(2);
}
const domain = opt['username-email-domain'] ?? DEFAULT_USERNAME_EMAIL_DOMAIN;
const USERNAME = /^[a-z0-9._-]{3,60}$/;
const ROLES = ['admin', 'registration_staff', 'event_operator', 'scanner_operator'];

const db = await connect(url);
await db.beginReadOnly();
let rows;
try {
  rows = await db.query('SELECT id, name, email, username, password_hash, role, status, session_version, last_login_at FROM users ORDER BY id');
} finally {
  await db.rollback();
  await db.close();
}

const count = (pred: (r: Record<string, unknown>) => boolean): number => rows.filter(pred).length;
const tally = (key: (r: Record<string, unknown>) => string): Record<string, number> => {
  const out: Record<string, number> = {};
  for (const r of rows) out[key(r)] = (out[key(r)] ?? 0) + 1;
  return out;
};
const hashFormat = (h: unknown): string => {
  const s = String(h ?? '');
  const m = /^\$(2[abxy])\$(\d{2})\$[./A-Za-z0-9]{53}$/.exec(s);
  if (m) return `bcrypt $${m[1]}$ cost ${m[2]}`;
  if (s.startsWith('$argon2')) return 'argon2';
  return s === '' ? 'empty' : 'unknown';
};
const lower = (v: unknown): string | null => (v === null || v === undefined || v === '' ? null : String(v).toLowerCase());
const dupes = (vals: (string | null)[]): number => {
  const seen = new Map<string, number>();
  for (const v of vals) if (v) seen.set(v, (seen.get(v) ?? 0) + 1);
  return [...seen.values()].filter((n) => n > 1).reduce((a, n) => a + n, 0);
};

const issues = {
  unknown_role: count((r) => !ROLES.includes(String(r.role))),
  no_login_identifier: count((r) => !r.email && !r.username),
  invalid_username: count((r) => r.username !== null && r.username !== '' && !USERNAME.test(String(r.username))),
  duplicate_usernames_case_insensitive: dupes(rows.map((r) => lower(r.username))),
  duplicate_emails_case_insensitive: dupes(rows.map((r) => lower(r.email))),
  password_hash_not_bcrypt: count((r) => !hashFormat(r.password_hash).startsWith('bcrypt')),
};
const w = (label: string, value: unknown): void => console.log(`${label.padEnd(36)} ${typeof value === 'object' ? JSON.stringify(value) : value}`);

console.log('Supabase Auth migration plan (READ-ONLY)\n---------------------------------------');
w('Source:', describeUrl(url));
w('Users:', rows.length);
w('By role:', tally((r) => String(r.role)));
w('By status:', tally((r) => String(r.status)));
w('Active by role:', tally((r) => (r.status === 'active' ? String(r.role) : '(inactive)')));
w('Login identifier:', tally((r) => (r.email && r.username ? 'email+username' : r.email ? 'email' : r.username ? 'username' : 'none')));
w('Password hash formats:', tally((r) => hashFormat(r.password_hash)));
w('Never signed in:', count((r) => r.last_login_at === null));
w('Username accounts -> Auth email:', `<username>@${domain} (internal, never shown to users)`);
console.log('');
for (const [k, v] of Object.entries(issues)) w(`Issue - ${k}:`, v);

if (opt.out) {
  const include = opt['include-password-hashes'] === true;
  const manifest = {
    manifest_version: 1,
    generated_at: new Date().toISOString(),
    username_email_domain: domain,
    password_hashes_included: include,
    users: rows.map((r) => {
      const username = r.username ? String(r.username).toLowerCase() : null;
      const bcrypt = hashFormat(r.password_hash).startsWith('bcrypt');
      return {
        legacy_user_id: Number(r.id),
        display_name: String(r.name),
        role: String(r.role),
        active: r.status === 'active',
        login: r.email ? { type: 'email', value: String(r.email).toLowerCase() } : username ? { type: 'username', value: username } : null,
        auth_email: r.email ? String(r.email).toLowerCase() : username ? usernameToAuthEmail(username, domain) : null,
        app_metadata: { role: String(r.role), display_name: String(r.name), username, legacy_user_id: Number(r.id), active: r.status === 'active' },
        password: include && bcrypt ? { method: 'import_bcrypt_hash', password_hash: String(r.password_hash) }
          : { method: bcrypt ? 'import_bcrypt_hash_available' : 'admin_sets_new_password' },
      };
    }),
  };
  writeFileSync(opt.out, JSON.stringify(manifest, null, 2) + '\n', { mode: 0o600 });
  chmodSync(opt.out, 0o600);
  console.log(`\nManifest written (${include ? 'CONTAINS PASSWORD HASHES - delete after import' : 'no password hashes'}): ${opt.out}`);
}
const blocking = issues.unknown_role + issues.no_login_identifier + issues.invalid_username + issues.duplicate_usernames_case_insensitive + issues.duplicate_emails_case_insensitive;
console.log(`\nRESULT: ${blocking === 0 ? 'READY' : `${blocking} account(s) need attention before import`}`);
process.exit(blocking === 0 ? 0 : 1);
