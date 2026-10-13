<?php
declare(strict_types=1);

/*
 * NotesArchive API for XAMPP / MySQL
 *
 * IMPORTANT:
 * - Back up your current api.php before replacing it.
 * - This file preserves app_storage as the source of truth.
 * - It does NOT delete/rebuild existing relational table records.
 * - File rows contain metadata only. This API does not store uploaded file bytes.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$allowedKeys = [
    'na.users.v1',
    'na.userdata.v1',
    'na.filemeta.v1',
    'na.session.v1',
    'na.remember.v1',
];

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function txt(mixed $value, int $max = 255): ?string {
    if ($value === null || is_array($value) || is_object($value)) return null;
    $s = trim((string)$value);
    if ($s === '') return null;
    return mb_substr($s, 0, $max, 'UTF-8');
}

function dt(mixed $value): string {
    if (is_numeric($value)) {
        $n = (float)$value;
        // App timestamps are JavaScript milliseconds.
        $seconds = $n > 100000000000 ? (int)floor($n / 1000) : (int)$n;
        return date('Y-m-d H:i:s', $seconds);
    }
    if (is_string($value) && trim($value) !== '') {
        $time = strtotime($value);
        if ($time !== false) return date('Y-m-d H:i:s', $time);
    }
    return date('Y-m-d H:i:s');
}

/** Decode JSON stored in app_storage. Some values may be JSON-encoded twice. */
function decodeStored(mixed $value): mixed {
    if (!is_string($value)) return $value;
    $decoded = json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE) return $value;
    if (is_string($decoded)) {
        $twice = json_decode($decoded, true);
        if (json_last_error() === JSON_ERROR_NONE) return $twice;
    }
    return $decoded;
}

function getStorage(PDO $pdo): array {
    $rows = $pdo->query('SELECT storage_key, storage_value FROM app_storage')->fetchAll();
    $data = [];
    foreach ($rows as $row) {
        $data[(string)$row['storage_key']] = $row['storage_value'];
    }
    return $data;
}

function tableColumns(PDO $pdo, string $table): array {
    // Table names are hard-coded by this file, never supplied by the request.
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $columns[$row['Field']] = $row;
    }
    return $columns;
}

/** Insert/update only columns that exist in the actual table. Never wipes tables. */
function upsertKnown(PDO $pdo, string $table, array $values, array $columns, string $primaryKey): void {
    if (!isset($columns[$primaryKey]) || !array_key_exists($primaryKey, $values)) return;

    $data = [];
    foreach ($values as $column => $value) {
        if (isset($columns[$column])) $data[$column] = $value;
    }
    if (!$data) return;

    $names = array_keys($data);
    $quoted = array_map(static fn($n) => '`' . str_replace('`', '``', $n) . '`', $names);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $updates = [];
    foreach ($names as $name) {
        if ($name !== $primaryKey) {
            $q = '`' . str_replace('`', '``', $name) . '`';
            $updates[] = $q . '=VALUES(' . $q . ')';
        }
    }

    $sql = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' .
        implode(',', $quoted) . ') VALUES (' . $placeholders . ')';
    if ($updates) $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
}

/** Safely sync metadata from app_storage into the user's relational tables. */
function syncTables(PDO $pdo): array {
    $raw = getStorage($pdo);
    $users = decodeStored($raw['na.users.v1'] ?? '[]');
    $userData = decodeStored($raw['na.userdata.v1'] ?? '{}');
    $files = decodeStored($raw['na.filemeta.v1'] ?? '[]');

    if (!is_array($users)) $users = [];
    if (!is_array($userData)) $userData = [];
    if (!is_array($files)) $files = [];

    // Users: match the known schema (id, full_name, email, password_hash, created_at)
    // while also supporting older schemas with display_name / raw_json.
    try {
        $cols = tableColumns($pdo, 'users');
        foreach ($users as $u) {
            if (!is_array($u) || !isset($u['id'])) continue;
            $hash = txt($u['hash'] ?? null, 255);
            $salt = txt($u['salt'] ?? null, 255);
            $passwordHash = ($salt !== null && $hash !== null) ? $salt . ':' . $hash : ($hash ?? '');
            $rawJson = json_encode($u, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            upsertKnown($pdo, 'users', [
                'id' => (string)$u['id'],
                'full_name' => txt($u['name'] ?? $u['full_name'] ?? $u['display_name'] ?? 'User', 255),
                'display_name' => txt($u['name'] ?? $u['display_name'] ?? $u['full_name'] ?? 'User', 255),
                'username' => txt($u['name'] ?? $u['username'] ?? $u['email'] ?? 'user', 255),
                'email' => txt($u['email'] ?? null, 255),
                'password_hash' => $passwordHash,
                'created_at' => dt($u['createdAt'] ?? $u['created_at'] ?? null),
                'raw_json' => $rawJson,
            ], $cols, 'id');
        }
    } catch (Throwable $e) {
        error_log('NotesArchive users sync: ' . $e->getMessage());
    }

    // Notes and settings: app userdata is normally keyed by user ID.
    try {
        $noteCols = tableColumns($pdo, 'notes');
        $settingsCols = tableColumns($pdo, 'user_settings');

        foreach ($userData as $userId => $bundle) {
            if (!is_array($bundle)) continue;
            $uid = txt($bundle['userId'] ?? $bundle['user_id'] ?? $userId, 128);
            if ($uid === null) continue;

            $notes = $bundle['notes'] ?? [];
            if (is_array($notes)) {
                foreach ($notes as $note) {
                    if (!is_array($note) || !isset($note['id'])) continue;
                    $rawJson = json_encode($note, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    upsertKnown($pdo, 'notes', [
                        'id' => (string)$note['id'],
                        'user_id' => $uid,
                        'subject' => txt($note['subject'] ?? null, 255),
                        'title' => txt($note['title'] ?? 'Untitled', 255),
                        'content' => (string)($note['content'] ?? ''),
                        'created_at' => dt($note['createdAt'] ?? $note['created_at'] ?? null),
                        'updated_at' => dt($note['updatedAt'] ?? $note['updated_at'] ?? $note['createdAt'] ?? null),
                        'raw_json' => $rawJson,
                    ], $noteCols, 'id');
                }
            }

            if (array_key_exists('settings', $bundle)) {
                $settingsJson = json_encode($bundle['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                upsertKnown($pdo, 'user_settings', [
                    'user_id' => $uid,
                    'settings_json' => $settingsJson === false ? '{}' : $settingsJson,
                    'synced_at' => date('Y-m-d H:i:s'),
                ], $settingsCols, 'user_id');
            }
        }
    } catch (Throwable $e) {
        error_log('NotesArchive notes/settings sync: ' . $e->getMessage());
    }

    // Files: actual schema reported by the user includes:
    // id, user-id, original_name, stored_name, mime_type, file_size, created_at.
    // stored_name is set to the metadata filename; actual file bytes are not uploaded here.
    $fileCount = 0;
    try {
        $cols = tableColumns($pdo, 'files');
        foreach ($files as $f) {
            if (!is_array($f) || !isset($f['id'])) continue;
            $name = txt($f['name'] ?? $f['filename'] ?? $f['original_name'] ?? null, 255);
            if ($name === null) continue;

            $owner = txt($f['ownerId'] ?? $f['owner_id'] ?? $f['user-id'] ?? $f['user_id'] ?? null, 128);
            $mime = txt($f['type'] ?? $f['mimeType'] ?? $f['mime_type'] ?? 'application/octet-stream', 255);
            $size = isset($f['size']) && is_numeric($f['size']) ? max(0, (int)$f['size']) : 0;

            $values = [
                'id' => (string)$f['id'],
                'user-id' => $owner,
                'user_id' => $owner,
                'owner_id' => $owner,
                'original_name' => $name,
                'stored_name' => $name,
                'name' => $name,
                'mime_type' => $mime,
                'file_size' => $size,
                'size_bytes' => $size,
                'subject' => txt($f['subject'] ?? null, 255),
                'created_at' => dt($f['createdAt'] ?? $f['created_at'] ?? null),
                'raw_json' => json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            // If required owner field is missing, don't insert an invalid row.
            if ($owner === null) {
                error_log('NotesArchive file sync skipped: missing ownerId for file ' . (string)$f['id']);
                continue;
            }

            upsertKnown($pdo, 'files', $values, $cols, 'id');
            $fileCount++;
        }
    } catch (Throwable $e) {
        error_log('NotesArchive files sync: ' . $e->getMessage());
        throw $e;
    }

    return ['users' => count($users), 'fileRecordsProcessed' => $fileCount];
}

try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;dbname=notesarchive;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sync = syncTables($pdo);
        $rows = $pdo->query('SELECT storage_key, storage_value FROM app_storage')->fetchAll();
        $data = [];
        foreach ($rows as $row) $data[$row['storage_key']] = $row['storage_value'];
        respond(200, ['ok' => true, 'data' => $data, 'synced' => true, 'sync' => $sync]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        respond(405, ['ok' => false, 'error' => 'Method not allowed']);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 8 * 1024 * 1024) {
        respond(413, ['ok' => false, 'error' => 'Request too large']);
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) respond(400, ['ok' => false, 'error' => 'Invalid JSON']);

    $key = $body['key'] ?? '';
    if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
        respond(400, ['ok' => false, 'error' => 'Invalid storage key']);
    }

    if (($body['action'] ?? '') === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM app_storage WHERE storage_key = ?');
        $stmt->execute([$key]);
    } else {
        if (($body['action'] ?? '') !== 'save' || !isset($body['value']) || !is_string($body['value'])) {
            respond(400, ['ok' => false, 'error' => 'Invalid save payload']);
        }
        if (strlen($body['value']) > 8 * 1024 * 1024) {
            respond(413, ['ok' => false, 'error' => 'Value too large']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO app_storage (storage_key, storage_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE storage_value = VALUES(storage_value)'
        );
        $stmt->execute([$key, $body['value']]);
    }

    $sync = syncTables($pdo);
    respond(200, ['ok' => true, 'synced' => true, 'sync' => $sync]);

} catch (Throwable $e) {
    error_log('NotesArchive API error: ' . $e->getMessage());
    respond(500, [
        'ok' => false,
        'error' => 'Database connection or query failed. Check the PHP error log for details.'
    ]);
}
