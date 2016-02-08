<?php
/**
 * @category Magento
 * @package Magento\Gateway
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Magento\Gateway;

use Entity\Service\EntityService;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Node\AbstractNode;
use Node\Entity;


class StockGateway extends AbstractGateway
{

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws MagelinkException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'stockitem') {
            throw new \Magelink\Exception\MagelinkException('Invalid entity type for this gateway');
            $success = FALSE;
        }

        return $success;
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     */
    public function retrieve()
    {
        if (!$this->_node->getConfig('load_stock')) {
            // No need to retrieve Stock from magento
            return;
        }

        $timestamp = $this->getNewRetrieveTimestamp();
//        $lastRetrieve = $this->getLastRetrieveDate();

        $products = $this->_entityService->locateEntity(
            $this->_node->getNodeId(),
            'product',
            0,
            array(),
            array(),
            array('static_field'=>'unique_id')
        );
        $products = array_unique($products);

        if (FALSE && $this->_db) {
            // TODO: Implement
        }elseif($this->_soap) {
            $results = $this->_soap->call('catalogInventoryStockItemList', array(
                    $products
                ));

            foreach($results as $item){
                $data = array();
                $unique_id = $item['sku'];
                $localId = $item['product_id'];

                $data = array('available'=>$item['qty']);

                foreach ($this->_node->getStoreViews() as $store_id=>$store_view) {
                    $product = $this->_entityService->loadEntity($this->_node->getNodeId(), 'product', 0, $item['sku']);

                    if (!$product) {
                        // No product exists, leave for now. May not be in this store.
                        continue;
                    }

                    $parent_id = $product->getId();

                    /** @var boolean $needsUpdate Whether we need to perform an entity update here */
                    $needsUpdate = TRUE;

                    $existingEntity = $this->_entityService
                        ->loadEntityLocal($this->_node->getNodeId(), 'stockitem', 0, $localId);
                    if (!$existingEntity) {
                        $existingEntity = $this->_entityService->loadEntity(
                            $this->_node->getNodeId(),
                            'stockitem',
                            $store_id,
                            $unique_id
                        );
                        if (!$existingEntity) {
                            $existingEntity = $this->_entityService->createEntity(
                                $this->_node->getNodeId(),
                                'stockitem',
                                $store_id,
                                $unique_id,
                                $data,
                                $parent_id
                            );
                            $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);

                            $this->getServiceLocator()->get('logService')
                                ->log(LogService::LEVEL_INFO,
                                    'mag_si_new',
                                    'New stockitem '.$unique_id,
                                    array('code'=>$unique_id),
                                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                                );
                            $needsUpdate = FALSE;
                        }else{
                            $this->getServiceLocator()->get('logService')
                                ->log(LogService::LEVEL_INFO,
                                    'mag_si_link',
                                    'Unlinked stockitem '.$unique_id,
                                    array('code'=>$unique_id),
                                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                                );
                            $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);
                        }
                    }else{
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_INFO,
                                'mag_si_upd',
                                'Updated stockitem '.$unique_id,
                                array('code'=>$unique_id),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                    }
                    if ($needsUpdate) {
                        $this->_entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, FALSE);
                    }
                }
            }
        }else{
            // Nothing worked
            throw new \Magelink\Exception\NodeException('No valid API available for sync');
        }
        $this->_nodeService->setTimestamp($this->_nodeEntity->getNodeId(), 'stockitem', 'retrieve', $timestamp);
    }

    /**
     * @param \Entity\Entity $entity
     * @param bool $log
     * @param bool $error
     * @return int|NULL
     */
    protected function getParentLocal(\Entity\Entity $entity, $log = FALSE, $error = FALSE)
    {
        $nodeId = $this->_node->getNodeId();

        if ($log) {
            $logLevel = ($error ? LogService::LEVEL_ERROR : LogService::LEVEL_WARN);
            $logMessage = 'Stock update for '.$entity->getUniqueId().' ('.$nodeId.') had to use parent local!';
            $this->getServiceLocator()->get('logService')
                ->log($logLevel, 'mag_si_par', $logMessage,
                    array('parent'=>$entity->getParentId()),
                    array('node'=>$this->_node, 'entity'=>$entity)
                );
        }

        $localId = $this->_entityService->getLocalId($this->_node->getNodeId(), $entity->getParentId());

        if (!$localId) {
            $parentEntity = $entity->getParent();
            if ($this->_db) {
                $localId = $this->_db->getLocalId('product', $parentEntity->getUniqueId());
            }

            if (!$localId && $this->_soap) {
                $productInfo = $this->_soap->call('catalogProductInfo', array($parentEntity->getUniqueId()));
                if (isset($productInfo['result'])) {
                    $productInfo = $productInfo['result'];
                }
                $localId = $productInfo['product_id'];
            }

            if ($localId) {
                $this->_entityService->linkEntity($this->_node->getNodeId(), $parentEntity, $localId);
                $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_INFO, 'mag_si_relink',
                    'Stock parent product '.$entity->getUniqueId().' re-linked on '.$nodeId.'!', array());
            }else {
                $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR, 'mag_si_nolink',
                    'Stock update for '.$entity->getUniqueId().' ('.$nodeId.'): Parent had no local id!',
                    array('data'=>$entity->getFullArrayCopy()), array('node'=>$this->_node));
            }
        }

        return $localId;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     * @throws MagelinkException
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type=\Entity\Update::TYPE_UPDATE)
    {
        $logCode = 'mag_si';
        $logData = array('data'=>$entity->getAllSetData());
        $logEntities = array('node' => $this->_node, 'entity' => $entity);

        if (in_array('available', $attributes)) {
            $isUnlinked = FALSE;
            $nodeId = $this->_node->getNodeId();
            $logEntities = array('node'=>$this->_node, 'entity' => $entity);

            $localId = $this->_entityService->getLocalId($nodeId, $entity);
            $qty = $entity->getData('available');
            $isInStock = (int) ($qty > 0);

            do {
                $success = FALSE;
                if ($localId) {
                    if ($this->_db) {
                        $success = $this->_db->updateStock($localId, $qty, $isInStock);
                    }elseif ($this->_soap) {
                        $success = $this->_soap->call('catalogInventoryStockItemUpdate',
                            array($localId, 'data'=>array('qty'=>$qty, 'is_in_stock'=>($isInStock))));
                    }
                }

                $quit = $success || $isUnlinked;
                $logData = array('node id'=>$nodeId, 'local id'=>$localId, 'data'=>$entity->getFullArrayCopy());

                if (!$success) {
                    if (!$isUnlinked) {
                        if ($localId) {
                            $this->_entityService->unlinkEntity($this->_node->getNodeId(), $entity);
                        }
                        $isUnlinked = TRUE;
                        $localId = $this->getParentLocal($entity, TRUE);

                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_WARN,
                                $logCode.'_unlink',
                                'Removed stockitem local id from '.$entity->getUniqueId().' ('.$nodeId.')',
                                $logData, $logEntities
                            );
                    }else{
                        $product = $this->_entityService
                            ->loadEntityId($this->_node->getNodeId(), $entity->getParentId());

                        if ($localId) {
                            $localId = NULL;
                            $this->_entityService->unlinkEntity($this->_node->getNodeId(), $product);

                            $logMessage = 'Stock update for '.$entity->getUniqueId().' failed!'
                                .' Product '.$product->getUniqueId().' had wrong local id '.$localId.' ('.$nodeId.').'
                                .' Both local ids (on stockitem and product) are now removed.';
                            $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR,
                                'mag_si_par_unlink', $logMessage, $logData, $logEntities);
                        }
                    }
                }
            }while (!$quit);

            if ($isUnlinked) {
                if ($success) {
                    $this->_entityService->linkEntity($this->_node->getNodeId(), $entity, $localId);
                    $logLevel = LogService::LEVEL_INFO;
                    $logCode .= '_link';
                    $logMessage = 'Linked stockitem '.$entity->getUniqueId().' on node '.$nodeId;
                }else{
                    $logLevel = LogService::LEVEL_WARN;
                    $logCode .= '_link_fail';
                    $logMessage = 'Stockitem '.$entity->getUniqueId().' could not be linked on node '.$nodeId;
                }
                $this->getServiceLocator()->get('logService')
                    ->log($logLevel, $logCode, $logMessage, $logData, $logEntities);
            }
        }else{
            // We don't care about any other attributes
            $success = TRUE;
        }

        return $success;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @throws MagelinkException
     */
    public function writeAction(\Entity\Action $action)
    {
        throw new MagelinkException('Unsupported action type ' . $action->getType() . ' for Magento Stock Items.');
    }

}