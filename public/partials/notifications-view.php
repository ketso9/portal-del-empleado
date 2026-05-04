<?php
defined('ABSPATH') || exit;

$user_id = get_current_user_id();
$notifications = EP_Notifications::get_user_notifications($user_id, 100); // Get last 100
?>

<div class="ep-notifications-view" id="ep-app-root">
    <div class="ep-notifications-header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <a href="?view=dashboard" class="ep-btn-back"><i class="fa-solid fa-arrow-left"></i> Volver</a>
            <h2><i class="fa-solid fa-bell"></i> Todas mis Notificaciones</h2>
        </div>
        <button id="epMarkAllReadBtn" class="ep-btn ep-btn-secondary"><i class="fa-solid fa-check-double"></i> Marcar
            todas como leídas</button>
    </div>

    <div class="ep-card">
        <div class="notifications-full-list">
            <?php if (empty($notifications)): ?>
                <div class="no-notifications">
                    <i class="fa-solid fa-bell-slash"></i>
                    <p>No tienes notificaciones pendientes.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notif): ?>
                    <div class="notification-full-item <?php echo $notif->is_read ? '' : 'unread'; ?>"
                        data-id="<?php echo $notif->id; ?>">
                        <div class="notif-icon-type">
                            <?php
                            $icon = 'fa-info-circle';
                            $color = '#2196F3';
                            if ($notif->type === 'success') {
                                $icon = 'fa-check-circle';
                                $color = '#4CAF50';
                            } elseif ($notif->type === 'warning') {
                                $icon = 'fa-exclamation-triangle';
                                $color = '#FF9800';
                            } elseif ($notif->type === 'error') {
                                $icon = 'fa-times-circle';
                                $color = '#F44336';
                            }
                            ?>
                            <i class="fa-solid <?php echo $icon; ?>" style="color: <?php echo $color; ?>"></i>
                        </div>
                        <div class="notif-content">
                            <h4>
                                <?php echo esc_html($notif->title); ?>
                            </h4>
                            <p>
                                <?php echo esc_html($notif->message); ?>
                            </p>
                            <span class="notif-date">
                                <?php echo human_time_diff(strtotime($notif->created_at), current_time('timestamp')) . ' ago'; ?>
                            </span>
                        </div>
                        <?php if (!empty($notif->link)): ?>
                            <div class="notif-action">
                                <a href="<?php echo esc_url($notif->link); ?>" class="ep-btn ep-btn-sm ep-btn-primary">Ver</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .ep-notifications-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
    }

    .notifications-full-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .notification-full-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px;
        border-radius: 8px;
        background: #f8f9fa;
        border-left: 4px solid transparent;
        transition: all 0.2s ease;
    }

    .notification-full-item.unread {
        background: #fff;
        border-left-color: #a81c24;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }

    .notification-full-item:hover {
        transform: translateX(5px);
    }

    .notif-icon-type {
        font-size: 24px;
        width: 30px;
        text-align: center;
    }

    .notif-content {
        flex: 1;
    }

    .notif-content h4 {
        margin: 0 0 5px 0;
        font-size: 16px;
    }

    .notif-content p {
        margin: 0 0 5px 0;
        color: #666;
        font-size: 14px;
    }

    .notif-date {
        font-size: 12px;
        color: #999;
    }

    .no-notifications {
        text-align: center;
        padding: 40px;
        color: #999;
    }

    .no-notifications i {
        font-size: 48px;
        margin-bottom: 15px;
        display: block;
    }
</style>

<script>
    jQuery(document).ready(function ($) {
        $('#epMarkAllReadBtn').on('click', function () {
            $.post(ep_vars.ajax_url, {
                action: 'ep_mark_all_notifications_read',
                nonce: ep_vars.nonce
            }, function (res) {
                if (res.success) {
                    $('.notification-full-item').removeClass('unread');
                    updateBadge(0);
                }
            });
        });

        $(document).on('click', '.notification-full-item.unread', function () {
            var $item = $(this);
            var id = $item.data('id');
            $.post(ep_vars.ajax_url, {
                action: 'ep_mark_notification_read',
                id: id,
                nonce: ep_vars.nonce
            }, function (res) {
                if (res.success) {
                    $item.removeClass('unread');
                }
            });
        });
    });
</script>