<?php
/** @var \Merlin\ProductFiller\Block\Adminhtml\Shell\MassPreview $block */
$rows = $block->rows();
$ready = array_values(array_filter($rows, static fn (array $row): bool => $row['eligible']));
?>
<div class="admin__page-section">
    <p><a href="<?= $block->escapeUrl($block->getUrl('merlin_product_filler/shell/index')) ?>">&larr; <?= $block->escapeHtml(__('Back to shell grid')) ?></a></p>
    <p><?= $block->escapeHtml(__('%1 of %2 selected products are ready for a 95/100 or better bulk fill.', count($ready), count($rows))) ?></p>
    <table class="data-grid"><thead><tr>
        <th class="data-grid-th"><?= $block->escapeHtml(__('Target')) ?></th>
        <th class="data-grid-th"><?= $block->escapeHtml(__('Matched source')) ?></th>
        <th class="data-grid-th"><?= $block->escapeHtml(__('Match')) ?></th>
        <th class="data-grid-th"><?= $block->escapeHtml(__('Proposed fill')) ?></th>
        <th class="data-grid-th"><?= $block->escapeHtml(__('Status')) ?></th>
    </tr></thead><tbody>
        <?php foreach ($rows as $row): ?>
            <?php $target = $row['target'] ?? null; $source = $row['source'] ?? null; ?>
            <tr>
                <td>
                    <?php if ($target): ?>
                        <a href="<?= $block->escapeUrl($block->getUrl('catalog/product/edit', ['id' => (int)$row['id']])) ?>"><?= $block->escapeHtml((string)$row['id'] . ' / ' . (string)$target->getSku()) ?></a>
                        <br/><?= $block->escapeHtml((string)$target->getName()) ?>
                    <?php else: ?><?= (int)$row['id'] ?><?php endif; ?>
                </td>
                <td>
                    <?php if ($source): ?>
                        <a href="<?= $block->escapeUrl($block->getUrl('catalog/product/edit', ['id' => (int)$source->getId()])) ?>"><?= $block->escapeHtml((string)$source->getId() . ' / ' . (string)$source->getSku()) ?></a>
                        <br/><?= $block->escapeHtml((string)$source->getName()) ?>
                    <?php else: ?>â€”<?php endif; ?>
                </td>
                <td><?= (int)($row['confidence'] ?? 0) ?>/100</td>
                <td>
                    <?php if ($row['eligible']): ?>
                        <?= $block->escapeHtml((string)$row['plan']['generated']['name']) ?><br/>
                        <?= $block->escapeHtml(__('%1 fields to copy; %2 fields to generate', count($row['plan']['copy']), count($row['plan']['generated']))) ?>
                        <?php if ($row['plan']['warnings']): ?>
                            <details><summary><?= $block->escapeHtml(__('Warnings')) ?></summary><ul>
                                <?php foreach ($row['plan']['warnings'] as $warning): ?><li><?= $block->escapeHtml($warning) ?></li><?php endforeach; ?>
                            </ul></details>
                        <?php endif; ?>
                        <a href="<?= $block->escapeUrl($block->getUrl('merlin_product_filler/shell/preview', ['target_id' => (int)$row['id'], 'source_id' => (int)$source->getId()])) ?>"><?= $block->escapeHtml(__('Full preview')) ?></a>
                    <?php else: ?>â€”<?php endif; ?>
                </td>
                <td><?= $block->escapeHtml($row['eligible'] ? __('Ready') : __('Skipped: %1', $row['reason'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table>
    <?php if ($ready): ?>
        <p><?= $block->escapeHtml(__('Confirmed products will be queued for background fill. Each product is checked again before its fill. Products changed since this review will be skipped. Stock, prices, images and protected fields remain under the existing apply safeguards.')) ?></p>
        <form method="post" action="<?= $block->escapeUrl($block->getUrl('merlin_product_filler/shell/massApply')) ?>">
            <input type="hidden" name="form_key" value="<?= $block->escapeHtmlAttr($block->getFormKey()) ?>"/>
            <?php foreach ($ready as $row): ?>
                <input type="hidden" name="product[]" value="<?= (int)$row['id'] ?>"/>
                <input type="hidden" name="expected_source[<?= (int)$row['id'] ?>]" value="<?= (int)$row['source']->getId() ?>"/>
                <input type="hidden" name="expected_sku[<?= (int)$row['id'] ?>]" value="<?= $block->escapeHtmlAttr((string)$row['target']->getSku()) ?>"/>
                <input type="hidden" name="expected_fingerprint[<?= (int)$row['id'] ?>]" value="<?= $block->escapeHtmlAttr($row['fingerprint']) ?>"/>
            <?php endforeach; ?>
            <label><input type="checkbox" name="reviewed" value="1" required/>
                <?= $block->escapeHtml(__('I have reviewed the selected products and their proposed sources.')) ?></label>
            <div class="admin__field" style="margin-top:1rem">
                <button type="submit" class="action-primary"><span><?= $block->escapeHtml(__('Queue %1 reviewed products', count($ready))) ?></span></button>
            </div>
        </form>
    <?php endif; ?>
</div>
