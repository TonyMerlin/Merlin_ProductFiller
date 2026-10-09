<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Block\Adminhtml\Shell;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Grid\Extended;
use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Merlin\ProductFiller\Model\ShellAssessment;
use Merlin\ProductFiller\Model\TargetEligibility;

class Grid extends Extended
{
    public function __construct(
        Context $context,
        BackendHelper $backendHelper,
        private CollectionFactory $collections,
        private ResourceConnection $resource,
        private ShellAssessment $assessment,
        private TargetEligibility $eligibility,
        array $data = []
    ) {
        parent::__construct($context, $backendHelper, $data);
    }

    protected function _construct(): void
    {
        parent::_construct();
        $this->setId('merlinProductFillerShellGrid');
        $this->setDefaultSort('entity_id');
        $this->setDefaultDir('DESC');
        $this->setDefaultLimit(20);
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(false);
    }

    protected function _prepareCollection()
    {
        $collection = $this->collections->create();
        $collection->setStoreId(0)->addAttributeToSelect([
            'name', 'ebay_quote_ref', 'status', 'manufacturer', 'modelno', 'mpn',
            'merlin_model_brand_key', 'merlin_model_code_key',
        ]);
        $collection->addAttributeToFilter([
            ['attribute' => 'sku', 'like' => 'NCSI%'],
            ['attribute' => 'sku', 'regexp' => '^[0-9]{5}$'],
        ]);
        $collection->addAttributeToFilter('attribute_set_id', ['eq' => 4]);
        $collection->addAttributeToFilter('status', ['eq' => 2]);
        $cutoff = $this->eligibility->cutoff();
        if ($cutoff === null) {
            $collection->getSelect()->where('1 = 0');
        } else {
            $collection->addFieldToFilter('created_at', ['gteq' => $cutoff]);
        }
        $categories = $this->resource->getTableName('catalog_category_product');
        $images = $this->resource->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $collection->getSelect()
            ->where('NOT EXISTS (SELECT 1 FROM ' . $categories . ' AS pf_category WHERE pf_category.product_id = e.entity_id)')
            ->where('NOT EXISTS (SELECT 1 FROM ' . $images . ' AS pf_image WHERE pf_image.entity_id = e.entity_id)');
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $renderer = \Merlin\ProductFiller\Block\Adminhtml\Shell\Grid\Renderer\Review::class;
        $this->addColumn('entity_id', ['header' => __('ID'), 'index' => 'entity_id', 'type' => 'number', 'width' => '75px']);
        $this->addColumn('sku', [
            'header' => __('Target SKU'),
            'index' => 'sku',
            'width' => '130px',
            'renderer' => $renderer,
        ]);
        $this->addColumn('name', ['header' => __('Target Name'), 'index' => 'name']);
        $this->addColumn('ebay_quote_ref', ['header' => __('Stock Reference'), 'index' => 'ebay_quote_ref', 'width' => '110px']);
        foreach ([
            'identity' => __('Detected Brand / Model'),
            'source' => __('Best Source'),
            'confidence' => __('Match'),
            'recommended_action' => __('Recommended Action'),
            'review_link' => __('Review'),
        ] as $id => $header) {
            $this->addColumn($id, [
                'header' => $header,
                'index' => $id,
                'renderer' => $renderer,
                'filter' => false,
                'sortable' => false,
            ]);
        }
        return parent::_prepareColumns();
    }

    protected function _prepareMassaction()
    {
        if (!$this->_authorization->isAllowed('Merlin_ProductFiller::apply')) {
            return $this;
        }
        $this->setMassactionIdField('entity_id');
        $this->setNoFilterMassactionColumn(true);
        $massaction = $this->getMassactionBlock();
        $massaction->setFormFieldName('product');
        $massaction->setUseSelectAll(false);
        $massaction->addItem('mass_fill_review', [
            'label' => __('Review selected fills (95/100+ matches)'),
            'url' => $this->getUrl('merlin_product_filler/shell/massPreview'),
        ]);
        return $this;
    }

    public function getRowUrl($row): string
    {
        $review = $this->assessment->assess($row);
        return $this->getUrl('merlin_product_filler/shell/preview', [
            'target_id' => (int)$row->getId(),
            'source_id' => $review['best'] ? (int)$review['best']['product']->getId() : 0,
        ]);
    }
}
