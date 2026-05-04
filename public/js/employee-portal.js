jQuery(document).ready(function ($) {
	// --- Navigation & Sidebar ---
	const sidebar = $('#epSidebar');
	const menuToggle = $('#epMenuToggle');
	const closeBtn = $('#epSidebarClose');
	const overlay = $('<div class="ep-sidebar-overlay"></div>');

	if (sidebar.length && menuToggle.length) {
		$('body').append(overlay);

		menuToggle.on('click', function () {
			sidebar.toggleClass('active');
			overlay.toggleClass('active');
			$('body').toggleClass('ep-menu-open');
		});

		const closeSidebar = function () {
			sidebar.removeClass('active');
			overlay.removeClass('active');
			$('body').removeClass('ep-menu-open');
		};

		overlay.on('click', closeSidebar);
		if (closeBtn.length) {
			closeBtn.on('click', closeSidebar);
		}

		// Close sidebar when clicking links
		sidebar.find('nav a').on('click', function () {
			if ($(window).width() < 1024) {
				closeSidebar();
			}
		});
	}

	// --- User Menu & Dark Mode ---

	// User Dropdown
	$('#epUserMenuTrigger').on('click', function (e) {
		e.stopPropagation();
		$('#epUserDropdown').toggleClass('active');
	});

	$(document).on('click', function (e) {
		if (!$(e.target).closest('#epUserMenuTrigger').length) {
			$('#epUserDropdown').removeClass('active');
		}
	});

	// Dark Mode Toggle & Initialization
	const $root = $('#ep-app-root');
	const $html = $('html'); // Target global root for variables
	const $toggleBtn = $('#epDarkModeToggle');
	const $icon = $toggleBtn.find('i');

	// Initialize from Server State
	if (typeof ep_vars !== 'undefined' && ep_vars.is_dark_mode === 'on') {
		$root.addClass('dark-mode');
		$html.addClass('dark-mode');
		$icon.removeClass('fa-moon').addClass('fa-sun');
	}

	$toggleBtn.on('click', function () {
		// Use html class as the source of truth
		const isDarkMode = $html.hasClass('dark-mode');

		if (isDarkMode) {
			// Switch to Light
			$root.removeClass('dark-mode');
			$html.removeClass('dark-mode');
			$icon.removeClass('fa-sun').addClass('fa-moon');
		} else {
			// Switch to Dark
			$root.addClass('dark-mode');
			$html.addClass('dark-mode');
			$icon.removeClass('fa-moon').addClass('fa-sun');
		}

		$.ajax({
			url: ep_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'ep_toggle_dark_mode',
				dark_mode: !isDarkMode ? 'on' : 'off',
				nonce: ep_vars.nonce
			}
		});
	});

	// --- Search Functionality ---
	$('.ep-search input').on('keyup', function () {
		var value = $(this).val().toLowerCase();

		// Filter Apps
		$('.ep-app-card').filter(function () {
			$(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
		});

		// Filter Announcements
		$('.announcement-card').filter(function () {
			$(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
		});
	});


	// --- Notifications Logic ---
	$('#epNotificationsTrigger').on('click', function (e) {
		e.stopPropagation();
		var $dropdown = $('#epNotificationsDropdown');
		$dropdown.toggleClass('active');

		if ($dropdown.hasClass('active')) {
			loadNotifications();
		}
	});

	$(document).on('click', function (e) {
		if (!$(e.target).closest('#epNotificationsTrigger').length) {
			$('#epNotificationsDropdown').removeClass('active');
		}
	});

	function loadNotifications() {
		var $list = $('#epNotificationsList');
		$list.html('<div class="loading-notifications">Cargando...</div>');

		$.ajax({
			url: ep_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'ep_get_notifications',
				nonce: ep_vars.nonce
			},
			success: function (response) {
				if (response.success) {
					renderNotifications(response.data.notifications);
					updateBadge(response.data.unread_count);
				} else {
					$list.html('<div class="empty-notifications">Error al cargar notificaciones</div>');
				}
			},
			error: function () {
				$list.html('<div class="empty-notifications">Error de conexión</div>');
			}
		});
	}

	function renderNotifications(notifications) {
		var $list = $('#epNotificationsList');
		if (!notifications || notifications.length === 0) {
			$list.html('<div class="empty-notifications">No hay notificaciones recientes</div>');
			return;
		}

		var html = '';
		notifications.forEach(function (notif) {
			var icon = 'fa-info-circle';
			if (notif.type === 'success') icon = 'fa-check-circle';
			if (notif.type === 'warning') icon = 'fa-exclamation-triangle';
			if (notif.type === 'error') icon = 'fa-times-circle';

			var unreadClass = notif.is_read == 0 ? 'unread' : '';
			var link = notif.link ? notif.link : '#';

			html += '<a href="' + link + '" class="notification-item ' + unreadClass + '" data-id="' + notif.id + '">';
			html += '    <div class="notification-icon-small notification-' + notif.type + '">';
			html += '        <i class="fa-solid ' + icon + '"></i>';
			html += '    </div>';
			html += '    <div class="notification-content">';
			html += '        <h4>' + notif.title + '</h4>';
			html += '        <p>' + notif.message + '</p>';
			html += '        <span class="time">' + notif.created_at + '</span>';
			html += '    </div>';
			html += '</a>';
		});

		$list.html(html);
	}

	function updateBadge(count) {
		var $badge = $('#epNotificationsBadge');
		if (count > 0) {
			if ($badge.length) {
				$badge.text(count);
			} else {
				$('.notification-icon').append('<span class="badge" id="epNotificationsBadge">' + count + '</span>');
			}
		} else {
			$badge.remove();
		}
	}

	$(document).on('click', '.notification-item', function (e) {
		var $item = $(this);
		var id = $item.data('id');

		if ($item.hasClass('unread')) {
			$.ajax({
				url: ep_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'ep_mark_notification_read',
					id: id,
					nonce: ep_vars.nonce
				},
				success: function (response) {
					if (response.success) {
						$item.removeClass('unread');
						var $badge = $('#epNotificationsBadge');
						if ($badge.length) {
							var count = Math.max(0, parseInt($badge.text()) - 1);
							updateBadge(count);
						}
					}
				}
			});
		}
		$('#epNotificationsDropdown').removeClass('active');
	});

	// Real-time Polling for new notifications
	const storageKey = 'ep_last_notif_' + (ep_vars.user_id || 'guest');
	let lastNotificationId = parseInt(localStorage.getItem(storageKey)) || 0;

	function pollNotifications() {
		$.ajax({
			url: ep_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'ep_get_notifications',
				nonce: ep_vars.nonce
			},
			success: function (response) {
				if (response.success && response.data.notifications && response.data.notifications.length > 0) {
					const notifications = response.data.notifications;
					const latest = notifications[0];
					const latestId = parseInt(latest.id);

					// If we have a stored ID and there are newer ones
					if (lastNotificationId > 0 && latestId > lastNotificationId) {
						// Filter only notifications newer than what we've shown
						const newItems = notifications.filter(n => parseInt(n.id) > lastNotificationId);

						// Show toasts for new items (reverse to show oldest first)
						newItems.reverse().forEach(item => {
							showToastNotification(item.title, item.message, item.link);
						});
					}

					// If it's the very first time (lastNotificationId === 0), 
					// we just set the baseline to avoid spamming old notifications.
					// But we update badge and list anyway.

					lastNotificationId = latestId;
					localStorage.setItem(storageKey, latestId);

					updateBadge(response.data.unread_count);
					if ($('#epNotificationsDropdown').hasClass('active')) {
						renderNotifications(notifications);
					}
				}
			}
		});
	}

	setInterval(pollNotifications, 10000); // 10s polling for better real-time feel
	pollNotifications(); // Initial poll on load

	function showToastNotification(title, message, link) {
		if (!$('.ep-toast-container').length) {
			$('body').append('<div class="ep-toast-container"></div>');
		}

		// Limit to 3 toasts
		const $existingToasts = $('.ep-toast');
		if ($existingToasts.length >= 3) {
			$existingToasts.first().addClass('toast-out');
			setTimeout(() => $existingToasts.first().remove(), 500);
		}

		const toastLink = link ? link : '#';
		const toastHtml = `
            <div class="ep-toast" data-link="${toastLink}">
                <div class="ep-toast-icon"><i class="fa-solid fa-bell"></i></div>
                <div class="ep-toast-content">
                    <h4>${title}</h4>
                    <p>${message}</p>
                </div>
                <div class="ep-toast-close">&times;</div>
            </div>
        `;
		const $toast = $(toastHtml);
		$('.ep-toast-container').append($toast);

		// Handle close button
		$toast.find('.ep-toast-close').on('click', function (e) {
			e.stopPropagation();
			$toast.addClass('toast-out');
			setTimeout(() => $toast.remove(), 500);
		});

		// Handle click on toast (except close button)
		$toast.on('click', function (e) {
			if (!$(e.target).hasClass('ep-toast-close')) {
				window.location.href = $(this).data('link');
			}
		});

		setTimeout(() => {
			if ($toast.parent().length) {
				$toast.addClass('toast-out');
				setTimeout(() => $toast.remove(), 500);
			}
		}, 10000);
	}

	if ($('#epProfileForm').length) {
		$(document).on('submit', '#epProfileForm', function (e) {
			e.preventDefault();
			const $btn = $('#epSaveProfileBtn');
			const originalText = $btn.html();
			$btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando...');
			$.ajax({
				url: ep_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'ep_update_profile',
					nonce: ep_vars.nonce,
					phone: $('#ep_phone').val(),
					extension: $('#ep_extension').val(),
					job_title: $('#ep_job_title').val(),
					department: $('#ep_department').val(),
					office_phone: $('#ep_office_phone').val()
				},
				success: function (res) {
					if (res.success) {
						showToastNotification('Perfil Guardado', 'Tu información ha sido actualizada correctamente.');
						setTimeout(loadNotifications, 500);
					} else {
						alert('Error: ' + res.data);
					}
				},
				complete: function () {
					$btn.prop('disabled', false).html(originalText);
				}
			});
		});
	}

	$('#epMarkAllRead').on('click', function (e) {
		e.preventDefault();
		e.stopPropagation();

		$.ajax({
			url: ep_vars.ajax_url,
			type: 'POST',
			data: {
				action: 'ep_mark_all_notifications_read',
				nonce: ep_vars.nonce
			},
			success: function (response) {
				if (response.success) {
					$('.notification-item').removeClass('unread');
					updateBadge(0);
				}
			}
		});
	});

	// --- Dashboard Calendar Hover Logic ---
	const $calendarWidget = $('#dashboard-calendar-widget');
	if ($calendarWidget.length) {
		let dashboardEvents = [];
		const $tooltip = $('#calendar-event-tooltip');
		const $tooltipBody = $('#tooltip-events-list');

		function loadDashboardEvents() {
			$.ajax({
				url: ep_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'ep_get_dashboard_events',
					nonce: ep_vars.nonce
				},
				success: function (res) {
					if (res.success) {
						dashboardEvents = res.data.events || [];
						markDaysWithEvents();
					}
				}
			});
		}

		function markDaysWithEvents() {
			$('.calendar-day-interactive').each(function () {
				const dateStr = $(this).data('date');
				const hasEvents = dashboardEvents.some(ev => ev.start.startsWith(dateStr));
				if (hasEvents) {
					$(this).addClass('has-events');
				}
			});
		}

		loadDashboardEvents();

		$(document).on('mouseenter', '.calendar-day-interactive', function (e) {
			const dateStr = $(this).data('date');
			const dayEvents = dashboardEvents.filter(ev => ev.start.startsWith(dateStr));

			if (dayEvents.length > 0) {
				let html = '';
				dayEvents.forEach(ev => {
					const start = new Date(ev.start);
					const time = ev.isAllDay ? 'Todo el día' : start.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
					html += `
                        <div class="tooltip-event">
                            <span class="event-time">${time}</span>
                            <span class="event-title">${ev.title}</span>
                        </div>
                    `;
				});
				$tooltipBody.html(html);
			} else {
				$tooltipBody.html('<div class="no-events">No hay eventos programados</div>');
			}

			// Position and show tooltip
			const rect = this.getBoundingClientRect();
			const tooltipHeight = 200; // Estimated height
			const spaceAbove = rect.top;

			$tooltip.removeClass('placement-bottom');

			let finalTop = rect.top;
			if (spaceAbove < tooltipHeight) {
				$tooltip.addClass('placement-bottom');
				finalTop = rect.bottom;
			}

			$tooltip.css({
				display: 'block',
				top: finalTop,
				left: rect.left + (rect.width / 2)
			});
		});

		$(document).on('mouseleave', '.calendar-day-interactive', function () {
			$tooltip.hide();
		});
	}

	// --- M365 Phase 1 Widgets ---

	// 1. Presence Widget
	const $presenceTrigger = $('#epPresenceTrigger');
	const $presenceDropdown = $('#epPresenceDropdown');
	const $presenceList = $('#epPresenceList');

	if ($presenceTrigger.length) {
		$presenceTrigger.on('click', function (e) {
			e.stopPropagation();
			$presenceDropdown.toggleClass('active');

			if ($presenceDropdown.hasClass('active')) {
				loadCompanionsPresence();
			}
		});

		$(document).on('click', function (e) {
			if (!$(e.target).closest('#epPresenceContainer').length) {
				$presenceDropdown.removeClass('active');
			}
		});

		function loadCompanionsPresence() {
			$presenceList.html('<div class="ep-presence-loading">Consultando disponibilidad...</div>');

			$.ajax({
				url: ep_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'ep_get_m365_presence',
					nonce: ep_vars.nonce
				},
				success: function (res) {
					if (res.success && res.data.length > 0) {
						let html = '';
						res.data.forEach(user => {
							const statusText = getStatusTranslation(user.availability);
							const teamsChatUrl = `https://teams.microsoft.com/l/chat/0/0?users=${encodeURIComponent(user.email)}`;
							html += `
                                <div class="ep-presence-item-card" data-status="${user.availability}">
                                    <div class="ep-comp-avatar-mini">
                                        <div class="ep-status-ring"></div>
                                        <img src="${user.photo}" alt="${user.name}">
                                    </div>
                                    <div class="ep-comp-details-mini">
                                        <span class="ep-comp-name-mini">${user.name}</span>
                                        <span class="ep-comp-status-mini">${statusText}</span>
                                    </div>
                                    <a href="${teamsChatUrl}" target="_blank" class="ep-btn-teams-inline" title="Chat en Teams">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                                    </a>
                                </div>
                            `;
						});
						$presenceList.html(html);
					} else {
						$presenceList.html('<div class="ep-presence-loading">No hay otros compañeros con O365 conectado.</div>');
					}
				},
				error: function () {
					$presenceList.html('<div class="ep-presence-loading">Error al conectar con Microsoft Graph.</div>');
				}
			});
		}

		function getStatusTranslation(status) {
			const map = {
				'Available': 'Disponible',
				'Busy': 'Ocupado',
				'Away': 'Ausente',
				'Offline': 'Desconectado',
				'DoNotDisturb': 'No molestar',
				'BeRightBack': 'Vuelvo enseguida'
			};
			return map[status] || status;
		}
	}

	// 2. Event Teleprompter
	const $tpContent = $('#epTeleprompterContent');
	if ($tpContent.length) {
		function loadNextEvent() {
			$.ajax({
				url: ep_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'ep_get_m365_events',
					nonce: ep_vars.nonce
				},
				success: function (res) {
					if (res.success && res.data) {
						const event = res.data;
						const startTime = new Date(event.start.dateTime);
						const timeStr = startTime.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
						const dateStr = startTime.toLocaleDateString('es-ES', { day: '2-digit', month: 'short' });

						const html = `<a href="${event.webLink}" target="_blank" class="ep-tp-scroll"><strong>${event.subject}</strong> &nbsp; | &nbsp; ${dateStr} - ${timeStr} &nbsp; | &nbsp; Siguiente evento del calendario Microsoft 365</a>`;
						$tpContent.html(html);
					} else {
						$tpContent.html('<span class="ep-tp-scroll">No hay eventos próximos en tu calendario. Que tengas un buen día.</span>');
					}
				}
			});
		}

		loadNextEvent();
		setInterval(loadNextEvent, 300000); // Update every 5 minutes
	}

	// 3. Productivity Widgets (To Do & Mail)
	if ($('#ep-todo-list').length) {
		function loadMyTasks() {
			$.ajax({
				url: ep_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'ep_get_m365_tasks',
					nonce: ep_vars.nonce
				},
				success: function (res) {
					if (res.success && res.data.length > 0) {
						let html = '';
						res.data.forEach(task => {
							let icon = 'fa-regular fa-circle';
							let typeClass = 'todo-generic';

							if (task.source === 'portal') {
								if (task.type === 'signature') icon = 'fa-solid fa-file-signature';
								if (task.type === 'ticket') icon = 'fa-solid fa-ticket';
								if (task.type === 'inventory') icon = 'fa-solid fa-box-open';
								if (task.type === 'download') icon = 'fa-solid fa-comment-dots';
								typeClass = 'todo-portal';
								// typeClass = 'todo-portal'; // This variable is no longer used
							}

							// The original clickAction logic is replaced by the <a> tag's href
							// const clickAction = task.link.startsWith('http') ? `window.open('${task.link}', '_blank')` : `window.location.href='${task.link}'`;

							html += `
                                <a href="${task.link}" target="_blank" class="ep-todo-item" data-type="${task.type}" data-source="${task.source}">
                                    <i class="${icon} task-icon"></i>
                                    <div class="todo-text">
                                        <span class="task-title">${task.title}</span>
                                        <span class="task-source">${task.source === 'microsoft' ? 'Microsoft 365' : 'Portal'}</span>
                                    </div>
                                    <i class="fa-solid fa-chevron-right" style="font-size: 0.8rem; opacity: 0.3;"></i>
                                </a>
                            `;
						});
						$('#ep-todo-list').html(html);
					} else {
						$('#ep-todo-list').html('<div class="ep-widget-loading">No tienes tareas pendientes.</div>');
					}
				}
			});
		}

		loadMyTasks();
		setInterval(loadMyTasks, 600000); // 10 min
	}

	if ($('#ep-mail-list').length) {
		function loadRecentEmails() {
			$.ajax({
				url: ep_vars.ajax_url,
				type: 'POST',
				data: {
					action: 'ep_get_m365_emails',
					nonce: ep_vars.nonce
				},
				success: function (res) {
					if (res.success && res.data.length > 0) {
						let html = '';
						res.data.forEach(mail => {
							const date = new Date(mail.receivedDateTime);
							const timeStr = date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
							html += `
                                <a href="${mail.webLink}" target="_blank" class="ep-mail-item">
                                    <div class="mail-subject">${mail.subject}</div>
                                    <div class="mail-meta">
                                        <span class="mail-from">${mail.from.emailAddress.name}</span>
                                        <span class="mail-time">${timeStr}</span>
                                    </div>
                                </a>
                            `;
						});
						$('#ep-mail-list').html(html);
					} else {
						$('#ep-mail-list').html('<div class="ep-widget-loading">Bandeja de entrada limpia.</div>');
					}
				}
			});
		}

		loadRecentEmails();
		setInterval(loadRecentEmails, 300000); // 5 min
	}
});
