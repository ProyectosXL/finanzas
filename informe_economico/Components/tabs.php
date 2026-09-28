<?php
/**
 * tabs.php
 * Las solapas, desde Menu::permitidas(): una solapa sin permiso no se dibuja.
 */
?>
<nav class="ie-tabs" id="ieTabs" role="tablist" aria-label="Pestañas del informe">
    <?php foreach ($pestanas as $i => $p): ?>
        <button class="ie-tab<?php echo $i === 0 ? ' active' : ''; ?>" role="tab" type="button"
                data-tab="<?php echo htmlspecialchars($p['tab']); ?>"
                data-filtros="<?php echo $p['filtros'] ? '1' : '0'; ?>">
            <i class="bi <?php echo htmlspecialchars($p['icono']); ?>"></i>
            <?php echo htmlspecialchars($p['nombre']); ?>
        </button>
    <?php endforeach; ?>
</nav>
