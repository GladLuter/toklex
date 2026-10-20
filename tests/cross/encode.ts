// Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
// Reads a JSON list of cases on stdin and prints [reply, decoded rows] for each, or ["ERR", ""] when the library refuses.
// The input is read with its key order kept (objects become Maps) and with exact big integers (bigint), as PHP and
// Python read it, because JSON.parse puts keys such as "1" first and rounds integers above 2^53.
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Toklex, ToklexError } from '../../js/src/index.ts';

function parse(t: string): unknown {
  let i = 0;
  const ws = () => {
    while (' \t\n\r'.includes(t[i]) && i < t.length) i++;
  };
  const value = (): unknown => {
    ws();
    const ch = t[i];
    if (ch === '{') {
      const m = new Map<string, unknown>();
      i++;
      ws();
      if (t[i] === '}') {
        i++;
        return m;
      }
      for (;;) {
        ws();
        const k = value() as string;
        ws();
        i++; // :
        m.set(k, value());
        ws();
        if (t[i++] === '}') return m;
      }
    }
    if (ch === '[') {
      const a: unknown[] = [];
      i++;
      ws();
      if (t[i] === ']') {
        i++;
        return a;
      }
      for (;;) {
        a.push(value());
        ws();
        if (t[i++] === ']') return a;
      }
    }
    if (ch === '"') {
      const start = i;
      for (i++; t[i] !== '"'; i++) if (t[i] === '\\') i++;
      i++;
      return JSON.parse(t.slice(start, i));
    }
    const m = /^-?[0-9]+(\.[0-9]+)?([eE][+-]?[0-9]+)?|^true|^false|^null/.exec(t.slice(i, i + 400))!;
    i += m[0].length;
    if (/^-?[0-9]+$/.test(m[0]) && !Number.isSafeInteger(Number(m[0]))) return BigInt(m[0]);
    return JSON.parse(m[0]);
  };
  return value();
}

const fx = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'spec', 'fixtures');
const tx = new Map<string, Toklex>();
const out = (parse(readFileSync(0, 'utf8')) as Map<string, unknown>[]).map((c) => {
  const schema = c.get('schema') as string;
  if (!tx.has(schema)) tx.set(schema, Toklex.fromFile(join(fx, schema)));
  const t = tx.get(schema)!;
  try {
    const r = t.encode(c.get('tool') as string, c.get('rows') as unknown[], c.get('meta') as Map<string, unknown>, c.get('mode') as string | null);
    return [r, t.decode(r).rows];
  } catch (e) {
    if (e instanceof ToklexError) return ['ERR', ''];
    throw e;
  }
});
const raw = (JSON as unknown as { rawJSON: (s: string) => unknown }).rawJSON;
process.stdout.write(JSON.stringify(out, (_k, v) => (typeof v === 'bigint' ? raw(String(v)) : v)));
