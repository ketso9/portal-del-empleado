<?php
/**
 * Premium 404 Page for Employee Portal
 * Design matches the login screen for a high-end feel.
 */
if (!defined('ABSPATH'))
    exit;

$custom = get_option('ep_portal_customization', array());
$logo_url = $custom['logo_url'] ?? plugin_dir_url(dirname(__FILE__, 2)) . 'public/images/logo-portal.jpg';
$bg_url = plugin_dir_url(dirname(__FILE__, 2)) . 'public/images/login-bg-camara.jpg';
$portal_home = home_url('/');

// We don't use layout.php here to allow for a full-screen premium experience
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Página no encontrada | <?php echo get_bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <style>
        :root {
            --ep-primary: #a81c24;
            --ep-primary-rgb: 168, 28, 36;
        }

        body,
        html {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            overflow: hidden;
        }

        .ep-404-page-wrapper {
            position: relative;
            width: 100%;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .ep-404-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            z-index: 1;
            filter: scale(1.1) brightness(0.7) blur(25px);
            /* Desenfoque más intenso */
            opacity: 0.6;
            /* Opacidad reducida */
            animation: kenburns 20s infinite alternate;
        }

        .ep-404-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to bottom, rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.4));
            z-index: 2;
        }

        .ep-404-container {
            position: relative;
            z-index: 3;
            width: 100%;
            max-width: 550px;
            padding: 20px;
        }

        .ep-404-card {
            background: rgba(255, 255, 255, 0.4);
            /* Blanco con mayor transparencia */
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            padding: 4rem 3rem;
            border-radius: 32px;
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.25),
                inset 0 0 0 1px rgba(255, 255, 255, 0.5);
            text-align: center;
            animation: fadeInScale 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .ep-404-logo {
            margin-bottom: 2rem;
        }

        .ep-404-logo img {
            height: 80px;
            width: auto;
            object-fit: contain;
        }

        .ep-404-error-code {
            font-size: 5rem;
            font-weight: 900;
            color: var(--ep-primary);
            margin: 0;
            line-height: 1;
            opacity: 0.1;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: -1;
            pointer-events: none;
        }

        h1 {
            font-size: 1.8rem;
            color: #1e293b;
            margin-bottom: 1rem;
            font-weight: 800;
            position: relative;
        }

        p {
            color: #64748b;
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .ep-back-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            background: var(--ep-primary);
            color: white !important;
            text-decoration: none;
            padding: 1rem 2rem;
            border-radius: 50px;
            font-weight: 700;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            box-shadow: 0 10px 20px rgba(var(--ep-primary-rgb), 0.3);
        }

        .ep-back-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px rgba(var(--ep-primary-rgb), 0.4);
            background: #8b171d;
        }

        .ep-back-btn i {
            font-size: 1.2rem;
        }

        @keyframes kenburns {
            0% {
                transform: scale(1);
            }

            100% {
                transform: scale(1.1);
            }
        }

        @keyframes fadeInScale {
            0% {
                opacity: 0;
                transform: scale(0.9);
            }

            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Dark mode support (if forced by browser/system) */
        @media (prefers-color-scheme: dark) {
            .ep-404-card {
                background: rgba(30, 41, 59, 0.9);
                border-color: rgba(255, 255, 255, 0.1);
            }

            h1 {
                color: #f1f5f9;
            }

            p {
                color: #94a3b8;
            }
        }
    </style>
</head>

<body>
    <div class="ep-404-page-wrapper">
        <div class="ep-404-bg" style="background-image: url('<?php echo esc_url($bg_url); ?>');"></div>
        <div class="ep-404-overlay"></div>

        <div class="ep-404-container">
            <div class="ep-404-card">
                <div class="ep-404-error-code">404</div>

                <div class="ep-404-logo">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="Logo">
                </div>

                <h1>404 - ¡Vaya! Te has salido del mapa</h1>
                <p>Parece que la página que buscas se ha tomado un día de asuntos propios o simplemente no existe en
                    este portal.<br>
                    <small>No te preocupes, hasta los mejores empleados se pierden de vez en cuando.</small>
                </p>

                <a href="<?php echo esc_url($portal_home); ?>" class="ep-back-btn">
                    <i class="fa-solid fa-house"></i>
                    <span>Volver al Inicio</span>
                </a>
            </div>
        </div>
    </div>
    <?php wp_footer(); ?>
</body>

</html>