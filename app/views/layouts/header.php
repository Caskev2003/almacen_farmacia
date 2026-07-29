<?php

require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/utils.php';

$user = currentUser();

$moduleCss = $moduleCss ?? null;

$rolUsuario = strtoupper(
    trim($user['rol'] ?? '')
);

$almacenId = (int) (
    $user['almacen_id'] ?? 0
);

$esAdmin = $rolUsuario === 'ADMINISTRADOR';
$esGerente = $rolUsuario === 'GERENTE';
$esEncargado = $rolUsuario === 'ENCARGADO';
$esConsulta = $rolUsuario === 'CONSULTA';

// ======================================================
// PERMISOS DEL MENÚ
// ======================================================

$puedeVerAgotados = in_array(
    $rolUsuario,
    [
        'ADMINISTRADOR',
        'GERENTE',
        'ENCARGADO',
        'CONSULTA'
    ],
    true
);

$puedeVerInventarios =
    $esAdmin
    || $esGerente
    || $esEncargado
    || $esConsulta
    || $almacenId === 1
    || $almacenId === 2
    || $almacenId === 3;

$puedeVerResurtidos = in_array(
    $rolUsuario,
    [
        'ADMINISTRADOR',
        'GERENTE',
        'ENCARGADO'
    ],
    true
);

/*
 * Solo el ADMINISTRADOR y el ENCARGADO reciben
 * notificaciones de solicitudes pendientes.
 */
$puedeRecibirResurtidos =
    $esAdmin
    || $esEncargado;

$esGerenteCiudadHidalgo =
    $esGerente
    && $almacenId === 1;

$puedeVerInventarioVirtual =
    $puedeVerInventarios
    && !$esGerenteCiudadHidalgo;

$puedeVerTickets =
    $esAdmin
    || (
        $almacenId === 1
        && ($esGerente || $esEncargado)
    );

$puedeRecibirTickets =
    $esAdmin
    || ($esEncargado && $almacenId === 1);

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Sistema de Almacén de Farmacia</title>

    <link
        rel="stylesheet"
        href="assets/css/global.css"
    >

    <?php if (!empty($moduleCss)): ?>

        <link
            rel="stylesheet"
            href="assets/css/<?= e($moduleCss) ?>.css?v=<?= time() ?>"
        >

    <?php endif; ?>

    <style>
        /* ==============================================
           ENLACE Y CONTADOR DE RESURTIDOS
        ============================================== */

        .nav-resurtidos {
            position: relative;
            display: inline-flex !important;
            align-items: center;
            gap: 7px;
        }

        .contador-resurtidos {
            display: inline-flex;
            min-width: 21px;
            height: 21px;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
            border: 2px solid #ffffff;
            border-radius: 999px;
            background: #dc3545;
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
            line-height: 1;
            box-shadow:
                0 2px 6px rgba(220, 53, 69, 0.3);
        }

        .contador-resurtidos[hidden] {
            display: none !important;
        }

        .contador-resurtidos.nuevas {
            animation:
                resurtidoPulso 1.2s ease-in-out 2;
        }

        @keyframes resurtidoPulso {
            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.2);
            }
        }
    </style>
</head>

<body>

<header class="topbar">

    <div class="topbar-left">

        <h1>
            SISTEMA DE ALMACÉN - FARMACIA
        </h1>

    </div>

    <div class="topbar-right">

        <?php if ($user): ?>

            <span>
                <?= e($user['nombre'] ?? '') ?>

                |

                <?= e($user['rol'] ?? '') ?>
            </span>

            <a
                href="logout.php"
                class="btn-top"
            >
                Cerrar sesión
            </a>

        <?php endif; ?>

    </div>

</header>

<?php if ($user): ?>

    <nav class="navbar">

        <a href="dashboard.php">
            Inicio
        </a>

        <?php if (!$esGerente && !$esConsulta): ?>

            <a href="productos.php">
                Productos
            </a>

            <a href="entradas.php">
                Entradas
            </a>

            <a href="salidas.php">
                Salidas
            </a>

        <?php endif; ?>

        <a href="existencias.php">
            Existencias
        </a>

        <?php if ($puedeVerAgotados): ?>

            <a href="agotados.php">
                Agotados
            </a>

        <?php endif; ?>

        <?php if ($puedeVerResurtidos): ?>

            <a
                href="resurtidos.php"
                class="nav-resurtidos"
                id="enlaceResurtidos"
            >
                <span>Resurtidos</span>

                <?php if ($puedeRecibirResurtidos): ?>

                    <span
                        id="contadorResurtidos"
                        class="contador-resurtidos"
                        aria-label="Solicitudes de resurtido pendientes"
                        title="Solicitudes pendientes"
                        hidden
                    >
                        0
                    </span>

                <?php endif; ?>
            </a>

        <?php endif; ?>

        <?php if ($puedeVerTickets): ?>

            <a
                href="tickets.php"
                class="nav-resurtidos"
                id="enlaceTickets"
            >
                <span>Tickets</span>

                <?php if ($puedeRecibirTickets): ?>

                    <span
                        id="contadorTickets"
                        class="contador-resurtidos"
                        aria-label="Tickets pendientes"
                        title="Tickets pendientes"
                        hidden
                    >
                        0
                    </span>

                <?php endif; ?>
            </a>

        <?php endif; ?>

        <?php if (!$esGerente && !$esConsulta): ?>

            <a href="kardex.php">
                Kardex
            </a>

            <a href="reportes.php">
                Reportes
            </a>

            <a href="historial_entradas.php">
                Historial de Entradas
            </a>

            <a href="historial_salidas.php">
                Historial Salidas
            </a>

        <?php endif; ?>

        <?php if ($puedeVerInventarios): ?>

            <a href="inventario_fisico.php">
                Inventario Físico
            </a>

        <?php endif; ?>

        <?php if ($puedeVerInventarioVirtual): ?>

            <a href="inventario_virtual.php">
                Inventario Virtual
            </a>

            <a href="inventario_virtual_historial.php">
                Historial Virtual
            </a>

        <?php endif; ?>

        <?php if ($esAdmin): ?>

            <a href="historial_movimientos.php">
                Historial de Movimientos
            </a>

            <a href="usuario.php">
                Usuarios
            </a>

        <?php endif; ?>

        <?php if ($esAdmin || $esEncargado): ?>

            <a href="respaldos.php">
                Respaldos
            </a>

        <?php endif; ?>

    </nav>

<?php endif; ?>

<?php if ($user && $puedeRecibirResurtidos): ?>

    <script>
        (function () {
            'use strict';

            const contador =
                document.getElementById(
                    'contadorResurtidos'
                );

            let cantidadAnterior = null;

            if (!contador) {
                return;
            }

            async function consultarResurtidosPendientes() {
                try {
                    const respuesta = await fetch(
                        'resurtidos.php?action=notificaciones',
                        {
                            method: 'GET',
                            cache: 'no-store',
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            }
                        }
                    );

                    if (!respuesta.ok) {
                        return;
                    }

                    const resultado =
                        await respuesta.json();

                    if (!resultado.ok) {
                        return;
                    }

                    const cantidad = Number(
                        resultado.datos?.cantidad ?? 0
                    );

                    if (
                        !Number.isInteger(cantidad)
                        || cantidad <= 0
                    ) {
                        contador.textContent = '0';
                        contador.hidden = true;
                        cantidadAnterior = 0;

                        return;
                    }

                    contador.textContent =
                        cantidad > 99
                            ? '99+'
                            : String(cantidad);

                    contador.hidden = false;

                    /*
                     * Animar solamente si la cantidad aumentó.
                     */
                    if (
                        cantidadAnterior !== null
                        && cantidad > cantidadAnterior
                    ) {
                        contador.classList.remove(
                            'nuevas'
                        );

                        void contador.offsetWidth;

                        contador.classList.add(
                            'nuevas'
                        );
                    }

                    cantidadAnterior = cantidad;
                } catch (error) {
                    /*
                     * La consulta se ejecuta silenciosamente
                     * para no interrumpir el uso del sistema.
                     */
                    console.warn(
                        'No fue posible consultar los resurtidos pendientes.'
                    );
                }
            }

            consultarResurtidosPendientes();

            /*
             * Consultar cada 3 segundos si hay nuevas
             * solicitudes de resurtido.
             */
            window.setInterval(
                consultarResurtidosPendientes,
                3000
            );
        })();
    </script>

<?php endif; ?>

<?php if ($user && $puedeRecibirTickets): ?>

    <script>
        (function () {
            'use strict';

            const contador =
                document.getElementById('contadorTickets');

            let cantidadAnterior = null;

            if (!contador) {
                return;
            }

            async function consultarTicketsPendientes() {
                try {
                    const respuesta = await fetch(
                        'tickets.php?action=notificaciones',
                        {
                            method: 'GET',
                            cache: 'no-store',
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            }
                        }
                    );

                    if (!respuesta.ok) {
                        return;
                    }

                    const resultado = await respuesta.json();

                    if (!resultado.ok) {
                        return;
                    }

                    const cantidad = Number(
                        resultado.datos?.cantidad ?? 0
                    );

                    if (
                        !Number.isInteger(cantidad)
                        || cantidad <= 0
                    ) {
                        contador.textContent = '0';
                        contador.hidden = true;
                        cantidadAnterior = 0;
                        return;
                    }

                    contador.textContent =
                        cantidad > 99 ? '99+' : String(cantidad);
                    contador.hidden = false;

                    if (
                        cantidadAnterior !== null
                        && cantidad > cantidadAnterior
                    ) {
                        contador.classList.remove('nuevas');
                        void contador.offsetWidth;
                        contador.classList.add('nuevas');
                    }

                    cantidadAnterior = cantidad;
                } catch (error) {
                    console.warn(
                        'No fue posible consultar los tickets pendientes.'
                    );
                }
            }

            consultarTicketsPendientes();

            window.setInterval(
                consultarTicketsPendientes,
                3000
            );
        })();
    </script>

<?php endif; ?>

<main class="main-content">
