// Pure unit tests (no database): canonical rendering, fingerprints and manifest comparison.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canonicalJson, canonicalValue } from '../lib/tables.ts';
import { buildManifest, compare, fingerprint, identityFingerprint, manifestIsIntact, sortRecords, type QrRecord, type Snapshot } from '../lib/qr-verifier.ts';

const rec = (id: number, code: string, token: string, extra: Partial<QrRecord> = {}): QrRecord =>
  ({ event_id: 1, attendee_id: id, attendee_code: code, full_name: `N${id}`, company: null, department: null, token, ...extra });

const snap = (records: QrRecord[]): Snapshot => {
  const sorted = sortRecords([...records]);
  return {
    event_scope: null, records: sorted, attendee_count: records.length, attendees_with_qr: records.length, qr_count: records.length,
    issues: { missing_qr: [], orphan_qr: [], duplicate_tokens: [], duplicate_attendee_qr: [], missing_attendee_code: [], empty_token: [], invalid_token_format: [], event_missing: [], scan_qr_mismatch: 0 },
    warnings: { non_standard_token_length: [], archived_without_qr: 0 },
    fingerprint: fingerprint(sorted), identity_fingerprint: identityFingerprint(sorted),
  };
};

test('canonical JSON sorts keys recursively and keeps arrays in order', () => {
  assert.equal(canonicalJson({ b: 1, a: [3, { d: null, c: 'x' }] }), '{"a":[3,{"c":"x","d":null}],"b":1}');
  assert.equal(canonicalValue('{"b": 2, "a": 1}', 'json'), canonicalValue({ a: 1, b: 2 }, 'json'));
});

test('canonical values escape separators and keep case/whitespace', () => {
  assert.equal(canonicalValue(null, 'text'), '\\N');
  assert.equal(canonicalValue('a|b\\c\nd\re', 'text'), 'a\\|b\\\\c\\nd\\re');
  assert.notEqual(canonicalValue(' x', 'text'), canonicalValue('x', 'text'));
  assert.notEqual(canonicalValue('AbC', 'text'), canonicalValue('abc', 'text'));
});

test('QR fingerprint is order independent and byte exact (case, "-" vs "_")', () => {
  const a = [rec(1, 'A1', 'Token_AAAAAAAAAAAAAAAAAAAAAAAAAA'), rec(2, 'A2', 'token-BBBBBBBBBBBBBBBBBBBBBBBBBB')];
  assert.equal(snap(a).fingerprint, snap([...a].reverse()).fingerprint);
  assert.notEqual(snap(a).fingerprint, snap([rec(1, 'A1', 'token_AAAAAAAAAAAAAAAAAAAAAAAAAA'), a[1]!]).fingerprint);
  assert.notEqual(snap(a).identity_fingerprint, snap([rec(1, 'A1', 'Token-AAAAAAAAAAAAAAAAAAAAAAAAAA'), a[1]!]).identity_fingerprint);
});

test('compare: renumbered ids pass, changed token / swapped tokens fail, tampered manifest detected', () => {
  const base = [rec(1, 'A1', 'tok1-AAAAAAAAAAAAAAAAAAAAAAAAAAA'), rec(2, 'A2', 'tok2_BBBBBBBBBBBBBBBBBBBBBBBBBBB')];
  const manifest = buildManifest(snap(base), 'unit');
  assert.ok(manifestIsIntact(manifest));
  const renumbered = compare(manifest, snap([rec(11, 'A1', base[0]!.token as string), rec(12, 'A2', base[1]!.token as string)]));
  assert.ok(renumbered.pass);
  assert.equal(renumbered.id_changes.length, 2);
  const swapped = compare(manifest, snap([rec(1, 'A1', base[1]!.token as string), rec(2, 'A2', base[0]!.token as string)]));
  assert.ok(!swapped.pass);
  const flipped = compare(manifest, snap([rec(1, 'A1', 'TOK1-AAAAAAAAAAAAAAAAAAAAAAAAAAA'), base[1]!]));
  assert.ok(!flipped.pass);
  const tampered = { ...manifest, records: manifest.records.map((r, i) => (i === 0 ? { ...r, token: 'x' + String(r.token).slice(1) } : r)) };
  assert.ok(!manifestIsIntact(tampered));
});
