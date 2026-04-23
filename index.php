<?php
/**
 * Easy Upload v2.1
 *
 * New features:
 *   - Shareable links with expiry time       → ?action=share
 *   - Password-protected share links         → ?action=share&password=
 *   - /file/{public_id}  → truy cập trực tiếp qua public_id (vĩnh viễn)
 *   - /share/{token}     → share link (có password/expiry)
 *   - Video streaming (HTTP Range)
 *   - PDF & media inline serving
 *   - Image resize: ?w= ?h= ?s=
 */

define('UPLOAD_BASE', __DIR__ . '/storage/uploads/');
define('THUMB_DIR',   __DIR__ . '/storage/cache/');
define('DB_FILE',     __DIR__ . '/storage/database.db');
define('PER_PAGE', 20);
define('BASE_URL', (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'));

// --- SECURITY CONFIG ---
if (file_exists(__DIR__ . '/config.php')) include __DIR__ . '/config.php';
if (!defined('API_KEY'))         define('API_KEY',         '');
if (!defined('ALLOWED_ORIGINS')) define('ALLOWED_ORIGINS', ['*']);
if (!defined('RATE_LIMIT'))      define('RATE_LIMIT',      100);

// --- SETUP ---
foreach ([UPLOAD_BASE, THUMB_DIR] as $d) if (!is_dir($d)) mkdir($d, 0755, true);
// Tự tạo storage/.htaccess nếu bị xóa
$storageHtaccess = dirname(UPLOAD_BASE) . '/.htaccess';
if (!file_exists($storageHtaccess)) {
    file_put_contents($storageHtaccess,
        "Options -Indexes\n" .
        "<Files \"database.db\">\n    Order allow,deny\n    Deny from all\n</Files>\n" .
        "php_flag engine off\n"
    );
}
$db = new PDO('sqlite:' . DB_FILE);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT UNIQUE,
    filename TEXT, original_name TEXT, mime TEXT, size INTEGER, created_at INTEGER, hash TEXT
)');
$db->exec('CREATE TABLE IF NOT EXISTS shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT UNIQUE,
    file_id INTEGER,
    password TEXT,
    expires_at INTEGER,
    created_at INTEGER,
    access_count INTEGER DEFAULT 0
)');
$db->exec('CREATE TABLE IF NOT EXISTS rate_limits (
    ip TEXT PRIMARY KEY, hits INTEGER DEFAULT 1, window INTEGER DEFAULT 0
)');
// Migration: thêm public_id/hash vào bảng cũ nếu chưa có
try { $db->exec('ALTER TABLE files ADD COLUMN public_id TEXT'); } catch (Exception $e) {}
try { $db->exec('ALTER TABLE files ADD COLUMN hash TEXT'); } catch (Exception $e) {}
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_files_public_id ON files(public_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_files_hash ON files(hash)');
// Backfill: gán public_id cho các row cũ đang NULL
$nullRows = $db->query('SELECT id FROM files WHERE public_id IS NULL')->fetchAll(PDO::FETCH_ASSOC);
if ($nullRows) {
    $backfill = $db->prepare('UPDATE files SET public_id = ? WHERE id = ?');
    foreach ($nullRows as $row) {
        for ($t = 0; $t < 3; $t++) {
            try { $backfill->execute([gen_id_raw(), $row['id']]); break; } catch (Exception $e) {}
        }
    }
}

// --- HELPERS ---
function out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fmt(int $b): string {
    return $b >= 1048576 ? number_format($b / 1048576, 2) . ' MB' : number_format($b / 1024, 1) . ' KB';
}

// CORS: set headers theo ALLOWED_ORIGINS, handle OPTIONS preflight
function handle_cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $list   = ALLOWED_ORIGINS;
    if ($list[0] === '*') {
        header('Access-Control-Allow-Origin: *');
    } elseif (in_array($origin, $list)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');
    header('Access-Control-Max-Age: 86400');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

// API Key: bảo vệ write operations
function require_api_key(): void {
    if (!API_KEY) return; // disabled nếu config chưa set
    $key = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    if ($key !== API_KEY) out(['error' => 'Unauthorized', 'hint' => 'Set X-Api-Key header'], 401);
}

// Rate Limit: đếm request theo IP và window 1 phút
function rate_limit(PDO $db): void {
    $limit  = RATE_LIMIT;
    $ip     = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip     = trim(explode(',', $ip)[0]); // lấy IP đầu tiên nếu có proxy chain
    $window = (int)(time() / 60);

    $row = $db->prepare('SELECT hits, window FROM rate_limits WHERE ip = ?');
    $row->execute([$ip]);
    $data = $row->fetch(PDO::FETCH_ASSOC);

    if (!$data || (int)$data['window'] !== $window) {
        $db->prepare('INSERT OR REPLACE INTO rate_limits (ip, hits, window) VALUES (?,1,?)')->execute([$ip, $window]);
    } elseif ((int)$data['hits'] >= $limit) {
        $retry = 60 - (time() % 60);
        header('Retry-After: ' . $retry);
        out(['error' => 'Rate limit exceeded', 'limit' => "$limit req/min", 'retry_after' => "${retry}s"], 429);
    } else {
        $db->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE ip = ?')->execute([$ip]);
    }
}

function share_url(string $token): string {
    return BASE_URL . '/share/' . $token;
}

function file_url(string $pubId): string {
    return BASE_URL . '/file/' . $pubId;
}

/**
 * Resize image and serve, or fallback to original.
 * Cache key prefix to avoid collisions between /file/ and /share/ routes.
 */
function resize_and_serve(string $filePath, string $mime, string $name, string $cachePrefix): void {
    $w = (int)($_GET['w'] ?? 0);
    $h = (int)($_GET['h'] ?? 0);
    $s = (int)($_GET['s'] ?? 0);
    $allowed = [100,150,200,300,400,500,600,800,1000,1200];

    if (($w || $h || $s) && str_starts_with($mime, 'image/') && extension_loaded('imagick')) {
        if (in_array($w, $allowed) || in_array($h, $allowed) || in_array($s, $allowed)) {
            $cachePath = THUMB_DIR . $cachePrefix . '_w' . $w . '_h' . $h . '_s' . $s;
            if (!file_exists($cachePath)) {
                try {
                    $img = new Imagick($filePath);
                    if ($s > 0) $img->cropThumbnailImage($s, $s);
                    else $img->thumbnailImage($w, $h, ($w > 0 && $h > 0));
                    $img->setImageCompressionQuality(85);
                    $img->writeImage($cachePath);
                    chmod($cachePath, 0644);
                    $img->destroy();
                } catch (Exception $e) {}
            }
            if (file_exists($cachePath)) {
                serve_file($cachePath, mime_content_type($cachePath), $name);
            }
        }
    }

    serve_file($filePath, $mime, $name);
}

/**
 * Generate a unique base62 ID:
 * 7 chars = millisecond timestamp in base62 (sortable, time-ordered)
 * 3 chars = random base62 (collision-safe within same ms)
 * Result: 10-char ID like "A2ytaqJa9X"
 */
function gen_id(): string { return gen_id_raw(); }
function gen_id_raw(): string {
    static $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $n = (int)(microtime(true) * 1000);
    $ts = '';
    while ($n > 0) { $ts = $chars[$n % 62] . $ts; $n = intdiv($n, 62); }
    $ts = str_pad($ts, 7, '0', STR_PAD_LEFT);
    $rand = '';
    for ($i = 0; $i < 4; $i++) $rand .= $chars[random_int(0, 61)];
    return $ts . $rand;
}

/**
 * Serve a file with HTTP Range support (video streaming) and inline/attachment detection.
 * Inline types: image/*, video/*, audio/*, application/pdf
 */
function serve_file(string $path, string $mime, string $name): void {
    if (!file_exists($path)) { http_response_code(404); exit; }

    $size   = filesize($path);
    $inline = preg_match('#^(image|video|audio)/#', $mime) || $mime === 'application/pdf';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($name) . '"');
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=86400');

    // HTTP Range support — required for video seek / resume
    if (!empty($_SERVER['HTTP_RANGE'])) {
        [, $range] = explode('=', $_SERVER['HTTP_RANGE'], 2);
        [$start, $end] = array_pad(explode('-', $range, 2), 2, '');
        $start = max(0, (int)$start);
        $end   = ($end !== '' && (int)$end < $size) ? (int)$end : $size - 1;
        $length = $end - $start + 1;

        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
        header("Content-Length: $length");

        $fp = fopen($path, 'rb');
        fseek($fp, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = min(65536, $remaining);
            echo fread($fp, $chunk);
            $remaining -= $chunk;
            flush();
        }
        fclose($fp);
    } else {
        header("Content-Length: $size");
        readfile($path);
    }
    exit;
}

// ============================================================
// CORS + Rate limit — áp dụng cho tất cả request
// ============================================================
handle_cors();
rate_limit($db);

// ============================================================
// ROUTE: /file/{public_id}  → trực tiếp (không expiry, không cần key)
// ============================================================
if (isset($_GET['pubid'])) {
    $pubId = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['pubid']);
    $stmt  = $db->prepare('SELECT filename, original_name, mime FROM files WHERE public_id = ?');
    $stmt->execute([$pubId]);
    $file  = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) out(['error' => 'File not found'], 404);

    resize_and_serve(UPLOAD_BASE . $file['filename'], $file['mime'], $file['original_name'], 'pub_' . $pubId);
}

// ============================================================
// ROUTE: /share/{token}  → share link (password / expiry)
// ============================================================
if (isset($_GET['token'])) {
    $token = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['token']);

    $stmt = $db->prepare('
        SELECT s.*, f.filename, f.original_name, f.mime
        FROM shares s JOIN files f ON s.file_id = f.id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $share = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$share)                                             out(['error' => 'Share not found'], 404);
    if ($share['expires_at'] && $share['expires_at'] < time()) out(['error' => 'Share link has expired'], 410);

    if ($share['password']) {
        $provided = $_GET['password'] ?? $_SERVER['HTTP_X_SHARE_PASSWORD'] ?? '';
        if ($provided !== $share['password'])
            out(['error' => 'Password required', 'hint' => 'Add ?password=YOUR_PASSWORD to the URL'], 401);
    }

    $db->prepare('UPDATE shares SET access_count = access_count + 1 WHERE token = ?')->execute([$token]);

    resize_and_serve(UPLOAD_BASE . $share['filename'], $share['mime'], $share['original_name'], 'tok_' . $token);
}

// ============================================================
// IMAGE PROXY (resize on-the-fly, binary response)
// ============================================================
if (isset($_GET['process_image'])) {
    $source = UPLOAD_BASE . $_GET['process_image'];
    if (!file_exists($source)) { http_response_code(404); exit; }

    $w = (int)($_GET['w'] ?? 0);
    $h = (int)($_GET['h'] ?? 0);
    $s = (int)($_GET['s'] ?? 0);
    $cacheName = 'cache_w'.$w.'_h'.$h.'_s'.$s.'_' . str_replace('/', '_', $_GET['process_image']);
    $cachePath = THUMB_DIR . $cacheName;
    $allowed   = [100,150,200,300,400,500,600,800,1000,1200];

    if (!file_exists($cachePath) && extension_loaded('imagick')) {
        try {
            if (in_array($w, $allowed) || in_array($h, $allowed) || in_array($s, $allowed)) {
                $img = new Imagick($source);
                if ($s > 0) $img->cropThumbnailImage($s, $s);
                else $img->thumbnailImage($w, $h, ($w > 0 && $h > 0));
                $img->setImageCompressionQuality(85);
                $img->writeImage($cachePath);
                chmod($cachePath, 0644);
                $img->destroy();
            }
        } catch (Exception $e) {}
    }

    $serve = file_exists($cachePath) ? $cachePath : $source;
    serve_file($serve, mime_content_type($serve), basename($serve));
}

// ============================================================
// ALL OTHER ACTIONS → JSON (require API Key)
// ============================================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
require_api_key();

// ── ZIP (Multi-download) ──────────────────────────────────────
if ($action === 'zip') {
    $ids = $_REQUEST['ids'] ?? '';
    if (is_string($ids)) {
        $ids = array_filter(explode(',', $ids));
    }
    if (!is_array($ids) || empty($ids)) {
        out(['error' => 'No files specified. Use ?ids=id1,id2'], 400);
    }

    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $db->prepare("SELECT public_id, filename, original_name FROM files WHERE public_id IN ($placeholders)");
    $stmt->execute(array_values($ids));
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$files) {
        out(['error' => 'No valid files found for the given IDs'], 404);
    }

    $zipFile = sys_get_temp_dir() . '/easy_upload_' . uniqid() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        out(['error' => 'Cannot create zip file'], 500);
    }

    $names = [];
    foreach ($files as $f) {
        $path = UPLOAD_BASE . $f['filename'];
        if (is_file($path)) {
            $name = $f['original_name'];
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $c = 1;
            // Xử lý trùng tên file bên trong mảng nén (vd: tải 2 file đều tên image.png)
            while (isset($names[$name])) {
                $name = $base . '_' . $c++ . ($ext ? '.' . $ext : '');
            }
            $names[$name] = true;
            $zip->addFile($path, $name);
        }
    }
    $zip->close();

    if (!file_exists($zipFile) || filesize($zipFile) == 0) {
        @unlink($zipFile);
        out(['error' => 'Zip file is empty (files might be missing on disk)'], 400);
    }

    $zipName = 'download_' . date('Y-m-d_H-i') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($zipFile));
    header('Cache-Control: no-cache, must-revalidate');

    readfile($zipFile);
    @unlink($zipFile);
    exit;
}

// ── LIST ─────────────────────────────────────────────────────
if ($action === 'list') {
    $q      = $_GET['q'] ?? '';
    $from   = !empty($_GET['from']) ? strtotime($_GET['from'] . ' 00:00:00') : null;
    $to     = !empty($_GET['to'])   ? strtotime($_GET['to']   . ' 23:59:59') : null;
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * PER_PAGE;

    $where = 'original_name LIKE :q';
    if ($from && $to) $where .= " AND created_at BETWEEN $from AND $to";

    $countStmt = $db->prepare("SELECT COUNT(*) FROM files WHERE $where");
    $countStmt->execute([':q' => "%$q%"]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT * FROM files WHERE $where ORDER BY created_at DESC LIMIT " . PER_PAGE . " OFFSET $offset");
    $stmt->execute([':q' => "%$q%"]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files as &$f) {
        $pubId           = $f['public_id'];
        $f['id']         = $pubId;
        $f['url']        = file_url($pubId);           // /file/{public_id}
        $f['raw_url']    = 'storage/uploads/' . $f['filename'];
        $f['size_fmt']   = fmt((int)$f['size']);
        $f['created_at'] = (int)$f['created_at'];
        unset($f['public_id']);
    }

    out(['total' => $total, 'page' => $page, 'per_page' => PER_PAGE,
         'total_pages' => (int)ceil($total / PER_PAGE), 'files' => $files]);
}

// ── UPLOAD ───────────────────────────────────────────────────
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
    $ymd    = date('Y/m/d');
    $upPath = UPLOAD_BASE . $ymd . '/';
    if (!is_dir($upPath)) mkdir($upPath, 0755, true);

    $stmt      = $db->prepare('INSERT INTO files (public_id, filename, original_name, mime, size, created_at, hash) VALUES (?,?,?,?,?,?,?)');
    $shareStmt = $db->prepare('INSERT INTO shares (token, file_id, password, expires_at, created_at) VALUES (?,?,NULL,NULL,?)');
    $uploaded  = [];

    foreach ($_FILES['files']['name'] as $k => $name) {
        if ($_FILES['files']['error'][$k] !== UPLOAD_ERR_OK) continue;
        $tmpName = $_FILES['files']['tmp_name'][$k];

        // --- CHUNKED UPLOAD SUPPORT ---
        $totalChunks = (int)($_POST['dztotalchunkcount'] ?? $_POST['resumableTotalChunks'] ?? 0);
        if ($totalChunks > 1) {
            $chunkIndex = (int)($_POST['dzchunkindex'] ?? ($_POST['resumableChunkNumber'] ?? 1) - 1);
            $uuid       = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['dzuuid'] ?? $_POST['resumableIdentifier'] ?? md5($name));
            $partPath   = sys_get_temp_dir() . '/easy_upload_' . $uuid . '.part';
            $countPath  = $partPath . '.count';
            
            $chunkSize  = (int)($_POST['dzchunksize'] ?? $_POST['resumableChunkSize'] ?? 0);
            $offset     = (int)($_POST['dzchunkbyteoffset'] ?? ($chunkIndex * $chunkSize));

            $out = fopen($partPath, 'cb'); // Create/Write, con trỏ ở đầu file
            if ($out) {
                flock($out, LOCK_EX); // Chống lỗi ghi đè nát file khi tải Parallel song song
                fseek($out, $offset);
                $in = fopen($tmpName, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                
                // Đếm số mảnh đã hạ cánh (chống lỗi out-of-order mảng cuối về trước mảng đầu)
                $cFp = fopen($countPath, 'cb+');
                $received = (int)stream_get_contents($cFp) + 1;
                rewind($cFp);
                ftruncate($cFp, 0);
                fwrite($cFp, (string)$received);
                fclose($cFp);
                
                flock($out, LOCK_UN);
                fclose($out);
            }

            if ($received < $totalChunks) {
                out(['status' => 'chunk_uploaded', 'chunk' => $chunkIndex, 'received' => $received]);
            }
            
            // Mảnh Cuối Cùng! -> Tráo hàng mảnh partPath thành tmpName xử lý tiếp
            @unlink($countPath);
            $tmpName = $partPath;
            $_FILES['files']['size'][$k] = filesize($partPath);
        }
        // --- END CHUNKED ---

        $hash = sha1_file($tmpName);

        // --- Xác Thực Mime Type Chính Xác (Sửa lỗi Octet-stream của Chunk Dropzone) ---
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = @mime_content_type($tmpName);
        if (!$mime || $mime === 'application/octet-stream' || $mime === 'inode/x-empty') {
            $mmap = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
                     'webp'=>'image/webp','mp4'=>'video/mp4','mp3'=>'audio/mpeg','pdf'=>'application/pdf',
                     'zip'=>'application/zip','rar'=>'application/vnd.rar','txt'=>'text/plain'];
            $mime = $mmap[$ext] ?? 'application/octet-stream';
        }
        $_FILES['files']['type'][$k] = $mime;
        // --- End Mime Type ---

        // Deduplication: Kiểm tra xem file đã từng upload chưa
        $dupStmt = $db->prepare('SELECT filename FROM files WHERE hash = ? LIMIT 1');
        $dupStmt->execute([$hash]);
        $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);

        $pubId    = gen_id();
        $filename = '';

        if ($dup) {
            $filename = $dup['filename']; // Dùng lại file cũ trên ổ cứng
            // Nếu là file chunked temp thì xóa đi, vì rác ko dùng
            if ($totalChunks > 1) @unlink($tmpName);
        } else {
            $safe = bin2hex(random_bytes(4)) . '_' . time() . '.' . $ext;
            if (is_uploaded_file($tmpName)) {
                $success = move_uploaded_file($tmpName, $upPath . $safe);
            } else {
                $success = rename($tmpName, $upPath . $safe); // Rename file ghép (.part)
            }
            if ($success) {
                $filename = $ymd . '/' . $safe;
            } else {
                continue;
            }
        }

        // Retry nếu trùng public_id (cực hiếm)
        for ($try = 0; $try < 3; $try++) {
            try {
                $stmt->execute([$pubId, $filename, $name, $_FILES['files']['type'][$k], $_FILES['files']['size'][$k], time(), $hash]);
                break;
            } catch (Exception $e) { $pubId = gen_id(); }
        }
        // Auto-tạo share link vĩnh viễn
        $token = bin2hex(random_bytes(8));
        $shareStmt->execute([$token, (int)$db->lastInsertId(), time()]);

        $uploaded[] = [
            'id'            => $pubId,
            'original_name' => $name,
            'url'           => file_url($pubId),           // /file/{public_id} — permanent
            'share_url'     => share_url($token),          // /share/{token} — shareable
            'raw_url'       => 'storage/uploads/' . $filename,
            'mime'          => $_FILES['files']['type'][$k],
            'size'          => (int)$_FILES['files']['size'][$k],
            'size_fmt'      => fmt((int)$_FILES['files']['size'][$k]),
        ];
    }

    out(['uploaded' => $uploaded, 'count' => count($uploaded)], 201);
}


// ── DELETE single ─────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT id, filename FROM files WHERE public_id = ?');
    $stmt->execute([$_GET['id']]);
    if ($f = $stmt->fetch()) {
        $db->prepare('DELETE FROM shares WHERE file_id = ?')->execute([$f['id']]);
        $db->prepare('DELETE FROM files WHERE id = ?')->execute([$f['id']]);
        
        // Chỉ unlink physical file nếu ko còn ai (public_id nào khác) trỏ vào filename này
        $check = $db->prepare('SELECT COUNT(1) FROM files WHERE filename = ?');
        $check->execute([$f['filename']]);
        if ($check->fetchColumn() == 0) {
            $slug = str_replace('/', '_', $f['filename']);
            @unlink(UPLOAD_BASE . $f['filename']);
            foreach (glob(THUMB_DIR . '*' . $slug) as $c) @unlink($c);
        }
        
        out(['deleted' => true, 'id' => $_GET['id']]);
    }
    out(['error' => 'File not found'], 404);
}

// ── BULK DELETE ───────────────────────────────────────────────
if ($action === 'bulk_delete' && !empty($_POST['ids'])) {
    $selStmt   = $db->prepare('SELECT id, filename FROM files WHERE public_id = ?');
    $delShares = $db->prepare('DELETE FROM shares WHERE file_id = ?');
    $delStmt   = $db->prepare('DELETE FROM files WHERE id = ?');
    $chkStmt   = $db->prepare('SELECT COUNT(1) FROM files WHERE filename = ?');
    $deleted   = [];
    foreach ((array)$_POST['ids'] as $pubId) {
        $selStmt->execute([$pubId]);
        if ($f = $selStmt->fetch()) {
            $delShares->execute([$f['id']]);
            $delStmt->execute([$f['id']]);
            
            // Deduplication safety check
            $chkStmt->execute([$f['filename']]);
            if ($chkStmt->fetchColumn() == 0) {
                $slug = str_replace('/', '_', $f['filename']);
                @unlink(UPLOAD_BASE . $f['filename']);
                foreach (glob(THUMB_DIR . '*' . $slug) as $c) @unlink($c);
            }
            $deleted[] = $pubId;
        }
    }
    out(['deleted' => $deleted, 'count' => count($deleted)]);
}

// ── CREATE SHARE ──────────────────────────────────────────────
if ($action === 'share' && isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT id FROM files WHERE public_id = ?');
    $stmt->execute([$_GET['id']]);
    if (!($row = $stmt->fetch())) out(['error' => 'File not found'], 404);

    $token     = bin2hex(random_bytes(8));  // 16-char token
    $expiresIn = isset($_GET['expires']) ? (int)$_GET['expires'] : 0;
    $expiresAt = $expiresIn > 0 ? time() + $expiresIn : null;
    $password  = !empty($_GET['password']) ? $_GET['password'] : null;

    $db->prepare('INSERT INTO shares (token, file_id, password, expires_at, created_at) VALUES (?,?,?,?,?)')
       ->execute([$token, $row['id'], $password, $expiresAt, time()]);

    out([
        'token'      => $token,
        'url'        => share_url($token),
        'expires_at' => $expiresAt,
        'expires_in' => $expiresIn ? $expiresIn . 's (' . gmdate('H:i:s', $expiresIn) . ')' : 'never',
        'protected'  => (bool)$password,
    ]);
}

// ── LIST SHARES for a file ────────────────────────────────────
if ($action === 'shares' && isset($_GET['id'])) {
    $fileStmt = $db->prepare('SELECT id FROM files WHERE public_id = ?');
    $fileStmt->execute([$_GET['id']]);
    if (!($row = $fileStmt->fetch())) out(['error' => 'File not found'], 404);

    $stmt = $db->prepare('
        SELECT token, (password IS NOT NULL) as protected, expires_at, created_at, access_count
        FROM shares WHERE file_id = ? ORDER BY created_at DESC
    ');
    $stmt->execute([$row['id']]);
    $shares = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($shares as &$s) {
        $s['url']       = share_url($s['token']);
        $s['expired']   = $s['expires_at'] && $s['expires_at'] < time();
        $s['protected'] = (bool)$s['protected'];
    }
    out(['file_id' => $_GET['id'], 'shares' => $shares]);
}

// ── REVOKE SHARE ──────────────────────────────────────────────
if ($action === 'revoke' && isset($_GET['token'])) {
    $token = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['token']);
    $stmt  = $db->prepare('DELETE FROM shares WHERE token = ?');
    $stmt->execute([$token]);
    out(['revoked' => $stmt->rowCount() > 0, 'token' => $token]);
}

// ── CLEAR CACHE ───────────────────────────────────────────────
if ($action === 'clear_cache') {
    $count = 0;
    foreach (glob(THUMB_DIR . '*') as $f) { @unlink($f); $count++; }
    out(['cleared' => $count]);
}

// ── MAINTENANCE / CRON (Garbage Collector) ────────────────────
if ($action === 'maintenance') {
    $report = [];
    $time   = time();

    // 1. Xóa Rate Limits cũ (Chỉ giữ lại log của 1-2 phút gần nhất)
    $winDel = (int)($time / 60) - 2;
    $stmt   = $db->prepare('DELETE FROM rate_limits WHERE window < ?');
    $stmt->execute([$winDel]);
    $report['rate_limits_cleaned'] = $stmt->rowCount();

    // 2. Xóa Share Tokens quá hạn
    $stmt = $db->prepare('DELETE FROM shares WHERE expires_at > 0 AND expires_at < ?');
    $stmt->execute([$time]);
    $report['expired_shares_deleted'] = $stmt->rowCount();

    // 3. Xóa Cache thumbnails > 30 ngày không ai xem
    $cacheDel = 0;
    $cacheExp = $time - (30 * 86400);
    foreach (glob(THUMB_DIR . '*') as $f) {
        if (is_file($f) && filemtime($f) < $cacheExp) {
            @unlink($f);
            $cacheDel++;
        }
    }
    $report['old_caches_deleted'] = $cacheDel;

    // 4. Chống File mồ côi (Self-healing)
    // 4.1. Mất file vật lý -> DB lỗi 404 -> Xóa row trong DB
    $orphansDb = 0;
    $files = $db->query('SELECT id, filename FROM files')->fetchAll(PDO::FETCH_ASSOC);
    $validFilenames = [];
    $delFile = $db->prepare('DELETE FROM files WHERE id = ?');
    $delSh   = $db->prepare('DELETE FROM shares WHERE file_id = ?');
    
    foreach ($files as $f) {
        $path = UPLOAD_BASE . $f['filename'];
        if (!is_file($path)) {
            $delSh->execute([$f['id']]);
            $delFile->execute([$f['id']]);
            $orphansDb++;
        } else {
            $validFilenames[$f['filename']] = true;
        }
    }
    $report['missing_physical_files_db_cleaned'] = $orphansDb;

    // 4.2. Dư file vật lý (file rác không có mặt trong DB) -> Xóa file
    $orphansDisk = 0;
    $baseLen = strlen(UPLOAD_BASE);
    if (is_dir(UPLOAD_BASE)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_BASE, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fileinfo) {
            if ($fileinfo->isFile()) {
                $relPath = str_replace('\\', '/', substr($fileinfo->getPathname(), $baseLen));
                if ($relPath === '.htaccess') continue; // Bỏ qua file bảo mật
                if (!isset($validFilenames[$relPath])) {
                    @unlink($fileinfo->getPathname());
                    $orphansDisk++;
                }
            }
        }
    }
    $report['orphaned_physical_files_deleted'] = $orphansDisk;

    // 5. Dọn dẹp file chunk/zip bị treo trong thư mục tạm hệ thống (> 24h)
    $tempDel = 0;
    $tempExp = $time - 86400;
    foreach (glob(sys_get_temp_dir() . '/easy_upload_*') as $f) {
        if (is_file($f) && filemtime($f) < $tempExp) {
            @unlink($f);
            $tempDel++;
        }
    }
    $report['temp_trash_deleted'] = $tempDel;

    // 6. Tối ưu hoá dung lượng ổ cứng của SQLite (VACUUM)
    $db->exec('VACUUM');
    $report['database_optimized'] = true;

    out(['maintenance' => 'success', 'report' => $report]);
}

// ── STATS ─────────────────────────────────────────────────────
if ($action === 'stats') {
    out([
        'total_files'      => (int)($db->query('SELECT COUNT(*) FROM files')->fetchColumn() ?: 0),
        'total_shares'     => (int)($db->query('SELECT COUNT(*) FROM shares')->fetchColumn() ?: 0),
        'active_shares'    => (int)($db->query('SELECT COUNT(*) FROM shares WHERE expires_at IS NULL OR expires_at > ' . time())->fetchColumn() ?: 0),
        'total_size_bytes' => (int)($db->query('SELECT SUM(size) FROM files')->fetchColumn() ?: 0),
        'total_size'       => fmt((int)($db->query('SELECT SUM(size) FROM files')->fetchColumn() ?: 0)),
    ]);
}

// Fallback: không match route nào → JSON thay vì HTML server error
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($action) {
    out(['error' => 'Unknown action', 'valid_actions' => ['list','upload','delete','bulk_delete','zip','share','shares','revoke','clear_cache','maintenance','stats']], 400);
}
out(['error' => 'Not found', 'path' => $uri], 404);