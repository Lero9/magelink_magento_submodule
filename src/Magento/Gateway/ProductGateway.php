<?php
/**
 * Magento\Gateway\OrderGateway
 *
 * @category Magento
 * @package Magento\Gateway
 * @author Matt Johnston
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license Commercial - All Rights Reserved
 */

namespace Magento\Gateway;

use Node\AbstractNode;
use Node\Entity;
use Magelink\Exception\MagelinkException;


class ProductGateway extends AbstractGateway
{

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param AbstractNode $node
     * @param Entity\Node $nodeEntity
     * @param string $entity_type
     * @throws \Magelink\Exception\MagelinkException
     * @return boolean
     */
    public function init(AbstractNode $node, Entity\Node $nodeEntity, $entityType)
    {
        if ($entityType != 'product') {
            $success = FALSE;
            throw new \Magelink\Exception\MagelinkException('Invalid entity type for this gateway');
        }else{
            $success = parent::init($node, $nodeEntity, $entityType);

            $attributeSets = $this->_soap->call('catalogProductAttributeSetList', array());
            $this->_attributeSets = array();
            foreach ($attributeSets as $attributeSetArray){
                $this->_attributeSets[$attributeSetArray['set_id']] = $attributeSetArray;
            }
        }

        return $success;
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     */
    public function retrieve()
    {
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');
        /** @var \Node\Service\NodeService $nodeService */
        $nodeService = $this->getServiceLocator()->get('nodeService');

        $timestamp = time() - $this->apiOverlappingSeconds;

        foreach ($this->_node->getStoreViews() as $store_id=>$store_view) {
            $lastRetrieve = date('Y-m-d H:i:s',
                $this->_nodeService->getTimestamp($this->_nodeEntity->getNodeId(), 'product', 'retrieve')
                    + (intval($this->_node->getConfig('time_delta_product')) * 3600)
            );

            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_INFO,
                    'retr_time',
                    'Retrieving products updated since '.$lastRetrieve,
                    array('type'=>'product', 'timestamp'=>$lastRetrieve)
                );

            if ($this->_db) {
                $updatedProducts = $this->_db->getChangedEntityIds('catalog_product', $lastRetrieve);

                if(!count($updatedProducts)){
                    continue;
                }

                if($store_id == 0){
                    $store_id = false;
                }

                $atts = array(
                    'sku',
                    'name',
                    'attribute_set_id',
                    'type_id',
                    'description',
                    'short_description',
                    'status',
                    'visibility',
                    'price',
                    'tax_class_id',
                    'special_price',
                    'special_from_date',
                    'special_to_date',
                );

                $additional = $this->_node->getConfig('product_attributes');
                if(is_string($additional)){
                    $additional = explode(',', $additional);
                }
                if(!$additional || !is_array($additional)){
                    $additional = array();
                }
                foreach($additional as $i=>$k){
                    if(!strlen(trim($k))){
                        unset($additional[$i]);
                        continue;
                    }
                    if(!$entityConfigService->checkAttribute('product', $k)){
                        $entityConfigService->createAttribute(
                            $k, $k, false, 'varchar', 'product', 'Magento Additional Attribute');
                        $nodeService->subscribeAttribute($this->_node->getNodeId(), $k, 'product', TRUE);
                    }
                }

                $atts = array_merge($atts, $additional);

                $brands = false;
                if(in_array('brand', $atts)){
                    try{
                        $brands = $this->_db->loadEntitiesEav('brand', null, $store_id, array('name'));
                    }catch(\Exception $e){
                        $brands = false;
                    }
                }

                $prodData = $this->_db->loadEntitiesEav('catalog_product', $updatedProducts, $store_id, $atts);
                foreach($prodData as $product_id=>$rawData){
                    $data = $this->convertFromMagento($rawData, $additional);

                    if($brands && isset($data['brand']) && is_numeric($data['brand'])){
                        if(isset($brands[intval($data['brand'])])){
                            $data['brand'] = $brands[intval($data['brand'])]['name'];
                        }else{
                            $data['brand'] = null;
                        }
                    }

                    if(isset($this->_attributeSets[intval($rawData['attribute_set_id'])])){
                        $data['product_class'] = $this->_attributeSets[intval($rawData['attribute_set_id'])]['name'];
                    }else{
                        $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_WARN,
                            'unknown_set',
                            'Unknown attribute set ID '.$rawData['attribute_set_id'],
                            array('set'=>$rawData['attribute_set_id'], 'sku'=>$rawData['sku'])
                        );
                    }
                    $parent_id = null; // TODO: Calculate
                    $sku = $rawData['sku'];

                    $this->processUpdate($entityService, $product_id, $sku, $store_id, $parent_id, $data);
                }

            }elseif($this->_soap){
                $results = $this->_soap->call('catalogProductList', array(
                    array(
                        'complex_filter'=>array(
                            array(
                                'key'=>'updated_at',
                                'value'=>array('key'=>'gt', 'value'=>$lastRetrieve),
                            ),
                        ),
                    ), // filters
                    $store_id, // storeView
                ));

                foreach($results as $prod){
                    $data = $prod;
                    if($this->_node->getConfig('load_full_product')){
                        $data = array_merge($data, $this->loadFullProduct($prod['product_id'], $store_id, $entityConfigService));
                    }

                    if(isset($this->_attributeSets[intval($data['set'])])){
                        $data['product_class'] = $this->_attributeSets[intval($data['set'])]['name'];
                        unset($data['set']);
                    }else{
                        $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_WARN, 'unknown_set', 'Unknown attribute set ID '.$data['set'], array('set'=>$data['set'], 'sku'=>$data['sku']));
                    }

                    if(isset($data[''])){
                        unset($data['']);
                    }

                    unset($data['category_ids']); // TODO parse into categories
                    unset($data['website_ids']); // Not used

                    $product_id = $data['product_id'];
                    $sku = $data['sku'];
                    unset($data['product_id']);
                    unset($data['sku']);

                    $parent_id = null; // TODO: Calculate

                    $this->processUpdate($entityService, $product_id, $sku, $store_id, $parent_id, $data);
                }
            }else{
                // Nothing worked
                throw new \Magelink\Exception\NodeException('No valid API available for sync');
            }
        }
        $this->_nodeService->setTimestamp($this->_nodeEntity->getNodeId(), 'product', 'retrieve', $timestamp);
    }

    /**
     * @param \Entity\Service\EntityService $entityService
     * @param int $product_id
     * @param string $sku
     * @param int $store_id
     * @param int $parent_id
     * @param array $data
     * @return \Entity\Entity|null
     */
    protected function processUpdate(\Entity\Service\EntityService $entityService, $product_id, $sku, $store_id,
        $parent_id, $data)
    {
        /** @var boolean $needsUpdate Whether we need to perform an entity update here */
        $needsUpdate = true;

        $existingEntity = $entityService->loadEntityLocal($this->_node->getNodeId(), 'product', $store_id, $product_id);
        if (!$existingEntity) {
            $existingEntity = $entityService->loadEntity($this->_node->getNodeId(), 'product', $store_id, $sku);
            if (!$existingEntity) {
                $existingEntity = $entityService->createEntity(
                    $this->_node->getNodeId(),
                    'product',
                    $store_id,
                    $sku,
                    $data,
                    $parent_id
                );
                $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $product_id);
                $this->getServiceLocator()->get('logService')
                    ->log(\Log\Service\LogService::LEVEL_INFO,
                        'ent_new',
                        'New product '.$sku,
                        array('sku'=>$sku),
                        array('node'=>$this->_node, 'entity'=>$existingEntity)
                    );
                try{
                    $stockEnt = $entityService->createEntity(
                        $this->_node->getNodeId(),
                        'stockitem',
                        $store_id,
                        $sku,
                        array(),
                        $existingEntity
                    );
                    $entityService->linkEntity($this->_node->getNodeId(), $stockEnt, $product_id);
                }catch(\Exception $e){
                    $this->getServiceLocator()->get('logService')
                        ->log(\Log\Service\LogService::LEVEL_WARN,
                            'already_stockitem',
                            'Already existing stockitem for new product '.$sku,
                            array('sku'=>$sku),
                            array('node'=>$this->_node, 'entity'=>$existingEntity)
                        );
                }
                $needsUpdate = false;
            }else{
                $this->getServiceLocator()->get('logService')
                    ->log(\Log\Service\LogService::LEVEL_INFO,
                        'ent_link',
                        'Unlinked product '.$sku,
                        array('sku'=>$sku),
                        array('node'=>$this->_node, 'entity'=>$existingEntity)
                    );
                $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $product_id);
            }
        }else{
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_INFO,
                    'ent_update',
                    'Updated product '.$sku,
                    array('sku'=>$sku),
                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                );
        }
        if($needsUpdate){
            $entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, false);
        }
        return $existingEntity;
    }

    /**
     * Load detailed product data from Magento
     * @param $product_id
     * @param $store_id
     * @param \Entity\Service\EntityConfigService $entityConfigService
     * @return array
     * @throws \Magelink\Exception\MagelinkException
     */
    public function loadFullProduct($product_id, $store_id, \Entity\Service\EntityConfigService $entityConfigService){

        $additional = $this->_node->getConfig('product_attributes');
        if(is_string($additional)){
            $additional = explode(',', $additional);
        }
        if(!$additional || !is_array($additional)){
            $additional = array();
        }

        $req = array(
            $product_id,
            $store_id,
            array(
                'additional_attributes'=>$additional,
            ),
            'id',
        );

        $res = $this->_soap->call('catalogProductInfo', $req);

        if(!$res && !$res['sku']){
            throw new MagelinkException('Invalid product info response');
        }

        $data = $this->convertFromMagento($res, $additional);


        foreach($additional as $att){
            $att = trim($att);
            $att = strtolower($att);
            if(!strlen($att)){
                continue;
            }
            if(!array_key_exists($att, $data)){
                $data[$att] = null;
            }

            if(!$entityConfigService->checkAttribute('product', $att)){
                $entityConfigService->createAttribute($att, $att, 0, 'varchar', 'product', 'Custom Magento attribute');
                $this->getServiceLocator()->get('nodeService')->subscribeAttribute($this->_node->getNodeId(), $att, 'product');
            }
        }

        return $data;

    }

    /**
     * Converts Magento-named attributes into our internal Magelink attributes / formats
     * @param array $res Input array of Magento attribute codes
     * @param array $additional Additional product attributes to load in
     * @return array
     */
    protected function convertFromMagento($res, $additional){
        $data = array();
        if(isset($res['type_id'])){
            $data['type'] = $res['type_id'];
        }else{
            if(isset($res['type'])){
                $data['type'] = $res['type'];
            }else{
                $data['type'] = null;
            }
        }
        if(isset($res['name'])){
            $data['name'] = $res['name'];
        }else{
            $data['name'] = null;
        }
        if(isset($res['description'])){
            $data['description'] = $res['description'];
        }else{
            $data['description'] = null;
        }
        if(isset($res['short_description'])){
            $data['short_description'] = $res['short_description'];
        }else{
            $data['short_description'] = null;
        }
        if(isset($res['status'])){
            $data['enabled'] = ($res['status'] == 1) ? 1 : 0;
        }else{
            $data['enabled'] = 0;
        }
        if(isset($res['visibility'])){
            $data['visible'] = ($res['visibility'] == 4) ? 1 : 0;
        }else{
            $data['visible'] = 0;
        }
        if(isset($res['price'])){
            $data['price'] = $res['price'];
        }else{
            $data['price'] = null;
        }
        if(isset($res['tax_class_id'])){
            $data['taxable'] = ($res['tax_class_id'] == 2) ? 1 : 0;
        }else{
            $data['taxable'] = 0;
        }
        if(isset($res['special_price'])){
            $data['special_price'] = $res['special_price'];

            if(isset($res['special_from_date'])){
                $data['special_from_date'] = $res['special_from_date'];
            }else{
                $data['special_from_date'] = null;
            }
            if(isset($res['special_to_date'])){
                $data['special_to_date'] = $res['special_to_date'];
            }else{
                $data['special_to_date'] = null;
            }
        }else{
            $data['special_price'] = null;
            $data['special_from_date'] = null;
            $data['special_to_date'] = null;
        }

        if(isset($res['additional_attributes'])){
            foreach($res['additional_attributes'] as $pair){
                $att = trim(strtolower($pair['key']));
                if(!in_array($att, $additional)){
                    throw new MagelinkException('Invalid attribute returned by Magento: '.$att);
                }
                if(isset($pair['value'])){
                    $data[$att] = $pair['value'];
                }else{
                    $data[$att] = null;
                }
            }
        }else{
            foreach($additional as $k){
                if(isset($res[$k])){
                    $data[$k] = $res[$k];
                }
            }
        }

        return $data;
    }

    /**
     * Restructure data for soap call and return this array
     * @param array $data
     * @param array $customAttributes
     * @return array $soapData
     * @throws \Magelink\Exception\MagelinkException
     */
    protected function getUpdateDataForSoapCall(array $data, array $customAttributes)
    {
        // Restructure data for soap call
        $soapData = array(
            'additional_attributes'=>array(
                'single_data'=>array(),
                'multi_data'=>array()
            )
        );
        $removeSingleData = $removeMultiData = TRUE;

        foreach ($data as $code=>$value) {
            $isCustomAttribute = in_array($code, $customAttributes);
            if ($isCustomAttribute) {
                if (is_array($data[$code])) {
                    // TODO (maybe): Implement
                    throw new MagelinkException("This gateway doesn't support multi_data custom attributes yet.");
                    $removeMultiData = FALSE;
                }else{
                    $soapData['additional_attributes']['single_data'][] = array(
                        'key'=>$code,
                        'value'=>$value,
                    );
                    $removeSingleData = FALSE;
                }
            }else{
                $soapData[$code] = $value;
            }
        }

        if ($removeSingleData) {
            unset($data['additional_attributes']['single_data']);
        }
        if ($removeMultiData) {
            unset($data['additional_attributes']['multi_data']);
        }
        if ($removeSingleData && $removeMultiData) {
            unset($data['additional_attributes']);
        }

        return $soapData;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');

        $customAttributes = $this->_node->getConfig('product_attributes');
        if (is_string($customAttributes)) {
            $customAttributes = explode(',', $customAttributes);
        }
        if (!$customAttributes || !is_array($customAttributes)) {
            $customAttributes = array();
        }

        $this->getServiceLocator()->get('logService')
            ->log(\Log\Service\LogService::LEVEL_DEBUGEXTRA,
                'mag_prod_write_update',
                'Attributes for update of product '.$entity->getUniqueId().': '.var_export($attributes, TRUE),
                array('attributes'=>$attributes, 'custom'=>$customAttributes),
                array('entity'=>$entity)
            );

        $data = array();
        $attributeCodes = array_unique(array_merge(
            //array('special_price', 'special_from_date', 'special_to_date'), // force update off these attributes
            //$customAttributes,
            $attributes
        ));

        foreach ($attributeCodes as $attributeCode) {

            if (strlen(trim($attributeCode)) == 0) {
                continue;
            }

            $value = $entity->getData($attributeCode);
            switch ($attributeCode) {
                // Normal attributes
                case 'price':
                case 'special_price':
                case 'special_from_date':
                case 'special_to_date':
                    $value = ($value ? $value : NULL);
                case 'name':
                case 'description':
                case 'short_description':
                case 'weight':
                // Custom attributes
                case 'barcode':
                case 'bin_location':
                case 'msrp':
                    // Same name in both systems
                    $data[$attributeCode] = $value;
                    break;

                case 'enabled':
                    $data['status'] = ($value == 1 ? 2 : 1);
                    break;
                case 'visible':
                    $data['visibility'] = ($value == 1 ? 4 : 1);
                    break;
                case 'taxable':
                    $data['tax_class_id'] = ($value == 1 ? 2 : 1);
                    break;

                // ToDo (maybe): Add logic for this custom attributes
                case 'brand':
                case 'size':
                    // Ignore attributes
                    break;

                case 'product_class':
                case 'type':
                    if($type != \Entity\Update::TYPE_CREATE){
                        // ToDo: Log error (but no exception)
                    }else{
                        // Ignore attributes
                    }
                    break;
                default:
                    $this->getServiceLocator()->get('logService')
                        ->log(\Log\Service\LogService::LEVEL_WARN,
                            'mag_prod_inv_data',
                            'Unsupported attribute for update of '.$entity->getUniqueId().': '.$attributeCode,
                            array('attribute'=>$attributeCode),
                            array('entity'=>$entity)
                        );
                    // Warn unsupported attribute
                    break;
            }
        }

        if (!count($data)) {
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_WARN,
                    'mag_prod_noupd',
                    'No update required for '.$entity->getUniqueId().' but requested was '.implode(', ', $attributes),
                    array('attributes'=>$attributes),
                    array('entity'=>$entity)
                );
        }

        $localId = $entityService->getLocalId($this->_node->getNodeId(), $entity);

        $data['website_ids'] = array($entity->getStoreId());
        if (count($this->_node->getStoreViews()) && $type != \Entity\Update::TYPE_DELETE) {

            foreach($this->_node->getStoreViews() as $store_id=>$store_name){
                if($store_id == $entity->getStoreId()){
                    continue;
                }

                $loadedEntity = $entityService->loadEntity(
                    $this->_node->getNodeId(), $entity->getType(), $store_id, $entity->getUniqueId());
                if ($loadedEntity) {
                    if (!in_array($loadedEntity->getStoreId(), $data['website_ids'])) {
                        $data['website_ids'][] = $loadedEntity->getStoreId();
                    }

                    if($type == \Entity\Update::TYPE_CREATE && !$localId){
                        $message = 'Product exists in other store, ';
                        $localId = $entityService->getLocalId($this->_node->getNodeId(), $loadedEntity);
                        if ($localId) {
                            $this->getServiceLocator()->get('logService')
                                ->log(\Log\Service\LogService::LEVEL_INFO,
                                    'prod_storedup',
                                    $message.'forcing local ID for '.$entity->getUniqueId().' to '.$localId,
                                    array('local_id'=>$localId, 'loadedEntityId'=>$loadedEntity->getId()),
                                    array('entity'=>$entity)
                                );
                            $entityService->linkEntity($this->_node->getNodeId(), $entity, $localId);
                            $type = \Entity\Update::TYPE_UPDATE;
                            break;
                        }else{
                            $this->getServiceLocator()->get('logService')
                                ->log(\Log\Service\LogService::LEVEL_INFO,
                                    'prod_storenew',
                                    'Product exists in other store, but no local ID: '.$entity->getUniqueId(),
                                    array('loadedEntityId'=>$loadedEntity->getId()),
                                    array('entity'=>$entity)
                                );
                        }
                    }
                }
            }
        }

        $soapData = $this->getUpdateDataForSoapCall($data, $customAttributes);
        if ($type == \Entity\Update::TYPE_UPDATE || $localId) {

            $logData = array(
                'type'=>$entity->getData('type'),
                'websites'=>$data['website_ids'],
                'data keys'=>array_keys($data),
                'data'=>$data
            );

            $updateViaDbApi =  $this->_db && $localId;
            if ($updateViaDbApi) {
                $message = 'DB';
            }else{
                $message = 'SOAP';
                $logData['soap data keys'] = array_keys($soapData);
                $logData['soap data'] = $soapData;

            }

            $message = 'Updating product ('.$message.'): '
                .$entity->getUniqueId().' with '.implode(', ', array_keys($data));
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_INFO, 'mag_prod_update', $message, $logData);

            if ($updateViaDbApi) {
                $tablePrefix = 'catalog_product';
                $this->_db->updateEntityEav(
                    $tablePrefix,
                    $localId,
                    $entity->getStoreId(),
                    $data
                );
            }else{
                $request = array(
                    $entity->getUniqueId(),
                    $soapData,
                    $entity->getStoreId(),
                    'sku'
                );
                $this->_soap->call('catalogProductUpdate', $request);
            }
        }elseif ($type == \Entity\Update::TYPE_CREATE ){

            $attributeSet = NULL;
            foreach ($this->_attributeSets as $setId=>$set) {
                if ($set['name'] == $entity->getData('product_class', 'default')) {
                    $attributeSet = $setId;
                    break;
                }
            }
            if ($attributeSet === NULL) {
                $message = 'Invalid product class '.$entity->getData('product_class', 'default');
                throw new \Magelink\Exception\SyncException($message);
            }

            $message = 'Creating product (SOAP): '.$entity->getUniqueId().' with '.implode(', ', array_keys($data));
            $this->getServiceLocator()->get('logService')
                ->log(\Log\Service\LogService::LEVEL_INFO,
                    'mag_prod_create',
                    $message,
                    array(
                        'type'=>$entity->getData('type'),
                        'websites'=>$data['website_ids'],
                        'set'=>$attributeSet,
                        'data keys'=>array_keys($data),
                        'data'=>$data,
                        'soap data keys'=>array_keys($soapData),
                        'soap data'=>$soapData
                    )
                );

            $request = array(
                $entity->getData('type'),
                $attributeSet,
                $entity->getUniqueId(),
                $soapData,
                $entity->getStoreId()
            );

            try{
                $soapResult = $this->_soap->call('catalogProductCreate', $request);
                $soapFault = NULL;
            }catch(\SoapFault $soapFault){
                $soapResult = FALSE;
                if ($soapFault->getMessage() == 'The value of attribute "SKU" must be unique') {
                    $this->getServiceLocator()->get('logService')
                        ->log(\Log\Service\LogService::LEVEL_WARN,
                            'prod_dupfault',
                            'Creating product '.$entity->getUniqueId().' hit SKU duplicate fault',
                            array(),
                            array('entity'=>$entity)
                        );

                    $check = $this->_soap->call('catalogProductInfo', array(
                        $entity->getUniqueId(),
                        0, // store ID
                        array(),
                        'sku'
                    ));

                    if (!$check || !count($check)) {
                        throw new MagelinkException('Magento complained duplicate SKU but we cannot find a duplicate!');

                    }else{
                        $found = FALSE;
                        foreach ($check as $row) {
                            if($row['sku'] == $entity->getUniqueId()){
                                $found = TRUE;

                                $entityService->linkEntity($this->_node->getNodeId(), $entity, $row['product_id']);
                                $this->getServiceLocator()->get('logService')
                                    ->log(\Log\Service\LogService::LEVEL_WARN,
                                        'prod_dupres',
                                        'Creating product '.$entity->getUniqueId().' resolved SKU duplicate fault',
                                        array('local_id'=>$row['product_id']),
                                        array('entity'=>$entity)
                                    );
                            }
                        }

                        if (!$found) {
                            $message = 'Magento found duplicate SKU '.$entity->getUniqueId()
                                .' but we could not replicate. Database fault?';
                            throw new MagelinkException($message);
                        }
                    }
                }
            }

            if (!$soapResult) {
                $message = 'Error creating product in Magento ('.$entity->getUniqueId().'!';
                throw new MagelinkException($message, 0, $soapFault);
            }

            $entityService->linkEntity($this->_node->getNodeId(), $entity, $soapResult);
        }
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @throws MagelinkException
     */
    public function writeAction(\Entity\Action $action)
    {
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        $entity = $action->getEntity();

        switch($action->getType()){
            case 'delete':
                $this->_soap->call('catalogProductDelete', array($entity->getUniqueId(), 'sku'));
                return true;
                break;
            default:
                throw new MagelinkException('Unsupported action type '.$action->getType().' for Magento Orders.');
        }
    }
    
}