<?php
/**
 * topbar.php
 * La barra de arriba, la de rentabilidad_rubro. El boton home va al HUB (en
 * rentabilidad_rubro iba a una IP fija del menu viejo).
 */
?>
<header class="ie-topbar">
    <a href="<?php echo $urlHub; ?>" class="ie-topbar-home" title="Volver al HUB">
        <i class="bi bi-house-door"></i>
    </a>

    <div class="ie-topbar-divider"></div>

    <div class="ie-topbar-title">
        <div class="ie-topbar-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
        <div class="ie-topbar-text">
            <h1>Informe Económico por Canal</h1>
            <span>Resumen de Control de Gastos — Administración &amp; Finanzas</span>
        </div>
    </div>

    <div class="ie-topbar-user">
        <?php echo htmlspecialchars($u['nombre'] ?: $u['username']); ?><br>
        <small><?php echo htmlspecialchars($u['rol_nombre']); ?></small>
    </div>

    <button id="ieBtnInfo" class="ie-info-btn" type="button" title="Acerca del informe">
        <i class="bi bi-info-circle"></i>
    </button>
</header>
