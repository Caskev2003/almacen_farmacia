#!/usr/bin/env bash

set -Eeuo pipefail

umask 027

readonly BASE_DATOS="almacen_farmacia"
readonly DIRECTORIO_RESPALDOS="/backups/almacen_farmacia"
readonly ARCHIVO_CREDENCIALES="/etc/almacen-backup.cnf"
readonly MYSQLDUMP="/usr/bin/mysqldump"
readonly SED="/usr/bin/sed"
readonly FECHA="$(date '+%Y-%m-%d_%H-%M-%S')"
readonly NOMBRE="respaldo_${BASE_DATOS}_${FECHA}.sql"
readonly DESTINO="${DIRECTORIO_RESPALDOS}/${NOMBRE}"
readonly TEMPORAL="${DIRECTORIO_RESPALDOS}/.${NOMBRE}.tmp"
readonly LOG="/var/log/backup_almacen.log"
readonly ESTADO_OK="${DIRECTORIO_RESPALDOS}/.ultimo_exito"
readonly ESTADO_ERROR="${DIRECTORIO_RESPALDOS}/.ultimo_error"

mkdir -p "$DIRECTORIO_RESPALDOS"
touch "$LOG"

exec 9>/run/lock/backup_almacen.lock

if ! flock -n 9; then
    printf '%s | OMITIDO | Ya existe otro respaldo en ejecución.\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" >> "$LOG"
    exit 0
fi

limpiar_temporal() {
    rm -f "$TEMPORAL"
}

guardar_error() {
    local codigo="$1"
    local mensaje="$2"

    limpiar_temporal

    printf '%s | ERROR | %s\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" \
        "$mensaje" >> "$LOG"

    printf '%s|%s\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" \
        "$mensaje" > "$ESTADO_ERROR"

    chown root:www-data "$ESTADO_ERROR" 2>/dev/null || true
    chmod 0640 "$ESTADO_ERROR" 2>/dev/null || true

    trap - ERR EXIT
    exit "$codigo"
}

registrar_error() {
    local codigo=$?

    guardar_error \
        "$codigo" \
        "El respaldo falló con código ${codigo}."
}

fallar() {
    local codigo="$1"
    shift
    local mensaje="$*"

    printf '%s\n' "$mensaje" >&2
    guardar_error "$codigo" "$mensaje"
}

trap registrar_error ERR
trap limpiar_temporal EXIT

if [[ ! -x "$MYSQLDUMP" ]]; then
    fallar 10 "No existe el ejecutable ${MYSQLDUMP}."
fi

if [[ ! -r "$ARCHIVO_CREDENCIALES" ]]; then
    fallar 11 "No se puede leer ${ARCHIVO_CREDENCIALES}."
fi

opciones_compatibilidad=()

if "$MYSQLDUMP" --help 2>/dev/null \
    | grep -q -- '--column-statistics'; then
    opciones_compatibilidad+=(--column-statistics=0)
fi

if "$MYSQLDUMP" --help 2>/dev/null \
    | grep -q -- '--set-gtid-purged'; then
    opciones_compatibilidad+=(--set-gtid-purged=OFF)
fi

printf '%s | INICIO | Generando %s\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" \
    "$NOMBRE" >> "$LOG"

"$MYSQLDUMP" \
    --defaults-extra-file="$ARCHIVO_CREDENCIALES" \
    "${opciones_compatibilidad[@]}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --hex-blob \
    --no-tablespaces \
    --default-character-set=utf8mb4 \
    --databases "$BASE_DATOS" \
    --result-file="$TEMPORAL"

if [[ ! -s "$TEMPORAL" ]]; then
    fallar 20 'mysqldump generó un archivo vacío.'
fi

if ! grep -q 'CREATE TABLE' "$TEMPORAL"; then
    fallar 21 'El archivo no contiene estructuras de tablas.'
fi

# Compatibilidad con phpMyAdmin/XAMPP de Windows: se sustituyen collations
# exclusivas de versiones nuevas de MySQL y se eliminan DEFINER que pueden
# bloquear la importación con otro usuario.
"$SED" -E -i \
    -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' \
    -e 's/utf8mb4_uca1400_ai_ci/utf8mb4_unicode_ci/g' \
    -e 's/DEFINER=`[^`]+`@`[^`]+`//g' \
    "$TEMPORAL"

mv "$TEMPORAL" "$DESTINO"
chown root:www-data "$DESTINO"
chmod 0640 "$DESTINO"

printf '%s|%s|%s\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" \
    "$NOMBRE" \
    "$(stat -c '%s' "$DESTINO")" > "$ESTADO_OK"

chown root:www-data "$ESTADO_OK"
chmod 0640 "$ESTADO_OK"
rm -f "$ESTADO_ERROR"

printf '%s | OK | %s | %s bytes\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" \
    "$NOMBRE" \
    "$(stat -c '%s' "$DESTINO")" >> "$LOG"

trap - ERR EXIT

printf 'BACKUP_OK=%s\n' "$DESTINO"
