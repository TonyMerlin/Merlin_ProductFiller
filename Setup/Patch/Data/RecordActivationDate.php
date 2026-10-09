<?php
declare(strict_types=1);

namespace Merlin\ProductFiller\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Merlin\ProductFiller\Model\TargetEligibility;

class RecordActivationDate implements DataPatchInterface
{
    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private ScopeConfigInterface $scopeConfig,
        private WriterInterface $configWriter,
        private DateTime $dateTime
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        try {
            $existing = trim((string)$this->scopeConfig->getValue(TargetEligibility::CONFIG_PATH));
            if ($existing === '') {
                $this->configWriter->save(TargetEligibility::CONFIG_PATH, $this->dateTime->gmtDate());
            }
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
        }
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
