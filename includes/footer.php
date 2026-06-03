    </main>
</div>
<script src="<?= h(asset_url('/assets/js/shortcuts.js')) ?>"></script>
<script src="<?= h(asset_url('/assets/js/app.js')) ?>"></script>
<script src="<?= h(asset_url('/assets/js/dropzones.js')) ?>"></script>
<script src="<?= h(asset_url('/assets/js/datatable.js')) ?>"></script>
<script src="<?= h(asset_url('/assets/js/chips.js')) ?>"></script>
<script src="<?= h(asset_url('/assets/js/combobox.js')) ?>"></script>
<?php
// BOM designer engine. 'sortable' (default) uses the new SortableJS-based
// implementation; 'custom' uses the original hand-rolled drag-drop. Toggle
// in app.config.php under $APP['bom_designer']['engine'] to revert.
$bomEngine = isset($GLOBALS['APP']['bom_designer']['engine'])
    ? $GLOBALS['APP']['bom_designer']['engine']
    : 'sortable';
if ($bomEngine === 'sortable'): ?>
    <script src="<?= h(asset_url('/assets/js/vendor/Sortable.min.js')) ?>"></script>
    <script src="<?= h(asset_url('/assets/js/bom-designer-sortable.js')) ?>"></script>
<?php else: ?>
    <script src="<?= h(asset_url('/assets/js/bom-designer.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(asset_url('/assets/js/spa.js')) ?>"></script>
<?php if (!empty($extra_js)): ?>
    <?php foreach ((array)$extra_js as $js): ?>
        <script src="<?= h(asset_url($js)) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
