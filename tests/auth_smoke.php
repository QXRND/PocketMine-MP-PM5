<?php
declare(strict_types=1);

require __DIR__ . '/../src/auth/AuthDatabase.php';

use pocketmine\auth\AuthDatabase;

$path = tempnam(sys_get_temp_dir(), 'pmmp-auth-');
$db = new AuthDatabase($path);
if($db->hasAccount('Admin')){
    throw new RuntimeException('account unexpectedly exists');
}
if(!$db->createAccount('Admin', 'secret123')){
    throw new RuntimeException('account creation failed');
}
if(!$db->hasAccount('admin')){
    throw new RuntimeException('case-insensitive lookup failed');
}
if(!$db->verifyPassword('ADMIN', 'secret123') || $db->verifyPassword('admin', 'wrong-password')){
    throw new RuntimeException('password verification failed');
}
if(!$db->changePassword('admin', 'secret123', 'newsecret123')){
    throw new RuntimeException('password change failed');
}
if(!$db->verifyPassword('admin', 'newsecret123')){
    throw new RuntimeException('changed password verification failed');
}
if(!$db->deleteAccount('ADMIN') || $db->hasAccount('admin')){
    throw new RuntimeException('account deletion failed');
}
unlink($path);
echo "auth smoke test passed\n";
