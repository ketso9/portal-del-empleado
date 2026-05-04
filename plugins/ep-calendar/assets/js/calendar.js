document.addEventListener('DOMContentLoaded', function () {
    console.warn('EP Calendar: JS v1.0.1 Loaded - Monitoring Sync...');
    const calendarEl = document.getElementById('ep-fullcalendar');
    if (!calendarEl) return;

    // Microsoft Graph Color Palette Map
    const GRAPH_COLORS = {
        'lightBlue': '#3b82f6',
        'lightGreen': '#10b981',
        'lightOrange': '#f59e0b',
        'lightGray': '#94a3b8',
        'lightYellow': '#eab308',
        'lightTeal': '#14b8a6',
        'lightPink': '#ec4899',
        'lightBrown': '#a16207',
        'lightRed': '#ef4444',
        'maxColor': '#6366f1',
        'auto': '#3b82f6' // Default fallback
    };

    let calendarColors = {}; // Store ID -> Hex mapping

    // Load FullCalendar from CDN dynamically if not present
    if (typeof FullCalendar === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js';
        script.onload = initCalendar;
        document.head.appendChild(script);
    } else {
        initCalendar();
    }

    let calendar;

    function initCalendar() {
        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            locale: 'es',
            firstDay: 1, // Monday
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: '' // Handled by our custom buttons
            },
            events: function (fetchInfo, successCallback, failureCallback) {
                fetchEvents(fetchInfo.startStr, fetchInfo.endStr, successCallback, failureCallback);
            },
            eventClick: function (info) {
                showEventDetails(info.event);
            },
            height: 'auto',
            nowIndicator: true,
            buttonText: {
                today: 'Hoy'
            }
        });

        calendar.render();

        // Custom View Switchers
        document.querySelectorAll('.btn-view').forEach(btn => {
            btn.addEventListener('click', function () {
                const view = this.getAttribute('data-view');
                calendar.changeView(view);

                document.querySelectorAll('.btn-view').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // Initialize user calendars
        initCalendarList();
    }

    function logDebug(message, isError = false) {
        const list = document.getElementById('debug-log-list');
        const debugDiv = document.getElementById('ep-calendar-debug');
        if (!list) return;

        if (window.EP_DEBUG) debugDiv.style.display = 'block';

        const li = document.createElement('li');
        li.style.color = isError ? '#721c24' : '#155724';
        li.innerText = `[${new Date().toLocaleTimeString()}] ${message}`;
        list.appendChild(li);
    }

    function fetchEvents(start, end, successCallback, failureCallback) {
        logDebug(`Fetching events from ${start} to ${end}...`);
        const formData = new FormData();
        formData.append('action', 'ep_get_calendar_events');
        formData.append('nonce', window.epCalendarData.nonce);
        formData.append('start', start);
        formData.append('end', end);

        fetch(window.epCalendarData.ajaxUrl, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                console.log('EP Calendar: Data received:', data);
                if (data.success) {
                    const events = data.data.events || [];
                    const debug = data.data.debug || {};

                    logDebug(`Received ${events.length} events for ${start}.`);
                    console.log('EP Calendar Debug Detailed:', debug);

                    if (events.length === 0) {
                        logDebug(`Debug details: ${JSON.stringify(debug)}`);
                    }

                    // Assign colors to events
                    const coloredEvents = events.map(ev => {
                        if (ev.sourceCalendar && calendarColors[ev.sourceCalendar]) {
                            ev.backgroundColor = calendarColors[ev.sourceCalendar];
                            ev.borderColor = calendarColors[ev.sourceCalendar];
                        }
                        return ev;
                    });

                    successCallback(coloredEvents);
                } else {
                    console.error('EP Calendar: Server error:', data.data);
                    const errorMsg = data.data?.message || data.data || 'Error desconocido';
                    logDebug(`Server Error: ${errorMsg}`, true);
                    document.getElementById('ep-calendar-debug').style.display = 'block';
                    failureCallback(data.data);
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                logDebug(`Fetch Error: ${error.message}`, true);
                failureCallback(error);
            });
    }

    function showEventDetails(event) {
        const modal = document.getElementById('event-detail-modal');
        const title = document.getElementById('modal-event-title');
        const time = document.getElementById('modal-event-time');
        const location = document.getElementById('modal-event-location');
        const locRow = document.getElementById('modal-location-row');
        const desc = document.getElementById('modal-event-description');

        title.innerText = event.title;

        // Format dates
        const start = new Date(event.start);
        const end = new Date(event.end);
        const options = { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' };

        if (event.allDay) {
            time.innerText = start.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' }) + ' (Todo el día)';
        } else {
            time.innerText = start.toLocaleString('es-ES', options) + ' - ' + end.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        }

        if (event.extendedProps.location) {
            location.innerText = event.extendedProps.location;
            locRow.style.display = 'flex';
        } else {
            locRow.style.display = 'none';
        }

        desc.innerHTML = event.extendedProps.description || 'Sin descripción adicional.';

        modal.style.display = 'block';
    }

    // Modal Closing
    document.querySelectorAll('.close-calendar-modal').forEach(btn => {
        btn.onclick = function () {
            document.getElementById('event-detail-modal').style.display = 'none';
        }
    });

    window.onclick = function (event) {
        const modal = document.getElementById('event-detail-modal');
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    }

    // --- Multi-Calendar Logic ---

    function initCalendarList() {
        const listContainer = document.getElementById('ep-calendar-list');
        const loading = document.getElementById('ep-calendar-list-loading');
        if (!listContainer) return;

        const formData = new FormData();
        formData.append('action', 'ep_get_calendars');
        formData.append('nonce', window.epCalendarData.nonce);

        fetch(window.epCalendarData.ajaxUrl, {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (loading) loading.style.display = 'none';
                if (data.success) {
                    console.log('EP Calendar Debug:', data.data.debug); // Log backend debug
                    const calendarList = data.data.calendars || data.data.calendars; // Support nested or flat
                    const list = Array.isArray(calendarList) ? calendarList : [];
                    logDebug(`Loaded ${list.length} calendars.`);
                    renderCalendarList(list);
                    listContainer.style.display = 'block';
                    if (calendar) calendar.refetchEvents();
                } else {
                    console.error('Error fetching calendars:', data.data);
                    logDebug(`Error fetching calendars: ${JSON.stringify(data.data)}`, true);
                    document.getElementById('ep-calendar-debug').style.display = 'block';

                    // Detect Auth Error
                    if (data.data?.code === 'auth_error') {
                        listContainer.innerHTML = `
                            <div style="padding:10px; background:#fff3cd; border:1px solid #ffeeba; border-radius:8px; color:#856404; font-size:12px; line-height:1.4;">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                Tu sesión de Office 365 ha caducado o no tiene permisos suficientes.
                                <a href="/?view=profile" style="display:block; margin-top:5px; font-weight:600; color:#856404;">Reconectar cuenta</a>
                            </div>
                        `;
                    } else {
                        listContainer.innerHTML = '<p style="color:red; font-size:12px;">Error cargando calendarios.</p>';
                    }
                    listContainer.style.display = 'block';
                }
            })
            .catch(err => {
                console.error(err);
                if (loading) loading.style.display = 'none';
            });
    }

    function renderCalendarList(calendars) {
        const listContainer = document.getElementById('ep-calendar-list');
        listContainer.innerHTML = '';
        calendarColors = {}; // Reset map

        calendars.forEach(cal => {
            const item = document.createElement('div');
            item.className = 'calendar-checkbox-item';

            // Resolve color: Check Custom -> Graph -> Default
            let hexColor = '#3b82f6';
            if (cal.customColor) {
                hexColor = cal.customColor;
            } else if (cal.color && GRAPH_COLORS[cal.color]) {
                hexColor = GRAPH_COLORS[cal.color];
            } else if (cal.color && cal.color.startsWith('#')) {
                hexColor = cal.color;
            }

            // Store for event rendering
            calendarColors[cal.id] = hexColor;

            item.innerHTML = `
                <div style="display:flex; align-items:center; gap:8px; width:100%;">
                    <input type="checkbox" id="cal_${cal.id}" value="${cal.id}" ${cal.selected ? 'checked' : ''} style="margin:0;">
                    
                    <input type="color" class="visible-color-picker" 
                           value="${hexColor}" 
                           data-cal-id="${cal.id}"
                           title="Clic para cambiar color"
                           style="border:none; width:24px; height:24px; padding:0; background:none; cursor:pointer;">
                    
                    <label for="cal_${cal.id}" title="${cal.name}" style="flex:1; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${cal.name}</label>
                </div>
            `;
            listContainer.appendChild(item);
        });

        // Add Listeners
        // 1. Checkbox changes
        document.querySelectorAll('#ep-calendar-list input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', () => {
                saveCalendarPreferences();
            });
        });

        // 2. Color Picker changes
        document.querySelectorAll('.visible-color-picker').forEach(picker => {
            picker.addEventListener('change', (e) => {
                const newColor = e.target.value;
                const calId = e.target.getAttribute('data-cal-id');
                saveCalendarColor(calId, newColor);
            });
            // Stop click propagation to prevent checking/unchecking the row
            picker.addEventListener('click', (e) => e.stopPropagation());
        });
    }

    function saveCalendarColor(calId, hexColor) {
        logDebug(`Saving color ${hexColor} for calendar ${calId}`);

        // Update local map immediately for speed
        calendarColors[calId] = hexColor;
        // Refetch/Render events to see changes instantly
        calendar.refetchEvents();

        const formData = new FormData();
        formData.append('action', 'ep_save_calendar_color');
        formData.append('nonce', window.epCalendarData.nonce);
        formData.append('id', calId);
        formData.append('color', hexColor);

        fetch(window.epCalendarData.ajaxUrl, {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    console.error('Error saving color:', data);
                } else {
                    logDebug('Color saved successfully.');
                }
            });
    }

    function saveCalendarPreferences() {
        const selected = [];
        document.querySelectorAll('#ep-calendar-list input[type="checkbox"]:checked').forEach(cb => {
            selected.push(cb.value);
        });

        const formData = new FormData();
        formData.append('action', 'ep_save_calendar_prefs');
        formData.append('nonce', window.epCalendarData.nonce);
        selected.forEach(id => formData.append('calendars[]', id));

        logDebug('Updating calendar selection...');

        fetch(window.epCalendarData.ajaxUrl, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    logDebug('Preferences saved. Refreshing events...');
                    calendar.refetchEvents();
                }
            });
    }

    // --- Shared Calendar Search Logic ---

    const addBtn = document.getElementById('btn-add-calendar');
    const searchModal = document.getElementById('add-calendar-modal');
    if (addBtn && searchModal) {
        addBtn.addEventListener('click', () => {
            searchModal.style.display = 'block';
            document.getElementById('user-search-input').focus();
        });

        document.querySelector('.close-add-modal').addEventListener('click', () => {
            searchModal.style.display = 'none';
        });
    }

    const searchUserBtn = document.getElementById('btn-search-user');
    const searchInput = document.getElementById('user-search-input');
    const resultsContainer = document.getElementById('user-search-results');
    const loadingDiv = document.getElementById('user-search-loading');
    const errorDiv = document.getElementById('user-search-error');

    if (searchUserBtn) {
        searchUserBtn.addEventListener('click', performUserSearch);
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') performUserSearch();
        });
    }

    function performUserSearch() {
        const query = searchInput.value.trim();
        if (query.length < 3) {
            alert('Escribe al menos 3 letras.');
            return;
        }

        loadingDiv.style.display = 'block';
        resultsContainer.style.display = 'none';
        errorDiv.style.display = 'none';
        resultsContainer.innerHTML = '';

        const formData = new FormData();
        formData.append('action', 'ep_search_users');
        formData.append('nonce', window.epCalendarData.nonce);
        formData.append('query', query);

        fetch(window.epCalendarData.ajaxUrl, {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                loadingDiv.style.display = 'none';
                if (data.success) {
                    if (data.data.length === 0) {
                        errorDiv.innerText = 'No se encontraron usuarios.';
                        errorDiv.style.display = 'block';
                    } else {
                        renderUserResults(data.data);
                    }
                } else {
                    console.error('Search error details:', data.data);
                    errorDiv.innerText = (typeof data.data === 'string') ? data.data : (data.data.message || 'Error en la búsqueda. Si eres administrador, comprueba los permisos de la App en Azure.');
                    errorDiv.style.display = 'block';
                }
            })
            .catch(err => {
                loadingDiv.style.display = 'none';
                errorDiv.innerText = 'Error de conexión';
                errorDiv.style.display = 'block';
            });
    }

    function renderUserResults(users) {
        resultsContainer.innerHTML = '';
        resultsContainer.style.display = 'block';

        users.forEach(u => {
            const div = document.createElement('div');
            div.className = 'user-result-item';
            div.innerHTML = `
                <div>
                    <strong>${u.name}</strong><br>
                    <small style="color:#64748b;">${u.email}</small>
                </div>
                <button class="ep-btn-small" onclick="addSharedCalendar('${u.id}', '${u.name}', '${u.email}')">
                    <i class="fa-solid fa-plus"></i> Añadir
                </button>
            `;
            resultsContainer.appendChild(div);
        });
    }

    // Specially exposed for onclick usage in innerHTML
    window.addSharedCalendar = function (id, name, email) {
        if (!confirm(`¿Añadir el calendario de ${name}?`)) return;

        const formData = new FormData();
        formData.append('action', 'ep_add_shared_calendar');
        formData.append('nonce', window.epCalendarData.nonce);
        formData.append('id', id);
        formData.append('name', name);
        formData.append('email', email);

        fetch(window.epCalendarData.ajaxUrl, {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('add-calendar-modal').style.display = 'none';
                    initCalendarList(); // Refresh list to show new item
                } else {
                    alert('Error: ' + data.data);
                }
            });
    };
});
