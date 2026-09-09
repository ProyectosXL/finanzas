<?php
    /**
     * Menú lateral.
     *
     * La lista de pestañas y el estado de cada una salen de Class/Menu.php: acá
     * sólo se dibuja. Antes eran veintiséis enlaces escritos a mano e iguales
     * entre sí, y por eso no se podía ver de un vistazo qué está hecho.
     *
     * TRES ESTADOS, no dos. El del medio es el que importa: una pestaña puede
     * estar dibujada y tener los números escritos a mano (el Dashboard), lo que
     * es peor que un placeholder — un placeholder avisa, una maqueta se lee como
     * un dato real. Ver la nota del encabezado de Class/Menu.php.
     */
    require_once __DIR__ . '/../Class/Menu.php';

    $menu = Menu::estructura();

    /**
     * Un enlace del menú, con la marca de su estado.
     *
     * @param array $item Item resuelto por Menu::estructura()
     * @param bool $activo Si arranca marcado como activo
     * @return string
     */
    function menuLink($item, $activo = false) {
        $clases = 'menu-link menu-' . $item['estado'] . ($activo ? ' active' : '');

        $html = '<a href="#" class="' . $clases . '" data-tab="'
            . htmlspecialchars($item['tab']) . '"'
            . ($item['titulo'] !== '' ? ' title="' . htmlspecialchars($item['titulo']) . '"' : '')
            . '>'
            . '<i class="fas ' . htmlspecialchars($item['icono']) . ' menu-icono"></i>'
            . '<span>' . htmlspecialchars($item['nombre']) . '</span>';

        // La marca del estado va a la derecha y sólo cuando hay algo que decir:
        // una pestaña con datos es el caso normal y no se marca.
        if ($item['icono_estado'] !== '') {
            $html .= '<i class="fas ' . htmlspecialchars($item['icono_estado']) . ' menu-marca"></i>';
        }

        return $html . '</a>';
    }
?>
<!-- Sidebar Toggle Button (visible when collapsed) -->
<button class="sidebar-toggle" id="sidebarToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Overlay (for mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <i class="fas fa-chart-line"></i>
            <span class="brand-text">Flujo de Fondos</span>
        </div>
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Contraer sidebar">
            <i class="fas fa-chevron-left"></i>
        </button>
        <button class="sidebar-close" id="sidebarClose">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div class="sidebar-menu">

        <!-- Pestañas de nivel raíz. Cashflow es la que carga index.php. -->
        <?php foreach ($menu['principales'] as $i => $item): ?>
            <div class="menu-item-main">
                <?php echo menuLink($item, $i === 0); ?>
            </div>
        <?php endforeach; ?>

        <div class="menu-divider"></div>

        <!-- Categorías.
             El contador dice cuántas pestañas de la categoría tienen datos, para
             ver el avance sin abrirla. -->
        <?php foreach ($menu['categorias'] as $cat): ?>
            <div class="menu-category">
                <a href="#" class="category-header<?php echo $cat['abierta'] ? '' : ' collapsed'; ?>"
                   data-bs-toggle="collapse" data-bs-target="#menu<?php echo $cat['codigo']; ?>">
                    <div class="category-title">
                        <i class="fas <?php echo htmlspecialchars($cat['icono']); ?>"></i>
                        <span><?php echo htmlspecialchars($cat['nombre']); ?></span>
                        <span class="category-avance<?php echo $cat['con_datos'] === 0 ? ' avance-vacio' : ''; ?>"
                              title="<?php echo $cat['con_datos']; ?> de <?php echo $cat['total']; ?> pestañas con datos del sistema">
                            <?php echo $cat['con_datos']; ?>/<?php echo $cat['total']; ?>
                        </span>
                    </div>
                    <i class="fas fa-chevron-down category-arrow"></i>
                </a>
                <div class="collapse<?php echo $cat['abierta'] ? ' show' : ''; ?>"
                     id="menu<?php echo $cat['codigo']; ?>">
                    <ul class="category-items">
                        <?php foreach ($cat['items'] as $item): ?>
                            <li><?php echo menuLink($item); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Al pie: Parámetros. No es un módulo de datos como los de arriba, es
             la configuración de todos ellos. -->
        <div class="menu-divider"></div>

        <?php foreach ($menu['pie'] as $item): ?>
            <div class="menu-item-main">
                <?php echo menuLink($item); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Referencia de las marcas. Sin esto, los íconos de la derecha son
         adornos: hay que decir qué significan. -->
    <div class="sidebar-leyenda">
        <span class="leyenda-item">
            <i class="fas fa-pen-ruler"></i> Maqueta
        </span>
        <span class="leyenda-item">
            <i class="fas fa-hard-hat"></i> Pendiente
        </span>
    </div>

    <div class="sidebar-footer">
        <span class="version-text">v1.0.0</span>
    </div>
</nav>
