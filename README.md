<h1 align="center">iLO Fans Controller</h1>

<p align="center">
  <img width="800" src="screenshot.png" alt="Webpage Screenshot">
  <br>
  <i>Easily manage your HP's server fans speeds with automatic temperature-based control!</i>
</p>

---

> 🍴 **This is a fork** of [alex3025/ilo-fans-controller](https://github.com/alex3025/ilo-fans-controller) with significant enhancements for automatic fan control and temperature monitoring.

## ✨ What's New in This Fork

### 🌡️ Temperature Monitoring
- **Real-time temperature display** organized by zones (CPU, Memory, Storage, VR, Chipset, iLO, PCI, Ambient)
- **Auto-refresh** with configurable intervals (5s, 10s, 30s, 60s)
- **Color-coded progress bars** showing temperature relative to critical thresholds
- **Collapsible zone cards** with detailed sensor view

### 🤖 Automatic Fan Control
- **Background daemon** (`fan-daemon.php`) that adjusts fan speeds based on temperatures
- **Three built-in profiles** with a quiet 10–30% fan range and a high-temperature emergency boost
- **Zone-aware cooling** for CPU, GPU/PCI, memory, regulators, ambient, and storage sensors
- **Per-fan zone routing** configurable by iLO fan index
- **Manual/Auto toggle** in the web interface
- **Hysteresis** to prevent fan oscillation (only changes speed if diff > 3%)
- **Ambient temperature safety**: Forces Normal profile if inlet temp > 40°C
- **Persistent configuration** via `auto-control.json`

### 🐳 Enhanced Docker Support
- **Supervisord** manages both Apache and the daemon in a single container
- **Environment variables** for all configuration
- **Health checks** built-in
- **Swarm-ready** docker-compose.yml

### ⚡ Performance Optimizations
- **Combined SSH commands** for faster fan speed application
- **Timeout handling** to prevent blocking
- **Fan count detection** from iLO API

---

## 🚀 Quick Start with Docker

### Docker Run
```bash
docker run -d \
  --name='ilofancontrol' \
  --net='bridge' \
  --pids-limit 2048 \
  -e ILO_HOST='your-ilo-ip' \
  -e ILO_USERNAME='Administrator' \
  -e ILO_PASSWORD='your-Password' \
  -e AUTO_DAEMON='true' \
  -e UNRAID_HOST='ip' \
  -e UNRAID_API_KEY='key' \
  -p '8000:80/tcp' \
    postal4093/ilo-fans-controller:latest
```

### Docker Compose
```yaml
version: "3.8"

services:
  ilo-fans-controller:
    image: postal4093/ilo-fans-controller:latest
    ports:
      - "8080:80"
    environment:
      ILO_HOST: 'your-ilo-ip'
      ILO_USERNAME: 'Administrator'
      ILO_PASSWORD: 'your-password'
      MINIMUM_FAN_SPEED: '10'
      AUTO_DAEMON: 'true'
    volumes:
      - ilo-fans-data:/data
    deploy:
      replicas: 1
      restart_policy:
        condition: on-failure

volumes:
  ilo-fans-data:
```

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `ILO_HOST` | IP address of your iLO interface | *required* |
| `ILO_USERNAME` | iLO username | *required* |
| `ILO_PASSWORD` | iLO password | *required* |
| `UNRAID_HOST` | Unraid server address for disk temperatures | optional |
| `UNRAID_API_KEY` | Read-only Unraid GraphQL API key | optional |
| `MINIMUM_FAN_SPEED` | Minimum allowed fan speed (%) | `10` |
| `AUTO_DAEMON` | Enable background auto-control daemon | `true` |

---

## 🎛️ Auto-Control Profiles

The daemon uses profiles to determine fan speeds based on CPU temperatures:

| Profile | Normal Fan Range | Target Temp | Full quiet-range speed | Emergency boost |
|---------|------------------|-------------|------------------------|-----------------|
| **Silence** | 10% - 30% | 60°C | 80°C | 100% at 85°C |
| **Normal** | 10% - 30% | 60°C | 80°C | 100% at 85°C |
| **Turbo** | 10% - 30% | 55°C | 75°C | 100% at 82°C |

### How It Works

1. The daemon reads all enabled iLO thermal sensors and Unraid drive temperatures every 20 seconds.
2. Each zone has its own temperature curve. Normal speeds stay between 10% and 30%; the controller raises all fans to 100% only at the configured high-temperature boost threshold.
3. Fans not assigned to a zone follow the strongest cooling demand across the system.
4. Assign fans to zones by adding zero-based iLO fan indexes to `fanZones`. For example, after confirming fan positions from your server's fan list, set `"fanZones": {"cpu": [2, 3], "gpu": [0, 1], "storage": [4, 5]}`. These example indexes are placeholders: verify your physical fan layout before using them. Supported zones include `cpu`, `gpu`, `pci`, `memory`, `vr`, `storage`, `ambient`, `power`, `chipset`, and `other`.
5. The emergency boost thresholds are intentionally configurable in `auto-control.json`; tune them against your server's sensor critical limits and workload behavior.

### Configuration File (`auto-control.json`)

```json
{
  "enabled": true,
  "profile": "normal",
  "fanZones": {},
  "profiles": {
    "silence": {
      "label": "Silence",
      "minSpeed": 10,
      "maxSpeed": 30,
      "targetTemp": 60,
      "maxTemp": 80,
      "boostSpeed": 100,
      "boostTemp": 85
    }
  },
  "checkInterval": 20
}
```

---

## 🔧 Manual Installation

### Requirements
- HP server with **patched iLO 4** firmware (Gen8/Gen9)
- PHP 8.x with `php-curl`, `php-ssh2`, `php-pcntl`, `php-posix`
- Apache or Nginx web server

### Installation Steps

1. Clone the repository:
   ```bash
   git clone https://github.com/jorisbertomeu/ilo-fans-controller.git
   cd ilo-fans-controller
   ```

2. Create your config file:
   ```bash
   cp config.inc.php.example config.inc.php
   nano config.inc.php
   ```

3. Copy files to web server:
   ```bash
   sudo cp ilo-fans-controller.php /var/www/html/index.php
   sudo cp fan-daemon.php auto-control.json config.inc.php favicon.ico /var/www/html/
   ```

4. Start the daemon (optional, for auto-control):
   ```bash
   nohup php /var/www/html/fan-daemon.php > /dev/null 2>&1 &
   ```

---

## 📡 API Reference

### GET Endpoints

| Endpoint | Description |
|----------|-------------|
| `?api=fans` | Get current fan speeds |
| `?api=temperatures` | Get temperature readings (grouped by zone) |
| `?api=presets` | Get saved presets |
| `?api=autocontrol` | Get auto-control configuration |

### POST Actions

| Action | Payload | Description |
|--------|---------|-------------|
| `fans` | `{ "action": "fans", "fans": 50 }` | Set all fans to 50% |
| `presets` | `{ "action": "presets", "presets": [...] }` | Save presets |
| `autocontrol` | `{ "action": "autocontrol", "config": {...} }` | Update auto-control config |

---

## ⚠️ Requirements

This tool requires a **patched iLO firmware** that exposes fan control commands via SSH.

- ✅ Supported: **Gen8 & Gen9 servers with iLO 4**
- 🚫 Not supported: Gen10/11/12 with iLO 5/6/7

More info: [Reddit post about iLO 4 patching](https://www.reddit.com/r/homelab/comments/sx3ldo/hp_ilo4_v277_unlocked_access_to_fan_controls/)

---

## 🙏 Credits

- Original project by [alex3025](https://github.com/alex3025/ilo-fans-controller)

- Forked From [jorisbertomeu](https://github.com/jorisbertomeu)

## 📝 License

This project is open source. See the original repository for license information.
