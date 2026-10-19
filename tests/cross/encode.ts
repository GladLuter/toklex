// Toklex protocol. Copyright 2026 Yevgenii Slieptsov (GladLuter). https://github.com/GladLuter/toklex
// Reads a JSON list of cases on stdin and prints [reply, decoded rows] for each, or ["ERR", ""] when the library refuses.
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Toklex, ToklexError } from '../../js/src/index.ts';

const fx = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'spec', 'fixtures');
const tx = new Map<string, Toklex>();
const out = JSON.parse(readFileSync(0, 'utf8')).map((c: any) => {
  if (!tx.has(c.schema)) tx.set(c.schema, Toklex.fromFile(join(fx, c.schema)));
  const t = tx.get(c.schema)!;
  try {
    const r = t.encode(c.tool, c.rows, c.meta, c.mode);
    return [r, t.decode(r).rows];
  } catch (e) {
    if (e instanceof ToklexError) return ['ERR', ''];
    throw e;
  }
});
process.stdout.write(JSON.stringify(out));
