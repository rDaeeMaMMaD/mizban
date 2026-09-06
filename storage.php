<?php
/**
 * میزبان v5.0.0 - Storage Manager
 * ------------------------------------------------------------------
 * ذخیره‌سازی فایل‌ها با قابلیت توسعه:
 *   - Local (پوشه uploads/)
 *   - S3 (در آینده)
 *   - MinIO (در آینده)
 *   - FTP (در آینده)
 *
 * جدول attachments:
 *   id, file_id, original_name, stored_path, stored_url, mime_type,
 *   file_size, file_hash, storage_driver, created_at
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';

class StorageManager {
    private PDO $db;
    private string $uploadDir;
    private string $uploadUrl;

    public function __construct() {
        $this->db = db();
        $this->uploadDir = __DIR__ . '/uploads';
        $this->uploadUrl = '/uploads';
        if (!is_dir($this->uploadDir)) @mkdir($this->uploadDir, 0755, true);
    }

    /**
     * ذخیره یک فایل (از upload یا base64 یا URL).
     * @return array ['file_id' => str, 'path' => str, 'url' => str, 'mime' => str, 'size' => int, 'hash' => str]
     */
    public function store(string $data, string $mime, string $originalName = ''): array {
        $fileId = bin2hex(random_bytes(16));
        $ext = $this->getExtension($mime, $originalName);
        $storedPath = $this->uploadDir . '/' . $fileId . $ext;
        $storedUrl = $this->uploadUrl . '/' . $fileId . $ext;

        file_put_contents($storedPath, $data);
        $size = strlen($data);
        $hash = hash_file('sha256', $storedPath);

        // ثبت در دیتابیس
        $this->db->prepare("
            INSERT INTO attachments (file_id, original_name, stored_path, stored_url, mime_type, file_size, file_hash, storage_driver)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'local')
        ")->execute([$fileId, $originalName, $storedPath, $storedUrl, $mime, $size, $hash]);

        return [
            'file_id' => $fileId,
            'path' => $storedPath,
            'url' => $storedUrl,
            'mime' => $mime,
            'size' => $size,
            'hash' => $hash,
        ];
    }

    /**
     * ذخیره از base64.
     */
    public function storeFromBase64(string $base64, string $mime, string $name = ''): array {
        $data = base64_decode($base64);
        if ($data === false) throw new InvalidArgumentException(mtr('err.base64Invalid'));
        return $this->store($data, $mime, $name);
    }

    /**
     * ذخیره از URL (دانلود فایل).
     */
    public function storeFromUrl(string $url, string $name = ''): array {
        $data = @file_get_contents($url);
        if ($data === false) throw new RuntimeException(mtr('err.downloadFailed'));
        $mime = $this->getMimeFromUrl($url);
        return $this->store($data, $mime, $name ?: basename($url));
    }

    /**
     * ولیدیشن فایل.
     */
    public function validate(string $mime, int $size): array {
        $maxSize = 50 * 1024 * 1024; // 50MB
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf',
            'audio/mpeg', 'audio/wav', 'audio/webm', 'audio/mp4',
            'video/mp4', 'video/webm',
            'text/plain', 'application/json',
        ];
        if (!in_array($mime, $allowedMimes)) {
            return ['ok' => false, 'error' => mtr('err.mimeNotAllowed', ['{mime}' => $mime])];
        }
        if ($size > $maxSize) {
            return ['ok' => false, 'error' => mtr('err.fileTooLarge')];
        }
        return ['ok' => true];
    }

    /**
     * گرفتن اطلاعات یک فایل.
     */
    public function get(string $fileId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM attachments WHERE file_id = ?");
        $stmt->execute([$fileId]);
        $r = $stmt->fetch();
        return $r ?: null;
    }

    /**
     * حذف یک فایل.
     */
    public function delete(string $fileId): bool {
        $file = $this->get($fileId);
        if (!$file) return false;
        @unlink($file['stored_path']);
        $this->db->prepare("DELETE FROM attachments WHERE file_id = ?")->execute([$fileId]);
        return true;
    }

    private function getExtension(string $mime, string $name): string {
        if ($name && strpos($name, '.') !== false) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            return '.' . $ext;
        }
        $map = [
            'image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif',
            'image/webp' => '.webp', 'application/pdf' => '.pdf',
            'audio/mpeg' => '.mp3', 'audio/wav' => '.wav', 'audio/webm' => '.webm',
            'video/mp4' => '.mp4', 'video/webm' => '.webm',
            'text/plain' => '.txt', 'application/json' => '.json',
        ];
        return $map[$mime] ?? '';
    }

    private function getMimeFromUrl(string $url): string {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'mp4' => 'video/mp4',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
}

// پایان storage.php
