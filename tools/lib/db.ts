// Connections for the migration tools. MySQL is only ever read; PostgreSQL is
// read (verification) or written by the import tool inside one transaction.
//
// Connection strings come from the environment / command line, never from Git:
//   MYSQL_URL     mysql://user:pass@127.0.0.1:3306/holcim_event
//   SUPABASE_DB_URL / DATABASE_URL  postgresql://...   (Supabase: Project Settings > Database)
// TLS for PostgreSQL: certificates are always verified. For Supabase download the
// project CA certificate and pass it with PGSSLROOTCERT=/path/to/prod-ca-2021.crt
// (or --ca=PATH). Local sockets / localhost connect without TLS.
import { readFileSync } from 'node:fs';
import pg from 'pg';
import mysql from 'mysql2/promise';

export type Row = Record<string, unknown>;

export interface Db {
  kind: 'mysql' | 'postgres';
  label: string;
  query(sql: string, params?: unknown[]): Promise<Row[]>;
  beginReadOnly(): Promise<void>;
  rollback(): Promise<void>;
  close(): Promise<void>;
}

/** Database name shown in reports (never the password). */
export function describeUrl(url: string): string {
  try {
    const u = new URL(url);
    return `${u.protocol.replace(':', '')}://${u.hostname}${u.port ? ':' + u.port : ''}/${decodeURIComponent(u.pathname.replace(/^\//, ''))}`;
  } catch {
    return '(unparseable connection string)';
  }
}

function databaseName(url: string): string {
  try {
    return decodeURIComponent(new URL(url).pathname.replace(/^\//, '')) || '(default)';
  } catch {
    return '(unknown)';
  }
}

export async function connect(url: string, options: { ca?: string } = {}): Promise<Db> {
  if (/^mysql:\/\//i.test(url)) {
    const conn = await mysql.createConnection({
      uri: url,
      dateStrings: true,          // DATETIME as 'YYYY-MM-DD HH:MM:SS' text: no JS Date / time zone conversion
      supportBigNumbers: true,
      bigNumberStrings: true,
      charset: 'utf8mb4_unicode_ci',
      multipleStatements: false,
    });
    return {
      kind: 'mysql',
      label: databaseName(url),
      async query(sql, params = []) {
        const [rows] = await conn.query(sql, params);
        return rows as Row[];
      },
      async beginReadOnly() {
        await conn.query('SET SESSION TRANSACTION READ ONLY');
        await conn.query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
      },
      async rollback() {
        await conn.query('ROLLBACK');
      },
      async close() {
        await conn.end();
      },
    };
  }
  if (/^postgres(ql)?:\/\//i.test(url)) {
    const u = new URL(url);
    const local = u.hostname === '' || u.hostname === 'localhost' || u.hostname === '127.0.0.1' || u.searchParams.has('host');
    const caPath = options.ca ?? process.env.PGSSLROOTCERT;
    // Strip sslmode/sslrootcert from the URL so our explicit TLS settings below apply.
    for (const key of ['sslmode', 'sslrootcert', 'sslcert', 'sslkey']) u.searchParams.delete(key);
    const ssl = local ? undefined : caPath ? { ca: readFileSync(caPath, 'utf8'), rejectUnauthorized: true } : { rejectUnauthorized: true };
    const client = new pg.Client({ connectionString: u.toString(), ssl });
    // Keep bigint (int8) ids as strings; int4 ids arrive as numbers.
    await client.connect();
    return {
      kind: 'postgres',
      label: databaseName(url),
      async query(sql, params = []) {
        return (await client.query(sql, params as unknown[])).rows as Row[];
      },
      async beginReadOnly() {
        await client.query('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
      },
      async rollback() {
        await client.query('ROLLBACK');
      },
      async close() {
        await client.end();
      },
    };
  }
  throw new Error('Unsupported connection string (expected mysql://... or postgresql://...).');
}
