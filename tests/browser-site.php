<?php
/** Create a public quote page on the disposable test site. */
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = '127.0.0.1:' . getenv('DIGICYFR_TEST_PORT');
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = getenv('DIGICYFR_TEST_PORT');
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = 'off';
require '/var/www/html/wp-load.php';

$id = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'Request a quote',
    'post_content' => '[digicyfr_service_quote]',
]);
if (is_wp_error($id) || !$id) {
    throw new RuntimeException('Could not create quote page');
}
update_option('show_on_front', 'page');
update_option('page_on_front', $id);
echo "Quote page ready: $id\n";
