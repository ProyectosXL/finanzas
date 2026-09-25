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

        // data-encabezado es el título de la página: main.js lo lee de acá al
        // cargar la pestaña, así el menú es la única lista de nombres.
        $html = '<a href="#" class="' . $clases . '" data-tab="'
            . htmlspecialchars($item['tab']) . '"'
            . ' data-encabezado="' . htmlspecialchars($item['encabezado']) . '"'
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

        <!-- Pestañas de nivel raíz -->
        <?php foreach ($menu['principales'] as $item): ?>
            <div class="menu-item-main">
                <?php echo menuLink($item, $item['tab'] === $tabInicial); ?>
            </div>
        <?php endforeach; ?>

        <?php if(!empty($menu['principales']) && !empty($menu['categorias'])): ?>
            <div class="menu-divider"></div>
        <?php endif; ?>

        <!-- Categorías permitidas -->
        <?php foreach ($menu['categorias'] as $cat): ?>
            <?php 
                // Verificar si esta categoría contiene el tab activo
                $tieneTabActivo = false;
                foreach($cat['items'] as $it) {
                    if ($it['tab'] === $tabInicial) { $tieneTabActivo = true; break; }
                }
                $catAbierta = $cat['abierta'] || $tieneTabActivo;
            ?>
            <div class="menu-category">
                <a href="#" class="category-header<?php echo $catAbierta ? '' : ' collapsed'; ?>"
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
                <div class="collapse<?php echo $catAbierta ? ' show' : ''; ?>"
                     id="menu<?php echo $cat['codigo']; ?>">
                    <ul class="category-items">
                        <?php foreach ($cat['items'] as $item): ?>
                            <li><?php echo menuLink($item, $item['tab'] === $tabInicial); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Al pie: Parámetros / Configuración -->
        <?php if(!empty($menu['pie'])): ?>
            <div class="menu-divider"></div>
            <?php foreach ($menu['pie'] as $item): ?>
                <div class="menu-item-main">
                    <?php echo menuLink($item, $item['tab'] === $tabInicial); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
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
