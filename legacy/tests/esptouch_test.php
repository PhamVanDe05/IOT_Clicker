<?php
declare(strict_types=1);
require __DIR__ . '/../web/esptouch.php';

function check(bool $condition): void { if (!$condition) throw new RuntimeException('ESPTouch packet check failed.'); }

check(esptouch_crc8('123456789') === 0xA1);
$lengths = esptouch_lengths('TestNet', '12345678', 'AA:BB:CC:DD:EE:FF', '192.168.43.2');
check(count($lengths) === 90);
$indexes = [];
for ($i = 1; $i < count($lengths); $i += 3) $indexes[] = $lengths[$i] - 296;
check(array_slice($indexes, 0, 10) === [0, 1, 2, 3, 4, 24, 5, 6, 7, 25]);
check(max($lengths) <= 551);
echo "ESPTouch packets OK\n";
