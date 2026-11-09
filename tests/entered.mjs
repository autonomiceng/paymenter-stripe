// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// CI coverage evidence for tests/ci-coverage.php: with PAY_TEST_ENTERED set, a check run by node appends its file and this one.
import { appendFileSync, mkdirSync, realpathSync } from 'node:fs';
import { basename, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const log = process.env.PAY_TEST_ENTERED;
const entry = process.argv[1] ? realpathSync(process.argv[1]) : '';
if (log && dirname(entry) === dirname(fileURLToPath(import.meta.url))) {
    process.on('exit', () => {
        mkdirSync(dirname(log), { recursive: true });
        appendFileSync(log, `${basename(entry)}\nentered.mjs\n`);
    });
}
