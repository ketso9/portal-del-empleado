<?php
defined('ABSPATH') || exit;

/**
 * Clase que registra e implementa la Mini App de Control de Gastos y Dietas en el Portal del Empleado
 */
class EP_App_Expenses implements EP_App_Interface
{
    public function get_id()
    {
        return 'expenses';
    }

    public function get_name()
    {
        return 'Gastos y Dietas';
    }

    public function get_icon()
    {
        return 'fa-solid fa-file-invoice-dollar';
    }

    public function get_menu_label()
    {
        return 'Gastos y Dietas';
    }

    /**
     * Renderiza la tarjeta en el Dashboard principal
     */
    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=expenses'">
            <div class="app-icon-container color-green">
                <i class="fa-solid fa-file-invoice-dollar"></i>
            </div>
            <h3>Gastos y Dietas</h3>
            <p>Justificación de tickets de compra y dietas</p>
        </div>
        <?php
    }

    /**
     * Renderiza la vista principal dentro del portal
     */
    public function render_full_view()
    {
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;

        // Modelo de permisos centralizado en EP_Expenses::get_user_context()
        $ctx = class_exists('EP_Expenses')
            ? EP_Expenses::get_user_context($user_id)
            : array('has_access' => true, 'can_view_all' => false, 'can_configure' => false);

        $user_perm = isset($ctx['perm']) ? $ctx['perm'] : 'read';

        if (empty($ctx['has_access'])) {
            echo '<div class="ep-alert ep-alert-danger" style="margin:20px; padding:20px; border-radius:10px; background:#ffebee; color:#c62828; border:1px solid #ef5350; text-align:center;"><i class="fa-solid fa-lock" style="font-size:24px; margin-bottom:10px; display:block;"></i> <strong>Acceso Denegado:</strong> No tienes permisos para acceder a la aplicación de Control de Gastos y Dietas. Contacta con Administración si crees que se trata de un error.</div>';
            return;
        }

        // Ve la columna de empleado y el listado completo quien gestiona gastos
        // (Dirección, Dpto. Administración, permiso de escritura o admin de WordPress).
        $is_admin = !empty($ctx['can_view_all']);
        // La pestaña de configuración global es más restrictiva que el simple "ver todo".
        $can_configure = !empty($ctx['can_configure']);

        // Obtener configuraciones de retenciones y sedes
        $workers_config = get_option('ep_expenses_workers_config', []);
        $numbering_config = get_option('ep_expenses_numbering_config', []);
        $km_exempt_limit = get_option('ep_expenses_km_exempt_limit', '0.26');

        $current_user_config = isset($workers_config[$user_id]) ? $workers_config[$user_id] : [
            'irpf' => 2.00,
            'km_rate' => 0.25,
            'ss' => 1.00
        ];

        // Output variables inline en JS
        echo '<script>';
        echo 'window.ep_expenses_can_manage = ' . ($is_admin ? 'true' : 'false') . ';';
        echo 'window.ep_current_user_config = ' . wp_json_encode($current_user_config) . ';';
        echo 'window.ep_km_exempt_limit = ' . floatval($km_exempt_limit) . ';';
        echo 'window.ep_numbering_config = ' . wp_json_encode($numbering_config) . ';';
        echo '</script>';

        $current_year  = date('Y');
        $current_month = date('m');

        // Obtener todos los usuarios si es administrador para el selector de filtro por empleado
        $all_employees = array();
        if ($is_admin) {
            $all_employees = get_users(array(
                'fields'  => array('ID', 'display_name', 'user_email'),
                'orderby' => 'display_name',
                'order'   => 'ASC'
            ));
        }

        ?>
        <div class="ep-expenses-app">
            <!-- Header con botón Volver y Título acorde al diseño global -->
            <div class="ep-app-header">
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <a href="?view=dashboard" class="ep-btn-back"><i class="fa-solid fa-arrow-left"></i> Volver</a>
                    <h2><i class="fa-solid fa-file-invoice-dollar"></i> Control de Gastos y Dietas</h2>
                </div>
                <div class="ep-app-header-actions" id="ep-header-actions-area">
                    <?php if ($is_admin): ?>
                        <button id="ep-btn-export-xlsx" class="ep-btn ep-btn-secondary" title="Exportar a Excel">
                            <i class="fa-solid fa-file-excel"></i> Exportar a Excel
                        </button>
                    <?php endif; ?>
                    <button id="ep-btn-new-expense" class="ep-btn ep-btn-primary">
                        <i class="fa-solid fa-plus-circle"></i> Nuevo Registro
                    </button>
                </div>
            </div>

            <!-- Tabs de navegación principal (Simplificado, se eliminó la pestaña de Liquidaciones) -->
            <div class="ep-expenses-tabs">
                <button class="ep-tab-btn active" data-tab="tab-tickets">
                    <i class="fa-solid fa-receipt"></i> Tickets y Gastos
                </button>
                <?php if ($can_configure): ?>
                    <button class="ep-tab-btn" data-tab="tab-config">
                        <i class="fa-solid fa-gears"></i> Configuración de Administración
                    </button>
                <?php endif; ?>
            </div>

            <!-- CONTENIDO TAB 1: TICKETS Y GASTOS (CON LISTADO UNIFICADO) -->
            <div id="tab-tickets" class="ep-tab-content active">
                <!-- Banner Informativo Resumen del Mes (Día 28) -->
                <div id="ep-expenses-closure-banner" class="ep-card ep-alert-closure-card glass" style="display: none; margin-bottom: 20px;">
                    <div class="closure-banner-content">
                        <div class="closure-banner-icon">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>
                        <div class="closure-banner-text">
                            <h4>Resumen Informativo del Mes (Cierre de Periodo)</h4>
                            <p id="closure-banner-message">Resumen de estado de tickets del mes...</p>
                            <button type="button" id="ep-btn-open-closure" class="ep-btn ep-btn-primary ep-btn-sm" style="display:none; margin-top:10px;">
                                <i class="fa-solid fa-calendar-check"></i> Declarar cierre del periodo
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Tarjetas Resumen KPI -->
                <div class="ep-expenses-kpi-grid">
                    <div class="ep-card glass kpi-card">
                        <div class="kpi-icon color-green"><i class="fa-solid fa-receipt"></i></div>
                        <div class="kpi-data">
                            <span class="kpi-title">Total Registros (Mes)</span>
                            <h3 id="kpi-total-tickets">0</h3>
                        </div>
                    </div>
                    <div class="ep-card glass kpi-card">
                        <div class="kpi-icon color-blue"><i class="fa-solid fa-euro-sign"></i></div>
                        <div class="kpi-data">
                            <span class="kpi-title">Total Importe (Mes)</span>
                            <h3 id="kpi-total-amount">0.00 €</h3>
                        </div>
                    </div>
                    <div class="ep-card glass kpi-card">
                        <div class="kpi-icon color-purple"><i class="fa-solid fa-money-bill-wave"></i></div>
                        <div class="kpi-data">
                            <span class="kpi-title">Dietas y Viajes</span>
                            <h3 id="kpi-total-dietas">0.00 €</h3>
                        </div>
                    </div>
                    <div class="ep-card glass kpi-card">
                        <div class="kpi-icon color-orange"><i class="fa-solid fa-shield-halved"></i></div>
                        <div class="kpi-data">
                            <span class="kpi-title">Estado de Cierre</span>
                            <h3 id="kpi-closure-status"><span class="ep-badge ep-badge-warning">Pendiente</span></h3>
                        </div>
                    </div>
                </div>

                <!-- Filtros de búsqueda tickets -->
                <div class="ep-card glass ep-expenses-filters-card" style="margin-top: 20px; margin-bottom: 20px;">
                    <form id="ep-expenses-filter-form" class="ep-expenses-filter-grid">
                        <div class="filter-group">
                            <label><i class="fa-solid fa-calendar"></i> Año</label>
                            <select id="filter-year" name="year" class="ep-select">
                                <?php 
                                for ($y = intval($current_year); $y >= intval($current_year) - 3; $y--) {
                                    echo '<option value="' . $y . '" ' . selected($y, $current_year, false) . '>' . $y . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fa-solid fa-calendar-days"></i> Mes</label>
                            <select id="filter-month" name="month" class="ep-select">
                                <option value="">Todos los meses</option>
                                <?php
                                $meses = array(
                                    '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo',
                                    '04' => 'Abril', '05' => 'Mayo', '06' => 'Junio',
                                    '07' => 'Julio', '08' => 'Agosto', '09' => 'Septiembre',
                                    '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
                                );
                                foreach ($meses as $num => $nombre) {
                                    echo '<option value="' . $num . '" ' . selected($num, $current_month, false) . '>' . $nombre . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label><i class="fa-solid fa-tags"></i> Categoría / Justificación</label>
                            <select id="filter-category" name="category" class="ep-select">
                                <option value="">Todas las categorías</option>
                                <option value="dieta">Liquidación de Viaje y Dietas</option>
                                <option value="transporte_peaje">Taxi / Parking / Uber / Tren / Peaje</option>
                                <option value="material">Material / Suministros</option>
                                <option value="otros">Otros Gastos</option>
                            </select>
                        </div>

                        <?php if ($is_admin): ?>
                            <div class="filter-group">
                                <label><i class="fa-solid fa-user-gear"></i> Empleado (Vista Admin)</label>
                                <select id="filter-user-id" name="user_id" class="ep-select">
                                    <option value="">Todos los empleados</option>
                                    <?php foreach ($all_employees as $emp): ?>
                                        <option value="<?php echo intval($emp->ID); ?>"><?php echo esc_html($emp->display_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="filter-group filter-actions">
                            <button type="submit" class="ep-btn ep-btn-secondary"><i class="fa-solid fa-filter"></i> Filtrar</button>
                            <button type="button" id="ep-btn-reset-filters" class="ep-btn ep-btn-light" title="Limpiar Filtros"><i class="fa-solid fa-rotate-left"></i></button>
                        </div>
                    </form>
                </div>

                <!-- Tabla de Registros Unificada (Tickets y Liquidaciones juntas) -->
                <div class="ep-card glass full-width">
                    <div class="ep-card-header">
                        <h3><i class="fa-solid fa-list-check"></i> Listado Unificado de Gastos, Viajes y Dietas</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="ep-table" id="ep-expenses-table">
                            <thead>
                                <tr>
                                    <th>Nº Justificante</th>
                                    <th>Fecha</th>
                                    <?php if ($is_admin): ?><th>Empleado</th><?php endif; ?>
                                    <th>Concepto / Destino</th>
                                    <th>Tipo Justificación</th>
                                    <th>Forma Pago</th>
                                    <th>Importe</th>
                                    <th>Adjunto / PDF</th>
                                    <th>Estado / Firma</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="ep-expenses-table-body">
                                <tr>
                                    <td colspan="<?php echo $is_admin ? 10 : 9; ?>" class="text-center"><i class="fa-solid fa-spinner fa-spin"></i> Cargando justificantes...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- CONTENIDO TAB 3: CONFIGURACIÓN DE ADMINISTRACIÓN (Solo Admin) -->
            <?php if ($can_configure): ?>
                <div id="tab-config" class="ep-tab-content">
                    <div class="ep-card ep-alert ep-alert-warning glass" style="margin-bottom: 20px; border-left: 4px solid #f59e0b; background: rgba(245, 158, 11, 0.08); padding: 15px 20px; border-radius: 8px;">
                        <div style="display: flex; gap: 15px; align-items: flex-start;">
                            <i class="fa-solid fa-triangle-exclamation" style="font-size: 24px; color: #d97706; margin-top: 2px;"></i>
                            <div>
                                <h4 style="color: #b45309; margin-bottom: 5px; font-weight: 700;">Aviso Importante sobre los Cambios de Retención</h4>
                                <p style="color: #78350f; font-size: 13px; line-height: 1.5; margin: 0;">
                                    Los cambios realizados en el IRPF %, el % de Seguridad Social, el precio del Km de los trabajadores, o el límite exento global se aplicarán <strong>únicamente a las nuevas liquidaciones creadas</strong> a partir de este momento. Las liquidaciones antiguas que ya hayan sido registradas, firmadas o archivadas no se verán afectadas para garantizar la coherencia e integridad de los datos históricos.
                                </p>
                            </div>
                        </div>
                    </div>

                    <form id="ep-admin-config-form">
                        <!-- Guardar cambios superior rápido -->
                        <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
                            <button type="submit" class="ep-btn ep-btn-primary" style="padding: 10px 25px; font-size: 14px; font-weight: 600;">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Guardar Todos los Cambios
                            </button>
                        </div>

                        <!-- Bloque: Límites y Sedes -->
                        <div class="ep-grid-layout" style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 20px;">
                            <!-- Límite Exento de Km -->
                            <div class="ep-card glass">
                                <div class="ep-card-header">
                                    <h3><i class="fa-solid fa-scale-balanced text-primary"></i> Parámetro Legal Global</h3>
                                </div>
                                <div style="padding: 15px;">
                                    <div class="form-group">
                                        <label for="cfg-km-exempt-limit">Límite Km Exento por Ley (€/Km) *</label>
                                        <input type="number" step="0.001" min="0.001" id="cfg-km-exempt-limit" name="km_exempt_limit" class="ep-input" value="<?php echo esc_attr($km_exempt_limit); ?>" required>
                                        <small class="text-muted" style="display: block; margin-top: 5px; font-size: 11px;">En España, actualmente el límite de kilometraje exento de IRPF y SS es de <strong>0,26 €/Km</strong>.</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Configuración de las Sedes -->
                            <div class="ep-card glass">
                                <div class="ep-card-header" style="display: flex; justify-content: space-between; align-items: center;">
                                    <h3><i class="fa-solid fa-map-location-dot text-primary"></i> Numeración Única por Sede</h3>
                                    <button type="button" class="ep-btn ep-btn-sm ep-btn-primary" id="btn-add-sede" style="padding: 4px 10px; font-size: 12px;"><i class="fa-solid fa-plus"></i> Añadir Sede</button>
                                </div>
                                <div style="padding: 15px;">
                                    <div class="table-responsive">
                                        <table class="ep-table" style="min-width: 100%;" id="cfg-sedes-table">
                                            <thead>
                                                <tr>
                                                    <th style="width: 45%;">Nombre de la Sede</th>
                                                    <th style="width: 25%;">Prefijo Serie (ej: MAD-)</th>
                                                    <th style="width: 20%;">Próximo Número</th>
                                                    <th style="width: 10%; text-align: center;">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody id="cfg-sedes-tbody">
                                                <?php 
                                                $has_sedes = false;
                                                if (is_array($numbering_config) && !empty($numbering_config)) {
                                                    foreach ($numbering_config as $index => $sede) {
                                                        if (empty($sede) || !is_array($sede)) continue;
                                                        $has_sedes = true;
                                                        ?>
                                                        <tr class="sede-row">
                                                            <td>
                                                                <input type="text" class="ep-input cfg-sede-name" value="<?php echo esc_attr($sede['name']); ?>" required placeholder="Ej. Sede Cáceres...">
                                                            </td>
                                                            <td>
                                                                <input type="text" class="ep-input cfg-sede-prefix" value="<?php echo esc_attr($sede['prefix']); ?>" required placeholder="Ej. CAC-">
                                                            </td>
                                                            <td>
                                                                <input type="number" min="1" class="ep-input cfg-sede-next" value="<?php echo intval($sede['next_number']); ?>" required>
                                                            </td>
                                                            <td class="text-center">
                                                                <button type="button" class="ep-btn ep-btn-sm ep-btn-light btn-delete-sede" style="color: #ef4444; padding: 4px 8px;" title="Eliminar Sede"><i class="fa-solid fa-trash"></i></button>
                                                            </td>
                                                        </tr>
                                                        <?php
                                                    }
                                                }
                                                if (!$has_sedes) {
                                                    ?>
                                                    <tr class="sede-row">
                                                        <td>
                                                            <input type="text" class="ep-input cfg-sede-name" value="Sede Cáceres" required placeholder="Ej. Sede Cáceres...">
                                                        </td>
                                                        <td>
                                                            <input type="text" class="ep-input cfg-sede-prefix" value="CAC-" required placeholder="Ej. CAC-">
                                                        </td>
                                                        <td>
                                                            <input type="number" min="1" class="ep-input cfg-sede-next" value="1" required>
                                                        </td>
                                                        <td class="text-center">
                                                            <button type="button" class="ep-btn ep-btn-sm ep-btn-light btn-delete-sede" style="color: #ef4444; padding: 4px 8px;" title="Eliminar Sede"><i class="fa-solid fa-trash"></i></button>
                                                        </td>
                                                    </tr>
                                                    <?php
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bloque: Trabajadores -->
                        <div class="ep-card glass full-width" style="margin-bottom: 20px;">
                            <div class="ep-card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                                <h3><i class="fa-solid fa-users-gear text-primary"></i> Retenciones e Importe de Km por Trabajador</h3>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <i class="fa-solid fa-magnifying-glass text-muted"></i>
                                    <input type="text" id="cfg-worker-search" class="ep-input" style="max-width: 250px; padding: 6px 12px; font-size: 13px;" placeholder="Buscar empleado...">
                                </div>
                            </div>
                            <div style="padding: 15px;">
                                <!-- Bloque de cambios masivos -->
                                <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 20px; background: rgba(0,0,0,0.02); padding: 12px 18px; border-radius: 8px; border: 1px solid var(--ep-border); flex-wrap: wrap;">
                                    <span style="font-weight: 700; font-size: 13px; color: var(--ep-text-muted);"><i class="fa-solid fa-users-gear text-primary"></i> Cambio Masivo (Todos los empleados):</span>
                                    <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                        <div style="display: flex; align-items: center; gap: 5px;">
                                            <label style="font-size: 12px; color: var(--ep-text-muted);">Precio Km:</label>
                                            <input type="number" step="0.01" min="0" id="bulk-km-rate" class="ep-input" style="width: 75px; padding: 4px 8px; font-size: 12px;" placeholder="0.28">
                                            <button type="button" id="btn-bulk-apply-km" class="ep-btn ep-btn-sm ep-btn-light" style="padding: 4px 8px; font-size: 12px;">Aplicar</button>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 5px; border-left: 1px solid var(--ep-border); padding-left: 12px;">
                                            <label style="font-size: 12px; color: var(--ep-text-muted);">IRPF %:</label>
                                            <input type="number" step="0.01" min="0" id="bulk-irpf" class="ep-input" style="width: 75px; padding: 4px 8px; font-size: 12px;" placeholder="2.00">
                                            <button type="button" id="btn-bulk-apply-irpf" class="ep-btn ep-btn-sm ep-btn-light" style="padding: 4px 8px; font-size: 12px;">Aplicar</button>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 5px; border-left: 1px solid var(--ep-border); padding-left: 12px;">
                                            <label style="font-size: 12px; color: var(--ep-text-muted);">S. Social %:</label>
                                            <input type="number" step="0.01" min="0" id="bulk-ss" class="ep-input" style="width: 75px; padding: 4px 8px; font-size: 12px;" placeholder="1.00">
                                            <button type="button" id="btn-bulk-apply-ss" class="ep-btn ep-btn-sm ep-btn-light" style="padding: 4px 8px; font-size: 12px;">Aplicar</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="ep-table" id="cfg-workers-table">
                                        <thead>
                                            <tr>
                                                <th>Empleado</th>
                                                <th>Email</th>
                                                <th style="width: 20%;">Tasa de Desplazamiento (€/Km)</th>
                                                <th style="width: 20%;">Retención I.R.P.F. (%)</th>
                                                <th style="width: 20%;">Seguridad Social (%)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($all_employees as $emp): 
                                                $uid = $emp->ID;
                                                $u_conf = isset($workers_config[$uid]) ? $workers_config[$uid] : [
                                                    'irpf' => 2.00,
                                                    'km_rate' => 0.25,
                                                    'ss' => 1.00
                                                ];
                                            ?>
                                                <tr class="worker-row" data-name="<?php echo esc_attr(strtolower($emp->display_name)); ?>">
                                                    <td><strong><?php echo esc_html($emp->display_name); ?></strong></td>
                                                    <td><small class="text-muted"><?php echo esc_html($emp->user_email); ?></small></td>
                                                    <td>
                                                        <div style="display: flex; align-items: center; gap: 5px;">
                                                            <input type="number" step="0.01" min="0.00" class="ep-input cfg-w-km" data-uid="<?php echo $uid; ?>" value="<?php echo esc_attr($u_conf['km_rate']); ?>" required>
                                                            <span>€</span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div style="display: flex; align-items: center; gap: 5px;">
                                                            <input type="number" step="0.01" min="0.00" class="ep-input cfg-w-irpf" data-uid="<?php echo $uid; ?>" value="<?php echo esc_attr($u_conf['irpf']); ?>" required>
                                                            <span>%</span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div style="display: flex; align-items: center; gap: 5px;">
                                                            <input type="number" step="0.01" min="0.00" class="ep-input cfg-w-ss" data-uid="<?php echo $uid; ?>" value="<?php echo esc_attr($u_conf['ss']); ?>" required>
                                                            <span>%</span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Footer Formulario de Configuración -->
                        <div style="display: flex; justify-content: flex-end; margin-bottom: 30px;">
                            <button type="submit" class="ep-btn ep-btn-primary" id="ep-btn-save-config" style="padding: 12px 30px; font-size: 15px;">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Guardar Todos los Cambios
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($is_admin): ?>
        <!-- MODAL: EXPORTACIÓN A EXCEL -->
        <div id="ep-modal-export" class="ep-modal-backdrop" style="display:none;">
            <div class="ep-modal-content glass" style="max-width: 560px;">
                <div class="ep-modal-header">
                    <h3><i class="fa-solid fa-file-excel text-success"></i> Exportar gastos a Excel</h3>
                    <button class="ep-modal-close" onclick="EP_Expenses_App.closeExportModal()">&times;</button>
                </div>
                <div class="ep-modal-body">
                    <p style="margin-bottom: 15px;">Selecciona el periodo que quieres incluir en el listado:</p>

                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="radio" name="ep_export_periodo" value="1m" checked> Último mes
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="radio" name="ep_export_periodo" value="3m"> Últimos 3 meses
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="radio" name="ep_export_periodo" value="6m"> Últimos 6 meses
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="radio" name="ep_export_periodo" value="ytd"> Año en curso (<?php echo date('Y'); ?>)
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="radio" name="ep_export_periodo" value="years"> Años concretos
                        </label>
                    </div>

                    <div id="ep-export-years" style="display:none; margin-top:15px; padding:12px; background:rgba(0,0,0,0.03); border:1px solid var(--ep-border); border-radius:8px;">
                        <label style="font-weight:600; display:block; margin-bottom:8px; font-size:13px;">
                            Marca hasta 5 años
                        </label>
                        <div style="display:flex; flex-wrap:wrap; gap:12px;">
                            <?php
                            // Solo años ya cerrados: el año en curso tiene su propia
                            // opción arriba. Así, el 1 de enero el primero de la lista
                            // es el año que acaba de terminar.
                            $anio_actual = intval(date('Y'));
                            for ($y = $anio_actual - 1; $y >= $anio_actual - 8; $y--) {
                                echo '<label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:normal;">'
                                    . '<input type="checkbox" class="ep-export-anio" value="' . $y . '"> ' . $y
                                    . '</label>';
                            }
                            ?>
                        </div>
                        <small class="text-muted" id="ep-export-years-aviso" style="display:block; margin-top:8px; font-size:11px;">
                            Ningún año seleccionado.
                        </small>
                    </div>

                    <small class="text-muted" style="display:block; margin-top:15px; font-size:11px; line-height:1.5;">
                        El fichero incluye tickets y liquidaciones de viaje de toda la plantilla, con su estado,
                        trazabilidad de autorización y abono, y el desglose de kilometraje y retenciones.
                    </small>
                </div>
                <div class="ep-modal-footer">
                    <button type="button" class="ep-btn ep-btn-light" onclick="EP_Expenses_App.closeExportModal()">Cancelar</button>
                    <button type="button" class="ep-btn ep-btn-success" id="ep-btn-confirm-export">
                        <i class="fa-solid fa-download"></i> Descargar Excel
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- MODAL ÚNICO UNIFICADO: AÑADIR / EDITAR REGISTRO -->
        <div id="ep-modal-backdrop-unified" class="ep-modal-backdrop" style="display:none;">
            <div class="ep-modal-content glass" style="max-width: 900px; width: 94%;">
                <div class="ep-modal-header">
                    <h3 id="ep-expense-modal-title"><i class="fa-solid fa-file-circle-plus"></i> Registrar Gasto / Justificante</h3>
                    <button class="ep-modal-close" onclick="EP_Expenses_App.closeExpenseModal()">&times;</button>
                </div>
                <form id="ep-expense-form" enctype="multipart/form-data">
                    <input type="hidden" id="expense_id" name="id" value="">
                    
                    <div class="ep-modal-body" style="max-height: 72vh; overflow-y: auto; padding: 20px;">
                        
                        <!-- Dropdown de Selección del Tipo de Justificación / Gasto (Único selector que determina la lógica) -->
                        <div class="form-group full-width" style="margin-bottom: 20px; background: rgba(15, 23, 42, 0.04); padding: 12px; border-radius: 6px; border: 1px solid var(--ep-border);">
                            <label for="registration_type" style="font-weight: bold; color: var(--ep-text);"><i class="fa-solid fa-square-poll-horizontal"></i> Tipo de Justificación / Gasto *</label>
                            <select id="registration_type" name="registration_type" class="ep-select" style="margin-top: 6px; font-weight: 600;">
                                <option value="dieta">Liquidación de Viaje y Dietas (Kilometraje, manutención, alojamiento, otros gastos del viaje)</option>
                                <option value="transporte_peaje">Taxi / Parking / Uber / Tren / Peaje (Ticket individual)</option>
                                <option value="material">Material / Suministros (Ticket individual)</option>
                                <option value="otros">Otros Gastos (Ticket individual)</option>
                            </select>
                        </div>

                        <!-- GRUPO A: CAMPOS EXCLUSIVOS DE TICKET INDIVIDUAL -->
                        <div class="group-ticket-only ep-form-grid" style="margin-bottom: 20px;">
                            <div class="form-group">
                                <label for="expense_date"><i class="fa-solid fa-calendar-day"></i> Fecha del Gasto *</label>
                                <input type="date" id="expense_date" name="expense_date" class="ep-input" value="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="concept"><i class="fa-solid fa-heading"></i> Concepto / Descripción *</label>
                                <input type="text" id="concept" name="concept" class="ep-input" placeholder="Ej. Taxi a reunión de equipo, Material oficina...">
                            </div>

                            <div class="form-group">
                                <label for="amount"><i class="fa-solid fa-euro-sign"></i> Importe Total (€) *</label>
                                <input type="number" step="0.01" min="0.01" id="amount" name="amount" class="ep-input" placeholder="0.00">
                            </div>
                        </div>

                        <!-- GRUPO B: CAMPOS EXCLUSIVOS DE LIQUIDACIÓN DE VIAJE -->
                        <div class="group-liq-only" style="display: none; margin-bottom: 20px;">
                            <!-- Cabecera de Viaje -->
                            <div class="ep-form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                                <div class="form-group">
                                    <label><i class="fa-solid fa-user"></i> Asistente / Empleado</label>
                                    <input type="text" class="ep-input" value="<?php echo esc_attr($current_user->display_name); ?>" readonly style="background-color: rgba(255,255,255,0.05); cursor: not-allowed;">
                                </div>
                                <div class="form-group">
                                    <label for="liq_sede"><i class="fa-solid fa-building"></i> Sede para Numeración *</label>
                                    <select id="liq_sede" name="sede_id" class="ep-select">
                                        <?php foreach ($numbering_config as $s_id => $s_conf): ?>
                                            <option value="<?php echo intval($s_id); ?>"><?php echo esc_html($s_conf['name']); ?> (<?php echo esc_html($s_conf['prefix']); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="liq_destino"><i class="fa-solid fa-location-dot"></i> Viaje a (Destino) *</label>
                                    <input type="text" id="liq_destino" name="destino" class="ep-input" placeholder="Ej. Plasencia, Badajoz...">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label for="liq_motivo"><i class="fa-solid fa-heading"></i> Motivo del Viaje / Formación *</label>
                                    <input type="text" id="liq_motivo" name="motivo" class="ep-input" placeholder="Ej. Curso de Inteligencia Artificial...">
                                </div>
                            </div>

                            <!-- Fechas de Liquidación -->
                            <div class="ep-form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 15px;">
                                <div class="form-group">
                                    <label for="liq_fecha_desde"><i class="fa-solid fa-calendar-day"></i> Fecha Salida *</label>
                                    <input type="date" id="liq_fecha_desde" name="fecha_desde" class="ep-input" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="liq_hora_desde"><i class="fa-solid fa-clock"></i> Hora Salida</label>
                                    <input type="time" id="liq_hora_desde" name="hora_desde" class="ep-input">
                                </div>
                                <div class="form-group">
                                    <label for="liq_fecha_hasta"><i class="fa-solid fa-calendar-day"></i> Fecha Regreso *</label>
                                    <input type="date" id="liq_fecha_hasta" name="fecha_hasta" class="ep-input" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="liq_hora_hasta"><i class="fa-solid fa-clock"></i> Hora Regreso</label>
                                    <input type="time" id="liq_hora_hasta" name="hora_hasta" class="ep-input">
                                </div>
                            </div>

                            <!-- Kilometraje y tarifas -->
                            <div style="background: rgba(2, 132, 199, 0.05); border-left: 4px solid #0284c7; padding: 15px; border-radius: 6px; margin-bottom: 20px; display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 15px; align-items: center;">
                                <div>
                                    <label style="font-weight: 700; color: var(--ep-text); margin-bottom: 4px; display: block;"><i class="fa-solid fa-car-side"></i> Distancia de Desplazamiento (Km)</label>
                                    <input type="number" step="0.1" min="0" id="liq_kilometros" name="kilometros" class="ep-input" placeholder="Kilómetros realizados" value="0.0">
                                </div>
                                <div class="text-center">
                                    <span style="font-size: 11px; color: #64748b; display: block; margin-bottom: 2px;">Precio / Km</span>
                                    <strong style="font-size: 16px;" id="liq-calc-rate"><?php echo number_format($current_user_config['km_rate'], 2); ?> €</strong>
                                </div>
                                <div class="text-center">
                                    <span style="font-size: 11px; color: #64748b; display: block; margin-bottom: 2px;">Importe Exento</span>
                                    <strong style="font-size: 16px; color: #16a34a;" id="liq-calc-exempt">0,00 €</strong>
                                </div>
                                <div class="text-center">
                                    <span style="font-size: 11px; color: #64748b; display: block; margin-bottom: 2px;">Importe Sujeto</span>
                                    <strong style="font-size: 16px; color: #dc2626;" id="liq-calc-subject">0,00 €</strong>
                                </div>
                            </div>

                            <!-- Tablas Dinámicas de Dietas -->
                            <div style="margin-bottom: 20px;">
                                <!-- Manutención -->
                                <div style="margin-top: 15px; margin-bottom: 15px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 4px;">
                                        <h4 style="font-weight: 700; color: #1e3a8a; font-size: 13px; margin: 0;"><i class="fa-solid fa-utensils"></i> 1. Gastos de Manutención Justificados (Comida / Restaurantes)</h4>
                                        <button type="button" class="ep-btn ep-btn-sm ep-btn-light" onclick="EP_Expenses_App.addLiqRow('manutencion')"><i class="fa-solid fa-plus"></i> Añadir fila</button>
                                    </div>
                                    <table class="ep-table dynamic-table" id="liq-table-manutencion">
                                        <thead>
                                            <tr>
                                                <th style="width: 20%;">Fecha</th>
                                                <th style="width: 60%;">Concepto / Descripción</th>
                                                <th style="width: 15%;">Importe (€)</th>
                                                <th style="width: 5%;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="liq-tbody-manutencion"></tbody>
                                    </table>
                                </div>

                                <!-- Alojamiento -->
                                <div style="margin-bottom: 15px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 4px;">
                                        <h4 style="font-weight: 700; color: #1e3a8a; font-size: 13px; margin: 0;"><i class="fa-solid fa-hotel"></i> 2. Gastos de Alojamiento Justificados</h4>
                                        <button type="button" class="ep-btn ep-btn-sm ep-btn-light" onclick="EP_Expenses_App.addLiqRow('alojamiento')"><i class="fa-solid fa-plus"></i> Añadir fila</button>
                                    </div>
                                    <table class="ep-table dynamic-table" id="liq-table-alojamiento">
                                        <thead>
                                            <tr>
                                                <th style="width: 20%;">Fecha</th>
                                                <th style="width: 60%;">Concepto / Descripción</th>
                                                <th style="width: 15%;">Importe (€)</th>
                                                <th style="width: 5%;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="liq-tbody-alojamiento"></tbody>
                                    </table>
                                </div>

                                <!-- Otros -->
                                <div style="margin-bottom: 15px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 4px;">
                                        <h4 style="font-weight: 700; color: #1e3a8a; font-size: 13px; margin: 0;"><i class="fa-solid fa-compass"></i> 3. Otros Gastos Justificados (Gastos Varios del Viaje)</h4>
                                        <button type="button" class="ep-btn ep-btn-sm ep-btn-light" onclick="EP_Expenses_App.addLiqRow('otros')"><i class="fa-solid fa-plus"></i> Añadir fila</button>
                                    </div>
                                    <table class="ep-table dynamic-table" id="liq-table-otros">
                                        <thead>
                                            <tr>
                                                <th style="width: 20%;">Fecha</th>
                                                <th style="width: 60%;">Concepto / Descripción</th>
                                                <th style="width: 15%;">Importe (€)</th>
                                                <th style="width: 5%;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="liq-tbody-otros"></tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Resumen del Cálculo Automático de Retenciones de Viaje -->
                            <div style="margin-top: 25px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 15px; margin-bottom: 15px;">
                                <h4 style="font-weight: 700; color: var(--ep-primary); margin-bottom: 10px; font-size: 14px;"><i class="fa-solid fa-calculator"></i> Desglose de Cálculo y Retenciones (Viaje)</h4>
                                <div class="closure-details-card" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
                                    <div class="detail-item">
                                        <span>Total Bruto:</span>
                                        <strong id="liq-show-bruto">0,00 €</strong>
                                    </div>
                                    <div class="detail-item">
                                        <span>Base Retención:</span>
                                        <strong id="liq-show-base">0,00 €</strong>
                                    </div>
                                    <div class="detail-item">
                                        <span>Renta Exenta:</span>
                                        <strong id="liq-show-exento">0,00 €</strong>
                                    </div>
                                    <div class="detail-item">
                                        <span>Retención I.R.P.F. (<span id="liq-show-irpf-pct">0.00</span>%):</span>
                                        <strong style="color: #dc2626;" id="liq-show-irpf-amt">- 0,00 €</strong>
                                    </div>
                                    <div class="detail-item">
                                        <span>Seguridad Social (<span id="liq-show-ss-pct">0.00</span>%):</span>
                                        <strong style="color: #dc2626;" id="liq-show-ss-amt">- 0,00 €</strong>
                                    </div>
                                    <div class="detail-item highlight">
                                        <span>Neto a Percibir:</span>
                                        <strong id="liq-show-neto">0,00 €</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- GRUPO C: CAMPOS COMPARTIDOS (Se muestran siempre en la parte inferior) -->
                        <div class="ep-form-grid" style="margin-top: 15px; border-top: 1px solid var(--ep-border); padding-top: 15px;">
                            <!-- Google Maps URL -->
                            <div class="form-group full-width" id="group-google-maps-url">
                                <label for="google_maps_url"><i class="fa-solid fa-map-location-dot text-danger"></i> Enlace de Ruta (Google Maps) / Justificante del Trayecto</label>
                                <input type="url" id="google_maps_url" name="google_maps_url" class="ep-input" placeholder="https://maps.app.goo.gl/... o https://www.google.com/maps/dir/...">
                                <small class="text-muted" style="font-size: 11px; display: block; margin-top: 6px; line-height: 1.4; background: rgba(0,0,0,0.02); padding: 8px; border-radius: 4px; border: 1px dashed var(--ep-border);">
                                    <strong>¿Cómo obtener el enlace de Google Maps?</strong><br>
                                    1. Entra en <a href="https://maps.google.com" target="_blank" style="text-decoration: underline; color: var(--ep-primary); font-weight: 600;">Google Maps</a> (web o aplicación móvil) y calcula tu ruta (Origen y Destino).<br>
                                    2. Pulsa en el botón <strong>Compartir</strong> (en móvil suele ser el icono de tres puntos arriba a la derecha y luego "Compartir", o el botón directo "Compartir" de la ruta) y elige <strong>Copiar enlace</strong>.<br>
                                    3. Pega el enlace copiado en esta casilla.
                                </small>
                            </div>

                            <!-- Forma de Pago (Shared) -->
                            <div class="form-group">
                                <label for="payment_method"><i class="fa-solid fa-credit-card"></i> Forma de Pago / Liquidación *</label>
                                <select id="payment_method" name="payment_method" class="ep-select">
                                    <option value="personal">Cuenta Personal (A reponer)</option>
                                    <option value="empresa">Tarjeta de Empresa</option>
                                    <option value="efectivo">Efectivo / Caja Chica</option>
                                </select>
                            </div>

                            <!-- Observaciones (Shared) -->
                            <div class="form-group full-width">
                                <label for="notes"><i class="fa-solid fa-comment-alt"></i> Observaciones / Notas Adicionales</label>
                                <textarea id="notes" name="notes" class="ep-textarea" rows="2" placeholder="Detalles o aclaraciones adicionales..."></textarea>
                            </div>

                            <!-- Subida de Archivos -->
                            <div class="form-group full-width">
                                <label><i class="fa-solid fa-paperclip"></i> Comprobantes Adjuntos (Tickets o Facturas en PDF o Imagen, hasta 10 archivos)</label>
                                <div class="ep-upload-box">
                                    <div class="ep-upload-actions">
                                        <label for="ep_expense_file" class="ep-btn ep-btn-secondary">
                                            <i class="fa-solid fa-upload"></i> Seleccionar Fotos o PDF (Múltiples)
                                        </label>
                                        <input type="file" id="ep_expense_file" name="expense_files[]" accept="image/*,application/pdf,.pdf" multiple style="display:none;">
                                    </div>
                                    <small class="text-muted" style="display:block; margin-top:6px; font-size:11px;">
                                        Puedes seleccionar varios archivos a la vez manteniendo pulsada la tecla <strong>Ctrl</strong> o <strong>Shift</strong>.
                                    </small>
                                    <div id="ep-file-preview-container" style="margin-top: 10px; display: none;">
                                        <div id="ep-files-grid-preview" style="display:flex; flex-wrap:wrap; gap:8px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ep-modal-footer">
                        <button type="button" class="ep-btn ep-btn-light" onclick="EP_Expenses_App.closeExpenseModal()">Cancelar</button>
                        <button type="submit" class="ep-btn ep-btn-primary" id="ep-btn-save-expense">
                            <i class="fa-solid fa-save"></i> Guardar Registro
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL: VISOR DE TICKET / IMAGEN ADJUNTA (ORIGINAL) -->
        <div id="ep-modal-viewer" class="ep-modal-backdrop" style="display:none;">
            <div class="ep-modal-content glass" style="max-width: 800px; width: 90%;">
                <div class="ep-modal-header">
                    <h3 id="ep-viewer-title"><i class="fa-solid fa-image"></i> Vista Previa del Ticket</h3>
                    <button class="ep-modal-close" onclick="EP_Expenses_App.closeViewerModal()">&times;</button>
                </div>
                <div class="ep-modal-body text-center" style="max-height: 70vh; overflow-y: auto;">
                    <img id="ep-viewer-img" src="" alt="Ticket" style="max-width: 100%; max-height: 60vh; border-radius: 8px; display: none;">
                    <iframe id="ep-viewer-pdf" src="" style="width: 100%; height: 60vh; border: none; display: none;"></iframe>
                </div>
                <div class="ep-modal-footer">
                    <a id="ep-viewer-download" href="" target="_blank" class="ep-btn ep-btn-primary"><i class="fa-solid fa-download"></i> Abrir / Descargar</a>
                    <button type="button" class="ep-btn ep-btn-light" onclick="EP_Expenses_App.closeViewerModal()">Cerrar</button>
                </div>
            </div>
        </div>

        <!-- MODAL: GALERÍA DE MÚLTIPLES ADJUNTOS POR TICKET (ORIGINAL) -->
        <div id="ep-modal-gallery" class="ep-modal-backdrop" style="display:none;">
            <div class="ep-modal-content glass" style="max-width: 750px; width:92%;">
                <div class="ep-modal-header">
                    <h3><i class="fa-solid fa-folder-open text-primary"></i> Archivos Adjuntos - Ticket <span id="gallery-ticket-number">-</span></h3>
                    <button class="ep-modal-close" onclick="EP_Expenses_App.closeGalleryModal()">&times;</button>
                </div>
                <div class="ep-modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div id="gallery-attachments-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:12px; margin-top:10px;">
                    </div>
                </div>
                <div class="ep-modal-footer">
                    <button type="button" class="ep-btn ep-btn-light" onclick="EP_Expenses_App.closeGalleryModal()">Cerrar</button>
                </div>
            </div>
        </div>

        <!-- MODAL: DECLARACIÓN DE CIERRE MENSUAL (DÍA 27) (ORIGINAL) -->
        <div id="ep-modal-closure" class="ep-modal-backdrop" style="display:none;">
            <div class="ep-modal-content glass">
                <div class="ep-modal-header">
                    <h3><i class="fa-solid fa-calendar-check"></i> Declaración y Cierre de Gastos del Mes</h3>
                    <button class="ep-modal-close" onclick="EP_Expenses_App.closeClosureModal()">&times;</button>
                </div>
                <div class="ep-modal-body">
                    <div class="closure-summary-box">
                        <p>A continuación se resume la totalidad de dietas y tickets subidos durante el periodo seleccionado para su conformidad y envío a Administración:</p>
                        <div class="closure-details-card">
                            <div class="detail-item">
                                <span>Mes / Periodo:</span>
                                <strong id="closure-modal-period">-</strong>
                            </div>
                            <div class="detail-item">
                                <span>Total de Tickets:</span>
                                <strong id="closure-modal-tickets-count">0</strong>
                            </div>
                            <div class="detail-item highlight">
                                <span>Total Declarado:</span>
                                <strong id="closure-modal-total-amount">0.00 €</strong>
                            </div>
                        </div>
                        <div class="form-group" style="margin-top: 15px;">
                            <label for="closure_notes"><i class="fa-solid fa-comment-dots"></i> Observaciones Generales del Cierre (Opcional)</label>
                            <textarea id="closure_notes" class="ep-textarea" rows="2" placeholder="Cualquier nota aclaratoria para administración..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="ep-modal-footer">
                    <button type="button" class="ep-btn ep-btn-light" onclick="EP_Expenses_App.closeClosureModal()">Volver</button>
                    <button type="button" class="ep-btn ep-btn-success" id="ep-btn-confirm-closure">
                        <i class="fa-solid fa-check-circle"></i> Confirmar y Declarar Cierre
                    </button>
                </div>
            </div>
        </div>

        <!-- MODAL: LIQUIDAR GASTO INDIVIDUAL / TICKET (ORIGINAL) -->
        <div id="ep-modal-liquidate" class="ep-modal-backdrop" style="display:none;">
            <div class="ep-modal-content glass" style="max-width: 550px;">
                <div class="ep-modal-header">
                    <h3><i class="fa-solid fa-circle-check text-success"></i> Liquidar Gasto / Ticket</h3>
                    <button class="ep-modal-close" onclick="EP_Expenses_App.closeLiquidateModal()">&times;</button>
                </div>
                <form id="ep-liquidate-form">
                    <input type="hidden" id="liquidate_expense_id" value="">
                    <div class="ep-modal-body">
                        <p style="margin-bottom: 15px;">Vas a marcar el ticket <strong id="liquidate-ticket-number">-</strong> como <strong>Liquidado / Abonado</strong>.</p>
                        
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label for="liquidation_method"><i class="fa-solid fa-credit-card"></i> Forma de Pago / Abonado por</label>
                            <select id="liquidation_method" class="ep-select" required>
                                <option value="Transferencia Bancaria">Transferencia Bancaria</option>
                                <option value="Efectivo / Caja Chica">Efectivo / Caja Chica</option>
                                <option value="Ingreso en Nómina">Ingreso en Nómina</option>
                                <option value="Tarjeta de Empresa">Tarjeta de Empresa</option>
                                <option value="Otro Medio">Otro Medio</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="liquidation_notes"><i class="fa-solid fa-comment-dots"></i> Referencia / Notas de Liquidación (Opcional)</label>
                            <textarea id="liquidation_notes" class="ep-textarea" rows="2" placeholder="Ej. Transferencia Ref #98124, abonado el 05/08..."></textarea>
                        </div>
                    </div>
                    <div class="ep-modal-footer">
                        <button type="button" class="ep-btn ep-btn-light" onclick="EP_Expenses_App.closeLiquidateModal()">Cancelar</button>
                        <button type="submit" class="ep-btn ep-btn-success" id="ep-btn-confirm-liquidate">
                            <i class="fa-solid fa-check-circle"></i> Confirmar Liquidación
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL: RECHAZAR GASTO / TICKET (ORIGINAL) -->
        <div id="ep-modal-reject" class="ep-modal-backdrop" style="display:none;">
            <div class="ep-modal-content glass" style="max-width: 550px;">
                <div class="ep-modal-header">
                    <h3><i class="fa-solid fa-circle-xmark text-danger"></i> Rechazar Ticket / Gasto</h3>
                    <button class="ep-modal-close" onclick="EP_Expenses_App.closeRejectModal()">&times;</button>
                </div>
                <form id="ep-reject-form" onsubmit="event.preventDefault(); EP_Expenses_App.confirmRejection();">
                    <input type="hidden" id="reject_expense_id" value="">
                    <div class="ep-modal-body">
                        <p style="margin-bottom: 15px;">Vas a rechazar el ticket <strong id="reject-ticket-number">-</strong>.</p>
                        
                        <div class="form-group">
                            <label for="rejection_reason"><i class="fa-solid fa-comment-slash"></i> Motivo del Rechazo *</label>
                            <textarea id="rejection_reason" class="ep-textarea" rows="3" placeholder="Especifica el motivo del rechazo para que el empleado esté informado..." required></textarea>
                        </div>
                    </div>
                    <div class="ep-modal-footer">
                        <button type="button" class="ep-btn ep-btn-light" onclick="EP_Expenses_App.closeRejectModal()">Cancelar</button>
                        <button type="submit" class="ep-btn ep-btn-danger" id="ep-btn-confirm-reject" style="background:#dc2626; color:#fff;">
                            <i class="fa-solid fa-xmark"></i> Confirmar Rechazo
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL: TRAZABILIDAD / DETALLES DE LIQUIDACIÓN O RECHAZO DE TICKET (ORIGINAL) -->
        <div id="ep-modal-traceability" class="ep-modal-backdrop" style="display:none;">
            <div class="ep-modal-content glass" style="max-width: 500px;">
                <div class="ep-modal-header">
                    <h3 id="trace-modal-title"><i class="fa-solid fa-info-circle text-info"></i> Detalles del Gasto</h3>
                    <button class="ep-modal-close" onclick="EP_Expenses_App.closeTraceabilityModal()">&times;</button>
                </div>
                <div class="ep-modal-body">
                    <div class="closure-details-card">
                        <div class="detail-item">
                            <span>Estado:</span>
                            <strong id="trace-status-badge"><span class="ep-badge ep-badge-success"><i class="fa-solid fa-check-circle"></i> Liquidado</span></strong>
                        </div>
                        <div class="detail-item">
                            <span>Gestionado / Aprobado por:</span>
                            <strong id="trace-approved-by">-</strong>
                        </div>
                        <div class="detail-item" id="trace-liq-date-row">
                            <span>Fecha de Liquidación:</span>
                            <strong id="trace-liquidated-at">-</strong>
                        </div>
                        <div class="detail-item" id="trace-liq-by-row">
                            <span>Liquidado por:</span>
                            <strong id="trace-liquidated-by">-</strong>
                        </div>
                        <div class="detail-item" id="trace-liq-method-row">
                            <span>Forma de Pago / Medio:</span>
                            <strong id="trace-liquidation-method">-</strong>
                        </div>
                        <div class="detail-item full-width" id="trace-liq-notes-row" style="margin-top:10px;">
                            <span>Referencia / Observaciones:</span>
                            <p id="trace-liquidation-notes" style="background: rgba(255,255,255,0.1); padding: 8px; border-radius: 6px; margin-top:4px; font-size:13px; color:var(--ep-text);">-</p>
                        </div>
                        <div class="detail-item full-width" id="trace-rejection-container" style="display:none; margin-top:10px;">
                            <span>Motivo del Rechazo:</span>
                            <p id="trace-rejection-reason" style="background: rgba(239, 68, 68, 0.1); border:1px solid rgba(239,68,68,0.3); padding: 8px; border-radius: 6px; margin-top:4px; font-size:13px; color:#dc2626; font-weight:600;"></p>
                        </div>
                    </div>
                </div>
                <div class="ep-modal-footer">
                    <button type="button" class="ep-btn ep-btn-light" onclick="EP_Expenses_App.closeTraceabilityModal()">Cerrar</button>
                </div>
            </div>
        </div>

        <!-- MODAL: GESTIÓN DE LIQUIDACIÓN (APROBAR / RECHAZAR / LIQUIDAR) -->
        <div id="ep-modal-liq-manage" class="ep-modal-backdrop" style="display:none;">
            <div class="ep-modal-content glass" style="max-width: 550px;">
                <div class="ep-modal-header">
                    <h3 id="liq-manage-title"><i class="fa-solid fa-clipboard-check text-primary"></i> Gestionar Liquidación</h3>
                    <button class="ep-modal-close" onclick="EP_Expenses_App.closeLiqManageModal()">&times;</button>
                </div>
                <form id="ep-liq-manage-form">
                    <input type="hidden" id="liq_manage_id" value="">
                    <input type="hidden" id="liq_manage_action" value="">
                    
                    <div class="ep-modal-body">
                        <p id="liq-manage-prompt">¿Deseas realizar esta acción en la liquidación <strong id="liq-manage-number">-</strong>?</p>
                        
                        <div class="form-group" id="liq-manage-reason-group" style="display: none; margin-top: 15px;">
                            <label for="liq_manage_reason"><i class="fa-solid fa-comment-slash"></i> Motivo del Rechazo *</label>
                            <textarea id="liq_manage_reason" class="ep-textarea" rows="3" placeholder="Indique el motivo del rechazo..."></textarea>
                        </div>
                        
                        <!-- Campos de liquidación/abono -->
                        <div id="liq-manage-payment-group" style="display: none; margin-top: 15px;">
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label for="liq_manage_payment_method"><i class="fa-solid fa-credit-card"></i> Forma de Pago / Abonado por *</label>
                                <select id="liq_manage_payment_method" class="ep-select">
                                    <option value="Transferencia Bancaria">Transferencia Bancaria</option>
                                    <option value="Caja">Caja</option>
                                    <option value="Ingreso en Nómina">Ingreso en Nómina</option>
                                    <option value="Otro Medio">Otro Medio</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="liq_manage_payment_notes"><i class="fa-solid fa-comment-dots"></i> Notas de Liquidación / Información para el Empleado</label>
                                <textarea id="liq_manage_payment_notes" class="ep-textarea" rows="3" placeholder="Ej: Se te transferirá al número de cuenta guardado en RRHH..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ep-modal-footer">
                        <button type="button" class="ep-btn ep-btn-light" onclick="EP_Expenses_App.closeLiqManageModal()">Cancelar</button>
                        <button type="submit" class="ep-btn" id="ep-btn-liq-manage-confirm">Confirmar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL: TRAZABILIDAD / DETALLES DE RECHAZO O LIQUIDACIÓN DE VIAJE -->
        <div id="ep-modal-liq-trace" class="ep-modal-backdrop" style="display:none;">
            <div class="ep-modal-content glass" style="max-width: 600px;">
                <div class="ep-modal-header">
                    <h3><i class="fa-solid fa-info-circle text-info"></i> Detalles de Liquidación de Viaje</h3>
                    <button class="ep-modal-close" onclick="EP_Expenses_App.closeLiqTraceModal()">&times;</button>
                </div>
                <div class="ep-modal-body">
                    <div class="closure-details-card" style="grid-template-columns: 1fr;">
                        <div class="detail-item">
                            <span>Estado de Liquidación:</span>
                            <strong id="liq-trace-status">-</strong>
                        </div>
                        <div class="detail-item" id="liq-trace-approved-row">
                            <span>Aprobado por:</span>
                            <strong id="liq-trace-approved-by">-</strong>
                        </div>
                        <div class="detail-item" id="liq-trace-liquidated-row">
                            <span>Abonado por:</span>
                            <strong id="liq-trace-liquidated-by">-</strong>
                        </div>
                        <div class="detail-item" id="liq-trace-method-row" style="display:none;">
                            <span>Forma de Pago / Medio:</span>
                            <strong id="liq-trace-method">-</strong>
                        </div>
                        <div class="detail-item full-width" id="liq-trace-notes-row" style="display:none;">
                            <span>Referencia / Observaciones del abono:</span>
                            <p id="liq-trace-notes" style="background: rgba(255,255,255,0.1); padding: 8px; border-radius: 6px; margin-top:4px; font-size:13px; color:var(--ep-text);">-</p>
                        </div>
                        <div class="detail-item full-width" id="liq-trace-reject-row" style="display:none;">
                            <span>Motivo del Rechazo:</span>
                            <p id="liq-trace-reject-reason" style="background: rgba(239, 68, 68, 0.1); border:1px solid rgba(239,68,68,0.3); padding: 8px; border-radius: 6px; margin-top:4px; font-size:13px; color:#dc2626; font-weight:600;"></p>
                        </div>
                        <div class="detail-item full-width" id="liq-trace-attachments-row" style="display:none; margin-top: 10px;">
                            <span>Comprobantes / Justificantes Adjuntos:</span>
                            <div id="liq-trace-attachments-list" style="display:flex; flex-wrap:wrap; gap:8px; margin-top:6px;"></div>
                        </div>
                    </div>
                </div>
                <div class="ep-modal-footer">
                    <button type="button" class="ep-btn ep-btn-light" onclick="EP_Expenses_App.closeLiqTraceModal()">Cerrar</button>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_ajax()
    {
        // Handled in main sub-plugin class ep-expenses.php
    }
}
