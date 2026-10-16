<?php

declare(strict_types=1);

use App\Services\UserService;
use App\Support\Database;

// Creates the initial administrator from ADMIN_EMAIL / ADMIN_PASSWORD.
$email = (string) config('app.admin.email');
$password = (string) config('app.admin.password');

if ($email === '' || $password === '') {
    echo "Skipping admin seed: ADMIN_EMAIL / ADMIN_PASSWORD not set.\n";
    return;
}

if (Database::value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
    echo "Admin $email already exists.\n";
    return;
}

$user = (new UserService())->register($email, $password, 'Administrator');
Database::update('users', ['role' => 'admin'], 'id = ?', [$user['id']]);
echo "Created admin user $email.\n";
