#!/usr/bin/env bash
set -Eeuo pipefail

# Idempotent installer: existing files, packages, services, and .env are preserved.
APP_DIR="${APP_DIR:-/var/www/ANTRIAN-KU}"
INSTALL_MISSING_PACKAGES="${INSTALL_MISSING_PACKAGES:-0}"
INSTALL_CONFIGS="${INSTALL_CONFIGS:-1}"
INSTALL_COMPOSER="${INSTALL_COMPOSER:-0}"
ENABLE_SERVICES="${ENABLE_SERVICES:-0}"
RELOAD_SERVICES="${RELOAD_SERVICES:-0}"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"

usage() {
    cat <<'HELP'
Usage: sudo [VARIABLE=value] ./deploy/install-ubuntu.sh [options]

Safe defaults:
  - missing packages are reported, not installed;
  - existing configuration files and .env are preserved;
  - missing repository configuration files are added;
  - active services are not restarted or reloaded.

Options:
  --install-missing-packages  Install only packages that are missing.
  --no-install-missing        Do not install packages (default).
  --skip-configs              Do not add missing Nginx/Supervisor/systemd config.
  --enable-services           Enable/start inactive services, never restart active ones.
  --reload-services           Validate and reload services after newly added config.
  --help                      Show this help.
HELP
}

log() { printf '[antrian-ku-installer] %s\n' "$*"; }
warn() { printf '[antrian-ku-installer] WARNING: %s\n' "$*" >&2; }

for argument in "$@"; do
    case "$argument" in
        --install-missing-packages) INSTALL_MISSING_PACKAGES=1 ;;
        --no-install-missing) INSTALL_MISSING_PACKAGES=0 ;;
        --skip-configs) INSTALL_CONFIGS=0 ;;
        --enable-services) ENABLE_SERVICES=1 ;;
        --reload-services) RELOAD_SERVICES=1 ;;
        --help|-h) usage; exit 0 ;;
        *) warn "Opsi tidak dikenal: $argument"; usage; exit 2 ;;
    esac
done

if [[ "${EUID}" -ne 0 ]]; then
    warn "Jalankan dengan sudo/root agar pemeriksaan sistem dapat dilakukan."
    exit 1
fi

if [[ ! -d "$APP_DIR" ]]; then
    install -d -m 0755 "$APP_DIR"
    log "Membuat direktori aplikasi $APP_DIR."
else
    log "Direktori aplikasi sudah ada; ownership dan isinya dipertahankan."
fi

is_package_installed() {
    dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q 'install ok installed'
}

BASE_PACKAGES=(nginx redis-server supervisor postgresql postgresql-contrib git unzip curl ca-certificates)
REQUIRED_PACKAGES=("${BASE_PACKAGES[@]}")
MISSING_PACKAGES=()

for package in "${REQUIRED_PACKAGES[@]}"; do
    if ! is_package_installed "$package"; then MISSING_PACKAGES+=("$package"); fi
done

# Accept any installed PHP minor version instead of forcing a second version.
if ! command -v php >/dev/null 2>&1; then
    MISSING_PACKAGES+=(php-cli)
else
    for module in pgsql redis mbstring xml curl zip bcmath; do
        if ! php -m 2>/dev/null | grep -Fxq "$module"; then
            MISSING_PACKAGES+=("php-$module")
        fi
    done
fi
if ! compgen -G '/etc/php/*/fpm/php-fpm.conf' >/dev/null 2>&1; then
    MISSING_PACKAGES+=(php-fpm)
fi

if (("${#MISSING_PACKAGES[@]}" > 0)); then
    warn "Paket belum tersedia: ${MISSING_PACKAGES[*]}"
    if [[ "$INSTALL_MISSING_PACKAGES" == "1" ]]; then
        log "Memasang hanya paket yang belum tersedia."
        apt-get update
        apt-get install -y "${MISSING_PACKAGES[@]}"
    else
        warn "Tidak ada paket yang diubah. Gunakan --install-missing-packages bila diperlukan."
    fi
else
    log "Semua paket dasar yang diperlukan sudah tersedia."
fi

if command -v composer >/dev/null 2>&1; then
    log "Composer sudah tersedia: $(command -v composer)"
elif [[ "$INSTALL_COMPOSER" == "1" && ! -e /usr/local/bin/composer ]]; then
    log "Composer belum tersedia; memasang ke /usr/local/bin/composer."
    curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
else
    warn "Composer belum tersedia atau target sudah ada; tidak ditimpa."
fi

if [[ ! -f "$PROJECT_ROOT/backend/.env" ]]; then
    warn "backend/.env belum ada. File .env tidak dibuat otomatis."
else
    log "backend/.env sudah ada dan dipertahankan."
fi

CONFIG_CHANGED=0
install_config_if_absent() {
    local source="$1" target="$2" mode="${3:-0644}"
    if [[ ! -f "$source" ]]; then warn "Source tidak ditemukan: $source"; return 0; fi
    if [[ -e "$target" || -L "$target" ]]; then
        warn "Konfigurasi sudah ada, dipertahankan: $target"
        return 0
    fi
    install -D -o root -g root -m "$mode" "$source" "$target"
    log "Menambahkan konfigurasi: $target"
    CONFIG_CHANGED=1
}

if [[ "$INSTALL_CONFIGS" == "1" ]]; then
    install_config_if_absent "$PROJECT_ROOT/deploy/nginx/antrian.conf" /etc/nginx/sites-available/antrian
    if [[ ! -e /etc/nginx/sites-enabled/antrian && ! -L /etc/nginx/sites-enabled/antrian && -e /etc/nginx/sites-available/antrian ]]; then
        ln -s /etc/nginx/sites-available/antrian /etc/nginx/sites-enabled/antrian
        log "Menambahkan symlink Nginx site."
        CONFIG_CHANGED=1
    else
        warn "Symlink Nginx site sudah ada atau source belum tersedia; dipertahankan."
    fi
    install_config_if_absent "$PROJECT_ROOT/deploy/supervisor/antrian-worker.conf" /etc/supervisor/conf.d/antrian-worker.conf
    install_config_if_absent "$PROJECT_ROOT/deploy/systemd/antrian-reverb.service" /etc/systemd/system/antrian-reverb.service
    install_config_if_absent "$PROJECT_ROOT/deploy/scripts/backup-postgres.sh" /usr/local/sbin/antrian-backup-postgres 0750
else
    log "Penambahan konfigurasi dilewati (--skip-configs)."
fi

service_exists() { systemctl cat "$1" >/dev/null 2>&1; }
service_active() { systemctl is-active --quiet "$1"; }

if [[ "$CONFIG_CHANGED" == "1" ]] && command -v systemctl >/dev/null 2>&1; then systemctl daemon-reload; fi

if [[ "$ENABLE_SERVICES" == "1" ]]; then
    PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.5-fpm}"
    if [[ -z "$PHP_FPM_SERVICE" ]] && command -v systemctl >/dev/null 2>&1; then
        PHP_FPM_SERVICE="$(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk 'NR == 1 {print $1}')"
    elif ! service_exists "$PHP_FPM_SERVICE" && command -v systemctl >/dev/null 2>&1; then
        PHP_FPM_SERVICE="$(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk 'NR == 1 {print $1}')"
    fi
    SERVICES=(nginx redis-server postgresql antrian-reverb)
    [[ -n "$PHP_FPM_SERVICE" ]] && SERVICES+=("$PHP_FPM_SERVICE")
    for service in "${SERVICES[@]}"; do
        if ! service_exists "$service"; then continue; fi
        if service_active "$service"; then
            log "Service $service sudah aktif; tidak direstart."
        else
            systemctl enable --now "$service"
            log "Service $service diaktifkan dan dijalankan."
        fi
    done
    if command -v supervisorctl >/dev/null 2>&1 && service_exists supervisor; then
        supervisorctl reread || warn "Supervisor reread gagal."
        supervisorctl update || warn "Supervisor update gagal."
    fi
else
    log "Enable/start service dilewati. Gunakan --enable-services bila diperlukan."
fi

if [[ "$RELOAD_SERVICES" == "1" ]]; then
    if command -v nginx >/dev/null 2>&1 && service_exists nginx && nginx -t; then
        if service_active nginx; then systemctl reload nginx; else warn "Nginx tidak aktif; tidak direload."; fi
    fi
    if command -v supervisorctl >/dev/null 2>&1; then supervisorctl reread && supervisorctl update || warn "Supervisor reload gagal."; fi
else
    log "Reload service dilewati."
fi

if (("${#MISSING_PACKAGES[@]}" > 0)) && [[ "$INSTALL_MISSING_PACKAGES" != "1" ]]; then
    warn "Installer selesai tanpa mengubah paket. Lengkapi paket yang dilaporkan sebelum deployment."
fi
log "Selesai. Konfigurasi existing tidak ditimpa; hanya yang belum ada yang ditambahkan."
