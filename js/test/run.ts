// Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
// Conformance runner for the TypeScript port. Run: node js/test/run.ts (Node 22.18 or newer strips the types).
import { readFileSync, readdirSync, writeFileSync, unlinkSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Toklex, ToklexError } from '../src/index.ts';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const FX = join(ROOT, 'spec', 'fixtures');
let n = 0;
const fails: string[] = [];
type O = Record<string, unknown>;

/** Strict equality, except that two numbers are equal when they differ by less than 1e-9, and an empty object equals an empty list as PHP arrays do. */
function same(a: unknown, b: unknown): boolean {
  const empty = (x: unknown) => typeof x === 'object' && x !== null && Object.keys(x).length === 0;
  if (empty(a) && empty(b)) return true;
  if (Array.isArray(a) && Array.isArray(b)) return a.length === b.length && a.every((x, i) => same(x, b[i]));
  if (typeof a === 'object' && a !== null && typeof b === 'object' && b !== null && !Array.isArray(a) && !Array.isArray(b)) {
    const ka = Object.keys(a), kb = Object.keys(b);
    return ka.length === kb.length && ka.every((k) => Object.prototype.hasOwnProperty.call(b, k) && same((a as O)[k], (b as O)[k]));
  }
  if (typeof a === 'number' && typeof b === 'number') return Math.abs(a - b) < 1e-9;
  return a === b;
}

function check(name: string, expected: unknown, actual: unknown): void {
  n++;
  if (!same(expected, actual)) fails.push(`FAIL ${name}\n  expected: ${JSON.stringify(expected)}\n  actual:   ${JSON.stringify(actual)}`);
}

function raises(f: () => unknown): boolean {
  try {
    f();
  } catch (e) {
    return e instanceof ToklexError;
  }
  return false;
}

const load = (p: string): any => JSON.parse(readFileSync(join(FX, p), 'utf8'));
const files = (d: string) => readdirSync(join(FX, d)).filter((f) => f.endsWith('.json')).sort();
const tmp = mkdtempSync(join(tmpdir(), 'tx-'));

// schema loading, version, schema text, tools, modes, resources
for (const f of files('invalid')) check(`invalid/${f} is rejected`, true, raises(() => Toklex.fromFile(join(FX, 'invalid', f))));
check('missing file is rejected', true, raises(() => Toklex.fromFile(join(FX, 'nope.json'))));
writeFileSync(join(tmp, 'junk.json'), 'not json');
check('non-JSON file is rejected', true, raises(() => Toklex.fromFile(join(tmp, 'junk.json'))));

const tx = Toklex.fromFile(join(FX, 'test.schema.json'));
const v = tx.version();
check('version matches hash.json', load('hash.json').version, v);
writeFileSync(join(tmp, 'crlf.json'), readFileSync(join(FX, 'test.schema.json'), 'utf8').replaceAll('\r\n', '\n').replaceAll('\n', '\r\n'));
check('CRLF copy has the same version', v, Toklex.fromFile(join(tmp, 'crlf.json')).version());
unlinkSync(join(tmp, 'crlf.json'));
unlinkSync(join(tmp, 'junk.json'));

const text = tx.schemaText();
const contract = `Toklex reply ~tx@${v}. If this version is not in context, call toklex_schema first. Never guess a code.`;
const tools = tx.withTools([{ name: 'get_station', description: 'Get a station.', inputSchema: { type: 'object', properties: {} } }]);
check('withTools returns the tool plus the two Toklex tools', ['get_station', 'toklex_schema', 'toklex_calibrate'], tools.map((t) => t.name));
check('first description carries the contract', 'Get a station.\n\n' + contract, tools[0].description);
const warnings: string[] = [];
tx.withTools([{ name: 'big', description: 'x'.repeat(2100) }], (m) => warnings.push(m));
check('2100-char description gives one warning to the callback', 1, warnings.length);
warnings.length = 0;
const os = tx.withTools([{ name: 'a', outputSchema: { type: 'object' } }], (m) => warnings.push(m));
check('withTools removes outputSchema and says so', [false, 1], ['outputSchema' in os[0], warnings.length]);

load('modes/args.json').forEach((c: any, i: number) => {
  const args = { ...c.args };
  check(`modes/args.json #${i} mode`, c.expected_mode, tx.mode(c.tool, args));
  check(`modes/args.json #${i} args after`, c.expected_args_after, args);
});
const odd: O = { toklex_mode: ['dense'] };
check('non-string toklex_mode falls back to the default and is removed', ['safe', {}], [tx.mode('get_station', odd), odd]);
check('resources lists the two URIs', ['toklex://modes', 'toklex://schema'], tx.resources().map((r) => r.uri));
check('schema resource equals schemaText', text, tx.readResource('toklex://schema'));
check('unknown resource is null', null, tx.readResource('toklex://nope'));
const modes = tx.readResource('toklex://modes');

// every case, encode byte for byte and read back
const cases = files('cases');
const schemas = new Map<string, Toklex>();
for (const f of cases) {
  const name = f.slice(0, -5);
  const c = load(join('cases', f));
  if (!schemas.has(c.schema)) schemas.set(c.schema, Toklex.fromFile(join(FX, c.schema)));
  const cx = schemas.get(c.schema)!;
  const cv = cx.version();
  const exp = c.expected.replaceAll('{v}', cv);
  const got = 'error' in c ? cx.error(c.error, c.mode ?? null, c.instructions ?? true) : cx.encode(c.tool, c.rows, c.meta, c.mode ?? null, c.instructions ?? true);
  check(`${name} encode`, exp, got);
  const d = cx.decode(exp);
  check(`${name} decode schema`, [load(c.schema).id, cv, c.decoded_instructions ?? true], [d.schema, d.version, d.instructions]);
  if ('error' in c) {
    check(`${name} decode meta`, c.decoded_meta, d.meta);
    check(`${name} decode rows`, [], d.rows);
    continue;
  }
  check(`${name} decode rows`, c.decoded ?? c.rows, d.rows);
  const meta = c.decoded_meta ?? (c.mode === 'off' ? c.meta : Object.fromEntries(Object.entries(c.meta).filter(([, x]) => x !== null)));
  check(`${name} decode meta`, meta, d.meta);
}

const spec = readFileSync(join(ROOT, 'spec', 'toklex.md'), 'utf8').replaceAll('\r\n', '\n');
const cnt = /The folder `cases\/` holds (\d+) files: (\d+) coded cases [\s\S]*? and (\d+) plain cases [\s\S]*?The folder `invalid\/` holds (\d+) schemas\./.exec(spec)!;
const coded = cases.filter((f) => Number(f.slice(0, 2)) < 40).length;
check('the fixture folders hold what section 10 counts', cnt.slice(1).map(Number), [cases.length, coded, cases.length - coded, files('invalid').length]);

const hd = `~tx@${v} i:+`;
const tp = Toklex.fromFile(join(FX, 'plain.schema.json'));
const lastLine = (row: O) => tx.encode('get_station', [row]).split('\n').pop();
check('a very small and a very large float have no exponent', 'x|0.00000015|100000000000000000000', lastLine({ k: 'x', a: 1.5e-7, b: 1e20 }));
check('shortest digits, scaled on decimal digits', ['x|0.1|12345678.9|-3.25', `${hd}\nv\n2\n-2\n1`],
  [lastLine({ k: 'x', a: 0.1, b: 12345678.9, c: -3.25 }), tx.encode('get_station', [0.00015, -0.00015, 0.0001499999999999999].map((x) => ({ rain_m: x })))]);
check('INF, -INF and NaN are empty cells', Array(3).fill(`${hd}\nk\n|`), [Infinity, -Infinity, NaN].map((x) => tx.encode('get_station', [{ k: x }])));
check('a non-finite header value is left out', hd, tx.encode('get_station', [], { k: NaN }));
check('meta keys i and e are reserved', [true, true], [raises(() => tx.encode('get_station', [], { i: 'x' })), raises(() => tx.encode('get_station', [], { e: 'x' }, 'off'))]);
check('a lone surrogate becomes U+FFFD', `${hd}\nid\na�b`, tx.encode('get_station', [{ station_id: 'a\ud800b' }]));
check('dates with a step of 7 are a sequence', `${hd}\n[3]\nt:2026-07-01+7d`, tx.encode('station_history', ['2026-07-01', '2026-07-08', '2026-07-15'].map((date) => ({ date }))));
check('a sequence crosses a leap day', `${hd}\n[3]\nt:2028-02-28+1d`, tx.encode('station_history', ['2028-02-28', '2028-02-29', '2028-03-01'].map((date) => ({ date }))));
check('an impossible date is not a sequence', `${hd}\n[3]\nt:2026-02-27|2026-02-28|2026-02-29`, tx.encode('station_history', ['2026-02-27', '2026-02-28', '2026-02-29'].map((date) => ({ date }))));
check('a date sequence reads back as N dates', ['2026-07-01', '2026-07-08', '2026-07-15'], tx.decode(`${hd}\n[3]\nt:2026-07-01+7d`).rows.map((r: any) => r.date));
check('a run longer than the record count is cut at N', [{ name: 'x' }, { name: 'x' }], tx.decode(`${hd}\n[2]\nn:x*9`).rows);
const wide = (k: number) => Array.from({ length: k }, (_, i) => `c${i}:x*100000`).join('\n');
check('a forged record or cell count is refused', [true, true], [raises(() => tx.decode(`${hd}\n[100001]\nn:x`)), raises(() => tx.decode(`${hd}\n[100000]\n${wide(200)}`))]);
check('the cell limit itself still decodes', 100000, tx.decode(`${hd}\n[100000]\n${wide(10)}`).rows.length);
check('an escaped name never splits into two names in any mode', [{ 'a|b': 'x', 'my key': 'y' }, { 'k:x y': 'z' }],
  [tp.decode(tp.encode('station_history', [{ 'a|b': 'x', 'my key': 'y' }])).rows[0], tp.decode(tp.encode('get_station', [], { 'k:x y': 'z' })).meta]);

class Point {
  x: number;
  #hidden = 1;
  constructor(x: number) {
    this.x = x;
  }
  hidden(): number {
    return this.#hidden;
  }
}
check('a Date at midnight UTC is the date alone and stays a date sequence', `${hd}\n[3]\nt:2026-07-01+1d`,
  tx.encode('station_history', [1, 2, 3].map((d) => ({ date: new Date(Date.UTC(2026, 6, d)) }))));
check('any other Date is ISO 8601 with +00:00, and a fraction only when there is one',
  `${hd}\nk\n2026-07-01T10:30:00+00:00\n2026-07-01T10:30:00.250000+00:00`,
  tx.encode('get_station', [{ k: new Date(Date.UTC(2026, 6, 1, 10, 30)) }, { k: new Date(Date.UTC(2026, 6, 1, 10, 30, 0, 250)) }]));
check('toJSON, a class instance, a Map, a Set and a bigint travel as JSON would carry them', `${hd}\nid|k.x|m.a|s|big\na|3|1|[1,2]|12345678901234567890`,
  tx.encode('get_station', [{ station_id: 'a', k: new Point(3), m: new Map([['a', 1]]), s: new Set([1, 2]), big: 12345678901234567890n }]));
check('undefined is absent, as JSON.stringify leaves it out', `${hd}\nid\na`, tx.encode('get_station', [{ station_id: 'a', gone: undefined }]));
check('encode refuses a record that is not an object, in every mode', [true, true, true],
  [raises(() => tx.encode('get_station', ['x'])), raises(() => tx.encode('get_station', [1], {}, 'off')), raises(() => tx.encode('get_station', [null]))]);
check('decode refuses an off reply that is not JSON or has no schema', [true, true], [raises(() => tx.decode('{not json')), raises(() => tx.decode('{"a":1}'))]);
check('a plain error without instructions starts with the header and reads back', [`~tp@${tp.version()} error:boom`, false],
  [tp.error('boom', null, false), tp.decode(tp.error('boom', null, false)).instructions]);
check('auto with no records is the header alone', tp.encode('get_station', []), tp.encode('station_log', []));

// calibration
const cal = tx.calibrate(null);
const right = { s1: '2026-03-05', s2: '0.0823', s3: '3', d1: 'MEDIUM', d2: '2026-03-07', d3: '0.0917' };
check('all correct is dense, the reply shape is exact', '{"recommended":"dense","results":{"s1":true,"s2":true,"s3":true,"d1":true,"d2":true,"d3":true}}', tx.calibrate(right));
check('only the s answers correct is safe', 'safe', JSON.parse(tx.calibrate({ s1: right.s1, s2: right.s2, s3: right.s3 })).recommended);
check('one wrong s and one wrong d is off', 'off', JSON.parse(tx.calibrate({ ...right, s2: 'x', d1: 'x' })).recommended);
check('numbers and strings compare by decimal text', 'dense', JSON.parse(tx.calibrate({ ...right, s2: 0.0823, s3: 3, d3: 0.0917 })).recommended);
check('answers are trimmed, comparison is case-sensitive', [true, false], [JSON.parse(tx.calibrate({ ...right, d1: ' MEDIUM\n' })).results.d1, JSON.parse(tx.calibrate({ ...right, d1: 'medium' })).results.d1]);
check('the arguments object and a JSON string grade like the bare map', [tx.calibrate(right), tx.calibrate(right)], [tx.calibrate({ answers: right }), tx.calibrate({ answers: JSON.stringify(right) })]);
check('malformed answers return the dataset', Array(5).fill(cal), [tx.calibrate({ answers: [] }), tx.calibrate(['a']), tx.calibrate({}), tx.calibrate({ answers: 'x' }), tx.calibrate({ toklex_mode: 'safe' })]);

// spec appendix A: every model-facing text, verbatim
const app = [...spec.slice(spec.indexOf('\n## Appendix A')).matchAll(/^```\w*\n([\s\S]*?)\n```$/gm)].map((m) => m[1].replaceAll('{v}', v));
const pretty = (x: unknown) => JSON.stringify(x, null, 4);
const pt = tp.withTools([{ name: 'get_station', description: 'Get a station.', inputSchema: { type: 'object', properties: {} } }]);
const c41 = load('cases/41-plain-dense.json');
const countLine = /^\[5\] .*$/m.exec(tp.encode(c41.tool, c41.rows, c41.meta))?.[0];
check('appendix A has ten blocks', 10, app.length);
check('appendix A.1 is the schema text', text, app[0]);
check('appendix A.2 is the tool list', pretty(tools), app[1]);
check('appendix A.3 is the resource list', pretty(tx.resources()), app[2]);
check('appendix A.3 is the modes text', modes, app[3]);
check('appendix A.4 is the calibration page', cal, app[4]);
check('appendix A.4 is the graded reply', tx.calibrate(right), app[5]);
check('appendix A.5 is the plain tool list', pretty(pt), app[6]);
check('appendix A.5 is the plain resource list', pretty(tp.resources()), app[7]);
check('appendix A.5 is the plain modes text', tp.readResource('toklex://modes'), app[8]);
check('appendix A.5 is the plain dense count line', countLine, app[9]);

if (fails.length) {
  console.log(fails.join('\n'));
  console.log(`${fails.length} of ${n} checks failed`);
  process.exit(1);
}
console.log(`OK ${n} checks`);
