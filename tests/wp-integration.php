<?php
/** End-to-end plugin behavior in disposable WordPress. */
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = '127.0.0.1:' . getenv('DIGICYFR_TEST_PORT');
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = getenv('DIGICYFR_TEST_PORT');
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.42';
$_SERVER['HTTPS'] = 'off';
require '/var/www/html/wp-load.php';

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

global $wpdb;
$table = Digicyfr_Service_Quote_Desk_PL::table();
$admin = get_user_by('login', 'digicyfr_test_admin');
check($admin instanceof WP_User, 'Test administrator missing');
wp_set_current_user($admin->ID);
$phase = $argv[1] ?? '';

if ($phase === 'base') {
    check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, 'Request table missing');
    $html = do_shortcode('[digicyfr_service_quote]');
    check(str_contains($html, 'name="details"') && str_contains($html, 'name="digicyfr_quote_nonce"'), 'Public form missing fields');
    $html2 = do_shortcode('[digicyfr_service_quote]');
    preg_match('/id="([^"]+)" name="service"/', $html, $first_id);
    preg_match('/id="([^"]+)" name="service"/', $html2, $second_id);
    check(isset($first_id[1], $second_id[1]) && $first_id[1] !== $second_id[1], 'Multiple forms have duplicate IDs');
    $catalog = WP_PLUGIN_DIR . '/digicyfr-service-quote-desk-pl/languages/digicyfr-service-quote-desk-pl-pl_PL.mo';
    check(load_textdomain('digicyfr-service-quote-desk-pl', $catalog), 'Polish MO catalog failed to load');
    $translated = __('Quote Desk', 'digicyfr-service-quote-desk-pl');
    check($translated === 'Skrzynka wycen', 'Polish translation missing');
    $valid = [
        'digicyfr_quote_nonce' => wp_create_nonce('digicyfr_quote_submit'),
        'service' => 'Repair', 'location' => 'Warszawa', 'details' => 'Repair a door',
        'customer_name' => 'Test Customer', 'email' => 'client@example.invalid',
        'phone' => '', 'preferred_time' => 'Tomorrow afternoon',
        'source_url' => 'https://attacker.example/private', 'website' => '',
    ];
    check(is_wp_error(Digicyfr_Service_Quote_Desk_PL::process_submission(array_merge($valid, ['email' => '']))), 'Missing contact accepted');
    check(is_wp_error(Digicyfr_Service_Quote_Desk_PL::process_submission(array_merge($valid, ['website' => 'spam']))), 'Honeypot ignored');
    check(is_wp_error(Digicyfr_Service_Quote_Desk_PL::process_submission(array_merge($valid, ['digicyfr_quote_nonce' => 'bad']))), 'Bad nonce accepted');
    $id = Digicyfr_Service_Quote_Desk_PL::process_submission($valid);
    check(is_int($id) && $id > 0, 'Valid request failed');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    check($row->status === 'new' && $row->details === 'Repair a door', 'Stored request invalid');
    check($row->source_url === home_url('/'), 'External source URL was stored');
    check(in_array($row->notification_status, ['accepted', 'failed'], true), 'Notification state not saved');
    update_option('digicyfr_quote_test_id', $id);
    echo "Base request saved: #$id\n";
} elseif ($phase === 'update') {
    $id = (int) get_option('digicyfr_quote_test_id');
    $_POST = [
        'request_id' => (string) $id, 'status' => 'quote_sent',
        'assignee_id' => (string) $admin->ID, 'follow_up_date' => '2026-11-30',
        'internal_note' => 'Follow up with customer',
        '_wpnonce' => wp_create_nonce('digicyfr_quote_update_' . $id),
    ];
    $_REQUEST = $_POST;
    Digicyfr_Service_Quote_Desk_PL::handle_update();
} elseif ($phase === 'verify') {
    $id = (int) get_option('digicyfr_quote_test_id');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    check($row->status === 'quote_sent' && $row->follow_up_date === '2026-11-30', 'Update failed');
    check($row->quote_sent_at_gmt !== null && $row->assignee_id == $admin->ID, 'Workflow timestamp or assignment missing');
    $export = Digicyfr_Service_Quote_Desk_PL::export_personal_data('client@example.invalid');
    check(count($export['data']) === 1 && $export['data'][0]['item_id'] === 'quote-' . $id, 'Privacy export failed');
    $bad = Digicyfr_Service_Quote_Desk_PL::process_submission([
        'digicyfr_quote_nonce' => wp_create_nonce('digicyfr_quote_submit'),
        'service' => 'Test', 'location' => 'Łódź', 'details' => 'Test',
        'customer_name' => 'A', 'email' => 'bad-email', 'phone' => '',
    ]);
    check(is_wp_error($bad), 'Invalid email accepted');
    $old = Digicyfr_Service_Quote_Desk_PL::process_submission([
        'digicyfr_quote_nonce' => wp_create_nonce('digicyfr_quote_submit'),
        'service' => 'Test', 'location' => 'Łódź', 'details' => 'Old request',
        'customer_name' => 'Old Customer', 'email' => 'old@example.invalid', 'phone' => '',
    ]);
    check(is_int($old), 'Second valid request failed');
    $wpdb->update($table, ['created_at_gmt' => gmdate('Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS)], ['id' => $old]);
    Digicyfr_Service_Quote_Desk_PL::purge_expired();
    check($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $old)) === null, 'Retention did not purge old request');
    check($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $id)) !== null, 'Retention deleted current request');
    $phone_only = Digicyfr_Service_Quote_Desk_PL::process_submission([
        'digicyfr_quote_nonce' => wp_create_nonce('digicyfr_quote_submit'),
        'service' => 'Cleaning', 'location' => 'Poznań', 'details' => 'Clean a shop',
        'customer_name' => 'Phone Customer', 'email' => '', 'phone' => '+48 600 000 000',
    ]);
    check(is_int($phone_only), 'Phone-only request failed');
    update_option('digicyfr_quote_test_phone_id', $phone_only);
    $erased = Digicyfr_Service_Quote_Desk_PL::erase_personal_data('client@example.invalid');
    check($erased['items_removed'] && !$erased['items_retained'], 'Privacy eraser failed');
    check($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $id)) === null, 'Email request not erased');
    echo "Workflow, translation, privacy export/erase and retention verified\n";
} elseif ($phase === 'delete') {
    $id = (int) get_option('digicyfr_quote_test_phone_id');
    $_POST = ['request_id' => (string) $id, 'confirm_delete' => '1', '_wpnonce' => wp_create_nonce('digicyfr_quote_delete_' . $id)];
    $_REQUEST = $_POST;
    Digicyfr_Service_Quote_Desk_PL::handle_delete();
} elseif ($phase === 'verify-delete') {
    $id = (int) get_option('digicyfr_quote_test_phone_id');
    check($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $id)) === null, 'Manual deletion failed');
    echo "Manual deletion verified\n";
} else {
    throw new RuntimeException('Unknown phase');
}
