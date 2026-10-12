<?php
/** @var \Merlin\ProductFiller\Block\Adminhtml\Shell\Preview $block */
$review = $block->getReview();
?>
<div class="admin__page-section">
    <p><a href="<?= $block->escapeUrl($block->getUrl('merlin_product_filler/shell/index')) ?>">&larr; <?= $block->escapeHtml(__('Back to shell grid')) ?></a></p>
    <?php if (isset($review['error'])): ?>
        <div class="message message-error error"><div><?= $block->escapeHtml($review['error']) ?></div></div>
    <?php else: ?>
        <?php $target = $review['target']; $source = $review['source']; $assessment = $review['assessment']; $plan = $review['plan']; ?>
        <h2><?= $block->escapeHtml(__('Target')) ?>: <?= $block->escapeHtml((string)$target->getId() . ' / ' . (string)$target->getSku()) ?></h2>
        <p><a href="<?= $block->escapeUrl($block->getUrl('merlin_product_filler/audit/index', ['target_id' => (int)$target->getId()])) ?>"><?= $block->escapeHtml(__('View fill audit history for this product')) ?></a></p>
        <p><?= $block->escapeHtml((string)$target->getName()) ?></p>
        <p><?= $block->escapeHtml(__('Reference')) ?>: <strong><?= $block->escapeHtml($assessment['reference'] ?: '(missing)') ?></strong></p>
        <?php if ($assessment['reference_problem'] !== null): ?>
            <div class="message message-error error"><div><?= $block->escapeHtml($assessment['reference_problem']) ?></div></div>
        <?php endif; ?>
        <p><?= $block->escapeHtml(__('Detected brand / model')) ?>:
            <?= $block->escapeHtml($assessment['identity']['brand'] . ' / ' . $assessment['identity']['model']) ?></p>
        <p><?= $block->escapeHtml(__('Shell')) ?>: <?= $assessment['shell']['is_shell'] ? 'Yes' : 'No' ?> â€”
            <?= $block->escapeHtml($assessment['shell']['reason']) ?></p>
        <p><strong><?= $block->escapeHtml(__('Recommended action')) ?>: <?= $block->escapeHtml($assessment['action']) ?></strong></p>

        <?php if ($review['requested_source_missing']): ?>
            <div class="message message-warning warning"><div><?= $block->escapeHtml(__('The requested source is not an exact candidate for this target.')) ?></div></div>
        <?php endif; ?>
        <?php if (!$source): ?>
            <div class="message message-notice notice"><div><?= $block->escapeHtml(__('No source is available for a fill preview. Review the model and reference data.')) ?></div></div>
        <?php else: ?>
            <h2><?= $block->escapeHtml(__('Matched source')) ?>: <?= $block->escapeHtml((string)$source->getId() . ' / ' . (string)$source->getSku()) ?></h2>
            <p><?= $block->escapeHtml((string)$source->getName()) ?> â€”
                <?= $block->escapeHtml($review['match']['enabled'] ? __('Enabled') : __('Disabled')) ?>,
                <?= (int)$review['match']['confidence'] ?>/100,
                <?= $block->escapeHtml($review['match']['match_reason']) ?></p>
            <?php $displayWarnings = array_merge($plan['warnings'], ['Images and the explicitly skipped damage attributes are never copied.']); ?>
            <p>
                <strong><?= $block->escapeHtml(__('Review summary')) ?>:</strong>
                <a href="#product-filler-generated"><?= $block->escapeHtml(__('%1 fields to generate', count($plan['generated']))) ?></a> Â·
                <a href="#product-filler-warnings"><?= $block->escapeHtml(__('%1 warnings and safeguards', count($displayWarnings))) ?></a> Â·
                <a href="#product-filler-copy"><?= $block->escapeHtml(__('%1 fields to copy', count($plan['copy']))) ?></a>
            </p>

            <?php foreach (['generated' => __('Fields to generate'), 'copy' => __('Fields to copy')] as $group => $heading): ?>
                <h3 id="product-filler-<?= $block->escapeHtmlAttr($group) ?>"><?= $block->escapeHtml(__('%1 (%2)', $heading, count($plan[$group]))) ?></h3>
                <table class="data-grid"><thead><tr>
                    <th class="data-grid-th"><?= $block->escapeHtml(__('Field')) ?></th>
                    <th class="data-grid-th"><?= $block->escapeHtml(__('Target now')) ?></th>
                    <th class="data-grid-th"><?= $block->escapeHtml(__('Proposed')) ?></th>
                </tr></thead><tbody>
                <?php foreach ($plan[$group] as $field => $value): ?>
                    <?php
                    $before = $field === 'category_ids' ? $target->getCategoryIds() : $target->getData($field);
                    $beforeText = $block->formatValue($target, $field, $before);
                    $afterText = $block->formatValue($source, $field, $value);
                    ?>
                    <tr><td><?= $block->escapeHtml($field) ?></td>
                        <?php foreach ([$beforeText, $afterText] as $text): ?>
                            <td>
                                <?= $block->escapeHtml(strlen($text) > 240 ? substr($text, 0, 240) . 'â€¦' : $text) ?>
                                <?php if (strlen($text) > 240): ?>
                                    <details><summary><?= $block->escapeHtml(__('Show full value')) ?></summary><pre style="white-space:pre-wrap;max-width:65rem"><?= $block->escapeHtml($text) ?></pre></details>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php if ($group === 'generated'): ?>
                    <div class="message message-warning warning" id="product-filler-warnings" style="margin-top:1.5rem">
                        <strong><?= $block->escapeHtml(__('Warnings and safeguards (%1)', count($displayWarnings))) ?></strong>
                        <ul>
                            <?php foreach ($displayWarnings as $warning): ?>
                                <li><?= $block->escapeHtml($warning) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <h3><?= $block->escapeHtml(__('Fields kept from target')) ?></h3>
            <p><?= $block->escapeHtml(implode(', ', array_values(array_diff(
                \Merlin\ProductFiller\Model\FillPlanBuilder::KEEP_FIELDS, array_keys($plan['copy'])
            )))) ?></p>
            <?php if ($plan['preserved']): ?>
                <p><?= $block->escapeHtml(__('Existing target values retained')) ?>: <?= $block->escapeHtml(implode(', ', $plan['preserved'])) ?></p>
            <?php endif; ?>
            <?php if ($plan['attribute_set_additions']): ?>
                <h3><?= $block->escapeHtml(__('Destination attribute set update')) ?></h3>
                <p><?= $block->escapeHtml(__('Add Ncompass tracking attributes to set %1: %2', $source->getAttributeSetId(), implode(', ', $plan['attribute_set_additions']))) ?></p>
            <?php endif; ?>
            <h3><?= $block->escapeHtml(__('Fields explicitly skipped')) ?></h3>
            <p><?= $block->escapeHtml(implode(', ', \Merlin\ProductFiller\Model\FillPlanBuilder::SKIP_FIELDS)) ?></p>
            <?php if ($plan['content_errors']): ?>
                <div class="message message-error error"><div><?= $block->escapeHtml(__('This source contains unapproved content. Fill is blocked until the source content is corrected and reviewed again.')) ?></div></div>
            <?php endif; ?>
            <?php if ($plan['price_error']): ?>
                <div class="message message-error error"><div><?= $block->escapeHtml($plan['price_error']) ?></div></div>
            <?php endif; ?>

            <?php if ($review['can_apply'] && $block->canApply()): ?>
                <form method="post" action="<?= $block->escapeUrl($block->getUrl('merlin_product_filler/shell/apply')) ?>">
                    <input type="hidden" name="form_key" value="<?= $block->escapeHtmlAttr($block->getFormKey()) ?>"/>
                    <input type="hidden" name="target_id" value="<?= (int)$target->getId() ?>"/>
                    <input type="hidden" name="source_id" value="<?= (int)$source->getId() ?>"/>
                    <input type="hidden" name="confirm_sku" value="<?= $block->escapeHtmlAttr((string)$target->getSku()) ?>"/>
                    <input type="hidden" name="review_fingerprint" value="<?= $block->escapeHtmlAttr($review['fingerprint']) ?>"/>
                    <label><input type="checkbox" name="reviewed" value="1" required/>
                        <?= $block->escapeHtml(__('I have reviewed the proposed values, including the full source descriptions, for this stock unit.')) ?></label>
                    <div class="admin__field" style="margin-top:1rem">
                        <button type="submit" class="action-primary"><span><?= $block->escapeHtml(__('Apply fill to this product')) ?></span></button>
                    </div>
                </form>
            <?php elseif ($assessment['shell']['is_shell']): ?>
                <div class="message message-warning warning"><div><?= $block->escapeHtml(__('Apply is unavailable until this shell has a strong exact source match and a stock reference.')) ?></div></div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
