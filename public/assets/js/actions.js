document.querySelectorAll('[data-back]').forEach(btn => {
    btn.addEventListener('click', () => history.back());
});

document.querySelectorAll('[data-autosubmit]').forEach(input => {
    input.addEventListener('change', () => input.form?.submit());
});
