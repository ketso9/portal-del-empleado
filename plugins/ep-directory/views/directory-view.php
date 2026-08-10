<?php
/**
 * Directory View Template
 * Variables available: $users
 */

// Collect unique departments
$departments = array();
foreach ($users as $u) {
    $dept = get_user_meta($u->ID, 'ep_department', true);
    if (!empty($dept) && !in_array($dept, $departments)) {
        $departments[] = $dept;
    }
}
sort($departments);
?>
<div class="ep-directory-container">
    <div class="ep-directory-header" style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">
        <h1 style="margin:0; font-size:1.6rem; font-weight:800; color:var(--ep-text, #1e293b);"><i class="fa-solid fa-address-book" style="color:#0078d4;"></i> Directorio de Personal</h1>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button class="button ep-btn-secondary" id="ep-export-pdf-btn" onclick="epExportPDF('<?php echo wp_create_nonce('ep_directory_export_pdf'); ?>')" style="padding: 8px 16px; background-color: #9e1c2e; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-file-pdf"></i> Exportar PDF
            </button>
            <?php if (current_user_can('manage_options')): ?>
                <button class="button ep-btn-primary" id="ep-sync-photos-btn" onclick="epForceSyncPhotos(this, '<?php echo wp_create_nonce('ep_directory_sync_photos'); ?>')" style="padding: 8px 16px; background-color: #0078d4; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-sync"></i> Sincronizar M365
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="ep-dir-toolbar">
        <div class="ep-dir-toolbar-row">
            <div class="ep-dir-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="ep-dir-search" placeholder="Buscar por nombre, puesto o email..." class="ep-input">
            </div>

            <div class="ep-dir-dept-wrap">
                <select id="ep-dir-dept-filter" class="ep-input">
                    <option value="">Todos los departamentos</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo esc_attr($dept); ?>"><?php echo esc_html($dept); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="ep-dir-status-chips" id="ep-dir-status-chips">
            <button type="button" class="ep-status-chip active" data-status="">
                <i class="fa-solid fa-users-line"></i>
                <span>Todos</span>
                <span class="chip-count" id="count-all">0</span>
            </button>
            <button type="button" class="ep-status-chip status-available" data-status="Available">
                <i class="fa-solid fa-circle"></i>
                <span>Disponibles</span>
                <span class="chip-count" id="count-available">0</span>
            </button>
            <button type="button" class="ep-status-chip status-busy" data-status="Busy">
                <i class="fa-solid fa-circle"></i>
                <span>Ocupados</span>
                <span class="chip-count" id="count-busy">0</span>
            </button>
            <button type="button" class="ep-status-chip status-away" data-status="Away">
                <i class="fa-solid fa-circle"></i>
                <span>Ausentes</span>
                <span class="chip-count" id="count-away">0</span>
            </button>
            <button type="button" class="ep-status-chip status-oof" data-status="OutOfOffice">
                <i class="fa-solid fa-umbrella-beach"></i>
                <span>Fuera de la oficina</span>
                <span class="chip-count" id="count-oof">0</span>
            </button>
            <button type="button" class="ep-status-chip status-offline" data-status="Offline">
                <i class="fa-solid fa-circle"></i>
                <span>Desconectados</span>
                <span class="chip-count" id="count-offline">0</span>
            </button>
        </div>
    </div>

    <div class="ep-directory-grid">
        <?php foreach ($users as $user):
            $photo_url = get_user_meta($user->ID, 'ep_user_photo_url', true);
            if (empty($photo_url)) {
                $photo_url = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($user->user_email))) . '?d=mp&s=200';
            }
            $job_title = get_user_meta($user->ID, 'ep_job_title', true);
            $department = get_user_meta($user->ID, 'ep_department', true);
            $phone = get_user_meta($user->ID, 'ep_mobile_phone', true);
            $office_phone = get_user_meta($user->ID, 'ep_business_phone', true);
            $email = $user->user_email;
            $saved_presence = get_user_meta($user->ID, 'ep_teams_presence', true) ?: 'Offline';
            ?>
            <div class="ep-employee-card" data-user-id="<?php echo $user->ID; ?>" data-department="<?php echo esc_attr($department); ?>" data-presence-status="<?php echo esc_attr($saved_presence); ?>" data-search-text="<?php echo esc_attr(strtolower($user->display_name . ' ' . $job_title . ' ' . $department . ' ' . $email)); ?>">
                <div class="ep-employee-photo-frame" style="position:relative;">
                    <img src="<?php echo esc_url($photo_url); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
                    <span class="ep-presence-badge-dot" style="position:absolute; bottom:4px; right:4px; width:14px; height:14px; border-radius:50%; border:2px solid #fff; background:#95a5a6;" title="Estado Teams"></span>
                </div>

                <h3 class="ep-employee-name" style="margin-top:10px; margin-bottom:4px;"><?php echo esc_html($user->display_name); ?></h3>

                <?php if ($department): ?>
                    <div style="margin-bottom:6px;"><span class="ep-dept-badge" style="background:rgba(0,120,212,0.08); color:#0078d4; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; display:inline-block;"><?php echo esc_html($department); ?></span></div>
                <?php endif; ?>

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
                    <?php if ($phone): ?>
                        <a href="tel:<?php echo esc_attr($phone); ?>" class="ep-contact-item" title="Llamar al móvil">
                            <i class="fa-solid fa-mobile-screen"></i> Móvil: <?php echo esc_html($phone); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="ep-employee-actions" style="display:flex; gap:8px; justify-content:center; margin-top:12px;">
                    <?php if ($email): ?>
                        <a href="mailto:<?php echo esc_attr($email); ?>" class="ep-action-icon" title="Enviar Email">
                            <i class="fa-solid fa-envelope"></i>
                        </a>
                        <a href="https://teams.microsoft.com/l/chat/0/0?users=<?php echo urlencode($email); ?>"
                            class="ep-action-icon ep-teams-chat" title="Chat directo en Teams" target="_blank" style="background:#464eb8; color:#fff;">
                            <i class="fa-brands fa-microsoft"></i>
                        </a>
                        <a href="https://teams.microsoft.com/l/call/0/0?users=<?php echo urlencode($email); ?>" class="ep-action-icon ep-teams-call"
                            title="Llamada de Teams" target="_blank" style="background:#10b981; color:#fff;">
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
                <div class="ep-presence-footer" data-presence-user="<?php echo $user->ID; ?>" style="display:flex; align-items:center; justify-content:center; gap:6px; margin-top:10px; font-size:11px; color:#64748b;">
                    <span class="ep-presence-dot" style="width:8px; height:8px; border-radius:50%; background:#95a5a6;"></span>
                    <span class="ep-presence-text">Cargando...</span>
                </div>

                <button class="ep-vcard-btn" style="margin-top:10px; border-radius:6px;"
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
        let activeStatusFilter = '';

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
            'OutOfOffice': '#8b5cf6'
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
            'OutOfOffice': 'Fuera de la oficina'
        };

        function updateChipCounts() {
            let counts = { all: 0, available: 0, busy: 0, away: 0, oof: 0, offline: 0 };
            document.querySelectorAll('.ep-employee-card[data-user-id]').forEach(card => {
                counts.all++;
                const cardStatus = card.dataset.presenceStatus || 'Offline';
                const isOof      = card.dataset.isOof === 'true' || cardStatus === 'OutOfOffice';

                if (isOof) {
                    counts.oof++;
                } else if (cardStatus === 'Available' || cardStatus === 'AvailableIdle') {
                    counts.available++;
                } else if (['Busy', 'BusyIdle', 'DoNotDisturb', 'InACall', 'InAConferenceCall', 'InAMeeting', 'Presenting', 'UrgentInterruptionsOnly'].includes(cardStatus)) {
                    counts.busy++;
                } else if (['Away', 'BeRightBack'].includes(cardStatus)) {
                    counts.away++;
                } else {
                    counts.offline++;
                }
            });

            const setVal = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = val;
            };

            setVal('count-all', counts.all);
            setVal('count-available', counts.available);
            setVal('count-busy', counts.busy);
            setVal('count-away', counts.away);
            setVal('count-oof', counts.oof);
            setVal('count-offline', counts.offline);
        }

        function filterDirectory() {
            const searchText = (document.getElementById('ep-dir-search')?.value || '').toLowerCase().trim();
            const deptText   = (document.getElementById('ep-dir-dept-filter')?.value || '').toLowerCase().trim();
            const statusVal  = activeStatusFilter;

            document.querySelectorAll('.ep-employee-card[data-user-id]').forEach(card => {
                const cardSearch = (card.dataset.searchText || '').toLowerCase();
                const cardDept   = (card.dataset.department || '').toLowerCase();
                const cardStatus = card.dataset.presenceStatus || 'Offline';
                const isOof      = card.dataset.isOof === 'true' || cardStatus === 'OutOfOffice';

                const matchesSearch = !searchText || cardSearch.includes(searchText);
                const matchesDept   = !deptText || cardDept === deptText;

                let matchesStatus = true;
                if (statusVal) {
                    if (statusVal === 'Available') {
                        matchesStatus = (cardStatus === 'Available' || cardStatus === 'AvailableIdle') && !isOof;
                    } else if (statusVal === 'Busy') {
                        matchesStatus = ['Busy', 'BusyIdle', 'DoNotDisturb', 'InACall', 'InAConferenceCall', 'InAMeeting', 'Presenting', 'UrgentInterruptionsOnly'].includes(cardStatus) && !isOof;
                    } else if (statusVal === 'Away') {
                        matchesStatus = ['Away', 'BeRightBack'].includes(cardStatus) && !isOof;
                    } else if (statusVal === 'OutOfOffice') {
                        matchesStatus = isOof;
                    } else if (statusVal === 'Offline') {
                        matchesStatus = ['Offline', 'PresenceUnknown', ''].includes(cardStatus) && !isOof;
                    }
                }

                const isVisible = matchesSearch && matchesDept && matchesStatus;
                if (isVisible) {
                    card.classList.remove('hidden');
                    card.style.display = 'flex';
                } else {
                    card.classList.add('hidden');
                    card.style.display = 'none';
                }
            });
        }

        // Chip click delegation
        document.getElementById('ep-dir-status-chips')?.addEventListener('click', function (e) {
            const btn = e.target.closest('.ep-status-chip');
            if (!btn) return;

            document.querySelectorAll('#ep-dir-status-chips .ep-status-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeStatusFilter = btn.getAttribute('data-status') || '';
            filterDirectory();
        });

        document.getElementById('ep-dir-search')?.addEventListener('input', filterDirectory);
        document.getElementById('ep-dir-dept-filter')?.addEventListener('change', filterDirectory);

        // Initial setup
        updateChipCounts();
        filterDirectory();

        // Fetch presence for all users from M365 Graph
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

                    const isOof = presence.is_oof || presence.availability === 'OutOfOffice';
                    const availability = isOof ? 'OutOfOffice' : (presence.availability || 'Offline');
                    const color = presenceColors[availability] || '#95a5a6';
                    const label = presenceLabels[availability] || availability;

                    card.dataset.presenceStatus = availability;
                    card.dataset.isOof = isOof ? 'true' : 'false';

                    // Color the photo border & badge dot
                    const photoFrame = card.querySelector('.ep-employee-photo-frame');
                    if (photoFrame) {
                        photoFrame.style.borderColor = color;
                        photoFrame.style.borderWidth = '3px';
                        photoFrame.style.borderStyle = 'solid';
                    }

                    const badgeDot = card.querySelector('.ep-presence-badge-dot');
                    if (badgeDot) {
                        badgeDot.style.backgroundColor = color;
                        badgeDot.title = 'Teams: ' + label;
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

                    // Render Out of Office card banner if active
                    if (isOof) {
                        card.classList.add('is-oof');
                        let oofBanner = card.querySelector('.ep-card-oof-banner');
                        if (!oofBanner) {
                            oofBanner = document.createElement('div');
                            oofBanner.className = 'ep-card-oof-banner';
                            const photoFrame = card.querySelector('.ep-employee-photo-frame');
                            if (photoFrame && photoFrame.nextSibling) {
                                card.insertBefore(oofBanner, photoFrame.nextSibling);
                            } else {
                                card.appendChild(oofBanner);
                            }
                        }
                        const noteSnippet = presence.oof_message ? `<span class="ep-oof-note-text">: "${presence.oof_message}"</span>` : '';
                        oofBanner.innerHTML = `<i class="fa-solid fa-umbrella-beach"></i> Fuera de la oficina ${noteSnippet}`;
                        if (presence.oof_message) {
                            oofBanner.title = 'Nota de fuera de la oficina: ' + presence.oof_message;
                        }
                    }
                });

                updateChipCounts();
                filterDirectory();
            })
            .catch(err => console.log('Presence load error:', err));
    })();
</script>