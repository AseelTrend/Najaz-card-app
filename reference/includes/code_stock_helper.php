<?php
/**
 * نظام إدارة مخزون الأكواد الرقمية — عام لأي خدمة
 * ─────────────────────────────────────────────────
 * يوفر:
 *   - إنشاء الجداول اللازمة (مرة واحدة تلقائياً، بنفس أسلوب recharge_cards.php)
 *   - إضافة أكواد جديدة لخدمة معيّنة (سطر = كود)
 *   - تسليم أول كود متاح لعميل عند الشراء (FIFO) بأمان (Transaction + Lock)
 *   - إحصائيات المخزون لكل خدمة + تنبيه اقتراب النفاد
 *
 * الاستخدام:
 *   require_once __DIR__.'/code_stock_helper.php';
 *   codeStockEnsureTables($pdo);
 */

function codeStockEnsureTables(PDO $pdo): void {
    // جدول الأكواد نفسها
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `code_stock` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `service_id` INT NOT NULL,
        `code` VARCHAR(255) NOT NULL,
        `status` ENUM('available','used','deleted') NOT NULL DEFAULT 'available',
        `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `used_at` TIMESTAMP NULL DEFAULT NULL,
        `order_id` INT DEFAULT NULL,
        `user_id` INT DEFAULT NULL,
        `added_by` INT DEFAULT NULL,
        `deleted_at` TIMESTAMP NULL DEFAULT NULL,
        `deleted_by` INT DEFAULT NULL,
        KEY `idx_service_status` (`service_id`,`status`,`id`),
        KEY `idx_order` (`order_id`),
        KEY `idx_user` (`user_id`),
        KEY `idx_code` (`code`(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

    // إعدادات لكل خدمة: هل مفعّلة كخدمة أكواد + الحد الأدنى للتنبيه
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `code_stock_settings` (
        `service_id` INT NOT NULL PRIMARY KEY,
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `low_stock_threshold` INT NOT NULL DEFAULT 20,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

    // سجل العمليات (Logs)
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `code_stock_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `service_id` INT DEFAULT NULL,
        `code_id` INT DEFAULT NULL,
        `action` VARCHAR(40) NOT NULL,
        `admin_id` INT DEFAULT NULL,
        `details` VARCHAR(500) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_service` (`service_id`), KEY `idx_code` (`code_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
}

function codeStockLog(PDO $pdo, $serviceId, $codeId, $action, $adminId = null, $details = null): void {
    try {
        $pdo->prepare("INSERT INTO code_stock_logs (service_id,code_id,action,admin_id,details) VALUES (?,?,?,?,?)")
            ->execute([$serviceId ?: null, $codeId ?: null, $action, $adminId ?: null, $details]);
    } catch (Exception $e) {}
}

/**
 * هل الخدمة مفعّلة كخدمة "مخزون أكواد"؟
 */
function codeStockIsEnabled(PDO $pdo, int $serviceId): bool {
    $stmt = $pdo->prepare("SELECT enabled FROM code_stock_settings WHERE service_id=?");
    $stmt->execute([$serviceId]);
    $row = $stmt->fetch();
    return $row ? (bool)$row['enabled'] : false;
}

/**
 * تفعيل/تعطيل خدمة كخدمة أكواد، وتحديد حد التنبيه الأدنى
 */
function codeStockSetServiceSettings(PDO $pdo, int $serviceId, bool $enabled, int $threshold): void {
    $pdo->prepare("
        INSERT INTO code_stock_settings (service_id, enabled, low_stock_threshold)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), low_stock_threshold=VALUES(low_stock_threshold)
    ")->execute([$serviceId, $enabled ? 1 : 0, max(0, $threshold)]);
}

/**
 * إضافة أكواد جديدة لخدمة (كل سطر = كود مستقل). يتجاهل الأسطر الفارغة
 * ويمنع تكرار نفس الكود لنفس الخدمة.
 * يرجع: ['added'=>عدد المضاف, 'duplicates'=>عدد المكرر]
 */
function codeStockAddCodes(PDO $pdo, int $serviceId, string $rawText, ?int $adminId = null): array {
    $lines = preg_split('/\r\n|\r|\n/', $rawText);
    $added = 0; $duplicates = 0;

    $checkStmt  = $pdo->prepare("SELECT id FROM code_stock WHERE service_id=? AND code=? AND status!='deleted' LIMIT 1");
    $insertStmt = $pdo->prepare("INSERT INTO code_stock (service_id, code, status, added_by) VALUES (?,?,'available',?)");

    foreach ($lines as $line) {
        $code = trim($line);
        if ($code === '') continue;

        $checkStmt->execute([$serviceId, $code]);
        if ($checkStmt->fetch()) { $duplicates++; continue; }

        $insertStmt->execute([$serviceId, $code, $adminId]);
        $added++;
        codeStockLog($pdo, $serviceId, (int)$pdo->lastInsertId(), 'add', $adminId, 'إضافة كود جديد للمخزون');
    }

    return ['added' => $added, 'duplicates' => $duplicates];
}

/**
 * تسليم أول كود متاح لخدمة معيّنة لعميل/طلب (FIFO)، بأمان ضد التزامن.
 * تُستخدم Transaction + SELECT...FOR UPDATE لمنع تسليم نفس الكود مرتين.
 *
 * يرجع الكود (string) عند النجاح، أو null إذا لا يوجد مخزون متاح.
 */
function codeStockDeliver(PDO $pdo, int $serviceId, int $orderId, int $userId): ?string {
    $alreadyOwnTransaction = $pdo->inTransaction();
    if (!$alreadyOwnTransaction) $pdo->beginTransaction();

    try {
        // قفل أول صف "متاح" لهذه الخدمة لمنع تعارض عمليتين متزامنتين
        $stmt = $pdo->prepare("
            SELECT id, code FROM code_stock
            WHERE service_id=? AND status='available'
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$serviceId]);
        $row = $stmt->fetch();

        if (!$row) {
            if (!$alreadyOwnTransaction) $pdo->commit(); // لا يوجد تغيير فعلي
            return null;
        }

        $pdo->prepare("
            UPDATE code_stock
            SET status='used', used_at=NOW(), order_id=?, user_id=?
            WHERE id=? AND status='available'
        ")->execute([$orderId, $userId, $row['id']]);

        codeStockLog($pdo, $serviceId, $row['id'], 'deliver', null, "تسليم تلقائي للطلب #$orderId");

        if (!$alreadyOwnTransaction) $pdo->commit();
        return $row['code'];

    } catch (Exception $e) {
        if (!$alreadyOwnTransaction && $pdo->inTransaction()) $pdo->rollBack();
        error_log('codeStockDeliver error: ' . $e->getMessage());
        return null;
    }
}

/**
 * جلب الكود المسلَّم لطلب معيّن (لعرضه في صفحة تفاصيل الطلب)
 */
function codeStockGetByOrder(PDO $pdo, int $orderId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM code_stock WHERE order_id=? AND status='used' LIMIT 1");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * إحصائيات المخزون لكل الخدمات المفعّلة كخدمات أكواد
 */
function codeStockGetAllServiceStats(PDO $pdo): array {
    return $pdo->query("
        SELECT s.id AS service_id, s.name AS service_name,
               COALESCE(cs.enabled,0) AS enabled,
               COALESCE(cs.low_stock_threshold,20) AS low_stock_threshold,
               COUNT(c.id) AS total,
               SUM(c.status='available') AS available_count,
               SUM(c.status='used') AS used_count,
               SUM(c.status='deleted') AS deleted_count
        FROM code_stock_settings cs
        JOIN services s ON s.id = cs.service_id
        LEFT JOIN code_stock c ON c.service_id = s.id
        GROUP BY s.id
        ORDER BY s.name ASC
    ")->fetchAll();
}

function codeStockGetServiceStats(PDO $pdo, int $serviceId): array {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(status='available') AS available_count,
            SUM(status='used') AS used_count,
            SUM(status='deleted') AS deleted_count
        FROM code_stock WHERE service_id=?
    ");
    $stmt->execute([$serviceId]);
    $row = $stmt->fetch() ?: ['total'=>0,'available_count'=>0,'used_count'=>0,'deleted_count'=>0];
    foreach (['total','available_count','used_count','deleted_count'] as $k) $row[$k] = (int)($row[$k] ?? 0);
    return $row;
}

/**
 * قائمة الخدمات التي أوشك مخزونها على النفاد (لعرض تنبيه في لوحة الإدارة)
 */
function codeStockLowStockAlerts(PDO $pdo): array {
    return $pdo->query("
        SELECT s.id AS service_id, s.name AS service_name,
               cs.low_stock_threshold,
               SUM(c.status='available') AS available_count
        FROM code_stock_settings cs
        JOIN services s ON s.id = cs.service_id
        LEFT JOIN code_stock c ON c.service_id = s.id
        WHERE cs.enabled = 1
        GROUP BY s.id
        HAVING available_count < cs.low_stock_threshold
        ORDER BY available_count ASC
    ")->fetchAll();
}
