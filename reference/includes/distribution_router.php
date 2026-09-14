<?php
/**
 * distribution_router.php
 * ─────────────────────────────────────────────────────────────────
 * منطق تحديد التوزيع المناسب للعميل عند تنفيذ الطلب
 *
 * الأولوية:
 *  1. توزيع مخصص للعميل مباشرة
 *  2. توزيع مخصص لمجموعة العميل
 *  3. التوزيع الافتراضي في telecom_agent_routes (المزود الافتراضي)
 *
 * ضع هذا الملف في: includes/distribution_router.php
 */

/**
 * جلب سلسلة المزودين (Failover chain) للعميل والخدمة
 *
 * @param PDO    $pdo
 * @param int    $userId
 * @param int    $methodId   معرف الشبكة (telecom) أو service_id (services)
 * @param string $opKey      'amount'|'fees'|'bundles'|'order'
 * @param string $serviceType 'telecom'|'service'
 *
 * @return array{
 *   chain: array,        قائمة المزودين مرتبة بالأولوية
 *   distribution_id: int|null,
 *   distribution_name: string|null,
 *   source: string       'user'|'group'|'default'
 * }
 */
function resolveDistribution(PDO $pdo, int $userId, int $methodId, string $opKey = 'amount', string $serviceType = 'telecom'): array
{
    $empty = ['chain' => [], 'distribution_id' => null, 'distribution_name' => null, 'source' => 'default'];

    try {
        // ── 1. جلب بيانات العميل (group_id) ─────────────────────
        $userStmt = $pdo->prepare("SELECT group_id FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return $empty;

        $groupId = $user['group_id'] ? (int)$user['group_id'] : null;

        // ── 2. بحث عن توزيع خاص بالعميل مباشرة ─────────────────
        $dist = findDistribution($pdo, null, $userId, $methodId, $opKey, $serviceType);
        if ($dist) {
            return array_merge($dist, ['source' => 'user']);
        }

        // ── 3. بحث عن توزيع خاص بمجموعة العميل ─────────────────
        if ($groupId) {
            $dist = findDistribution($pdo, $groupId, null, $methodId, $opKey, $serviceType);
            if ($dist) {
                return array_merge($dist, ['source' => 'group']);
            }
        }

        // ── 4. التوزيع الافتراضي من telecom_agent_routes ─────────
        if ($serviceType === 'telecom') {
            $defaultChain = getDefaultTelecomChain($pdo, $methodId, $opKey);
            if (!empty($defaultChain)) {
                return ['chain' => $defaultChain, 'distribution_id' => null, 'distribution_name' => 'افتراضي', 'source' => 'default'];
            }
        }

        return $empty;

    } catch (Exception $e) {
        return $empty;
    }
}

/**
 * البحث عن توزيع مطابق في جدول agent_distributions
 */
function findDistribution(PDO $pdo, ?int $groupId, ?int $userId, int $methodId, string $opKey, string $serviceType): ?array
{
    // بناء شرط المطابقة
    if ($userId !== null) {
        $targetCondition = "d.target_type = 'user' AND d.target_user_id = ?";
        $params = [$userId];
    } else {
        $targetCondition = "d.target_type = 'group' AND d.target_group_id = ?";
        $params = [$groupId];
    }

    $stmt = $pdo->prepare("
        SELECT d.id, d.name
        FROM agent_distributions d
        WHERE d.status = 1
          AND d.service_type IN (?, 'both')
          AND ($targetCondition)
        LIMIT 1
    ");
    $stmt->execute(array_merge([$serviceType], $params));
    $dist = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dist) return null;

    // جلب سلسلة المزودين لهذا التوزيع + الشبكة + العملية
    $chain = getDistributionChain($pdo, (int)$dist['id'], $methodId, $opKey);
    if (empty($chain)) return null;

    return [
        'chain'             => $chain,
        'distribution_id'   => (int)$dist['id'],
        'distribution_name' => $dist['name'],
    ];
}

/**
 * جلب سلسلة المزودين لتوزيع محدد + شبكة + عملية
 */
function getDistributionChain(PDO $pdo, int $distId, int $methodId, string $opKey): array
{
    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.type, a.config, dr.priority
        FROM agent_distribution_routes dr
        JOIN telecom_agents a ON a.id = dr.agent_id AND a.status = 1
        WHERE dr.distribution_id = ?
          AND dr.method_id = ?
          AND dr.op_key = ?
        ORDER BY dr.priority ASC
    ");
    $stmt->execute([$distId, $methodId, $opKey]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * جلب السلسلة الافتراضية من telecom_agent_routes
 */
function getDefaultTelecomChain(PDO $pdo, int $methodId, string $opKey): array
{
    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.type, a.config, r.priority
        FROM telecom_agent_routes r
        JOIN telecom_agents a ON a.id = r.agent_id AND a.status = 1
        WHERE r.method_id = ? AND r.op_key = ?
        ORDER BY r.priority ASC
    ");
    $stmt->execute([$methodId, $opKey]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * تنفيذ الطلب عبر السلسلة مع Failover
 *
 * @param array    $chain     من resolveDistribution()['chain']
 * @param callable $executor  function(array $agent, array $cfg): array{ok:bool, data:mixed, error:string}
 */
function executeWithFailover(array $chain, callable $executor): array
{
    if (empty($chain)) {
        return ['ok' => false, 'error' => 'لا يوجد مزود متاح', 'tried' => []];
    }

    $tried = [];
    foreach ($chain as $agent) {
        $cfg = json_decode($agent['config'] ?? '{}', true) ?? [];
        try {
            $result = $executor($agent, $cfg);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        $tried[] = [
            'agent_id'   => (int)$agent['id'],
            'agent_name' => $agent['name'],
            'priority'   => (int)$agent['priority'],
            'ok'         => (bool)($result['ok'] ?? false),
            'error'      => $result['error'] ?? null,
        ];

        if (!empty($result['ok'])) {
            return array_merge($result, ['tried' => $tried, 'agent_id' => (int)$agent['id'], 'agent_name' => $agent['name']]);
        }
    }

    $last = end($tried);
    return [
        'ok'    => false,
        'error' => 'فشل جميع المزودين (' . count($tried) . '): ' . ($last['error'] ?? 'خطأ غير معروف'),
        'tried' => $tried,
    ];
}
