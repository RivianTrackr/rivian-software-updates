import * as esbuild from 'esbuild';

const isWatch = process.argv.includes('--watch');

const jsDefaults = {
  platform: 'browser',
  target: ['es2020'],
  charset: 'utf8',
};

const builds = [
  // Frontend JS
  {
    ...jsDefaults,
    bundle: true,
    entryPoints: ['frontend/js/rsu-frontend.js'],
    outfile: 'frontend/js/rsu-frontend.min.js',
    format: 'iife',
    minify: true,
    drop: ['console', 'debugger'],
  },
  // Frontend CSS
  {
    entryPoints: ['frontend/css/rsu-frontend.css'],
    outfile: 'frontend/css/rsu-frontend.min.css',
    bundle: false,
    minify: true,
  },
  // Admin JS
  {
    ...jsDefaults,
    bundle: false,
    entryPoints: ['admin/js/rsu-admin.js'],
    outfile: 'admin/js/rsu-admin.min.js',
    minify: true,
    drop: ['console', 'debugger'],
  },
  // Admin CSS
  {
    entryPoints: ['admin/css/rsu-admin.css'],
    outfile: 'admin/css/rsu-admin.min.css',
    bundle: false,
    minify: true,
  },
];

async function run() {
  if (isWatch) {
    const contexts = await Promise.all(
      builds.map((config) => esbuild.context(config))
    );
    await Promise.all(contexts.map((ctx) => ctx.watch()));
    console.log('Watching for changes...');
  } else {
    await Promise.all(builds.map((config) => esbuild.build(config)));
    console.log('Build complete.');
  }
}

run().catch((err) => {
  console.error(err);
  process.exit(1);
});
