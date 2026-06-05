    <!-- ══════════════ FOOTER ══════════════ -->
    <footer class="lm-footer mt-auto py-4">
        <div class="container-fluid px-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <span class="lm-brand-text small">Libre<strong>Mercado</strong></span>
                    <span class="text-muted ms-2 small">&mdash; Sistema de Comercio Electrónico Distribuido</span>
                </div>
                <div class="col-md-6 text-md-end text-muted small mt-2 mt-md-0">
                    Sistemas Distribuidos &bull; Juan Torres O. &bull; <?= date('Y') ?>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Script global -->
    <script>
    // Confirmación antes de eliminar
    document.querySelectorAll('.btn-eliminar').forEach(btn => {
        btn.addEventListener('click', e => {
            if (!confirm('¿Confirmar eliminación? Esta acción no se puede deshacer.')) {
                e.preventDefault();
            }
        });
    });

    // Auto-ocultar alertas después de 4 s
    setTimeout(() => {
        document.querySelectorAll('.alert-auto').forEach(el => {
            new bootstrap.Alert(el).close();
        });
    }, 4000);
    </script>
</body>
</html>
