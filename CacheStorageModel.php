<?php

/**
 * This class implement data cache and storage (elasticsearch).
 * To use this class, create a sub class, then in the constructor,
 * need to set $storageIndex, $modelName, which will be used as Index and Type
 * in elastic search. You also need to call: setStorageService to set storage service.
 * Either Cache and Storage can be disabled.
 */

namespace App\Models;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Services\APIStorageService;
use Illuminate\Http\Request;

class CacheStorageModel
{
    /** Must be a valid cache engine defined in cache config */
    private $cacheEngine = 'memcached';
    
    private $useCache = true;
    
    // Now by default, es storage cache is disabled.
    private $useStorage = false;
    
    // If we clear cache in this request
    private $clearCache = false;
    
    // If we clear storage in this request
    private $clearStorage = false;

    private $debug = true;
    
    private $errorCode = null;
    
    private $errorMessage = '';
    
    // Map function names to Model names
    protected $functionModelMap = array();
    
    protected $defaultCacheTime = 120;
    
    // Cache time, array [methodName => minutes], if not set, default cache time is 120
    private $cacheTime = [];
    
    function getCacheTime($methodName) {
        if (isset($this->cacheTime[$methodName])) {
            return $this->cacheTime[$methodName];
        } else {
            return $this->defaultCacheTime;
        }
    }

    /**
     * Set cache time, if not set, default cache time will be used.
     * 
     * @param string $methodName
     * @param integer $cacheTime
     * 
     * @throws \Exception
     */
    function setCacheTime($methodName, $cacheTime) {
        
        if (empty($methodName) || !is_numeric($cacheTime)) {
            throw new \Exception('methodName should be a string, and cachetime should be numberic');
        }
        
        $this->cacheTime[$methodName] = $cacheTime;
    }

    function getFunctionModelMap() {
        return $this->functionModelMap;
    }

    function setFunctionModelMap($functionModelMap) {
        $this->functionModelMap = $functionModelMap;
    }

    public function getError()
    {
        return array($this->errorCode, $this->errorMessage);
    }
    
    public function setError($errorCode, $errorMessage)
    {
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        Log::error($errorCode . '|' . $errorMessage);
    }
    
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function setErrorCode($errorCode)
    {
        $this->errorCode = $errorCode;
    }

    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    public function setErrorMessage($errorMessage)
    {
        $this->errorMessage = $errorMessage;
    }

    // Debug information
    private $debugInfo = array();
    
    /** Need to set your own storage index when you inherit */
    private $storageIndex = 'default';

    /** Need to set your own modelName when you inherit this class, this is for cache key prefix, and storage type */
    private $modelName = 'default';

    /** Must set to an AWS storage service */
    private $storageService = null;
    
    /** Data entry storage service */
    private $deStorageService = null;
    
    public function getDeStorageService()
    {
        return $this->deStorageService;
    }

    public function setDeStorageService($deStorageService)
    {
        $this->deStorageService = $deStorageService;
    }

    protected $request = null;

    public function getRequest()
    {
        return $this->request;
    }

    public function getDebug()
    {
        return $this->debug;
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }
    
    /**
     * To set if we get data from cache/storage; and if we clear cache/storage.
     *
     * @param Request $request
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;

        $disable = $request->get('disable');
        $clear = $request->get('clear');

        switch ($disable) {
            case 'cache':
                $this->setUseCache(false);
                break;
            case 'storage':
                $this->setUseStorage(false);
                break;
            case 'both':
                $this->setUseCache(false);
                $this->setUseStorage(false);
                break;
            default:
                break;
        }

        switch ($clear) {
            case 'cache':
                $this->clearCache = true;
                break;
            case 'storage':
                $this->clearStorage = true;
                break;
            case 'both':
                $this->clearCache = true;
                $this->clearStorage = true;
                break;
            default:
                break;
        }
    }

    public function getStorageService()
    {
        return $this->storageService;
    }

    public function setStorageService($storageService)
    {
        $this->storageService = $storageService;
    }

    public function getModelName()
    {
        return $this->modelName;
    }

    public function setModelName($modelName)
    {
        $this->modelName = $modelName;
    }

    public function getDebugInfo()
    {
        return $this->debugInfo;
    }

    public function addDebugInfo($debugInfo)
    {
        if ($this->debug) {
            if (!is_string($debugInfo)) {
                $debugInfo = (string) $debugInfo;
            }
            
            if (true === env('APP_DEBUG')) {
                Log::info($debugInfo);
            }
            
            $this->debugInfo[] = $debugInfo;
        }
    }
    
    public function getStorageIndex()
    {
        return $this->storageIndex;
    }

    public function setStorageIndex($storageIndex)
    {
        $this->storageIndex = $storageIndex;
    }

    public function getUseStorage()
    {
        return $this->useStorage;
    }

    public function setUseStorage($useStorage)
    {
        $this->useStorage = $useStorage;
    }

    public function getUseCache()
    {
        return $this->useCache;
    }

    public function setUseCache($useCache)
    {
        $this->useCache = $useCache;
    }

    public function __construct()
    {
        $this->setRequest(request());
        
        // Set default storage config, not used now.
        $this->setStorageIndex($this->storageIndex);
        $this->setModelName($this->modelName);
    }

    public function getCacheEngine()
    {
        return $this->cacheEngine;
    }

    public function setCacheEngine($cacheEngine)
    {
        $this->cacheEngine = $cacheEngine;
    }

    /**
     * Convert array to string, used for generating memcache key.
     * 
     * @param array $src
     * 
     * @return string
     */
    public function convertArrayToString($src)
    {
        $ret = '';

        if (is_scalar($src)) {
            return $src;
        } else {

            foreach($src as $key => $value) {
                if (is_array($value)) {
                    $ret .= $key . '#' . $this->convertArrayToString($value);
                } else {
                    $ret .= $key . '#' . $value;
                }
            }
        }

        return $ret;
    }

    /**
     * Generate a valid memcache key. Ideally $params include [ ModelName, key1, key2 ...].
     *
     * @param  array $params
     * @return mixed
     */
    public function generateKey(array $params)
    {
        // If params has array element, convert it to string, nested sub array is not supported
        for($i = 0; $i < count($params); $i ++) {
            if (is_array($params[$i])){
                $params[$i] = $this->convertArrayToString($params[$i]);
            }
        }
        
        $key = implode('-', $params);
        $key = preg_replace("/[^A-Za-z0-9\-#]/", "", $key);
        
        if (empty($key)) {
            Log::error("Error in " . __FUNCTION__ . " key cannot be empty!");
            return false;
        } else {
            // Maximum memcache key length
            if (strlen($key) > 250) {
                $key = md5($key);
            }
            
        }
        
        $this->addDebugInfo('generate key: ' . $key);
        return $key;
    }

    public function cacheHas($key)
    {
        if (empty($key)) {
            return false;
        }

        return Cache::store($this->cacheEngine)->has($key);
    }

    public function cacheGet($key)
    {
        if (empty($key)) {
            return false;
        }

        if ($this->cacheHas($key)) {
            return Cache::store($this->cacheEngine)->get($key);
        } else {
            return false;
        }
    }

    public function cacheSet($key, $value, $cacheTime = null)
    {
        if (empty($key)) {
            return false;
        }

        if (empty($cacheTime)) {
            Cache::store($this->cacheEngine)->forever($key, $value);
        } else {
            Cache::store($this->cacheEngine)->put($key, $value, $cacheTime);
        }

        return true;
    }

    public function cacheDelete($key)
    {
        Cache::store($this->cacheEngine)->forget($key);

        return true;
    }

    /**
     * Get data from elastic search.
     */
    public function esGet($index, $type, $id)
    {
        $document = $this->storageService->searchDocumentById($index, $type, $id);

        if (!$document) {
            return false;
        } else {
            return $document;
        }
    }

    public function esSet($index, $type, $key, $body)
    {
        return $this->storageService->createDocument($index, $type, $key, $body);
    }

    /**
     * Inherit class will implement a method start with underscore, here will call that method and use cache/storage.
     *
     * @param string $name
     * @param array $arguments
     */
    public function __call($name, array $arguments)
    {
        if (empty($name)) {
            $this->addDebugInfo('Method can not be empty');
            return false;
        }

        // If model name is not set, use function to map
        $currentModelName = $this->getModelName();
        if(empty($currentModelName) || 'default' == $currentModelName) {
            if (!empty($this->functionModelMap[$name])) {
                $this->setModelName($this->functionModelMap[$name]);
            }
        }
        
        $methodName = '_' . $name;

        if (!method_exists($this, $methodName)) {
            $error_message = "method: $name does not exist";
            Log::error($error_message);
            $this->addDebugInfo($error_message);
            return false;
        }

        // Generate cache and storage key
        $cacheKeyArray = $arguments;
        array_unshift($cacheKeyArray, $this->modelName, $name);
        $cacheKey = $this->generateKey($cacheKeyArray);

        // Get from memcache
        if ($this->getUseCache() && !$this->clearCache) {
            $result = $this->cacheGet($cacheKey);
            if (false !== $result) {
                list($meta, $resultData) = $this->unpackData($result);
                $this->addDebugInfo("$name " . 'from: '. $this->cacheEngine);
                return $resultData;
            }
        }

        // Get from storage cache
        if ($this->getUseStorage() && !$this->clearStorage) {
            $result = $this->esGet($this->storageIndex, $this->modelName, $cacheKey);

            // If we find the data, save to cache and return the data
            if (false !== $result) {
                if ($this->getUseCache()) {
                    $this->cacheSet($cacheKey, $result, $this->getCacheTime($methodName));
                }
                
                list($meta, $resultData) = $this->unpackData($result);
                $this->addDebugInfo("$name " . 'from: storage');
                return $resultData;
            }

            // If we cannot find data in storage, continue to 3rd party api
        }

        try {
            $result = call_user_func_array(array($this, $methodName), $arguments);
        } catch (\Exception $e) {
            $this->setErrorMessage($e->getMessage());
            return false;
        }

        if (false === $result) {
            $message = 'No data returned from method: '. $methodName . var_export($arguments, true);
            $this->addDebugInfo($message);
            Log::error($message);
            return false;
        }

        $cachedResult = $this->packData($result);

        if ($this->getUseCache()) {
            $ret = $this->cacheSet($cacheKey, $cachedResult, $this->getCacheTime($methodName));
        }

        if ($this->getUseStorage()) {
            $ret = $this->esSet($this->storageIndex, $this->modelName, $cacheKey, $cachedResult);
            
            if (!$ret) {
                $this->addDebugInfo('Storage error: '. $this->getStorageService()->getErrorMessage());
            }
        }

        $this->addDebugInfo("$name " . 'from: 3rd party');
        
        return $result;
    }
    
    /**
     * Generate result to store in cache and storage, add extra information.
     *
     * @param type $result
     * @return array
     */
    public function packData($result)
    {
        return array('doc' => $result, 'meta' => array('updateTime' => date("Y-m-d H:i:s")));
    }
    
    public function unpackData($storedResult)
    {
        return array($storedResult['meta'], $storedResult['doc']);
    }
    
    /**
     * Extract field from array.
     * 
     * @param array $src
     * @param string $field
     * 
     * @return array
     */
    public function getIds($src, $field)
    {
        $ids = [];
        
        if (is_array($src) && count($src)) {
            foreach ($src as $key => $value) {
                $value = (array)$value;

                $id = $value[$field];
                if (!empty($id)) {
                    $ids[] = $id;
                }
            }
        }
        
        return $ids;
    }
    
}
