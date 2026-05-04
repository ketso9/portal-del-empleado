<?php defined('ABSPATH') || exit; ?>

<div class="ep-maintenance-container">
    <div class="ep-maintenance-card">
        <div class="ep-maintenance-icon">
            <i class="fa-solid fa-screwdriver-wrench"></i>
        </div>
        <h1>Portal en Mantenimiento</h1>
        <p>Estamos realizando mejoras para ofrecerte un mejor servicio. El portal estará disponible de nuevo pronto.</p>
        <div class="ep-maintenance-footer">
            <p>Disculpa las molestias.</p>
            <?php
            $custom = get_option('ep_portal_customization', array());
            $logo_url = $custom['logo_url'] ?? plugin_dir_url(dirname(__FILE__, 2)) . 'public/images/logo-portal.jpg';
            ?>
            <img src="<?php echo esc_url($logo_url); ?>" alt="Logo Portal" style="width: 150px; margin-top: 20px;">
        </div>
    </div>
</div>

<style>
    .ep-maintenance-container {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 60vh;
        padding: 20px;
    }

    .ep-maintenance-card {
        background: #fff;
        padding: 40px;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        text-align: center;
        max-width: 500px;
        width: 100%;
    }

    .ep-maintenance-icon {
        font-size: 60px;
        color: #a81c24;
        margin-bottom: 20px;
    }

    .ep-maintenance-card h1 {
        color: #333;
        margin-bottom: 15px;
        font-size: 28px;
    }

    .ep-maintenance-card p {
        color: #666;
        line-height: 1.6;
        margin-bottom: 20px;
    }

    .ep-maintenance-footer {
        border-top: 1px solid #eee;
        padding-top: 20px;
        margin-top: 20px;
    }
</style>