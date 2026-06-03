<?php
/**
 * Shared footer for all MagDyn tools.
 * Apps-menu JS is now inlined in apps-menu.php, so this file just closes
 * the body/html tags.
 *
 * Caller MAY set:
 *   $extra_scripts — array of additional <script src=> URLs
 */
$extra_scripts = $extra_scripts ?? [];
?>
<?php foreach ($extra_scripts as $src): ?>
<script src="<?= htmlspecialchars($src) ?>"></script>
<?php endforeach; ?>

</body>
</html>
