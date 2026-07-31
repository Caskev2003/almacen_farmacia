#!/usr/bin/env bash

set -Eeuo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    printf 'Ejecute este instalador con sudo.\n' >&2
    exit 1
fi

readonly ORIGEN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly DIRECTORIO="/backups/almacen_farmacia"

for archivo in \
    backup_almacen.sh \
    almacen-backup.cnf \
    backup_almacen.cron
do
    if [[ ! -f "${ORIGEN}/${archivo}" ]]; then
        printf 'Falta el archivo %s.\n' "${ORIGEN}/${archivo}" >&2
        exit 2
    fi
done

if [[ ! -x /usr/bin/mysqldump ]]; then
    printf 'Instalando el cliente de MySQL...\n'
    apt-get update
    apt-get install -y mysql-client
fi

if [[ ! -x /usr/bin/flock ]]; then
    printf 'Instalando util-linux...\n'
    apt-get update
    apt-get install -y util-linux
fi

if [[ ! -x /usr/sbin/cron ]]; then
    printf 'Instalando cron...\n'
    apt-get update
    apt-get install -y cron
fi

install -d -o root -g www-data -m 2770 "$DIRECTORIO"
install -o root -g www-data -m 0750 \
    "${ORIGEN}/backup_almacen.sh" \
    /usr/local/bin/backup_almacen.sh

if [[ ! -f /etc/almacen-backup.cnf ]]; then
    install -o root -g www-data -m 0640 \
        "${ORIGEN}/almacen-backup.cnf" \
        /etc/almacen-backup.cnf
else
    chown root:www-data /etc/almacen-backup.cnf
    chmod 0640 /etc/almacen-backup.cnf
fi

touch /var/log/backup_almacen.log
chown root:www-data /var/log/backup_almacen.log
chmod 0660 /var/log/backup_almacen.log

for estado in \
    "${DIRECTORIO}/.ultimo_exito" \
    "${DIRECTORIO}/.ultimo_error" \
    "${DIRECTORIO}/.backup_almacen.lock"
do
    if [[ -e "$estado" ]]; then
        chown root:www-data "$estado"
        chmod 0660 "$estado"
    fi
done

install -o root -g root -m 0644 \
    "${ORIGEN}/backup_almacen.cron" \
    /etc/cron.d/backup_almacen

# El botón ya no requiere sudo. Se elimina solamente la regla antigua y
# limitada que pertenecía a este módulo.
rm -f /etc/sudoers.d/backup_almacen

# Elimina solamente programaciones antiguas del mismo script en el crontab
# de root para impedir que se creen dos respaldos a la misma hora.
cronAnterior="$(mktemp)"
cronNuevo="${cronAnterior}.nuevo"

if crontab -l > "$cronAnterior" 2>/dev/null; then
    grep -Ev \
        '/usr/local/bin/backup_(almacen|inventario)\.sh' \
        "$cronAnterior" > "$cronNuevo" || true
    crontab "$cronNuevo"
fi

rm -f "$cronAnterior" "$cronNuevo"

if command -v timedatectl >/dev/null 2>&1; then
    timedatectl set-timezone America/Mexico_City
fi

systemctl enable --now cron
systemctl restart cron

printf '\nProbando un respaldo ahora...\n'

if /usr/local/bin/backup_almacen.sh; then
    printf '\nProbando el mismo acceso que utiliza el botón web...\n'
    sleep 1

    if ! runuser -u www-data -- /usr/local/bin/backup_almacen.sh; then
        printf '\nEl respaldo como root funcionó, pero www-data no pudo ejecutarlo.\n' >&2
        exit 4
    fi

    printf '\nINSTALACIÓN CORRECTA\n'
    printf 'Horario: todos los días a las 08:00, 12:00 y 17:00.\n'
    printf 'Carpeta: %s\n' "$DIRECTORIO"
    printf 'Registro: /var/log/backup_almacen.log\n'
    printf 'Botón manual: habilitado sin sudo y sin restricción de horario.\n'
else
    printf '\nLa programación quedó instalada, pero la prueba falló.\n' >&2
    printf 'Revise /etc/almacen-backup.cnf y después ejecute:\n' >&2
    printf 'sudo /usr/local/bin/backup_almacen.sh\n' >&2
    exit 3
fi
