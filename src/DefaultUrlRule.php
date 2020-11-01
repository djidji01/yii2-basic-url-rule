<?php

namespace djidji;

use Yii;
use yii\di\Instance;
use yii\base\BaseObject;
use yii\web\UrlRuleInterface;


class DefaultUrlRule extends BaseObject  implements UrlRuleInterface
{
    /**
     * @var object|string|array $cache an instance of [['yii\caching\Cache']].
     * You may specify $cache in terms of a component ID.
     * You may also pass in a configuration array for creating the object.
     * If the "class" value is not specified in the configuration array, it will use the value of `$type`.
     *
     *  string
     */
    private $cache=null;
    private $_cache=null;

    /**
     * Initializes this rule by ensuring the path for routes file exists.
     */
    public function init()
    {
        if ($this->cache !== false) {
            $this->_cache = Instance::ensure($this->cache?:Yii::$app->getCache(), 'yii\caching\CacheInterface');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createUrl($manager, $route, $urlArgs)
    {
        if ($manager->enableStrictParsing) {
            return false;
        }
        $args='';

        if (($params=$this->normalizeUrlArgs($route,$urlArgs,false))===false) {
            return false;
        }
        $urlArgs=array_replace($params, $urlArgs);
        foreach ($params as $paramName=>$paramValue) {
            if (array_key_exists($paramName, $urlArgs)) {
                $args .= '/'.$urlArgs[$paramName];
                unset($urlArgs[$paramName]);
            }
        }


        $route.=$args!==''?"/{$manager->routeParam}{$args}":'';
        if ($manager->suffix !== null) {
            $route .= $manager->suffix;
        }
        if (!empty($urlArgs) && ($urlArgs = http_build_query($urlArgs)) !== '') {
            $route .= '?' . $urlArgs;
        }

        return $route;
    }

    /**
     * {@inheritdoc}
     */
    public function parseRequest($manager, $request)
    {
        if ($manager->enableStrictParsing) {
            return false;
        }
        $pathInfo = $request->getPathInfo();
        $normalized = false;
        $pathInfoParts=$this->parsePathInfo($manager, $pathInfo, $normalized);
        if($pathInfoParts===false){
            return false;
        }
        list($route,$urlArgs)=$pathInfoParts;

        if (($params=$this->normalizeUrlArgs($route,$urlArgs))===false) {
            return false;
        }

        if ($normalized) {
            // pathInfo was changed by normalizer - we need also normalize route
            return $manager->normalizer->normalizeRoute([$route,$params]);
        }

        return [$route,$params];
    }

    protected function parsePathInfo($manager, $pathInfo, &$normalized)
    {
        $suffix = (string) $manager->suffix;

        if ($manager->normalizer !== false) {
            $pathInfo = $manager->normalizer->normalizePathInfo($pathInfo, $suffix, $normalized);
        }
        if ($suffix !== '' && $pathInfo !== '') {
            $n = strlen($manager->suffix);
            if (substr_compare($pathInfo, $manager->suffix, -$n, $n) === 0) {
                $pathInfo = substr($pathInfo, 0, -$n);
                if ($pathInfo === '') {
                    // suffix alone is not allowed
                    return false;
                }
            } else {
                // suffix doesn't match
                return false;
            }
        }
        $pathInfo=trim($pathInfo,'/');
        if (strpos($pathInfo, "/{$manager->routeParam}/") !== false) {
            list($route,$urlArgs)=explode("/{$manager->routeParam}/",$pathInfo);
            $urlArgs=explode('/',$urlArgs);
        }else {
            $route=$pathInfo;
            $urlArgs=[];
        }

        return [$route,$urlArgs];
    }

    protected function normalizeUrlArgs($url,$urlArgs,$isParse=true)
    {
        $params=false;
        if ($this->cache !== false) {
            if ($isParse) {
                $id = $url.'&'.count($urlArgs);
            }else {
                ksort ($urlArgs);
                $id =$url.'&'.implode('$',array_keys($urlArgs));
            }
            $params = $this->_cache->get($id);
        }

        if ($params===false) {
            if ($parts=Yii::$app->createController($url)) {
                if (($args=$this->validateActionId($parts[0],$parts[1],$urlArgs,$isParse))!==false) {
                    list($params,$actionParams)=$args;
                    if ($this->cache !== false) {
                        if($isParse){
                            $route_id=$url.'&'.implode('$',array_keys($actionParams));
                            $route=['url_id'=>$id,'route_id'=>$route_id,'params'=>json_encode($actionParams)];
                        }else{
                            $url_id = $url.'&'.count($actionParams);
                            $route=['url_id'=>$url_id,'route_id'=>$id,'params'=>json_encode($actionParams)];
                        }

                        $this->_cache->set($id,$route);
                    }
                }
            }
        }elseif ($isParse) {
            $actionArgs=json_decode($params['params'],true);
            $actionParams=array_keys($actionArgs);
            $params=array_replace($actionArgs, array_combine( $actionParams, $urlArgs));
        }else{
            $params=json_decode($params['params'],true);
        }

        return $params;
    }


    /**
     * Verifie if the actionId and args have a corresponding Controller method and parameters
     *
     * @param string $actionId actionId to be validates
     * @param array $args passed arguments
     * @param bool $maxArgs verify that arguments should not be more than delared method parameters
     * @return array|bool index being method parameter name and value being passed argument or false if method not found
     */
    public function validateActionId($controller,$actionId,$args,$maxArgs=false)
    {
        $actionId=$actionId?:$controller->defaultAction;
        $actionMap= $controller->actions();
        if (isset($actionMap[$actionId])) {
            $actionClass=isset($actionMap[$actionId]['class'])?$actionMap[$actionId]['class']:$actionMap[$actionId];
            $method = new \ReflectionMethod($actionClass, 'run');
        }
        if (preg_match('/^(?:[a-z0-9_]+-)*[a-z0-9_]+$/', $actionId)) {
            $methodName = 'action' . str_replace(' ', '', ucwords(str_replace('-', ' ', $actionId)));
            if (method_exists($controller, $methodName)) {
                $method = new \ReflectionMethod($controller, $methodName);
            }
        }

        if (isset($method) && $method->isPublic()) {
            if ($method->getNumberOfRequiredParameters()<=count($args)) {
                if ($maxArgs && count($args)>$method->getNumberOfParameters()) {
                    return false;
                }
                return $this->routifyUrlParams($method,$args);

            }
        }

        return false;

    }

    /**
     * Map and sort url or route Arguments in the way defined by the controller method
     *
     * This is called by [[validateActionId()]] when method corresponding to actionId is found
     * @param \ReflectionMethod $method \ReflectionMethod instance of action method
     * @param array $urlArgs arguments to be passed
     * @return array index being method parameter name and value being passed argument
     */
    public function routifyUrlParams($method,$urlArgs)
    {
        $actionArgs = [];
        $actionParams = [];
        $optionals=[];
        $pos=0;
        foreach ($method->getParameters()as $index => $param) {
            $name = $param->getName();            
            if ($paramType=$param->getType()) {
                if(class_exists(class_exists('\ReflectionNamedType')?$paramType->getName():"$paramType")){
                    $pos++;
                    continue;
                };
            }            
            if (isset($urlArgs[$index-$pos])) {
                $urlArgs[$name]=$urlArgs[$index];
                unset($urlArgs[$index]);
            }
            if (array_key_exists($name, $urlArgs)) {

                if ($param->isDefaultValueAvailable()) {
                    $actionArgs+=$optionals;
                    $actionParams+=$optionals;
                    $actionParams[$name]=$param->getDefaultValue();
                    $optionals=[];
                }else {
                    $actionParams[$name] = null;
                }
                $actionArgs[$name] = $urlArgs[$name];
            }elseif ($param->isDefaultValueAvailable()) {
                $optionals[$name]=$param->getDefaultValue();

            }
        }

        return [$actionArgs,$actionParams];

    }

}
