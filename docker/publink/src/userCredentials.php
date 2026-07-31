<?php

/**
 * script to update super user record with 
 * user input usernmame and password
 */


require './vendor/autoload.php';

use Biblhertz\Publink\pages\Bibliotheca_Content_Page;
use Biblhertz\Publink\om\User;


$page = new Bibliotheca_Content_Page(1);
$db   = $page->getObjDB();

// Seed the two default user groups if they don't already exist.
// INSERT IGNORE is idempotent — safe on a fresh install or after a DB reset.
$db->preparedStatement("INSERT IGNORE INTO user_group (id, name) VALUES (?, ?)", [1,  'User']);
$db->preparedStatement("INSERT IGNORE INTO user_group (id, name) VALUES (?, ?)", [20, 'Super User']);

// Ensure the super-user placeholder row exists so User::__construct() can load it.
$db->preparedStatement(
    "INSERT IGNORE INTO user_details (id, name, user_group_id, `current`, login_type, first_name, last_name, email, token)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [1, 'admin', 20, 't', 'local', 'Publink', 'Admin', '', '']
);

$user = new User($db, 1);

$credfile = "./src/credentials.txt";

// Usage
try {
    $creds = readCredentials($credfile);
    echo "Setting Username and Password in DB : " . $creds['username'] . PHP_EOL;
    // Never echo the password in production!
    $user->updateCredentials($creds);
} catch (RuntimeException $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// Assign all tasks to the admin user
$tasks = $db->preparedSelect("SELECT id FROM task", []);
while ($row = $tasks->fetch(PDO::FETCH_ASSOC)) {
    $db->preparedStatement(
        "INSERT IGNORE INTO user_details_task (user_details_id, task_id) VALUES (?, ?)",
        [1, $row['id']]
    );
}
echo "All tasks assigned to admin user." . PHP_EOL;



function readCredentials(string $filepath = 'credentials.txt'): array
{
    if (!file_exists($filepath)) {
        throw new RuntimeException("Credentials file not found: $filepath");
    }

    $perms = fileperms($filepath) & 0777;
    if ($perms & 0044) {
        trigger_error("Warning: credentials file has loose permissions", E_USER_WARNING);
    }

    $credentials = [];
    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$key, $value] = explode('=', $line, 2);
        $credentials[trim($key)] = trim($value);
    }

    $username = $credentials['USERNAME'] ?? null;
    $password = $credentials['PASSWORD'] ?? null;
    $passwordRepeat = $credentials['PASSWORD_REPEAT'] ?? null;

    if (!$username || !$password || !$passwordRepeat) {
        throw new RuntimeException("Missing USERNAME, PASSWORD or PASSWORD_REPEAT in credentials file");
    }

    if ($password !== $passwordRepeat) {
        throw new RuntimeException("Passwords do not match");
    }

    return compact('username', 'password');
}




?>