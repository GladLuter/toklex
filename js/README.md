# Toklex for TypeScript

A port of the PHP library in this repository. It passes every fixture in [spec/fixtures](https://github.com/GladLuter/toklex/tree/main/spec/fixtures) and gives the texts of appendix A byte for byte. The protocol is [spec/toklex.md](https://github.com/GladLuter/toklex/blob/main/spec/toklex.md). Node 22.18 or newer, no runtime dependencies.

```ts
import { Toklex } from 'toklex';

const tx = Toklex.fromFile('stations.schema.json'); // throws ToklexError on a bad schema

// tools/list: adds toklex_mode to every tool and removes outputSchema
const tools = tx.withTools(myTools);

// tools/call
const mode = tx.mode(name, args);     // removes toklex_mode from args
const rows = await runMyTool(name, args); // an array of objects
const text = tx.encode(name, rows, { date: '2026-09-30' }, mode);
```

Return `text` as one MCP `text` content block and add no `structuredContent`.

The methods have the PHP names: `error`, `decode`, `version`, `style`, `instructionsText`, `schemaText`, `calibrate`, `resources` and `readResource`. `encode` and `error` take `instructions = false` to leave the instruction text out of a reply the model has read before (spec 5.1). `withTools` takes a `warn` callback, and without it warnings go to `console.error`, which is stderr and safe for a stdio server. `Toklex.fromText(text)` loads a schema from a string.

Values travel as `JSON.stringify` would carry them: a `Date` is ISO 8601 text in UTC (the date alone at midnight UTC), an object with `toJSON` is what that returns, a `Map` is a map with its keys in their order, a `Set` is an array, a class instance is its own enumerable properties, and `undefined` is left out. A `bigint` is written with all its digits.

Two limits come from JavaScript itself.

- A number is a double, so an integer above 2^53 has lost digits before the library sees it. Pass it as a `bigint` or a string. `decode` returns a `bigint` for an integer cell outside the safe range, so it keeps every digit.
- An object puts keys that look like array indexes, such as `"0"`, before its other keys. Pass a record or the metadata as a `Map` when such names must keep their place. The reply then has the bytes of the PHP library. `decode` returns plain objects, so there such keys come first. For the same reason it reads a JSON map with the keys `"0"`, `"1"` and so on as a map, where PHP and Python read a list.

## Tests

```
npm test            # node test/run.ts, the conformance runner
npm run typecheck   # tsc --noEmit, strict
npm run build       # dist/ for publishing
```
