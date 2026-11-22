// Builds the release package: src/ -> dist/udoy-connect/ -> dist/udoy-connect-<version>.zip
// The zip contains a single top-level folder named after the plugin slug, as WordPress expects.
import { createWriteStream } from 'node:fs';
import { cp, mkdir, readFile, readdir, rm, stat } from 'node:fs/promises';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ZipArchive } from 'archiver';

const SLUG = 'udoy-connect';
const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const srcDir = join(root, 'src');
const distDir = join(root, 'dist');
const stageDir = join(distDir, SLUG);

// Files that never belong in a release.
const IGNORE = [/(^|\/)\.DS_Store$/, /(^|\/)Thumbs\.db$/, /\.(log|bak|orig|swp)$/, /~$/];
const ignored = (rel) => IGNORE.some((re) => re.test(rel.split(sep).join('/')));

async function version() {
  const main = await readFile(join(srcDir, `${SLUG}.php`), 'utf8');
  const m = main.match(/^\s*\*\s*Version:\s*(\S+)/m);
  if (!m) throw new Error(`No "Version:" header found in src/${SLUG}.php`);
  const constant = (await readFile(join(srcDir, `${SLUG}.php`), 'utf8')).match(/define\(\s*'UDOY_VERSION'\s*,\s*'([^']+)'/);
  if (constant && constant[1] !== m[1]) {
    throw new Error(`Version mismatch: header ${m[1]} vs UDOY_VERSION ${constant[1]}`);
  }
  return m[1];
}

async function walk(dir) {
  const out = [];
  for (const e of await readdir(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) out.push(...(await walk(p)));
    else out.push(p);
  }
  return out.sort();
}

async function main() {
  if (process.argv.includes('--clean')) {
    await rm(distDir, { recursive: true, force: true });
    console.log('Removed dist/');
    return;
  }

  const ver = await version();
  const zipPath = join(distDir, `${SLUG}-${ver}.zip`);

  await rm(stageDir, { recursive: true, force: true });
  await rm(zipPath, { force: true });
  await mkdir(distDir, { recursive: true });
  await cp(srcDir, stageDir, {
    recursive: true,
    filter: (p) => !ignored(relative(srcDir, p)),
  });

  const files = await walk(stageDir);
  await new Promise((resolve, reject) => {
    const out = createWriteStream(zipPath);
    const zip = new ZipArchive({ zlib: { level: 9 } });
    out.on('close', resolve);
    zip.on('error', reject);
    zip.pipe(out);
    const fixed = new Date('2020-01-01T00:00:00Z'); // reproducible output
    for (const f of files) {
      zip.file(f, { name: `${SLUG}/${relative(stageDir, f).split(sep).join('/')}`, date: fixed });
    }
    zip.finalize();
  });

  const kb = ((await stat(zipPath)).size / 1024).toFixed(1);
  console.log(`Built dist/${SLUG}-${ver}.zip (${files.length} files, ${kb} KB)`);
}

main().catch((err) => {
  console.error(err.message);
  process.exit(1);
});
