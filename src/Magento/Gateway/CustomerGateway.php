<?php

namespace Magento\Gateway;

use Node\AbstractNode;
use Node\Entity;
use Magelink\Exception\MagelinkException;
use Entity\Service\EntityService;

class CustomerGateway extends AbstractGateway
{

    /**
     * Initialize the gateway and perform any setup actions required.
     * @param AbstractNode $node
     * @param Entity\Node $nodeEntity
     * @param string $entityType
     * @throws \Magelink\Exception\MagelinkException
     * @return boolean
     */
    public function init(AbstractNode $node, Entity\Node $nodeEntity, $entityType)
    {
        if ($entityType != 'customer') {
            $success = FALSE;
            throw new \Magelink\Exception\MagelinkException('Invalid entity type for this gateway');
        }else{
            $success = parent::init($node, $nodeEntity, $entityType);

            if ($this->_node->getConfig('customer_attributes')
                && strlen($this->_node->getConfig('customer_attributes'))) {

                $this->_soapv1 = $node->getApi('soapv1');
                if (!$this->_soapv1) {
                    $success = FALSE;
                    throw new MagelinkException('SOAP v1 is required for extended customer attributes');
                }
            }

            $groups = $this->_soap->call('customerGroupList', array());
            $this->_custGroups = array();
            foreach ($groups as $groupArray) {
                $this->_custGroups[$groupArray['customer_group_id']] = $groupArray;
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

        $timestamp = time() - $this->apiOverlappingSeconds;

        $lastRetrieve = date('Y-m-d H:i:s',
            $this->_nodeService->getTimestamp($this->_nodeEntity->getNodeId(), 'customer', 'retrieve')
                + (intval($this->_node->getConfig('time_delta_customer')) * 3600));

        $this->getServiceLocator()->get('logService')
            ->log(\Log\Service\LogService::LEVEL_INFO,
                'retr_time',
                'Retrieving customers updated since '.$lastRetrieve,
                array('type'=>'customer', 'timestamp'=>$lastRetrieve)
            );

        if ($this->_soap) {
            $results = $this->_soap->call('customerCustomerList', array(
                array(
                    'complex_filter'=>array(
                        array(
                            'key'=>'updated_at',
                            'value'=>array('key'=>'gt', 'value'=>$lastRetrieve),
                        ),
                    ),
                ), // filters
            ));

            if (!is_array($results)) {
                $this->getServiceLocator()->get('logService')->log(\Log\Service\LogService::LEVEL_ERROR,
                    'mag_soap_customer',
                    'SOAP (customerCustomerList) did not return an array but '.gettype($results).' instead.',
                    array('type'=>gettype($results), 'class'=>(is_object($results) ? get_class($results) : 'no object')),
                    array('soap result'=>$results)
                );
            }

            /**$specialAtt = $this->_node->getConfig('customer_special_attributes');
            if(!strlen(trim($specialAtt))){
                $specialAtt = false;
            }else{
                $specialAtt = trim(strtolower($specialAtt));
                if(!$entityConfigService->checkAttribute('customer', $specialAtt)){
                    $entityConfigService->createAttribute($specialAtt, $specialAtt, 0, 'varchar', 'customer', 'Custom Magento attribute (special - taxvat)');
                    $this->getServiceLocator()->get('nodeService')->subscribeAttribute($this->_node->getNodeId(), $specialAtt, 'customer');
                }
            }**/

            $additional = $this->_node->getConfig('customer_attributes');
            if(is_string($additional)){
                $additional = explode(',', $additional);
            }
            if(!$additional || !is_array($additional)){
                $additional = array();
            }
            foreach($additional as $k=>&$att){
                $att = trim(strtolower($att));
                if(!strlen($att)){
                    unset($additional[$k]);
                    continue;
                }
                if(!$entityConfigService->checkAttribute('customer', $att)){
                    $entityConfigService->createAttribute($att, $att, 0, 'varchar', 'customer', 'Custom Magento attribute');
                    $this->getServiceLocator()->get('nodeService')->subscribeAttribute($this->_node->getNodeId(), $att, 'customer');
                }

            }

            foreach($results as $cust){
                $data = array();

                $uniqueId = $cust['email'];
                $local_id = $cust['customer_id'];
                $storeId = ($this->_node->isMultiStore() ? $cust['store_id'] : 0);
                $parentId = NULL;

                $data['first_name'] = (isset($cust['firstname']) ? $cust['firstname'] : NULL);
                $data['middle_name'] = (isset($cust['middlename']) ? $cust['middlename'] : NULL);
                $data['last_name'] = (isset($cust['lastname']) ? $cust['lastname'] : NULL);
                $data['date_of_birth'] = (isset($cust['dob']) ? $cust['dob'] : NULL);

                /**if($specialAtt){
                    $data[$specialAtt] = (isset($cust['taxvat']) ? $cust['taxvat'] : NULL);
                }**/
                if(count($additional) && $this->_soapv1){
                    $extra = $this->_soapv1->call('customer.info', array($cust['customer_id'], $additional));
                    foreach($additional as $att){
                        if(array_key_exists($att, $extra)){
                            $data[$att] = $extra[$att];
                        }else{
                            $data[$att] = NULL;
                        }
                    }
                }

                if(isset($this->_custGroups[intval($cust['group_id'])])){
                    $data['customer_type'] = $this->_custGroups[intval($cust['group_id'])]['customer_group_code'];
                }else{
                    $this->getServiceLocator()->get('logService')
                        ->log(\Log\Service\LogService::LEVEL_WARN, 
                            'unknown_group', 
                            'Unknown customer group ID '.$cust['group_id'], 
                            array('group'=>$cust['group_id'], 'unique'=>$cust['email'])
                        );
                }

                if($this->_node->getConfig('load_full_customer')){
                    $data = array_merge($data, $this->createAddresses($cust, $entityService));

                    if($this->_db){
                        $data['enable_newsletter'] = $this->_db->getNewsletterStatus($local_id);
                    }
                }

                /** @var boolean $needsUpdate Whether we need to perform an entity update here */
                $needsUpdate = true;

                $existingEntity = $entityService
                    ->loadEntityLocal($this->_node->getNodeId(), 'customer', $storeId, $local_id);
                if (!$existingEntity) {
                    $existingEntity = $entityService
                        ->loadEntity($this->_node->getNodeId(), 'customer', $storeId, $uniqueId);
                    if (!$existingEntity) {
                        $existingEntity = $entityService->createEntity(
                            $this->_node->getNodeId(), 
                            'customer', 
                            $storeId, 
                            $uniqueId, 
                            $data, 
                            $parentId
                        );
                        $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $local_id);
                        $this->getServiceLocator()->get('logService')
                            ->log(\Log\Service\LogService::LEVEL_INFO,
                                'ent_new',
                                'New customer '.$uniqueId,
                                array('code'=>$uniqueId),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                        $needsUpdate = false;
                    }elseif ($entityService->getLocalId($this->_node->getNodeId(), $existingEntity) != NULL) {
                        $this->getServiceLocator()->get('logService')
                            ->log(\Log\Service\LogService::LEVEL_INFO,
                                'ent_wronglink',
                                'Incorrectly linked customer '.$uniqueId,
                                array('code'=>$uniqueId),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                        $entityService->unlinkEntity($this->_node->getNodeId(), $existingEntity);
                        $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $local_id);
                    }else{
                        $this->getServiceLocator()->get('logService')
                            ->log(\Log\Service\LogService::LEVEL_INFO,
                                'ent_link',
                                'Unlinked customer '.$uniqueId,
                                array('code'=>$uniqueId),
                                array('node'=>$this->_node, 'entity'=>$existingEntity)
                            );
                        $entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $local_id);
                    }
                }else{
                    $this->getServiceLocator()->get('logService')
                        ->log(\Log\Service\LogService::LEVEL_INFO,
                            'ent_update',
                            'Updated customer '.$uniqueId,
                            array('code'=>$uniqueId),
                            array('node'=>$this->_node, 'entity'=>$existingEntity)
                        );
                }
                if ($needsUpdate) {
                    $entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, false);
                }
            }
        }else{
            // Nothing worked
            throw new \Magelink\Exception\NodeException('No valid API available for sync');
        }
        $this->_nodeService->setTimestamp($this->_nodeEntity->getNodeId(), 'customer', 'retrieve', $timestamp);
    }

    /**
     * Create the Address entities for a given customer and pass them back as the appropriate attributes
     *
     * @param array $cust
     * @param EntityService $entityService
     * @return array
     */
    protected function createAddresses($cust, EntityService $entityService)
    {
        $data = array();

        $addressRes = $this->_soap->call('customerAddressList', array($cust['customer_id']));
        foreach($addressRes as $a){
            if($a['is_default_billing']){
                $data['billing_address'] = $this->createAddressEntity($a, $cust, 'billing', $entityService);
            }
            if($a['is_default_shipping']){
                $data['shipping_address'] = $this->createAddressEntity($a, $cust, 'shipping', $entityService);
            }
            if(!$a['is_default_billing'] && !$a['is_default_shipping']){
                // TODO: Store this maybe? For now ignore
            }
        }
        return $data;
    }

    /**
     * Create an individual Address entity for a customer
     *
     * @param array $addressData
     * @param array $cust
     * @param string $type "billing" or "shipping"
     * @param EntityService $entityService
     * @return \Entity\Entity
     */
    protected function createAddressEntity($addressData, $cust, $type, EntityService $entityService)
    {
        $uniqueId = 'cust-'.$cust['customer_id'].'-'.$type;

        $entity = $entityService->loadEntity(
            $this->_node->getNodeId(), 
            'address', 
            ($this->_node->isMultiStore() ? $cust['store_id'] : 0), 
            $uniqueId
        );

        $data = array(
            'first_name'=>(isset($addressData['firstname']) ? $addressData['firstname'] : NULL),
            'middle_name'=>(isset($addressData['middlename']) ? $addressData['middlename'] : NULL),
            'last_name'=>(isset($addressData['lastname']) ? $addressData['lastname'] : NULL),
            'prefix'=>(isset($addressData['prefix']) ? $addressData['prefix'] : NULL),
            'suffix'=>(isset($addressData['suffix']) ? $addressData['suffix'] : NULL),
            'street'=>(isset($addressData['street']) ? $addressData['street'] : NULL),
            'city'=>(isset($addressData['city']) ? $addressData['city'] : NULL),
            'region'=>(isset($addressData['region']) ? $addressData['region'] : NULL),
            'postcode'=>(isset($addressData['postcode']) ? $addressData['postcode'] : NULL),
            'country_code'=>(isset($addressData['country_id']) ? $addressData['country_id'] : NULL),
            'telephone'=>(isset($addressData['telephone']) ? $addressData['telephone'] : NULL),
            'company'=>(isset($addressData['company']) ? $addressData['company'] : NULL)
        );

        if (!$entity) {
            $entity = $entityService->createEntity(
                $this->_node->getNodeId(), 
                'address', 
                ($this->_node->isMultiStore() ? $cust['store_id'] : 0), 
                $uniqueId, $data
            );
            $entityService->linkEntity($this->_node->getNodeId(), $entity, $addressData['customer_address_id']);
        }else{
            $entityService->updateEntity($this->_node->getNodeId(), $entity, $data, false);
        }
        return $entity;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param \Entity\Attribute[] $attributes
     * @param int $type
     * @throws MagelinkException
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type=\Entity\Update::TYPE_UPDATE)
    {
        /** @var \Entity\Service\EntityService $entityService */
        /*$entityService = $this->getServiceLocator()->get('entityService');

        $additional = $this->_node->getConfig('customer_attributes');
        if(is_string($additional)){
            $additional = explode(',', $additional);
        }
        if(!$additional || !is_array($additional)){
            $additional = array();
        }

        $data = array(
            'additional_attributes'=>array(
                'single_data'=>array(),
                'multi_data'=>array(),
            ),
        );

        foreach($attributes as $att){
            $v = $entity->getData($att);
            if(in_array($att, $additional)){
                // Custom attribute
                if(is_array($v)){
                    // TODO implement
                    throw new MagelinkException('This gateway does not yet support multi_data additional attributes');
                }else{
                    $data['additional_attributes']['single_data'][] = array(
                        'key'=>$att,
                        'value'=>$v,
                    );
                }
                continue;
            }
            // Normal attribute
            switch($att){
                case 'name':
                case 'description':
                case 'short_description':
                case 'price':
                case 'special_price':
                    // Same name in both systems
                    $data[$att] = $v;
                    break;
                case 'special_from':
                    $data['special_from_date'] = $v;
                    break;
                case 'special_to':
                    $data['special_to_date'] = $v;
                    break;
                case 'customer_class':
                    if($type != \Entity\Update::TYPE_CREATE){
                        // TODO log error (but no exception)
                    }
                    break;
                case 'type':
                    if($type != \Entity\Update::TYPE_CREATE){
                        // TODO log error (but no exception)
                    }
                    break;
                case 'enabled':
                    $data['status'] = ($v == 1 ? 2 : 1);
                    break;
                case 'visible':
                    $data['status'] = ($v == 1 ? 4 : 1);
                    break;
                case 'taxable':
                    $data['status'] = ($v == 1 ? 2 : 1);
                    break;
                default:
                    // Warn unsupported attribute
                    break;
            }
        }

        if($type == \Entity\Update::TYPE_UPDATE){
            $req = array(
                $entity->getUniqueId(),
                $data,
                $entity->getStoreId(),
                'sku'
            );
            $this->_soap->call('catalogCustomerUpdate', $req);
        }else if($type == \Entity\Update::TYPE_CREATE){

            $attSet = NULL;
            foreach($this->_attSets as $setId=>$set){
                if($set['name'] == $entity->getData('customer_class', 'default')){
                    $attSet = $setId;
                    break;
                }
            }
            $req = array(
                $entity->getData('type'),
                $attSet,
                $entity->getUniqueId(),
                $data,
                $entity->getStoreId()
            );
            $res = $this->_soap->call('catalogCustomerCreate', $req);
            if(!$res){
                throw new MagelinkException('Error creating customer in Magento (' . $entity->getUniqueId() . '!');
            }
            $entityService->linkEntity($this->_node->getNodeId(), $entity, $res);
        }*/

        // TODO: Implement writeUpdates() method.
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @throws MagelinkException
     */
    public function writeAction(\Entity\Action $action)
    {
        return false;
        /** @var \Entity\Service\EntityService $entityService */
        $entityService = $this->getServiceLocator()->get('entityService');
        /** @var \Entity\Service\EntityConfigService $entityConfigService */
        $entityConfigService = $this->getServiceLocator()->get('entityConfigService');

        /*$entity = $action->getEntity();

        switch($action->getType()){
            case 'delete':
                $this->_soap->call('catalogCustomerDelete', array($entity->getUniqueId(), 'sku'));
                break;
            default:
                throw new MagelinkException('Unsupported action type ' . $action->getType() . ' for Magento Orders.');
        }*/
    }
}