<?php
defined('ABSPATH') || exit;

$is_admin = current_user_can('administrator');
$links = EP_Links::get_links();
?>

<div class="ep-links-app">
    <div class="ep-app-header" style="margin-bottom: 30px;">
        <h1><i class="fa-solid fa-link"></i> Enlaces de Interés</h1>
        <p>Accesos directos y recursos útiles para el día a día.</p>
    </div>

    <?php if ($is_admin): ?>
        <div class="ep-links-admin-form">
            <h2><i class="fa-solid fa-plus-circle"></i> Gestionar Enlaces (Admin)</h2>
            <form id="ep_add_link_form">
                <input type="hidden" id="link_id" name="link_id" value="">
                <div class="ep-form-row">
                    <div class="ep-form-group">
                        <label for="link_title">Título</label>
                        <input type="text" id="link_title" name="title" placeholder="Ej: Google Drive" required>
                    </div>
                    <div class="ep-form-group">
                        <label for="link_url">URL</label>
                        <input type="url" id="link_url" name="url" placeholder="https://..." required>
                    </div>
                    <div class="ep-form-group">
                        <label>Icono</label>
                        <div class="ep-icon-selector-wrapper">
                            <div id="ep_icon_preview" class="ep-icon-preview">
                                <i class="fa-solid fa-link"></i>
                            </div>
                            <button type="button" id="ep_open_icon_picker" class="ep-btn-secondary">
                                <i class="fa-solid fa-icons"></i> Seleccionar Icono
                            </button>
                            <input type="hidden" id="link_icon" name="icon" value="fa-solid fa-link">
                        </div>
                    </div>
                    <div class="ep-form-group" style="margin-left: auto; flex-direction: row; align-items: center; gap: 10px;">
                        <button type="button" id="ep_cancel_edit" class="ep-btn-secondary" style="display: none; border-color: #95a5a6; color: #95a5a6; height: 48px;">
                            Cancelar
                        </button>
                        <button type="submit" class="ep-btn-add-link">
                            <i class="fa-solid fa-save"></i> <span id="submit_btn_text">Guardar</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="ep-links-grid">
        <?php if (empty($links)): ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #7f8c8d;">
                <i class="fa-solid fa-link-slash" style="font-size: 3rem; margin-bottom: 15px; display: block;"></i>
                <p>No hay enlaces registrados todavía.</p>
            </div>
        <?php else: ?>
            <?php foreach ($links as $link): 
                $url = get_post_meta($link->ID, '_ep_link_url', true);
                $icon = get_post_meta($link->ID, '_ep_link_icon', true) ?: 'fa-solid fa-link';
            ?>
                <a href="<?php echo esc_url($url); ?>" class="ep-link-card" target="_blank">
                    <?php if ($is_admin): ?>
                        <div class="ep-card-actions">
                            <button type="button" class="ep-btn-edit-link" 
                                    data-id="<?php echo $link->ID; ?>" 
                                    data-title="<?php echo esc_attr($link->post_title); ?>"
                                    data-url="<?php echo esc_attr($url); ?>"
                                    data-icon="<?php echo esc_attr($icon); ?>"
                                    title="Editar enlace">
                                <i class="fa-solid fa-pencil"></i>
                            </button>
                            <button type="button" class="ep-btn-delete-link" data-id="<?php echo $link->ID; ?>" title="Eliminar enlace">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <div class="ep-link-icon-wrapper">
                        <i class="<?php echo esc_attr($icon); ?>"></i>
                    </div>
                    <h3><?php echo esc_html($link->post_title); ?></h3>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Icon Picker Modal INSIDE main container -->
    <div id="ep_icon_picker_modal" class="ep-modal">
        <div class="ep-modal-content">
            <div class="ep-modal-header">
                <h3>Seleccionar Icono</h3>
                <button type="button" class="ep-modal-close" onclick="jQuery('#ep_icon_picker_modal').fadeOut(200)">&times;</button>
            </div>
            <div class="ep-modal-body">
                <div class="ep-icon-grid">
                    <?php
                    $common_icons = [
                        // Core & Web
                        'fa-solid fa-link', 'fa-solid fa-globe', 'fa-solid fa-cloud', 'fa-solid fa-shield-halved',
                        'fa-solid fa-info-circle', 'fa-solid fa-magnifying-glass', 'fa-solid fa-gear', 'fa-solid fa-rocket',
                        'fa-solid fa-house', 'fa-solid fa-earth-europe', 'fa-solid fa-compass', 'fa-solid fa-at',
                        
                        // Business & Finance
                        'fa-solid fa-briefcase', 'fa-solid fa-building', 'fa-solid fa-city', 'fa-solid fa-shop',
                        'fa-solid fa-chart-line', 'fa-solid fa-chart-bar', 'fa-solid fa-chart-pie', 'fa-solid fa-calculator',
                        'fa-solid fa-money-bill-wave', 'fa-solid fa-money-check-dollar', 'fa-solid fa-credit-card', 'fa-solid fa-file-invoice',
                        'fa-solid fa-piggy-bank', 'fa-solid fa-handshake-angle', 'fa-solid fa-users-gear', 'fa-solid fa-wallet',
                        'fa-solid fa-sack-dollar', 'fa-solid fa-coins', 'fa-solid fa-receipt', 'fa-solid fa-vault',
                        
                        // Administration & Legal
                        'fa-solid fa-landmark', 'fa-solid fa-scale-balanced', 'fa-solid fa-stamp', 'fa-solid fa-signature',
                        'fa-solid fa-file-contract', 'fa-solid fa-file-signature', 'fa-solid fa-book', 'fa-solid fa-gavel',
                        'fa-solid fa-folder-tree', 'fa-solid fa-sitemap', 'fa-solid fa-id-badge', 'fa-solid fa-passport',
                        
                        // Communication & Social
                        'fa-solid fa-envelope', 'fa-solid fa-phone', 'fa-solid fa-comments', 'fa-solid fa-bell',
                        'fa-solid fa-headset', 'fa-solid fa-video', 'fa-solid fa-share-nodes', 'fa-solid fa-paper-plane',
                        'fa-brands fa-whatsapp', 'fa-brands fa-teams', 'fa-brands fa-linkedin', 'fa-brands fa-facebook',
                        'fa-brands fa-twitter', 'fa-brands fa-instagram', 'fa-brands fa-youtube', 'fa-brands fa-skype',
                        'fa-solid fa-address-book', 'fa-solid fa-rss', 'fa-solid fa-bullhorn', 'fa-solid fa-message',
                        
                        // Documents & Folders
                        'fa-solid fa-file-pdf', 'fa-solid fa-file-word', 'fa-solid fa-file-excel', 'fa-solid fa-file-powerpoint',
                        'fa-solid fa-folder-open', 'fa-solid fa-box-archive', 'fa-solid fa-clipboard-list', 'fa-solid fa-print',
                        'fa-solid fa-file-lines', 'fa-solid fa-file-zipper', 'fa-solid fa-file-shield', 'fa-solid fa-file-import',
                        
                        // Productivity
                        'fa-solid fa-calendar-days', 'fa-solid fa-list-check', 'fa-solid fa-thumbtack', 'fa-solid fa-bullseye',
                        'fa-solid fa-lightbulb', 'fa-solid fa-brain', 'fa-solid fa-puzzle-piece', 'fa-solid fa-star',
                        'fa-solid fa-clock', 'fa-solid fa-hourglass-half', 'fa-solid fa-calendar-check', 'fa-solid fa-stopwatch',
                        
                        // Users & HR
                        'fa-solid fa-user', 'fa-solid fa-users', 'fa-solid fa-user-tie', 'fa-solid fa-graduation-cap',
                        'fa-solid fa-heart', 'fa-solid fa-id-card', 'fa-solid fa-medal', 'fa-solid fa-user-gear',
                        'fa-solid fa-user-shield', 'fa-solid fa-user-group', 'fa-solid fa-hospital-user',
                        
                        // Tech
                        'fa-solid fa-laptop', 'fa-solid fa-desktop', 'fa-solid fa-server', 'fa-solid fa-database',
                        'fa-solid fa-mobile-screen', 'fa-solid fa-microchip', 'fa-solid fa-code', 'fa-solid fa-wifi',
                        'fa-solid fa-key', 'fa-solid fa-lock', 'fa-solid fa-keyboard', 'fa-solid fa-bug',
                        
                        // Services & Tools
                        'fa-brands fa-google-drive', 'fa-brands fa-microsoft', 'fa-brands fa-dropbox', 'fa-brands fa-slack',
                        'fa-solid fa-wrench', 'fa-solid fa-hammer', 'fa-solid fa-map-location-dot', 'fa-solid fa-location-dot',
                        'fa-solid fa-truck', 'fa-solid fa-car', 'fa-solid fa-plane', 'fa-solid fa-hotel', 'fa-solid fa-umbrella',
                        'fa-solid fa-mug-hot', 'fa-solid fa-utensils', 'fa-solid fa-pills', 'fa-solid fa-kit-medical'
                    ];
                    foreach ($common_icons as $icon): ?>
                        <div class="ep-icon-item" data-icon="<?php echo $icon; ?>">
                            <i class="<?php echo $icon; ?>"></i>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    const ajax_url = '<?php echo admin_url('admin-ajax.php'); ?>';
    const nonce = '<?php echo wp_create_nonce('ep_links_nonce'); ?>';

    // Add Link
    $('#ep_add_link_form').on('submit', function(e) {
        e.preventDefault();
        
        const $btn = $(this).find('button[type="submit"]');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando...');

        const link_id = $('#link_id').val();
        const link_action = link_id ? 'edit' : 'add';

        $.ajax({
            url: ajax_url,
            type: 'POST',
            data: {
                action: 'ep_links_action',
                link_action: link_action,
                security: nonce,
                link_id: link_id,
                title: $('#link_title').val(),
                url: $('#link_url').val(),
                icon: $('#link_icon').val()
            },
            success: function(res) {
                if (res.success) {
                    Swal.fire({
                        title: '¡Éxito!',
                        text: res.data,
                        icon: 'success',
                        confirmButtonColor: '#8e44ad'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', res.data, 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            },
            error: function() {
                Swal.fire('Error', 'Error de red al conectar con el servidor.', 'error');
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    // Delete Link
    $('.ep-btn-delete-link').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const link_id = $(this).data('id');
        
        Swal.fire({
            title: '¿Estás seguro?',
            text: "Este enlace se eliminará permanentemente.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74c3c',
            cancelButtonColor: '#95a5a6',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ep_links_action',
                        link_action: 'delete',
                        security: nonce,
                        link_id: link_id
                    },
                    success: function(res) {
                        if (res.success) {
                            location.reload();
                        } else {
                            Swal.fire('Error', res.data, 'error');
                        }
                    }
                });
            }
        });
    });

    // Edit Link
    $('.ep-btn-edit-link').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const id = $(this).data('id');
        const title = $(this).data('title');
        const url = $(this).data('url');
        const icon = $(this).data('icon');
        
        $('#link_id').val(id);
        $('#link_title').val(title);
        $('#link_url').val(url);
        $('#link_icon').val(icon);
        $('#ep_icon_preview').html('<i class="' + icon + '"></i>');
        
        $('#submit_btn_text').text('Actualizar');
        $('#ep_cancel_edit').show();
        $('.ep-links-admin-form h2').html('<i class="fa-solid fa-pencil"></i> Editar Enlace');
        
        $('html, body').animate({
            scrollTop: $(".ep-links-admin-form").offset().top - 100
        }, 500);
    });

    $('#ep_cancel_edit').on('click', function() {
        $('#ep_add_link_form')[0].reset();
        $('#link_id').val('');
        $('#link_icon').val('fa-solid fa-link');
        $('#ep_icon_preview').html('<i class="fa-solid fa-link"></i>');
        $('#submit_btn_text').text('Guardar');
        $(this).hide();
        $('.ep-links-admin-form h2').html('<i class="fa-solid fa-plus-circle"></i> Gestionar Enlaces (Admin)');
    });

    // Icon Picker Logic - More direct
    $(document).on('click', '#ep_open_icon_picker', function(e) {
        e.preventDefault();
        $('#ep_icon_picker_modal').fadeIn(200).addClass('is-active');
    });

    $(document).on('click', '#ep_icon_picker_modal', function(e) {
        if (e.target === this) {
            $(this).fadeOut(200);
        }
    });

    $(document).on('click', '.ep-icon-item', function() {
        const icon = $(this).data('icon');
        $('#link_icon').val(icon);
        $('#ep_icon_preview').html('<i class="' + icon + '"></i>');
        $('.ep-icon-item').removeClass('is-selected');
        $(this).addClass('is-selected');
        $('#ep_icon_picker_modal').fadeOut(200);
    });
});
</script>
