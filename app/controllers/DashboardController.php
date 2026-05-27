<?php

require_once __DIR__ . '/../config/database.php';

class DashboardController
{
    private PDO $conn;
    private string $rol = '';
    private int $almacenId = 0;
    private int $stockMinimoDashboard = 120;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        date_default_timezone_set('America/Mexico_City');

        $database = new Database();
        $this->conn = $database->connect();
        $this->conn->query("SET time_zone = '-06:00'");

        $usuario = $_SESSION['user'] ?? [];

        $this->rol = strtoupper(trim($usuario['rol'] ?? ''));
        $this->almacenId = (int)($usuario['almacen_id'] ?? 0);
    }

    private function esAdmin(): bool
    {
        return in_array($this->rol, ['ADMINISTRADOR', 'ADMIN'], true);
    }

    public function getRolActual(): string
    {
        return $this->rol;
    }

    public function getAlmacenIdActual(): int
    {
        return $this->almacenId;
    }

    public function getStockMinimoDashboard(): int
    {
        return $this->stockMinimoDashboard;
    }

    private function sucursalesPermitidas(): array
    {
        if ($this->esAdmin()) {
            return [];
        }

        if ($this->almacenId === 1) {
            return ['CIUDAD HIDALGO', 'CD HIDALGO'];
        }

        if ($this->almacenId === 2 || $this->almacenId === 3) {
            return ['TUXTLA', 'TUXTLA GUTIERREZ', 'TUXTLA GUTIÉRREZ'];
        }

        return [];
    }

    private function agregarFiltroSucursal(string &$sql, array &$params, string $alias = 'pe'): void
    {
        $sucursales = $this->sucursalesPermitidas();

        if (empty($sucursales)) {
            return;
        }

        $marks = [];

        foreach ($sucursales as $i => $sucursal) {
            $key = ":sucursal_{$alias}_{$i}";
            $marks[] = $key;
            $params[$key] = $sucursal;
        }

        $sql .= " AND UPPER(COALESCE({$alias}.sucursal, '')) COLLATE utf8mb4_general_ci IN (" . implode(',', $marks) . ") ";
    }

    private function agregarFiltroMovimientos(string &$sql, array &$params): void
    {
        if (!$this->esAdmin() && $this->almacenId > 0) {
            $sql .= " AND m.almacen_id = :almacen_id ";
            $params[':almacen_id'] = $this->almacenId;
        }
    }

    private function rangoFechas(string $periodo): array
    {
        $periodo = strtolower(trim($periodo));

        $hoy = new DateTime('now', new DateTimeZone('America/Mexico_City'));

        if ($periodo === 'semana') {
            $inicio = clone $hoy;
            $inicio->modify('monday this week');
            $inicio->setTime(0, 0, 0);

            $fin = clone $hoy;
            $fin->modify('sunday this week');
            $fin->setTime(23, 59, 59);

            return [
                'inicio' => $inicio->format('Y-m-d H:i:s'),
                'fin' => $fin->format('Y-m-d H:i:s'),
                'texto' => 'Esta semana'
            ];
        }

        if ($periodo === 'mes') {
            $inicio = clone $hoy;
            $inicio->modify('first day of this month');
            $inicio->setTime(0, 0, 0);

            $fin = clone $hoy;
            $fin->modify('last day of this month');
            $fin->setTime(23, 59, 59);

            return [
                'inicio' => $inicio->format('Y-m-d H:i:s'),
                'fin' => $fin->format('Y-m-d H:i:s'),
                'texto' => 'Este mes'
            ];
        }

        if ($periodo === 'anio' || $periodo === 'año') {
            $inicio = new DateTime($hoy->format('Y') . '-01-01 00:00:00', new DateTimeZone('America/Mexico_City'));
            $fin = new DateTime($hoy->format('Y') . '-12-31 23:59:59', new DateTimeZone('America/Mexico_City'));

            return [
                'inicio' => $inicio->format('Y-m-d H:i:s'),
                'fin' => $fin->format('Y-m-d H:i:s'),
                'texto' => 'Este año'
            ];
        }

        $inicio = clone $hoy;
        $inicio->setTime(0, 0, 0);

        $fin = clone $hoy;
        $fin->setTime(23, 59, 59);

        return [
            'inicio' => $inicio->format('Y-m-d H:i:s'),
            'fin' => $fin->format('Y-m-d H:i:s'),
            'texto' => 'Hoy'
        ];
    }

    public function getIndicadores(string $periodo = 'hoy'): array
    {
        $stockMinimo = $this->stockMinimoDashboard;

        $stmtTotal = $this->conn->prepare("SELECT COUNT(*) AS total FROM productos WHERE estado = 1");
        $stmtTotal->execute();
        $totalProductos = (int)($stmtTotal->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $sqlSinAlmacen = "SELECT COUNT(DISTINCT p.id) AS total
                          FROM productos p
                          LEFT JOIN producto_existencias pe
                              ON pe.producto_id = p.id
                          WHERE p.estado = 1
                          AND (
                              pe.producto_id IS NULL
                              OR pe.sucursal IS NULL
                              OR TRIM(pe.sucursal) = ''
                          )";

        $stmtSinAlmacen = $this->conn->prepare($sqlSinAlmacen);
        $stmtSinAlmacen->execute();
        $sinAlmacen = (int)($stmtSinAlmacen->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $paramsSinExistencia = [];

        $sqlSinExistencia = "SELECT COUNT(*) AS total
                             FROM (
                                SELECT p.id
                                FROM productos p
                                INNER JOIN producto_existencias pe
                                    ON pe.producto_id = p.id
                                WHERE p.estado = 1
                                AND pe.sucursal IS NOT NULL
                                AND TRIM(pe.sucursal) != ''";

        $this->agregarFiltroSucursal($sqlSinExistencia, $paramsSinExistencia, 'pe');

        $sqlSinExistencia .= " GROUP BY p.id
                               HAVING SUM(COALESCE(pe.existencia, 0)) <= 0
                             ) AS t";

        $stmtSinExistencia = $this->conn->prepare($sqlSinExistencia);
        $stmtSinExistencia->execute($paramsSinExistencia);
        $sinExistencia = (int)($stmtSinExistencia->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $paramsStock = [];

        $sqlStock = "SELECT COUNT(*) AS total
                     FROM (
                        SELECT p.id, SUM(COALESCE(pe.existencia, 0)) AS existencia_total
                        FROM productos p
                        INNER JOIN producto_existencias pe
                            ON pe.producto_id = p.id
                        WHERE p.estado = 1
                        AND pe.sucursal IS NOT NULL
                        AND TRIM(pe.sucursal) != ''
                        AND pe.ubicacion IS NOT NULL
                        AND TRIM(pe.ubicacion) != ''
                        AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

        $this->agregarFiltroSucursal($sqlStock, $paramsStock, 'pe');

        $sqlStock .= " GROUP BY p.id
                       HAVING SUM(COALESCE(pe.existencia, 0)) > 0
                     ) AS t";

        $stmtStock = $this->conn->prepare($sqlStock);
        $stmtStock->execute($paramsStock);
        $stockCorrecto = (int)($stmtStock->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $paramsBajo = [];

        $sqlBajo = "SELECT COUNT(*) AS total
                    FROM (
                        SELECT p.id, SUM(COALESCE(pe.existencia, 0)) AS existencia_total
                        FROM productos p
                        INNER JOIN producto_existencias pe
                            ON pe.producto_id = p.id
                        WHERE p.estado = 1
                        AND pe.sucursal IS NOT NULL
                        AND TRIM(pe.sucursal) != ''
                        AND pe.ubicacion IS NOT NULL
                        AND TRIM(pe.ubicacion) != ''
                        AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

        $this->agregarFiltroSucursal($sqlBajo, $paramsBajo, 'pe');

        $sqlBajo .= " GROUP BY p.id
                      HAVING SUM(COALESCE(pe.existencia, 0)) BETWEEN 1 AND {$stockMinimo}
                    ) AS t";

        $stmtBajo = $this->conn->prepare($sqlBajo);
        $stmtBajo->execute($paramsBajo);
        $bajoStock = (int)($stmtBajo->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $enStock = max($stockCorrecto - $bajoStock, 0);

        $rango = $this->rangoFechas($periodo);

        $paramsEntrada = [
            ':inicio' => $rango['inicio'],
            ':fin' => $rango['fin']
        ];

        $sqlEntradas = "SELECT COUNT(*) AS total
                        FROM movimientos m
                        WHERE m.tipo_movimiento = 'ENTRADA'
                        AND m.fecha BETWEEN :inicio AND :fin";

        $this->agregarFiltroMovimientos($sqlEntradas, $paramsEntrada);

        $stmtEntradas = $this->conn->prepare($sqlEntradas);
        $stmtEntradas->execute($paramsEntrada);
        $entradas = (int)($stmtEntradas->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $paramsSalida = [
            ':inicio' => $rango['inicio'],
            ':fin' => $rango['fin']
        ];

        $sqlSalidas = "SELECT COUNT(*) AS total
                       FROM movimientos m
                       WHERE m.tipo_movimiento = 'SALIDA'
                       AND m.fecha BETWEEN :inicio AND :fin";

        $this->agregarFiltroMovimientos($sqlSalidas, $paramsSalida);

        $stmtSalidas = $this->conn->prepare($sqlSalidas);
        $stmtSalidas->execute($paramsSalida);
        $salidas = (int)($stmtSalidas->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        return [
            'total_productos' => $totalProductos,
            'agotados' => $sinExistencia,
            'sin_existencia' => $sinExistencia,
            'sin_almacen' => $sinAlmacen,
            'bajo_stock' => $bajoStock,
            'en_stock' => $enStock,
            'stock_correcto' => $stockCorrecto,
            'entradas_hoy' => $entradas,
            'salidas_hoy' => $salidas,
            'entradas_periodo' => $entradas,
            'salidas_periodo' => $salidas,
            'periodo_texto' => $rango['texto'],
        ];
    }

    public function getComparativoIndicadores(): array
    {
        $hoy = new DateTime('now', new DateTimeZone('America/Mexico_City'));

        $inicioHoy = clone $hoy;
        $inicioHoy->setTime(0, 0, 0);

        $finHoy = clone $hoy;
        $finHoy->setTime(23, 59, 59);

        $inicioAyer = clone $hoy;
        $inicioAyer->modify('-1 day');
        $inicioAyer->setTime(0, 0, 0);

        $finAyer = clone $hoy;
        $finAyer->modify('-1 day');
        $finAyer->setTime(23, 59, 59);

        return [
            'entradas' => [
                'hoy' => $this->contarMovimientosPorRango('ENTRADA', $inicioHoy->format('Y-m-d H:i:s'), $finHoy->format('Y-m-d H:i:s')),
                'ayer' => $this->contarMovimientosPorRango('ENTRADA', $inicioAyer->format('Y-m-d H:i:s'), $finAyer->format('Y-m-d H:i:s')),
            ],
            'salidas' => [
                'hoy' => $this->contarMovimientosPorRango('SALIDA', $inicioHoy->format('Y-m-d H:i:s'), $finHoy->format('Y-m-d H:i:s')),
                'ayer' => $this->contarMovimientosPorRango('SALIDA', $inicioAyer->format('Y-m-d H:i:s'), $finAyer->format('Y-m-d H:i:s')),
            ],
        ];
    }

    private function contarMovimientosPorRango(string $tipo, string $inicio, string $fin): int
    {
        $params = [
            ':tipo' => $tipo,
            ':inicio' => $inicio,
            ':fin' => $fin
        ];

        $sql = "SELECT COUNT(*) AS total
                FROM movimientos m
                WHERE m.tipo_movimiento = :tipo
                AND m.fecha BETWEEN :inicio AND :fin";

        $this->agregarFiltroMovimientos($sql, $params);

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }

    public function getAlertasInteligentes(): array
    {
        $indicadores = $this->getIndicadores();

        $alertas = [];

        if ((int)$indicadores['bajo_stock'] > 0) {
            $alertas[] = [
                'tipo' => 'warning',
                'icono' => '⚠️',
                'titulo' => 'Productos con bajo stock',
                'texto' => number_format((int)$indicadores['bajo_stock']) . ' productos tienen existencia de 1 a ' . $this->stockMinimoDashboard . ' piezas.',
                'link' => 'existencias.php'
            ];
        }

        if ((int)$indicadores['sin_existencia'] > 0) {
            $alertas[] = [
                'tipo' => 'danger',
                'icono' => '🚫',
                'titulo' => 'Productos sin existencia',
                'texto' => number_format((int)$indicadores['sin_existencia']) . ' productos tienen almacén asignado pero no tienen piezas disponibles.',
                'link' => 'agotados.php'
            ];
        }

        if ((int)$indicadores['sin_almacen'] > 0) {
            $alertas[] = [
                'tipo' => 'info',
                'icono' => '📦',
                'titulo' => 'Productos sin almacén',
                'texto' => number_format((int)$indicadores['sin_almacen']) . ' productos no tienen sucursal asignada.',
                'link' => 'agotados.php'
            ];
        }

        if ((int)$indicadores['entradas_hoy'] === 0 && (int)$indicadores['salidas_hoy'] === 0) {
            $alertas[] = [
                'tipo' => 'neutral',
                'icono' => '🕒',
                'titulo' => 'Sin movimientos hoy',
                'texto' => 'Aún no se han registrado entradas ni salidas durante el día.',
                'link' => 'reportes.php'
            ];
        }

        return $alertas;
    }

public function getMetricasInteligentes(): array
{
    $params = [];

    $sqlUbicacion = "SELECT
                        COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACION') AS ubicacion,
                        SUM(COALESCE(pe.existencia, 0)) AS total
                     FROM producto_existencias pe
                     INNER JOIN productos p
                        ON p.id = pe.producto_id
                     WHERE p.estado = 1
                     AND COALESCE(pe.existencia, 0) > 0
                     AND pe.sucursal IS NOT NULL
                     AND TRIM(pe.sucursal) != ''
                     AND pe.ubicacion IS NOT NULL
                     AND TRIM(pe.ubicacion) != ''
                     AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

    $this->agregarFiltroSucursal($sqlUbicacion, $params, 'pe');

    $sqlUbicacion .= " GROUP BY COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACION')
                       ORDER BY total DESC
                       LIMIT 1";

    $stmtUbicacion = $this->conn->prepare($sqlUbicacion);
    $stmtUbicacion->execute($params);

    $ubicacion = $stmtUbicacion->fetch(PDO::FETCH_ASSOC) ?: [];

    $paramsUsuario = [];

    $sqlUsuario = "SELECT
                        u.nombre AS usuario,
                        COUNT(*) AS total
                   FROM movimientos m
                   INNER JOIN usuarios u
                        ON u.id = m.usuario_id
                   WHERE m.fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

    $this->agregarFiltroMovimientos($sqlUsuario, $paramsUsuario);

    $sqlUsuario .= " GROUP BY u.id, u.nombre
                     ORDER BY total DESC
                     LIMIT 1";

    $stmtUsuario = $this->conn->prepare($sqlUsuario);
    $stmtUsuario->execute($paramsUsuario);

    $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'ubicacion_mas_usada' => [
            'titulo' => 'Ubicación con más stock',
            'principal' => $ubicacion['ubicacion'] ?? 'Sin datos',
            'detalle' => isset($ubicacion['total'])
                ? number_format((int)$ubicacion['total']) . ' piezas'
                : 'Sin movimientos'
        ],

        'usuario_mas_activo' => [
            'titulo' => 'Usuario más activo',
            'principal' => $usuario['usuario'] ?? 'Sin datos',
            'detalle' => isset($usuario['total'])
                ? number_format((int)$usuario['total']) . ' movimientos últimos 30 días'
                : 'Sin movimientos'
        ]
    ];
}
    public function getProductosCriticos(int $limite = 12): array
    {
        $params = [];
        $stockMinimo = $this->stockMinimoDashboard;

        $sql = "SELECT
                    p.id,
                    p.codigo,
                    p.descripcion,
                    {$stockMinimo} AS stock_minimo,
                    MIN(pe.ubicacion) AS ubicacion,
                    SUM(COALESCE(pe.existencia, 0)) AS existencia_actual
                FROM productos p
                INNER JOIN producto_existencias pe
                    ON pe.producto_id = p.id
                WHERE p.estado = 1
                AND pe.sucursal IS NOT NULL
                AND TRIM(pe.sucursal) != ''
                AND pe.ubicacion IS NOT NULL
                AND TRIM(pe.ubicacion) != ''
                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

        $this->agregarFiltroSucursal($sql, $params, 'pe');

        $sql .= " GROUP BY p.id, p.codigo, p.descripcion
                  HAVING SUM(COALESCE(pe.existencia, 0)) BETWEEN 1 AND {$stockMinimo}
                  ORDER BY existencia_actual ASC, p.descripcion ASC
                  LIMIT :limite";

        $stmt = $this->conn->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMovimientosRecientes(int $limite = 8): array
    {
        $params = [];

        $sql = "SELECT
                    m.id,
                    m.folio,
                    m.tipo_movimiento,
                    m.tipo_operacion,
                    m.fecha,
                    a.nombre AS almacen,
                    u.nombre AS usuario
                FROM movimientos m
                LEFT JOIN almacenes a
                    ON m.almacen_id = a.id
                INNER JOIN usuarios u
                    ON m.usuario_id = u.id
                WHERE 1 = 1";

        $this->agregarFiltroMovimientos($sql, $params);

        $sql .= " ORDER BY m.fecha DESC, m.id DESC LIMIT :limite";

        $stmt = $this->conn->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getProductosPorUbicacion(int $limite = 10): array
    {
        return $this->getTopUbicaciones($limite);
    }

    public function getTopUbicaciones(int $limite = 10): array
    {
        $params = [];

        $sql = "SELECT
                    COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACION') AS ubicacion,
                    SUM(COALESCE(pe.existencia, 0)) AS total
                FROM producto_existencias pe
                INNER JOIN productos p
                    ON p.id = pe.producto_id
                WHERE p.estado = 1
                AND COALESCE(pe.existencia, 0) > 0
                AND pe.sucursal IS NOT NULL
                AND TRIM(pe.sucursal) != ''
                AND pe.ubicacion IS NOT NULL
                AND TRIM(pe.ubicacion) != ''
                AND UPPER(TRIM(pe.ubicacion)) COLLATE utf8mb4_general_ci NOT IN ('SIN UBICACION', 'SIN UBICACIÓN')";

        $this->agregarFiltroSucursal($sql, $params, 'pe');

        $sql .= " GROUP BY COALESCE(NULLIF(TRIM(pe.ubicacion), ''), 'SIN UBICACION')
                  ORDER BY total DESC
                  LIMIT :limite";

        $stmt = $this->conn->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDocumentosMasUsados(int $limite = 5): array
    {
        $params = [];

        $sql = "SELECT 
                    COALESCE(NULLIF(TRIM(m.tipo_operacion), ''), 'SIN DOCUMENTO') AS documento,
                    COUNT(*) AS total
                FROM movimientos m
                WHERE m.tipo_movimiento = 'SALIDA'";

        $this->agregarFiltroMovimientos($sql, $params);

        $sql .= " GROUP BY COALESCE(NULLIF(TRIM(m.tipo_operacion), ''), 'SIN DOCUMENTO')
                  ORDER BY total DESC
                  LIMIT :limite";

        $stmt = $this->conn->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }


    public function getTopProductosVendidos(int $limite = 10): array
{
    $params = [];

    $sql = "SELECT
                p.codigo,
                p.descripcion,
                SUM(COALESCE(md.cantidad, 0)) AS total
            FROM movimiento_detalles md
            INNER JOIN movimientos m
                ON m.id = md.movimiento_id
            INNER JOIN productos p
                ON p.id = md.producto_id
            WHERE m.tipo_movimiento = 'SALIDA'
            AND m.fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

    $this->agregarFiltroMovimientos($sql, $params);

    $sql .= " GROUP BY p.id, p.codigo, p.descripcion
              ORDER BY total DESC
              LIMIT :limite";

    $stmt = $this->conn->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);

    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
    public function getEstadoProducto(array $item): array
    {
        $existencia = (int)($item['existencia_actual'] ?? 0);

        if ($existencia <= 0) {
            return [
                'texto' => 'SIN EXISTENCIA',
                'clase' => 'badge-danger'
            ];
        }

        if ($existencia <= $this->stockMinimoDashboard) {
            return [
                'texto' => 'BAJO STOCK',
                'clase' => 'badge-warning'
            ];
        }

        return [
            'texto' => 'EN STOCK',
            'clase' => 'badge-success'
        ];
    }

    public function getPorcentajeStockProducto(array $item): int
    {
        $existencia = (int)($item['existencia_actual'] ?? 0);
        $minimo = (int)($item['stock_minimo'] ?? $this->stockMinimoDashboard);

        if ($minimo <= 0) {
            return 0;
        }

        $porcentaje = (int)round(($existencia / $minimo) * 100);

        return max(0, min($porcentaje, 100));
    }
}