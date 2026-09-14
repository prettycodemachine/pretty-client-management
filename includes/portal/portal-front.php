<?php
/**
 * Pulls in the portal's public-facing half.
 *
 * public/ is outside includes/, which is the only directory the module
 * loader's 'files' list resolves against (see includes/modules.php) — this
 * one-line wrapper is what lets the shortcode and its assets load only when
 * the Client Portal module is switched on, the same as everything else here.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once PCM_CRM_DIR . 'public/portal.php';
