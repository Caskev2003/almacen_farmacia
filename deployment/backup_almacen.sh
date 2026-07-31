#!/usr/bin/env bash

set -Eeuo pipefail

# El directorio de respaldos tiene el bit setgid y grupo www-data. Esta
# máscara permite que cron (root) y el botón web (www-data) compartan el
# archivo de bloqueo sin dar acceso a otros usuarios del servidor.
umask 007

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
readonly BLOQUEO="${DIRECTORIO_RESPALDOS}/.backup_almacen.lock"

mkdir -p "$DIRECTORIO_RESPALDOS"
touch "$LOG"

exec 9>"$BLOQUEO"

if ! flock -w 120 9; then
    printf '%s | ERROR | Otro respaldo continúa en ejecución.\n' \
        "$(date '+%Y-%m-%d %H:%M:%S')" >> "$LOG"
    printf '%s\n' \
        'Otro respaldo continúa en ejecución. Intente nuevamente.' >&2
    exit 75
fi

normalizar_archivo() {
    local archivo="$1"
    local modo="${2:-0640}"

    if [[ "$EUID" -eq 0 ]]; then
        chown root:www-data "$archivo"
    else
        chgrp www-data "$archivo" 2>/dev/null || true
    fi

    chmod "$modo" "$archivo"
}

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

    normalizar_archivo "$ESTADO_ERROR" 0660 2>/dev/null || true

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
normalizar_archivo "$DESTINO"

printf '%s|%s|%s\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" \
    "$NOMBRE" \
    "$(stat -c '%s' "$DESTINO")" > "$ESTADO_OK"

normalizar_archivo "$ESTADO_OK" 0660
rm -f "$ESTADO_ERROR"

printf '%s | OK | %s | %s bytes\n' \
    "$(date '+%Y-%m-%d %H:%M:%S')" \
    "$NOMBRE" \
    "$(stat -c '%s' "$DESTINO")" >> "$LOG"

trap - ERR EXIT

printf 'BACKUP_OK=%s\n' "$DESTINO"
