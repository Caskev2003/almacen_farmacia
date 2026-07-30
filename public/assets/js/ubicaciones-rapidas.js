(function () {
    'use strict';

    const selector = 'input[data-ubicacion-rapida]';
    const estados = new WeakMap();

    function soloDigitos(valor) {
        return String(valor || '').replace(/\D/g, '').slice(0, 4);
    }

    function esCapturaNumerica(valor) {
        const texto = String(valor || '').trim().toUpperCase();

        if (texto === '') {
            return true;
        }

        if (/^[0-9\s]+$/.test(texto)) {
            return true;
        }

        return /^R?\d?_?N?\d?_?Z?\d{0,2}_*$/.test(
            texto.replace(/\s+/g, '')
        );
    }

    function codigoDesdeDigitos(digitos, conMascara) {
        const partes = String(digitos || '').slice(0, 4).split('');

        if (partes.length === 0) {
            return '';
        }

        if (!conMascara && partes.length < 4) {
            return '';
        }

        return 'R' + (partes[0] || '_')
            + 'N' + (partes[1] || '_')
            + 'Z' + (partes[2] || '_')
            + (partes[3] || '_');
    }

    function obtenerOpciones(estado) {
        const lista = estado.listaId
            ? document.getElementById(estado.listaId)
            : null;

        if (!lista) {
            return [];
        }

        return Array.from(lista.querySelectorAll('option'))
            .map(function (opcion) {
                return String(opcion.value || opcion.textContent || '')
                    .trim()
                    .toUpperCase();
            })
            .filter(Boolean)
            .filter(function (valor, indice, arreglo) {
                return arreglo.indexOf(valor) === indice;
            });
    }

    function buscarCoincidencias(estado) {
        const valor = estado.input.value.trim().toUpperCase();

        if (valor === '') {
            return [];
        }

        const opciones = obtenerOpciones(estado);

        if (estado.modo === 'numerico') {
            const digitos = estado.digitos;

            if (!digitos) {
                return [];
            }

            return opciones.filter(function (opcion) {
                return soloDigitos(opcion).startsWith(digitos);
            }).slice(0, 8);
        }

        return opciones.filter(function (opcion) {
            return opcion.includes(valor);
        }).slice(0, 8);
    }

    function cerrarSugerencias(estado) {
        estado.coincidencias = [];
        estado.indiceActivo = -1;
        estado.sugerencias.innerHTML = '';
        estado.sugerencias.hidden = true;
        estado.input.setAttribute('aria-expanded', 'false');
    }

    function marcarActivo(estado) {
        const elementos = estado.sugerencias.querySelectorAll(
            '.ubicacion-rapida-opcion'
        );

        elementos.forEach(function (elemento, indice) {
            const activo = indice === estado.indiceActivo;
            elemento.classList.toggle('activa', activo);
            elemento.setAttribute('aria-selected', activo ? 'true' : 'false');

            if (activo) {
                elemento.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    function seleccionar(estado, valor, origen) {
        estado.input.value = valor;
        estado.modo = 'seleccionado';
        estado.digitos = soloDigitos(valor);
        estado.input.setCustomValidity('');
        estado.input.classList.remove('ubicacion-rapida-incompleta');
        cerrarSugerencias(estado);

        estado.input.dispatchEvent(new CustomEvent(
            'ubicacionseleccionada',
            {
                bubbles: true,
                detail: { valor: valor, origen: origen || 'lista' }
            }
        ));
    }

    function mostrarSugerencias(estado) {
        const coincidencias = buscarCoincidencias(estado);
        estado.coincidencias = coincidencias;
        estado.indiceActivo = coincidencias.length > 0 ? 0 : -1;
        estado.sugerencias.innerHTML = '';

        if (coincidencias.length === 0) {
            cerrarSugerencias(estado);
            return;
        }

        coincidencias.forEach(function (valor, indice) {
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'ubicacion-rapida-opcion';
            boton.setAttribute('role', 'option');
            boton.setAttribute('aria-selected', indice === 0 ? 'true' : 'false');
            boton.textContent = valor;

            boton.addEventListener('mousedown', function (evento) {
                evento.preventDefault();
            });

            boton.addEventListener('click', function () {
                seleccionar(estado, valor, 'clic');
                estado.input.focus();
            });

            estado.sugerencias.appendChild(boton);
        });

        estado.sugerencias.hidden = false;
        estado.input.setAttribute('aria-expanded', 'true');
    }

    function renderizarMascara(estado) {
        estado.input.value = codigoDesdeDigitos(estado.digitos, true);
        estado.input.setCustomValidity('');
        estado.input.classList.toggle(
            'ubicacion-rapida-incompleta',
            estado.digitos.length > 0 && estado.digitos.length < 4
        );

        requestAnimationFrame(function () {
            const final = estado.input.value.length;
            estado.input.setSelectionRange(final, final);
        });

        mostrarSugerencias(estado);
    }

    function sincronizarDesdeValor(estado) {
        const valor = estado.input.value.trim().toUpperCase();

        if (esCapturaNumerica(valor)) {
            estado.modo = 'numerico';
            estado.digitos = soloDigitos(valor);
            renderizarMascara(estado);
            return;
        }

        estado.modo = 'texto';
        estado.digitos = '';
        estado.input.value = valor;
        estado.input.classList.remove('ubicacion-rapida-incompleta');
        estado.input.setCustomValidity('');
        mostrarSugerencias(estado);
    }

    function resolverValor(input, mostrarError) {
        const estado = estados.get(input);

        if (!estado) {
            return input.value.trim().toUpperCase();
        }

        const valor = input.value.trim().toUpperCase();

        if (valor === '') {
            input.setCustomValidity('');
            return '';
        }

        if (estado.modo === 'numerico' || esCapturaNumerica(valor)) {
            const digitos = soloDigitos(valor);

            if (digitos.length !== 4) {
                if (mostrarError !== false) {
                    input.setCustomValidity(
                        'Complete los cuatro números: rack, nivel y zona. Ejemplo: 1 1 01.'
                    );
                    input.classList.add('ubicacion-rapida-incompleta');
                }
                return null;
            }

            const opciones = obtenerOpciones(estado);
            const opcionExacta = opciones.find(function (opcion) {
                return soloDigitos(opcion) === digitos;
            });
            const resultado = opcionExacta
                || codigoDesdeDigitos(digitos, false);

            seleccionar(estado, resultado, 'normalizacion');
            return resultado;
        }

        input.value = valor;
        input.setCustomValidity('');
        return valor;
    }

    function inicializar(input) {
        if (estados.has(input)) {
            return;
        }

        const listaId = input.getAttribute('list') || '';
        const contenedor = document.createElement('div');
        const sugerencias = document.createElement('div');

        contenedor.className = 'ubicacion-rapida-control';
        sugerencias.className = 'ubicacion-rapida-sugerencias';
        sugerencias.id = 'sugerencias-' + Math.random().toString(36).slice(2);
        sugerencias.setAttribute('role', 'listbox');
        sugerencias.hidden = true;

        input.parentNode.insertBefore(contenedor, input);
        contenedor.appendChild(input);
        contenedor.appendChild(sugerencias);

        input.removeAttribute('list');
        input.placeholder = input.dataset.ubicacionPlaceholder || 'R_N_Z__';
        input.inputMode = 'numeric';
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', sugerencias.id);
        input.setAttribute('aria-expanded', 'false');

        const estado = {
            input: input,
            listaId: listaId,
            sugerencias: sugerencias,
            coincidencias: [],
            indiceActivo: -1,
            modo: 'texto',
            digitos: ''
        };

        estados.set(input, estado);

        input.addEventListener('keydown', function (evento) {
            if (
                /^[0-9]$/.test(evento.key)
                && !evento.ctrlKey
                && !evento.altKey
                && !evento.metaKey
            ) {
                const seleccionCompleta = input.selectionStart === 0
                    && input.selectionEnd === input.value.length;

                if (
                    seleccionCompleta
                    || input.value === ''
                    || estado.modo === 'numerico'
                    || esCapturaNumerica(input.value)
                ) {
                    evento.preventDefault();

                    if (seleccionCompleta || estado.modo !== 'numerico') {
                        estado.digitos = '';
                    }

                    estado.modo = 'numerico';

                    if (estado.digitos.length < 4) {
                        estado.digitos += evento.key;
                    }

                    renderizarMascara(estado);
                }
                return;
            }

            if (
                evento.key === 'Backspace'
                && (estado.modo === 'numerico' || esCapturaNumerica(input.value))
            ) {
                evento.preventDefault();
                estado.modo = 'numerico';
                estado.digitos = soloDigitos(input.value).slice(0, -1);
                renderizarMascara(estado);
                return;
            }

            if (
                evento.key === 'Delete'
                && (estado.modo === 'numerico' || esCapturaNumerica(input.value))
            ) {
                evento.preventDefault();
                estado.modo = 'numerico';
                estado.digitos = '';
                renderizarMascara(estado);
                return;
            }

            if (
                evento.key === 'ArrowDown'
                && estado.coincidencias.length > 0
            ) {
                evento.preventDefault();
                evento.stopPropagation();
                estado.indiceActivo = Math.min(
                    estado.indiceActivo + 1,
                    estado.coincidencias.length - 1
                );
                marcarActivo(estado);
                return;
            }

            if (
                evento.key === 'ArrowUp'
                && estado.coincidencias.length > 0
            ) {
                evento.preventDefault();
                evento.stopPropagation();
                estado.indiceActivo = Math.max(estado.indiceActivo - 1, 0);
                marcarActivo(estado);
                return;
            }

            if (
                evento.key === 'Enter'
                && !estado.sugerencias.hidden
                && estado.indiceActivo >= 0
            ) {
                evento.preventDefault();
                evento.stopPropagation();
                seleccionar(
                    estado,
                    estado.coincidencias[estado.indiceActivo],
                    'teclado'
                );
                return;
            }

            if (evento.key === 'Escape') {
                cerrarSugerencias(estado);
            }
        });

        input.addEventListener('input', function () {
            sincronizarDesdeValor(estado);
        });

        input.addEventListener('focus', function () {
            const valor = input.value.trim();

            if (
                valor !== ''
                && (
                    estado.modo === 'numerico'
                    || valor.includes('_')
                )
            ) {
                sincronizarDesdeValor(estado);
            }
        });

        input.addEventListener('blur', function () {
            window.setTimeout(function () {
                cerrarSugerencias(estado);
            }, 120);
        });

        if (input.value.trim() !== '') {
            const valorInicial = input.value.trim().toUpperCase();
            input.value = valorInicial;
            estado.modo = valorInicial.includes('_')
                ? 'numerico'
                : 'seleccionado';
            estado.digitos = soloDigitos(valorInicial);
        }
    }

    function inicializarTodos() {
        document.querySelectorAll(selector).forEach(inicializar);
    }

    document.addEventListener('submit', function (evento) {
        const formulario = evento.target;
        const campos = formulario.querySelectorAll(selector);

        for (const campo of campos) {
            const valor = resolverValor(campo, true);

            if (valor === null) {
                evento.preventDefault();
                campo.reportValidity();
                campo.focus();
                break;
            }
        }
    }, true);

    window.UbicacionesRapidas = {
        inicializar: inicializar,
        inicializarTodos: inicializarTodos,
        obtenerValor: function (input) {
            return resolverValor(input, true);
        },
        establecerValor: function (input, valor) {
            if (!estados.has(input)) {
                inicializar(input);
            }

            const estado = estados.get(input);
            input.value = String(valor || '').trim().toUpperCase();
            estado.modo = input.value === '' ? 'texto' : 'seleccionado';
            estado.digitos = soloDigitos(input.value);
            input.setCustomValidity('');
            input.classList.remove('ubicacion-rapida-incompleta');
            cerrarSugerencias(estado);
        },
        limpiar: function (input) {
            this.establecerValor(input, '');
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializarTodos);
    } else {
        inicializarTodos();
    }
})();
