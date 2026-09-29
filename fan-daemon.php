#!/usr/bin/env php
<?php
/**
 * Fan Control Daemon
 * Uses all iLO thermal zones plus Unraid drive temperatures.
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
    $zones = [];
    $ambientTemp = null;
    $fanCount = 0;

    if (isset($data['Temperatures'])) {
        foreach ($data['Temperatures'] as $temp) {
            $name = strtolower($temp['Name'] ?? '');
            $reading = $temp['ReadingCelsius'] ?? null;
            $status = $temp['Status']['State'] ?? 'Enabled';

            if ($reading !== null && !in_array($status, ['Absent', 'Disabled', 'Unavailable'], true)) {
                $zone = classify_temperature_zone($name);
                $zones[$zone][] = (float) $reading;
                if ($zone === 'ambient') {
                    $ambientTemp = max($ambientTemp ?? (float) $reading, (float) $reading);
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

    return ['zones' => $zones, 'ambient' => $ambientTemp, 'fanCount' => $fanCount];
}

function classify_temperature_zone($name)
{
    $name = strtolower($name);
    $patterns = [
        'ambient' => ['inlet', 'exhaust', 'ambient'],
        'cpu' => ['cpu', 'processor'],
        'gpu' => ['gpu', 'graphics', 'accelerator'],
        'fpga' => ['fpga'],
        'memory' => ['dimm', 'memory', 'mem'],
        'vr' => ['vr p1', 'vr p2', 'voltage regulator'],
        'storage' => ['hd', 'storage', 'drive', 'cntlr'],
        'power' => ['p/s', 'psu', 'power'],
        'chipset' => ['chipset', 'ilo'],
        'pci' => ['pci', 'slot'],
    ];
    foreach ($patterns as $zone => $needles) {
        foreach ($needles as $needle) {
            if (strpos($name, $needle) !== false) return $zone;
        }
    }
    return 'other';
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

function calculate_fan_speed($temps, $profile, $zone = null)
{
    if (empty($temps)) {
        return (int) $profile['maxSpeed'];
    }

    $maxTemp     = max($temps);
    $curve = $profile['zoneCurves'][$zone] ?? $profile;
    $targetTemp  = $curve['targetTemp'];
    $criticalTemp = $curve['maxTemp'];
    $minSpeed    = $profile['minSpeed'];
    $maxSpeed    = $profile['maxSpeed'];

    // Keep normal cooling quiet, but allow an explicit emergency boost.
    if (isset($curve['boostTemp'], $profile['boostSpeed']) && $maxTemp >= $curve['boostTemp']) {
        return (int) $profile['boostSpeed'];
    }

    if ($maxTemp <= $targetTemp) {
        return $minSpeed;
    } elseif ($maxTemp >= $criticalTemp) {
        return $maxSpeed;
    } else {
        $ratio = ($maxTemp - $targetTemp) / ($criticalTemp - $targetTemp);
        return (int) round($minSpeed + ($maxSpeed - $minSpeed) * $ratio);
    }
}

function set_fan_speeds($speeds, $fanCount)
{
    global $ILO_HOST, $ILO_USERNAME, $ILO_PASSWORD, $MINIMUM_FAN_SPEED;

    try {
        $ssh = ssh2_connect($ILO_HOST, 22);
        if (!$ssh || !ssh2_auth_password($ssh, $ILO_USERNAME, $ILO_PASSWORD)) {
            return false;
        }

        for ($i = 0; $i < $fanCount; $i++) {
            $speed = max($MINIMUM_FAN_SPEED, min(100, (int) ($speeds[$i] ?? $MINIMUM_FAN_SPEED)));
            $pwm = (int) ceil($speed / 100 * 255);
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

echo "=== Fan Control Daemon Started (iLO Zones + Unraid Disk Temps) ===\n";
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

    $profileName = strtolower($config['profile'] ?? 'normal');
    $profile = $config['profiles'][$profileName] ?? $config['profiles']['normal'];

    // Get all iLO thermal zones and the available fan count.
    $iloData = get_ilo_temperatures();
    if ($iloData === null) {
        echo "[WARN] Could not fetch iLO temperatures\n";
        sleep($config['checkInterval'] ?? 30);
        continue;
    }

    $zoneTemps   = $iloData['zones'];
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

    // Unraid reports actual drive temperatures; prefer those over iLO's storage-zone sensor.
    if (!empty($diskTemps)) {
        $zoneTemps['storage'] = array_map('floatval', array_values($diskTemps));
    }
    $maxCpu  = !empty($zoneTemps['cpu']) ? max($zoneTemps['cpu']) : 0;

    echo "  CPU: {$maxCpu}°C";
    if (!empty($diskTemps)) {
        $diskSummary = implode(', ', array_map(fn($n, $t) => "$n:{$t}°C", array_keys($diskTemps), $diskTemps));
        echo " | Disks: $diskSummary";
    }
    echo "\n";

    $zoneDemands = [];
    foreach ($zoneTemps as $zone => $readings) {
        $zoneDemands[$zone] = calculate_fan_speed($readings, $profile, $zone);
        printf("  %s: %.1f°C -> %d%%\n", ucfirst($zone), max($readings), $zoneDemands[$zone]);
    }
    // Fans without a configured zone follow the strongest system cooling demand.
    $baseSpeed = empty($zoneDemands) ? $profile['maxSpeed'] : max($zoneDemands);
    $fanZones = $config['fanZones'] ?? [];
    $fanSpeeds = array_fill(0, $fanCount, $baseSpeed);
    $mappedFans = [];
    foreach ($fanZones as $zone => $indices) {
        if (empty($zoneTemps[$zone])) continue;
        $zoneSpeed = calculate_fan_speed($zoneTemps[$zone], $profile, $zone);
        foreach ($indices as $index) {
            $index = (int) $index;
            if ($index >= 0 && $index < $fanCount) {
                // A fan can cool multiple zones (for example GPU and FPGA); honor the higher demand.
                $fanSpeeds[$index] = isset($mappedFans[$index])
                    ? max($fanSpeeds[$index], $zoneSpeed)
                    : $zoneSpeed;
                $mappedFans[$index] = true;
            }
        }
    }
    // A genuinely hot component overrides zone routing and boosts every fan.
    $boostSpeed = max($fanSpeeds);
    if ($boostSpeed > $profile['maxSpeed']) $fanSpeeds = array_fill(0, $fanCount, $boostSpeed);
    echo "  Calculated fan speeds: " . implode(', ', $fanSpeeds) . "%\n";

    $speedDiff = $lastSpeed === null ? 100 : max(array_map(fn($s, $i) => abs($s - ($lastSpeed[$i] ?? 0)), $fanSpeeds, array_keys($fanSpeeds)));
    if ($lastSpeed === null || $speedDiff > 3) {
        echo "  Applying fan speeds (largest diff: {$speedDiff}%)...\n";
        if (set_fan_speeds($fanSpeeds, $fanCount)) {
            echo "  [OK] Fan speeds set to: " . implode(', ', $fanSpeeds) . "%\n";
            $lastSpeed = $fanSpeeds;
        } else {
            echo "  [ERROR] Could not apply fan speeds\n";
        }
    } else {
        echo "  No change (diff: {$speedDiff}% < 3%)\n";
    }

    sleep($config['checkInterval'] ?? 30);
}
