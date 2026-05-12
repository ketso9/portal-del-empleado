<?php
defined('ABSPATH') || exit;

$can_write = EP_Empresas::can_write();
$zonas = EP_Empresas::get_zonas();
$settings = EP_Empresas::get_settings();
$app_name = $settings['app_name'];
$app_logo = $settings['app_logo'];
?>

<div class="ep-empresas-app">
    <div class="ep-emp-header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <img src="<?php echo esc_url($app_logo); ?>" alt="<?php echo esc_attr($app_name); ?>" style="height: 45px; width: auto; object-fit: contain;">
            <div>
                <h1 style="margin-bottom: 0; color: var(--emp-primary);"><?php echo esc_html($app_name); ?></h1>
                <p>Consulta, gestiona y exporta el listado de empresas asociadas.</p>
            </div>
        </div>
        <div class="ep-emp-header-actions">
            <?php if ($can_write): ?>
                <button type="button" class="ep-emp-btn-import-trigger" id="ep_emp_btn_import_trigger">
                    <i class="fa-solid fa-file-import"></i> Importar
                </button>
                <button type="button" class="ep-emp-btn-add" id="ep_emp_btn_add">
                    <i class="fa-solid fa-plus"></i> Nueva Empresa
                </button>
            <?php endif; ?>
            <?php if ($can_write): ?>
                <button type="button" class="ep-emp-btn-settings" id="ep_emp_btn_settings" title="Configuración de la App">
                    <i class="fa-solid fa-cog"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="ep-emp-toolbar">
        <div class="ep-emp-search-box">
            <i class="fa-solid fa-search"></i>
            <input type="text" id="ep_emp_search" placeholder="Buscar por nombre o CIF...">
        </div>
        
        <select id="ep_emp_filter_membresia">
            <option value="">Todas las membresías</option>
            <option value="Basic">Basic</option>
            <option value="Corporate">Corporate</option>
            <option value="Premium">Premium</option>
        </select>

        <select id="ep_emp_filter_zona">
            <option value="">Todas las zonas</option>
            <?php foreach ($zonas as $zona): ?>
                <option value="<?php echo esc_attr($zona); ?>"><?php echo esc_html($zona); ?></option>
            <?php endforeach; ?>
        </select>

        <div class="ep-emp-sort-btns">
            <button class="ep-emp-sort-btn active" data-sort="nombre" title="Ordenar por Nombre">
                A-Z <i class="fa-solid fa-sort sort-icon"></i>
            </button>
            <button class="ep-emp-sort-btn" data-sort="created_at" title="Más recientes">
                <i class="fa-solid fa-clock sort-icon"></i>
            </button>
        </div>

        <!-- Export -->
        <div class="ep-emp-export-btns" style="margin-left: auto;">
            <button type="button" class="ep-emp-btn-export csv" id="ep_emp_export_csv" title="Exportar CSV">
                <i class="fa-solid fa-file-csv"></i> CSV
            </button>
            <button type="button" class="ep-emp-btn-export xls" id="ep_emp_export_xls" title="Exportar XLS (Excel Antiguo)">
                <i class="fa-solid fa-file-excel"></i> XLS
            </button>
            <button type="button" class="ep-emp-btn-export xlsx" id="ep_emp_export_excel" title="Exportar XLSX (Excel Moderno)">
                <i class="fa-solid fa-file-excel"></i> XLSX
            </button>
        </div>

        <div class="ep-view-toggle">
            <button class="ep-view-btn active" data-view="cards" title="Vista Tarjetas"><i class="fa-solid fa-grip"></i></button>
            <button class="ep-view-btn" data-view="table" title="Vista Tabla"><i class="fa-solid fa-list"></i></button>
        </div>
    </div>

    <!-- Contenedores de Vistas -->
    <div id="ep-emp-cards-view"></div>
    
    <div id="ep-emp-table-view" style="display: none;">
        <div class="ep-emp-table-wrapper">
            <table class="ep-emp-table">
                <thead>
                    <tr>
                        <th>Logo</th>
                        <th class="sortable" data-sort="nombre">Empresa <i class="fa-solid fa-sort sort-arrow"></i></th>
                        <th>CIF</th>
                        <th>Contacto</th>
                        <th class="sortable" data-sort="zona">Zona <i class="fa-solid fa-sort sort-arrow"></i></th>
                        <th class="sortable" data-sort="tipo_membresia">Membresía <i class="fa-solid fa-sort sort-arrow"></i></th>
                        <?php if ($can_write): ?>
                            <th style="text-align: right;">Acciones</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="ep-emp-table-body"></tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($can_write): ?>
    <!-- Modal Formularios -->
    <div class="ep-emp-modal-overlay" id="ep_emp_modal">
        <div class="ep-emp-modal">
            <div class="ep-emp-modal-header">
                <h2 id="ep_emp_modal_title"><i class="fa-solid fa-building"></i> Alta de Empresa</h2>
                <button type="button" class="ep-emp-modal-close" id="ep_emp_modal_close">&times;</button>
            </div>
            <div class="ep-emp-modal-body">
                <form id="ep_emp_form">
                    <input type="hidden" id="emp_id" name="empresa_id" value="">
                    
                    <div class="ep-emp-form-section">
                        <div class="ep-emp-form-section-title">Datos Generales</div>
                        <div class="ep-emp-form-row cols-2">
                            <div class="ep-emp-form-group">
                                <label>Nombre de la empresa</label>
                                <input type="text" id="emp_nombre" name="nombre">
                            </div>
                            <div class="ep-emp-form-group">
                                <label>Persona responsable</label>
                                <input type="text" id="emp_responsable" name="responsable">
                            </div>
                        </div>
                        <div class="ep-emp-form-row cols-3">
                            <div class="ep-emp-form-group">
                                <label>CIF</label>
                                <input type="text" id="emp_cif" name="cif">
                            </div>
                            <div class="ep-emp-form-group">
                                <label>Teléfono</label>
                                <input type="text" id="emp_telefono" name="telefono">
                            </div>
                            <div class="ep-emp-form-group">
                                <label>Nº Trabajadores</label>
                                <input type="number" id="emp_trabajadores" name="num_trabajadores" min="0">
                            </div>
                        </div>
                        <div class="ep-emp-form-row cols-2">
                            <div class="ep-emp-form-group">
                                <label>Dirección</label>
                                <input type="text" id="emp_direccion" name="direccion">
                            </div>
                            <div class="ep-emp-form-group">
                                <label>Email</label>
                                <input type="email" id="emp_email" name="email">
                            </div>
                        </div>
                        <div class="ep-emp-form-row cols-3">
                            <div class="ep-emp-form-group">
                                <label>Zona</label>
                                <select id="emp_zona" name="zona">
                                    <option value="">Selecciona zona...</option>
                                    <?php foreach ($zonas as $zona): ?>
                                        <option value="<?php echo esc_attr($zona); ?>"><?php echo esc_html($zona); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ep-emp-form-group">
                                <label>Tipo de membresía</label>
                                <select id="emp_membresia" name="tipo_membresia">
                                    <option value="Basic">Basic</option>
                                    <option value="Corporate">Corporate</option>
                                    <option value="Premium">Premium</option>
                                </select>
                            </div>
                            <div class="ep-emp-form-group">
                                <label>IAE</label>
                                <input type="text" id="emp_iae" name="iae" placeholder="Rellena tu IAE...">
                            </div>
                        </div>
                    </div>

                    <div class="ep-emp-form-section">
                        <div class="ep-emp-form-section-title">Imágenes</div>
                        <div class="ep-emp-form-row cols-2">
                            <!-- Logo -->
                            <div class="ep-emp-form-group">
                                <label>Logo (opcional)</label>
                                <input type="hidden" id="emp_logo_url" name="logo_url">
                                <div class="ep-emp-upload-zone" id="ep_emp_logo_drop">
                                    <i class="fa-solid fa-cloud-arrow-up"></i>
                                    <p>Clic para subir logo</p>
                                    <input type="file" id="ep_emp_logo_file" accept="image/*">
                                </div>
                                <img id="ep_emp_logo_preview" class="ep-emp-upload-preview" src="" alt="Preview">
                            </div>
                            <!-- Foto -->
                            <div class="ep-emp-form-group">
                                <label>Fotografía sede/equipo (opcional)</label>
                                <input type="hidden" id="emp_foto_url" name="foto_url">
                                <div class="ep-emp-upload-zone" id="ep_emp_foto_drop">
                                    <i class="fa-solid fa-camera"></i>
                                    <p>Clic para subir foto</p>
                                    <input type="file" id="ep_emp_foto_file" accept="image/*">
                                </div>
                                <img id="ep_emp_foto_preview" class="ep-emp-upload-preview" src="" alt="Preview">
                            </div>
                        </div>
                    </div>

                    <div class="ep-emp-form-section">
                        <div class="ep-emp-form-section-title">Observaciones</div>
                        <div class="ep-emp-form-row cols-1">
                            <div class="ep-emp-form-group">
                                <textarea id="emp_observaciones" name="observaciones" placeholder="Notas adicionales sobre la empresa..."></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="ep-emp-modal-footer">
                <button type="button" class="ep-emp-btn-cancel" id="ep_emp_modal_cancel">Cancelar</button>
                <button type="submit" form="ep_emp_form" class="ep-emp-btn-save" id="ep_emp_btn_save">
                    <i class="fa-solid fa-save"></i> Guardar Empresa
                </button>
            </div>
        </div>
    </div>
    <!-- Modal Importación -->
    <div class="ep-emp-modal-overlay" id="ep_emp_import_modal">
        <div class="ep-emp-modal">
            <div class="ep-emp-modal-header">
                <h2><i class="fa-solid fa-file-import"></i> Importar Empresas (Excel/CSV)</h2>
                <button type="button" class="ep-emp-modal-close" id="ep_emp_import_modal_close">&times;</button>
            </div>
            <div class="ep-emp-modal-body">
                <div class="ep-emp-import-instructions">
                    <p>Sube un archivo <strong>Excel (.xlsx/.xls)</strong> o <strong>CSV</strong> con las columnas en el siguiente orden:</p>
                    <code style="display: block; background: #f1f5f9; padding: 10px; border-radius: 6px; margin: 10px 0; font-size: 0.8rem;">
                        nombre, responsable, cif, telefono, email, direccion, zona, tipo_membresia, num_trabajadores, iae, observaciones
                    </code>
                    <p style="font-size: 0.8rem; color: var(--emp-muted);">* La primera fila debe contener los encabezados. En Excel, se usará la primera hoja.</p>
                </div>
                
                <div class="ep-emp-upload-zone" id="ep_emp_import_drop">
                    <i class="fa-solid fa-file-excel" style="font-size: 3rem; color: #1d6f42;"></i>
                    <p>Arrastra tu archivo Excel o CSV aquí o haz clic para seleccionar</p>
                    <input type="file" id="ep_emp_import_file" accept=".csv, .xlsx, .xls">
                </div>
                <div id="ep_emp_import_status" style="margin-top: 15px; display: none;"></div>
            </div>
            <div class="ep-emp-modal-footer">
                <button type="button" class="ep-emp-btn-cancel" id="ep_emp_import_modal_cancel">Cancelar</button>
                <button type="button" class="ep-emp-btn-save" id="ep_emp_btn_do_import" disabled>
                    <i class="fa-solid fa-upload"></i> Iniciar Importación
                </button>
            </div>
        </div>
    </div>
    <!-- Modal Ajustes (Solo usuarios con permisos de escritura) -->
    <div class="ep-emp-modal-overlay" id="ep_emp_settings_modal">
        <div class="ep-emp-modal" style="max-width: 500px;">
            <div class="ep-emp-modal-header">
                <h2><i class="fa-solid fa-cog"></i> Ajustes de la App</h2>
                <button type="button" class="ep-emp-modal-close" id="ep_emp_settings_close">&times;</button>
            </div>
            <div class="ep-emp-modal-body">
                <form id="ep_emp_settings_form">
                    <div class="ep-emp-form-group">
                        <label>Nombre de la Aplicación</label>
                        <input type="text" id="set_app_name" name="app_name" value="<?php echo esc_attr($app_name); ?>" placeholder="Ej: Empresas socias">
                    </div>
                    <div class="ep-emp-form-group" style="margin-top: 15px;">
                        <label>Logo de la Aplicación</label>
                        <input type="hidden" id="set_app_logo" name="app_logo" value="<?php echo esc_attr($app_logo); ?>">
                        <div class="ep-emp-upload-zone" id="ep_emp_set_logo_drop">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <p>Clic para cambiar logo</p>
                            <input type="file" id="ep_emp_set_logo_file" accept="image/*">
                        </div>
                        <div style="text-align: center; margin-top: 10px;">
                            <img id="ep_emp_set_logo_preview" src="<?php echo esc_url($app_logo); ?>" style="max-height: 80px; width: auto; border-radius: 8px; border: 1px solid #e2e8f0; padding: 5px;">
                        </div>
                    </div>
                </form>
            </div>
            <div class="ep-emp-modal-footer">
                <button type="button" class="ep-emp-btn-cancel" id="ep_emp_settings_cancel">Cancelar</button>
                <button type="submit" form="ep_emp_settings_form" class="ep-emp-btn-save" id="ep_emp_btn_save_settings">
                    <i class="fa-solid fa-save"></i> Guardar Ajustes
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Pasar variables PHP a JS -->
<script>
    window.epEmpresasConfig = {
        ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('ep_empresas_nonce')); ?>',
        canWrite: <?php echo $can_write ? 'true' : 'false'; ?>,
        isAdmin: <?php echo $can_write ? 'true' : 'false'; ?>
    };
</script>
