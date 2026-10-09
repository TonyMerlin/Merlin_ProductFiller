<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Block\Adminhtml;

use Magento\Backend\Block\Widget\Grid\Container;

class Shell extends Container
{
    protected function _construct(): void
    {
        $this->_controller = 'adminhtml_shell';
        $this->_blockGroup = 'Merlin_ProductFiller';
        $this->_headerText = __('Ncompass Shell Products');
        parent::_construct();
        $this->buttonList->remove('add');
    }
}
