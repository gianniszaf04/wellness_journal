<?php
declare(strict_types=1);

// κοινο ξεκινημα για το web (public/index.php) και τα CLI scripts (bin/)
define('BASE_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require_once BASE_PATH .'App/Core/Env.php';

\App\Core\Env::load(BASE_PATH . '.env');

$appDebug = filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN);
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('display_startup_errors', $appDebug ? '1' : '0');
error_reporting(E_ALL);
// μια ζωνη ωρας για PHP και MySQL (το database.php παιρνει το offset απο εδω), αλλιως οι μερες κοβονται σε αλλη ωρα
$timezone = (string) getenv('APP_TIMEZONE');
date_default_timezone_set(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Europe/Athens');
// τα δεκαδικα στο JSON να βγαινουν οπως τα γραφουμε (-0.42 και οχι -0.41999999...)
ini_set('serialize_precision', '-1');
define('APP_URL',rtrim((string) getenv('APP_URL'), '/'));
require_once BASE_PATH . 'App/Core/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $base_dir = BASE_PATH . 'App' . DIRECTORY_SEPARATOR;

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $file = $base_dir
        . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)))
        . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

require_once BASE_PATH . 'Config/database.php';
