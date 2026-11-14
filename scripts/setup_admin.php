<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

echo "========================================================\n";
echo "   DOODHDESK SAAS - SUPER ADMIN PROVISIONING            \n";
echo "========================================================\n\n";

$options = getopt('', ['name::', 'email::', 'password::']);

$name = $options['name'] ?? null;
$email = $options['email'] ?? null;
$password = $options['password'] ?? null;

// If arguments missing, prompt interactively
if (empty($name)) {
    echo "Enter Administrator Name [Default: Platform Administrator]: ";
    $line = trim((string)fgets(STDIN));
    $name = !empty($line) ? $line : 'Platform Administrator';
}

if (empty($email)) {
    echo "Enter Administrator Email: ";
    $email = trim((string)fgets(STDIN));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "[\033[31mERROR\033[0m] Invalid email address: '{$email}'\n";
    exit(1);
}

if (empty($password)) {
    echo "Enter Administrator Password (min 8 characters): ";
    $password = trim((string)fgets(STDIN));
}

if (strlen($password) < 8) {
    echo "[\033[31mERROR\033[0m] Password must be at least 8 characters long.\n";
    exit(1);
}

try {
    $db = Database::getInstance();
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $existing = $db->fetchOne("SELECT id FROM super_admins WHERE email = ?", [$email]);
    if ($existing) {
        $db->execute("UPDATE super_admins SET name = ?, password_hash = ? WHERE email = ?", [$name, $hash, $email]);
        echo "\n[\033[32mSUCCESS\033[0m] Existing super admin account updated for: {$email}\n";
    } else {
        $db->execute("INSERT INTO super_admins (name, email, password_hash) VALUES (?, ?, ?)", [$name, $email, $hash]);
        echo "\n[\033[32mSUCCESS\033[0m] Super admin account created for: {$email}\n";
    }

    echo "You can now log in at your domain under: /admin/login\n";
} catch (\Throwable $e) {
    echo "\n[\033[31mERROR\033[0m] Failed to provision administrator: " . $e->getMessage() . "\n";
    exit(1);
}
