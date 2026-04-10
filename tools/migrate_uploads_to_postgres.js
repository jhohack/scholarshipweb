const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

function loadPgModule() {
  const explicitPath = process.env.PG_MODULE_PATH;
  if (explicitPath) {
    return require(explicitPath);
  }
  return require('pg');
}

async function main() {
  const connectionString = process.env.DATABASE_URL;
  if (!connectionString) {
    throw new Error('DATABASE_URL is required.');
  }

  const dryRun = process.argv.includes('--dry-run');
  const legacyUploadBaseUrls = parseLegacyBaseUrls();
  const repoRoot = path.resolve(__dirname, '..');
  const publicRoot = path.join(repoRoot, 'public');
  const { Client } = loadPgModule();
  const client = new Client({
    connectionString,
    ssl: { rejectUnauthorized: false },
  });

  const targets = [
    {
      label: 'User profile pictures',
      table: 'users',
      idColumn: 'id',
      pathColumn: 'profile_picture_path',
      nameColumn: null,
      folder: 'avatars',
    },
    {
      label: 'Application documents',
      table: 'documents',
      idColumn: 'id',
      pathColumn: 'file_path',
      nameColumn: 'file_name',
      folder: 'documents',
    },
    {
      label: 'Announcement attachments',
      table: 'announcement_attachments',
      idColumn: 'id',
      pathColumn: 'file_path',
      nameColumn: 'file_name',
      folder: 'announcements',
    },
    {
      label: 'Message attachments',
      table: 'messages',
      idColumn: 'id',
      pathColumn: 'attachment_path',
      nameColumn: null,
      folder: 'messages',
    },
  ];

  await client.connect();

  try {
    for (const target of targets) {
      const columns = [target.idColumn, target.pathColumn];
      if (target.nameColumn) {
        columns.push(target.nameColumn);
      }

      console.log(`[${target.label}]`);
      const selectSql = `SELECT ${columns.join(', ')} FROM ${target.table} WHERE ${target.pathColumn} LIKE 'uploads/%' ORDER BY ${target.idColumn} ASC`;
      const result = await client.query(selectSql);

      let converted = 0;
      let missing = 0;
      let skipped = 0;
      let recoveredFromRemote = 0;

      for (const row of result.rows) {
        const relativePath = row[target.pathColumn];
        if (!relativePath) {
          skipped++;
          continue;
        }

        const absolutePath = path.join(publicRoot, relativePath.replace(/\//g, path.sep));
        const originalName = target.nameColumn
          ? (row[target.nameColumn] || path.basename(relativePath))
          : path.basename(relativePath);

        let buffer = null;
        let mimeType = detectMimeType(absolutePath);
        let sourceLabel = 'local';

        if (fs.existsSync(absolutePath)) {
          buffer = fs.readFileSync(absolutePath);
        } else {
          const remoteFile = await fetchLegacyFile(relativePath, legacyUploadBaseUrls);
          if (remoteFile) {
            buffer = remoteFile.buffer;
            mimeType = remoteFile.mimeType || detectMimeType(relativePath);
            sourceLabel = remoteFile.sourceUrl;
            recoveredFromRemote++;
          }
        }

        if (!buffer) {
          missing++;
          console.log(`  - Missing file for ${target.table}#${row[target.idColumn]}: ${relativePath}`);
          continue;
        }

        if (dryRun) {
          converted++;
          console.log(`  - Would migrate ${target.table}#${row[target.idColumn]}: ${relativePath} (${sourceLabel})`);
          continue;
        }

        const storageKey = crypto.randomBytes(16).toString('hex');

        await client.query(
          `INSERT INTO uploaded_files (storage_key, folder_name, original_name, mime_type, file_size, content_blob)
           VALUES ($1, $2, $3, $4, $5, $6)`,
          [storageKey, target.folder, originalName, mimeType, buffer.length, buffer]
        );

        await client.query(
          `UPDATE ${target.table} SET ${target.pathColumn} = $1 WHERE ${target.idColumn} = $2`,
          [`filedb:${storageKey}`, row[target.idColumn]]
        );

        converted++;
        console.log(`  - Migrated ${target.table}#${row[target.idColumn]} => filedb:${storageKey} (${sourceLabel})`);
      }

      console.log(`  Summary: ${converted} converted, ${missing} missing, ${skipped} skipped, ${recoveredFromRemote} recovered remotely.`);
      console.log('');
    }
  } finally {
    await client.end();
  }
}

function detectMimeType(filePath) {
  const ext = path.extname(String(filePath || '')).toLowerCase();
  switch (ext) {
    case '.jpg':
    case '.jpeg':
      return 'image/jpeg';
    case '.png':
      return 'image/png';
    case '.gif':
      return 'image/gif';
    case '.webp':
      return 'image/webp';
    case '.jfif':
      return 'image/jpeg';
    case '.pdf':
      return 'application/pdf';
    default:
      return 'application/octet-stream';
  }
}

function parseLegacyBaseUrls() {
  const raw = process.env.LEGACY_UPLOAD_BASE_URLS || process.env.LEGACY_UPLOAD_BASE_URL || '';
  return raw
    .split(',')
    .map(value => value.trim().replace(/\/+$/, ''))
    .filter(Boolean);
}

async function fetchLegacyFile(relativePath, baseUrls) {
  if (!baseUrls.length) {
    return null;
  }

  const cleanPath = String(relativePath || '').replace(/^\/+/, '');
  for (const baseUrl of baseUrls) {
    const url = `${baseUrl}/${cleanPath}`;

    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 15000);
      const response = await fetch(url, {
        method: 'GET',
        redirect: 'follow',
        signal: controller.signal,
        headers: {
          'user-agent': 'scholarship-portal-migrator/1.0',
        },
      });
      clearTimeout(timeout);

      if (!response.ok) {
        continue;
      }

      const arrayBuffer = await response.arrayBuffer();
      const buffer = Buffer.from(arrayBuffer);
      const mimeType = sanitizeMimeType(response.headers.get('content-type'));
      if (!isAcceptableRemoteFile(cleanPath, mimeType, buffer)) {
        continue;
      }

      return {
        buffer,
        mimeType,
        sourceUrl: url,
      };
    } catch (error) {
      continue;
    }
  }

  return null;
}

function sanitizeMimeType(value) {
  if (!value) {
    return '';
  }

  return String(value).split(';', 1)[0].trim().toLowerCase();
}

function isAcceptableRemoteFile(filePath, mimeType, buffer) {
  if (!buffer || buffer.length === 0) {
    return false;
  }

  const ext = path.extname(String(filePath || '')).toLowerCase();
  const startsWithPdf = buffer.subarray(0, 5).toString('utf8') === '%PDF-';

  if (ext === '.pdf') {
    return mimeType === 'application/pdf' || startsWithPdf;
  }

  if (['.jpg', '.jpeg', '.png', '.gif', '.webp', '.jfif'].includes(ext)) {
    return mimeType.startsWith('image/');
  }

  return mimeType !== 'text/html';
}

main().catch((error) => {
  console.error(error.message || error);
  process.exit(1);
});
