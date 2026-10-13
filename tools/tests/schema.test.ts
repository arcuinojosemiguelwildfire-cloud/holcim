// Schema tests: the Supabase migrations build every table, foreign key, unique rule,
// index, CHECK and trigger of the MySQL schema (after migrations 001-036), plus the
// Phase 1 security baseline. MySQL is migrated from database/migrations in a scratch
// database and compared object by object.
import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import type pg from 'pg';
import type mysql from 'mysql2/promise';
import { PG_ONLY_COLUMNS, TABLES } from '../lib/tables.ts';
import { createMysqlScratch, createPgScratch, dropMysql, dropPg, mysqlAdmin, pgClient, skipBoth } from './helpers.ts';

const MY = 'holcim_migtest_schema_my';
const PG = 'holcim_migtest_schema_pg';

describe('PostgreSQL schema mirrors MySQL', { skip: skipBoth }, () => {
  let p: pg.Client;
  let m: mysql.Connection;
  before(async () => {
    await createMysqlScratch(MY);
    await createPgScratch(PG);
    p = await pgClient(PG);
    m = await mysqlAdmin(MY);
  });
  after(async () => {
    await p?.end();
    await m?.end();
    await dropPg(PG);
    await dropMysql(MY);
  });

  const myRows = async (sql: string): Promise<Record<string, unknown>[]> => (await m.query(sql))[0] as Record<string, unknown>[];

  test('all application tables exist (plus migration_import_runs and the Phase 2 profiles), schema_migrations is not copied', async () => {
    const r = await p.query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'");
    const names = r.rows.map((x) => x.table_name).sort();
    assert.deepEqual(names, [...TABLES, 'migration_import_runs', 'profiles'].sort());
  });

  test('same columns, nullability and types family in every table', async () => {
    for (const t of TABLES) {
      const my = await myRows(`SELECT column_name AS c, is_nullable AS n, IF(column_type = 'tinyint(1)', 'boolean', data_type) AS d FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = '${t}' AND extra NOT LIKE '%GENERATED%'`);
      const pgc = (await p.query(`SELECT column_name AS c, is_nullable AS n, data_type AS d FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = $1`, [t])).rows.filter((x) => !PG_ONLY_COLUMNS.has(`${t}.${x.c}`));
      const fam = (d: string): string => d === 'boolean' ? 'bool' : /int|numeric/.test(d) ? 'int' : /datetime|timestamp/.test(d) ? 'datetime'
        : d === 'date' ? 'date' : /^time/.test(d) ? 'time' : d === 'jsonb' ? 'json' : 'text';
      const norm = (rows: Record<string, unknown>[], json: string[]): string[] => rows.map((x) => {
        const c = String(x.c);
        return `${c}:${x.n}:${json.includes(c) ? 'json' : fam(String(x.d))}`;
      }).sort();
      const jsonCols = ['column_mapping', 'error_summary', 'extra_data', 'response_data', 'metadata'];
      assert.deepEqual(norm(pgc, []), norm(my, jsonCols), `columns of ${t}`);
    }
  });

  test('every MySQL foreign key exists with the same columns, target and rules', async () => {
    const my = await myRows(`SELECT k.table_name AS t, k.constraint_name AS n, GROUP_CONCAT(k.column_name ORDER BY k.ordinal_position) AS cols,
        k.referenced_table_name AS rt, GROUP_CONCAT(k.referenced_column_name ORDER BY k.ordinal_position) AS rcols, r.delete_rule AS del, r.update_rule AS upd
      FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r
        ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
      WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL GROUP BY k.table_name, k.constraint_name, k.referenced_table_name, r.delete_rule, r.update_rule`);
    const pgfk = (await p.query(`SELECT c.conrelid::regclass::text AS t,
        (SELECT string_agg(a.attname, ',' ORDER BY x.n) FROM unnest(c.conkey) WITH ORDINALITY x(k, n) JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = x.k) AS cols,
        c.confrelid::regclass::text AS rt,
        (SELECT string_agg(a.attname, ',' ORDER BY x.n) FROM unnest(c.confkey) WITH ORDINALITY x(k, n) JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = x.k) AS rcols,
        c.confdeltype AS del, c.confupdtype AS upd
      FROM pg_constraint c WHERE c.contype = 'f' AND c.connamespace = 'public'::regnamespace`)).rows;
    const rule: Record<string, string> = { a: 'NO ACTION', r: 'RESTRICT', c: 'CASCADE', n: 'SET NULL', d: 'SET DEFAULT' };
    const key = (t: string, cols: string, rt: string, rcols: string): string => `${t}(${cols})->${rt}(${rcols})`;
    const pgMap = new Map(pgfk.map((f) => [key(f.t, f.cols, f.rt, f.rcols), f]));
    assert.ok(my.length >= 20, `found ${my.length} MySQL foreign keys`);
    for (const f of my) {
      const k = key(String(f.t), String(f.cols), String(f.rt), String(f.rcols));
      const g = pgMap.get(k);
      assert.ok(g, `missing foreign key ${k}`);
      // MySQL reports its default (no ON DELETE clause) as RESTRICT; PostgreSQL must refuse too.
      const pgDel = rule[g.del] === 'NO ACTION' ? 'RESTRICT' : rule[g.del];
      const pgUpd = rule[g.upd] === 'NO ACTION' ? 'RESTRICT' : rule[g.upd];
      assert.equal(pgDel, String(f.del), `ON DELETE of ${k}`);
      assert.equal(pgUpd, String(f.upd), `ON UPDATE of ${k}`);
    }
    // PostgreSQL-only (Supabase phases): profiles -> auth.users / users, audit_logs.actor_id -> profiles.
    const mirrored = pgfk.filter((f) => f.t !== 'profiles' && !(f.t === 'audit_logs' && f.cols === 'actor_id'));
    assert.equal(mirrored.length, my.length, 'no extra foreign keys');
  });

  test('every MySQL unique key and index has a PostgreSQL equivalent', async () => {
    const my = await myRows(`SELECT table_name AS t, index_name AS i, non_unique AS nu, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
      FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name <> 'schema_migrations' GROUP BY table_name, index_name, non_unique`);
    const pgi = (await p.query(`SELECT t.relname AS t, ix.indisunique AS u, pg_get_indexdef(ix.indexrelid) AS def,
        (SELECT string_agg(coalesce(a.attname, '?'), ',' ORDER BY x.n) FROM unnest(ix.indkey::int2[]) WITH ORDINALITY x(k, n) LEFT JOIN pg_attribute a ON a.attrelid = ix.indrelid AND a.attnum = x.k) AS cols
      FROM pg_index ix JOIN pg_class t ON t.oid = ix.indrelid WHERE t.relnamespace = 'public'::regnamespace`)).rows;
    for (const ix of my) {
      const t = String(ix.t);
      const cols = String(ix.cols).split(',');
      const unique = Number(ix.nu) === 0;
      let found;
      if (cols.includes('active_lock')) {
        // generated active_lock + UNIQUE -> partial unique index on status = 'active'
        found = pgi.find((g) => g.t === t && g.u && /WHERE \(status = 'active'::text\)/.test(g.def));
      } else if (cols.includes('live_lock')) {
        // generated live_lock (1 / NULL when voided) in a UNIQUE key -> partial unique index WHERE voided_at IS NULL
        const rest = cols.filter((c) => c !== 'live_lock').join(',');
        found = pgi.find((g) => g.t === t && g.u && g.cols === rest && /WHERE \(voided_at IS NULL\)/.test(g.def));
      } else if (cols.includes('current_lock')) {
        // generated current_lock + UNIQUE -> partial unique index WHERE is_current
        found = pgi.find((g) => g.t === t && g.u && /WHERE is_current/.test(g.def));
      } else {
        found = pgi.find((g) => g.t === t && (!unique || g.u)
          && cols.every((c, n) => (g.cols.split(',')[n] === c) || new RegExp(`lower\\(\\(?${c}\\)?`).test(g.def))
          && g.cols.split(',').length === cols.length);
      }
      assert.ok(found, `${unique ? 'unique ' : ''}index ${t}.${ix.i} (${cols.join(',')}) has no PostgreSQL equivalent`);
    }
  });

  test('ENUM columns are CHECK constraints with exactly the same values', async () => {
    const enums = await myRows(`SELECT table_name AS t, column_name AS c, column_type AS ty FROM information_schema.columns
      WHERE table_schema = DATABASE() AND data_type = 'enum' AND table_name <> 'schema_migrations'`);
    assert.ok(enums.length >= 10);
    for (const e of enums) {
      const values = [...String(e.ty).matchAll(/'([^']*)'/g)].map((x) => x[1]).sort();
      const r = await p.query(`SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE contype = 'c' AND conrelid = $1::regclass
        AND pg_get_constraintdef(oid) LIKE '%' || $2 || '%'`, [`public.${e.t}`, String(e.c)]);
      const def = r.rows.map((x) => x.def).find((d: string) => values.every((v) => d.includes(`'${v}'`)));
      assert.ok(def, `CHECK for ${e.t}.${e.c}`);
      const inPg = [...String(def).matchAll(/'([^']*)'::text/g)].map((x) => x[1]).sort();
      assert.deepEqual(inPg, values, `values of ${e.t}.${e.c}`);
    }
  });

  test('ids are identity columns; timestamps are timestamptz; token is text COLLATE "C"', async () => {
    for (const t of [...TABLES, 'migration_import_runs']) {
      const r = await p.query("SELECT is_identity, identity_generation FROM information_schema.columns WHERE table_schema = 'public' AND table_name = $1 AND column_name = 'id'", [t]);
      assert.equal(r.rows[0]?.is_identity, 'YES', `${t}.id identity`);
      assert.equal(r.rows[0]?.identity_generation, 'BY DEFAULT', `${t}.id accepts imported ids`);
    }
    const ts = await p.query("SELECT count(*)::int AS n FROM information_schema.columns WHERE table_schema = 'public' AND data_type = 'timestamp without time zone'");
    assert.equal(ts.rows[0].n, 0, 'no timestamp without time zone');
    const tok = await p.query("SELECT data_type, collation_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'attendee_qr_codes' AND column_name = 'token'");
    assert.deepEqual(tok.rows[0], { data_type: 'text', collation_name: 'C' });
  });

  test('updated_at triggers exist where MySQL has ON UPDATE CURRENT_TIMESTAMP', async () => {
    const my = await myRows("SELECT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'updated_at' AND extra LIKE '%on update%'");
    const pgt = (await p.query("SELECT event_object_table AS t FROM information_schema.triggers WHERE trigger_schema = 'public' AND action_statement LIKE '%set_updated_at%'")).rows.map((x) => x.t);
    for (const r of my) assert.ok(pgt.includes(r.t), `trigger on ${r.t}`);
  });

  test('security baseline: RLS on every table; anon has nothing; authenticated only SELECT through SELECT policies', async () => {
    const rls = await p.query("SELECT relname, relrowsecurity FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind = 'r'");
    for (const r of rls.rows) assert.equal(r.relrowsecurity, true, `RLS on ${r.relname}`);
    const pol = await p.query("SELECT tablename, cmd, roles::text AS roles FROM pg_policies WHERE schemaname = 'public'");
    assert.ok(pol.rows.length >= 15);
    for (const r of pol.rows) {
      assert.equal(r.cmd, 'SELECT', `only SELECT policies (${r.tablename})`);
      assert.equal(r.roles, '{authenticated}', `policies apply to authenticated only (${r.tablename})`);
    }
    const anon = await p.query("SELECT count(*)::int AS n FROM information_schema.role_table_grants WHERE grantee = 'anon' AND table_schema = 'public'");
    assert.equal(anon.rows[0].n, 0, 'anon has no table privileges');
    const auth = await p.query("SELECT DISTINCT privilege_type FROM information_schema.role_table_grants WHERE grantee = 'authenticated' AND table_schema = 'public'");
    assert.deepEqual(auth.rows.map((r) => r.privilege_type), ['SELECT'], 'authenticated may only SELECT');
    for (const t of ['users', 'login_attempts', 'attendee_qr_codes']) {
      const g = await p.query("SELECT has_table_privilege('authenticated', $1, 'SELECT') AS x", [`public.${t}`]);
      assert.equal(g.rows[0].x, false, `no client access to ${t}`);
    }
    for (const role of ['anon', 'authenticated']) {
      const f = await p.query(`SELECT has_function_privilege($1, 'public.generate_qr_token()', 'execute') AS x`, [role]);
      assert.equal(f.rows[0].x, false, `${role} cannot call generate_qr_token()`);
    }
  });

  test('generate_qr_token(): 24 random bytes as 32-char base64url, all distinct', async () => {
    const r = await p.query('SELECT public.generate_qr_token() AS t FROM generate_series(1, 2000)');
    const tokens = r.rows.map((x) => x.t as string);
    for (const t of tokens) assert.match(t, /^[A-Za-z0-9_-]{32}$/);
    assert.equal(new Set(tokens).size, tokens.length);
    for (const t of tokens.slice(0, 50)) assert.equal(Buffer.from(t, 'base64url').length, 24);
  });

  test('app time zone helper is Asia/Manila', async () => {
    assert.equal((await p.query('SELECT public.app_time_zone() AS z')).rows[0].z, 'Asia/Manila');
  });
});
