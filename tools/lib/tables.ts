// Table catalogue and canonical per-table fingerprints shared by the import tool and
// the tests. A fingerprint is the SHA-256 of every row rendered to one canonical text
// line, so the same data gives the same hash in MySQL and in PostgreSQL:
//   * rows ordered by id; columns in a fixed (alphabetical) order
//   * NULL -> \N; "\", "|", LF, CR escaped (same scheme as the QR manifest)
//   * DATETIME / timestamptz -> Asia/Manila wall time 'YYYY-MM-DD HH:MM:SS[.ffffff]'
//   * DATE -> 'YYYY-MM-DD'; TIME -> 'HH:MM:SS'; integers -> decimal text
//   * TINYINT(1) flags / boolean -> '1' or '0'
//   * JSON -> parsed and re-serialised with sorted object keys (jsonb reorders keys)
// Text is compared byte for byte (case and whitespace preserved).
import { createHash } from 'node:crypto';
import type { Db, Row } from './db.ts';

export const TIME_ZONE = 'Asia/Manila';

/** Copy order: every table comes after the tables its foreign keys point to. */
export const TABLES = [
  'users', 'events', 'event_days', 'registration_sessions', 'import_batches', 'attendees', 'attendee_qr_codes',
  'registration_scans', 'session_registrations', 'session_registration_changes', 'scan_logs',
  'minor_manual_entries', 'major_eligibility', 'major_entries', 'randomizer_draws', 'predrawn_winners', 'audit_logs', 'login_attempts',
] as const;
export type TableName = (typeof TABLES)[number];

export type ColumnKind = 'int' | 'bool' | 'text' | 'datetime' | 'date' | 'time' | 'json';
export interface Column { name: string; kind: ColumnKind }

const JSON_COLUMNS = new Set(['import_batches.column_mapping', 'import_batches.error_summary', 'attendees.extra_data',
  'major_entries.response_data', 'audit_logs.metadata']);

/** Columns of a MySQL table (generated columns such as active_lock are skipped: PostgreSQL derives them). */
export async function mysqlColumns(db: Db, table: string): Promise<Column[]> {
  const rows = await db.query(
    `SELECT column_name AS name, data_type AS type, column_type AS ctype FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND extra NOT LIKE '%GENERATED%' ORDER BY column_name`, [table]);
  return sortColumns(rows.map((r) => {
    const name = String(r.name);
    const type = String(r.type).toLowerCase();
    let kind: ColumnKind = 'text';
    if (JSON_COLUMNS.has(`${table}.${name}`) || type === 'json') kind = 'json';
    else if (/^tinyint\(1\)/i.test(String(r.ctype))) kind = 'bool';
    else if (type === 'time') kind = 'time';
    else if (/int$/.test(type) || type === 'decimal') kind = 'int';
    else if (type === 'datetime' || type === 'timestamp') kind = 'datetime';
    else if (type === 'date') kind = 'date';
    return { name, kind };
  }));
}

/**
 * Columns that exist only in PostgreSQL (added by later Supabase phases, nullable, never
 * filled from MySQL). The import neither copies nor fingerprints them.
 */
export const PG_ONLY_COLUMNS = new Set(['audit_logs.actor_id']);

export async function postgresColumns(db: Db, table: string): Promise<Column[]> {
  const rows = await db.query(
    `SELECT column_name AS name, data_type AS type FROM information_schema.columns
      WHERE table_schema = 'public' AND table_name = $1 AND is_generated = 'NEVER' ORDER BY column_name`, [table]);
  return sortColumns(rows.filter((r) => !PG_ONLY_COLUMNS.has(`${table}.${String(r.name)}`)).map((r) => {
    const type = String(r.type);
    const kind: ColumnKind = type === 'jsonb' || type === 'json' ? 'json'
      : type === 'boolean' ? 'bool'
      : type.startsWith('time without') ? 'time'
      : ['integer', 'bigint', 'smallint', 'numeric'].includes(type) ? 'int'
      : type.startsWith('timestamp') ? 'datetime'
      : type === 'date' ? 'date' : 'text';
    return { name: String(r.name), kind };
  }));
}

/** Byte (code point) order, independent of the server collation. */
function sortColumns(cols: Column[]): Column[] {
  return cols.sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
}

/** SELECT that returns every column as canonical text (except JSON, normalised in JS). */
export function selectCanonical(kind: 'mysql' | 'postgres', table: string, columns: Column[]): string {
  if (kind === 'mysql') {
    // dateStrings: true already returns DATETIME/DATE as text exactly as stored.
    return `SELECT ${columns.map((c) => '`' + c.name + '`').join(', ')} FROM \`${table}\` ORDER BY id`;
  }
  const expr = (c: Column): string => {
    const col = `"${c.name}"`;
    switch (c.kind) {
      case 'datetime': return `replace(to_char(${col} AT TIME ZONE '${TIME_ZONE}', 'YYYY-MM-DD HH24:MI:SS.US'), '.000000', '') AS ${col}`;
      case 'date': return `to_char(${col}, 'YYYY-MM-DD') AS ${col}`;
      case 'bool': return `(${col})::int::text AS ${col}`;
      case 'time': return `to_char(${col}, 'HH24:MI:SS') AS ${col}`;
      case 'json': return `${col}::text AS ${col}`;
      default: return `${col}::text AS ${col}`;
    }
  };
  return `SELECT ${columns.map(expr).join(', ')} FROM public."${table}" AS t ORDER BY t.id`; // qualified: the text alias "id" would sort as text
}

/** JSON with object keys sorted recursively (jsonb does not keep key order). */
export function canonicalJson(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(canonicalJson).join(',')}]`;
  if (value !== null && typeof value === 'object') {
    const obj = value as Record<string, unknown>;
    return `{${Object.keys(obj).sort().map((k) => `${JSON.stringify(k)}:${canonicalJson(obj[k])}`).join(',')}}`;
  }
  return JSON.stringify(value);
}

const escape = (s: string): string => s.replace(/\\/g, '\\\\').replace(/\|/g, '\\|').replace(/\n/g, '\\n').replace(/\r/g, '\\r');

export function canonicalValue(value: unknown, kind: ColumnKind): string {
  if (value === null || value === undefined) return '\\N';
  if (kind === 'json') {
    const parsed = typeof value === 'string' ? JSON.parse(value) : value;
    return escape(canonicalJson(parsed));
  }
  if (Buffer.isBuffer(value)) return escape(value.toString('utf8'));
  return escape(String(value));
}

export function canonicalLine(row: Row, columns: Column[]): string {
  return columns.map((c) => canonicalValue(row[c.name], c.kind)).join('|') + '\n';
}

export interface TableFingerprint {
  count: number; sha256: string; max_id: number;
  /** Canonical rows by id, kept in memory only to explain a mismatch (never printed). */
  rows?: Map<string, string[]>;
}

export async function tableFingerprint(db: Db, table: string, columns: Column[], keepRows = false): Promise<TableFingerprint> {
  const rows = await db.query(selectCanonical(db.kind, table, columns));
  const hash = createHash('sha256');
  hash.update(columns.map((c) => c.name).join('|') + '\n');
  let maxId = 0;
  const kept = keepRows ? new Map<string, string[]>() : undefined;
  for (const row of rows) {
    hash.update(canonicalLine(row, columns));
    maxId = Math.max(maxId, Number(row.id));
    kept?.set(String(row.id), columns.map((c) => canonicalValue(row[c.name], c.kind)));
  }
  return { count: rows.length, sha256: hash.digest('hex'), max_id: maxId, rows: kept };
}

/** Where two fingerprints differ: ids and column NAMES only (no values, so no personal data). */
export function explainDifference(a: TableFingerprint, b: TableFingerprint, columns: Column[], limit = 5): string[] {
  if (!a.rows || !b.rows) return [];
  const out: string[] = [];
  const ids = new Set([...a.rows.keys(), ...b.rows.keys()]);
  for (const id of ids) {
    const x = a.rows.get(id);
    const y = b.rows.get(id);
    if (!x || !y) out.push(`id ${id}: ${x ? 'missing in target' : 'only in target'}`);
    else {
      const cols = columns.filter((_, i) => x[i] !== y[i]).map((c) => c.name);
      if (cols.length) out.push(`id ${id}: column(s) ${cols.join(', ')}`);
    }
    if (out.length >= limit) break;
  }
  return out;
}
