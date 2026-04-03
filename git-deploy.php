<?php
// ============================================================
// git-deploy.php — GitHub Webhook Auto-Deploy for Moodle
// Repo: https://github.com/Zakiapps/courses.git
// Branch: master
// ============================================================

ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(300);

// ============================================================
// CONFIGURATION
// ============================================================

// Set these as environment variables on your server for security,
// or edit the fallback values below.
$webhook_secret = getenv('WEBHOOK_SECRET') ?: 'CHANGE_ME_WEBHOOK_SECRET';
$deploy_token   = getenv('DEPLOY_TOKEN')   ?: 'CHANGE_ME_DEPLOY_TOKEN';

$config = [
    'repo_path'    => __DIR__,
    'branch'       => 'master',
    'remote_name'  => 'origin',
    'github_repo'  => 'Zakiapps/courses',

    // Log files
    'log_file'     => __DIR__ . '/logs/deploy.log',
    'error_log'    => __DIR__ . '/logs/deploy-errors.log',

    // Security
    'webhook_secret'    => $webhook_secret,
    'deploy_token'      => $deploy_token,
    'require_signature' => true,
    'allowed_ips'       => [], // GitHub IPs — left open; signature protects you

    // Maintenance mode flag file
    'maintenance_file' => __DIR__ . '/.maintenance',

    // Post-deployment tasks (Moodle-specific defaults)
    'post_deploy' => [
        'fix_permissions'  => true,
        'clear_opcache'    => true,
        'clear_moodle_cache' => true,  // purge Moodle's cache/ directory
        'run_migrations'   => false,   // Moodle handles upgrades via admin UI
        'composer_install' => false,   // Moodle bundles its own vendor
        'npm_build'        => false,
    ],

    // Notifications (all disabled by default — configure as needed)
    'notifications' => [
        'email' => [
            'enabled' => false,
            'to'      => 'admin@example.com',
            'from'    => 'deploy@example.com',
        ],
        'slack' => [
            'enabled' => false,
            'webhook' => 'https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK',
            'channel' => '#deployments',
        ],
        'telegram' => [
            'enabled'   => false,
            'bot_token' => 'YOUR_BOT_TOKEN',
            'chat_id'   => 'YOUR_CHAT_ID',
        ],
    ],
];

// Ensure log directory exists
if (!is_dir(dirname($config['log_file']))) {
    @mkdir(dirname($config['log_file']), 0755, true);
}

// ============================================================
// LOGGING
// ============================================================

function logMsg($message, $type = 'INFO')
{
    global $config;
    $entry = '[' . date('Y-m-d H:i:s') . "] [{$type}] {$message}" . PHP_EOL;
    @file_put_contents($config['log_file'], $entry, FILE_APPEND | LOCK_EX);
}

function logError($message)
{
    global $config;
    $entry = '[' . date('Y-m-d H:i:s') . "] [ERROR] {$message}" . PHP_EOL;
    @file_put_contents($config['error_log'], $entry, FILE_APPEND | LOCK_EX);
    logMsg($message, 'ERROR');
}

// ============================================================
// COMMAND EXECUTION
// ============================================================

function safeExec($cmd, $description = '')
{
    logMsg("Running [{$description}]: {$cmd}");

    if (function_exists('exec') && !isDisabled('exec')) {
        $output = [];
        $code   = -1;
        exec($cmd . ' 2>&1', $output, $code);
        $out = implode("\n", $output);
        logMsg("[{$description}] exit:{$code} — " . substr($out, 0, 500));
        return ['output' => $out, 'code' => $code, 'success' => $code === 0];
    }

    if (function_exists('shell_exec') && !isDisabled('shell_exec')) {
        $out = trim((string) shell_exec($cmd . ' 2>&1'));
        logMsg("[{$description}] output: " . substr($out, 0, 500));
        return ['output' => $out, 'code' => 0, 'success' => strlen($out) > 0];
    }

    if (function_exists('proc_open') && !isDisabled('proc_open')) {
        $desc    = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $desc, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            $out  = trim($stdout . "\n" . $stderr);
            logMsg("[{$description}] exit:{$code} — " . substr($out, 0, 500));
            return ['output' => $out, 'code' => $code, 'success' => $code === 0];
        }
    }

    logError("[{$description}] No execution function available");
    return ['output' => 'No execution function available', 'code' => -1, 'success' => false];
}

function isDisabled($fn)
{
    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    return in_array($fn, $disabled);
}

// ============================================================
// SECURITY
// ============================================================

function verifySignature($payload, $signature, $secret)
{
    if (empty($secret))   return true;
    if (empty($signature)) return false;
    $hash = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    return hash_equals($hash, $signature);
}

function ipInRange($ip, $range)
{
    if (strpos($range, '/') === false) return $ip === $range;
    [$base, $mask] = explode('/', $range, 2);
    $baseDecimal   = ip2long($base);
    $ipDecimal     = ip2long($ip);
    $wildcardDec   = pow(2, (32 - (int)$mask)) - 1;
    $netmaskDec    = ~$wildcardDec;
    return ($ipDecimal & $netmaskDec) == ($baseDecimal & $netmaskDec);
}

function isAllowedIP($ip, $ranges)
{
    if (empty($ranges)) return true;
    foreach ($ranges as $range) {
        if (ipInRange($ip, $range)) return true;
    }
    return false;
}

// ============================================================
// MAINTENANCE MODE
// ============================================================

function enableMaintenanceMode()
{
    global $config;
    @file_put_contents($config['maintenance_file'], date('Y-m-d H:i:s'));
    logMsg('Maintenance mode ON');
}

function disableMaintenanceMode()
{
    global $config;
    @unlink($config['maintenance_file']);
    logMsg('Maintenance mode OFF');
}

// ============================================================
// POST-DEPLOYMENT TASKS
// ============================================================

function runPostDeployTasks(&$details)
{
    global $config;
    $tasks = [];

    if ($config['post_deploy']['fix_permissions']) {
        $tasks[] = fixPermissions();
    }

    if ($config['post_deploy']['clear_opcache']) {
        $tasks[] = clearOPcache();
    }

    if ($config['post_deploy']['clear_moodle_cache']) {
        $tasks[] = clearMoodleCache();
    }

    if ($config['post_deploy']['run_migrations']) {
        $tasks[] = runMigrations();
    }

    if ($config['post_deploy']['composer_install']) {
        $tasks[] = runComposerInstall();
    }

    if ($config['post_deploy']['npm_build']) {
        $tasks[] = runNpmBuild();
    }

    $details['tasks'] = $tasks;

    foreach ($tasks as $task) {
        if (!$task['success']) return false;
    }
    return true;
}

function fixPermissions()
{
    global $config;
    $path = escapeshellarg($config['repo_path']);
    $r1 = safeExec("find {$path} -type d -exec chmod 755 {} \\;", 'Dir permissions');
    $r2 = safeExec("find {$path} -type f -exec chmod 644 {} \\;", 'File permissions');
    // Keep the deploy script executable
    $r3 = safeExec("chmod +x " . escapeshellarg($config['repo_path'] . '/git-deploy.php'), 'Deploy script +x');
    return [
        'name'    => 'fix_permissions',
        'success' => $r1['success'] && $r2['success'],
        'output'  => $r1['output'] . "\n" . $r2['output'],
    ];
}

function clearOPcache()
{
    if (function_exists('opcache_reset')) {
        $ok = @opcache_reset();
        logMsg('OPcache reset: ' . ($ok ? 'OK' : 'FAILED'));
        return ['name' => 'clear_opcache', 'success' => $ok, 'output' => $ok ? 'OPcache cleared' : 'OPcache reset failed'];
    }
    $r = safeExec('php -r "opcache_reset();"', 'OPcache via CLI');
    return ['name' => 'clear_opcache', 'success' => $r['success'], 'output' => $r['output']];
}

/**
 * Clear Moodle's file-based cache directories.
 * NOTE: For a full purge (including DB caches) you should run
 *   php admin/cli/purge_caches.php
 * on the server after deploy.
 */
function clearMoodleCache()
{
    global $config;
    $path     = $config['repo_path'];
    $cleared  = [];

    // Moodle cache directories (relative to $CFG->dataroot, but we clear what we can here)
    $cacheDirs = ['cache', 'temp', 'localcache'];
    foreach ($cacheDirs as $dir) {
        $full = $path . '/' . $dir;
        if (is_dir($full)) {
            safeExec("find " . escapeshellarg($full) . " -mindepth 1 -delete", "Clear {$dir}");
            $cleared[] = $dir;
        }
    }

    // Try Moodle CLI purge (works only if PHP CLI can access config.php)
    $cliResult = safeExec("cd " . escapeshellarg($path) . " && php admin/cli/purge_caches.php", 'Moodle purge_caches CLI');

    return [
        'name'    => 'clear_moodle_cache',
        'success' => true,
        'output'  => 'Cleared dirs: ' . implode(', ', $cleared) . "\n" . $cliResult['output'],
    ];
}

function runMigrations()
{
    global $config;
    $path = $config['repo_path'];
    // Moodle upgrades via CLI
    $r = safeExec("cd " . escapeshellarg($path) . " && php admin/cli/upgrade.php --non-interactive", 'Moodle upgrade CLI');
    return ['name' => 'run_migrations', 'success' => $r['success'], 'output' => $r['output']];
}

function runComposerInstall()
{
    global $config;
    $path = escapeshellarg($config['repo_path']);
    if (!file_exists($config['repo_path'] . '/composer.json')) {
        return ['name' => 'composer_install', 'success' => true, 'output' => 'No composer.json — skipped'];
    }
    $r = safeExec("cd {$path} && composer install --no-dev --optimize-autoloader 2>&1", 'Composer install');
    return ['name' => 'composer_install', 'success' => $r['success'], 'output' => $r['output']];
}

function runNpmBuild()
{
    global $config;
    $path = escapeshellarg($config['repo_path']);
    if (!file_exists($config['repo_path'] . '/package.json')) {
        return ['name' => 'npm_build', 'success' => true, 'output' => 'No package.json — skipped'];
    }
    $r = safeExec("cd {$path} && npm ci && npm run build 2>&1", 'NPM build');
    return ['name' => 'npm_build', 'success' => $r['success'], 'output' => $r['output']];
}

// ============================================================
// NOTIFICATIONS
// ============================================================

function sendNotifications($status, $details)
{
    global $config;
    if ($config['notifications']['email']['enabled'])    sendEmail($status, $details);
    if ($config['notifications']['slack']['enabled'])    sendSlack($status, $details);
    if ($config['notifications']['telegram']['enabled']) sendTelegram($status, $details);
}

function sendEmail($status, $details)
{
    global $config;
    $cfg     = $config['notifications']['email'];
    $subject = "[Courses] Deploy " . strtoupper($status) . " — {$details['commit']}";
    $body    = "Status: {$status}\nCommit: {$details['commit']}\nBranch: {$details['branch']}\nBy: {$details['pusher']}\nDuration: {$details['duration']}s\n\n{$details['output']}";
    @mail($cfg['to'], $subject, $body, "From: {$cfg['from']}\r\n");
}

function sendSlack($status, $details)
{
    global $config;
    $cfg     = $config['notifications']['slack'];
    $color   = $status === 'success' ? '#36a64f' : '#ff0000';
    $payload = [
        'channel'  => $cfg['channel'],
        'username' => 'Courses Deploy',
        'attachments' => [[
            'color'  => $color,
            'title'  => "Deploy {$status}",
            'fields' => [
                ['title' => 'Commit',   'value' => $details['commit'],             'short' => true],
                ['title' => 'Branch',   'value' => $details['branch'],             'short' => true],
                ['title' => 'Duration', 'value' => $details['duration'] . 's',     'short' => true],
                ['title' => 'Output',   'value' => substr($details['output'], 0, 500), 'short' => false],
            ],
            'footer' => 'Auto-Deploy',
            'ts'     => time(),
        ]],
    ];
    $ch = curl_init($cfg['webhook']);
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT       => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function sendTelegram($status, $details)
{
    global $config;
    $cfg  = $config['notifications']['telegram'];
    $icon = $status === 'success' ? '✅' : '❌';
    $msg  = "{$icon} <b>Deploy {$status}</b>\n<b>Commit:</b> {$details['commit']}\n<b>Branch:</b> {$details['branch']}\n<b>By:</b> {$details['pusher']}\n<b>Duration:</b> {$details['duration']}s";
    $ch   = curl_init("https://api.telegram.org/bot{$cfg['bot_token']}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => http_build_query(['chat_id' => $cfg['chat_id'], 'text' => $msg, 'parse_mode' => 'HTML']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT       => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ============================================================
// HEALTH CHECK
// ============================================================

function runHealthCheck()
{
    global $config;
    $path   = $config['repo_path'];
    $checks = [];

    // Critical Moodle files
    foreach (['index.php', 'config.php', 'version.php'] as $file) {
        $checks['files'][$file] = file_exists($path . '/' . $file);
    }

    // Disk space
    $free = disk_free_space($path);
    $checks['disk'] = [
        'free_gb' => round($free / 1073741824, 2),
        'healthy' => $free > 104857600, // 100 MB minimum
    ];

    return $checks;
}

// ============================================================
// REQUEST HANDLING
// ============================================================

header('Content-Type: application/json');

$method        = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
$payload       = file_get_contents('php://input');
$signature     = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$clientIP      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$manualToken   = $_GET['token'] ?? '';
$isManual      = ($manualToken !== '' && $manualToken === $config['deploy_token']);

// ---- GET: health check or manual deploy ----
if ($method === 'GET') {
    if ($isManual) {
        logMsg("=== MANUAL DEPLOY from {$clientIP} ===");
        goto do_deploy;
    }
    echo json_encode([
        'status'    => 'active',
        'repo'      => $config['github_repo'],
        'branch'    => $config['branch'],
        'health'    => runHealthCheck(),
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoints' => [
            'webhook' => 'POST /git-deploy.php  (GitHub webhook)',
            'manual'  => 'GET  /git-deploy.php?token=YOUR_DEPLOY_TOKEN',
        ],
    ], JSON_PRETTY_PRINT);
    exit;
}

// ---- Non-POST, non-manual ----
if ($method !== 'POST' && !$isManual) {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// ---- IP check (informational — signature is the real guard) ----
if (!isAllowedIP($clientIP, $config['allowed_ips'])) {
    logMsg("Note: request from unlisted IP {$clientIP}");
}

logMsg("=== WEBHOOK RECEIVED from {$clientIP} ===");

// ---- Signature verification ----
if (!$isManual && $config['require_signature'] && !verifySignature($payload, $signature, $config['webhook_secret'])) {
    logError('Invalid signature');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
    exit;
}

// ---- Parse JSON ----
$data = json_decode($payload, true);
if (!$isManual && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// ---- Ping event ----
if (isset($data['zen'])) {
    logMsg("GitHub ping: {$data['zen']}");
    echo json_encode(['status' => 'ok', 'message' => 'Pong — webhook connected!']);
    exit;
}

// ---- Branch check ----
if (!$isManual) {
    $ref         = $data['ref'] ?? '';
    $expectedRef = 'refs/heads/' . $config['branch'];
    if ($ref !== $expectedRef) {
        logMsg("Skipping: push to {$ref} (watching {$expectedRef})");
        echo json_encode(['status' => 'skipped', 'reason' => 'Not the watched branch']);
        exit;
    }
}

$pusher = $data['pusher']['name'] ?? ($isManual ? 'manual' : 'unknown');
logMsg("Triggered by: {$pusher}");

// ---- Acknowledge immediately (prevents GitHub timeout) ----
ignore_user_abort(true);
ob_end_clean();
header('Connection: close');
ob_start();
echo json_encode(['status' => 'processing', 'message' => 'Deploying in background…', 'pusher' => $pusher]);
$size = ob_get_length();
header("Content-Length: {$size}");
ob_end_flush();
@ob_flush();
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ============================================================
// DEPLOY
// ============================================================

do_deploy:

$startTime = microtime(true);
$repoPath  = $config['repo_path'];
$branch    = $config['branch'];
$remote    = $config['remote_name'];

enableMaintenanceMode();

$details = [
    'commit'    => 'unknown',
    'branch'    => $branch,
    'pusher'    => $pusher ?? 'manual',
    'timestamp' => date('Y-m-d H:i:s'),
    'duration'  => 0,
    'output'    => '',
    'tasks'     => [],
];

try {
    $escapedPath = escapeshellarg($repoPath);

    logMsg("Repo path : {$repoPath}");
    logMsg('.git exists: ' . (is_dir($repoPath . '/.git') ? 'YES' : 'NO'));

    if (!is_dir($repoPath . '/.git')) {
        throw new Exception('No .git directory found at ' . $repoPath);
    }

    // Confirm git is available
    $gitCheck = safeExec('git --version', 'Git version check');
    if (!$gitCheck['success']) {
        throw new Exception('git binary not found in PATH');
    }
    logMsg('Git: ' . $gitCheck['output']);

    // Mark directory as safe (shared hosting)
    safeExec("git config --global safe.directory {$escapedPath}", 'Safe directory');
    safeExec("git config --global safe.directory '*'", 'Safe directory (wildcard)');

    // Pull via HTTPS (no credentials needed for public repo)
    // For private repo: set GITHUB_TOKEN env var on server, then swap URL below.
    $repoUrl = "https://github.com/{$config['github_repo']}.git";

    // Update remote URL (supports private repos via token)
    $ghToken = getenv('GITHUB_TOKEN');
    if ($ghToken) {
        $repoUrl = "https://oauth2:{$ghToken}@github.com/{$config['github_repo']}.git";
    }
    safeExec("cd {$escapedPath} && git remote set-url {$remote} " . escapeshellarg($repoUrl), 'Set remote URL');

    // Fetch
    $fetch = safeExec("cd {$escapedPath} && git fetch {$remote} {$branch}", 'git fetch');
    if (!$fetch['success']) {
        throw new Exception('git fetch failed: ' . $fetch['output']);
    }

    // Hard reset to remote (discards local changes, matches remote exactly)
    $reset = safeExec("cd {$escapedPath} && git reset --hard {$remote}/{$branch}", 'git reset --hard');
    if (!$reset['success']) {
        throw new Exception('git reset failed: ' . $reset['output']);
    }

    $details['output'] = $reset['output'];

    // Commit hash
    $hashResult        = safeExec("cd {$escapedPath} && git rev-parse --short HEAD", 'Get HEAD hash');
    $hash              = trim($hashResult['output']);
    $details['commit'] = $hash;
    logMsg("Deployed commit: {$hash}");

    // Post-deploy
    logMsg('Running post-deploy tasks…');
    $ok = runPostDeployTasks($details);
    if (!$ok) logError('Some post-deploy tasks failed (see above)');

    // Health
    $health = runHealthCheck();

    disableMaintenanceMode();

    $details['duration'] = round(microtime(true) - $startTime, 2);
    $status = $ok ? 'success' : 'partial';
    sendNotifications($status, $details);

    logMsg("=== DEPLOY {$status}: {$hash} in {$details['duration']}s ===");

    echo json_encode([
        'status'    => $status,
        'commit'    => $hash,
        'duration'  => $details['duration'],
        'health'    => $health,
        'tasks'     => $details['tasks'],
        'timestamp' => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    logError('Deploy FAILED: ' . $e->getMessage());
    disableMaintenanceMode();

    $details['duration'] = round(microtime(true) - $startTime, 2);
    $details['output']   = $e->getMessage();
    sendNotifications('failed', $details);

    http_response_code(500);
    echo json_encode([
        'status'    => 'error',
        'message'   => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
}
?>
