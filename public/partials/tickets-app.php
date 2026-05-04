<?php

defined('ABSPATH') || exit;
$current_user = wp_get_current_user();
$tickets = class_exists('EP_Tickets') ? EP_Tickets::get_user_tickets() : [];
?>

<div class="ep-content-grid">
    <section class="ep-tickets-section full-width">
        <div class="ep-cards-row">
            <div class="ep-card ticket-form-card">
                <h3>Crear Nuevo Ticket</h3>
                <?php if (isset($_GET['ticket_submitted'])): ?>
                    <div class="ep-alert success">¡Ticket enviado correctamente!</div>
                <?php endif; ?>
                <form method="post">
                    <?php wp_nonce_field('ep_submit_ticket_nonce'); ?>
                    <div class="form-group">
                        <label>Asunto / Breve descripción</label>
                        <input type="text" name="ticket_title" required placeholder="Ej: No funciona el teclado"
                            style="width:100%; margin-bottom: 1rem;">
                    </div>
                    <div class="form-group">
                        <label>Mensaje detallado</label>
                        <textarea name="ticket_message" required rows="5"
                            style="width:100%; margin-bottom: 1rem;"></textarea>
                    </div>
                    <button type="submit" name="ep_submit_ticket" class="ep-btn">Enviar Ticket</button>
                </form>
            </div>
        </div>

        <div class="ep-card ticket-list-card" style="margin-top: 2rem;">
            <h3>Mis Tickets Recientes</h3>
            <ul class="ep-ticket-list">
                <?php if (!empty($tickets)): ?>
                    <?php foreach ($tickets as $ticket): ?>
                        <li class="ticket-item">
                            <div class="ticket-info">
                                <strong><?php echo esc_html($ticket->post_title); ?></strong>
                                <span class="ticket-date"><?php echo get_the_date('d/m/Y', $ticket->ID); ?></span>
                            </div>
                            <span
                                class="status-badge <?php echo strtolower(get_post_meta($ticket->ID, '_ep_ticket_status', true)); ?>">
                                <?php echo ucfirst(get_post_meta($ticket->ID, '_ep_ticket_status', true)); ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="text-muted">No tienes tickets abiertos.</li>
                <?php endif; ?>
            </ul>
        </div>
    </section>
</div>