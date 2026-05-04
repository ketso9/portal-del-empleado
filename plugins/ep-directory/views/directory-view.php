<?php
/**
 * Directory View Template
 * Variables available: $users
 */
?>
<div class="ep-directory-container">
    <div class="ep-directory-header" style="display: flex; justify-content: space-between; align-items: center; gap: 15px;">
        <h1 style="margin:0;">Directorio de Personal</h1>
        <div style="display: flex; gap: 10px;">
            <button class="button ep-btn-secondary" id="ep-export-pdf-btn" onclick="epExportPDF('<?php echo wp_create_nonce('ep_directory_export_pdf'); ?>')" style="padding: 8px 16px; background-color: #9e1c2e; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-file-pdf"></i> Exportar PDF
            </button>
            <?php if (current_user_can('manage_options')): ?>
                <button class="button ep-btn-primary" id="ep-sync-photos-btn" onclick="epForceSyncPhotos(this, '<?php echo wp_create_nonce('ep_directory_sync_photos'); ?>')" style="padding: 8px 16px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-sync"></i> Forzar Sincronización de Fotos
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="ep-directory-grid">
        <?php foreach ($users as $user):
            $photo_url = get_user_meta($user->ID, 'ep_user_photo_url', true);
            if (empty($photo_url)) {
                $photo_url = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($user->user_email))) . '?d=mp&s=200';
            }
            $job_title = get_user_meta($user->ID, 'ep_job_title', true);
            $phone = get_user_meta($user->ID, 'ep_mobile_phone', true);
            $office_phone = get_user_meta($user->ID, 'ep_business_phone', true);
            $email = $user->user_email;
            ?>
            <div class="ep-employee-card" data-user-id="<?php echo $user->ID; ?>">
                <div class="ep-employee-photo-frame">
                    <img src="<?php echo esc_url($photo_url); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
                </div>

                <h3 class="ep-employee-name"><?php echo esc_html($user->display_name); ?></h3>

                <div class="ep-employee-info">
                    <?php if ($job_title): ?>
                        <div class="ep-job-title"><?php echo esc_html($job_title); ?></div>
                    <?php endif; ?>
                </div>

                <div class="ep-employee-contact">
                    <?php if ($email): ?>
                        <a href="mailto:<?php echo esc_attr($email); ?>" class="ep-contact-item" title="Enviar correo">
                            <i class="fa-solid fa-envelope"></i> <?php echo esc_html($email); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($office_phone): ?>
                        <a href="tel:<?php echo esc_attr($office_phone); ?>" class="ep-contact-item" title="Llamar a extensión">
                            <i class="fa-solid fa-phone"></i> Extensión: <?php echo esc_html($office_phone); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="ep-employee-actions">
                    <?php if ($email): ?>
                        <a href="mailto:<?php echo esc_attr($email); ?>" class="ep-action-icon" title="Enviar Email">
                            <i class="fa-solid fa-envelope"></i>
                        </a>
                        <a href="https://teams.microsoft.com/l/chat/0/0?users=<?php echo esc_attr($email); ?>"
                            class="ep-action-icon ep-teams-chat" title="Chat de Teams" target="_blank">
                            <i class="fa-brands fa-microsoft"></i>
                        </a>
                        <a href="msteams:/l/call/0/0?users=<?php echo esc_attr($email); ?>" class="ep-action-icon ep-teams-call"
                            title="Llamada de Teams">
                            <i class="fa-solid fa-video"></i>
                        </a>
                    <?php endif; ?>

                    <?php if ($phone || $office_phone): ?>
                        <a href="tel:<?php echo esc_attr($phone ?: $office_phone); ?>" class="ep-action-icon"
                            title="Llamar al teléfono">
                            <i class="fa-solid fa-phone"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Presence status footer -->
                <div class="ep-presence-footer" data-presence-user="<?php echo $user->ID; ?>" style="display:none;">
                    <span class="ep-presence-dot"></span>
                    <span class="ep-presence-text">Cargando...</span>
                </div>

                <button class="ep-vcard-btn"
                    onclick="epDownloadVCard(<?php echo $user->ID; ?>, '<?php echo wp_create_nonce('ep_download_vcard_' . $user->ID); ?>')">
                    <i class="fa-solid fa-download"></i> ¡Guárdame!
                </button>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    function epDownloadVCard(userId, nonce) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = ''; // Current URL

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'ep_directory_action';
        actionInput.value = 'download_vcard';
        form.appendChild(actionInput);

        const userIdInput = document.createElement('input');
        userIdInput.type = 'hidden';
        userIdInput.name = 'user_id';
        userIdInput.value = userId;
        form.appendChild(userIdInput);

        const nonceInput = document.createElement('input');
        nonceInput.type = 'hidden';
        nonceInput.name = 'vcard_nonce';
        nonceInput.value = nonce;
        form.appendChild(nonceInput);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    function epExportPDF(nonce) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = ''; // Current URL

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'ep_directory_action';
        actionInput.value = 'export_pdf';
        form.appendChild(actionInput);

        const nonceInput = document.createElement('input');
        nonceInput.type = 'hidden';
        nonceInput.name = 'pdf_nonce';
        nonceInput.value = nonce;
        form.appendChild(nonceInput);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    function epForceSyncPhotos(btnElement, nonce) {
        if (!confirm("¿Seguro que deseas forzar la sincronización de fotos? Este proceso puede tardar varios segundos.")) return;
        
        const originalText = btnElement.innerHTML;
        btnElement.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sincronizando...';
        btnElement.disabled = true;

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ep_directory_ajax',
                sub_action: 'sync_photos',
                security: nonce
            })
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                alert(res.data);
                window.location.reload();
            } else {
                alert('Error: ' + res.data);
                btnElement.innerHTML = originalText;
                btnElement.disabled = false;
            }
        })
        .catch(err => {
            alert('Error de red al sincronizar las fotos');
            btnElement.innerHTML = originalText;
            btnElement.disabled = false;
        });
    }

    // Teams Presence Integration
    (function () {
        const presenceColors = {
            'Available': '#00cc6a',
            'Busy': '#e74c3c',
            'DoNotDisturb': '#c0392b',
            'BeRightBack': '#f39c12',
            'Away': '#f39c12',
            'Offline': '#95a5a6',
            'PresenceUnknown': '#bdc3c7',
            'InACall': '#e74c3c',
            'InAConferenceCall': '#e74c3c',
            'InAMeeting': '#e74c3c',
            'Presenting': '#9b59b6',
            'UrgentInterruptionsOnly': '#c0392b',
            'OutOfOffice': '#95a5a6'
        };

        const presenceLabels = {
            'Available': 'Disponible',
            'Busy': 'Ocupado',
            'DoNotDisturb': 'No molestar',
            'BeRightBack': 'Vuelvo enseguida',
            'Away': 'Ausente',
            'Offline': 'Desconectado',
            'PresenceUnknown': 'Desconocido',
            'InACall': 'En llamada',
            'InAConferenceCall': 'En conferencia',
            'InAMeeting': 'En reunión',
            'Presenting': 'Presentando',
            'UrgentInterruptionsOnly': 'Solo urgentes',
            'OutOfOffice': 'Fuera de oficina'
        };

        // Fetch presence for all users
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ep_directory_ajax',
                sub_action: 'get_presence',
                security: '<?php echo wp_create_nonce('ep_directory_presence'); ?>'
            })
        })
            .then(r => r.json())
            .then(res => {
                if (!res.success || !res.data) return;

                const presenceData = res.data;

                document.querySelectorAll('.ep-employee-card[data-user-id]').forEach(card => {
                    const uid = card.dataset.userId;
                    const presence = presenceData[uid];
                    if (!presence) return;

                    const availability = presence.availability || 'Offline';
                    const color = presenceColors[availability] || '#95a5a6';
                    const label = presenceLabels[availability] || availability;

                    // Color the photo border
                    const photoFrame = card.querySelector('.ep-employee-photo-frame');
                    if (photoFrame) {
                        photoFrame.style.borderColor = color;
                        photoFrame.style.borderWidth = '3px';
                        photoFrame.style.borderStyle = 'solid';
                    }

                    // Show presence footer
                    const footer = card.querySelector('.ep-presence-footer');
                    if (footer) {
                        footer.style.display = 'flex';
                        const dot = footer.querySelector('.ep-presence-dot');
                        const text = footer.querySelector('.ep-presence-text');
                        if (dot) dot.style.backgroundColor = color;
                        if (text) text.textContent = label;
                    }
                });
            })
            .catch(err => console.log('Presence load error:', err));
    })();
</script>