<?php

namespace djidji;

use Yii;
use yii\base\BaseObject;
use yii\web\UrlRuleInterface;

class DefaultUrlRule extends BaseObject  implements UrlRuleInterface
{
    public $routesFile='@app/config/routes.php';

    /**
     * Initializes this rule by ensuring the path for routes file exists.
     */
    public function init()
    {
        $this->routesFile = Yii::getAlias($this->routesFile);
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

    protected function normalizeUrlArgs($route,$urlArgs,$isParse=true)
    {

        if ($isParse) {
            $cacheKey = $route.'&'.count($urlArgs);
        }else {

            ksort ($urlArgs);
            $cacheKey =$route.'&'.implode('$',array_keys($urlArgs));
        }
        if (\file_exists($this->routesFile)) {
            $routes=require $this->routesFile;
        }

        if (!isset($routes[$cacheKey])) {
            if ($parts=Yii::$app->createController($route)) {
                if (($args=$this->validateActionId($parts[0],$parts[1],$urlArgs,$isParse))!==false) {
                    list($params,$actionParams)=$args;
                    if($isParse){
                        $routeKey=$route.'&'.implode('$',array_keys($actionParams));
                        if (!isset($routes[$routeKey])) {
                            $routes[$routeKey]=['route'=>$route,'params'=>$actionParams];
                        }
                        $routes[$cacheKey]=$routeKey;
                    }else{
                        $routes[$cacheKey]=['route'=>$route,'params'=>$actionParams];
                    }
                    file_put_contents($this->routesFile, "<?php  \nreturn ".var_export($routes,true).";");

                }else {
                    return false;
                }
            }else {
                return false;
            }
        }elseif ($isParse) {
            $actionArgs=$routes[$routes[$cacheKey]]['params'];
            $actionParams=array_keys($actionArgs);
            $params=array_replace($actionArgs, array_combine( $actionParams, $urlArgs));
        }else{
            $params=$routes[$cacheKey]['params'];
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
        foreach ($method->getParameters()as $index => $param) {
            $name = $param->getName();
            if (isset($urlArgs[$index])) {
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
