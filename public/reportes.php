<?php
require_once __DIR__ . '/../app/helpers/auth.php';
requireLogin();
$moduleCss = 'reportes';
include __DIR__ . '/../app/views/layouts/header.php';
?>

<div class="page-card">
    <h2>Reportes</h2>
    <p>Aquí irá el módulo de reportes.</p>
</div>

<?php include __DIR__ . '/../app/views/layouts/footer.php'; ?>