(function () {
    const dialogo = document.getElementById('confirm-delete');
    const texto = document.getElementById('confirm-delete-text');
    const cancelar = document.getElementById('confirm-delete-cancel');
    const confirmar = document.getElementById('confirm-delete-ok');

    if (
        !(dialogo instanceof HTMLDialogElement)
        || !(texto instanceof HTMLElement)
        || !(cancelar instanceof HTMLButtonElement)
        || !(confirmar instanceof HTMLButtonElement)
    ) {
        return;
    }

    let formularioPendiente = null;

    const cerrar = function () {
        formularioPendiente = null;
        if (dialogo.open) {
            dialogo.close();
        }
    };

    document.querySelectorAll('form.js-delete-user').forEach(function (formulario) {
        if (!(formulario instanceof HTMLFormElement)) {
            return;
        }

        formulario.addEventListener('submit', function (evento) {
            evento.preventDefault();
            formularioPendiente = formulario;

            const nombre = formulario.getAttribute('data-name') || 'este usuario';
            const email = formulario.getAttribute('data-email') || '';
            texto.textContent = email === ''
                ? '¿Eliminar a ' + nombre + '? Esta acción no se puede deshacer.'
                : '¿Eliminar a ' + nombre + ' (' + email + ')? Esta acción no se puede deshacer.';

            dialogo.showModal();
            confirmar.focus();
        });
    });

    cancelar.addEventListener('click', cerrar);

    confirmar.addEventListener('click', function () {
        const formulario = formularioPendiente;
        formularioPendiente = null;
        dialogo.close();

        if (formulario instanceof HTMLFormElement) {
            formulario.submit();
        }
    });

    dialogo.addEventListener('cancel', function () {
        formularioPendiente = null;
    });
})();
