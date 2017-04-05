<?php
/**
 * @category Magento
 * @package Magento
 * @author Andreas Gerhards <andreas@lero9.co.nz>
 * @copyright Copyright (c) 2014 LERO9 Ltd.
 * @license http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause - Please view LICENSE.md for more information
 */

namespace Magento;

use Application\Service\ApplicationConfigService;
use Log\Service\LogService;
use Magelink\Exception\NodeException;
use Node\AbstractNode;
use Node\AbstractGateway;
use Node\Entity;
use Magelink\Exception\MagelinkException;
use Magelink\Exception\SyncException;
use Zend\Db\TableGateway\TableGateway;
use Zend\Db\Sql\Where;


class Node extends AbstractNode
{

    /** @var array|NULL $_storeViews */
    protected $_storeViews = NULL;


    /**
     * @return string $nodeLogPrefix
     */
    protected function getNodeLogPrefix()
    {
        return 'mag_';
    }

    /**
     * @return bool Whether or not we should enable multi store mode
     */
    public function isMultiStore()
    {
        return (bool) $this->getConfig('multi_store');
    }

    /**
     * Returns an api instance set up for this node. Will return false if that type of API is unavailable.
     * @param string $type The type of API to establish - must be available as a service with the name "magento_{type}"
     * @return object|false
     */
    public function getApi($type)
    {
        if(isset($this->_api[$type])){
            return $this->_api[$type];
        }

        $this->_api[$type] = $this->getServiceLocator()->get('magento_' . $type);
        $this->getServiceLocator()->get('logService')
            ->log(LogService::LEVEL_INFO,
                'mag_init_api',
                'Creating API instance '.$type,
                array('type'=>$type),
                array('node'=>$this)
            );

        $apiExists = $this->_api[$type]->init($this);
        if (!$apiExists) {
            $this->_api[$type] = FALSE;
        }

        return $this->_api[$type];
    }

    /**
     * Return a data array of all store views
     * @return array $this->_storeViews
     */
    public function getStoreViews()
    {
        if ($this->_storeViews === NULL) {
            $soap = $this->getApi('soap');
            if (!$soap) {
                throw new SyncException('Failed to initialize SOAP api for store view fetch');
            }else{
                /** @var \Magento\Api|Soap $soap */
                $response = $soap->call('storeList', array());
                if (count($response)) {
                    if (isset($response['result'])) {
                        $response = $response['result'];
                    }

                    $this->_storeViews = array();
                    foreach ($response as $storeView) {
                        $this->_storeViews[$storeView['store_id']] = $storeView;
                    }
                }

                $this->getServiceLocator()->get('logService')
                    ->log(LogService::LEVEL_INFO,
                        'mag_storeviews',
                        'Loaded store views',
                        array('soap response'=>$response, 'store views'=>$this->_storeViews),
                        array('node'=>$this)
                    );
            }
        }

        return $this->_storeViews;
    }

    /**
     * Should set up any initial data structures, connections, and open any required files that the node needs to operate.
     * In the case of any errors that mean a successful sync is unlikely, a Magelink\Exception\InitException MUST be thrown.
     */
    protected function _init()
    {
        $this->getStoreViews();
        $storeCount = count($this->_storeViews);

        if ($storeCount == 1 && $this->isMultiStore()) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_ERROR,
                    'mag_mstore_sng',
                    'Multi-store enabled but only one store view!',
                    array(),
                    array('node'=>$this)
                );
        }elseif ($storeCount > 1 && !$this->isMultiStore()) {
            $this->getServiceLocator()->get('logService')
                ->log(LogService::LEVEL_WARN,
                    'mag_mstore_miss',
                    'Multi-store disabled but multiple store views!',
                    array(),
                    array('node'=>$this)
                );
        }

        if (!$this->isMultiStore()) {
            $this->_storeViews = array(0=>array());
        }
    }

    /**
     * This will always be the last call to the Node to close off any open connections, files, etc.
     */
    protected function _deinit() {}

    /**
     * @return bool $success
     */
    protected function triggerSliFeed()
    {
        $logCode = 'mag_crn_slif';
        $logData = array();
        $success = NULL;

        try {
            /** @var ApplicationConfigService $applicationConfigService */
            $applicationConfigService = $this->getServiceLocator()->get('applicationConfigService');
            /** @var NULL|\HOPS\Cron\SliFeed $sliFeedCron */
            $sliFeedCron = $applicationConfigService->getCronjob('slifeed');
            if (is_null($sliFeedCron)) {
                $success = FALSE;
                $logCode = LogService::LEVEL_DEBUG;
                $logCode .= '_nex';
                $logMessage = 'Sli cron job is not existing.';
            }elseif (!$sliFeedCron->prepareExecution()) {
                $success = FALSE;
                $logLevel = LogService::LEVEL_ERROR;
                $logCode .= '_erp';
                $logMessage = 'Sli feed lock file could not be created.';
            }else{
                $tableGateway = new TableGateway('cron', $this->getServiceLocator()->get('zend_db'));
                $sql = $tableGateway->getSql();
                $logData['tableGateway'] = get_class($tableGateway);

                $where = new Where();
                $where->equalTo('cron_name', 'slifeed');
                $logData['where'] = get_class($where);

                $sqlSelect = $sql->select()->where($where);
                $selectedRows = $tableGateway->selectWith($sqlSelect);
                $logData['selected rows'] = $selectedRows;

                if (count($selectedRows) > 0) {
                    $logMessage = 'update';
                    $sqlUpdate = $sql->update()
                        ->set(array('overdue' => 1, 'updated_at' => date('Y-m-d H:i:s')))
                        ->where($where);
                    $success = (bool) $tableGateway->updateWith($sqlUpdate);
                    $sqlString = $sql->getSqlStringForSqlObject($sqlUpdate);
                }else{
                    $logMessage = 'insert';
                    $sqlInsert = $sql->insert()
                        ->values(array('cron_name' => 'slifeed', 'overdue' => 1, 'updated_at' => date('Y-m-d H:i:s')));
                    $success = (bool) $tableGateway->insertWith($sqlInsert);
                    $sqlString = $sql->getSqlStringForSqlObject($sqlInsert);
                }
                $logData['query'] = $sqlString;

                if ($success) {
                    $logLevel = LogService::LEVEL_INFO;
                    $logMessage = 'Successfully triggered slifeed cron job to be executed via '.$logMessage.'.';
                }else{
                    $logLevel = LogService::LEVEL_ERROR;
                    $logCode .= '_fai';
                    $logMessage = 'Failed to '.$logMessage.' slifeed cron job with overdue set.';
                }
            }
        }catch (\Exception $exception) {
            $logLevel = LogService::LEVEL_ERROR;
            $logCode .= '_err';
            $logMessage = 'Execption thrown on Magento ProductGateway::triggerSliFeed(): '.$exception->getMessage();
            $success = FALSE;
        }

        $logData['success'] = $success;
        $this->getServiceLocator()->get('logService')->log($logLevel, $logCode, $logMessage, $logData);

        return $success;
    }

    /**
     * Updates all data into the node’s source - should load and collapse all pending updates and call writeUpdates,
     *   as well as loading and sequencing all actions.
     * @throws NodeException
     */
    public function update()
    {
        $logCode = $this->logTimes('Magento\Node');

        $startGetActionsTime = time();
        try{
            $this->getPendingActions();
        }catch (NodeException $nodeException) {
            $this->_logService->log(LogService::LEVEL_ERROR,
                $logCode.'_peac_ex',
                'Synchronizer error on ->getPendingActions() on the Magento node: '.$nodeException->getMessage(),
                array($nodeException->getMessage(), $nodeException->getTraceAsString()),
                array('exception'=>$nodeException, 'node'=>$this)
            );
            throw new NodeException($nodeException->getMessage(), $nodeException->getCode(), $nodeException->getPrevious());
        }

        $startGetUpdatesTime = time();
        try{
            $this->getPendingUpdates();
        }catch (NodeException $nodeException) {
            $this->_logService->log(LogService::LEVEL_ERROR,
                $logCode.'_peup_ex',
                'Synchronizer error on ->getPendingActions() on the Magento node: '.$nodeException->getMessage(),
                array($nodeException->getMessage(), $nodeException->getTraceAsString()),
                array('exception'=>$nodeException, 'node'=>$this)
            );
            throw new NodeException($nodeException->getMessage(), $nodeException->getCode(), $nodeException->getPrevious());
        }

        $getEndTime = time();

        $logMessage = 'Magento\Node update: '.count($this->updates).' updates, '.count($this->actions).' actions.';
        $logEntities = array('node'=>$this, 'actions'=>$this->actions, 'updates'=>$this->updates);
        $this->_logService->log(LogService::LEVEL_DEBUGEXTRA, $logCode.'_list', $logMessage, array(), $logEntities);

        $startProcessActionsTime = time();
        $this->processActions();

        $startProcessUpdatesTime = time();
        $triggerSliFeed = $this->processUpdates();

        if ($triggerSliFeed) {
            $this->triggerSliFeed();
        }

        $endProcessTime = time();

        $getActionsTime = ceil($startGetActionsTime - $startGetUpdatesTime);
        $getUpdatesTime = ceil($getEndTime - $startGetUpdatesTime);
        $processActionsTime = ceil($startProcessActionsTime - $startProcessUpdatesTime);
        $processUpdatesTime = ceil($endProcessTime - $startProcessUpdatesTime);

        $message = 'Get and process actions and updates to Magento.';
        $logData = array('type'=>'all', 'actions'=>count($this->actions), 'updates'=>count($this->updates),
            'get actions [s]'=>$getActionsTime, 'get updates [s]'=>$getUpdatesTime,
            'process actions [s]'=>$processActionsTime, 'process updates [s]'=>$processUpdatesTime);
        if (count($this->actions) > 0) {
            $logData['per action [s]'] = round(($getActionsTime + $processActionsTime) / count($this->actions), 1);
        }
        if (count($this->updates) > 0) {
            $logData['per update [s]'] = round(($getUpdatesTime + $processUpdatesTime) / count($this->updates), 1);
        }
        $this->getServiceLocator()->get('logService')->log(LogService::LEVEL_INFO, $logCode.'_no', $message, $logData);

        $this->logTimes('Magento\Node', TRUE);
    }

    /**
     * Implemented in each NodeModule
     * Returns an instance of a subclass of AbstractGateway that can handle the provided entity type.
     *
     * @throws MagelinkException
     * @param string $entityType
     * @return AbstractGateway
     */
    protected function _createGateway($entityType)
    {
        switch ($entityType) {
            case 'customer':
                $gateway = new Gateway\CustomerGateway;
                break;
            case 'product':
                $gateway = new Gateway\ProductGateway;
                break;
            case 'order':
                $gateway = new Gateway\OrderGateway;
                break;
            case 'stockitem':
                $gateway = new Gateway\StockGateway;
                break;
            case 'creditmemo':
                $gateway = new Gateway\CreditmemoGateway;
                break;
            default:
                throw new SyncException('Unknown/invalid entity type '.$entityType);
        }

        return $gateway;
    }

}
