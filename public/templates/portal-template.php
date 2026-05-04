<?php
/**
 * Template Name: Portal del Empleado
 * Description: A full-page template for the Employee Portal plugin, bypassing the active theme.
 */

defined('ABSPATH') || exit;


// Basic HTML structure since we are bypassing get_header() and get_footer() of the theme
// to avoid conflicts. We manually call wp_head() and wp_footer().

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php wp_title('|', true, 'right'); ?></title>
    <?php wp_head(); ?>
    <style>
        /* Essential reset for the portal container to ensure it takes full height/width if needed */
        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            background-color: #f0f2f5;
            /* Match portal background */
        }
    </style>
</head>

<body <?php body_class(); ?>>

    <?php
    // We execute the standard loop logic just in case, but really we just want the content
    // which effectively is the shortcode output since we are on the portal page.
    while (have_posts()):
        the_post();
        the_content();
    endwhile;
    ?>

    <?php wp_footer(); ?>
</body>

</html>