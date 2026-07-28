<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Bitácora central de auditoría.
 *
 * Este archivo no debe interrumpir una operación del sistema si la tabla de
 * auditoría todavía no existe o si ocurre un error al registrar el evento.
 */

function auditSanitizeData(mixed $value, int $depth = 0): mixed
{
    if ($depth > 6) {
        return '[DATOS OMITIDOS POR PROFUNDIDAD]';
    }

    if (is_array($value)) {
        $result = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if ($count >= 150) {
                $result['_resumen'] = 'Se omitieron elementos adicionales.';
                break;
            }

            $normalizedKey = strtolower(
                str_replace(
                    ['á', 'é', 'í', 'ó', 'ú', 'ñ'],
                    ['a', 'e', 'i', 'o', 'u', 'n'],
                    (string) $key
                )
            );

            if (
                preg_match(
                    '/password|contrasena|passwd|token|csrf|secret|authorization|cookie|session/i',
                    $normalizedKey
                )
            ) {
                $result[$key] = '[PROTEGIDO]';
            } else {
                $result[$key] = auditSanitizeData(
                    $item,
                    $depth + 1
                );
            }

            $count++;
        }

        return $result;
    }

    if (is_object($value)) {
        return auditSanitizeData(
            get_object_vars($value),
            $depth + 1
        );
    }

    if (is_string($value)) {
        $value = trim($value);

        if (mb_strlen($value) > 4000) {
            return mb_substr($value, 0, 4000)
                . '… [VALOR RECORTADO]';
        }
    }

    return $value;
}

function auditJson(mixed $value): ?string
{
    if ($value === null || $value === [] || $value === '') {
        return null;
    }

    $json = json_encode(
        auditSanitizeData($value),
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    return $json === false ? null : $json;
}

function auditClientIp(): ?string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($ip === '') {
        return null;
    }

    return mb_substr($ip, 0, 45);
}

function auditCurrentUrl(): ?string
{
    $url = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));

    return $url === ''
        ? null
        : mb_substr($url, 0, 1000);
}

function auditUserSnapshot(?array $override = null): array
{
    $user = $override ?? ($_SESSION['user'] ?? []);

    return [
        'id' => isset($user['id']) && (int) $user['id'] > 0
            ? (int) $user['id']
            : null,
        'nombre' => trim((string) ($user['nombre'] ?? '')),
        'usuario' => trim((string) ($user['usuario'] ?? '')),
        'rol' => strtoupper(trim((string) ($user['rol'] ?? ''))),
        'almacen_id' => isset($user['almacen_id'])
            && (int) $user['almacen_id'] > 0
                ? (int) $user['almacen_id']
                : null,
        'almacen_nombre' => trim(
            (string) ($user['almacen_nombre'] ?? '')
        ),
    ];
}

/**
 * Registra un evento sin lanzar excepciones hacia el flujo principal.
 *
 * Claves admitidas:
 * modulo, accion, entidad, registro_id, descripcion, anteriores, nuevos,
 * metadata y usuario.
 */
function auditLog(array $event): bool
{
    static $connection = null;

    try {
        if (!$connection instanceof PDO) {
            $database = new Database();
            $connection = $database->connect();
        }

        $user = auditUserSnapshot(
            isset($event['usuario']) && is_array($event['usuario'])
                ? $event['usuario']
                : null
        );

        $module = trim((string) ($event['modulo'] ?? 'Sistema'));
        $action = strtoupper(
            trim((string) ($event['accion'] ?? 'ACCION'))
        );
        $description = trim(
            (string) ($event['descripcion'] ?? '')
        );

        if ($description === '') {
            $description = $action . ' en ' . $module;
        }

        $sql = "
            INSERT INTO auditoria_movimientos (
                usuario_id,
                usuario_nombre,
                usuario_login,
                usuario_rol,
                almacen_id,
                almacen_nombre,
                modulo,
                accion,
                entidad,
                registro_id,
                descripcion,
                datos_anteriores,
                datos_nuevos,
                metadata,
                direccion_ip,
                user_agent,
                metodo_http,
                url
            ) VALUES (
                :usuario_id,
                :usuario_nombre,
                :usuario_login,
                :usuario_rol,
                :almacen_id,
                :almacen_nombre,
                :modulo,
                :accion,
                :entidad,
                :registro_id,
                :descripcion,
                :datos_anteriores,
                :datos_nuevos,
                :metadata,
                :direccion_ip,
                :user_agent,
                :metodo_http,
                :url
            )
        ";

        $stmt = $connection->prepare($sql);

        return $stmt->execute([
            ':usuario_id' => $user['id'],
            ':usuario_nombre' => $user['nombre'] ?: null,
            ':usuario_login' => $user['usuario'] ?: null,
            ':usuario_rol' => $user['rol'] ?: null,
            ':almacen_id' => $user['almacen_id'],
            ':almacen_nombre' => $user['almacen_nombre'] ?: null,
            ':modulo' => mb_substr($module, 0, 100),
            ':accion' => mb_substr($action, 0, 80),
            ':entidad' => isset($event['entidad'])
                ? mb_substr(trim((string) $event['entidad']), 0, 100)
                : null,
            ':registro_id' => isset($event['registro_id'])
                ? mb_substr(trim((string) $event['registro_id']), 0, 100)
                : null,
            ':descripcion' => mb_substr($description, 0, 4000),
            ':datos_anteriores' => auditJson(
                $event['anteriores'] ?? null
            ),
            ':datos_nuevos' => auditJson(
                $event['nuevos'] ?? null
            ),
            ':metadata' => auditJson(
                $event['metadata'] ?? null
            ),
            ':direccion_ip' => auditClientIp(),
            ':user_agent' => isset($_SERVER['HTTP_USER_AGENT'])
                ? mb_substr(
                    trim((string) $_SERVER['HTTP_USER_AGENT']),
                    0,
                    500
                )
                : null,
            ':metodo_http' => isset($_SERVER['REQUEST_METHOD'])
                ? mb_substr(
                    strtoupper((string) $_SERVER['REQUEST_METHOD']),
                    0,
                    10
                )
                : null,
            ':url' => auditCurrentUrl(),
        ]);
    } catch (Throwable $e) {
        error_log(
            'No se pudo guardar la auditoría: '
            . $e->getMessage()
        );

        return false;
    }
}

function auditChangedValues(array $before, array $after): array
{
    $oldValues = [];
    $newValues = [];

    foreach (array_unique(array_merge(
        array_keys($before),
        array_keys($after)
    )) as $key) {
        $old = $before[$key] ?? null;
        $new = $after[$key] ?? null;

        if (
            auditJson(['value' => $old])
            === auditJson(['value' => $new])
        ) {
            continue;
        }

        $oldValues[$key] = $old;
        $newValues[$key] = $new;
    }

    return [
        'anteriores' => $oldValues,
        'nuevos' => $newValues,
    ];
}

function auditExtractProductIds(mixed ...$sources): array
{
    $ids = [];

    $walk = static function (mixed $value) use (&$walk, &$ids): void {
        if (!is_array($value)) {
            return;
        }

        if (
            isset($value['producto_id'])
            && (int) $value['producto_id'] > 0
        ) {
            $ids[] = (int) $value['producto_id'];
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                $walk($item);
            }
        }
    };

    foreach ($sources as $source) {
        $walk($source);
    }

    $ids = array_values(array_unique($ids));

    return array_slice($ids, 0, 500);
}

/**
 * Obtiene el estado actual del catálogo y de todas las ubicaciones de los
 * productos indicados. Se utiliza antes y después de entradas, salidas y
 * cancelaciones para conservar el cambio real de existencias.
 */
function auditInventorySnapshot(array $productIds): array
{
    static $connection = null;

    $productIds = array_values(array_unique(array_filter(
        array_map('intval', $productIds),
        static fn (int $id): bool => $id > 0
    )));

    if ($productIds === []) {
        return [];
    }

    $productIds = array_slice($productIds, 0, 500);

    try {
        if (!$connection instanceof PDO) {
            $database = new Database();
            $connection = $database->connect();
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($productIds), '?')
        );

        $stmtProducts = $connection->prepare("
            SELECT
                id,
                codigo,
                codigo_barras,
                descripcion,
                ubicacion,
                existencia_bodega,
                estado
            FROM productos
            WHERE id IN ({$placeholders})
            ORDER BY id ASC
        ");
        $stmtProducts->execute($productIds);

        $products = [];

        foreach ($stmtProducts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['existencias'] = [];
            $products[(int) $row['id']] = $row;
        }

        $stmtStocks = $connection->prepare("
            SELECT
                id,
                producto_id,
                sucursal,
                ubicacion,
                existencia
            FROM producto_existencias
            WHERE producto_id IN ({$placeholders})
            ORDER BY producto_id ASC, sucursal ASC, ubicacion ASC, id ASC
        ");
        $stmtStocks->execute($productIds);

        foreach ($stmtStocks->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) $row['producto_id'];

            if (!isset($products[$productId])) {
                $products[$productId] = [
                    'id' => $productId,
                    'existencias' => [],
                ];
            }

            $products[$productId]['existencias'][] = $row;
        }

        return array_values($products);
    } catch (Throwable $e) {
        error_log(
            'No se pudo obtener el estado de existencias para auditoría: '
            . $e->getMessage()
        );

        return [];
    }
}

function auditModuleForScript(string $script): string
{
    $modules = [
        'dashboard.php' => 'Inicio',
        'productos.php' => 'Productos',
        'importar_productos.php' => 'Importación de productos',
        'entradas.php' => 'Entradas',
        'historial_entradas.php' => 'Historial de entradas',
        'imprimir_entrada.php' => 'Entradas',
        'salidas.php' => 'Salidas',
        'historial_salidas.php' => 'Historial de salidas',
        'imprimir_salida.php' => 'Salidas',
        'existencias.php' => 'Existencias',
        'agotados.php' => 'Agotados',
        'resurtidos.php' => 'Resurtidos',
        'kardex.php' => 'Kardex',
        'reportes.php' => 'Reportes',
        'inventario_fisico.php' => 'Inventario físico',
        'inventario_virtual.php' => 'Inventario virtual',
        'inventario_virtual_historial.php' => 'Historial de inventario virtual',
        'usuario.php' => 'Usuarios',
        'respaldos.php' => 'Respaldos',
        'historial_movimientos.php' => 'Historial de movimientos',
        'api_buscar_producto.php' => 'Inventario virtual',
        'api_guardar_inventario_virtual.php' => 'Inventario virtual',
        'exportar_agotados_excel.php' => 'Agotados',
        'exportar_inventario_virtual_excel.php' => 'Inventario virtual',
        'exportar_inventario_virtual_pdf.php' => 'Inventario virtual',
        'exportar_reporte_excel.php' => 'Reportes',
        'exportar_reporte_pdf.php' => 'Reportes',
    ];

    return $modules[$script]
        ?? ucwords(str_replace(['_', '.php'], [' ', ''], $script));
}

/**
 * Registra una sola vez por petición los accesos, búsquedas y exportaciones.
 * Las acciones POST se registran después de conocer si realmente tuvieron éxito.
 */
function auditTrackCurrentRequest(): void
{
    static $tracked = false;

    if ($tracked || empty($_SESSION['user'])) {
        return;
    }

    $tracked = true;

    $method = strtoupper(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );

    if ($method !== 'GET') {
        return;
    }

    $script = basename(
        (string) ($_SERVER['SCRIPT_NAME'] ?? '')
    );

    $action = strtolower(
        trim((string) ($_GET['action'] ?? ''))
    );

    // Evita llenar la bitácora con el sondeo automático cada 30 segundos.
    if (
        $script === 'resurtidos.php'
        && in_array(
            $action,
            ['notificaciones', 'buscar_producto'],
            true
        )
    ) {
        return;
    }

    $module = auditModuleForScript($script);
    $eventAction = 'ACCESO_MODULO';
    $description = 'Ingresó al módulo ' . $module . '.';
    $metadata = [];

    if (str_starts_with($script, 'exportar_')) {
        $eventAction = 'EXPORTACION';
        $description = 'Exportó información desde el módulo '
            . $module . '.';
        $metadata['filtros'] = $_GET;
    } elseif (str_starts_with($script, 'imprimir_')) {
        $eventAction = 'CONSULTA_DOCUMENTO';
        $description = 'Consultó el formato de impresión de '
            . $module . '.';
        $metadata['parametros'] = $_GET;
    } elseif (
        in_array(
            $action,
            ['buscar_producto', 'buscar', 'obtener'],
            true
        )
    ) {
        $eventAction = $action === 'obtener'
            ? 'CONSULTA_REGISTRO'
            : 'BUSQUEDA';
        $description = $eventAction === 'BUSQUEDA'
            ? 'Realizó una búsqueda en ' . $module . '.'
            : 'Consultó el detalle de un registro en ' . $module . '.';
        $metadata['parametros'] = $_GET;
    } else {
        $filters = $_GET;

        foreach (
            ['page', 'ok', 'success', 'error', 'preview'] as $ignored
        ) {
            unset($filters[$ignored]);
        }

        $filters = array_filter(
            $filters,
            static fn (mixed $value): bool =>
                is_array($value)
                ? $value !== []
                : trim((string) $value) !== ''
        );

        if ($filters !== []) {
            $eventAction = isset($filters['edit'])
                ? 'CONSULTA_REGISTRO'
                : 'BUSQUEDA';
            $description = $eventAction === 'BUSQUEDA'
                ? 'Aplicó una búsqueda o filtros en ' . $module . '.'
                : 'Consultó un registro para edición en ' . $module . '.';
            $metadata['filtros'] = $filters;
        }
    }

    auditLog([
        'modulo' => $module,
        'accion' => $eventAction,
        'descripcion' => $description,
        'metadata' => $metadata,
    ]);
}
