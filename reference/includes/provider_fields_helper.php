<?php
/**
 * provider_fields_helper.php
 * جلب وعرض الحقول الديناميكية لمزود معين
 */

/**
 * جلب حقول مزود مفعّلة مرتبة
 */
function getProviderFields(PDO $pdo, int $providerId): array {
    try {
        $st = $pdo->prepare("SELECT * FROM provider_fields WHERE provider_id=? AND is_enabled=1 ORDER BY sort_order,id");
        $st->execute([$providerId]);
        return $st->fetchAll();
    } catch(Exception $e) {
        return [];
    }
}

/**
 * تحويل نص الخيارات إلى مصفوفة [{label,value}]
 */
function parseFieldOptions(string $raw): array {
    $opts = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!$line) continue;
        if (str_contains($line, '|')) {
            [$l,$v] = explode('|', $line, 2);
            $opts[] = ['label'=>trim($l), 'value'=>trim($v)];
        } else {
            $opts[] = ['label'=>$line, 'value'=>$line];
        }
    }
    return $opts;
}

/**
 * بناء HTML لحقل واحد (للعرض في واجهة العميل)
 */
function renderProviderField(array $field, string $prefix='pfield'): string {
    $key   = htmlspecialchars($field['field_key']);
    $label = htmlspecialchars($field['field_label']);
    $plch  = htmlspecialchars($field['placeholder'] ?? '');
    $req   = $field['is_required'] ? 'required' : '';
    $reqStar = $field['is_required'] ? ' <span style="color:#ef4444">*</span>' : '';
    $name  = $prefix . '[' . $field['field_key'] . ']';
    $id    = $prefix . '_' . $field['field_key'];

    $html  = '<div class="pf-group" style="margin-bottom:14px">';
    $html .= "<label for=\"{$id}\" style=\"display:block;font-size:.8rem;font-weight:700;color:var(--text2);margin-bottom:6px\">{$label}{$reqStar}</label>";

    switch ($field['field_type']) {
        case 'select':
            $opts = parseFieldOptions($field['field_options'] ?? '');
            $html .= "<select name=\"{$name}\" id=\"{$id}\" {$req} style=\"width:100%;background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;color:var(--text);font-family:var(--font);font-size:.9rem;outline:none\">";
            $html .= "<option value=\"\">— اختر —</option>";
            foreach ($opts as $o) {
                $html .= "<option value=\"".htmlspecialchars($o['value'])."\">".htmlspecialchars($o['label'])."</option>";
            }
            $html .= "</select>";
            break;
        case 'textarea':
            $html .= "<textarea name=\"{$name}\" id=\"{$id}\" {$req} placeholder=\"{$plch}\" rows=\"3\" style=\"width:100%;background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;color:var(--text);font-family:var(--font);font-size:.9rem;outline:none;resize:vertical\"></textarea>";
            break;
        case 'checkbox':
            $html .= "<label style=\"display:flex;align-items:center;gap:8px;cursor:pointer\">";
            $html .= "<input type=\"checkbox\" name=\"{$name}\" id=\"{$id}\" value=\"1\" {$req} style=\"width:18px;height:18px\">";
            $html .= "<span style=\"font-size:.85rem\">{$plch}</span></label>";
            break;
        default:
            $type = $field['field_type'] === 'number' ? 'number' : 'text';
            $html .= "<input type=\"{$type}\" name=\"{$name}\" id=\"{$id}\" {$req} placeholder=\"{$plch}\" style=\"width:100%;background:var(--card2);border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;color:var(--text);font-family:var(--font);font-size:.9rem;outline:none\">";
    }
    $html .= '</div>';
    return $html;
}

/**
 * استخراج قيم الحقول من POST وإرجاعها كـ JSON string
 */
function extractProviderFieldValues(array $post, string $prefix='pfield'): string {
    $vals = $post[$prefix] ?? [];
    return json_encode($vals, JSON_UNESCAPED_UNICODE);
}
?>
