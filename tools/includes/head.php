<?php
/**
 * Shared <head> block for all MagDyn tools.
 *
 * Caller must set BEFORE include:
 *   $page_title   — string, the <title>
 *   $current_page — string, this script's basename (used by apps-menu.php)
 * Caller MAY set:
 *   $cdn_scripts  — array of <script src=> URLs (libraries)
 */
$page_title   = $page_title   ?? 'MagDyn Tools';
$current_page = $current_page ?? '';
$cdn_scripts  = $cdn_scripts  ?? [];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?></title>
<link rel="stylesheet" href="assets/css/magdyn-base.css">
<?php foreach ($cdn_scripts as $src): ?>
<script src="<?= htmlspecialchars($src) ?>"></script>
<?php endforeach; ?>
