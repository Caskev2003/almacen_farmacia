<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';
require_once __DIR__ . '/../app/controllers/AuditoriaController.php';

requireLogin();

$controller = new AuditoriaController();
$controller->verificarAdministrador();

$filters = [
    'buscar' => trim((string) ($_GET['buscar'] ?? '')),
    'usuario_id' => (int) ($_GET['usuario_id'] ?? 0),
    'almacen_id' => (int) ($_GET['almacen_id'] ?? 0),
    'modulo' => trim((string) ($_GET['modulo'] ?? '')),
    'accion' => trim((string) ($_GET['accion'] ?? '')),
    'fecha_inicio' => trim(
        (string) ($_GET['fecha_inicio'] ?? '')
    ),
    'fecha_final' => trim(
        (string) ($_GET['fecha_final'] ?? '')
    ),
];

$page = max(1, (int) ($_GET['page'] ?? 1));
$error = '';
$result = [
    'registros' => [],
    'total' => 0,
    'pagina' => 1,
    'por_pagina' => 30,
    'total_paginas' => 1,
];
$options = [
    'usuarios' => [],
    'almacenes' => [],
    'modulos' => [],
    'acciones' => [],
];

try {
    if (!$controller->estaInstalado()) {
        $error = 'La tabla de auditoría todavía no está instalada. Ejecute database/instalar_auditoria.sql en phpMyAdmin.';
    } else {
        $result = $controller->consultar($filters, $page);
        $options = $controller->opciones();
    }
} catch (Throwable $e) {
    error_log(
        'Error al consultar la auditoría: '
        . $e->getMessage()
    );

    $error = 'No fue posible consultar el historial de movimientos.';
}

function auditDecodeForView(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function auditValueForView(mixed $value): string
{
    if ($value === null) {
        return 'Vacío';
    }

    if (is_bool($value)) {
        return $value ? 'Sí' : 'No';
    }

    if (is_array($value)) {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
        );

        return $json === false ? '' : $json;
    }

    $text = trim((string) $value);

    return $text === '' ? 'Vacío' : $text;
}

function auditFieldLabel(string $field): string
{
    return ucfirst(
        str_replace('_', ' ', $field)
    );
}

function auditActionClass(string $action): string
{
    if (
        str_contains($action, 'ELIMIN')
        || str_contains($action, 'CANCEL')
        || str_contains($action, 'FALLIDO')
        || str_contains($action, 'DENEGADO')
    ) {
        return 'audit-badge-danger';
    }

    if (
        str_contains($action, 'CRE')
        || str_contains($action, 'GUARD')
        || str_contains($action, 'INICIO_SESION')
    ) {
        return 'audit-badge-success';
    }

    if (
        str_contains($action, 'ACTUAL')
        || str_contains($action, 'EDIT')
        || str_contains($action, 'CAMBIO')
    ) {
        return 'audit-badge-warning';
    }

    return 'audit-badge-info';
}

$queryWithoutPage = $_GET;
unset($queryWithoutPage['page']);

$moduleCss = 'historial_movimientos';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<section class="audit-page">
    <div class="audit-heading">
        <div>
            <h2>Historial detallado de movimientos</h2>
            <p>
                Auditoría exclusiva del administrador:
                accesos, búsquedas y cambios realizados en el sistema.
            </p>
        </div>

        <div class="audit-total">
            <strong><?= number_format((int) $result['total']) ?></strong>
            <span>movimientos encontrados</span>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="audit-alert audit-alert-error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form
        method="GET"
        action="historial_movimientos.php"
        class="audit-filters"
    >
        <div class="audit-field audit-field-wide">
            <label for="buscar">Buscar</label>
            <input
                type="search"
                id="buscar"
                name="buscar"
                value="<?= e($filters['buscar']) ?>"
                placeholder="Usuario, descripción, producto, folio o ID"
            >
        </div>

        <div class="audit-field">
            <label for="usuario_id">Usuario</label>
            <select id="usuario_id" name="usuario_id">
                <option value="0">Todos</option>
                <?php foreach ($options['usuarios'] as $option): ?>
                    <option
                        value="<?= (int) $option['id'] ?>"
                        <?= (int) $filters['usuario_id'] === (int) $option['id'] ? 'selected' : '' ?>
                    >
                        <?= e($option['nombre']) ?>
                        (<?= e($option['usuario']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="audit-field">
            <label for="almacen_id">Almacén</label>
            <select id="almacen_id" name="almacen_id">
                <option value="0">Todos</option>
                <?php foreach ($options['almacenes'] as $option): ?>
                    <option
                        value="<?= (int) $option['id'] ?>"
                        <?= (int) $filters['almacen_id'] === (int) $option['id'] ? 'selected' : '' ?>
                    >
                        <?= e($option['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="audit-field">
            <label for="modulo">Módulo</label>
            <select id="modulo" name="modulo">
                <option value="">Todos</option>
                <?php foreach ($options['modulos'] as $option): ?>
                    <option
                        value="<?= e((string) $option) ?>"
                        <?= $filters['modulo'] === $option ? 'selected' : '' ?>
                    >
                        <?= e((string) $option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="audit-field">
            <label for="accion">Acción</label>
            <select id="accion" name="accion">
                <option value="">Todas</option>
                <?php foreach ($options['acciones'] as $option): ?>
                    <option
                        value="<?= e((string) $option) ?>"
                        <?= $filters['accion'] === $option ? 'selected' : '' ?>
                    >
                        <?= e(
                            str_replace('_', ' ', (string) $option)
                        ) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="audit-field">
            <label for="fecha_inicio">Desde</label>
            <input
                type="date"
                id="fecha_inicio"
                name="fecha_inicio"
                value="<?= e($filters['fecha_inicio']) ?>"
            >
        </div>

        <div class="audit-field">
            <label for="fecha_final">Hasta</label>
            <input
                type="date"
                id="fecha_final"
                name="fecha_final"
                value="<?= e($filters['fecha_final']) ?>"
            >
        </div>

        <div class="audit-filter-actions">
            <button type="submit" class="audit-btn audit-btn-primary">
                Filtrar
            </button>
            <a
                href="historial_movimientos.php"
                class="audit-btn audit-btn-secondary"
            >
                Limpiar
            </a>
        </div>
    </form>

    <div class="audit-table-card">
        <div class="audit-table-scroll">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Fecha y hora</th>
                        <th>Usuario</th>
                        <th>Módulo</th>
                        <th>Acción</th>
                        <th>Descripción</th>
                        <th>Detalles</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result['registros'] === []): ?>
                        <tr>
                            <td colspan="6" class="audit-empty">
                                No hay movimientos que coincidan con los filtros.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($result['registros'] as $row): ?>
                        <?php
                        $before = auditDecodeForView(
                            $row['datos_anteriores']
                        );
                        $after = auditDecodeForView(
                            $row['datos_nuevos']
                        );
                        $metadata = auditDecodeForView(
                            $row['metadata']
                        );
                        $fields = array_unique(array_merge(
                            array_keys($before),
                            array_keys($after)
                        ));
                        ?>
                        <tr>
                            <td class="audit-date">
                                <?= e(
                                    date(
                                        'd/m/Y H:i:s',
                                        strtotime($row['creado_en'])
                                    )
                                ) ?>
                            </td>
                            <td>
                                <strong>
                                    <?= e(
                                        $row['usuario_nombre']
                                        ?: 'Usuario no identificado'
                                    ) ?>
                                </strong>
                                <small>
                                    <?= e($row['usuario_login'] ?? '') ?>
                                    <?php if (!empty($row['usuario_rol'])): ?>
                                        · <?= e($row['usuario_rol']) ?>
                                    <?php endif; ?>
                                </small>
                                <?php if (!empty($row['almacen_nombre'])): ?>
                                    <small>
                                        <?= e($row['almacen_nombre']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?= e($row['modulo']) ?></td>
                            <td>
                                <span class="audit-badge <?= e(
                                    auditActionClass($row['accion'])
                                ) ?>">
                                    <?= e(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $row['accion']
                                        )
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <?= e($row['descripcion']) ?>
                                <?php if (!empty($row['entidad'])): ?>
                                    <small>
                                        <?= e($row['entidad']) ?>
                                        <?php if (!empty($row['registro_id'])): ?>
                                            #<?= e($row['registro_id']) ?>
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <details class="audit-details">
                                    <summary>Ver detalle</summary>

                                    <?php if ($fields !== []): ?>
                                        <div class="audit-change-title">
                                            Comparación de cambios
                                        </div>
                                        <div class="audit-changes-scroll">
                                            <table class="audit-changes">
                                                <thead>
                                                    <tr>
                                                        <th>Campo</th>
                                                        <th>Antes</th>
                                                        <th>Después</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($fields as $field): ?>
                                                        <tr>
                                                            <td>
                                                                <?= e(
                                                                    auditFieldLabel(
                                                                        (string) $field
                                                                    )
                                                                ) ?>
                                                            </td>
                                                            <td>
                                                                <pre><?= e(
                                                                    auditValueForView(
                                                                        $before[$field] ?? null
                                                                    )
                                                                ) ?></pre>
                                                            </td>
                                                            <td>
                                                                <pre><?= e(
                                                                    auditValueForView(
                                                                        $after[$field] ?? null
                                                                    )
                                                                ) ?></pre>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($metadata !== []): ?>
                                        <div class="audit-change-title">
                                            Información adicional
                                        </div>
                                        <pre class="audit-json"><?= e(
                                            auditValueForView($metadata)
                                        ) ?></pre>
                                    <?php endif; ?>

                                    <dl class="audit-technical">
                                        <div>
                                            <dt>IP</dt>
                                            <dd><?= e(
                                                $row['direccion_ip']
                                                ?: 'No disponible'
                                            ) ?></dd>
                                        </div>
                                        <div>
                                            <dt>Método</dt>
                                            <dd><?= e(
                                                $row['metodo_http']
                                                ?: 'No disponible'
                                            ) ?></dd>
                                        </div>
                                        <div>
                                            <dt>URL</dt>
                                            <dd><?= e(
                                                $row['url']
                                                ?: 'No disponible'
                                            ) ?></dd>
                                        </div>
                                        <div>
                                            <dt>Navegador</dt>
                                            <dd><?= e(
                                                $row['user_agent']
                                                ?: 'No disponible'
                                            ) ?></dd>
                                        </div>
                                    </dl>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ((int) $result['total_paginas'] > 1): ?>
        <nav class="audit-pagination" aria-label="Paginación">
            <?php
            $pageNumbers = [
                1,
                (int) $result['total_paginas'],
            ];

            for (
                $nearby = max(1, (int) $result['pagina'] - 2);
                $nearby <= min(
                    (int) $result['total_paginas'],
                    (int) $result['pagina'] + 2
                );
                $nearby++
            ) {
                $pageNumbers[] = $nearby;
            }

            $pageNumbers = array_values(
                array_unique($pageNumbers)
            );
            sort($pageNumbers);
            ?>
            <?php foreach ($pageNumbers as $number): ?>
                <?php
                $pageQuery = $queryWithoutPage;
                $pageQuery['page'] = $number;
                ?>
                <a
                    href="?<?= e(http_build_query($pageQuery)) ?>"
                    class="<?= $number === (int) $result['pagina'] ? 'active' : '' ?>"
                >
                    <?= $number ?>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>
