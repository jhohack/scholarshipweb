const fs = require('node:fs');
const path = require('node:path');

function loadPgModule() {
  const explicitPath = process.env.PG_MODULE_PATH;
  if (explicitPath) {
    return require(explicitPath);
  }
  return require('pg');
}

async function main() {
  const cliConnectionString = process.argv[2];
  const connectionString = process.env.DATABASE_URL || cliConnectionString;
  const sqlFiles = process.env.DATABASE_URL ? process.argv.slice(2) : process.argv.slice(3);

  if (!connectionString) {
    throw new Error('DATABASE_URL is required.');
  }

  if (sqlFiles.length === 0) {
    throw new Error('At least one SQL file path is required.');
  }

  const { Client } = loadPgModule();
  const client = new Client({
    connectionString,
    ssl: { rejectUnauthorized: false },
  });

  await client.connect();

  try {
    for (const relativeFile of sqlFiles) {
      const absoluteFile = path.resolve(relativeFile);
      const sql = fs.readFileSync(absoluteFile, 'utf8');
      console.log(`Running ${path.basename(absoluteFile)}...`);
      await client.query(sql);
      console.log(`Completed ${path.basename(absoluteFile)}.`);
    }
  } finally {
    await client.end();
  }
}

main().catch((error) => {
  console.error(error.message || error);
  process.exit(1);
});
