<?php
/**
 * Real WP-CLI smoke test on disposable CI only.
 * This is not a full runtime or release acceptance test.
 */
declare(strict_types=1);

if (!defined('WP_CLI') || !WP_CLI || getenv('HTD_CI_DISPOSABLE') !== '1' ||
    !in_array(parse_url(home_url(), PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Refusing to run outside isolated CI.');
}
function htd_ci_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    WP_CLI::log('PASS: ' . $message);
}

global $wpdb;
$plugin = \HTD\AISEO\Core\Plugin::instance();
$c = $plugin->container();
$s = $plugin->settings();
htd_ci_assert(PHP_VERSION_ID >= 80100, 'Supported PHP runtime: ' . PHP_VERSION);
htd_ci_assert($wpdb->prefix === 'htdci_', 'Custom DB prefix');
htd_ci_assert(defined('WC_VERSION') && class_exists('WooCommerce'), 'WooCommerce loaded');
htd_ci_assert(defined('RANK_MATH_VERSION'), 'Rank Math loaded');
$schema = \HTD\AISEO\Database\Schema::class;
$tables = array_merge($schema::v020Tables($wpdb), $schema::v040Tables($wpdb), $schema::v050Tables($wpdb), $schema::v060Tables($wpdb));
htd_ci_assert(count($tables) === 23, 'Expected 23 schema tables (update contract explicitly if schema changes)');
foreach (array_keys($tables) as $name) {
    $table = $schema::table($wpdb, $name);
    htd_ci_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table, 'Real MySQL table ' . $table);
}
htd_ci_assert($plugin->migrator()->currentVersion() === HTD_AI_SEO_DB_VERSION, 'Schema version matches plugin');
htd_ci_assert($plugin->migrator()->migrate(), 'Idempotent real database migration');
foreach (['wp_read_only_mode' => true, 'allow_autopublish' => false,
    'allow_product_update' => false, 'allow_external_ai_requests' => false,
    'allow_background_content_generation' => false, 'allow_remote_update' => false,
    'allow_external_telemetry' => false, 'require_human_approval' => true] as $key => $expected) {
    htd_ci_assert($s->get($key) === $expected, 'Safe default ' . $key);
}
$guard = $c->get(\HTD\AISEO\Security\MutationGuard::class);
$blocked = false;
try {
    $guard->assertCanMutate('autopublish', 'post', 0, [], null);
} catch (RuntimeException $e) {
    $blocked = $e->getCode() === 403;
}
htd_ci_assert($blocked, 'Read-only mode refuses publishing');
$queue = $c->get(\HTD\AISEO\Queue\QueueEngine::class);
$worker = $c->get(\HTD\AISEO\Queue\WorkerEngine::class);
htd_ci_assert(in_array('HTD_SEO_AUDIT', $worker->registeredJobTypes(), true), 'Production container audit handler registered');
$id = $queue->enqueue('KEYWORD_ANALYSIS', ['keyword' => 'HTD CI synthetic keyword'], 'ci-runtime-' . wp_generate_uuid4());
htd_ci_assert($id > 0, 'Real queue insert');
$job = $queue->claimNext('ci-worker', 120);
htd_ci_assert(is_array($job) && (int) $job['id'] === $id, 'Real queue claim');
htd_ci_assert($queue->complete($id, 'ci-worker'), 'Real queue complete');
$id2 = $queue->enqueue('KEYWORD_ANALYSIS', ['keyword' => 'HTD CI expired lease'], 'ci-expired-' . wp_generate_uuid4());
$job = $queue->claimNext('ci-expired', 120);
htd_ci_assert(is_array($job) && (int) $job['id'] === $id2, 'Second real queue claim');
$table = $schema::table($wpdb, 'queue');
htd_ci_assert($wpdb->update($table, ['lease_expires_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $id2]) === 1, 'Expire synthetic lease');
htd_ci_assert(!$queue->heartbeat($id2, 'ci-expired'), 'Expired lease cannot heartbeat');
$blocked = false;
try {
    $blocked = !$queue->complete($id2, 'ci-expired');
} catch (RuntimeException $e) {
    $blocked = true;
}
htd_ci_assert($blocked, 'Expired lease cannot complete');
htd_ci_assert($queue->recoverExpiredLeases() >= 1, 'Real expired lease recovery');
htd_ci_assert($wpdb->last_error === '', 'No database error at end of smoke test');
WP_CLI::success('Runtime smoke checks completed; full E2E, multi-process race tests, live APIs and QA15 still required.');
