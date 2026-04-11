<?php

if (!function_exists('dbEnsureSessionStoreSchema')) {
    function dbEnsureSessionStoreSchema(PDO $pdo): void
    {
        if (dbTableExists($pdo, 'app_sessions')) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_sessions (
                session_id VARCHAR(128) PRIMARY KEY,
                payload TEXT NOT NULL,
                last_activity BIGINT NOT NULL,
                created_at BIGINT NOT NULL
            )
        ");
    }
}

if (!class_exists('DatabaseSessionHandler')) {
    class DatabaseSessionHandler implements SessionHandlerInterface
    {
        private PDO $pdo;
        private bool $schemaReady = false;

        public function __construct(PDO $pdo)
        {
            $this->pdo = $pdo;
        }

        public function open(string $savePath, string $sessionName): bool
        {
            $this->ensureSchema();
            return true;
        }

        public function close(): bool
        {
            return true;
        }

        public function read(string $id): string|false
        {
            $this->ensureSchema();

            $stmt = $this->pdo->prepare("SELECT payload FROM app_sessions WHERE session_id = ? LIMIT 1");
            $stmt->execute([$id]);
            $payload = $stmt->fetchColumn();

            if (!is_string($payload) || $payload === '') {
                return '';
            }

            $decoded = base64_decode($payload, true);
            return $decoded === false ? '' : $decoded;
        }

        public function write(string $id, string $data): bool
        {
            $this->ensureSchema();

            $encodedPayload = base64_encode($data);
            $now = time();

            if (dbIsPgsql($this->pdo)) {
                $sql = "
                    INSERT INTO app_sessions (session_id, payload, last_activity, created_at)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT (session_id)
                    DO UPDATE SET
                        payload = EXCLUDED.payload,
                        last_activity = EXCLUDED.last_activity
                ";
            } else {
                $sql = "
                    INSERT INTO app_sessions (session_id, payload, last_activity, created_at)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        payload = VALUES(payload),
                        last_activity = VALUES(last_activity)
                ";
            }

            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([$id, $encodedPayload, $now, $now]);
        }

        public function destroy(string $id): bool
        {
            $this->ensureSchema();

            $stmt = $this->pdo->prepare("DELETE FROM app_sessions WHERE session_id = ?");
            return $stmt->execute([$id]);
        }

        public function gc(int $max_lifetime): int|false
        {
            $this->ensureSchema();

            $cutoff = time() - $max_lifetime;
            $stmt = $this->pdo->prepare("DELETE FROM app_sessions WHERE last_activity < ?");
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        }

        private function ensureSchema(): void
        {
            if ($this->schemaReady) {
                return;
            }

            dbEnsureSessionStoreSchema($this->pdo);
            $this->schemaReady = true;
        }
    }
}

if (!function_exists('registerDatabaseSessionHandler')) {
    function registerDatabaseSessionHandler(PDO $pdo): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $handler = new DatabaseSessionHandler($pdo);
        session_set_save_handler($handler, true);
        $registered = true;
    }
}
