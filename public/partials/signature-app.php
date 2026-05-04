<?php

defined('ABSPATH') || exit;
/**
 * Template for the Signature Application
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="ep-signature-app" id="ep-app-root">
    <div class="ep-signature-header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <a href="?view=dashboard" class="ep-btn-back"><i class="fa-solid fa-arrow-left"></i> Volver</a>
            <h2><i class="fa-solid fa-pen-nib"></i> Aplicación de Firma Electrónica</h2>
        </div>
        <div class="ep-signature-tabs">
            <button class="ep-tab-btn active" data-tab="sign-doc">Firmar Documento</button>
            <button class="ep-tab-btn" data-tab="my-docs">Mis Documentos</button>
        </div>
    </div>
    <!-- Tab: Sign Document -->
    <div id="sign-doc" class="ep-tab-content active">
        <div class="ep-card">
            <?php echo do_shortcode('[firma_documentos]'); ?>
        </div>
    </div>

    <!-- Tab: My Documents -->
    <div id="my-docs" class="ep-tab-content">
        <div class="ep-card">
            <?php echo do_shortcode('[fds_mis_documentos]'); ?>
        </div>
    </div>
</div>

<style>
    .ep-signature-app {
        padding: 20px;
    }

    .ep-signature-header {
        display: flex;
        justify_content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .ep-signature-tabs {
        display: flex;
        gap: 10px;
    }

    .ep-tab-btn {
        background: transparent;
        border: none;
        padding: 10px 20px;
        cursor: pointer;
        font-weight: 600;
        color: #666;
        border-bottom: 2px solid transparent;
        transition: all 0.3s ease;
    }

    .ep-tab-btn.active {
        color: #0073aa;
        border-bottom-color: #0073aa;
    }

    .ep-tab-content {
        display: none;
        animation: fadeIn 0.3s ease;
    }

    .ep-tab-content.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tabs = document.querySelectorAll('.ep-tab-btn');
        const contents = document.querySelectorAll('.ep-tab-content');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));

                // Add active class to clicked
                tab.classList.add('active');
                const targetId = tab.getAttribute('data-tab');
                document.getElementById(targetId).classList.add('active');
            });
        });
    });
</script>