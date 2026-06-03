<?php
function getPersonalizedBody($pdo, $message_body, $contact_id) {
    $stmt = $pdo->prepare("
        SELECT cf.field_label, cfv.field_value 
        FROM contact_field_values cfv
        JOIN custom_fields cf ON cfv.field_id = cf.id
        WHERE cfv.contact_id = ?
    ");
    $stmt->execute([$contact_id]);
    $custom_values = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $replace_pairs = [];
    foreach ($custom_values as $val) {
        $slug = '{{' . strtolower(str_replace(' ', '_', trim($val['field_label']))) . '}}';
        $replace_pairs[$slug] = $val['field_value'];
    }

    return str_replace(array_keys($replace_pairs), array_values($replace_pairs), $message_body);
}
?>