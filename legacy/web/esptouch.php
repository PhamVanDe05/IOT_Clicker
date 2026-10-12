<?php
declare(strict_types=1);

// ESPTouch v1 packet lengths, matching Espressif's public protocol implementation.
function esptouch_crc8(string $bytes): int {
    $crc = 0;
    for ($i = 0; $i < strlen($bytes); $i++) {
        $crc ^= ord($bytes[$i]);
        for ($bit = 0; $bit < 8; $bit++) $crc = ($crc & 1) ? (($crc >> 1) ^ 0x8c) : ($crc >> 1);
    }
    return $crc;
}

function esptouch_lengths(string $ssid, string $password, string $bssid, string $serverIp): array {
    $ip = inet_pton($serverIp);
    $mac = hex2bin(str_replace(':', '', $bssid));
    if ($ip === false || strlen($ip) !== 4 || $mac === false || strlen($mac) !== 6) throw new InvalidArgumentException('IP hoặc BSSID không hợp lệ.');
    $header = chr(9 + strlen($password) + strlen($ssid)) . chr(strlen($password))
        . chr(esptouch_crc8($ssid)) . chr(esptouch_crc8($mac));
    $data = $header . chr(0) . $ip . $password . $ssid;
    $xor = 0;
    for ($i = 0; $i < strlen($data); $i++) $xor ^= ord($data[$i]);
    $data[4] = chr($xor);
    $codes = [];
    for ($i = 0; $i < strlen($data); $i++) $codes[] = [$i, ord($data[$i])];
    for ($i = 0; $i < 6; $i++) array_splice($codes, 5 + $i * 4, 0, [[strlen($data) + $i, ord($mac[$i])]]);
    $lengths = [];
    foreach ($codes as [$index, $value]) {
        $crc = esptouch_crc8(chr($value) . chr($index));
        $lengths[] = 40 + ($crc & 0xf0) + (($value & 0xf0) >> 4);
        $lengths[] = 40 + 0x100 + $index;
        $lengths[] = 40 + (($crc & 0x0f) << 4) + ($value & 0x0f);
    }
    return $lengths;
}

function esptouch_send(string $ssid, string $password, string $bssid, string $serverIp): array {
    $lengths = esptouch_lengths($ssid, $password, $bssid, $serverIp);
    $guides = [515, 514, 513, 512];
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($socket === false) throw new RuntimeException('Không tạo được UDP socket.');
    try {
        if (!socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1)
            || !socket_bind($socket, $serverIp, 18266)
            || !socket_set_nonblock($socket)) throw new RuntimeException('Không thể phát UDP từ IP laptop: ' . socket_strerror(socket_last_error($socket)));
        $packets = [];
        foreach (array_unique(array_merge($guides, $lengths)) as $length) $packets[$length] = str_repeat('1', $length);
        $found = [];
        $started = microtime(true);
        $roundAt = $started - 6;
        $guideUntil = 0.0;
        $guideIndex = 0;
        $dataIndex = 0;
        while (microtime(true) - $started < 30) {
            $now = microtime(true);
            if ($now - $roundAt >= 6) { $roundAt = $now; $guideUntil = $now + 2; $guideIndex = 0; $dataIndex = 0; }
            $length = $now < $guideUntil ? $guides[$guideIndex++ % 4] : $lengths[$dataIndex++ % count($lengths)];
            if (socket_sendto($socket, $packets[$length], $length, 0, '255.255.255.255', 7001) !== $length) {
                throw new RuntimeException('Gửi gói SmartConfig thất bại: ' . socket_strerror(socket_last_error($socket)));
            }
            $reply = '';
            while (@socket_recvfrom($socket, $reply, 64, MSG_DONTWAIT, $from, $port) !== false) {
                if (strlen($reply) === 11 && ord($reply[0]) === strlen($ssid) + strlen($password) + 9) {
                    $mac = strtoupper(implode(':', str_split(bin2hex(substr($reply, 1, 6)), 2)));
                    $found[$mac] = inet_ntop(substr($reply, 7, 4));
                }
            }
            usleep(8000);
        }
        return $found;
    } finally {
        socket_close($socket);
    }
}
