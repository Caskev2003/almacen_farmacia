<?php

require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/utils.php';

requireLogin();

$gruposTexto = trim($_GET['grupos'] ?? '');
$grupos = [];

if ($gruposTexto !== '') {
    $grupos = array_filter(array_map('trim', explode(',', $gruposTexto)));
}

$moduleCss = 'inventario_fisico';

include __DIR__ . '/../app/views/layouts/header.php';

?>

<div class="inventario-screen no-print">

    <div class="inventario-card">

        <h2>Hoja de Inventario Físico</h2>

        <p>
            Agrega los grupos que debe contar el almacenista.
            Presiona Enter después de cada grupo.
        </p>

        <form method="GET" id="formInventario">

            <input 
                type="hidden" 
                name="grupos" 
                id="gruposInput" 
                value="<?= e($gruposTexto) ?>"
            >

            <div class="grupo-input-box">

                <label>Grupo / Marca / Laboratorio</label>

                <input 
                    type="text" 
                    id="grupoTexto" 
                    placeholder="Ejemplo: AXE y presiona Enter"
                    autocomplete="off"
                >

            </div>

            <div class="chips" id="chips"></div>

            <div class="acciones">

                <button type="submit">
                    Generar hoja
                </button>

                <?php if (true): ?>

                    <button 
                        type="button" 
                        onclick="window.print()"
                    >
                        Imprimir
                    </button>

                <?php endif; ?>

            </div>

        </form>

    </div>

</div>



<div class="hoja-inventario">

    <div class="encabezado">

        <img src="assets/img/principal2.png" alt="Logo G&D">

        <div class="titulo">

            <h3>DISTRIBUCION G&amp;D SA DE CV</h3>

            <h2>I N V E N T A R I O</h2>

            <div class="fecha-linea">

                <span>FECHA:</span>

                <div></div>

            </div>

        </div>

    </div>

    <div class="equipo-linea">

        <strong>GRUPOS:</strong>

        <?= e(implode(', ', $grupos)) ?>

    </div>

    <table class="tabla-inventario">

        <thead>

            <tr>

                <th>CODIGO</th>
                <th>RACK</th>
                <th>NIVEL</th>
                <th>ZONA</th>
                <th>FISICO</th>

            </tr>

        </thead>

        <tbody>

            <?php for ($i = 0; $i < 35; $i++): ?>

                <tr>

                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>

                </tr>

            <?php endfor; ?>

        </tbody>

    </table>

    <table class="firmas">

        <tr>

            <td>ENTREGO:</td>
            <td>RECIBIO:</td>

        </tr>

        <tr>

            <td>NOMBRE Y FIRMA</td>
            <td>NOMBRE Y FIRMA</td>

        </tr>

    </table>

    <div class="equipo-final">

        <strong>EQUIPO.</strong>

        <span></span>

    </div>

</div>



<script>

const input = document.getElementById('grupoTexto');

const chips = document.getElementById('chips');

const hidden = document.getElementById('gruposInput');

const form = document.getElementById('formInventario');

let grupos = hidden.value
    ? hidden.value.split(',').map(g => g.trim()).filter(Boolean)
    : [];

function renderChips() {

    chips.innerHTML = '';

    grupos.forEach((grupo, index) => {

        const chip = document.createElement('div');

        chip.className = 'chip';

        chip.innerHTML = `
            <span>${grupo}</span>
            <button type="button" onclick="eliminarGrupo(${index})">×</button>
        `;

        chips.appendChild(chip);
    });

    hidden.value = grupos.join(',');
}

function eliminarGrupo(index) {

    grupos.splice(index, 1);

    renderChips();
}

input.addEventListener('keydown', function(e) {

    if (e.key === 'Enter') {

        e.preventDefault();

        const valor = input.value.trim().toUpperCase();

        if (valor && !grupos.includes(valor)) {

            grupos.push(valor);

            input.value = '';

            renderChips();
        }
    }
});

form.addEventListener('submit', function() {

    const valor = input.value.trim().toUpperCase();

    if (valor && !grupos.includes(valor)) {

        grupos.push(valor);

        input.value = '';
    }

    hidden.value = grupos.join(',');
});

renderChips();

</script>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>