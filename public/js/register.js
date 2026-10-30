(function () {
    const welcome = document.getElementById('welcome-email');
    if (welcome instanceof HTMLElement) {
        welcome.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const form = document.getElementById('register-form');
    const error = document.querySelector('.msg.err');
    const button = form ? form.querySelector('button[type="submit"]') : null;
    const emailInput = document.getElementById('email');
    const emailStatus = document.getElementById('email-status');

    if (error instanceof HTMLElement) {
        error.focus();
    }

    if (!(form instanceof HTMLFormElement) || !(button instanceof HTMLButtonElement)) {
        return;
    }

    form.addEventListener('submit', function () {
        button.disabled = true;
        button.textContent = 'Registrando…';
    });

    if (!(emailInput instanceof HTMLInputElement) || !(emailStatus instanceof HTMLElement)) {
        return;
    }

    const esperaMs = 500;
    let temporizador = 0;
    let consulta = null;

    const limpiarEstado = function () {
        emailStatus.textContent = '';
        emailStatus.className = 'email-status';
    };

    const mostrarEstado = function (texto, clase) {
        emailStatus.textContent = texto;
        emailStatus.className = 'email-status ' + clase;
    };

    const correoListoParaConsultar = function () {
        const valor = emailInput.value.trim();
        if (valor === '') {
            return false;
        }

        return emailInput.checkValidity();
    };

    const consultarDisponibilidad = function (valorConsultado) {
        if (consulta !== null) {
            consulta.abort();
        }

        consulta = new AbortController();
        const url = '/?action=check-email&email=' + encodeURIComponent(valorConsultado);

        fetch(url, {
            method: 'GET',
            headers: { Accept: 'application/json' },
            signal: consulta.signal,
            cache: 'no-store',
        })
            .then(function (respuesta) {
                if (!respuesta.ok) {
                    throw new Error('http');
                }

                return respuesta.json();
            })
            .then(function (datos) {
                if (emailInput.value.trim() !== valorConsultado.trim()) {
                    return;
                }

                if (!datos || datos.ok !== true) {
                    button.disabled = false;
                    mostrarEstado(
                        (datos && datos.error) || 'No se pudo comprobar el correo electrónico. Inténtalo de nuevo.',
                        'is-error'
                    );
                    return;
                }

                if (datos.available === false) {
                    button.disabled = true;
                    mostrarEstado('Este correo electrónico ya está registrado', 'is-taken');
                    return;
                }

                if (datos.available === true) {
                    button.disabled = false;
                    mostrarEstado('Este correo electrónico está disponible', 'is-available');
                    return;
                }

                button.disabled = false;
                limpiarEstado();
            })
            .catch(function (errorConsulta) {
                if (errorConsulta && errorConsulta.name === 'AbortError') {
                    return;
                }

                if (emailInput.value.trim() !== valorConsultado.trim()) {
                    return;
                }

                button.disabled = false;
                mostrarEstado('No se pudo comprobar el correo electrónico. Inténtalo de nuevo.', 'is-error');
            });
    };

    emailInput.addEventListener('input', function () {
        window.clearTimeout(temporizador);

        if (consulta !== null) {
            consulta.abort();
            consulta = null;
        }

        if (!correoListoParaConsultar()) {
            button.disabled = false;
            limpiarEstado();
            return;
        }

        temporizador = window.setTimeout(function () {
            consultarDisponibilidad(emailInput.value);
        }, esperaMs);
    });
})();
