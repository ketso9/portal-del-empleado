jQuery(document).ready(function ($) {
    // Attach event for New Item button
    $(document).on('click', '#ep-add-item-btn', function (e) {
        e.preventDefault();
        openInventoryModal();
    });

    // Close modal events
    $('.ep-close, .ep-btn-secondary').on('click', function () {
        closeInventoryModal();
        closeItinerantModal();
    });

    // Move modal to body to prevent stacking context issues
    // if ($('#ep-inventory-modal').length > 0) {
    //     $('#ep-inventory-modal').appendTo('body');
    // }

    // Select All Checkbox
    $(document).on('change', '#selectAll', function () {
        $('.item-checkbox').prop('checked', $(this).is(':checked'));
    });

    // Toggle itinerant status select
    $(document).on('change', '#item_is_itinerant', function () {
        if ($(this).is(':checked')) {
            $('#itinerant_status_container').show();
        } else {
            $('#itinerant_status_container').hide();
        }
    });
});

// Global functions for inline onclicks
function openInventoryModal() {
    if (document.getElementById('ep-inventory-form')) {
        document.getElementById('ep-inventory-form').reset();
        document.getElementById('item_id').value = 0;
        document.getElementById('modalTitle').innerText = 'Nuevo Item';
        if (document.getElementById('item_is_itinerant')) {
            document.getElementById('item_is_itinerant').checked = false;
        }
        if (document.getElementById('itinerant_status_container')) {
            document.getElementById('itinerant_status_container').style.display = 'none';
        }
        document.getElementById('ep-inventory-modal').classList.add('show');
    } else {
        console.error('Form not found');
    }
}

function closeInventoryModal() {
    const modal = document.getElementById('ep-inventory-modal');
    if (modal) modal.classList.remove('show');
}

function openItinerantModal(itemId) {
    const modal = document.getElementById('itinerant-checkout-modal');
    if (modal) {
        document.getElementById('ep-itinerant-checkout-form').reset();
        document.getElementById('checkout_item_id').value = itemId;
        modal.classList.add('show');
    }
}

function closeItinerantModal() {
    const modal = document.getElementById('itinerant-checkout-modal');
    if (modal) modal.classList.remove('show');
}

function editItem(id) {
    if (typeof ep_inventory_vars === 'undefined') {
        console.error('EP Inventory Vars not loaded');
        return;
    }
    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'ep_inventory_get_item',
            security: ep_inventory_vars.nonce,
            id: id
        },
        success: function (response) {
            if (response.success) {
                const data = response.data;
                document.getElementById('item_id').value = data.id;
                document.getElementById('item_title').value = data.title;
                document.getElementById('item_type').value = data.type;
                document.getElementById('item_serial').value = data.serial;
                document.getElementById('item_provider').value = data.provider;
                document.getElementById('item_purchase_date').value = data.purchase_date;
                document.getElementById('item_warranty_date').value = data.warranty_date;
                document.getElementById('item_assigned_to').value = data.assigned_to ? data.assigned_to : '';

                // Itinerant
                const isItinerant = data.is_itinerant === '1' || data.is_itinerant === true;
                if (document.getElementById('item_is_itinerant')) {
                    document.getElementById('item_is_itinerant').checked = isItinerant;
                }
                if (document.getElementById('item_itinerant_status')) {
                    document.getElementById('item_itinerant_status').value = data.itinerant_status || 'available';
                }
                if (document.getElementById('itinerant_status_container')) {
                    document.getElementById('itinerant_status_container').style.display = isItinerant ? 'block' : 'none';
                }

                document.getElementById('modalTitle').innerText = 'Editar Item';
                document.getElementById('ep-inventory-modal').classList.add('show');
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
}

function cloneItem(id) {
    if (typeof ep_inventory_vars === 'undefined') {
        console.error('EP Inventory Vars not loaded');
        return;
    }
    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'ep_inventory_get_item',
            security: ep_inventory_vars.nonce,
            id: id
        },
        success: function (response) {
            if (response.success) {
                const data = response.data;
                document.getElementById('item_id').value = 0; // Set to 0 to create new item on submit
                document.getElementById('item_title').value = data.title + ' (Copia)';
                document.getElementById('item_type').value = data.type;
                document.getElementById('item_serial').value = ''; // Clear serial for new entry
                document.getElementById('item_provider').value = data.provider;
                document.getElementById('item_purchase_date').value = data.purchase_date;
                document.getElementById('item_warranty_date').value = data.warranty_date;
                document.getElementById('item_assigned_to').value = data.assigned_to ? data.assigned_to : '';

                // Itinerant
                const isItinerant = data.is_itinerant === '1' || data.is_itinerant === true;
                if (document.getElementById('item_is_itinerant')) {
                    document.getElementById('item_is_itinerant').checked = isItinerant;
                }
                if (document.getElementById('item_itinerant_status')) {
                    document.getElementById('item_itinerant_status').value = data.itinerant_status || 'available';
                }
                if (document.getElementById('itinerant_status_container')) {
                    document.getElementById('itinerant_status_container').style.display = isItinerant ? 'block' : 'none';
                }

                document.getElementById('modalTitle').innerText = 'Clonar Item';
                document.getElementById('ep-inventory-modal').classList.add('show');
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
}

function deleteItem(id) {
    if (!confirm('¿Estás seguro de eliminar este item?')) return;

    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'ep_inventory_delete_item',
            security: ep_inventory_vars.nonce,
            id: id
        },
        success: function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
}

jQuery('#ep-inventory-form').on('submit', function (e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'ep_inventory_save_item');
    formData.append('security', ep_inventory_vars.nonce);

    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
});

jQuery('#ep-itinerant-checkout-form').on('submit', function (e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'ep_inventory_itinerant_action');
    formData.append('security', ep_inventory_vars.nonce);
    formData.append('op', 'check_out');

    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function (response) {
            if (response.success) {
                const msg = (response.data && typeof response.data === 'object') ? response.data.message : response.data;
                alert(msg || 'Operación realizada con éxito');
                if (response.data && response.data.download_url) {
                    window.open(response.data.download_url, '_blank');
                }
                location.reload();
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
});

function printSelectedLabels() {
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Por favor selecciona al menos un item.');
        return;
    }

    const ids = Array.from(checkboxes).map(cb => cb.value);

    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'ep_inventory_generate_labels',
            security: ep_inventory_vars.nonce,
            ids: ids
        },
        success: function (response) {
            if (response.success) {
                // Trigger download
                window.location.href = response.data.url;
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
}

// User Request Form
jQuery('#ep-request-material-form').on('submit', function (e) {
    e.preventDefault();
    const details = jQuery(this).find('textarea').val();

    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'ep_inventory_request_material',
            security: ep_inventory_vars.nonce,
            details: details
        },
        success: function (response) {
            if (response.success) {
                alert(response.data);
                jQuery('#ep-request-material-form')[0].reset();
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
});
function unassignUser(userId) {
    if (!userId) return;
    if (!confirm('¿Estás seguro de que quieres LIBERAR TODO el material asignado a este usuario?')) return;

    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'ep_inventory_unassign_user',
            security: ep_inventory_vars.nonce,
            user_id: userId
        },
        success: function (response) {
            if (response.success) {
                alert(response.data);
                location.reload();
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
}
function unassignSelected() {
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);

    if (ids.length === 0) {
        alert('Por favor selecciona al menos un item para liberar.');
        return;
    }

    if (!confirm('¿Estás seguro de que quieres LIBERAR los ' + ids.length + ' items seleccionados?')) return;

    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'ep_inventory_bulk_assign',
            security: ep_inventory_vars.nonce,
            ids: ids,
            user_id: 0
        },
        success: function (response) {
            if (response.success) {
                alert(response.data);
                location.reload();
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
}

function bulkAssignItems() {
    const userId = document.getElementById('ep-bulk-user-assign').value;
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);

    if (ids.length === 0) {
        alert('Por favor selecciona los items que quieres asignar.');
        return;
    }

    if (userId === "") {
        alert('Por favor selecciona un usuario.');
        return;
    }

    const actionText = userId === "0" ? 'LIBERAR' : 'ASIGNAR';
    if (!confirm('¿Estás seguro de que quieres ' + actionText + ' los ' + ids.length + ' items seleccionados?')) return;

    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'ep_inventory_bulk_assign',
            security: ep_inventory_vars.nonce,
            ids: ids,
            user_id: userId
        },
        success: function (response) {
            if (response.success) {
                alert(response.data);
                location.reload();
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
}

function itinerantCheckOut(itemId) {
    openItinerantModal(itemId);
}

function itinerantBulkCheckOut() {
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Por favor selecciona al menos un item.');
        return;
    }
    const ids = Array.from(checkboxes).map(cb => cb.value).join(',');
    openItinerantModal(ids);
}


function itinerantCheckIn(itemId) {
    if (!confirm('¿Confirmas la recepción del equipo y su vuelta al inventario disponible?')) return;

    jQuery.ajax({
        url: ep_inventory_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'ep_inventory_itinerant_action',
            security: ep_inventory_vars.nonce,
            item_id: itemId,
            op: 'check_in'
        },
        success: function (response) {
            if (response.success) {
                alert(response.data);
                location.reload();
            } else {
                alert('Error: ' + response.data);
            }
        }
    });
}

/**
 * USER MANAGEMENT FUNCTIONS
 */

let currentAssignUserId = 0;

function openAssignModal(userId, userName) {
    currentAssignUserId = userId;
    const modal = document.getElementById('epAssignModal');
    const nameSpan = document.getElementById('assign-user-name');
    const listDiv = document.getElementById('available-items-list');

    if (!modal || !nameSpan || !listDiv) return;

    nameSpan.innerText = userName;
    listDiv.innerHTML = '<p style="text-align:center; padding:20px;">Cargando material disponible...</p>';
    modal.classList.add('show');

    fetch(ep_inventory_vars.ajax_url + '?action=ep_inventory_get_available_items&security=' + ep_inventory_vars.nonce)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                let html = '<div style="display:flex; flex-direction:column; gap:10px;">';
                data.data.forEach(item => {
                    html += `
                        <div style="display:flex; align-items:center; justify-content:space-between; padding:10px; background:#f8fafc; border-radius:6px; border:1px solid #e2e8f0;">
                            <div>
                                <strong style="font-size:13px; color:var(--ep-text);">${item.title}</strong><br>
                                <small style="color:var(--ep-text-muted);">${item.serial || 'Sin S/N'}</small>
                            </div>
                            <button class="ep-btn ep-btn-sm ep-btn-primary" onclick="assignItemToUser(${item.id})">
                                <i class="fa-solid fa-plus"></i> Asignar
                            </button>
                        </div>
                    `;
                });
                html += '</div>';
                listDiv.innerHTML = html;
            } else {
                listDiv.innerHTML = '<p style="text-align:center; padding:20px; color:var(--ep-text-muted);">No hay material fijo disponible para asignar.</p>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            listDiv.innerHTML = '<p style="text-align:center; padding:20px; color:var(--ep-danger);">Error al cargar material.</p>';
        });
}

function closeAssignModal() {
    const modal = document.getElementById('epAssignModal');
    if (modal) modal.classList.remove('show');
}

function assignItemToUser(itemId) {
    if (!currentAssignUserId || !itemId) return;

    const formData = new FormData();
    formData.append('action', 'ep_inventory_assign_item_to_user');
    formData.append('item_id', itemId);
    formData.append('user_id', currentAssignUserId);
    formData.append('security', ep_inventory_vars.nonce);

    fetch(ep_inventory_vars.ajax_url, {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error de conexión');
        });
}

function unassignSingleItem(itemId) {
    if (!itemId) return;
    if (!confirm('¿Deseas liberar este item del usuario?')) return;

    const formData = new FormData();
    formData.append('action', 'ep_inventory_bulk_assign');
    formData.append('item_ids[]', itemId);
    formData.append('user_id', 0);
    formData.append('security', ep_inventory_vars.nonce);

    fetch(ep_inventory_vars.ajax_url, {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.data);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error de conexión');
        });
}

function uploadSignedInventoryDoc(userId, input) {
    if (!input.files || !input.files[0]) return;

    const formData = new FormData();
    formData.append('action', 'ep_inventory_upload_signed_commitment');
    formData.append('user_id', userId);
    formData.append('signed_doc', input.files[0]);
    formData.append('security', ep_inventory_vars.nonce);

    input.disabled = true;

    fetch(ep_inventory_vars.ajax_url, {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Documento subido y registrado correctamente.');
                location.reload();
            } else {
                alert('Error: ' + data.data);
                input.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error de conexión');
            input.disabled = false;
        });
}

// ============================================================
// INLINE CHIP EDITOR — Ubicación y Notas de Inventario
// ============================================================
function epChipEdit(spanEl) {
    if (spanEl.querySelector('input')) return; // ya en edición

    const field  = spanEl.dataset.field;
    const itemId = spanEl.dataset.id;

    // Captura el texto actual (ignorando el placeholder)
    const currentText = spanEl.querySelector('.ep-chip-placeholder')
        ? ''
        : spanEl.innerText.trim();

    // Reemplaza el span por un input
    const input = document.createElement('input');
    input.type  = 'text';
    input.value = currentText;
    input.className = 'ep-chip-input';
    input.placeholder = field === 'location' ? 'Ej: Sala 3, Planta 2...' : 'Observación...';
    input.style.cssText = 'width:140px;font-size:12px;padding:2px 6px;border:1px solid #6366f1;border-radius:4px;outline:none;';

    spanEl.innerHTML = '';
    spanEl.appendChild(input);
    input.focus();

    function saveChip() {
        const newValue = input.value.trim();
        spanEl.innerHTML = newValue
            ? newValue
            : '<span class="ep-chip-placeholder">' + (field === 'location' ? 'Ubicación...' : 'Observaciones...') + '</span>';

        // AJAX save
        const fd = new FormData();
        fd.append('action',   'ep_inventory_save_inline');
        fd.append('security', ep_inventory_vars.nonce);
        fd.append('id',       itemId);
        fd.append('field',    field);
        fd.append('value',    newValue);

        fetch(ep_inventory_vars.ajax_url, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Feedback visual: parpadeo verde
                    spanEl.style.transition = 'background 0.3s';
                    spanEl.style.background = '#d1fae5';
                    setTimeout(() => { spanEl.style.background = ''; }, 1000);
                } else {
                    console.warn('epChipEdit save error:', data.data);
                }
            })
            .catch(err => console.error('epChipEdit fetch error:', err));
    }

    input.addEventListener('blur',  saveChip);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter')  { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { spanEl.innerHTML = currentText || '<span class="ep-chip-placeholder">' + (field === 'location' ? 'Ubicación...' : 'Observaciones...') + '</span>'; }
    });
}

