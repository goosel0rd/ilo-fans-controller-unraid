#!/usr/bin/env php
<?php
/**
 * Fan Control Daemon
 * Uses CPU temps from iLO + real drive temps from Unraid GraphQL API
 */

require 'config.inc.php';

define('CONFIG_FILE', __DIR__ . '/auto-control.json');
define('PID_FILE', __DIR__ . '/fan-daemon.pid');

if (file_exists(PID_FILE)) {
    $pid = (int) file_get_contents(PID_FILE);
    if (posix_kill($pid, 0)) {
        echo "Daemon already running with PID $pid\n";
        exit(1);
    }
}

file_put_contents(PID_FILE, getmypid());

register_shutdown_function(function () {
    if (file_exists(PID_FILE)) {
        unlink(PID_FILE);
    }
});

pcntl_signal(SIGTERM, function () {
    echo "Received SIGTERM, shutting down...\n";
    exit(0);
});
pcntl_signal(SIGINT, function () {
    echo "Received SIGINT, shutting down...\n";
    exit(0);
});

function get_config()
{
    if (!file_exists(CONFIG_FILE)) {
        return null;
    }
    return json_decode(file_get_contents(CONFIG_FILE), true);
}

function get_ilo_temperatures()
{
    global $ILO_HOST, $ILO_USERNAME, $ILO_PASSWORD;

    $curl_handle = curl_init("https://$ILO_HOST/redfish/v1/chassis/1/Thermal");
    curl_setopt($curl_handle, CURLOPT_USERPWD, "$ILO_USERNAME:$ILO_PASSWORD");
    curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl_handle, CURLOPT_TIMEOUT, 10);

    $raw_ilo_data = curl_exec($curl_handle);

    if (!$raw_ilo_data) {
        return null;
    }

    $data = json_decode($raw_ilo_data, true);
    $cpuTemps = [];
    $ambientTemp = null;
    $fanCount = 0;

    if (isset($data['Temperatures'])) {
        foreach ($data['Temperatures'] as $temp) {
            $name = strtolower($temp['Name'] ?? '');
            $reading = $temp['ReadingCelsius'] ?? null;
            $status = $temp['Status']['State'] ?? 'Unknown';

            if ($reading !== null && $status === 'Enabled') {
                if (strpos($name, 'cpu') !== false) {
                    $cpuTemps[] = $reading;
                }
                if (strpos($name, 'inlet') !== false || strpos($name, 'ambient') !== false) {
                    $ambientTemp = $reading;
                }
            }
        }
    }

    if (isset($data['Fans'])) {
        foreach ($data['Fans'] as $fan) {
            $status = $fan['Status']['State'] ?? 'Unknown';
            if ($status === 'Enabled') {
                $fanCount++;
            }
        }
    }

    return ['cpu' => $cpuTemps, 'ambient' => $ambientTemp, 'fanCount' => $fanCount];
}

function get_unraid_disk_temperatures()
{
    $unraidHost = getenv('UNRAID_HOST') ?: '192.168.1.75';
    $apiKey     = getenv('UNRAID_API_KEY') ?: '';

    if (empty($apiKey)) {
        echo "  [WARN] UNRAID_API_KEY not set, skipping disk temps\n";
        return [];
    }

    $query = '{"query": "{ array { disks { name temp status } caches { name temp status } } }"}';

    $curl = curl_init("https://$unraidHost/graphql");
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $query);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "x-api-key: $apiKey"
    ]);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($curl);
    curl_close($curl);

    if (!$response) {
        echo "  [WARN] Could not reach Unraid GraphQL API\n";
        return [];
    }

    $data = json_decode($response, true);
    $temps = [];

    $devices = array_merge(
        $data['data']['array']['disks'] ?? [],
        $data['data']['array']['caches'] ?? []
    );

    foreach ($devices as $device) {
        if (isset($device['temp']) && $device['temp'] !== null) {
            $temps[$device['name']] = $device['temp'];
        }
    }

    return $temps;
}

function calculate_fan_speed($temps, $profile)
{
    if (empty($temps)) {
        return $profile['maxSpeed'];
    }

    $maxTemp     = max($temps);
    $targetTemp  = $profile['targetTemp'];
    $criticalTemp = $profile['maxTemp'];
    $minSpeed    = $profile['minSpeed'];
    $maxSpeed    = $profile['maxSpeed'];

    if ($maxTemp <= $targetTemp) {
        return $minSpeed;
    } elseif ($maxTemp >= $criticalTemp) {
        return $maxSpeed;
    } else {
        $ratio = ($maxTemp - $targetTemp) / ($criticalTemp - $targetTemp);
        return (int) round($minSpeed + ($maxSpeed - $minSpeed) * $ratio);
    }
}

function set_fan_speed($speed, $fanCount)
{
    global $ILO_HOST, $ILO_USERNAME, $ILO_PASSWORD, $MINIMUM_FAN_SPEED;

    $speed = max($MINIMUM_FAN_SPEED, min(100, $speed));
    $pwm = (int) ceil($speed / 100 * 255);

    try {
        $ssh = ssh2_connect($ILO_HOST, 22);
        if (!$ssh || !ssh2_auth_password($ssh, $ILO_USERNAME, $ILO_PASSWORD)) {
            return false;
        }

        for ($i = 0; $i < $fanCount; $i++) {
            $stream = ssh2_exec($ssh, "fan p $i max $pwm; fan p $i min 255");
            if ($stream) {
                stream_set_blocking($stream, true);
                stream_set_timeout($stream, 2);
                @stream_get_contents($stream);
                fclose($stream);
            }
            usleep(50000);
        }

        return true;
    } catch (Exception $e) {
        echo "  [ERROR] " . $e->getMessage() . "\n";
        return false;
    }
}

echo "=== Fan Control Daemon Started (CPU + Unraid Disk Temps) ===\n";
echo "PID: " . getmypid() . "\n";
echo "Config file: " . CONFIG_FILE . "\n\n";

$lastSpeed = null;

while (true) {
    pcntl_signal_dispatch();

    $config = get_config();

    if (!$config) {
        echo "[WARN] Config file not found, waiting...\n";
        sleep(10);
        continue;
    }

    if (!$config['enabled']) {
        if ($lastSpeed !== null) {
            echo "[INFO] Auto-control disabled\n";
            $lastSpeed = null;
        }
        sleep($config['checkInterval'] ?? 30);
        continue;
    }

    $profileName = $config['profile'] ?? 'normal';
    $profile = $config['profiles'][$profileName] ?? $config['profiles']['normal'];

    // Get iLO temps (CPU + ambient + fan count)
    $iloData = get_ilo_temperatures();
    if ($iloData === null) {
        echo "[WARN] Could not fetch iLO temperatures\n";
        sleep($config['checkInterval'] ?? 30);
        continue;
    }

    $cpuTemps    = $iloData['cpu'];
    $ambientTemp = $iloData['ambient'];
    $fanCount    = $iloData['fanCount'] ?: 8;

    // Get Unraid disk temps
    $diskTemps = get_unraid_disk_temperatures();

    // Safety: Force Normal profile if ambient > 40°C
    if ($ambientTemp !== null && $ambientTemp > 40 && $profileName === 'silence') {
        echo "[" . date('H:i:s') . "] SAFETY: Ambient {$ambientTemp}°C > 40°C, forcing Normal profile\n";
        $profile = $config['profiles']['normal'];
        $profileName = 'normal (forced)';
    } else {
        echo "[" . date('H:i:s') . "] Profile: {$profile['label']}";
        if ($ambientTemp !== null) echo " | Ambient: {$ambientTemp}°C";
        echo " | Fans: {$fanCount}\n";
    }

    $maxCpu  = !empty($cpuTemps) ? max($cpuTemps) : 0;
    $maxDisk = !empty($diskTemps) ? max($diskTemps) : 0;

    echo "  CPU: {$maxCpu}°C";
    if (!empty($diskTemps)) {
        $diskSummary = implode(', ', array_map(fn($n, $t) => "$n:{$t}°C", array_keys($diskTemps), $diskTemps));
        echo " | Disks: $diskSummary";
    }
    echo "\n";

    // Combine all temps for fan speed calculation
    $allTemps = array_merge($cpuTemps, array_values($diskTemps));

    $speed = calculate_fan_speed($allTemps, $profile);
    echo "  Calculated speed: {$speed}%\n";

    $speedDiff = abs($speed - ($lastSpeed ?? 0));
    if ($lastSpeed === null || $speedDiff > 3) {
        echo "  Applying new fan speed (diff: {$speedDiff}%)...\n";
        if (set_fan_speed($speed, $fanCount)) {
            echo "  [OK] Fans set to {$speed}%\n";
            $lastSpeed = $speed;
        }
    } else {
        echo "  No change (diff: {$speedDiff}% < 3%)\n";
    }

    sleep($config['checkInterval'] ?? 30);
}