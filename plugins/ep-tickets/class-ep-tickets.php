<?php

class EP_App_Tickets implements EP_App_Interface
{
    public function get_id()
    {
        return 'tickets';
    }

    public function get_name()
    {
        return 'Tickets & Soporte';
    }

    public function get_icon()
    {
        return 'fa-solid fa-ticket';
    }

    public function get_menu_label()
    {
        return 'Tickets';
    }

    public function render_dashboard_card()
    {
        ?>
        <div class="ep-app-card" onclick="window.location.href='?view=tickets'">
            <div class="app-icon-container color-blue">
                <i class="fa-solid fa-ticket"></i>
            </div>
            <h3>Tickets</h3>
            <p>Soporte y mantenimiento</p>
        </div>
        <?php
    }

    public function render_full_view()
    {
        include EP_TICKETS_PATH . 'partials/tickets-app.php';
    }

    public function handle_ajax()
    {
        if (isset($_POST['ep_action']) && $_POST['ep_action'] === 'get_ticket_comments') {
            $ticket_id = intval($_POST['ticket_id']);
            $current_user_id = get_current_user_id();

            // Permissions check
            $is_admin = current_user_can('administrator');
            $is_owner = EP_Tickets::is_ticket_owner($ticket_id, $current_user_id);
            
            // For managers, we check if they can manage this ticket type or have general write permission
            $is_manager = (EP_App_Manager::get_permission('tickets', $current_user_id) === 'write');
            
            // If not owner/admin/full-manager, check if it's manageable by dept
            if (!$is_admin && !$is_owner && !$is_manager) {
                $manageable = EP_Tickets::get_manageable_tickets_for_user($current_user_id);
                $is_manager = in_array($ticket_id, wp_list_pluck($manageable, 'ID'));
            }

            if (!$is_admin && !$is_owner && !$is_manager) {
                wp_send_json_error('No tienes permisos.');
            }

            $comments = get_comments([
                'post_id' => $ticket_id,
                'order' => 'ASC',
                'status' => 'approve'
            ]);

            $data = [];
            foreach ($comments as $comment) {
                $author_id = $comment->user_id;
                $is_staff_reply = EP_Tickets::is_staff($author_id);
                
                $data[] = [
                    'author' => $comment->comment_author,
                    'date' => date('d/m/Y H:i', strtotime($comment->comment_date)),
                    'content' => wpautop(esc_html($comment->comment_content)),
                    'is_staff' => $is_staff_reply
                ];
            }
            wp_send_json_success($data);
        }

        if (isset($_POST['ep_action']) && $_POST['ep_action'] === 'add_ticket_comment') {
            $ticket_id = intval($_POST['ticket_id']);
            $content = sanitize_textarea_field($_POST['comment_content']);
            $current_user_id = get_current_user_id();

            if (empty($content)) {
                wp_send_json_error('El mensaje no puede estar vacío.');
            }

            // Simple permission check
            if (!EP_Tickets::is_ticket_owner($ticket_id, $current_user_id) && !EP_Tickets::is_staff($current_user_id)) {
                wp_send_json_error('No tienes permisos.');
            }

            $comment_id = wp_insert_comment([
                'comment_post_ID' => $ticket_id,
                'comment_content' => $content,
                'user_id' => $current_user_id,
                'comment_author' => wp_get_current_user()->display_name,
                'comment_approved' => 1
            ]);

            if ($comment_id) {
                wp_send_json_success();
            } else {
                wp_send_json_error('Error al guardar el comentario.');
            }
        }

        if (isset($_POST['ep_action']) && $_POST['ep_action'] === 'change_ticket_priority') {
            $ticket_id = intval($_POST['ticket_id']);
            $priority = sanitize_text_field($_POST['priority']);
            $reason = sanitize_textarea_field($_POST['reason']);
            $current_user_id = get_current_user_id();

            // Verificar permisos de staff/manager
            if (!EP_Tickets::is_staff($current_user_id)) {
                wp_send_json_error('No tienes permisos para realizar esta acción.');
            }

            // Validar prioridad
            $valid_priorities = ['Baja', 'Normal', 'Alta'];
            if (!in_array($priority, $valid_priorities)) {
                wp_send_json_error('Prioridad no válida.');
            }

            $old_priority = get_post_meta($ticket_id, '_ep_ticket_priority', true) ?: 'Normal';

            if ($old_priority !== $priority) {
                update_post_meta($ticket_id, '_ep_ticket_priority', $priority);

                // Insertar comentario del sistema
                $system_comment = sprintf(
                    "Cambio de prioridad a %s (anterior: %s).",
                    $priority,
                    $old_priority
                );
                if (!empty($reason)) {
                    $system_comment .= "\nMotivo: " . $reason;
                }

                wp_insert_comment([
                    'comment_post_ID' => $ticket_id,
                    'comment_content' => $system_comment,
                    'user_id' => $current_user_id,
                    'comment_author' => wp_get_current_user()->display_name,
                    'comment_approved' => 1
                ]);

                // Notificar al propietario del ticket
                $owner_id = get_post_field('post_author', $ticket_id);
                if ($owner_id && class_exists('EP_Notifications')) {
                    $notif_message = sprintf('La prioridad del ticket "%s" ha cambiado a %s.', get_the_title($ticket_id), $priority);
                    if (!empty($reason)) {
                        $notif_message .= ' Motivo: ' . $reason;
                    }
                    EP_Notifications::add_notification($owner_id, [
                        'type' => 'info',
                        'title' => 'Prioridad de Ticket Actualizada',
                        'message' => $notif_message,
                        'link' => '?view=tickets'
                    ]);
                }
            }

            wp_send_json_success();
        }
    }
}

class EP_Tickets
{

    public function __construct()
    {
        add_action('init', array($this, 'register_tickets_cpt'));
        add_action('init', array($this, 'handle_ticket_submission'));
        add_action('init', array($this, 'handle_ticket_actions'));
        add_action('wp_insert_comment', array($this, 'notify_ticket_comment'), 10, 2);

        // AJAX Routing
        add_action('wp_ajax_ep_app_ajax', array($this, 'route_app_ajax'));

        // Cron tasks
        add_action('ep_daily_cleanup', array($this, 'purge_old_tickets'));

        // Security: Block direct access to ep_ticket posts
        add_action('template_redirect', array($this, 'block_direct_access'));

        // --- Integración con IA Bot ---
        add_filter('ep_bot_intents', array($this, 'registrar_intent_bot'));
        add_filter('ep_bot_handle_intent_tickets', array($this, 'responder_intent_bot'), 10, 5);
        add_filter('ep_bot_action_ticket_close', array($this, 'accion_bot_cerrar_ticket'), 10, 4);
    }

    /**
     * Routes global app ajax to the correct handler if app=tickets
     */
    public function route_app_ajax()
    {
        if (isset($_POST['app']) && $_POST['app'] === 'tickets') {
            $app = new EP_App_Tickets();
            $app->handle_ajax();
        }
        // If it's not for us, we don't die, in case other apps also hook here
        // (Though standard WP AJAX should die if no one handles it)
    }

    public function block_direct_access()
    {
        if (is_singular('ep_ticket')) {
            wp_redirect(home_url());
            exit;
        }
    }

    public function purge_old_tickets()
    {
        // Purge tickets closed more than 24 months ago
        $twenty_four_months_ago = date('Y-m-d', strtotime('-24 months'));

        $args = array(
            'post_type' => 'ep_ticket',
            'post_status' => 'publish', // CPT is publish even if 'status' meta is closed
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_ep_ticket_status',
                    'value' => 'closed',
                    'compare' => '='
                ),
                array(
                    'key' => '_ep_ticket_closed_date',
                    'value' => $twenty_four_months_ago,
                    'compare' => '<',
                    'type' => 'DATETIME'
                )
            )
        );

        $query = new WP_Query($args);
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                // Delete attachment if exists
                $attach_id = get_post_meta($post_id, '_ep_ticket_attachment_id', true);
                if ($attach_id) {
                    wp_delete_attachment($attach_id, true);
                }

                // Delete related comments (ticket replies)
                $comments = get_comments(array('post_id' => $post_id));
                foreach ($comments as $comment) {
                    wp_delete_comment($comment->comment_ID, true);
                }

                // Delete post permanently
                wp_delete_post($post_id, true);
            }
            wp_reset_postdata();
        }
    }

    public function handle_ticket_actions()
    {
        if (isset($_GET['ep_action']) && isset($_GET['ticket_id']) && isset($_GET['nonce'])) {
            $ticket_id = intval($_GET['ticket_id']);
            $action = sanitize_text_field($_GET['ep_action']);
            $nonce = sanitize_text_field($_GET['nonce']);
            $current_user_id = get_current_user_id();

            if (!wp_verify_nonce($nonce, 'ep_ticket_action_' . $ticket_id)) {
                wp_die('Error de seguridad: Nonce inválido.');
            }

            // Check permissions/ownership logic here strictly
            $is_admin = current_user_can('administrator');
            $manageable_tickets = self::get_manageable_tickets_for_user($current_user_id);
            $manageable_ids = wp_list_pluck($manageable_tickets, 'ID');
            $is_manageable = in_array($ticket_id, $manageable_ids);
            $is_owner = self::is_ticket_owner($ticket_id, $current_user_id);

            if (!$is_admin && !$is_manageable && !$is_owner) {
                wp_die('No tienes permisos para realizar esta acción.');
            }

            if ($action === 'take_ticket') {
                // Verify user can take this ticket (is in correct dept)
                self::update_ticket_status($ticket_id, 'in_progress', $current_user_id);
            } elseif ($action === 'close_ticket') {
                // Verify user is the handler or owner? (Usually handler)
                self::update_ticket_status($ticket_id, 'closed');
            }

            wp_redirect(remove_query_arg(['ep_action', 'ticket_id', 'nonce'], (string) ($_SERVER['REQUEST_URI'] ?? '')));
            exit;
        }
    }

    public function register_tickets_cpt()
    {
        $labels = array(
            'name' => _x('Tickets', 'Post Type General Name', 'employee-portal'),
            'singular_name' => _x('Ticket', 'Post Type Singular Name', 'employee-portal'),
            'menu_name' => __('Tickets', 'employee-portal'),
        );
        $args = array(
            'label' => __('Ticket', 'employee-portal'),
            'labels' => $labels,
            'supports' => array('title', 'editor', 'author', 'comments'), // Comments for ticket replies
            'hierarchical' => false,
            'public' => false, // Not publicly queryable like posts
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_position' => 6,
            'menu_icon' => 'dashicons-tickets-alt',
            'capability_type' => 'post',
        );
        register_post_type('ep_ticket', $args);
    }

    public function handle_ticket_submission()
    {
        if (isset($_POST['ep_submit_ticket']) && isset($_POST['ep_ticket_nonce'])) {
            if (!wp_verify_nonce($_POST['ep_ticket_nonce'], 'ep_new_ticket')) {
                return;
            }

            $title = sanitize_text_field($_POST['ticket_subject']);
            $content = sanitize_textarea_field($_POST['ticket_message']);
            // Map types to what we want internally or just use the allowed strings
            $valid_types = ['IT', 'Communication', 'Web'];
            $type = in_array($_POST['ticket_type'], $valid_types) ? $_POST['ticket_type'] : 'IT';

            $post_id = wp_insert_post(array(
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => 'publish',
                'post_type' => 'ep_ticket',
                'post_author' => get_current_user_id(),
            ));

            if ($post_id) {
                $priority = isset($_POST['ticket_priority']) ? sanitize_text_field($_POST['ticket_priority']) : 'Normal';
                update_post_meta($post_id, '_ep_ticket_priority', $priority);

                update_post_meta($post_id, '_ep_ticket_type', $type);
                update_post_meta($post_id, '_ep_ticket_status', 'open');

                // Handle File Upload
                if (!empty($_FILES['ticket_file']['name'])) {
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    require_once(ABSPATH . 'wp-admin/includes/media.php');

                    $attachment_id = media_handle_upload('ticket_file', $post_id);
                    if (!is_wp_error($attachment_id)) {
                        update_post_meta($post_id, '_ep_ticket_attachment_id', $attachment_id);
                    }
                }

                if (isset($_POST['ticket_asset']) && !empty($_POST['ticket_asset'])) {
                    update_post_meta($post_id, '_ep_ticket_related_asset', intval($_POST['ticket_asset']));
                }

                // Notify Dept (Email logic to be implemented or hooked here)
                $this->notify_new_ticket($post_id, $type);

                if (function_exists('ep_stats_log')) {
                    ep_stats_log('tickets', 'ticket_created', get_current_user_id(), [
                        'ticket_id' => $post_id,
                        'type' => $type,
                        'priority' => $priority
                    ]);
                }

                wp_redirect(add_query_arg('ticket_submitted', 'true', (string) ($_SERVER['REQUEST_URI'] ?? '')));
                exit;
            }
        }
    }

    /**
     * Check if a user is the owner (author) of a ticket.
     */
    public static function is_ticket_owner($ticket_id, $user_id)
    {
        $author_id = get_post_field('post_author', $ticket_id);
        return intval($author_id) === intval($user_id);
    }

    private function notify_new_ticket($ticket_id, $type)
    {
        // 1. Mapear tipo de ticket a palabras clave de departamento
        $dept_keywords = [];
        if ($type === 'IT')
            $dept_keywords = ['Transform', 'Digital', 'Informá', 'Inform'];
        if ($type === 'Communication' || $type === 'Web')
            $dept_keywords = ['Comunica'];

        // 2. Identificar destinatarios
        $recipient_ids = [];

        // 2a. Usuarios de departamentos relevantes (búsqueda difusa)
        if (!empty($dept_keywords)) {
            $meta_query = ['relation' => 'OR'];
            foreach ($dept_keywords as $kw) {
                $meta_query[] = [
                    'key'     => 'ep_department',
                    'value'   => $kw,
                    'compare' => 'LIKE'
                ];
            }
            $dept_users = get_users(['meta_query' => $meta_query, 'fields' => 'ID']);
            $recipient_ids = array_merge($recipient_ids, $dept_users);
        }

        // 2b. Administradores (siempre notificados)
        $admins = get_users(['role' => 'administrator', 'fields' => 'ID']);
        $recipient_ids = array_merge($recipient_ids, $admins);

        // 2c. Managers con permiso 'write' en tickets
        // Se usa LIKE doble (JSON y serializado) para máxima compatibilidad
        $all_users = get_users(['fields' => ['ID']]);
        foreach ($all_users as $u) {
            $perms = get_user_meta($u->ID, 'ep_app_permissions', true);
            if (empty($perms)) continue;
            // Si es array, buscar directamente
            if (is_array($perms) && isset($perms['tickets']) && $perms['tickets'] === 'write') {
                $recipient_ids[] = $u->ID;
                continue;
            }
            // Si es string (JSON o serializado), buscar la subcadena
            $perms_str = is_string($perms) ? $perms : wp_json_encode($perms);
            if (strpos($perms_str, 'tickets') !== false && strpos($perms_str, 'write') !== false) {
                $recipient_ids[] = $u->ID;
            }
        }

        // Eliminar duplicados
        $recipient_ids = array_unique(array_filter(array_map('intval', $recipient_ids)));

        $ticket_title = get_the_title($ticket_id);
        $submitter_id = (int) get_post_field('post_author', $ticket_id);

        ep_error_log("[TICKETS] Notificando ticket #{$ticket_id} tipo=$type a " . count($recipient_ids) . " destinatarios: " . implode(',', $recipient_ids), true);

        foreach ($recipient_ids as $uid) {
            // No notificar al propio autor del ticket
            if ($uid === $submitter_id) continue;

            if (class_exists('EP_Notifications')) {
                EP_Notifications::add_notification($uid, [
                    'type'    => 'info',
                    'title'   => 'Nuevo Ticket: ' . $type,
                    'message' => 'Se ha abierto un nuevo ticket: "' . $ticket_title . '".',
                    'link'    => '?view=tickets'
                ]);
            }
        }

        // Confirmar al autor que su ticket fue recibido
        if ($submitter_id > 0 && class_exists('EP_Notifications')) {
            EP_Notifications::add_notification($submitter_id, [
                'type'    => 'success',
                'title'   => 'Ticket enviado',
                'message' => 'Tu ticket "' . $ticket_title . '" ha sido enviado correctamente y será atendido pronto.',
                'link'    => '?view=tickets'
            ]);
        }
    }

    public function notify_ticket_comment($comment_id, $comment)
    {
        $post_id = $comment->comment_post_ID;
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'ep_ticket') {
            return;
        }

        $owner_id = $post->post_author;
        $comment_author_id = $comment->user_id;
        $ticket_title = $post->post_title;

        // Notify the owner if the comment is from someone else
        if ($owner_id != $comment_author_id && class_exists('EP_Notifications')) {
            EP_Notifications::add_notification($owner_id, [
                'type' => 'info',
                'title' => 'Nueva respuesta en Ticket',
                'message' => 'Has recibido una respuesta en tu ticket "' . $ticket_title . '".',
                'link' => '?view=tickets'
            ]);
        }

        // Notify the handler if the comment is from the owner
        $handler_id = get_post_meta($post_id, '_ep_ticket_handler_id', true);
        if ($handler_id && $handler_id != $comment_author_id && class_exists('EP_Notifications')) {
            EP_Notifications::add_notification($handler_id, [
                'type' => 'info',
                'title' => 'Respuesta del Usuario',
                'message' => 'El usuario ha respondido al ticket "' . $ticket_title . '".',
                'link' => '?view=tickets'
            ]);
        }
    }

    public static function get_user_tickets($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        $args = array(
            'post_type' => 'ep_ticket',
            'author' => $user_id,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        return get_posts($args);
    }

    public static function get_manageable_tickets_for_user($user_id, $status = 'open')
    {
        global $ep_app_manager;
        $is_full_manager = $ep_app_manager->get_user_permission('tickets', $user_id) === 'write';

        // Determine user department
        $dept = get_user_meta($user_id, 'ep_department', true);

        if (!$is_full_manager && !$dept)
            return [];

        $target_type = '';
        // Check fuzzy match for departments
        $allowed_types = [];
        if (stripos((string) $dept, 'Comuni') !== false) {
            $allowed_types[] = 'Communication';
            $allowed_types[] = 'Web';
        }
        if (stripos((string) $dept, 'TRANSFORMACI') !== false || stripos((string) $dept, 'Digital') !== false) {
            $allowed_types[] = 'IT';
            $allowed_types[] = 'Web';
        }

        $allowed_types = array_unique($allowed_types);
        if (!$is_full_manager && empty($allowed_types)) {
            return [];
        }

        if ($status === 'closed') {
            $meta_query[] = array(
                'key' => '_ep_ticket_status',
                'value' => 'closed',
                'compare' => '='
            );
        } else {
            $meta_query[] = array(
                'key' => '_ep_ticket_status',
                'value' => 'closed',
                'compare' => '!='
            );
        }

        if (!$is_full_manager) {
            $meta_query[] = array(
                'key' => '_ep_ticket_type',
                'value' => $allowed_types,
                'compare' => 'IN'
            );
        }

        $args = array(
            'post_type' => 'ep_ticket',
            'posts_per_page' => -1,
            'meta_query' => $meta_query,
            'orderby' => ($status === 'closed') ? 'meta_value' : 'date',
            'meta_key' => ($status === 'closed') ? '_ep_ticket_closed_date' : '',
            'order' => ($status === 'closed') ? 'DESC' : 'ASC'
        );

        return get_posts($args);
    }

    public static function update_ticket_status($ticket_id, $status, $handler_id = null)
    {
        $old_status = get_post_meta($ticket_id, '_ep_ticket_status', true);
        update_post_meta($ticket_id, '_ep_ticket_status', $status);

        $owner_id = get_post_field('post_author', $ticket_id);
        $ticket_title = get_the_title($ticket_id);

        if ($handler_id) {
            $old_handler = get_post_meta($ticket_id, '_ep_ticket_handler_id', true);
            update_post_meta($ticket_id, '_ep_ticket_handler_id', $handler_id);

            // Notify owner if handler changed
            if ($old_handler != $handler_id && class_exists('EP_Notifications')) {
                $handler_user = get_userdata($handler_id);
                $handler_name = $handler_user ? $handler_user->display_name : 'un agente';
                EP_Notifications::add_notification($owner_id, [
                    'type' => 'success',
                    'title' => 'Ticket Asignado',
                    'message' => 'Tu ticket "' . $ticket_title . '" ha sido asignado a ' . $handler_name . '.',
                    'link' => '?view=tickets'
                ]);
            }
        }

        if ($status === 'closed' && $old_status !== 'closed') {
            update_post_meta($ticket_id, '_ep_ticket_closed_date', current_time('mysql'));

            // Notify owner
            if (class_exists('EP_Notifications')) {
                EP_Notifications::add_notification($owner_id, [
                    'type' => 'success',
                    'title' => 'Ticket Cerrado',
                    'message' => 'Tu ticket "' . $ticket_title . '" ha sido marcado como Cerrado.',
                    'link' => '?view=tickets'
                ]);
            }

            if (function_exists('ep_stats_log')) {
                ep_stats_log('tickets', 'ticket_resolved', get_current_user_id(), [
                    'ticket_id' => $ticket_id,
                    'status' => 'closed'
                ]);
            }
        }
    }

    public static function get_user_assets_for_select($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'imjc_inventory_items';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return [];
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id, sku FROM $table_name WHERE assigned_to_user_id = %d",
            $user_id
        ));

        $assets = [];
        foreach ($items as $item) {
            $assets[$item->id] = get_the_title($item->post_id) . ' (' . $item->sku . ')';
        }
        return $assets;
    }

    public static function get_department_queues()
    {
        $queues = [
            'IT' => ['label' => 'Soporte Informático', 'types' => ['IT']],
            'Communication' => ['label' => 'Comunicación', 'types' => ['Communication']],
            'Web' => ['label' => 'Desarrollo/Soporte Web', 'types' => ['Web']]
        ];

        $results = [];
        foreach ($queues as $key => $data) {
            $tickets = get_posts([
                'post_type' => 'ep_ticket',
                'posts_per_page' => -1,
                'meta_query' => [
                    'relation' => 'AND',
                    ['key' => '_ep_ticket_type', 'value' => $data['types'], 'compare' => 'IN'],
                    ['key' => '_ep_ticket_status', 'value' => 'closed', 'compare' => '!=']
                ]
            ]);

            $stats = ['high' => 0, 'normal' => 0, 'low' => 0];
            foreach ($tickets as $t) {
                $prio = get_post_meta($t->ID, '_ep_ticket_priority', true) ?: 'Normal';
                $prio = strtolower($prio); // alta, normal, baja

                if ($prio === 'alta')
                    $stats['high']++;
                elseif ($prio === 'baja')
                    $stats['low']++;
                else
                    $stats['normal']++;
            }

            $results[] = [
                'label' => $data['label'],
                'count' => count($tickets),
                'breakdown' => $stats
            ];
        }
        return $results;
    }

    public static function get_stats()
    {
        $current_month = date('Y-m-01');
        $current_year = date('Y-01-01');

        $stats = [
            'month' => [
                'created' => 0,
                'resolved' => 0
            ],
            'year' => [
                'created' => 0,
                'resolved' => 0
            ]
        ];

        // Month Created
        $stats['month']['created'] = count(get_posts([
            'post_type' => 'ep_ticket',
            'posts_per_page' => -1,
            'date_query' => [['after' => $current_month, 'inclusive' => true]],
            'fields' => 'ids'
        ]));

        // Month Resolved
        $stats['month']['resolved'] = count(get_posts([
            'post_type' => 'ep_ticket',
            'posts_per_page' => -1,
            'meta_query' => [
                ['key' => '_ep_ticket_status', 'value' => 'closed'],
                ['key' => '_ep_ticket_closed_date', 'value' => $current_month, 'compare' => '>=', 'type' => 'DATETIME']
            ],
            'fields' => 'ids'
        ]));

        // Year Created
        $stats['year']['created'] = count(get_posts([
            'post_type' => 'ep_ticket',
            'posts_per_page' => -1,
            'date_query' => [['after' => $current_year, 'inclusive' => true]],
            'fields' => 'ids'
        ]));

        // Year Resolved
        $stats['year']['resolved'] = count(get_posts([
            'post_type' => 'ep_ticket',
            'posts_per_page' => -1,
            'meta_query' => [
                ['key' => '_ep_ticket_status', 'value' => 'closed'],
                ['key' => '_ep_ticket_closed_date', 'value' => $current_year, 'compare' => '>=', 'type' => 'DATETIME']
            ],
            'fields' => 'ids'
        ]));

        return $stats;
    }

    // --- INTEGRACIÓN CON IA BOT ---

    public function registrar_intent_bot($intents)
    {
        $intents['TICKETS'] = "El usuario pregunta por sus tickets de soporte abiertos o en su caso por aquellos que tiene que gestionar, o pide crear uno. Ej: 'mis tickets', 'soporte sobre web'.";
        return $intents;
    }

    public function responder_intent_bot($response, $intent_data, $user_id, $texto, $bot_instance)
    {
        $wp_user = get_userdata($user_id);
        $nombre  = $wp_user ? $wp_user->display_name : 'Usuario';
        
        // Comprobar si el intent detectado trajo parámetros
        $params = $intent_data['params'] ?? [];
        if (!empty($params['ticket_id'])) {
            return $this->tarjeta_ticket_individual(intval($params['ticket_id']), $user_id, $bot_instance);
        }

        return $this->tarjeta_tickets($user_id, $nombre, $bot_instance);
    }

    /**
     * Botón "✔ Cerrar #id" de las tarjetas del bot de Teams (data: {a:'ticket_close', id}).
     * Mismos permisos que el enlace de cerrar del portal: administrador, gestor
     * con el ticket en su cola o autor del ticket.
     */
    public function accion_bot_cerrar_ticket($response, $data, $user_id, $bot_instance)
    {
        $ticket_id = intval($data['id'] ?? 0);
        $ticket    = $ticket_id ? get_post($ticket_id) : null;
        if (!$ticket || $ticket->post_type !== 'ep_ticket') {
            return $bot_instance->tarjeta_simple('🔍 Ticket no encontrado', "No existe ningún ticket con el número #{$ticket_id}.", '');
        }
        if (!self::puede_cerrar($ticket_id, $user_id)) {
            return $bot_instance->tarjeta_simple('🚫 Acceso Denegado', "No tienes permisos para cerrar el ticket #{$ticket_id}.", '');
        }

        if (get_post_meta($ticket_id, '_ep_ticket_status', true) === 'closed') {
            $aviso = "ℹ️ El ticket #{$ticket_id} ya estaba cerrado.";
        } else {
            self::update_ticket_status($ticket_id, 'closed');
            $aviso = "✅ Ticket #{$ticket_id} «" . mb_substr($ticket->post_title, 0, 40) . "» cerrado.";
        }

        $wp_user = get_userdata($user_id);
        return $this->tarjeta_tickets($user_id, $wp_user ? $wp_user->display_name : 'Usuario', $bot_instance, $aviso);
    }

    public static function puede_cerrar($ticket_id, $user_id): bool
    {
        if (user_can($user_id, 'administrator')) return true;
        if (self::is_ticket_owner($ticket_id, $user_id)) return true;
        $ids = array_map('intval', wp_list_pluck(self::get_manageable_tickets_for_user($user_id), 'ID'));
        return in_array((int)$ticket_id, $ids, true);
    }

    private function tarjeta_tickets(int $user_id, string $nombre, $bot_instance, string $aviso = ''): array
    {
        $propios = self::get_user_tickets($user_id);
        $gestion = self::get_manageable_tickets_for_user($user_id);

        if (empty($propios) && empty($gestion)) {
            $texto = ($aviso !== '' ? $aviso . "\n\n" : '') . "No tienes tickets abiertos ni tareas pendientes. 🎉";
            return $bot_instance->tarjeta_simple('🎫 Tus Tickets', $texto, home_url('/?view=tickets&teams=true'));
        }

        $hechos  = [];
        $botones = [];

        // 1. Añadimos primero los que tiene que gestionar (Prioridad de trabajo)
        if (!empty($gestion)) {
            foreach (array_slice($gestion, 0, 5) as $ticket) {
                $prio = get_post_meta($ticket->ID, '_ep_ticket_priority', true) ?: 'Normal';
                $hechos[] = ['title' => '📥 [GESTIÓN] #' . $ticket->ID . ' ' . mb_substr($ticket->post_title, 0, 26), 'value' => $prio];
                if (count($botones) < 2) {
                    $botones[] = ['type' => 'Action.Submit', 'title' => '✔ Cerrar #' . $ticket->ID, 'data' => ['a' => 'ticket_close', 'id' => (int)$ticket->ID]];
                }
            }
        }

        // 2. Si hay hueco (hasta 6 total), añadimos los que él ha solicitado
        if (!empty($propios) && count($hechos) < 6) {
            foreach (array_slice($propios, 0, 6 - count($hechos)) as $ticket) {
                $st = get_post_meta($ticket->ID, '_ep_ticket_status', true) ?: 'open';
                $hechos[] = ['title' => '📤 [MI SOLICITUD] #' . $ticket->ID . ' ' . mb_substr($ticket->post_title, 0, 22), 'value' => $st];
                if ($st !== 'closed' && count($botones) < 4) {
                    $botones[] = ['type' => 'Action.Submit', 'title' => '✔ Cerrar #' . $ticket->ID, 'data' => ['a' => 'ticket_close', 'id' => (int)$ticket->ID]];
                }
            }
        }

        $cuerpo = [
            ['type' => 'TextBlock', 'text' => "🎫 Gestión de Tickets, {$nombre}", 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
        ];
        if ($aviso !== '') {
            $cuerpo[] = ['type' => 'TextBlock', 'text' => $aviso, 'wrap' => true, 'color' => 'Good'];
        }
        $cuerpo[]  = ['type' => 'FactSet', 'facts' => $hechos];
        $botones[] = ['type' => 'Action.OpenUrl', 'title' => '📋 Ver todos en el Portal', 'url' => home_url('/?view=tickets&teams=true')];

        return $bot_instance->adaptive_card($cuerpo, $botones);
    }

    private function tarjeta_ticket_individual(int $ticket_id, int $user_id, $bot_instance): array
    {
        $ticket = get_post($ticket_id);
        if (!$ticket || $ticket->post_type !== 'ep_ticket') {
            return $bot_instance->tarjeta_simple('🔍 Ticket no encontrado', "No existe ningún ticket con el número #{$ticket_id}.", '');
        }

        // Verificar si el usuario tiene permiso para VER este ticket específico
        $es_autor = (int)$ticket->post_author === $user_id;
        global $ep_app_manager;
        $es_gestor = false;
        if (isset($ep_app_manager)) {
            $es_gestor = $ep_app_manager->get_user_permission('tickets', $user_id) === 'write' || user_can($user_id, 'administrator'); 
        }

        if (!$es_autor && !$es_gestor) {
             return $bot_instance->tarjeta_simple('🚫 Acceso Denegado', "No tienes permisos para ver el ticket #{$ticket_id}.", '');
        }

        $status = get_post_meta($ticket_id, '_ep_ticket_status', true) ?: 'Abierto';
        $prio   = get_post_meta($ticket_id, '_ep_ticket_priority', true) ?: 'Normal';
        $desc   = mb_substr(strip_tags($ticket->post_content), 0, 150) . '...';

        $cuerpo = [
            ['type' => 'TextBlock', 'text' => "🎫 Ticket #{$ticket_id}", 'weight' => 'Bolder', 'size' => 'Large'],
            ['type' => 'TextBlock', 'text' => "**Asunto:** {$ticket->post_title}", 'wrap' => true],
            ['type' => 'FactSet', 'facts' => [
                ['title' => 'Estado', 'value' => $status],
                ['title' => 'Prioridad', 'value' => $prio],
                ['title' => 'Creado', 'value' => get_the_date('', $ticket_id)]
            ]],
            ['type' => 'TextBlock', 'text' => "_{$desc}_", 'wrap' => true, 'isSubtle' => true]
        ];

        $acciones = [];
        if ($status !== 'closed' && self::puede_cerrar($ticket_id, $user_id)) {
            $acciones[] = ['type' => 'Action.Submit', 'title' => '✔ Cerrar ticket', 'data' => ['a' => 'ticket_close', 'id' => (int)$ticket_id]];
        }
        $acciones[] = ['type' => 'Action.OpenUrl', 'title' => '🔍 Ver en Portal', 'url' => home_url("/?view=tickets&ticket_id={$ticket_id}")];

        return $bot_instance->adaptive_card($cuerpo, $acciones);
    }

    /**
     * Helper to check if a user is staff (admin or has write permissions)
     */
    public static function is_staff($user_id)
    {
        if (user_can($user_id, 'administrator')) return true;
        
        global $ep_app_manager;
        if (isset($ep_app_manager)) {
            if ($ep_app_manager->get_user_permission('tickets', $user_id) === 'write') return true;
        }

        $dept = get_user_meta($user_id, 'ep_department', true);
        if ($dept && (stripos((string)$dept, 'Comuni') !== false || stripos((string)$dept, 'TRANSFORMACI') !== false || stripos((string)$dept, 'Digital') !== false)) {
            return true;
        }

        return false;
    }
}

// Initialize the Logic Class
new EP_Tickets();
