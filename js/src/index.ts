// Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
// Licensed under Apache-2.0. See LICENSE.
// Toklex for TypeScript: a port of php/src/Toklex.php that passes spec/fixtures.
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

export const SCHEMA_TOOL = 'toklex_schema';
export const CALIBRATE_TOOL = 'toklex_calibrate';

export type Mode = 'off' | 'safe' | 'dense';
export type Json = null | boolean | number | string | Json[] | { [k: string]: Json };
export interface Schema {
  toklex: number;
  id: string;
  style?: 'plain' | 'coded';
  instructions_at?: 'reply' | 'server';
  keys?: Record<string, string>;
  values?: Record<string, Record<string, string>>;
  scale?: Record<string, number>;
  instructions?: Record<string, string>;
  dense?: string[];
  auto?: string[];
}
export interface Decoded {
  schema: string;
  version: string;
  instructions: boolean;
  meta: Record<string, unknown>;
  rows: unknown[];
}
export type Tool = Record<string, unknown> & { name: string };

const MODES: readonly string[] = ['off', 'safe', 'dense'];
const NUM = /^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?$/;
const DEC = /^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/;
const DATE = /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/;
const SEQ = /^([0-9]{4})-([0-9]{2})-([0-9]{2})\+([0-9]+)d$/;
const SEQ_SHAPE = /^[0-9]{4}-[0-9]{2}-[0-9]{2}\+[0-9]+d$/;
const LABEL = /\(÷[0-9]+\)$/;
const WS = /[ \t\n\v\f\r]/;
const BAR = '\\u007c';
const DENSE_LINE = "One line per column: name:v1|v2|… x*K is x repeated K times, start+Nd is a date every N days, an empty cell is null.";
const PRIMER = "Toklex encodes tool results compactly. Line 1 is the header: ~<schema>@<version>, then i:+ when the instructions below apply, then key:value metadata (e: is an error message). Normal mode: line 2 lists the columns and every following line is one record, cells separated by |. An empty cell is null and trailing empty cells are left out. A cell in double quotes is a literal JSON string, never a code. Inside it, \\u007c stands for |. A cell starting with [ is a JSON array that uses the same keys and codes. A dotted column such as us.lg is a nested field. Dense mode: line 2 is [N], the record count, and every following line is key:values for one column in record order, separated by |. x*K means x repeated K times. A value like 2026-07-03+1d means that date for the first record and one more day for each next record. Read codes only from this schema and never guess one. If a response header shows a different version, call toklex_schema again.";
const MODES_TEXT = [
  "Toklex modes",
  "A server that speaks Toklex can answer a tool call in one of three modes.",
  "off: compact JSON with full field names and the full text of the instructions. No codes and no scales.",
  "safe: the normal table. Line 1 is the header, line 2 lists the columns and each following line is one record. This is the default for every tool that the schema does not list as dense.",
  "dense: line 2 is [N], the record count, and each following line holds one column. This is the default for the tools the schema lists as dense.",
  "Pick a mode for one call with the optional argument toklex_mode on a tool of the server. The two Toklex tools, toklex_schema and toklex_calibrate, do not take it. The values are off, safe and dense. If you leave it out or send another value, the tool's default mode applies. The instructions of the server travel in every mode.",
  "Dense is the shortest form. Some models misread it. To learn which mode you read reliably, call toklex_calibrate without arguments. It returns a small dataset in both forms and six questions. Answer them from the dataset alone, then call toklex_calibrate again with answers, an object that maps each question id to your answer. The reply names the mode to use. Use it with toklex_mode on later calls.",
].join('\n');
const PLAIN_MODES_TEXT = [
  "Toklex modes",
  "A server that speaks Toklex can answer a tool call in one of three modes.",
  "off: compact JSON with full field names and numbers as they are.",
  "safe: a table. The line that starts with ~ is the header, the next line names the columns and each following line is one record, cells separated by |. A column name that ends in (÷K) holds the value times K, so divide the cell by K. This is the default for every tool that the server does not list as dense.",
  "dense: after the header, a line starting with [N] gives the record count and how to read the block. Each following line holds one column. This is the default for the tools the server lists as dense.",
  "Pick a mode for one call with the optional argument toklex_mode. The values are off, safe and dense. If you leave it out or send another value, the tool's default mode applies.",
].join('\n');
const MAX_RECORDS = 100000;
const MAX_CELLS = 1000000;
const AUTO_MIN = 10;
// characters that would break a column line or a header, written as \uXXXX (spec 6.1)
const NAME_ESC: Record<string, string> = {
  '\\': '\\u005c', '|': '\\u007c', ':': '\\u003a', '÷': '\\u00f7',
  '\t': '\\u0009', '\n': '\\u000a', '\v': '\\u000b', '\f': '\\u000c', '\r': '\\u000d',
};
const NAME_ESC_HDR: Record<string, string> = { ...NAME_ESC, ' ': '\\u0020' };

// The calibration dataset and its key. They belong to the library and never depend on a server schema.
const CAL_SCHEMA: Schema = {
  toklex: 1, id: 'cal', style: 'coded', keys: { n: 'name', d: 'day', t: 'tier', p: 'price' },
  values: { t: { l: 'LOW', m: 'MEDIUM', h: 'HIGH' } }, scale: { p: 10000 },
};
const CAL_ROWS = [
  { name: 'Anna', day: '2026-03-02', tier: 'LOW', price: 0.0612 },
  { name: 'Boris', day: '2026-03-03', tier: 'LOW', price: 0.0655 },
  { name: 'Chen', day: '2026-03-04', tier: 'MEDIUM', price: 0.0701 },
  { name: 'Dara', day: '2026-03-05', tier: 'MEDIUM', price: 0.0688 },
  { name: 'Emil', day: '2026-03-06', tier: 'MEDIUM', price: 0.0744 },
  { name: 'Faye', day: '2026-03-07', tier: 'HIGH', price: 0.0790 },
  { name: 'Gus', day: '2026-03-08', tier: 'HIGH', price: 0.0823 },
  { name: 'Hana', day: '2026-03-09', tier: 'LOW', price: 0.0917 },
];
const CAL_QUESTIONS: Record<string, [string, string, string]> = {
  s1: ['safe', 'On which day is the record named Dara? Write it as YYYY-MM-DD.', '2026-03-05'],
  s2: ['safe', 'What is the price of the record named Gus? Write the decimal number with four decimals.', '0.0823'],
  s3: ['safe', 'How many records have the tier LOW? Write a number.', '3'],
  d1: ['dense', 'What is the tier of the record dated 2026-03-06? Write the full word.', 'MEDIUM'],
  d2: ['dense', 'On which day does the first record with the tier HIGH fall? Write it as YYYY-MM-DD.', '2026-03-07'],
  d3: ['dense', 'What is the price of the last record? Write the decimal number with four decimals.', '0.0917'],
};
const CAL_HOWTO = [
  "Safe block: line 1 is the header, line 2 lists the columns and each next line is one record, with cells separated by |.",
  "Dense block: line 2 is [8], the record count, and each next line is key:values, one column for all 8 records in record order.",
  "z*3 means z three times. 2026-07-03+1d means 2026-07-03 for the first record and one more day for each next record.",
  "Read every code through the legend. A scaled number is divided by its scale.",
].join('\n');

/** A broken schema, a reserved metadata key, a record that is not a record, or a reply a reader refuses. */
export class ToklexError extends Error {
  override name = 'ToklexError';
}

type Obj = Record<string, unknown>;
const isObj = (x: unknown): x is Obj => typeof x === 'object' && x !== null && !Array.isArray(x);
const isNum = (x: unknown): x is number | bigint => typeof x === 'number' || typeof x === 'bigint';
const own = (o: object, k: string): boolean => Object.prototype.hasOwnProperty.call(o, k);

/** The string with each lone surrogate replaced by U+FFFD, the counterpart of invalid UTF-8 in PHP. */
function u8(s: string): string {
  return s.toWellFormed();
}

function jstr(s: string): string {
  return JSON.stringify(u8(s));
}

/** Strict JSON. Throws on bad input. */
function jload(t: string): unknown {
  return JSON.parse(t);
}

/** The date alone at midnight UTC, so that a date column stays a date sequence. Otherwise the time, a fraction when there is one, and +00:00. */
function iso(d: Date): string {
  const s = d.toISOString();
  if (s.endsWith('T00:00:00.000Z')) {
    return s.slice(0, 10);
  }
  const ms = d.getUTCMilliseconds();
  return s.slice(0, 19) + (ms ? '.' + String(ms * 1000).padStart(6, '0') : '') + '+00:00';
}

/**
 * A value as JSON would carry it (spec 11): a Date as ISO 8601 text, an object with toJSON by what it returns,
 * a Map as an object, a Set as an array, a class instance as its own enumerable properties.
 * undefined, functions and symbols count as absent.
 */
function val(v: unknown): unknown {
  for (let i = 0; i < 8; i++) {
    if (v === null || typeof v !== 'object') {
      return typeof v === 'undefined' || typeof v === 'function' || typeof v === 'symbol' ? undefined : v;
    }
    if (Array.isArray(v)) {
      return v;
    }
    if (v instanceof Date) {
      return Number.isNaN(v.getTime()) ? null : iso(v);
    }
    if (v instanceof Map) {
      return Object.fromEntries([...v].map(([k, x]) => [String(k), x]));
    }
    if (v instanceof Set) {
      return [...v];
    }
    if (typeof (v as { toJSON?: unknown }).toJSON === 'function') {
      v = (v as { toJSON: () => unknown }).toJSON();
      continue;
    }
    return Object.getPrototypeOf(v) === Object.prototype || Object.getPrototypeOf(v) === null ? v : { ...v };
  }
  return v;
}

/** Entries of a map without the absent values, as JSON.stringify leaves them out. */
function entries(o: Obj): [string, unknown][] {
  return Object.entries(o).filter(([, x]) => val(x) !== undefined);
}

/** A number as its shortest decimal text with no exponent. With a scale, that text times the scale in decimal, rounded half away from zero (spec 6.3). Null when it is not finite, also after scaling. */
function num(x: number | bigint, m: number | null | undefined): string | null {
  if (typeof x === 'bigint') {
    return m ? scaled(String(x), num(m, null)!) : String(x);
  }
  if (!Number.isFinite(x) || (m && !Number.isFinite(x * m))) {
    return null;
  }
  if (m) {
    return scaled(num(x, null)!, num(m, null)!);
  }
  if (x === Math.floor(x) && Math.abs(x) < 1e15) {
    return String(Math.trunc(x) || 0);
  }
  // shortest digits that read back as the same number, the same loop as the PHP library
  let s = '';
  for (let p = 0; p <= 16; p++) {
    s = x.toExponential(p);
    if (Number(s) === x) {
      break;
    }
  }
  const mt = /^(-?)([0-9]+)(?:\.([0-9]+))?e([+-]?[0-9]+)$/i.exec(s);
  if (!mt) {
    return s;
  }
  const [, sign, i, f = '', e] = mt;
  const d = i + f;
  const pt = i.length + Number(e);
  const r = pt <= 0 ? '0.' + '0'.repeat(-pt) + d : pt >= d.length ? d + '0'.repeat(pt - d.length) : d.slice(0, pt) + '.' + d.slice(pt);
  return sign + (r.includes('.') ? r.replace(/0+$/, '').replace(/\.$/, '') : r);
}

/** Decimal text x times integer text m, rounded half away from zero, in exact integer arithmetic. */
function scaled(x: string, m: string): string {
  const neg = x.startsWith('-');
  const [i, f = ''] = (neg ? x.slice(1) : x).split('.');
  const p = BigInt(i + f) * BigInt(m);
  const unit = 10n ** BigInt(f.length);
  let q = p / unit;
  if ((p % unit) * 2n >= unit) {
    q += 1n;
  }
  return q === 0n ? '0' : (neg ? '-' : '') + q;
}

/** A literal JSON string in quotes, with | written as backslash u 0 0 7 c. */
function quote(s: string): string {
  return jstr(s).replaceAll('|', BAR);
}

/** A name as a column line writes it (spec 6.1). hdr also escapes a space, which ends a header key. */
function name(n: string, hdr = false): string {
  const esc = hdr ? NAME_ESC_HDR : NAME_ESC;
  let o = '';
  for (const ch of n) {
    o += esc[ch] ?? ch;
  }
  return o.startsWith('[') ? '\\u005b' + o.slice(1) : o;
}

/** A written name back to the name. A written name holds no raw backslash, so every \uXXXX in it is an escape. */
function unname(n: string): string {
  return n.includes('\\u') ? n.replace(/\\u([0-9a-f]{4})/g, (_, h: string) => String.fromCharCode(parseInt(h, 16))) : n;
}

/** A code that a cell, a column name or a header value could not carry as plain text. */
function badCode(c: string): boolean {
  return c === '' || /[|*:"]/.test(c) || WS.test(c) || c[0] === '[' || c[0] === '{' || NUM.test(c) || ['true', 'false', 'null'].includes(c);
}

/** Days since 1970-01-01 in the proleptic Gregorian calendar. A day past the end of a month runs into the next one. */
function daysFromCivil(y: number, m: number, d: number): number {
  y -= m <= 2 ? 1 : 0;
  const era = Math.floor((y >= 0 ? y : y - 399) / 400);
  const yoe = y - era * 400;
  const doy = Math.floor((153 * (m + (m > 2 ? -3 : 9)) + 2) / 5) + d - 1;
  const doe = yoe * 365 + Math.floor(yoe / 4) - Math.floor(yoe / 100) + doy;
  return era * 146097 + doe - 719468;
}

function civilFromDays(z: number): string {
  z += 719468;
  const era = Math.floor((z >= 0 ? z : z - 146096) / 146097);
  const doe = z - era * 146097;
  const yoe = Math.floor((doe - Math.floor(doe / 1460) + Math.floor(doe / 36524) - Math.floor(doe / 146096)) / 365);
  const doy = doe - (365 * yoe + Math.floor(yoe / 4) - Math.floor(yoe / 100));
  const mp = Math.floor((5 * doy + 2) / 153);
  const d = doy - Math.floor((153 * mp + 2) / 5) + 1;
  const m = mp + (mp < 10 ? 3 : -9);
  const p = (x: number, w: number) => String(x).padStart(w, '0');
  return `${p(yoe + era * 400 + (m <= 2 ? 1 : 0), 4)}-${p(m, 2)}-${p(d, 2)}`;
}

/** The day number of a valid ISO date text, else null. */
function day(x: unknown): number | null {
  if (typeof x !== 'string' || !DATE.test(x)) {
    return null;
  }
  const [y, m, d] = [Number(x.slice(0, 4)), Number(x.slice(5, 7)), Number(x.slice(8))];
  if (m < 1 || m > 12 || d < 1) {
    return null;
  }
  const n = daysFromCivil(y, m, d);
  return civilFromDays(n) === x ? n : null;
}

/** <first>+<step>d when there are 3 or more values, all valid ISO dates, with the same positive day step. Otherwise null. */
function days(v: unknown[]): string | null {
  const t: number[] = [];
  for (const x of v) {
    const n = day(x);
    if (n === null) {
      return null;
    }
    t.push(n);
  }
  const step = (t.length > 1 ? t[1] : 0) - t[0];
  for (let i = 1; i < t.length; i++) {
    if (t[i] - t[i - 1] !== step) {
      return null;
    }
  }
  return t.length >= 3 && step > 0 ? `${v[0]}+${step}d` : null;
}

/** A dense column as one line of items. A run of equal cells is cell*K. */
function runs(cells: string[]): string {
  const o: string[] = [];
  for (let i = 0, j = 0; i < cells.length; i = j) {
    for (j = i + 1; j < cells.length && cells[j] === cells[i]; j++);
    o.push(cells[i] + (j - i > 1 ? '*' + (j - i) : ''));
  }
  return o.join('|');
}

/** The N cell texts of one dense column line. A date sequence gives quoted dates, so they stay literals. */
function expand(items: string, n: number): string[] {
  const m = SEQ.exec(items);
  if (m && Number(m[2]) >= 1 && Number(m[2]) <= 12) {
    const d0 = daysFromCivil(Number(m[1]), Number(m[2]), Number(m[3]));
    const step = Number(m[4]);
    return Array.from({ length: n }, (_, i) => '"' + civilFromDays(d0 + i * step) + '"');
  }
  const o: string[] = [];
  for (const it of items.split('|')) {
    if (o.length >= n) {
      break;
    }
    const r = /^([\s\S]*)\*([0-9]+)$/.exec(it);
    const k = r ? Math.min(Number(r[2]), n - o.length) : 1;
    for (let i = 0; i < k; i++) {
      o.push(r ? r[1] : it);
    }
  }
  return o;
}

/** One schema. Load it with Toklex.fromFile(). */
export class Toklex {
  private readonly s: Schema;
  private readonly v: string;
  private readonly kf = new Map<string, string>(); // full name -> code, coded only
  private readonly vf = new Map<string, Map<string, string>>(); // code -> full value -> value code, coded only
  private readonly dk = new Map<string, string>(); // code -> full name for decode, coded only
  private readonly dv = new Map<string, Map<string, string>>(); // code -> value code -> full value for decode, coded only
  private readonly sc = new Map<string, number>(); // scale by the name the encoder writes: the code when coded, the full name when plain

  private constructor(s: Schema, v: string) {
    this.s = s;
    this.v = v;
    const keys = s.keys ?? {};
    const coded = s.style === 'coded';
    if (coded) {
      for (const [c, f] of Object.entries(keys)) {
        this.kf.set(f, c);
        this.dk.set(c, f);
      }
      for (const [f, m] of Object.entries(s.values ?? {})) {
        this.vf.set(f, new Map(Object.entries(m).map(([c, full]) => [full, c])));
        this.dv.set(f, new Map(Object.entries(m)));
      }
    }
    for (const [c, m] of Object.entries(s.scale ?? {})) {
      this.sc.set(coded ? c : keys[c] ?? c, m);
    }
  }

  /** Throws ToklexError when the file is unreadable, not JSON or breaks a load rule. */
  static fromFile(path: string): Toklex {
    let raw: Buffer;
    try {
      raw = readFileSync(path);
    } catch {
      throw new ToklexError(`Toklex schema: cannot read ${path}`);
    }
    return Toklex.fromText(raw.toString('utf8'), path);
  }

  /** A schema file's text. The version hashes the text as UTF-8 with CRLF turned into LF. */
  static fromText(text: string, label = 'the schema'): Toklex {
    let s: unknown;
    try {
      s = JSON.parse(text);
    } catch {
      s = null;
    }
    if (!isObj(s)) {
      throw new ToklexError(`Toklex schema: ${label} is not a JSON object`);
    }
    Toklex.check(s);
    const v = createHash('sha256').update(text.replaceAll('\r\n', '\n'), 'utf8').digest('hex').slice(0, 4);
    return new Toklex(Toklex.norm(s), v);
  }

  private static norm(s: Obj): Schema {
    const o: Obj = { ...s };
    for (const k of ['keys', 'values', 'scale', 'instructions']) {
      if (Array.isArray(o[k])) {
        // an empty JSON list is an empty object to PHP, a list of instructions has index names
        o[k] = Object.fromEntries((o[k] as unknown[]).map((x, i) => [String(i), x]));
      }
    }
    for (const k of ['dense', 'auto']) {
      if (isObj(o[k])) {
        o[k] = Object.values(o[k] as Obj);
      }
    }
    return o as unknown as Schema;
  }

  style(): 'plain' | 'coded' {
    return this.s.style ?? 'plain';
  }

  version(): string {
    return this.v;
  }

  /** The instructions as the model reads them: one "name: text" per block, the text verbatim. It goes into the MCP instructions field. */
  instructionsText(): string {
    return Object.entries(this.s.instructions ?? {}).map(([n, b]) => `${n}: ${b}`).join('\n');
  }

  schemaText(): string {
    const s = this.s;
    const out = [`Toklex schema ${s.id}@${this.v}`, PRIMER];
    if (Object.keys(s.keys ?? {}).length) {
      out.push('Keys: ' + Object.entries(s.keys!).map(([c, f]) => `${c}=${f}`).join(', '));
    }
    if (Object.keys(s.values ?? {}).length) {
      out.push('Value codes: ' + Object.entries(s.values!).map(([f, m]) => `${f}: ` + Object.entries(m).map(([c, full]) => `${c}=${full}`).join(' ')).join('; '));
    }
    if (Object.keys(s.scale ?? {}).length) {
      const ex = (743 / Object.values(s.scale!)[0]).toFixed(12).replace(/0+$/, '').replace(/\.$/, '');
      out.push('Scaled: ' + Object.entries(s.scale!).map(([c, m]) => `${c} x${num(m, null)}`).join(', ') + ` (743 means ${ex})`);
    }
    if (s.dense?.length) {
      out.push('Dense tools: ' + s.dense.join(', '));
    }
    if (Object.keys(s.instructions ?? {}).length) {
      out.push('Instructions (they apply to every response marked i:+):');
      out.push(...Object.entries(s.instructions!).map(([n, b]) => `${n}: ${b}`));
    }
    return out.join('\n');
  }

  /**
   * Adds toklex_mode to every tool and removes outputSchema. Coded style also adds the contract line and appends the
   * two Toklex tools. warn gets each warning as one line, console.error (stderr) when it is absent.
   */
  withTools(tools: Tool[], warn: (msg: string) => void = (m) => console.error(m)): Tool[] {
    const plain = this.style() === 'plain';
    const contract = `Toklex reply ~${this.s.id}@${this.v}. If this version is not in context, call toklex_schema first. Never guess a code.`;
    const out: Tool[] = tools.map((tool) => {
      const t: Tool = { ...tool };
      if (!plain) {
        const d = typeof t.description === 'string' ? t.description + '\n\n' + contract : contract;
        if ([...d].length > 2048) {
          warn(`Toklex: description of tool '${t.name}' is over 2048 characters, a client may cut the contract line`);
        }
        t.description = d;
      }
      // a tool with an outputSchema must return structuredContent, and a Toklex reply is one text block (spec 9)
      if (own(t, 'outputSchema')) {
        delete t.outputSchema;
        warn(`Toklex: tool '${t.name}' loses its outputSchema, a Toklex reply carries no structuredContent`);
      }
      const inp: Obj = { ...((t.inputSchema as Obj | undefined) ?? { type: 'object' }) };
      const props: Obj = { ...((inp.properties as Obj | undefined) ?? {}) };
      if (!own(props, 'toklex_mode')) {
        props.toklex_mode = { type: 'string', enum: [...MODES], description: 'Reply format: off, safe or dense.' };
      }
      inp.properties = props;
      t.inputSchema = inp;
      return t;
    });
    if (plain) {
      return out;
    }
    const ro = (title: string) => ({ title, readOnlyHint: true, openWorldHint: false });
    out.push({
      name: SCHEMA_TOOL, title: 'Toklex schema',
      description: 'Returns the schema (field names, value codes, number scales and instructions) needed to read the Toklex responses of this server. Call it before reading a response whose ~<id>@<version> you do not have in context.',
      inputSchema: { type: 'object', properties: {} }, annotations: ro('Toklex schema'),
    });
    out.push({
      name: CALIBRATE_TOOL, title: 'Toklex calibrate',
      description: 'Finds out which Toklex mode you read reliably. Call it without arguments to get a small dataset with questions. Call it again with answers to get the recommended mode.',
      inputSchema: { type: 'object', properties: { answers: { type: 'object', description: 'Your answers, as an object that maps a question id to the answer.' } } },
      annotations: ro('Toklex calibrate'),
    });
    return out;
  }

  /** Mode of one call. Removes toklex_mode from args, so the tool never sees it. auto when the tool is listed in auto and the call names no mode. */
  mode(tool: string, args: Obj): Mode | 'auto' {
    const m = args.toklex_mode;
    delete args.toklex_mode;
    return typeof m === 'string' && MODES.includes(m) ? (m as Mode) : this.defaultMode(tool);
  }

  /**
   * mode null means the tool's default. instructions false leaves the instruction text out of this reply (spec 5.1).
   * Throws ToklexError on a reserved meta key or a record that is not an object.
   */
  encode(tool: string, rows: readonly unknown[], meta: Obj = {}, mode: string | null = null, instructions = true): string {
    let md = mode !== null && [...MODES, 'auto'].includes(mode) ? mode : this.defaultMode(tool);
    for (const k of Object.keys(meta)) {
      const c = this.kf.get(k) ?? u8(k);
      if ((this.style() === 'plain' ? ['error'] : ['i', 'e']).includes(c)) {
        throw new ToklexError(`Toklex: meta key ${c} is reserved`);
      }
    }
    const recs: Obj[] = [...rows].map((r0, i) => {
      const r = val(r0);
      if (Array.isArray(r)) {
        return Object.fromEntries(r.map((x, j) => [String(j), x]));
      }
      if (!isObj(r)) {
        throw new ToklexError(`Toklex: record ${i} is ${r === null ? 'null' : typeof r}, not a record. Pass a list of records, such as [row] for one record.`);
      }
      return r;
    });
    if (md === 'off') {
      return this.off([['meta', meta], ['rows', recs]], instructions);
    }
    const out = [this.header(meta, instructions)];
    if (!recs.length) {
      return out[0];
    }
    const flat = recs.map((r) => this.flat(r));
    const cols = new Map<string, string>();
    for (const f of flat) {
      for (const [p, [c]] of f) {
        if (!cols.has(p)) {
          cols.set(p, c);
        }
      }
    }
    const col = (p: string) => flat.map((f) => (f.has(p) ? f.get(p)![1] : null));
    if (md === 'auto') {
      // dense pays off on a long series of dates and costs more than safe on a short or wide list (spec 12.2)
      md = flat.length >= AUTO_MIN && [...cols.keys()].some((p) => days(col(p)) !== null) ? 'dense' : 'safe';
    }
    if (md === 'dense') {
      out.push(`[${flat.length}]` + (this.style() === 'plain' ? ' ' + DENSE_LINE : ''));
      for (const [p, c] of cols) {
        const v = col(p);
        out.push(this.label(p, c) + ':' + (days(v) ?? runs(v.map((x) => this.cell(x, c)))));
      }
      return out.join('\n');
    }
    out.push([...cols].map(([p, c]) => this.label(p, c)).join('|'));
    for (const f of flat) {
      const line = [...cols].map(([p, c]) => (f.has(p) ? this.cell(f.get(p)![1], c) : '')).join('|').replace(/\|+$/, '');
      out.push(line === '' ? '|' : line);
    }
    return out.join('\n');
  }

  /** codeOrText is a code from values.e or its full message. An error has no records, so only off differs from a header line. */
  error(codeOrText: string, mode: string | null = null, instructions = true): string {
    const e = this.s.values?.e ?? {};
    const code = own(e, codeOrText) ? codeOrText : Object.keys(e).find((c) => e[c] === codeOrText) ?? null;
    const text = code === null ? codeOrText : e[code];
    if (mode === 'off') {
      return this.off([['error', text]], instructions);
    }
    return this.style() === 'plain' ? this.header({}, instructions) + ' error:' + this.cell(text, 'error', true)
      : this.header({}, instructions) + ' e:' + (code === null ? quote(codeOrText) : code);
  }

  /** Reads a reply back with full key names and values. Throws ToklexError on a reply it refuses (spec 1). */
  decode(text: string): Decoded {
    if (text.startsWith('{')) {
      let j: unknown = null;
      try {
        j = jload(text);
      } catch {
        // refused below
      }
      if (!(isObj(j) && typeof j.schema === 'string' && j.schema.includes('@'))) {
        throw new ToklexError('Toklex: the off reply is not a JSON object with a schema');
      }
      const at = j.schema.indexOf('@');
      return {
        schema: j.schema.slice(0, at), version: j.schema.slice(at + 1), instructions: own(j, 'instructions'),
        meta: own(j, 'error') ? { [this.style() === 'plain' ? 'error' : 'e']: j.error } : ((j.meta as Obj) ?? {}),
        rows: (j.rows as unknown[]) ?? [],
      };
    }
    const plain = this.style() === 'plain';
    // instruction lines start with their name, the header with ~, so a reply that left them out starts with the header
    const k = this.inReply() && !text.startsWith('~') ? Object.keys(this.s.instructions!).length : 0;
    const l = text.split('\n').slice(k);
    const strip = (c: string) => (plain ? c.replace(LABEL, '') : c);
    const h = l.length ? /^~([^@ ]+)@([^ \t\n\v\f\r]+)/.exec(l[0]) : null;
    if (!h) {
      throw new ToklexError('Toklex: the reply has no header line');
    }
    let ins = k > 0;
    const meta: Obj = {};
    for (const m of l[0].matchAll(/ ([^ \t\n\v\f\r:]+):("(?:[^"\\]|\\[\s\S])*"|[^ \t\n\v\f\r]*)/g)) {
      if (m[1] === 'i' && !plain) {
        ins = m[2] === '+';
      } else {
        const c = unname(strip(m[1]));
        meta[this.dk.get(c) ?? c] = this.read(m[2], c);
      }
    }
    const rows: Obj[] = [];
    if (l.length > 1) {
      let cols: string[];
      let grid: string[][];
      const n = /^\[([0-9]+)\]( [^\n]*)?$/.exec(l[1]);
      if (n) {
        const cnt = Number(n[1]);
        if (cnt > MAX_RECORDS) {
          throw new ToklexError(`Toklex: a reply of more than ${MAX_RECORDS} records is refused`);
        }
        // each column line expands to N cells, so a short forged reply could otherwise claim gigabytes
        if (cnt * (l.length - 2) > MAX_CELLS) {
          throw new ToklexError(`Toklex: a reply of more than ${MAX_CELLS} cells is refused`);
        }
        cols = [];
        grid = Array.from({ length: cnt }, () => [] as string[]);
        l.slice(2).forEach((line, i) => {
          const at = line.indexOf(':');
          cols.push(at < 0 ? line : line.slice(0, at));
          expand(at < 0 ? '' : line.slice(at + 1), cnt).forEach((cell, r) => {
            grid[r][i] = cell;
          });
        });
      } else {
        cols = l[1] === '' ? [] : l[1].split('|');
        grid = l.slice(2).map((line) => line.split('|'));
      }
      const paths = cols.map((c) => strip(c).split('.').map(unname));
      for (const cells of grid) {
        const row: Obj = {};
        paths.forEach((segs, i) => this.put(row, segs, this.read(cells[i] ?? '', segs[segs.length - 1])));
        rows.push(row);
      }
    }
    return { schema: h[1], version: h[2], instructions: ins, meta, rows };
  }

  /**
   * Takes the tool arguments {"answers": ...} or the bare answers map. Answers given as a JSON string are decoded.
   * Answers that hold no question id count as absent and give the dataset, so a malformed call never grades as off.
   */
  calibrate(args: unknown): string {
    let answers: unknown = isObj(args) && args.answers !== undefined && args.answers !== null ? args.answers : args;
    if (typeof answers === 'string') {
      try {
        answers = jload(answers);
      } catch {
        answers = null;
      }
    }
    const cal = new Toklex(CAL_SCHEMA, '0000');
    if (!isObj(answers) || !Object.keys(CAL_QUESTIONS).some((q) => own(answers as Obj, q))) {
      const q = Object.entries(CAL_QUESTIONS).map(([id, [block, text]]) => `${id} (${block} block): ${text}`);
      return ['Toklex calibration',
        'The two blocks below hold the same 8 records, once as a safe table and once as a dense table. The legend covers this test only. Answer the six questions from the text on this page.',
        '', 'Legend', cal.schemaText().split('\n').slice(2).join('\n'), '', 'How to read', CAL_HOWTO,
        '', 'Safe block', cal.encode('cal', CAL_ROWS, {}, 'safe'), '', 'Dense block', cal.encode('cal', CAL_ROWS, {}, 'dense'),
        '', 'Questions', ...q, '',
        'Then call toklex_calibrate again with {"answers":{"s1":"<answer>","s2":"<answer>","s3":"<answer>","d1":"<answer>","d2":"<answer>","d3":"<answer>"}}. Write each answer as short text or a number.'].join('\n');
    }
    const a = answers;
    const norm = (x: unknown) => (typeof x === 'string' ? x.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '') : typeof x === 'number' ? num(x, null) : null);
    const res: Record<string, boolean> = {};
    for (const [id, [, , key]] of Object.entries(CAL_QUESTIONS)) {
      res[id] = a[id] !== undefined && a[id] !== null && norm(a[id]) === key;
    }
    const all = (p: string) => Object.entries(res).every(([id, ok]) => id[0] !== p || ok);
    return JSON.stringify({ recommended: all('d') ? 'dense' : all('s') ? 'safe' : 'off', results: res });
  }

  resources(): { uri: string; name: string; description: string; mimeType: string }[] {
    return this.style() === 'plain' ? [
      { uri: 'toklex://modes', name: 'Toklex modes', description: 'The three reply modes and how to pick one.', mimeType: 'text/plain' },
    ] : [
      { uri: 'toklex://modes', name: 'Toklex modes', description: 'The three reply modes, how to pick one and how to calibrate.', mimeType: 'text/plain' },
      { uri: 'toklex://schema', name: 'Toklex schema', description: 'The schema text that toklex_schema returns.', mimeType: 'text/plain' },
    ];
  }

  readResource(uri: string): string | null {
    const plain = this.style() === 'plain';
    if (uri === 'toklex://modes') {
      return plain ? PLAIN_MODES_TEXT : MODES_TEXT;
    }
    return uri === 'toklex://schema' && !plain ? this.schemaText() : null;
  }

  /** dense, auto or safe. auto picks dense or safe when the records are known. */
  private defaultMode(tool: string): Mode | 'auto' {
    return (this.s.dense ?? []).includes(tool) ? 'dense' : (this.s.auto ?? []).includes(tool) ? 'auto' : 'safe';
  }

  /** True when the instruction lines head the reply: plain style with instructions_at reply. */
  private inReply(): boolean {
    return this.style() === 'plain' && (this.s.instructions_at ?? 'reply') === 'reply' && Object.keys(this.s.instructions ?? {}).length > 0;
  }

  private header(meta: Obj, instructions = true): string {
    const ins = this.s.instructions ?? {};
    let h = '';
    for (const [n, b] of this.inReply() && instructions ? Object.entries(ins) : []) {
      h += `${n}: ` + (/[\r\n]/.test(b) ? quote(b) : b) + '\n';
    }
    h += `~${this.s.id}@${this.v}` + (Object.keys(ins).length && this.style() === 'coded' ? ' i:+' : '');
    for (const [k, v0] of Object.entries(meta)) {
      const c = this.kf.get(k) ?? u8(k);
      const v = val(v0);
      const t = typeof v === 'object' && v !== null ? quote(this.js(v, true, c)) : this.cell(v, c, true);
      if (t !== '') {
        h += ' ' + this.label(name(c, true), c) + ':' + t;
      }
    }
    return h;
  }

  /** The written path of a column or meta key, plus (÷K) when plain and key is scaled. */
  private label(path: string, key: string): string {
    const m = this.style() === 'plain' ? this.sc.get(key) : undefined;
    return m ? `${path}(÷${num(m, null)})` : path;
  }

  private off(tail: [string, unknown][], instructions = true): string {
    const ins = this.s.instructions ?? {};
    const o: [string, string][] = [['toklex', '"off"'], ['schema', jstr(`${this.s.id}@${this.v}`)]];
    if (Object.keys(ins).length && instructions && (this.style() === 'coded' || this.inReply())) {
      o.push(['instructions', this.js(ins, false, null, true)]);
    }
    for (const [k, v] of tail) {
      o.push([k, k === 'rows' ? '[' + (v as Obj[]).map((r) => this.js(r, false, null, true)).join(',') + ']' : this.js(v, false, null, k === 'meta')]);
    }
    return '{' + o.map(([k, t]) => jstr(k) + ':' + t).join(',') + '}';
  }

  /** One record as [dotted column, [code of the last segment, value]]. A non-empty object expands, anything else is a cell. */
  private flat(row: Obj, path = ''): Map<string, [string, unknown]> {
    const out = new Map<string, [string, unknown]>();
    for (const [k, v0] of entries(row)) {
      const c = this.kf.get(k) ?? u8(k);
      const p = path === '' ? name(c) : path + '.' + name(c);
      const v = val(v0);
      if (isObj(v) && entries(v).length) {
        for (const [q, x] of this.flat(v, p)) {
          if (!out.has(q)) {
            out.set(q, x); // a nested column keeps the cell it has, as PHP's += does
          }
        }
      } else {
        out.set(p, [c, v]); // two names with one code: the later cell wins and the column keeps its place
      }
    }
    return out;
  }

  /** One cell. hdr adds the header's two quote triggers, any whitespace and a double quote. */
  private cell(v0: unknown, c: string, hdr = false): string {
    const v = val(v0);
    if (v === null || v === undefined) {
      return '';
    }
    if (typeof v === 'boolean') {
      return v ? 'true' : 'false';
    }
    const isScaled = this.sc.has(c);
    // a database driver hands numbers over as strings, so a plain decimal in a scaled field is that number, scaled on its digits (spec 6.3)
    if (isScaled && typeof v === 'string' && DEC.test(v)) {
      return scaled(v, num(this.sc.get(c)!, null)!);
    }
    if (isNum(v)) {
      return num(v, this.sc.get(c)) ?? '';
    }
    if (typeof v === 'object') {
      return this.js(v, true, c).replaceAll('|', BAR);
    }
    const s = u8(String(v));
    const code = this.vf.get(c)?.get(s);
    if (code !== undefined) {
      return code;
    }
    const q = isScaled || s === '' || /^["[{]|[|*\n\r]/.test(s) || this.dv.get(c)?.has(s) || NUM.test(s) || ['true', 'false', 'null'].includes(s)
      || SEQ_SHAPE.test(s) || (hdr && /[ \t\n\v\f\r"]/.test(s));
    return q ? quote(s) : s;
  }

  /** JSON text. coded shortens keys and codes strings and numbers by the key they sit under. Lists pass their key down. An empty object is [] as an empty PHP array is, unless obj asks for an object. */
  private js(x0: unknown, coded: boolean, k: string | null = null, obj = false): string {
    const x = val(x0);
    if (Array.isArray(x)) {
      return '[' + x.map((e) => (val(e) === undefined ? 'null' : this.js(e, coded, k))).join(',') + ']';
    }
    if (isObj(x)) {
      const es = entries(x);
      if (!es.length && !obj) {
        return '[]';
      }
      // plain scales no member of an object (spec 6.3)
      return '{' + es.map(([n, e]) => {
        const c = coded ? this.kf.get(n) ?? n : n;
        return jstr(c) + ':' + this.js(e, coded, this.style() === 'coded' ? c : '');
      }).join(',') + '}';
    }
    if (x === null || x === undefined || typeof x === 'boolean') {
      return JSON.stringify(x ?? null);
    }
    if (isNum(x)) {
      return num(x, coded && k !== null ? this.sc.get(k) : null) ?? 'null';
    }
    let s = u8(String(x));
    const code = coded && k !== null ? this.vf.get(k)?.get(s) : undefined;
    s = code ?? s;
    return jstr(s);
  }

  /** One header value or cell back to a value, by the rules of spec section 8. */
  private read(t: string, c: string): unknown {
    if (t === '') {
      return null;
    }
    if (t[0] === '"') {
      try {
        const j = jload(t);
        if (typeof j === 'string') {
          return j;
        }
      } catch {
        // not a JSON string, read on
      }
    }
    const full = this.dv.get(c)?.get(t);
    if (full !== undefined) {
      return full;
    }
    if (NUM.test(t)) {
      return this.dec(jload(t), c);
    }
    if (t === 'true' || t === 'false') {
      return t === 'true';
    }
    if (t[0] === '[') {
      try {
        const j = jload(t);
        if (Array.isArray(j)) {
          return this.dec(j, c);
        }
      } catch {
        // not a JSON array, it stays text
      }
    }
    return t;
  }

  /** Full keys, full values and descaled numbers inside decoded JSON. */
  private dec(x: unknown, k: string | null): unknown {
    if (Array.isArray(x)) {
      return x.map((e) => this.dec(e, k));
    }
    if (isObj(x)) {
      return Object.fromEntries(Object.entries(x).map(([n, e]) => [this.dk.get(n) ?? n, this.dec(e, this.style() === 'coded' ? n : '')]));
    }
    if (typeof x === 'string') {
      return (k !== null && this.dv.get(k)?.get(x)) ?? x;
    }
    const m = typeof x === 'number' && k !== null ? this.sc.get(k) : undefined;
    return m ? (x as number) / m : x;
  }

  /** One cell into its nested place. A scalar parent gives way to a nested value, but a null never replaces it, and a null parent cell never wipes the object its nested columns built. */
  private put(row: Obj, segs: string[], value: unknown): void {
    let cont: Obj | null = null;
    let key = '';
    for (const sg of segs) {
      let cur: unknown = cont === null ? row : cont[key];
      if (Array.isArray(cur)) {
        // a list is an array to PHP, so a nested column adds its key to it
        cur = Object.fromEntries(cur.map((x, i) => [String(i), x]));
        cont![key] = cur;
      } else if (!isObj(cur)) {
        if (value === null && cur !== null && cur !== undefined) {
          return;
        }
        cur = {};
        cont![key] = cur;
      }
      const o = cur as Obj;
      const kk = this.dk.get(sg) ?? sg;
      if (!own(o, kk)) {
        o[kk] = null;
      }
      cont = o;
      key = kk;
    }
    const last = cont![key];
    if (value !== null || !(isObj(last) || Array.isArray(last))) {
      cont![key] = value;
    }
  }

  private static check(s: Obj): void {
    const fail = (why: string): never => {
      throw new ToklexError(`Toklex schema: ${why}`);
    };
    const hint = ' (add "style": "coded" to keep v0.1 names)';
    if (s.toklex !== 1) {
      fail('toklex must be 1');
    }
    if (!(typeof s.id === 'string' && /^[a-z][a-z0-9]{0,7}$/.test(s.id))) {
      fail('id must match [a-z][a-z0-9]{0,7}');
    }
    const style = s.style ?? 'plain';
    const plain = style !== 'coded';
    if (style !== 'plain' && style !== 'coded') {
      fail('style must be plain or coded');
    }
    if (!['reply', 'server'].includes((s.instructions_at as string) ?? 'reply')) {
      fail('instructions_at must be reply or server');
    }
    if (!plain && own(s, 'instructions_at')) {
      fail('instructions_at needs style plain');
    }
    const obj = (n: string): Obj => {
      const v = s[n] ?? {};
      if (Array.isArray(v) && !v.length) {
        return {};
      }
      return isObj(v) ? v : fail(`${n} must be an object`);
    };
    const keys = obj('keys');
    for (const [c, full] of Object.entries(keys)) {
      if (typeof full !== 'string') fail(`keys.${c} must be a string`);
      const f = full as string;
      if (c === 'i' || c === 'e') fail(`key code ${c} is reserved`);
      if (badCode(c)) fail(`key code ${c} is not a plain token`);
      if (c.includes('.')) fail(`key code ${c} holds a dot, which marks a nested column`);
      if (plain && badCode(f)) fail(`name ${f} of keys.${c} is not a plain token${hint}`);
      if (plain && f.includes('.')) fail(`name ${f} of keys.${c} holds a dot, which marks a nested column${hint}`);
      if (plain && f.includes('(÷')) fail(`name ${f} of keys.${c} holds (÷, which a reader takes for a scale label${hint}`);
    }
    if (new Set(Object.values(keys)).size !== Object.keys(keys).length) fail('a full name occurs twice in keys');
    for (const [f, m] of Object.entries(obj('values'))) {
      if (Array.isArray(m) && !m.length) continue;
      if (!isObj(m)) fail(`values.${f} must be an object`);
      for (const [c, full] of Object.entries(m as Obj)) {
        if (typeof full !== 'string') fail(`values.${f}.${c} must be a string`);
        if (badCode(c)) fail(`value code ${c} in values.${f} is not a plain token`);
        if (SEQ_SHAPE.test(c)) fail(`value code ${c} in values.${f} has the shape of a date sequence`);
      }
      if (new Set(Object.values(m as Obj)).size !== Object.keys(m as Obj).length) fail(`a full value occurs twice in values.${f}`);
    }
    for (const [f, m] of Object.entries(obj('scale'))) {
      if (!(typeof m === 'number' && Number.isInteger(m))) fail(`scale.${f} must be an integer`);
      if ((m as number) < 10) fail(`scale.${f} must be at least 10`);
    }
    let ins = s.instructions ?? {};
    if (Array.isArray(ins)) {
      ins = Object.fromEntries(ins.map((x, i) => [String(i), x]));
    }
    if (!isObj(ins) || !Object.values(ins).every((b) => typeof b === 'string')) fail('instructions must map names to strings');
    for (const n of plain ? Object.keys(ins as Obj) : []) {
      if (badCode(n)) fail(`instruction name ${n} is not a plain token${hint}`);
      if (n.startsWith('~')) fail(`instruction name ${n} starts with ~, which marks the header`);
    }
    const list = (v: unknown): unknown[] => (Array.isArray(v) ? v : isObj(v) ? Object.values(v) : []);
    for (const m of ['dense', 'auto']) {
      const v = s[m] ?? [];
      if (!(Array.isArray(v) || isObj(v)) || !list(v).every((x) => typeof x === 'string')) fail(`${m} must be a list of tool names`);
    }
    const both = list(s.dense).filter((t) => list(s.auto).includes(t));
    if (both.length) fail(`tool ${both[0]} is in both dense and auto`);
  }
}
