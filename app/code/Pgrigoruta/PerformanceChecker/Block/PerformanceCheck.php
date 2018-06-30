<?php

namespace Pgrigoruta\PerformanceChecker\Block;

use Magento\Backend\Block\Template;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\PageCache\Model\Config;

class PerformanceCheck extends Template {

    /** @var ResourceConnection  */
    protected $resourceConnection;

    /** @var DeploymentConfig  */
    protected $deploymentConfig;


    protected $checkedPhpSettings = [
        'max_execution_time'=> ['recommended_value' => '600','byte_value'=>false],
        'max_input_time' => ['recommended_value' => '-1','byte_value'=>false],
        'memory_limit' => ['recommended_value' => '768M','byte_value'=>true],
        'upload_max_filesize' => ['recommended_value' => '512M','byte_value'=>true],
        'post_max_size' => ['recommended_value' => '512M','byte_value'=>true],
        'realpath_cache_size' => ['recommended_value' => '4M','byte_value'=>true],
        'realpath_cache_ttl'=>['recommended_value' => '300','byte_value'=>true],
        'opcache.memory_consumption' => ['recommended_value' => '512','byte_value'=>true],
        'opcache.max_accelerated_files' => ['recommended_value' => '65407','byte_value'=>false],
        'opcache.validate_timestamps' => ['recommended_value' => '0','byte_value'=>false],
        'opcache.revalidate_freq' => ['recommended_value' => '4','byte_value'=>false],
        'opcache.interned_strings_buffer' => ['recommended_value' => '48','byte_value'=>true],
    ];

    protected $checkedMysqlVariables = [
        'innodb_buffer_pool_size' => [
            'recommended_value'=> 'custom',
            'notes' => '50% of the available memory if MySQL runs on the same server as the web server. 75% of the available memory if MySQL runs on a separate server.'
        ],
        'innodb_buffer_pool_instances' => [
            'recommended_value' => '4',
        ],
        'innodb_log_file_size' => [
            'recommended_value' => 'custom',
            'notes' => '10% of the innodb_buffer_pool_size setting'
        ],
        'innodb_log_buffer_size' => [
            'recommended_value' => 'custom',
            'notes' => '1/6 of the innodb_log_file_size setting'
        ],
        'query_cache_size' => [
            'recommended_value' => '134217728'
        ]
    ];


    public function __construct(Template\Context $context,
                                ResourceConnection $resourceConnection,
                                DeploymentConfig $deploymentConfig,
                                array $data = [])
    {
        $this->resourceConnection = $resourceConnection;
        $this->deploymentConfig = $deploymentConfig;
        parent::__construct($context, $data);
    }

    /**
     * @return array
     */
    public function getCheckedPhpSettings() {
        return array_keys($this->checkedPhpSettings);
    }

    /**
     * @param $setting
     * @return string
     */
    public function getPHPSettingValue($setting) {
        return ini_get($setting);
    }

    /**
     * @param $setting
     * @return int|string
     * @throws \Exception
     */
    public function getRecommendedPHPSettingValue($setting) {
       return $this->checkedPhpSettings[$setting]['recommended_value'];
    }

    /**
     * @param $settingName
     * @param $yourValue
     * @param $recommendedValue
     * @return bool
     */
    public function phpSettingIsGood($settingName, $yourValue, $recommendedValue) {
        if($this->checkedPhpSettings[$settingName]['byte_value']) {
            $yourValue = $this->convertToBytes($yourValue);
            $recommendedValue = $this->convertToBytes($recommendedValue);
        }

        if($yourValue > $recommendedValue) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * @param $val
     * @return bool|int|string
     */
    protected function convertToBytes($val)
    {
        $val  = trim($val);

        $last = strtolower($val[strlen($val)-1]);
        $val  = substr($val, 0, -1); // necessary since PHP 7.1; otherwise optional

        switch($last) {
            // The 'G' modifier is available since PHP 5.1.0
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    /**
     * @return array
     */
    public function getCheckedMysqlSettings() {
        return array_keys($this->checkedMysqlVariables);
    }

    /**
     * @param $setting
     * @return string
     */
    public function getMysqlSettingValue($setting) {
        $mysqlVariables = null;

        if(is_null($mysqlVariables)) {
            $query = "SHOW VARIABLES";
            $result = $this->resourceConnection->getConnection()->fetchAll($query);
            foreach($result as $item) {
                $mysqlVariables[$item['Variable_name']] = $item['Value'];
            }
        }

        if(isset($mysqlVariables[$setting])) {
            return $mysqlVariables[$setting];
        }
        else {
            return 'unknown';
        }
    }

    /**
     * @param $setting
     * @return string
     */
    public function getMysqlSettingNotes($setting) {
        if(isset($this->checkedMysqlVariables[$setting]['notes'])) {
            return $this->checkedMysqlVariables[$setting]['notes'];
        }
        else {
            return '';
        }
    }

    /**
     * @param $setting
     * @return int|string
     * @throws \Exception
     */
    public function getRecommendedMysqlSettingValue($setting) {
        switch ($setting) {
            case 'innodb_buffer_pool_size':
                $totalMemory = $this->getServerTotalMemory();
                if(!$totalMemory) {
                    return 'Cannot open /proc/meminfo . Probably not a Linux system.';
                }

                if($this->isMysqlInstalledOnTheSameServer()) {
                    $factor = 0.5;
                }
                else {
                    $factor = 0.75;
                }

                return $totalMemory*$factor;
            case 'innodb_log_file_size':
                $bufferSize = $this->getRecommendedMysqlSettingValue('innodb_buffer_pool_size');
                if(is_numeric($bufferSize)) {
                    return (int) (0.1*$bufferSize);
                }
                else {
                    return $bufferSize;
                }
            case 'innodb_log_buffer_size':
                $logFileSize = $this->getRecommendedMysqlSettingValue('innodb_log_file_size');
                if(is_numeric($logFileSize)) {
                    return (int) (0.16*$logFileSize);
                }
                else {
                    return $logFileSize;
                }

            default:
                return $this->checkedMysqlVariables[$setting]['recommended_value'];
        }

    }


    /**
     * @param $settingName
     * @param $yourValue
     * @param $recommendedValue
     * @return bool
     */
    public function mysqlSettingIsGood($settingName, $yourValue, $recommendedValue) {
        switch ($settingName) {
            case 'innodb_buffer_pool_size':
            case 'innodb_log_file_size':
            case 'innodb_log_buffer_size':
                return $yourValue > $recommendedValue;
            default:
                return $yourValue == $recommendedValue;
        }
    }

    /**
     * @return bool
     */
    protected function isMysqlInstalledOnTheSameServer() {
        $mysqlHost = $this->deploymentConfig->get('db/connection/default/host');
        return in_array($mysqlHost,['localhost','127.0.0.1']);
    }

    protected function getServerTotalMemory() {
        $fh = @fopen('/proc/meminfo','r');

        if(!$fh) {
            return 0;
        }

        $mem = 0;
        while ($line = fgets($fh)) {
            $pieces = array();
            if (preg_match('/^MemTotal:\s+(\d+)\skB$/', $line, $pieces)) {
                $mem = $pieces[1];
                break;
            }
        }
        fclose($fh);

        return $mem*1024;
    }

    /**
     * @return bool
     */
    public function isCacheInFiles() {
        return is_null($this->deploymentConfig->get('cache'));
    }

    /**
     * @return bool
     */
    public function isSessionInFiles() {
        $sessionSetting = $this->deploymentConfig->get('session');
        if(is_null($sessionSetting)) {
            return true;
        }
        else {
            return $sessionSetting['save'] == 'files';
        }
    }

    /**
     * @return bool
     */
    public function isAnyCacheDisabled() {
        $cacheTypes = $this->deploymentConfig->get('cache_types');

        foreach($cacheTypes as $cacheType) {
            if($cacheType != 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return mixed
     */
    public function isFpcInVarnish() {
        return $this->_scopeConfig->getValue(Config::XML_PAGECACHE_TYPE == 2);
    }
}