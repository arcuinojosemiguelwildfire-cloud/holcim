// QR migration verification - TypeScript port of backend/services/QrMigrationVerifier.php.
// MUST stay byte-compatible with the PHP version: same records, same canonical
// format, same SHA-256 fingerprints, same comparison rules. A manifest saved by
// either tool can be compared by the other, against MySQL or PostgreSQL.
//
// Canonical dataset, one line per record:
//   event_id|attendee_id|attendee_code|full_name|company|department|token\n
//   sorted by event_id (numeric), attendee_code (byte order), attendee_id, token (byte order);
//   NULL -> \N; inside values "\" -> "\\", "|" -> "\|", LF -> "\n", CR -> "\r";
//   UTF-8 bytes exactly as stored; tokens never trimmed / lower-cased / normalised.
// Identity fingerprint: event_id|attendee_code|token lines, byte-sorted.
import { createHash } from 'node:crypto';
import type { Db, Row } from './db.ts';

export const MANIFEST_VERSION = 1;
export const CURRENT_TOKEN_PATTERN = /^[A-Za-z0-9_-]{32}$/;
export const SCANNABLE_TOKEN_PATTERN = /^[A-Za-z0-9_-]{16,64}$/;
const LIST_LIMIT = 50;

export interface QrRecord {
  event_id: number;
  attendee_id: number;
  attendee_code: string;
  full_name: string | null;
  company: string | null;
  department: string | null;
  token: string | null;
}

export interface Issues {
  missing_qr: string[];
  orphan_qr: number[];
  duplicate_tokens: string[];
  duplicate_attendee_qr: string[];
  missing_attendee_code: number[];
  empty_token: string[];
  invalid_token_format: string[];
  event_missing: string[];
  scan_qr_mismatch: number;
}

export interface Snapshot {
  event_scope: number | null;
  attendee_count: number;
  attendees_with_qr: number;
  qr_count: number;
  records: QrRecord[];
  issues: Issues;
  warnings: { non_standard_token_length: string[]; archived_without_qr: number };
  fingerprint: string;
  identity_fingerprint: string;
}

export interface Manifest {
  manifest_version: number;
  database: string;
  generated_at: string;
  event_scope: number | null;
  attendee_count: number;
  qr_count: number;
  missing_qr: number;
  duplicate_tokens: number;
  canonical_format: string;
  fingerprint: string;
  identity_fingerprint: string;
  records: QrRecord[];
}

const byteCompare = (a: string, b: string): number => Buffer.compare(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8'));
const num = (v: unknown): number => Number(v);
const str = (v: unknown): string | null => (v === null || v === undefined ? null : String(v));

/** Reads the QR identity dataset and integrity counts. Never writes (read-only transaction, rolled back). */
/** inTransaction: the caller already opened a transaction (the import tool); use it as-is. */
export async function snapshot(db: Db, eventId: number | null = null, options: { inTransaction?: boolean } = {}): Promise<Snapshot> {
  let events: number[];
  let attendees: Row[];
  let qrs: Row[];
  let scanLinks: Row[];
  if (!options.inTransaction) await db.beginReadOnly();
  try {
    events = (await db.query('SELECT id FROM events')).map((r) => num(r.id));
    attendees = await db.query('SELECT id, event_id, attendee_code, full_name, company, department, status FROM attendees');
    qrs = await db.query('SELECT id, attendee_id, token FROM attendee_qr_codes');
    scanLinks = await db.query('SELECT r.attendee_id, q.attendee_id AS qr_attendee_id FROM registration_scans r JOIN attendee_qr_codes q ON q.id = r.qr_code_id');
  } finally {
    if (!options.inTransaction) await db.rollback();
  }

  const byId = new Map<number, Row>(attendees.map((a) => [num(a.id), a]));
  const inScope = (a: Row): boolean => eventId === null || num(a.event_id) === eventId;
  const records: QrRecord[] = [];
  const orphans: number[] = [];
  const qrPerAttendee = new Map<number, number>();
  for (const qr of qrs) {
    const a = byId.get(num(qr.attendee_id));
    if (!a) {
      orphans.push(num(qr.id));
      continue;
    }
    if (!inScope(a)) continue;
    qrPerAttendee.set(num(a.id), (qrPerAttendee.get(num(a.id)) ?? 0) + 1);
    records.push({
      event_id: num(a.event_id),
      attendee_id: num(a.id),
      attendee_code: String(a.attendee_code),
      full_name: str(a.full_name),
      company: str(a.company),
      department: str(a.department),
      token: str(qr.token),
    });
  }
  const sorted = sortRecords(records);

  const missingQr: string[] = [];
  let archivedWithoutQr = 0;
  let attendeesInScope = 0;
  for (const [id, a] of byId) {
    if (!inScope(a)) continue;
    attendeesInScope++;
    if (!qrPerAttendee.has(id)) {
      if (a.status === 'active') missingQr.push(String(a.attendee_code));
      else archivedWithoutQr++;
    }
  }

  const tokenCount = new Map<string, number>();
  for (const r of sorted) tokenCount.set(String(r.token), (tokenCount.get(String(r.token)) ?? 0) + 1);
  const codesWhere = (test: (r: QrRecord) => boolean): string[] => [...new Set(sorted.filter(test).map((r) => r.attendee_code))];
  // JS "$" (no m flag) also rejects a trailing newline, which PHP's "$" would allow;
  // that only makes this port stricter on corrupted data.
  const scannable = (t: string | null): boolean => t !== null && SCANNABLE_TOKEN_PATTERN.test(t);

  const issues: Issues = {
    missing_qr: missingQr,
    orphan_qr: orphans,
    duplicate_tokens: codesWhere((r) => r.token !== null && (tokenCount.get(r.token) ?? 0) > 1),
    duplicate_attendee_qr: codesWhere((r) => (qrPerAttendee.get(r.attendee_id) ?? 0) > 1),
    missing_attendee_code: sorted.filter((r) => r.attendee_code.trim() === '').map((r) => r.attendee_id),
    empty_token: codesWhere((r) => r.token === null || r.token === ''),
    invalid_token_format: codesWhere((r) => r.token !== null && r.token !== '' && !scannable(r.token)),
    event_missing: codesWhere((r) => !events.includes(r.event_id)),
    scan_qr_mismatch: scanLinks.filter((s) => num(s.attendee_id) !== num(s.qr_attendee_id)).length,
  };

  return {
    event_scope: eventId,
    attendee_count: attendeesInScope,
    attendees_with_qr: qrPerAttendee.size,
    qr_count: sorted.length,
    records: sorted,
    issues,
    warnings: {
      non_standard_token_length: codesWhere((r) => scannable(r.token) && !CURRENT_TOKEN_PATTERN.test(String(r.token))),
      archived_without_qr: archivedWithoutQr,
    },
    fingerprint: fingerprint(sorted),
    identity_fingerprint: identityFingerprint(sorted),
  };
}

export function issueCount(issues: Issues): number {
  return Object.values(issues).reduce<number>((n, v) => n + (Array.isArray(v) ? v.length : Number(v)), 0);
}

export function sortRecords(records: QrRecord[]): QrRecord[] {
  return [...records].sort((a, b) =>
    (a.event_id - b.event_id)
    || byteCompare(a.attendee_code, b.attendee_code)
    || (a.attendee_id - b.attendee_id)
    || byteCompare(String(a.token ?? ''), String(b.token ?? '')));
}

function line(fields: unknown[]): string {
  return fields.map((v) => (v === null || v === undefined ? '\\N'
    : String(v).replace(/\\/g, '\\\\').replace(/\|/g, '\\|').replace(/\n/g, '\\n').replace(/\r/g, '\\r'))).join('|') + '\n';
}

export function fingerprint(records: QrRecord[]): string {
  const hash = createHash('sha256');
  for (const r of records) hash.update(line([r.event_id, r.attendee_id, r.attendee_code, r.full_name, r.company, r.department, r.token]), 'utf8');
  return hash.digest('hex');
}

export function identityFingerprint(records: QrRecord[]): string {
  const lines = records.map((r) => line([r.event_id, r.attendee_code, r.token])).sort(byteCompare);
  return createHash('sha256').update(lines.join(''), 'utf8').digest('hex');
}

/** ISO-8601 with the Asia/Manila offset (like PHP DATE_ATOM in the app time zone). */
export function manilaNow(): string {
  return new Date(Date.now() + 8 * 3600_000).toISOString().replace(/\.\d{3}Z$/, '+08:00');
}

export function buildManifest(snap: Snapshot, database: string): Manifest {
  return {
    manifest_version: MANIFEST_VERSION,
    database,
    generated_at: manilaNow(),
    event_scope: snap.event_scope,
    attendee_count: snap.attendee_count,
    qr_count: snap.qr_count,
    missing_qr: snap.issues.missing_qr.length,
    duplicate_tokens: snap.issues.duplicate_tokens.length,
    canonical_format: 'event_id|attendee_id|attendee_code|full_name|company|department|token + LF per record; sorted by event_id, attendee_code (bytes), attendee_id, token; NULL = \\N; escapes \\\\ \\| \\n \\r',
    fingerprint: snap.fingerprint,
    identity_fingerprint: snap.identity_fingerprint,
    records: snap.records,
  };
}

export interface Comparison {
  matching: string[];
  changed_tokens: string[];
  missing: string[];
  unexpected: string[];
  changed_codes: string[];
  relationship_changes: string[];
  event_mismatch: string[];
  id_changes: string[];
  detail_changes: { full_name?: string[]; company?: string[]; department?: string[] };
  expected_count: number;
  actual_count: number;
  pass: boolean;
}

/** Same rules as the PHP compare(): identity = event_id + attendee_code + exact token. */
export function compare(manifest: Manifest, snap: Snapshot): Comparison {
  const key = (r: QrRecord): string => `${r.event_id}\0${r.attendee_code}`;
  const expByKey = new Map(manifest.records.map((r) => [key(r), r]));
  const actByKey = new Map(snap.records.map((r) => [key(r), r]));
  const actByToken = new Map<string, QrRecord[]>();
  for (const r of snap.records) {
    if (r.token === null) continue;
    actByToken.set(r.token, [...(actByToken.get(r.token) ?? []), r]);
  }
  const res: Comparison = {
    matching: [], changed_tokens: [], missing: [], unexpected: [], changed_codes: [],
    relationship_changes: [], event_mismatch: [], id_changes: [], detail_changes: {},
    expected_count: manifest.records.length, actual_count: snap.records.length, pass: false,
  };
  const accounted = new Set<string>();
  for (const e of manifest.records) {
    const k = key(e);
    const a = actByKey.get(k);
    if (a) {
      accounted.add(k);
      if (a.token === e.token) {
        res.matching.push(e.attendee_code);
        if (a.attendee_id !== e.attendee_id) res.id_changes.push(e.attendee_code);
        for (const field of ['full_name', 'company', 'department'] as const) {
          if (a[field] !== e[field]) (res.detail_changes[field] ??= []).push(e.attendee_code);
        }
      } else {
        res.changed_tokens.push(e.attendee_code);
      }
      for (const other of actByToken.get(String(e.token)) ?? []) {
        if (key(other) !== k) res.relationship_changes.push(`${e.attendee_code} -> ${other.attendee_code}`);
      }
      continue;
    }
    const holders = actByToken.get(String(e.token)) ?? [];
    if (holders.length === 0) {
      res.missing.push(e.attendee_code);
      continue;
    }
    for (const h of holders) {
      accounted.add(key(h));
      if (h.event_id !== e.event_id) res.event_mismatch.push(`${e.attendee_code} (event ${e.event_id} -> ${h.event_id})`);
      else res.changed_codes.push(`${e.attendee_code} -> ${h.attendee_code}`);
    }
  }
  for (const a of snap.records) {
    if (!accounted.has(key(a)) && !expByKey.has(key(a))) res.unexpected.push(a.attendee_code);
  }
  res.relationship_changes = [...new Set(res.relationship_changes)];
  const failures = res.changed_tokens.length + res.missing.length + res.unexpected.length + res.changed_codes.length
    + res.relationship_changes.length + res.event_mismatch.length + issueCount(snap.issues);
  res.pass = failures === 0 && res.matching.length === manifest.records.length;
  return res;
}

/** The manifest's records still match its own fingerprints (not edited). */
export function manifestIsIntact(m: Partial<Manifest>): boolean {
  if (!Array.isArray(m.records) || typeof m.fingerprint !== 'string' || typeof m.identity_fingerprint !== 'string') return false;
  for (const r of m.records) {
    if (typeof r !== 'object' || r === null || !('token' in r) || r.event_id === undefined || r.attendee_id === undefined || r.attendee_code === undefined) return false;
  }
  const sorted = sortRecords(m.records);
  return fingerprint(sorted) === m.fingerprint && identityFingerprint(sorted) === m.identity_fingerprint;
}

export function listed(items: unknown[]): string {
  const shown = items.slice(0, LIST_LIMIT).map(String).join(', ');
  return items.length > LIST_LIMIT ? `${shown}, … (+${items.length - LIST_LIMIT} more)` : shown;
}
