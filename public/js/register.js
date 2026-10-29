(function () {
    const welcome = document.getElementById('welcome-email');
    if (welcome instanceof HTMLElement) {
        welcome.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const form = document.getElementById('register-form');
    const error = document.querySelector('.msg.err');
    const button = form ? form.querySelector('button[type="submit"]') : null;

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
})();
