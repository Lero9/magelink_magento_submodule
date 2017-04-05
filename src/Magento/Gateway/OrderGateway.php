<?php
/**
 * Magento\Gateway\OrderGateway
 * @category Magento
 * @package Magento\Gateway
 * @author Matt Johnston
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause - Please view LICENSE.md for more information
 */

namespace Magento\Gateway;

use Entity\Comment;
use Entity\Service\EntityService;
use Entity\Wrapper\Order;
use Entity\Wrapper\Orderitem;
use Log\Service\LogService;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\NodeException;
use Magelink\Exception\GatewayException;
use Node\AbstractNode;
use Zend\Stdlib\ArrayObject;


class OrderGateway extends AbstractGateway
{

    const GATEWAY_ENTITY = 'order';
    const GATEWAY_ENTITY_CODE = 'o';

    const RETRIEVAL_DELAY_SECONDS = 90;

    const MAGENTO_STATUS_PENDING = 'pending';
    const MAGENTO_STATUS_PENDING_ALIPAY = 'pending_alipay';
    const MAGENTO_STATUS_PENDING_ALIPAY_NEW = 'new';
    const MAGENTO_STATUS_PENDING_DPS = 'pending_dps';
    const MAGENTO_STATUS_PENDING_OGONE = 'pending_ogone';
    const MAGENTO_STATUS_PENDING_PAYMENT = 'pending_payment';
    const MAGENTO_STATUS_PENDING_PAYPAL = 'pending_paypal';
    const MAGENTO_STATUS_PAYMENT_REVIEW = 'payment_review';
    const MAGENTO_STATUS_FRAUD = 'fraud';
    const MAGENTO_STATUS_FRAUD_DPS = 'fraud_dps';

    private static $magentoPendingStatusses = array(
        self::MAGENTO_STATUS_PENDING,
        self::MAGENTO_STATUS_PENDING_ALIPAY,
        self::MAGENTO_STATUS_PENDING_ALIPAY_NEW,
        self::MAGENTO_STATUS_PENDING_DPS,
        self::MAGENTO_STATUS_PENDING_OGONE,
        self::MAGENTO_STATUS_PENDING_PAYMENT,
        self::MAGENTO_STATUS_PENDING_PAYPAL,
        self::MAGENTO_STATUS_PAYMENT_REVIEW,
        self::MAGENTO_STATUS_FRAUD,
        self::MAGENTO_STATUS_FRAUD_DPS
    );

    const MAGENTO_STATUS_ONHOLD = 'holded';

    const MAGENTO_STATUS_PROCESSING = 'processing';
    const MAGENTO_STATUS_PROCESSING_DPS_PAID = 'processing_dps_paid';
    const MAGENTO_STATUS_PROCESSING_OGONE = 'processed_ogone';
    const MAGENTO_STATUS_PROCESSING_DPS_AUTH = 'processing_dps_auth';
    const MAGENTO_STATUS_PAYPAL_CANCELED_REVERSAL = 'paypal_canceled_reversal';

    private static $magentoProcessingStatusses = array(
        self::MAGENTO_STATUS_PROCESSING,
        self::MAGENTO_STATUS_PROCESSING_DPS_PAID,
        self::MAGENTO_STATUS_PROCESSING_OGONE,
        self::MAGENTO_STATUS_PROCESSING_DPS_AUTH,
        self::MAGENTO_STATUS_PAYPAL_CANCELED_REVERSAL
    );

    const MAGENTO_STATUS_PAYPAL_REVERSED = 'paypal_reversed';

    const MAGENTO_STATUS_COMPLETE = 'complete';
    const MAGENTO_STATUS_CLOSED = 'closed';
    const MAGENTO_STATUS_CANCELED = 'canceled';

    private static $magentoFinalStatusses = array(
        self::MAGENTO_STATUS_COMPLETE,
        self::MAGENTO_STATUS_CLOSED,
        self::MAGENTO_STATUS_CANCELED
    );

    /** @var array $notRetrievedOrderIncrementIds */
    protected $notRetrievedOrderIncrementIds = NULL;


    /**
     * Initialize the gateway and perform any setup actions required.
     * @param string $entityType
     * @return bool $success
     * @throws MagelinkException
     * @throws GatewayException
     */
    protected function _init($entityType)
    {
        $success = parent::_init($entityType);

        if ($entityType != 'order') {
            throw new GatewayException('Invalid entity type for this gateway');
            $success = FALSE;
        }

        return $success;
    }

    /**
     * Get last retrieve date from the database
     * @return bool|string
     */
    protected function getRetrieveDateForForcedSynchronisation()
    {
        if ($this->newRetrieveTimestamp !== NULL) {
            $retrieveInterval = $this->newRetrieveTimestamp - $this->getLastRetrieveTimestamp();
            $intervalsBefore = 2.4 - min(1.2, max(0, $retrieveInterval / 3600));
            $retrieveTimestamp = intval($this->getLastRetrieveTimestamp()
                - min($retrieveInterval * $intervalsBefore, $retrieveInterval + 3600));
            $date = $this->convertTimestampToMagentoDateFormat($retrieveTimestamp);
        }else{
            $date = FALSE;
        }

        return $date;
    }

    /**
     * Check, if the order should be ignored or imported
     * @param array $orderData
     * @return bool
     */
    protected function isOrderToBeRetrieved(array $orderData)
    {
        if (strtotime($orderData['updated_at']) < $this->getNewRetrieveTimestamp() - self::RETRIEVAL_DELAY_SECONDS) {
            if (intval($orderData['increment_id']) > 100000000 && intval($orderData['increment_id']) < 200000000) {
                $retrieve = TRUE;
                // new orders (determined by the first au increment id after the migration)
            }elseif (intval($orderData['increment_id']) > 200048293) {
                $retrieve = TRUE;
                // old au orders
            }elseif (intval($orderData['increment_id']) > 200000000) {
                $isOrderPending = self::hasOrderStatePending($orderData['status']);
                $isOrderProcessing = self::hasOrderStateProcessing($orderData['status']);
                $retrieve = $isOrderPending || $isOrderProcessing;
                // estar orders
            }
        }else{
            $retrieve =  FALSE;
        }

        return $retrieve;
    }

    /**
     * Check, if the order should be ignored or written
     * @param Order $order
     * @return bool
     */
    public static function isOrderToBeWritten(Order $order)
    {
        $storeId = $order->getStoreId();
        return is_numeric($storeId);
    }

    /**
     * @param $orderStatus
     * @return bool $hasOrderStatePending
     */
    public static function hasOrderStatePending($orderStatus)
    {
        $hasOrderStatePending = in_array($orderStatus, self::$magentoPendingStatusses);
        return $hasOrderStatePending;
    }

    /**
     * @param $orderStatus
     * @return bool $hasOrderStateProcessing
     */
    public static function hasOrderStateProcessing($orderStatus)
    {
        $hasOrderStateProcessing = in_array($orderStatus, self::$magentoProcessingStatusses);
        return $hasOrderStateProcessing;
    }

    /**
     * @param $orderStatus
     * @return bool $hasOrderStateCancelled
     */
    public static function hasOrderStateCancelled($orderStatus)
    {
        $hasOrderStateCancelled = $orderStatus == self::MAGENTO_STATUS_CANCELED;
        return $hasOrderStateCancelled;
    }

    /**
     * @param $orderStatus
     * @return bool $hasOrderStateProcessing
     */
    public static function isFinalOrderStatus($orderStatus)
    {
        $isFinalOrderStatus = in_array($orderStatus, self::$magentoFinalStatusses);
        return $isFinalOrderStatus;
    }

    /**
     * @param Order|int $orderOrStoreId
     * @return int $storeId
     */
    protected function getEntityStoreId($orderOrStoreId, $global)
    {
        if (is_int($orderOrStoreId)) {
            $storeId = $orderOrStoreId;
        }elseif (is_object($orderOrStoreId) && substr(strrchr(get_class($orderOrStoreId), '\\'), 1) == 'Order') {
            $order = $orderOrStoreId;
            $storeId = $order->getStoreId();
        }else{
            $storeId = NULL;
        }

        if ($global || !$this->_node->isMultiStore()) {
            $storeId = 0;
        }

        return $storeId;
    }

    /**
     * @param Order|int $orderOrStoreId
     * @return int $storeId
     */
    protected function getCustomerStoreId($orderOrStoreId)
    {
        $globalCustomer = TRUE;
        return $this->getEntityStoreId($orderOrStoreId, $globalCustomer);
    }

    /**
     * @param Order|int $orderOrStoreId
     * @return int $storeId
     */
    protected function getStockStoreId($orderOrStoreId)
    {
        $globalStock = TRUE;
        return $this->getEntityStoreId($orderOrStoreId, $globalStock);
    }

    /**
     * @param Order $order
     * @param Orderitem $orderitem
     * @return bool|NULL
     * @throws MagelinkException
     */
    protected function updateStockQuantities(Order $order, Orderitem $orderitem)
    {
        $qtyPreTransit = NULL;
        $orderStatus = $order->getData('status');
        $isOrderPending = self::hasOrderStatePending($orderStatus);
        $isOrderProcessing = self::hasOrderStateProcessing($orderStatus);
        $isOrderCancelled = self::hasOrderStateCancelled($orderStatus);

        $logData = array('order id'=>$order->getId(), 'orderitem'=>$orderitem->getId(), 'sku'=>$orderitem->getData('sku'));
        $logEntities = array('node'=>$this->_node, 'order'=>$order, 'orderitem'=>$orderitem);

        if ($isOrderPending || $isOrderProcessing || $isOrderCancelled) {
            $storeId = $this->getStockStoreId($order);
            $logData['store_id'] = $storeId;

            $stockitem = $this->_entityService->loadEntity(
                $this->_node->getNodeId(),
                'stockitem',
                $storeId,
                $orderitem->getData('sku')
            );
            $logEntities['stockitem'] = $stockitem;

            $success = FALSE;
            if ($stockitem) {
                if ($isOrderProcessing) {
                    $attributeCode = 'qty_pre_transit';
                }else{
                    $attributeCode = 'available';
                }

                $attributeValue = $stockitem->getData($attributeCode, 0);
                $itemQuantity = $orderitem->getData('quantity', 0);
                if (is_array($itemQuantity)) {
                    $itemQuantity = array_pop($itemQuantity);
                }
                if ($isOrderPending) {
                    $itemQuantity *= -1;
                }

                $updateData = array($attributeCode=>($attributeValue + $itemQuantity));
                $logData = array_merge($logData, array('quantity'=>$itemQuantity), $updateData);

                try{
                    $this->_entityService->updateEntity($this->_node->getNodeId(), $stockitem, $updateData, FALSE);
                    $success = TRUE;

                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_INFO,
                            'mag_o_pre_upd',
                            'Updated '.$attributeCode.' on stockitem '.$stockitem->getEntityId(),
                            $logData, $logEntities
                        );
                }catch (\Exception $exception) {
                    $this->getServiceLocator()->get('logService')
                        ->log(LogService::LEVEL_ERROR,
                            'mag_o_si_upd_err',
                            'Update of '.$attributeCode.' failed on stockitem '.$stockitem->getEntityId(),
                            $logData, $logEntities
                        );
                }
            }else{
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_ERROR,
                        'mag_o_si_no_ex',
                        'Stockitem '.$orderitem->getData('sku').' does not exist.',
                        $logData, $logEntities
                    );
            }
        }else{
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_DEBUGEXTRA,
                    'mag_o_upd_pre_f',
                    'No update of qty_pre_transit. Order '.$order->getUniqueId().' has wrong status: '.$orderStatus,
                    array('order id'=>$order->getId()),
                    $logData, $logEntities
                );
            $success = NULL;
        }

        return $success;
    }

    /**
     * Store order with provided order data
     * @param array $orderData
     * @param bool $forced
     * @throws GatewayException
     * @throws MagelinkException
     * @throws NodeException
     */
    protected function storeOrderData(array $orderData, $forced = FALSE)
    {
        if ($forced) {
            $logLevel = LogService::LEVEL_WARN;
            $logCodeSuffix = '_fcd';
            $logMessageSuffix = ' (out of sync - forced)';
        }else{
            $logLevel = LogService::LEVEL_INFO;
            $logMessageSuffix = $logCodeSuffix = '';
        }
        $correctionHours = sprintf('%+d hours', intval($this->_node->getConfig('time_correction_order')));

        $storeId = ($this->_node->isMultiStore() ? $orderData['store_id'] : 0);
        $uniqueId = $orderData['increment_id'];
        $localId = isset($orderData['entity_id']) ? $orderData['entity_id'] : $orderData['order_id'];
        $createdAtTimestamp = strtotime($orderData['created_at']);

        $data = array(
            'customer_email'=>array_key_exists('customer_email', $orderData)
                ? $orderData['customer_email'] : NULL,
            'customer_name'=>(
                array_key_exists('customer_firstname', $orderData) ? $orderData['customer_firstname'].' ' : '')
                .(array_key_exists('customer_lastname', $orderData) ? $orderData['customer_lastname'] : ''
                ),
            'status'=>$orderData['status'],
            'placed_at'=>date('Y-m-d H:i:s', strtotime($correctionHours, $createdAtTimestamp)),
            'grand_total'=>$orderData['base_grand_total'],
            'base_to_currency_rate'=>$orderData['base_to_order_rate'],
            'weight_total'=>(array_key_exists('weight', $orderData)
                ? $orderData['weight'] : 0),
            'discount_total'=>(array_key_exists('base_discount_amount', $orderData)
                ? $orderData['base_discount_amount'] : 0),
            'shipping_total'=>(array_key_exists('base_shipping_amount', $orderData)
                ? $orderData['base_shipping_amount'] : 0),
            'tax_total'=>(array_key_exists('base_tax_amount', $orderData)
                ? $orderData['base_tax_amount'] : 0),
            'shipping_method'=>(array_key_exists('shipping_method', $orderData)
                ? $orderData['shipping_method'] : NULL)
        );

        if (array_key_exists('base_gift_cards_amount', $orderData)) {
            $data['giftcard_total'] = $orderData['base_gift_cards_amount'];
        }elseif (array_key_exists('base_gift_cards_invoiced', $orderData)) {
            $data['giftcard_total'] = $orderData['base_gift_cards_invoiced'];
        }else{
            $data['giftcard_total'] = 0;
        }
        if (array_key_exists('base_reward_currency_amount', $orderData)) {
            $data['reward_total'] = $orderData['base_reward_currency_amount'];
        }elseif (array_key_exists('base_reward_currency_amount_invoiced', $orderData)) {
            $data['reward_total'] = $orderData['base_reward_currency_amount_invoiced'];
        }else{
            $data['reward_total'] = 0;
        }
        if (array_key_exists('base_customer_balance_amount', $orderData)) {
            $data['storecredit_total'] = $orderData['base_customer_balance_amount'];
        }elseif (array_key_exists('base_customer_balance_amount_invoiced', $orderData)) {
            $data['storecredit_total'] = $orderData['base_customer_balance_amount_invoiced'];
        }else{
            $data['storecredit_total'] = 0;
        }

        $payments = array();
        if (isset($orderData['payment'])) {
            if (is_array($orderData['payment']) && !isset($orderData['payment']['payment_id'])) {
                foreach ($orderData['payment'] as $payment) {
                    $payments = $this->_entityService->convertPaymentData(
                        $payment['method'], $payment['base_amount_ordered'], $payment['cc_type']);
                }
            }elseif (isset($orderData['payment']['payment_id'])) {
                $payments = $this->_entityService->convertPaymentData(
                    $orderData['payment']['method'],
                    $orderData['payment']['base_amount_ordered'],
                    (isset($orderData['payment']['cc_type']) ? $orderData['payment']['cc_type'] : '')
                );
            }else{
                // store as sync issue
                throw new GatewayException('Invalid payment details format for order '.$uniqueId);
            }
        }
        if (count($payments)) {
            $data['payment_method'] = $payments;
        }

        if (isset($orderData['customer_id']) && $orderData['customer_id']) {
            $nodeId = $this->_node->getNodeId();
            $customer = $this->_entityService
                ->loadEntityLocal($nodeId, 'customer', $this->getCustomerStoreId($storeId), $orderData['customer_id']);
                //->loadEntity($nodeId, 'customer', $this->getCustomerStoreId($storeId), $orderData['customer_email']);
            if ($customer && $customer->getId()) {
                $data['customer'] = $customer;
            }else{
                $data['customer'] = NULL;
                // ToDo : Should never be the case, exception handling neccessary
            }
        }else{
            if ($this->isFinalOrderStatus($data['status'])) {
                $logLevel = LogService::LEVEL_WARN;
            }else{
//                $data['flagged'] = 1;
                $logLevel = LogService::LEVEL_ERROR;
            }
            $this->getServiceLocator()->get('logService')->log($logLevel, 'mag_o_nocu_err',
                'New order '.$uniqueId.' has no customer assigned.', array('order unique'=>$uniqueId));
        }

        $needsUpdate = TRUE;
        $orderComment = FALSE;

        /** @var Order $existingEntity */
        $existingEntity = $this->_entityService->loadEntityLocal(
            $this->_node->getNodeId(),
            'order',
            $storeId,
            $localId
        );

        if (!$existingEntity) {
            $existingEntity = $this->_entityService->loadEntity(
                $this->_node->getNodeId(),
                'order',
                $storeId,
                $uniqueId
            );

            if (!$existingEntity) {
                $this->_entityService->beginEntityTransaction('magento-order-'.$uniqueId);
                try{
                    $data = array_merge(
                        $this->createAddresses($orderData),
                        $data
                    );
                    $existingEntity = $this->_entityService->createEntity(
                        $this->_node->getNodeId(),
                        'order',
                        $storeId,
                        $uniqueId,
                        $data,
                        NULL
                    );
                    $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);

                    $orderComment = array('Initial sync'=>'Order #'.$uniqueId.' synced to HOPS.');

                    $this->getServiceLocator()->get('logService')
                        ->log($logLevel,
                            'mag_o_new'.$logCodeSuffix,
                            'New order '.$uniqueId.$logMessageSuffix,
                            array('sku'=>$uniqueId),
                            array('node'=>$this->_node, 'entity'=>$existingEntity)
                        );

                    $this->createItems($orderData, $existingEntity);

                    try{
                        // ToDo: Get new status from Magento to prevent overwrites (via db api?)
                        $status = $existingEntity->getData('status');
                        $comment = 'Order retrieved by MageLink, Entity #'.$existingEntity->getId();
                        $orderComment = array('Initial sync'=>'Order #'.$uniqueId.' synced to HOPS.');

                        $this->_soap->call('salesOrderAddComment', array($uniqueId, $status, $comment, FALSE));
                    }catch (\Exception $exception) {
                        $this->getServiceLocator()->get('logService')
                            ->log($logLevel,
                                'mag_o_r_cerr'.$logCodeSuffix,
                                'Failed to write comment on order '.$uniqueId.$logMessageSuffix,
                                array('exception message'=>$exception->getMessage()),
                                array('node'=>$this->_node, 'entity'=>$existingEntity, 'exception'=>$exception)
                            );
                    }
                    $this->_entityService->commitEntityTransaction('magento-order-'.$uniqueId);
                }catch (\Exception $exception) {
                    $this->_entityService->rollbackEntityTransaction('magento-order-'.$uniqueId);
                    throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                }
                $needsUpdate = FALSE;
            }else{
                $this->getServiceLocator()->get('logService')
                    ->log($logLevel,
                        'mag_o_unlink'.$logCodeSuffix,
                        'Unlinked order '.$uniqueId.$logMessageSuffix,
                        array('sku'=>$uniqueId),
                        array('node'=>$this->_node, 'entity'=>$existingEntity)
                    );
                $this->_entityService->linkEntity($this->_node->getNodeId(), $existingEntity, $localId);
            }
        }else{
            $attributesNotToUpdate = array('grand_total');
            foreach ($attributesNotToUpdate as $code) {
                if ($existingEntity->getData($code, NULL) !== NULL) {
                    unset($data[$code]);
                }
            }
            $this->getServiceLocator()->get('logService')
                ->log($logLevel,
                    'mag_o_upd'.$logCodeSuffix,
                    'Updated order '.$uniqueId.$logMessageSuffix,
                    array('order'=>$uniqueId),
                    array('node'=>$this->_node, 'entity'=>$existingEntity)
                );
        }

        if ($needsUpdate) {
            try{
                $oldStatus = $existingEntity->getData('status', NULL);
                $statusChanged = $oldStatus != $data['status'];
                if (!$orderComment && $statusChanged) {
                    $orderComment = array(
                        'Status change' => 'Order #'.$uniqueId.' moved from '.$oldStatus.' to '.$data['status']
                    );

                    $statusData = array('status'=>$data['status']);
                    foreach ($existingEntity->getAllOrders() as $order) {
                        if ($existingEntity->getId() != $order->getId()) {
                            $this->_entityService
                                ->updateEntity($this->_node->getNodeId(), $order, $statusData, FALSE);
                        }
                    }
                }

                $movedToProcessing = self::hasOrderStateProcessing($orderData['status'])
                    && !self::hasOrderStateProcessing($existingEntity->getData('status'));
                $movedToCancel = self::hasOrderStateCancelled($orderData['status'])
                    && !self::hasOrderStateCancelled($existingEntity->getData('status'));
                $this->_entityService->updateEntity($this->_node->getNodeId(), $existingEntity, $data, FALSE);

                $order = $this->_entityService->loadEntityId($this->_node->getNodeId(), $existingEntity->getId());
                if ($movedToProcessing || $movedToCancel) {
                    /** @var Order $order */
                    foreach ($order->getOrderitems() as $orderitem) {
                        $this->updateStockQuantities($order, $orderitem);
                    }
                }
            }catch (\Exception $exception) {
                throw new GatewayException('Needs update: '.$exception->getMessage(), 0, $exception);
            }
        }

        try{
            if ($orderComment) {
                if (!is_array($orderComment)) {
                    $orderComment = array($orderComment=>$orderComment);
                }
                $this->_entityService
                    ->createEntityComment($existingEntity, 'Magento/HOPS', key($orderComment), current($orderComment));
            }
        }catch (\Exception $exception) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR,
                    'mag_o_w_cerr'.$logCodeSuffix,
                    'Comment creation failed on order '.$uniqueId.'.',
                    array('order'=>$uniqueId, 'order comment array'=>$orderComment, 'error'=>$exception->getMessage()),
                    array('entity'=>$existingEntity, 'exception'=>$exception)
                );
        }

        try{
            $this->updateStatusHistory($orderData, $existingEntity);
        }catch (\Exception $exception) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR,
                    'mag_o_w_herr'.$logCodeSuffix,
                    'Updating of the status history failed on order '.$uniqueId.'.',
                    array('order'=>$uniqueId, 'order data'=>$orderData, 'error'=>$exception->getMessage()),
                    array('entity'=>$existingEntity, 'exception'=>$exception)
                );
        }
    }

    /**
     * Retrieve and action all updated records (either from polling, pushed data, or other sources).
     * @throws GatewayException
     * @throws MagelinkException
     * @throws NodeException
     */
    protected function retrieveEntities()
    {
        $lastRetrieve = $this->getLastRetrieveDate();

        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO,
                'mag_o_re_time',
                'Retrieving orders updated since '.$lastRetrieve,
                array('type'=>'order', 'timestamp'=>$lastRetrieve)
            );

        $success = NULL;
        if (FALSE && $this->_db) {
            try{
                // ToDo (maybe): Implement
                $storeId = $orderIds = FALSE;
                $results = $this->_db->getOrders($storeId, $lastRetrieve, FALSE, $orderIds);
                foreach ($results as $orderData) {
                    $orderData = (array) $orderData;
                    if ($this->isOrderToBeRetrieved($orderData)) {
                        $success = $this->storeOrderData($orderData);
                    }
                }
            }catch (\Exception $exception) {
                // store as sync issue
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
            }
        }elseif ($this->_soap) {
            try{
                $complexFilter = array(array(
                    'key'=>'updated_at',
                    'value'=>array(
                        'key'=>'gt',
                        'value'=>$lastRetrieve
                    ))
                );
                $results = $this->_soap->call('salesOrderList', array(array('complex_filter'=>$complexFilter)));

                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_INFO,
                        'mag_o_soap_list',
                        'Retrieved salesOrderList updated from '.$lastRetrieve,
                        array('updated_at'=>$lastRetrieve, 'result no'=>count($results))
                    );
                foreach ($results as $orderFromList) {
                    if ($this->isOrderToBeRetrieved($orderFromList)) {
                        $orderData = $this->_soap->call('salesOrderInfo', array($orderFromList['increment_id']));
                        if (isset($orderData['result'])) {
                            $orderData = $orderData['result'];
                        }

                        unset ($orderFromList['status']); // Reduces risk overwriting a status when adding a comment
                        unset ($orderFromList['updated_at']); // Get the last update datetime
                        $missingFieldsInSalesOrderList = array_diff(
                            array_keys($orderFromList),
                            array_keys($orderData)
                        );

                        foreach ($missingFieldsInSalesOrderList as $key) {
                            $orderData[$key] = $orderFromList[$key];
                        }

                        $this->storeOrderData($orderData);
                    }
                }
            }catch(\Exception $exception) {
                if (!isset($results) || !$results) {
                    $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR, 'mag_o_soap_lerr',
                        'Error on soap call salesOrderList since '.$lastRetrieve,
                        array('results'=>(isset($results) ? $results : 'not set'), 'complex_filter'=>$complexFilter)
                    );
                }
                // store as sync issue
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                $results = array();
            }
        }else{
            throw new NodeException('No valid API available for sync');
            $results = array();
        }

        $this->_nodeService
            ->setTimestamp($this->_nodeEntity->getNodeId(), 'order', 'retrieve', $this->getNewRetrieveTimestamp());

        try{
            $this->forceSynchronisation();
        }catch(\Exception $exception) {
            // store as sync issue
           throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }

        return count($results);
    }

    /**
     * Compare orders on Magento with the orders no Magelink and return increment id array of orders not retrieved
     * @return array|bool $notRetrievedOrderIncrementIds
     * @throws GatewayException
     * @throws NodeException
     */
    protected function getNotRetrievedOrders()
    {
        $start = microtime(TRUE);
        $logCode = 'mag_o_re_f';

        if ($this->notRetrievedOrderIncrementIds === NULL) {
            $notRetrievedOrderIncrementIds = array();

            if ($this->_db) {
                $api = 'db';
                try {
                    $results = $this->_db->getOrders(
                        FALSE,
                        $this->getRetrieveDateForForcedSynchronisation(),
                        $this->convertTimestampToMagentoDateFormat($this->getNewRetrieveTimestamp())
                    );
                }catch (\Exception $exception) {
                    // store as sync issue
                    throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                }
            }elseif ($this->_soap) {
                $api = 'soap';
                if ($this->getRetrieveDateForForcedSynchronisation()) {
                    $soapCallFilterData = array(array('complex_filter'=>array(array(
                        'key'=>'updated_at',
                        'value'=>array('key'=>'gt', 'value'=>$this->getRetrieveDateForForcedSynchronisation()),
                    ))));
                }else{
                    $soapCallFilterData = array();
                }

                try {
                    $results = $this->_soap->call('salesOrderList', $soapCallFilterData);
                }catch (\Exception $exception) {
                    // store as sync issue
                    throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                }
            }else {
                throw new NodeException('No valid API available for synchronisation check');
            }

            foreach ($results as $magentoOrder) {
                if ($magentoOrder instanceof \ArrayObject) {
                    $magentoOrder = (array) $magentoOrder;
                }
                if ($this->isOrderToBeRetrieved((array) $magentoOrder)) {
                    $isMagelinkOrder = $this->_entityService->isEntity(
                        $this->_nodeEntity->getNodeId(),
                        'order',
                        0,
                        $magentoOrder['increment_id']
                    );
                    if (!$isMagelinkOrder) {
                        $notRetrievedOrderIncrementIds[$magentoOrder['increment_id']] = $magentoOrder['increment_id'];
                    }
                }
            }

            if ($notRetrievedOrderIncrementIds) {
                $this->notRetrievedOrderIncrementIds = $notRetrievedOrderIncrementIds;
                $seconds = ceil(microtime(TRUE) - $start);
                $message = 'Get not retrieved orders (back to '.$this->getRetrieveDateForForcedSynchronisation()
                    .') via '.$api.' took '.$seconds.'s.';
                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_INFO, $logCode.'_'.$api.'no', $message, array(
                        'checked orders'=>count($results),
                        'not retrieved orders'=>count($notRetrievedOrderIncrementIds),
                        'retrieve start date'=>$this->getRetrieveDateForForcedSynchronisation(),
                        'period[s]'=>$seconds
                    ));
            }else{
                $this->notRetrievedOrderIncrementIds = FALSE;
            }
        }

        return $this->notRetrievedOrderIncrementIds;
    }

    /**
     * Check if all orders are retrieved from Magento into Magelink
     * @return bool
     */
    protected function areOrdersInSync()
    {
        if ($this->notRetrievedOrderIncrementIds === NULL) {
            $this->getNotRetrievedOrders();
        }
        $isInSync = !(bool) $this->notRetrievedOrderIncrementIds;

        return $isInSync;
    }

    /**
     * Check for orders out of sync; load, create and check them; return success/failure
     * @return bool $success
     * @throws GatewayException
     * @throws MagelinkException
     * @throws NodeException
     */
    public function forceSynchronisation()
    {
        $success = TRUE;
        $start = microtime(TRUE);

        if (!$this->areOrdersInSync()) {
            $logCode = 'mag_o_re_frc';
            $forcedOrders = count($this->notRetrievedOrderIncrementIds);

            $orderOutOfSyncList = implode(', ', $this->notRetrievedOrderIncrementIds);
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_WARN, $logCode,
                    'Retrieving orders: '.$orderOutOfSyncList,
                    array(), array('order increment ids out of sync'=>$orderOutOfSyncList)
                );

            foreach ($this->notRetrievedOrderIncrementIds as $orderIncrementId) {
                if (FALSE && $this->_db) {
                    try {
                        // ToDo (maybe): Implemented
                        $orderData = (array) $this->_db->getOrderByIncrementId($orderIncrementId);
                    }catch (\Exception $exception) {
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }
                }elseif ($this->_soap) {
                    $orderData = $this->_soap->call('salesOrderInfo', array($orderIncrementId));
                    if (isset($orderData['result'])) {
                        $orderData = $orderData['result'];
                    }

                    try {
                        $complexFilter = array(array(
                            'key'=>'increment_id',
                            'value'=>array('key'=>'eq', 'value'=>$orderIncrementId),
                        ));
                        $results = $this->_soap->call('salesOrderList', array(array('complex_filter'=>$complexFilter)));
                        $orderFromList = array_shift($results);
                    }catch (\Exception $exception) {
                        $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_ERROR,
                            'mag_o_soap_lfer',
                            'Error on soap call salesOrderList on order '.$orderIncrementId.'.',
                            array('results'=>(isset($results) ? $results : 'not set'), 'complex_filter'=>$complexFilter)
                        );
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }

                    foreach (array_diff(array_keys($orderFromList), array_keys($orderData)) as $key) {
                        $orderData[$key] = $orderFromList[$key];
                    }
                }else{
                    throw new NodeException('No valid API available for forced synchronisation');
                }

                $this->storeOrderData($orderData, TRUE);

                $magelinkOrder = $this->_entityService
                    ->loadEntity($this->_nodeEntity->getNodeId(), 'order', 0, $orderIncrementId);
                if ($magelinkOrder) {
                    unset($this->notRetrievedOrderIncrementIds[$magelinkOrder->getUniqueId()]);
                }
            }

            $forcedOrders -= count($this->notRetrievedOrderIncrementIds);
            $seconds = ceil(microtime(TRUE) - $start);
            $logData = array('type'=>'order', 'forced orders'=>$forcedOrders, 'period [s]'=>$seconds);
            if (count($forcedOrders) > 0) {
                $logData['per entity [s]'] = round($seconds / count($forcedOrders), 3);
            }

            if (count($this->notRetrievedOrderIncrementIds) > 0) {
                $success = FALSE;
                $orderOutOfSyncList = implode(', ', $this->notRetrievedOrderIncrementIds);

                $logLevel = LogService::LEVEL_ERROR;
                $logCode .= 'err';
                $logMessage = 'Forced retrieval failed for orders: '.$orderOutOfSyncList;
                $logData['order increment ids still out of sync'] = $orderOutOfSyncList;
            }else{
                $logLevel = LogService::LEVEL_INFO;
                $logCode .= 'no';
                $logMessage = 'Forced retrieval on '.$forcedOrders.' orders in '.$seconds.'s up to '
                    .strftime('%H:%M:%S, %d/%m', $this->getNewRetrieveTimestamp()).'.';
            }

            $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $logMessage, $logData);
        }

        return $success;
    }

    /**
     * Insert any new status history entries as entity comments
     * @param array $orderData The full order data
     * @param Order $orderEntity The order entity to attach to
     * @throws MagelinkException
     * @throws NodeException
     */
    protected function updateStatusHistory(array $orderData, Order $orderEntity)
    {
        $referenceIds = array();
        $commentIds = array();
        $comments = $this->_entityService->loadEntityComments($orderEntity);

        foreach($comments as $com){
            $referenceIds[] = $com->getReferenceId();
            $commentIds[] = $com->getCommentId();
        }

        foreach ($orderData['status_history'] as $historyItem) {
            if (isset($historyItem['comment']) && preg_match('/{([0-9]+)} - /', $historyItem['comment'], $matches)) {
                if(in_array($matches[1], $commentIds)){
                    continue; // Comment already loaded through another means
                }
            }
            if (in_array($historyItem['created_at'], $referenceIds)) {
                continue; // Comment already loaded
            }

            if (!isset($historyItem['comment'])) {
                $historyItem['comment'] = '(no comment)';
            }
            if (!isset($historyItem['status'])) {
                $historyItem['status'] = '(no status)';
            }
            $notifyCustomer = isset($historyItem['is_customer_notified']) && $historyItem['is_customer_notified'] == '1';

            $this->_entityService->createEntityComment(
                $orderEntity,
                'Magento',
                'Status History Event: '.$historyItem['created_at'].' - '.$historyItem['status'],
                $historyItem['comment'],
                $historyItem['created_at'],
                $notifyCustomer
            );
        }
    }

    /**
     * Create all the OrderItem entities for a given order
     * @param array $orderData
     * @param Order $order
     * @throws MagelinkException
     * @throws NodeException
     */
    protected function createItems(array $orderData, Order $order)
    {
        $nodeId = $this->_node->getNodeId();
        $parentId = $order->getId();

        foreach ($orderData['items'] as $item) {
            $uniqueId = $orderData['increment_id'].'-'.$item['sku'].'-'.$item['item_id'];

            $entity = $this->_entityService
                ->loadEntity(
                    $this->_node->getNodeId(),
                    'orderitem',
                    ($this->_node->isMultiStore() ? $orderData['store_id'] : 0),
                    $uniqueId
                );
            if (!$entity) {
                $localId = $item['item_id'];
                $product = $this->_entityService->loadEntity($this->_node->getNodeId(), 'product', 0, $item['sku']);
                $data = array(
                    'product'=>($product ? $product->getId() : null),
                    'sku'=>$item['sku'],
                    'product_name'=>isset($item['name']) ? $item['name'] : '',
                    'is_physical'=>((isset($item['is_virtual']) && $item['is_virtual']) ? 0 : 1),
                    'product_type'=>(isset($item['product_type']) ? $item['product_type'] : null),
                    'quantity'=>$item['qty_ordered'],
                    'item_price'=>(isset($item['base_price']) ? $item['base_price'] : 0),
                    'total_price'=>(isset($item['base_row_total']) ? $item['base_row_total'] : 0),
                    'total_tax'=>(isset($item['base_tax_amount']) ? $item['base_tax_amount'] : 0),
                    'total_discount'=>(isset($item['base_discount_amount']) ? $item['base_discount_amount'] : 0),
                    'weight'=>(isset($item['row_weight']) ? $item['row_weight'] : 0),
                );

                if (isset($item['base_price_incl_tax'])) {
                    $data['item_tax'] = $item['base_price_incl_tax'] - $data['item_price'];
                }elseif ($data['total_price'] && $data['total_price'] > 0) {
                    $data['item_tax'] = ($data['total_tax'] / $data['total_price']) * $data['item_price'];
                }elseif ($data['quantity'] && $data['quantity'] > 0){
                    $data['item_tax'] = $data['total_tax'] / $data['quantity'];
                }else{
                    $data['item_tax'] = 0;
                }

                $data['item_discount'] = ($data['quantity'] ? $data['total_discount'] / $data['quantity'] : 0);

                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_INFO,
                        'mag_o_re_cr_oi',
                        'Create item data',
                        array('orderitem uniqued id'=>$uniqueId, 'quantity'=>$data['quantity'],'data'=>$data)
                    );

                $storeId = ($this->_node->isMultiStore() ? $orderData['store_id'] : 0);
                $orderitem = $this->_entityService
                    ->createEntity($nodeId, 'orderitem', $storeId, $uniqueId, $data, $parentId);
                $this->_entityService
                    ->linkEntity($this->_node->getNodeId(), $orderitem, $localId);

                $this->updateStockQuantities($order, $orderitem);
            }
        }

    }

    /**
     * Create the Address entities for a given order and pass them back as the appropraite attributes
     * @param array $orderData
     * @return array $data
     */
    protected function createAddresses(array $orderData)
    {
        $data = array();
        if(isset($orderData['shipping_address'])){
            $data['shipping_address'] = $this->createAddressEntity($orderData['shipping_address'], $orderData, 'shipping');
        }
        if(isset($orderData['billing_address'])){
            $data['billing_address'] = $this->createAddressEntity($orderData['billing_address'], $orderData, 'billing');
        }
        return $data;
    }

    /**
     * Creates an individual address entity (billing or shipping)
     * @param array $addressData
     * @param array $orderData
     * @param string $type "billing" or "shipping"
     * @return Order|null $entity
     * @throws MagelinkException
     * @throws NodeException
     */
    protected function createAddressEntity(array $addressData, array $orderData, $type)
    {
        if (!array_key_exists('address_id', $addressData) || $addressData['address_id'] == NULL) {
            return NULL;
        }

        $uniqueId = 'order-'.$orderData['increment_id'].'-'.$type;

        $entity = $this->_entityService->loadEntity(
            $this->_node->getNodeId(), 'address', ($this->_node->isMultiStore() ? $orderData['store_id'] : 0), $uniqueId
        );
/*
        // DISABLED: Generally doesn't work.
        if (!$entity) {
            $entity = $this->_entityService->loadEntityLocal(
                $this->_node->getNodeId(),
                'address',
                ($this->_node->isMultiStore() ? $orderData['store_id'] : 0),
                $addressData['address_id']
            );
        }
*/
        if (!$entity) {
            $data = array(
                'first_name'=>(isset($addressData['firstname']) ? $addressData['firstname'] : null),
                'last_name'=>(isset($addressData['lastname']) ? $addressData['lastname'] : null),
                'street'=>(isset($addressData['street']) ? $addressData['street'] : null),
                'city'=>(isset($addressData['city']) ? $addressData['city'] : null),
                'region'=>(isset($addressData['region']) ? $addressData['region'] : null),
                'postcode'=>(isset($addressData['postcode']) ? $addressData['postcode'] : null),
                'country_code'=>(isset($addressData['country_id']) ? $addressData['country_id'] : null),
                'telephone'=>(isset($addressData['telephone']) ? $addressData['telephone'] : null),
                'company'=>(isset($addressData['company']) ? $addressData['company'] : null)
            );

            $entity = $this->_entityService->createEntity(
                $this->_node->getNodeId(),
                'address',
                ($this->_node->isMultiStore() ? $orderData['store_id'] : 0),
                $uniqueId,
                $data
            );
            $this->_entityService->linkEntity($this->_node->getNodeId(), $entity, $addressData['address_id']);
        }

        return $entity;
    }

    /**
     * Write out all the updates to the given entity.
     * @param \Entity\Entity $entity
     * @param string[] $attributes
     * @param int $type
     */
    public function writeUpdates(\Entity\Entity $entity, $attributes, $type = \Entity\Update::TYPE_UPDATE)
    {
        // TODO (unlikely): Create method. (We don't perform any direct updates to orders in this manner).
        return NULL;
    }

    /**
     * Write out the given action.
     * @param \Entity\Action $action
     * @return bool $success
     * @throws GatewayException
     * @throws MagelinkException
     * @throws NodeException
     */
    public function writeAction(\Entity\Action $action)
    {
        /** @var \Entity\Wrapper\Order $order */
        $order = $action->getEntity();
        // Reload order because entity might have changed in the meantime
        $order = $this->_entityService->reloadEntity($order);

        if (self::isOrderToBeWritten($order)) {
            $success = TRUE;
            $uniqueId = $order->getUniqueId();
            $orderStatus = $order->getData('status');

            switch ($action->getType()) {
                case 'comment':
                    $status = ($action->hasData('status') ? $action->getData('status') : $orderStatus);
                    $comment = $action->getData('comment');
                    if ($comment == NULL && $action->getData('body')) {
                        if ($action->getData('title') != NULL) {
                            $comment = $action->getData('title').' - ';
                        }else{
                            $comment = '';
                        }
                        if($action->hasData('comment_id')){
                            $comment .= '{'.$action->getData('comment_id').'} ';
                        }
                        $comment .= $action->getData('body');
                    }

                    if ($action->hasData('customer_visible')) {
                        $notify = $action->getData('customer_visible') ? 'true' : 'false';
                    }else{
                        $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : NULL);
                    }

                    try {
                        $this->_soap->call('salesOrderAddComment', array(
                                $order->getOriginalOrder()->getUniqueId(),
                                $status,
                                $comment,
                                $notify
                            ));
                    }catch (\Exception $exception) {
                        // store as sync issue
                        throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                    }
                    break;
                case 'cancel':
                    $isCancelable = self::hasOrderStatePending($orderStatus);
                    if (!self::hasOrderStateCancelled($orderStatus)) {
                        if (!$isCancelable){
                            $message = 'Attempted to cancel non-pending order '.$uniqueId.' ('.$orderStatus.')';
                            // store as a sync issue
                            throw new GatewayException($message);
                            $success = FALSE;
                        }elseif ($order->isSegregated()) {
                            // store as a sync issue
                            throw new GatewayException('Attempted to cancel child order '.$uniqueId.' !');
                            $success = FALSE;
                        }else{
                            try {
                                $this->_soap->call('salesOrderCancel', $uniqueId);

                                // Update status straight away
                                $changedOrder = $this->_soap->call('salesOrderInfo', array($uniqueId));
                                if (isset($changedOrder['result'])) {
                                    $changedOrder = $changedOrder['result'];
                                }

                                $newStatus = $changedOrder['status'];
                                $changedOrderData = array('status'=>$newStatus);
                                $this->_entityService->updateEntity(
                                    $this->_node->getNodeId(),
                                    $order,
                                    $changedOrderData,
                                    FALSE
                                );
                                $changedOrderData['status_history'] = array(array(
                                    'comment'=>'HOPS updated status from Magento after abandoning order to '.$newStatus.'.',
                                    'created_at'=>date('Y/m/d H:i:s')
                                ));
                                $this->updateStatusHistory($changedOrderData, $order);
                            }catch (\Exception $exception) {
                                // store as sync issue
                                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                            }
                        }
                    }
                    break;
                case 'hold':
                    if ($order->isSegregated()) {
                        // Is that really necessary to throw an exception?
                        throw new GatewayException('Attempted to hold child order!');
                        $success = FALSE;
                    }else{
                        try {
                            $this->_soap->call('salesOrderHold', $uniqueId);
                        }catch (\Exception $exception) {
                            // store as sync issue
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }
                    }
                    break;
                case 'unhold':
                    if ($order->isSegregated()) {
                        // Is that really necessary to throw an exception?
                        throw new GatewayException('Attempted to unhold child order!');
                        $success = FALSE;
                    }else{
                        try {
                           $this->_soap->call('salesOrderUnhold', $uniqueId);
                        }catch (\Exception $exception) {
                            // store as sync issue
                            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
                        }
                    }
                    break;
                case 'ship':
                    if (self::hasOrderStateProcessing($orderStatus)) {
                        $comment = ($action->hasData('comment') ? $action->getData('comment') : NULL);
                        $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : NULL);
                        $sendComment = ($action->hasData('send_comment') ?
                            ($action->getData('send_comment') ? 'true' : 'false' ) : NULL);
                        $itemsShipped = ($action->hasData('items') ? $action->getData('items') : NULL);
                        $trackingCode = ($action->hasData('tracking_code') ? $action->getData('tracking_code') : NULL);

                        $this->actionShip($order, $comment, $notify, $sendComment, $itemsShipped, $trackingCode);
                    }else{
                        $message = 'Invalid order status for shipment: '
                            .$uniqueId.' has '.$order->getData('status');
                        // Is that really necessary to throw an exception?
                        throw new GatewayException($message);
                        $success = FALSE;
                    }
                    break;
                case 'creditmemo':
                    if (self::hasOrderStateProcessing($orderStatus) || $orderStatus == self::MAGENTO_STATUS_COMPLETE) {
                        $comment = ($action->hasData('comment') ? $action->getData('comment') : NULL);
                        $notify = ($action->hasData('notify') ? ($action->getData('notify') ? 'true' : 'false' ) : NULL);
                        $sendComment = ($action->hasData('send_comment') ?
                            ($action->getData('send_comment') ? 'true' : 'false' ) : NULL);
                        $itemsRefunded = ($action->hasData('items') ? $action->getData('items') : NULL);
                        $shippingRefund = ($action->hasData('shipping_refund') ? $action->getData('shipping_refund') : 0);
                        $creditRefund = ($action->hasData('credit_refund') ? $action->getData('credit_refund') : 0);
                        $adjustmentPositive =
                            ($action->hasData('adjustment_positive') ? $action->getData('adjustment_positive') : 0);
                        $adjustmentNegative =
                            ($action->hasData('adjustment_negative') ? $action->getData('adjustment_negative') : 0);

                        $message = 'Magento, create creditmemo: Passing values orderIncrementId '.$uniqueId
                            .'creditmemoData: [qtys=>'.var_export($itemsRefunded, TRUE).', shipping_amount=>'.$shippingRefund
                            .', adjustment_positive=>'.$adjustmentPositive.', adjustment_negative=>'.$adjustmentNegative
                            .'], comment '.$comment.', notifyCustomer '.$notify.', includeComment '.$sendComment
                            .', refundToStoreCreditAmount '.$creditRefund.'.';
                        $this->getServiceLocator()->get('logService')
                            ->log(LogService::LEVEL_DEBUGEXTRA,
                                'mag_o_wr_cr_cm',
                                $message,
                                array(
                                    'entity (order)'=>$order,
                                    'action'=>$action,
                                    'action data'=>$action->getData(),
                                    'orderIncrementId'=>$uniqueId,
                                    'creditmemoData'=>array(
                                        'qtys'=>$itemsRefunded,
                                        'shipping_amount'=>$shippingRefund,
                                        'adjustment_positive'=>$adjustmentPositive,
                                        'adjustment_negative'=>$adjustmentNegative
                                    ),
                                    'comment'=>$comment,
                                    'notifyCustomer'=>$notify,
                                    'includeComment'=>$sendComment,
                                    'refundToStoreCreditAmount'=>$creditRefund
                                )
                            );
                        $this->actionCreditmemo($order, $comment, $notify, $sendComment,
                            $itemsRefunded, $shippingRefund, $creditRefund, $adjustmentPositive, $adjustmentNegative);
                    }else{
                        $message = 'Invalid order status for creditmemo: '.$uniqueId.' has '.$orderStatus;
                        // store as a sync issue
                        throw new GatewayException($message);
                        $success = FALSE;
                    }
                    break;
                default:
                    // store as a sync issue
                    throw new GatewayException('Unsupported action type '.$action->getType().' for Magento Orders.');
                    $success = FALSE;
            }
        }else{
            $success = NULL;
        }

        return $success;
    }

    /**
     * Preprocesses order items array (key=orderitem entity id, value=quantity) into an array suitable for Magento
     * (local item ID=>quantity), while also auto-populating if not specified.
     * @param Order $order
     * @param array|NULL $rawItems
     * @return array
     * @throws GatewayException
     */
    protected function preprocessRequestItems(Order $order, $rawItems = NULL)
    {
        $items = array();
        if($rawItems == null){
            $orderItems = $this->_entityService->locateEntity(
                $this->_node->getNodeId(),
                'orderitem',
                $order->getStoreId(),
                array(
                    'PARENT_ID'=>$order->getId(),
                ),
                array(
                    'PARENT_ID'=>'eq'
                ),
                array('linked_to_node'=>$this->_node->getNodeId()),
                array('quantity')
            );
            foreach($orderItems as $oi){
                $localid = $this->_entityService->getLocalId($this->_node->getNodeId(), $oi);
                $items[$localid] = $oi->getData('quantity');
            }
        }else{
            foreach ($rawItems as $entityId=>$quantity) {
                $item = $this->_entityService->loadEntityId($this->_node->getNodeId(), $entityId);
                if ($item->getTypeStr() != 'orderitem' || $item->getParentId() != $order->getId()
                    || $item->getStoreId() != $order->getStoreId()){

                    $message = 'Invalid item '.$entityId.' passed to preprocessRequestItems for order '.$order->getId();
                    throw new GatewayException($message);
                }

                if ($quantity == NULL) {
                    $quantity = $item->getData('quantity');
                }elseif ($quantity > $item->getData('quantity')) {
                    $message = 'Invalid item quantity '.$quantity.' for item '.$entityId.' in order '.$order->getId()
                        .' - max was '.$item->getData('quantity');
                    throw new GatewayExceptionn($message);
                }

                $localId = $this->_entityService->getLocalId($this->_node->getNodeId(), $item);
                $items[$localId] = $quantity;
            }
        }
        return $items;
    }

    /**
     * Handles refunding an order in Magento
     *
     * @param Order $order
     * @param string $comment Optional comment to append to order
     * @param string $notify String boolean, whether to notify customer
     * @param string $sendComment String boolean, whether to include order comment in notify
     * @param array $itemsRefunded Array of item entity id->qty to refund, or null if automatic (all)
     * @param int $shippingRefund
     * @param int $creditRefund
     * @param int $adjustmentPositive
     * @param int $adjustmentNegative
     * @throws GatewayException
     */
    protected function actionCreditmemo(Order $order, $comment = '', $notify = 'false', $sendComment = 'false',
        $itemsRefunded = NULL, $shippingRefund = 0, $creditRefund = 0, $adjustmentPositive = 0, $adjustmentNegative = 0)
    {
        $items = array();

        if (count($itemsRefunded)) {
            $processItems = $itemsRefunded;
        }else{
            $processItems = array();
            foreach ($order->getOrderitems() as $orderItem) {
                $processItems[$orderItem->getId()] = 0;
            }
        }

        foreach ($this->preprocessRequestItems($order, $processItems) as $local=>$qty) {
            $items[] = array('order_item_id'=>$local, 'qty'=>$qty);
        }

        $creditmemoData = array(
            'qtys'=>$items,
            'shipping_amount'=>$shippingRefund,
            'adjustment_positive'=>$adjustmentPositive,
            'adjustment_negative'=>$adjustmentNegative,
        );

        $originalOrder = $order->getOriginalOrder();
        try {
            $soapResult = $this->_soap->call('salesOrderCreditmemoCreate', array(
                $originalOrder->getUniqueId(),
                $creditmemoData,
                $comment,
                $notify,
                $sendComment,
                $creditRefund
            ));
        }catch (\Exception $exception) {
            // store as sync issue
            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }

        if (is_object($soapResult)) {
            $soapResult = $soapResult->result;
        }elseif (is_array($soapResult)) {
            if (isset($soapResult['result'])) {
                $soapResult = $soapResult['result'];
            }else{
                $soapResult = array_shift($soapResult);
            }
        }

        if (!$soapResult) {
            // store as a sync issue
            throw new GatewayException('Failed to get creditmemo ID from Magento for order '.$order->getUniqueId());
        }

        try {
            $this->_soap->call('salesOrderCreditmemoAddComment',
                array($soapResult, 'FOR ORDER: '.$order->getUniqueId(), FALSE, FALSE));
        }catch (\Exception $exception) {
            // store as a sync issue
            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * Handles shipping an order in Magento
     *
     * @param Order $order
     * @param string $comment Optional comment to append to order
     * @param string $notify String boolean, whether to notify customer
     * @param string $sendComment String boolean, whether to include order comment in notify
     * @param array|null $itemsShipped Array of item entity id->qty to ship, or null if automatic (all)
     * @throws GatewayException
     */
    protected function actionShip(Order $order, $comment = '', $notify = 'false', $sendComment = 'false',
        $itemsShipped = NULL, $trackingCode = NULL)
    {
        $items = array();
        foreach ($this->preprocessRequestItems($order, $itemsShipped) as $local=>$qty) {
            $items[] = array('order_item_id'=>$local, 'qty'=>$qty);
        }
        if (count($items) == 0) {
            $items = NULL;
        }

        $orderId = ($order->getData('original_order') != NULL ?
            $order->resolve('original_order', 'order')->getUniqueId() : $order->getUniqueId());
        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_DEBUGEXTRA,
                'mag_o_act_ship',
                'Sending shipment for '.$orderId,
                array(
                    'ord'=>$order->getId(),
                    'items'=>$items,
                    'comment'=>$comment,
                    'notify'=>$notify,
                    'sendComment'=>$sendComment
                ),
                array('node'=>$this->_node, 'entity'=>$order)
            );

        try {
            $soapResult = $this->_soap->call('salesOrderShipmentCreate', array(
                'orderIncrementId'=>$orderId,
                'itemsQty'=>$items,
                'comment'=>$comment,
                'email'=>$notify,
                'includeComment'=>$sendComment
            ));
        }catch (\Exception $exception) {
            // store as sync issue
            throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
        }

        if (is_object($soapResult)) {
            $soapResult = $soapResult->shipmentIncrementId;
        }elseif (is_array($soapResult)) {
            if (isset($soapResult['shipmentIncrementId'])) {
                $soapResult = $soapResult['shipmentIncrementId'];
            }else{
                $soapResult = array_shift($soapResult);
            }
        }

        if (!$soapResult) {
            // store as sync issue
            throw new GatewayException('Failed to get shipment ID from Magento for order '.$order->getUniqueId());
        }

        if ($trackingCode != NULL) {
            try {
                $this->_soap->call('salesOrderShipmentAddTrack',
                    array(
                        'shipmentIncrementId'=>$soapResult,
                        'carrier'=>'custom',
                        'title'=>$order->getData('shipping_method', 'Shipping'),
                        'trackNumber'=>$trackingCode)
                );
            }catch (\Exception $exception) {
                // store as sync issue
                throw new GatewayException($exception->getMessage(), $exception->getCode(), $exception);
            }
        }
    }

}
