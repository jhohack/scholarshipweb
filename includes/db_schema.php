<?php
require_once __DIR__ . '/cache.php';

if (!function_exists('dbDriverName')) {
    function dbDriverName(?PDO $pdo = null): string
    {
        if ($pdo instanceof PDO) {
            return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        }

        return defined('DB_DRIVER') ? strtolower((string) DB_DRIVER) : 'mysql';
    }
}

if (!function_exists('dbIsPgsql')) {
    function dbIsPgsql(?PDO $pdo = null): bool
    {
        return dbDriverName($pdo) === 'pgsql';
    }
}

if (!function_exists('dbIsMysql')) {
    function dbIsMysql(?PDO $pdo = null): bool
    {
        return dbDriverName($pdo) === 'mysql';
    }
}

if (!function_exists('dbSchemaCacheKey')) {
    function dbSchemaCacheKey(PDO $pdo): string
    {
        return spl_object_id($pdo) . ':' . dbDriverName($pdo);
    }
}

if (!function_exists('dbQuoteIdentifier')) {
    function dbQuoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        return dbIsPgsql() ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }
}

if (!function_exists('dbTableExists')) {
    function dbTableExists(PDO $pdo, string $tableName): bool
    {
        static $cache = [];
        $cacheKey = dbSchemaCacheKey($pdo) . ':table:' . $tableName;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        if (dbIsPgsql($pdo)) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = current_schema()
                AND table_name = ?
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                AND table_name = ?
            ");
        }

        $stmt->execute([$tableName]);
        return $cache[$cacheKey] = ((int) $stmt->fetchColumn() > 0);
    }
}

if (!function_exists('dbColumnExists')) {
    function dbColumnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        return in_array($columnName, dbListColumns($pdo, $tableName), true);
    }
}

if (!function_exists('dbListColumns')) {
    function dbListColumns(PDO $pdo, string $tableName): array
    {
        static $cache = [];
        $cacheKey = dbSchemaCacheKey($pdo) . ':columns:' . $tableName;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        if (dbIsPgsql($pdo)) {
            $stmt = $pdo->prepare("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                AND table_name = ?
                ORDER BY ordinal_position
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                AND table_name = ?
                ORDER BY ordinal_position
            ");
        }

        $stmt->execute([$tableName]);
        return $cache[$cacheKey] = ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}

if (!function_exists('dbIndexExists')) {
    function dbIndexExists(PDO $pdo, string $indexName): bool
    {
        static $cache = [];
        $cacheKey = dbSchemaCacheKey($pdo) . ':index:' . $indexName;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        if (dbIsPgsql($pdo)) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM pg_indexes
                WHERE schemaname = current_schema()
                AND indexname = ?
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                AND index_name = ?
            ");
        }

        $stmt->execute([$indexName]);
        return $cache[$cacheKey] = ((int) $stmt->fetchColumn() > 0);
    }
}

if (!function_exists('dbFormatIndexExpression')) {
    function dbFormatIndexExpression(string $expression): string
    {
        $expression = trim($expression);
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+(ASC|DESC))?$/i', $expression, $matches)) {
            $column = dbQuoteIdentifier($matches[1]);
            $direction = isset($matches[2]) ? ' ' . strtoupper($matches[2]) : '';
            return $column . $direction;
        }

        return $expression;
    }
}

if (!function_exists('dbEnsureIndex')) {
    function dbEnsureIndex(PDO $pdo, string $indexName, string $tableName, array $columns): void
    {
        if (dbIndexExists($pdo, $indexName)) {
            return;
        }

        $indexSql = dbQuoteIdentifier($indexName);
        $tableSql = dbQuoteIdentifier($tableName);
        $columnSql = implode(', ', array_map('dbFormatIndexExpression', $columns));

        $pdo->exec("CREATE INDEX {$indexSql} ON {$tableSql} ({$columnSql})");
    }
}

if (!function_exists('dbEnsureIndexBatchOnce')) {
    function dbEnsureIndexBatchOnce(PDO $pdo, string $cacheKey, callable $callback): void
    {
        if (function_exists('portalCacheGet') && function_exists('portalCacheSet') && function_exists('portalCacheMissMarker')) {
            $cached = portalCacheGet($cacheKey, 86400);
            if ($cached !== portalCacheMissMarker()) {
                return;
            }
        }

        $callback();

        if (function_exists('portalCacheSet')) {
            portalCacheSet($cacheKey, true, 86400);
        }
    }
}

if (!function_exists('dbEnsureStudentIndexes')) {
    function dbEnsureStudentIndexes(PDO $pdo): void
    {
        dbEnsureIndexBatchOnce($pdo, 'schema.indexes.students.v1', function () use ($pdo) {
            dbEnsureIndex($pdo, 'idx_students_user_id', 'students', ['user_id']);
            dbEnsureIndex($pdo, 'idx_students_school_id_number', 'students', ['school_id_number']);
        });
    }
}

if (!function_exists('dbEnsureApplicationsIndexes')) {
    function dbEnsureApplicationsIndexes(PDO $pdo): void
    {
        dbEnsureIndexBatchOnce($pdo, 'schema.indexes.applications.v1', function () use ($pdo) {
            dbEnsureIndex($pdo, 'idx_applications_student_scholarship_id_desc', 'applications', ['student_id', 'scholarship_id', 'id DESC']);
            dbEnsureIndex($pdo, 'idx_applications_scholarship_student_id_desc', 'applications', ['scholarship_id', 'student_id', 'id DESC']);
            dbEnsureIndex($pdo, 'idx_applications_student_status_id_desc', 'applications', ['student_id', 'status', 'id DESC']);
            dbEnsureIndex($pdo, 'idx_applications_scholarship_status_id_desc', 'applications', ['scholarship_id', 'status', 'id DESC']);
        });
    }
}

if (!function_exists('dbEnsureDocumentReviewIndexes')) {
    function dbEnsureDocumentReviewIndexes(PDO $pdo): void
    {
        dbEnsureIndexBatchOnce($pdo, 'schema.indexes.application_reupload_requests.v1', function () use ($pdo) {
            dbEnsureIndex($pdo, 'idx_application_reupload_requests_application_status_created_at', 'application_reupload_requests', ['application_id', 'status', 'created_at DESC', 'id DESC']);
            dbEnsureIndex($pdo, 'idx_application_reupload_requests_document_id', 'application_reupload_requests', ['document_id']);
            dbEnsureIndex($pdo, 'idx_documents_application_id', 'documents', ['application_id']);
            dbEnsureIndex($pdo, 'idx_documents_user_id', 'documents', ['user_id']);
        });
    }
}

if (!function_exists('dbEnsureMessagingIndexes')) {
    function dbEnsureMessagingIndexes(PDO $pdo): void
    {
        dbEnsureIndexBatchOnce($pdo, 'schema.indexes.messaging.v1', function () use ($pdo) {
            dbEnsureIndex($pdo, 'idx_conversations_student_updated_at', 'conversations', ['student_user_id', 'updated_at DESC', 'id DESC']);
            dbEnsureIndex($pdo, 'idx_messages_conversation_created_at', 'messages', ['conversation_id', 'created_at DESC', 'id DESC']);
            dbEnsureIndex($pdo, 'idx_messages_conversation_read_sender', 'messages', ['conversation_id', 'is_read', 'sender_id', 'id DESC']);
        });
    }
}

if (!function_exists('dbEnsureFormsIndexes')) {
    function dbEnsureFormsIndexes(PDO $pdo): void
    {
        dbEnsureIndexBatchOnce($pdo, 'schema.indexes.forms.v1', function () use ($pdo) {
            dbEnsureIndex($pdo, 'idx_forms_scholarship_id', 'forms', ['scholarship_id']);
            dbEnsureIndex($pdo, 'idx_form_fields_scholarship_id', 'form_fields', ['scholarship_id']);
            dbEnsureIndex($pdo, 'idx_form_fields_form_id', 'form_fields', ['form_id']);
            dbEnsureIndex($pdo, 'idx_application_responses_application_id', 'application_responses', ['application_id']);
        });
    }
}

if (!function_exists('dbEnsureExamIndexes')) {
    function dbEnsureExamIndexes(PDO $pdo): void
    {
        dbEnsureIndexBatchOnce($pdo, 'schema.indexes.exam.v1', function () use ($pdo) {
            dbEnsureIndex($pdo, 'idx_exam_questions_scholarship_id', 'exam_questions', ['scholarship_id']);
            dbEnsureIndex($pdo, 'idx_exam_submissions_student_id', 'exam_submissions', ['student_id']);
            dbEnsureIndex($pdo, 'idx_exam_submissions_scholarship_id', 'exam_submissions', ['scholarship_id']);
            dbEnsureIndex($pdo, 'idx_exam_answers_submission_id', 'exam_answers', ['submission_id']);
        });
    }
}

if (!function_exists('dbAddColumnIfMissing')) {
    function dbAddColumnIfMissing(PDO $pdo, string $tableName, string $columnName, string $mysqlDefinition, ?string $pgsqlDefinition = null): void
    {
        if (dbColumnExists($pdo, $tableName, $columnName)) {
            return;
        }

        $tableSql = dbQuoteIdentifier($tableName);
        $columnSql = dbQuoteIdentifier($columnName);
        $definition = dbIsPgsql($pdo) ? ($pgsqlDefinition ?? $mysqlDefinition) : $mysqlDefinition;
        $pdo->exec("ALTER TABLE {$tableSql} ADD COLUMN {$columnSql} {$definition}");
    }
}

if (!function_exists('dbExecuteInsert')) {
    function dbExecuteInsert(PDO $pdo, string $sql, array $params = [], string $returningColumn = 'id'): string
    {
        $sql = rtrim(trim($sql), ';');

        if (dbIsPgsql($pdo)) {
            $sql .= ' RETURNING ' . dbQuoteIdentifier($returningColumn);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (string) $stmt->fetchColumn();
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (string) $pdo->lastInsertId();
    }
}

if (!function_exists('dbCurrentDateSql')) {
    function dbCurrentDateSql(PDO $pdo): string
    {
        return dbIsPgsql($pdo) ? 'CURRENT_DATE' : 'CURDATE()';
    }
}

if (!function_exists('dbDateDaysAgoSql')) {
    function dbDateDaysAgoSql(PDO $pdo, int $days): string
    {
        $days = max(0, $days);
        return dbIsPgsql($pdo)
            ? "CURRENT_DATE - INTERVAL '{$days} day'"
            : "CURDATE() - INTERVAL {$days} DAY";
    }
}

if (!function_exists('dbTimestampDaysAgoSql')) {
    function dbTimestampDaysAgoSql(PDO $pdo, int $days): string
    {
        $days = max(0, $days);
        return dbIsPgsql($pdo)
            ? "CURRENT_TIMESTAMP - INTERVAL '{$days} day'"
            : "NOW() - INTERVAL {$days} DAY";
    }
}

if (!function_exists('dbEnsureApplicationsSchema')) {
    function dbEnsureApplicationsSchema(PDO $pdo): void
    {
        dbAddColumnIfMissing($pdo, 'applications', 'year_program', 'VARCHAR(255) NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'applications', 'program', 'VARCHAR(100) NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'applications', 'year_level', 'VARCHAR(50) NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'applications', 'units_enrolled', 'INT NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'applications', 'gwa', 'DECIMAL(5,2) NULL DEFAULT NULL', 'NUMERIC(5,2) NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'applications', 'remarks', 'TEXT DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'applications', 'student_status', 'VARCHAR(50) NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'applications', 'scholarship_percentage', 'DECIMAL(5,2) NULL DEFAULT NULL', 'NUMERIC(5,2) NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'applications', 'scholarship_amount', 'DECIMAL(10,2) NULL DEFAULT NULL', 'NUMERIC(10,2) NULL DEFAULT NULL');
        dbEnsureApplicationsIndexes($pdo);
    }
}

if (!function_exists('dbEnsureDocumentReviewSchema')) {
    function dbEnsureDocumentReviewSchema(PDO $pdo): void
    {
        dbAddColumnIfMissing($pdo, 'documents', 'file_name', 'VARCHAR(255) DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'documents', 'uploaded_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

        if (!dbTableExists($pdo, 'application_reupload_requests')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS application_reupload_requests (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED,
                        document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE DEFERRABLE INITIALLY DEFERRED,
                        admin_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED,
                        note TEXT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'pending',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        resolved_at TIMESTAMP NULL DEFAULT NULL
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS application_reupload_requests (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        application_id INT NOT NULL,
                        document_id INT NOT NULL,
                        admin_id INT NULL,
                        note TEXT DEFAULT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'pending',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        resolved_at TIMESTAMP NULL DEFAULT NULL,
                        FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
                        FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
                        FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
                    )
                ");
            }
        }

        dbAddColumnIfMissing($pdo, 'application_reupload_requests', 'note', 'TEXT DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'application_reupload_requests', 'status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");
        dbAddColumnIfMissing($pdo, 'application_reupload_requests', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        dbAddColumnIfMissing($pdo, 'application_reupload_requests', 'resolved_at', 'TIMESTAMP NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'application_reupload_requests', 'admin_id', 'INT NULL', 'INTEGER NULL');
        dbEnsureDocumentReviewIndexes($pdo);
    }
}

if (!function_exists('dbEnsureFormsSchema')) {
    function dbEnsureFormsSchema(PDO $pdo): void
    {
        if (!dbTableExists($pdo, 'forms')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS forms (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        scholarship_id INTEGER NOT NULL REFERENCES scholarships(id) ON DELETE CASCADE,
                        title VARCHAR(255),
                        description TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS forms (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        scholarship_id INT NOT NULL,
                        title VARCHAR(255),
                        description TEXT DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (scholarship_id) REFERENCES scholarships(id) ON DELETE CASCADE
                    )
                ");
            }
        }

        if (!dbTableExists($pdo, 'form_fields')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS form_fields (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        scholarship_id INTEGER NULL REFERENCES scholarships(id) ON DELETE CASCADE,
                        form_id INTEGER NULL REFERENCES forms(id) ON DELETE CASCADE,
                        field_label VARCHAR(255),
                        field_name VARCHAR(255),
                        field_type VARCHAR(50) NOT NULL DEFAULT 'text',
                        field_options TEXT,
                        is_required SMALLINT NOT NULL DEFAULT 1,
                        field_order INTEGER NOT NULL DEFAULT 0,
                        options TEXT
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS form_fields (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        scholarship_id INT NULL,
                        form_id INT NULL,
                        field_label VARCHAR(255) NULL,
                        field_name VARCHAR(255) NULL,
                        field_type VARCHAR(50) NOT NULL DEFAULT 'text',
                        field_options TEXT DEFAULT NULL,
                        is_required TINYINT(1) NOT NULL DEFAULT 1,
                        field_order INT NOT NULL DEFAULT 0,
                        options TEXT DEFAULT NULL,
                        FOREIGN KEY (scholarship_id) REFERENCES scholarships(id) ON DELETE CASCADE,
                        FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
                    )
                ");
            }
        }

        if (!dbTableExists($pdo, 'application_responses')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS application_responses (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
                        form_field_id INTEGER NOT NULL REFERENCES form_fields(id) ON DELETE CASCADE,
                        response_value TEXT
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS application_responses (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        application_id INT NOT NULL,
                        form_field_id INT NOT NULL,
                        response_value TEXT,
                        FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
                        FOREIGN KEY (form_field_id) REFERENCES form_fields(id) ON DELETE CASCADE
                    )
                ");
            }
        }

        dbAddColumnIfMissing($pdo, 'forms', 'description', 'TEXT DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'forms', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

        dbAddColumnIfMissing($pdo, 'form_fields', 'form_id', 'INT NULL', 'INTEGER NULL');
        dbAddColumnIfMissing($pdo, 'form_fields', 'scholarship_id', 'INT NULL', 'INTEGER NULL');
        dbAddColumnIfMissing($pdo, 'form_fields', 'field_order', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
        dbAddColumnIfMissing($pdo, 'form_fields', 'field_name', 'VARCHAR(255) NULL');
        dbAddColumnIfMissing($pdo, 'form_fields', 'field_label', 'VARCHAR(255) NULL');
        dbAddColumnIfMissing($pdo, 'form_fields', 'field_type', "VARCHAR(50) NOT NULL DEFAULT 'text'");
        dbAddColumnIfMissing($pdo, 'form_fields', 'field_options', 'TEXT DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'form_fields', 'options', 'TEXT DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'form_fields', 'is_required', 'TINYINT(1) NOT NULL DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
        dbEnsureFormsIndexes($pdo);
    }
}

if (!function_exists('dbEnsureExamSchema')) {
    function dbEnsureExamSchema(PDO $pdo): void
    {
        if (!dbTableExists($pdo, 'exam_questions')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS exam_questions (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        scholarship_id INTEGER NOT NULL REFERENCES scholarships(id) ON DELETE CASCADE,
                        question_text TEXT NOT NULL,
                        question_type VARCHAR(50) NOT NULL,
                        options TEXT,
                        correct_answer TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS exam_questions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        scholarship_id INT NOT NULL,
                        question_text TEXT NOT NULL,
                        question_type VARCHAR(50) NOT NULL,
                        options TEXT,
                        correct_answer TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (scholarship_id) REFERENCES scholarships(id) ON DELETE CASCADE
                    )
                ");
            }
        }

        if (!dbTableExists($pdo, 'exam_submissions')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS exam_submissions (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        student_id INTEGER NOT NULL,
                        scholarship_id INTEGER NOT NULL REFERENCES scholarships(id) ON DELETE CASCADE,
                        score INTEGER DEFAULT 0,
                        total_items INTEGER DEFAULT 0,
                        status VARCHAR(20) DEFAULT 'in_progress',
                        start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        end_time TIMESTAMP NULL
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS exam_submissions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        student_id INT NOT NULL,
                        scholarship_id INT NOT NULL,
                        score INT DEFAULT 0,
                        total_items INT DEFAULT 0,
                        status VARCHAR(20) DEFAULT 'in_progress',
                        start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        end_time TIMESTAMP NULL,
                        FOREIGN KEY (scholarship_id) REFERENCES scholarships(id) ON DELETE CASCADE
                    )
                ");
            }
        }

        if (!dbTableExists($pdo, 'exam_answers')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS exam_answers (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        submission_id INTEGER NOT NULL REFERENCES exam_submissions(id) ON DELETE CASCADE,
                        question_id INTEGER NOT NULL REFERENCES exam_questions(id) ON DELETE CASCADE,
                        student_answer TEXT,
                        is_correct SMALLINT DEFAULT 0
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS exam_answers (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        submission_id INT NOT NULL,
                        question_id INT NOT NULL,
                        student_answer TEXT,
                        is_correct TINYINT(1) DEFAULT 0,
                        FOREIGN KEY (submission_id) REFERENCES exam_submissions(id) ON DELETE CASCADE,
                        FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
                    )
                ");
            }
        }

        dbAddColumnIfMissing($pdo, 'scholarships', 'requires_exam', 'TINYINT(1) NOT NULL DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
        dbAddColumnIfMissing($pdo, 'scholarships', 'passing_score', 'INT DEFAULT 75');
        dbAddColumnIfMissing($pdo, 'scholarships', 'exam_duration', 'INT DEFAULT 60');
        dbEnsureExamIndexes($pdo);
    }
}

if (!function_exists('dbEnsureScholarshipColumns')) {
    function dbEnsureScholarshipColumns(PDO $pdo): void
    {
        dbAddColumnIfMissing($pdo, 'scholarships', 'requires_exam', 'TINYINT(1) NOT NULL DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
        dbAddColumnIfMissing($pdo, 'scholarships', 'passing_score', 'INT DEFAULT 75');
        dbAddColumnIfMissing($pdo, 'scholarships', 'exam_duration', 'INT DEFAULT 60');
        dbAddColumnIfMissing($pdo, 'scholarships', 'end_of_term', 'DATE NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'scholarships', 'amount_type', "VARCHAR(20) NOT NULL DEFAULT 'Peso'");
        dbAddColumnIfMissing($pdo, 'users', 'profile_picture_path', 'VARCHAR(255) NULL DEFAULT NULL');
    }
}

if (!function_exists('dbEnsureUserStudentSyncSchema')) {
    function dbEnsureUserStudentSyncSchema(PDO $pdo): void
    {
        dbAddColumnIfMissing($pdo, 'users', 'contact_number', 'VARCHAR(50) NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'users', 'birthdate', 'DATE NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'users', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');

        dbAddColumnIfMissing($pdo, 'students', 'email', 'VARCHAR(255) NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'students', 'phone', 'VARCHAR(50) NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'students', 'date_of_birth', 'DATE NULL DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'students', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        dbEnsureStudentIndexes($pdo);
    }
}

if (!function_exists('dbEnsureNotificationsTable')) {
    function dbEnsureNotificationsTable(PDO $pdo): void
    {
        if (!dbTableExists($pdo, 'notifications')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS notifications (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        student_id INTEGER NULL REFERENCES students(id) ON DELETE CASCADE,
                        user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                        title VARCHAR(255) DEFAULT 'System Notification',
                        message TEXT,
                        is_read SMALLINT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        student_id INT NULL,
                        user_id INT NULL,
                        title VARCHAR(255) DEFAULT 'System Notification',
                        message TEXT,
                        is_read TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                    )
                ");
            }
        }

        dbAddColumnIfMissing($pdo, 'notifications', 'student_id', 'INT NULL', 'INTEGER NULL');
        dbAddColumnIfMissing($pdo, 'notifications', 'user_id', 'INT NULL', 'INTEGER NULL');
        dbAddColumnIfMissing($pdo, 'notifications', 'title', "VARCHAR(255) DEFAULT 'System Notification'");
        dbAddColumnIfMissing($pdo, 'notifications', 'is_read', 'TINYINT(1) DEFAULT 0', 'SMALLINT DEFAULT 0');

        if (dbColumnExists($pdo, 'notifications', 'student_id') && dbColumnExists($pdo, 'notifications', 'user_id')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    UPDATE notifications n
                    SET student_id = s.id
                    FROM students s
                    WHERE n.student_id IS NULL
                    AND n.user_id IS NOT NULL
                    AND s.user_id = n.user_id
                ");
            } else {
                $pdo->exec("
                    UPDATE notifications n
                    JOIN students s ON s.user_id = n.user_id
                    SET n.student_id = s.id
                    WHERE n.student_id IS NULL
                    AND n.user_id IS NOT NULL
                ");
            }
        }
    }
}

if (!function_exists('dbEnsureAnnouncementsSchema')) {
    function dbEnsureAnnouncementsSchema(PDO $pdo): void
    {
        if (!dbTableExists($pdo, 'announcements')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS announcements (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        title VARCHAR(255) NOT NULL,
                        content TEXT NOT NULL,
                        is_active SMALLINT NOT NULL DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        image_path VARCHAR(255) NULL
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS announcements (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        title VARCHAR(255) NOT NULL,
                        content TEXT NOT NULL,
                        is_active TINYINT(1) NOT NULL DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        image_path VARCHAR(255) DEFAULT NULL
                    )
                ");
            }
        }

        dbAddColumnIfMissing($pdo, 'announcements', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
        dbAddColumnIfMissing($pdo, 'announcements', 'image_path', 'VARCHAR(255) DEFAULT NULL');

        if (!dbTableExists($pdo, 'announcement_attachments')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS announcement_attachments (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        announcement_id INTEGER NOT NULL REFERENCES announcements(id) ON DELETE CASCADE,
                        file_path VARCHAR(255) NOT NULL,
                        file_name VARCHAR(255) NULL
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS announcement_attachments (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        announcement_id INT NOT NULL,
                        file_path VARCHAR(255) NOT NULL,
                        file_name VARCHAR(255) DEFAULT NULL,
                        FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE
                    )
                ");
            }
        }

        dbAddColumnIfMissing($pdo, 'announcement_attachments', 'file_name', 'VARCHAR(255) DEFAULT NULL');
    }
}

if (!function_exists('dbEnsureMessagingSchema')) {
    function dbEnsureMessagingSchema(PDO $pdo): void
    {
        if (!dbTableExists($pdo, 'conversations')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS conversations (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        student_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        subject VARCHAR(255) NOT NULL,
                        status VARCHAR(30) NOT NULL DEFAULT 'open',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS conversations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        student_user_id INT NOT NULL,
                        subject VARCHAR(255) NOT NULL,
                        status VARCHAR(30) NOT NULL DEFAULT 'open',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE
                    )
                ");
            }
        }

        if (!dbTableExists($pdo, 'messages')) {
            if (dbIsPgsql($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS messages (
                        id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                        conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
                        sender_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                        message_text TEXT,
                        attachment_path VARCHAR(255) NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        is_read SMALLINT NOT NULL DEFAULT 0
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS messages (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        conversation_id INT NOT NULL,
                        sender_id INT NOT NULL,
                        message_text TEXT DEFAULT NULL,
                        attachment_path VARCHAR(255) DEFAULT NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        is_read TINYINT(1) NOT NULL DEFAULT 0,
                        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
                    )
                ");
            }
        }

        dbAddColumnIfMissing($pdo, 'conversations', 'status', "VARCHAR(30) NOT NULL DEFAULT 'open'");
        dbAddColumnIfMissing($pdo, 'conversations', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
        dbAddColumnIfMissing($pdo, 'messages', 'attachment_path', 'VARCHAR(255) DEFAULT NULL');
        dbAddColumnIfMissing($pdo, 'messages', 'is_read', 'TINYINT(1) NOT NULL DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
        dbEnsureMessagingIndexes($pdo);
    }
}

if (!function_exists('dbEnsureDynamicSubmissionTable')) {
    function dbEnsureDynamicSubmissionTable(PDO $pdo, string $tableName): void
    {
        $tableSql = dbQuoteIdentifier($tableName);

        if (dbIsPgsql($pdo)) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$tableSql} (
                    id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                    application_id INTEGER NOT NULL,
                    student_id INTEGER NOT NULL,
                    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$tableName} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    application_id INT NOT NULL,
                    student_id INT NOT NULL,
                    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        }
    }
}
