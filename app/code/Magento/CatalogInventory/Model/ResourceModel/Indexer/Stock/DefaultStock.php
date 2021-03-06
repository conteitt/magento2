<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogInventory\Model\ResourceModel\Indexer\Stock;

use Magento\Catalog\Model\ResourceModel\Product\Indexer\AbstractIndexer;
use Magento\CatalogInventory\Model\Stock;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Action\Full;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;

/**
 * CatalogInventory Default Stock Status Indexer Resource Model
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @api
 * @since 100.0.2
 *
 * @deprecated 100.3.0 Replaced with Multi Source Inventory
 * @link https://devdocs.magento.com/guides/v2.4/inventory/index.html
 * @link https://devdocs.magento.com/guides/v2.4/inventory/inventory-api-reference.html
 */
class DefaultStock extends AbstractIndexer implements StockInterface
{
    /**
     * @var string
     */
    protected $_typeId;

    /**
     * @var bool
     */
    protected $_isComposite = false;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var QueryProcessorComposite
     */
    private $queryProcessorComposite;

    /**
     * @var StockConfigurationInterface
     * @since 100.1.0
     */
    protected $stockConfiguration;

    /**
     * @var string
     */
    private $actionType;

    /**
     * @var GetStatusExpression
     */
    private $getStatusExpression;

    /**
     * Class constructor
     *
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Framework\Indexer\Table\StrategyInterface $tableStrategy
     * @param \Magento\Eav\Model\Config $eavConfig
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param string $connectionName
     * @param \Magento\CatalogInventory\Model\ResourceModel\Indexer\Stock\GetStatusExpression|null $getStatusExpression
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Framework\Indexer\Table\StrategyInterface $tableStrategy,
        \Magento\Eav\Model\Config $eavConfig,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        $connectionName = null,
        \Magento\CatalogInventory\Model\ResourceModel\Indexer\Stock\GetStatusExpression $getStatusExpression = null
    ) {
        $this->_scopeConfig = $scopeConfig;
        parent::__construct($context, $tableStrategy, $eavConfig, $connectionName);
        $this->getStatusExpression = $getStatusExpression ?: ObjectManager::getInstance()->get(
            \Magento\CatalogInventory\Model\ResourceModel\Indexer\Stock\GetStatusExpression::class
        );
    }

    /**
     * Initialize connection and define main table name
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('cataloginventory_stock_status', 'product_id');
    }

    /**
     * Reindex all stock status data for default logic product type
     *
     * @return $this
     * @throws \Exception
     */
    public function reindexAll()
    {
        $this->tableStrategy->setUseIdxTable(true);
        $this->beginTransaction();
        try {
            $this->_prepareIndexTable();
            $this->commit();
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }
        return $this;
    }

    /**
     * Reindex stock data for defined product ids
     *
     * @param int|array $entityIds
     * @return $this
     */
    public function reindexEntity($entityIds)
    {
        if ($this->getActionType() === Full::ACTION_TYPE) {
            $this->tableStrategy->setUseIdxTable(false);
            $this->_prepareIndexTable($entityIds);
            return $this;
        }

        $this->_updateIndex($entityIds);
        return $this;
    }

    /**
     * Returns action run type
     *
     * @return string
     * @since 100.2.0
     */
    public function getActionType()
    {
        return $this->actionType;
    }

    /**
     * Set action run type
     *
     * @param string $type
     * @return $this
     * @since 100.2.0
     */
    public function setActionType($type)
    {
        $this->actionType = $type;
        return $this;
    }

    /**
     * Set active Product Type Id
     *
     * @param string $typeId
     * @return $this
     */
    public function setTypeId($typeId)
    {
        $this->_typeId = $typeId;
        return $this;
    }

    /**
     * Retrieve active Product Type Id
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getTypeId()
    {
        if ($this->_typeId === null) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Undefined product type'));
        }
        return $this->_typeId;
    }

    /**
     * Set Product Type Composite flag
     *
     * @param bool $flag
     * @return $this
     */
    public function setIsComposite($flag)
    {
        $this->_isComposite = (bool) $flag;
        return $this;
    }

    /**
     * Check product type is composite
     *
     * @return bool
     * @SuppressWarnings(PHPMD.BooleanGetMethodName)
     */
    public function getIsComposite()
    {
        return $this->_isComposite;
    }

    /**
     * Retrieve is Global Manage Stock enabled
     *
     * @return bool
     */
    protected function _isManageStock()
    {
        return $this->_scopeConfig->isSetFlag(
            \Magento\CatalogInventory\Model\Configuration::XML_PATH_MANAGE_STOCK,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get the select object for get stock status by product ids
     *
     * @param int|array $entityIds
     * @param bool $usePrimaryTable use primary or temporary index table
     * @return \Magento\Framework\DB\Select
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getStockStatusSelect($entityIds = null, $usePrimaryTable = false)
    {
        $connection = $this->getConnection();
        $qtyExpr = $connection->getCheckSql('cisi.qty > 0', 'cisi.qty', 0);
        $metadata = $this->getMetadataPool()->getMetadata(\Magento\Catalog\Api\Data\ProductInterface::class);
        $linkField = $metadata->getLinkField();

        $select = $connection->select()->from(
            ['e' => $this->getTable('catalog_product_entity')],
            ['entity_id']
        );
        $select->join(
            ['cis' => $this->getTable('cataloginventory_stock')],
            '',
            ['website_id', 'stock_id']
        )->joinInner(
            ['cisi' => $this->getTable('cataloginventory_stock_item')],
            'cisi.stock_id = cis.stock_id AND cisi.product_id = e.entity_id',
            []
        )->joinInner(
            ['mcpei' => $this->getTable('catalog_product_entity_int')],
            'e.' . $linkField . ' = mcpei.' . $linkField
            . ' AND mcpei.attribute_id = ' . $this->_getAttribute('status')->getId()
            . ' AND mcpei.value = ' . ProductStatus::STATUS_ENABLED,
            []
        )->joinLeft(
            ['css' => 'cataloginventory_stock_status'],
            'css.product_id = e.entity_id',
            []
        )->columns(
            ['qty' => $qtyExpr]
        )->where(
            'cis.website_id = ?',
            $this->getStockConfiguration()->getDefaultScopeId()
        )->where('e.type_id = ?', $this->getTypeId())
            ->group(['e.entity_id', 'cis.website_id', 'cis.stock_id']);

        $select->columns(['status' => $this->getStatusExpression($connection, true)]);
        if ($entityIds !== null) {
            $select->where('e.entity_id IN(?)', $entityIds, \Zend_Db::INT_TYPE);
        }

        return $select;
    }

    /**
     * Prepare stock status data in temporary index table
     *
     * @param int|array $entityIds the product limitation
     * @return $this
     */
    protected function _prepareIndexTable($entityIds = null)
    {
        $connection = $this->getConnection();
        $select = $this->_getStockStatusSelect($entityIds, true);
        $select = $this->getQueryProcessorComposite()->processQuery($select, $entityIds);
        $query = $select->insertFromSelect($this->getIdxTable());
        $connection->query($query);

        return $this;
    }

    /**
     * Update Stock status index by product ids
     *
     * @param array|int $entityIds
     * @return $this
     */
    protected function _updateIndex($entityIds)
    {
        $connection = $this->getConnection();
        $select = $this->_getStockStatusSelect($entityIds, true);
        $select = $this->getQueryProcessorComposite()->processQuery($select, $entityIds, true);
        $query = $connection->query($select);

        $i = 0;
        $data = [];
        $savedEntityIds = [];
        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            $i++;
            $data[] = [
                'product_id' => (int)$row['entity_id'],
                'website_id' => (int)$row['website_id'],
                'stock_id' => Stock::DEFAULT_STOCK_ID,
                'qty' => (double)$row['qty'],
                'stock_status' => (int)$row['status'],
            ];
            $savedEntityIds[] = (int)$row['entity_id'];
            if ($i % 1000 == 0) {
                $this->_updateIndexTable($data);
                $data = [];
            }
        }

        $this->_updateIndexTable($data);

        $this->deleteOldRecords(array_diff($entityIds, $savedEntityIds));
        return $this;
    }

    /**
     * Delete records by their ids from index table
     *
     * Used to clean table before re-indexation
     *
     * @param array $ids
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function deleteOldRecords(array $ids)
    {
        if (count($ids) !== 0) {
            $this->getConnection()->delete($this->getMainTable(), ['product_id in (?)' => $ids]);
        }
    }

    /**
     * Update stock status index table (INSERT ... ON DUPLICATE KEY UPDATE ...)
     *
     * @param array $data
     * @return $this
     */
    protected function _updateIndexTable($data)
    {
        if (empty($data)) {
            return $this;
        }

        $connection = $this->getConnection();
        $connection->insertOnDuplicate($this->getMainTable(), $data, ['qty', 'stock_status']);

        return $this;
    }

    /**
     * Retrieve temporary index table name
     *
     * @param string $table
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getIdxTable($table = null)
    {
        return $this->tableStrategy->getTableName('cataloginventory_stock_status');
    }

    /**
     * Get status expression
     *
     * @param AdapterInterface $connection
     * @param bool $isAggregate
     * @return mixed
     * @since 100.1.0
     */
    protected function getStatusExpression(AdapterInterface $connection, $isAggregate = false)
    {
        return $this->getStatusExpression->execute($this->getTypeId(), $connection, $isAggregate);
    }

    /**
     * Get stock configuration
     *
     * @return StockConfigurationInterface
     *
     * @deprecated 100.1.0
     * @since 100.1.0
     */
    protected function getStockConfiguration()
    {
        if ($this->stockConfiguration === null) {
            $this->stockConfiguration = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\CatalogInventory\Api\StockConfigurationInterface::class);
        }
        return $this->stockConfiguration;
    }

    /**
     * Get query processor composite
     *
     * @return QueryProcessorComposite
     */
    private function getQueryProcessorComposite()
    {
        if (null === $this->queryProcessorComposite) {
            $this->queryProcessorComposite = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\CatalogInventory\Model\ResourceModel\Indexer\Stock\QueryProcessorComposite::class);
        }
        return $this->queryProcessorComposite;
    }
}
