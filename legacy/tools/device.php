<?php
declare(strict_types=1);
// Register, rotate or revoke an ESP32/BLE bridge without printing its secret.
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$config = require (getenv('CLICKER_CONFIG') ?: "$root/.local/prod-config.php");
$db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
[$script, $command, $kind, $rawUid] = array_pad($argv, 4, '');
if ($command === 'list') {
    foreach ($db->query('SELECT device_uid,kind,revoked_at FROM device WHERE kind IS NOT NULL ORDER BY device_uid') as $row) {
        echo $row['device_uid'], ' ', $row['kind'], ' ', $row['revoked_at'] ? 'REVOKED' : 'ACTIVE', PHP_EOL;
    }
    exit;
}
$uid = strtoupper($rawUid);
if (!in_array($command, ['register', 'adopt', 'rotate', 'revoke'], true)
    || !in_array($kind, ['esp', 'bridge'], true)
    || ($command === 'adopt' && $kind !== 'esp')
    || !preg_match('/^[A-Z0-9:_-]{3,64}$/D', $uid)
    || ($kind === 'bridge') !== str_starts_with($uid, 'BRIDGE-')) {
    exit("Usage: php tools/device.php list | register|adopt|rotate|revoke esp|bridge UID\n");
}
if ($command === 'revoke') {
    $statement = $db->prepare('UPDATE device SET revoked_at=NOW() WHERE device_uid=? AND kind=? AND revoked_at IS NULL');
    $statement->execute([$uid, $kind]);
    if ($statement->rowCount() !== 1) exit("Device not active: $uid\n");
    echo "REVOKED $uid. Reflash/rotate before reusing it.\n";
    exit;
}
$accessPath = "$root/.local/access.json";
$access = json_decode(file_get_contents($accessPath), true, 512, JSON_THROW_ON_ERROR);
$key = $command === 'adopt' ? ($access['device_key'] ?? '') : bin2hex(random_bytes(32));
if (!is_string($key) || strlen($key) < 32) exit("Missing legacy device key.\n");
$hash = hash('sha256', $key);
// A board or tag may already have a device row from calling the server; only a kind means it holds a key.
$old = $db->prepare('SELECT kind FROM device WHERE device_uid=?');
$old->execute([$uid]);
$registered = $old->fetchColumn() ?: null;
if ($command === 'rotate' ? $registered !== $kind : $registered !== null) {
    exit("Use register/adopt for a new UID, rotate for an existing UID.\n");
}
$db->beginTransaction();
try {
    if ($command === 'rotate') {
        $statement = $db->prepare('UPDATE device SET key_hash=?,revoked_at=NULL WHERE device_uid=? AND kind=?');
        $statement->execute([$hash, $uid, $kind]);
    } else {
        $statement = $db->prepare('INSERT INTO device(device_uid,kind,key_hash,registered_at) VALUES(?,?,?,NOW())
            ON DUPLICATE KEY UPDATE kind=VALUES(kind),key_hash=VALUES(key_hash),revoked_at=NULL,registered_at=VALUES(registered_at)');
        $statement->execute([$uid, $kind, $hash]);
    }
    if ($command !== 'adopt') {
        if ($kind === 'bridge') {
            $access['bridge_key'] = $key;
            $target = $accessPath;
            $contents = json_encode($access, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        } else {
            $target = "$root/firmware/SmartClickerESP32/device_key.h";
            $contents = "#pragma once\n#define DEVICE_KEY \"$key\"\n";
        }
        $temp = $target . '.tmp';
        if (file_put_contents($temp, $contents, LOCK_EX) === false || !chmod($temp, 0600) || !rename($temp, $target)) {
            throw new RuntimeException("Cannot save credential to $target");
        }
    }
    $db->commit();
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
echo strtoupper($command), " $uid. ", $command === 'adopt' ? 'Existing ESP32 firmware can stay as-is.'
    : ($kind === 'esp' ? 'Upload the ESP32 sketch.' : 'Restart the BLE bridge.'), PHP_EOL;
